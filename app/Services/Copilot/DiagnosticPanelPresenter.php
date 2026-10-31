<?php

declare(strict_types=1);

namespace App\Services\Copilot;

use App\Repositories\DiagnosticSessionRepository;
use App\Services\Knowledge\InternalKnowledgeService;

/**
 * Monta o modelo de apresentação do painel "Diagnóstico" (Etapa 7.4).
 *
 * Só APRESENTA o estado já decidido pelo backend (sessão, hipóteses,
 * eventos, perguntas). Não decide status nem etapas: nenhuma regra de
 * diagnóstico vive aqui ou no JavaScript. Evidências exibidas são apenas o
 * que foi registrado (eventos de status, respostas do usuário, fontes) —
 * nunca raciocínio do modelo.
 *
 * O HTML é gerado por resources/views/chat/_diagnostic_panel.php, usado tanto
 * na página da conversa quanto na resposta de POST /api/chat.
 */
final class DiagnosticPanelPresenter
{
    public const TIMELINE = [
        'Problema identificado',
        'Contexto coletado',
        'Hipóteses em análise',
        'Solução proposta',
        'Confirmação do usuário',
    ];

    public const STAGE_LABELS = [
        CopilotDiagnosticService::STAGE_COLLECTING => 'Coletando contexto',
        CopilotDiagnosticService::STAGE_TESTING => 'Testando hipóteses',
        CopilotDiagnosticService::STAGE_WAITING => 'Aguardando confirmação',
        CopilotDiagnosticService::STAGE_ESCALATION => 'Inconclusivo',
        CopilotDiagnosticService::STAGE_RESOLVED => 'Concluído',
        CopilotDiagnosticService::STAGE_CLOSED => 'Encerrado',
    ];

    /** Ícone textual: o status nunca depende só da cor. */
    public const STATUS_ICONS = [
        'possible' => '○',
        'supported' => '▲',
        'weakened' => '▽',
        'rejected' => '✕',
        'confirmed' => '✓',
    ];

    private const STATUS_ORDER = ['confirmed' => 0, 'supported' => 1, 'possible' => 2, 'weakened' => 3, 'rejected' => 4];

    public const CLOSE_REASONS = [
        'nao_resolvido' => 'Não consegui resolver',
        'nao_preciso' => 'Não preciso mais',
        'mudei_assunto' => 'Mudei de assunto',
        'outro' => 'Outro',
    ];

    private DiagnosticSessionRepository $sessions;

    private InternalKnowledgeService $knowledge;

    public function __construct(?DiagnosticSessionRepository $sessions = null, ?InternalKnowledgeService $knowledge = null)
    {
        $this->sessions = $sessions ?? new DiagnosticSessionRepository();
        $this->knowledge = $knowledge ?? new InternalKnowledgeService();
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function build(array $session, bool $canManage): array
    {
        $sessionId = (int) $session['id'];
        $status = (string) $session['status'];
        $stage = (string) $session['current_stage'];
        $active = in_array($status, DiagnosticSessionRepository::ACTIVE_STATUSES, true);
        $resolved = $status === 'resolved';

        $questions = (array) $session['questions'];
        $answered = array_values(array_filter($questions, static fn (array $q): bool => ($q['answer'] ?? null) !== null));
        $last = $questions !== [] ? $questions[count($questions) - 1] : null;
        $nextQuestion = $active && $last !== null && ($last['answer'] ?? null) === null ? (string) $last['question'] : null;

        $hypotheses = $this->hypotheses($sessionId);
        $open = array_values(array_filter($hypotheses, static fn (array $h): bool => !in_array($h['status'], ['rejected', 'confirmed'], true)));
        $hasConfirmed = in_array('confirmed', array_column($hypotheses, 'status'), true);

        // Inconclusivo: o backend sinalizou escalonamento, ou a investigação
        // ativa ficou sem próxima verificação e sem causa confirmada.
        $inconclusive = $active && (
            $status === 'escalation_needed'
            || $stage === CopilotDiagnosticService::STAGE_ESCALATION
            || ($status !== 'solution_proposed' && $nextQuestion === null && !$hasConfirmed && $hypotheses !== [])
        );

        $caseDocument = $resolved ? $this->knowledge->caseForSession($sessionId) : null;
        $escalated = $status === 'escalated';
        $pendingInfo = $inconclusive ? array_slice((new EscalationSummaryService($this->sessions))->build($session, null)['pending_info'], 0, 4) : [];
        $conversationId = (int) $session['conversation_id'];

        return [
            'session_id' => $sessionId,
            'conversation_id' => $conversationId,
            'status' => $status,
            'status_label' => CopilotDiagnosticService::STATUS_LABELS[$status] ?? $status,
            'stage' => $stage,
            'stage_label' => self::STAGE_LABELS[$stage] ?? $stage,
            'active' => $active,
            'resolved' => $resolved,
            'expanded' => $active || $resolved || $escalated,
            'heading' => match (true) {
                $status === 'escalation_needed' => 'Diagnóstico inconclusivo',
                $escalated => 'Caso escalado',
                $active => 'Diagnóstico em andamento',
                $resolved => 'Diagnóstico concluído',
                default => 'Diagnóstico encerrado',
            },
            'escalated' => $escalated ? [
                'at' => (string) $session['escalated_at'],
                'by' => $this->userName($session['escalated_by'] ?? null),
                'reason' => (string) ($session['escalation_reason'] ?? ''),
                'reference' => (string) ($session['external_reference'] ?? ''),
                'title' => (string) (($session['escalation_summary']['title'] ?? '') ?: $session['original_problem']),
            ] : null,
            'pending_info' => $pendingInfo,
            'problem' => (string) $session['original_problem'],
            'module' => (string) ($session['identified_module'] ?? ''),
            'summary' => (string) ($session['current_summary'] ?? ''),
            'timeline' => $this->timeline($status, $stage),
            'hypotheses' => $hypotheses,
            'next_question' => $nextQuestion,
            'answered' => array_map(static fn (array $q): array => [
                'question' => (string) $q['question'],
                'answer' => (string) $q['answer'],
            ], array_slice($answered, -3)),
            'inconclusive' => $inconclusive,
            'open_hypotheses' => array_column($open, 'title'),
            'confirmed_cause' => $session['confirmed_cause'] ?? null,
            'recommended_action' => $session['recommended_action'] ?? null,
            'main_sources' => $this->mainSources((array) $session['sources']),
            'close_reasons' => self::CLOSE_REASONS,
            'actions' => [
                'resolve' => $active,
                'abandon' => $active,
                'register_case' => $resolved && $canManage && $caseDocument === null,
                'case_registered' => $caseDocument !== null,
                'create_procedure' => $canManage,
                'prepare_escalation' => $inconclusive,
                'reopen' => $status === 'escalation_needed',
                'view_escalation' => $escalated,
            ],
            'urls' => [
                'register_case' => app_url('assistente/' . $conversationId . '/caso-resolvido'),
                'case_edit' => $caseDocument !== null && $canManage ? app_url('conhecimento-interno/' . (int) $caseDocument['id'] . '/editar') : null,
                'create_procedure' => app_url('procedimentos/novo') . '?from_session=' . $sessionId,
            ],
        ];
    }

    /** HTML do painel (partial compartilhado por thread e API). */
    public function render(array $session, bool $canManage): string
    {
        $panel = $this->build($session, $canManage);

        ob_start();
        require config('paths.views') . '/chat/_diagnostic_panel.php';

        return (string) ob_get_clean();
    }

    /** @param mixed $userId */
    private function userName($userId): string
    {
        $user = is_numeric($userId) ? (new \App\Repositories\UserRepository())->find((int) $userId) : null;

        return $user !== null ? (string) $user['name'] : 'Não registrado';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function timeline(string $status, string $stage): array
    {
        // Índice da etapa atual (0-based). Resolvido = todas concluídas.
        $current = match (true) {
            $status === 'resolved' => count(self::TIMELINE),
            $status === 'solution_proposed' => 4,
            $stage === CopilotDiagnosticService::STAGE_COLLECTING => 1,
            default => 2,
        };

        $steps = [];

        foreach (self::TIMELINE as $index => $label) {
            $steps[] = [
                'label' => $label,
                'state' => $index < $current ? 'done' : ($index === $current && !in_array($status, ['abandoned', 'escalated'], true) ? 'current' : 'todo'),
            ];
        }

        return $steps;
    }

    /**
     * Hipóteses ordenadas por relevância de status, com evidências registradas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function hypotheses(int $sessionId): array
    {
        $events = [];

        foreach ($this->sessions->eventsForSession($sessionId) as $event) {
            $events[(int) $event['diagnostic_hypothesis_id']][] = $event;
        }

        $items = [];

        foreach ($this->sessions->hypotheses($sessionId) as $h) {
            $supports = [];
            $weakens = [];

            foreach ($events[$h['id']] ?? [] as $event) {
                $reason = trim((string) ($event['reason'] ?? ''));

                if ($reason === '') {
                    continue;
                }

                if (in_array($event['to_status'], ['supported', 'confirmed'], true)) {
                    $supports[] = $reason;
                } elseif (in_array($event['to_status'], ['weakened', 'rejected'], true)) {
                    $weakens[] = $reason;
                }
            }

            $sources = array_map(static fn (array $ref): array => [
                'badge' => EvidenceType::badge(EvidenceType::fromSource(['source_type' => $ref['type'] ?? ''])),
                'css' => EvidenceType::cssClass(EvidenceType::fromSource(['source_type' => $ref['type'] ?? ''])),
                'title' => (string) ($ref['title'] ?? ''),
            ], (array) ($h['metadata']['source_refs'] ?? []));

            $items[] = [
                'id' => (int) $h['id'],
                'title' => (string) $h['title'],
                'status' => (string) $h['status'],
                'status_label' => CopilotResponseValidator::STATUS_LABELS[$h['status']] ?? (string) $h['status'],
                'icon' => self::STATUS_ICONS[$h['status']] ?? '○',
                'supports' => array_values(array_unique($supports)),
                'weakens' => array_values(array_unique($weakens)),
                'sources' => $sources,
                'has_evidence' => $supports !== [] || $weakens !== [] || $sources !== [],
            ];
        }

        usort($items, static fn (array $a, array $b): int => (self::STATUS_ORDER[$a['status']] ?? 9) <=> (self::STATUS_ORDER[$b['status']] ?? 9) ?: $a['id'] <=> $b['id']);

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, string>>
     */
    private function mainSources(array $sources): array
    {
        // Oficiais primeiro; no máximo 3. Sem URL: fonte interna não vira link.
        usort($sources, static fn (array $a, array $b): int => (($a['type'] ?? '') === 'official' ? 0 : 1) <=> (($b['type'] ?? '') === 'official' ? 0 : 1));

        return array_map(static fn (array $s): array => [
            'badge' => EvidenceType::badge(EvidenceType::fromSource(['source_type' => $s['type'] ?? ''])),
            'css' => EvidenceType::cssClass(EvidenceType::fromSource(['source_type' => $s['type'] ?? ''])),
            'title' => (string) ($s['title'] ?? ''),
        ], array_slice($sources, 0, 3));
    }
}
