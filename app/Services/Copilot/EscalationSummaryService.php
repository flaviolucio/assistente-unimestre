<?php

declare(strict_types=1);

namespace App\Services\Copilot;

use App\Repositories\DiagnosticSessionRepository;
use App\Support\SensitiveDataMasker;

/**
 * Resumo técnico de escalonamento (Etapa 7.5).
 *
 * Construído SOMENTE com dados registrados (sessão, perguntas/respostas,
 * hipóteses, eventos de status, fontes, divergências). Nada é inventado:
 * o que não foi registrado aparece como "Não registrado". Hipóteses ficam em
 * seção própria e nunca como fonte documental; hipótese rejeitada nunca vira
 * causa. Todo texto passa pelo SensitiveDataMasker antes de ser exportado.
 * Nada é enviado a lugar nenhum: o técnico copia e cola.
 */
final class EscalationSummaryService
{
    public const NOT_RECORDED = 'Não registrado no atendimento';

    private DiagnosticSessionRepository $sessions;

    public function __construct(?DiagnosticSessionRepository $sessions = null)
    {
        $this->sessions = $sessions ?? new DiagnosticSessionRepository();
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function build(array $session, ?string $technician, ?string $reason = null): array
    {
        $sessionId = (int) $session['id'];
        $questions = (array) $session['questions'];
        $answered = array_values(array_filter($questions, static fn (array $q): bool => ($q['answer'] ?? null) !== null));
        $pending = array_values(array_filter($questions, static fn (array $q): bool => ($q['answer'] ?? null) === null));
        $hypotheses = $this->sessions->hypotheses($sessionId);
        $escalation = (array) ($session['escalation'] ?? []);

        $rejected = [];
        $open = [];
        $best = null;

        foreach ($hypotheses as $h) {
            $item = [
                'title' => (string) $h['title'],
                'status' => (string) $h['status'],
                'status_label' => CopilotResponseValidator::STATUS_LABELS[$h['status']] ?? (string) $h['status'],
                'evidence' => (string) ($h['evidence_summary'] ?? ''),
            ];

            if ($h['status'] === 'rejected') {
                $rejected[] = $item;
            } elseif ($h['status'] !== 'confirmed') {
                $open[] = $item;

                if ($h['status'] === 'supported' && $best === null) {
                    $best = $item['title'];
                }
            }
        }

        $sources = [];
        $procedures = [];

        foreach ((array) $session['sources'] as $s) {
            $type = EvidenceType::fromSource(['source_type' => $s['type'] ?? '']);
            $entry = ['type' => $type, 'badge' => EvidenceType::badge($type), 'title' => (string) ($s['title'] ?? '')];
            $sources[] = $entry;

            if ($type === EvidenceType::INTERNAL_PROCEDURE) {
                $procedures[] = ['title' => $entry['title'], 'result' => 'Resultado da execução não registrado'];
            }
        }

        $conflicts = array_map(static function (array $c): array {
            $type = EvidenceType::fromSource(['source_type' => $c['type'] ?? '']);

            return ['badge' => EvidenceType::badge($type), 'title' => (string) ($c['title'] ?? ''), 'claim' => (string) ($c['claim'] ?? '')];
        }, array_values((array) ($escalation['conflicts'] ?? [])));

        $pendingInfo = [];

        foreach ($pending as $q) {
            $pendingInfo[] = 'Resposta para a verificação pendente: ' . $q['question'];
        }

        foreach ($open as $h) {
            $pendingInfo[] = 'Evidência que confirme ou descarte a hipótese: ' . $h['title'];
        }

        foreach ([
            'Mensagem de erro exata exibida pelo sistema, se houver',
            'Captura de tela do comportamento observado (sem dados pessoais)',
            'Data e horário aproximados em que o problema ocorreu',
            'Quantos registros/usuários são afetados e se o problema é recorrente',
        ] as $generic) {
            $pendingInfo[] = $generic;
        }

        $masker = new SensitiveDataMasker();

        $summary = $masker->maskDeep([
            'title' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $session['original_problem'])), 0, 120),
            'date' => date('Y-m-d H:i'),
            'technician' => $technician ?? self::NOT_RECORDED,
            'status_label' => CopilotDiagnosticService::STATUS_LABELS[$session['status']] ?? (string) $session['status'],
            'original_problem' => (string) $session['original_problem'],
            'module' => (string) ($session['identified_module'] ?? '') ?: self::NOT_RECORDED,
            'context' => (string) ($session['identified_context'] ?? '') ?: self::NOT_RECORDED,
            'symptoms' => array_merge(
                ['Relato inicial: ' . $session['original_problem']],
                array_map(static fn (array $q): string => (string) $q['answer'], $answered)
            ),
            'expected_behavior' => self::NOT_RECORDED,
            'observed_behavior' => (string) $session['original_problem'],
            'questions' => array_map(static fn (array $q): array => [
                'question' => (string) $q['question'],
                'answer' => ($q['answer'] ?? null) !== null ? (string) $q['answer'] : 'Sem resposta',
            ], $questions),
            'checks' => array_map(static fn (array $q): string => $q['question'] . ' → ' . $q['answer'], $answered),
            'rejected' => $rejected,
            'open' => $open,
            'most_supported' => $best,
            'sources' => $sources,
            'procedures' => $procedures,
            'conflicts' => $conflicts,
            'has_conflict' => count($conflicts) >= 2,
            'pending_info' => $pendingInfo,
            'timeline' => $this->timeline($session, $hypotheses),
            'reason' => $reason,
            'suggested_reason' => $escalation['suggested_reason'] ?? null,
        ]);
        $summary['masked_count'] = $masker->count();

        return $summary;
    }

    /**
     * HTML do modal. Sessão escalada mostra o resumo CONGELADO (somente leitura).
     *
     * @param array<string, mixed> $session
     */
    public function renderView(array $session, ?string $technician): string
    {
        $readOnly = $session['status'] === 'escalated' && is_array($session['escalation_summary'] ?? null);
        $summary = $readOnly ? $session['escalation_summary'] : $this->build($session, $technician);
        $escalated = null;

        if ($session['status'] === 'escalated') {
            $user = is_numeric($session['escalated_by'] ?? null) ? (new \App\Repositories\UserRepository())->find((int) $session['escalated_by']) : null;
            $escalated = [
                'at' => (string) $session['escalated_at'],
                'by' => $user !== null ? (string) $user['name'] : self::NOT_RECORDED,
                'reason' => (string) ($session['escalation_reason'] ?? ''),
                'reference' => (string) ($session['external_reference'] ?? ''),
            ];
        }

        $sessionId = (int) $session['id'];
        $summaryText = $this->summaryText($summary);
        $supportText = $this->supportText($summary);
        $readOnly = $session['status'] === 'escalated';
        $canReopen = $session['status'] === 'escalation_needed';
        $reasons = CopilotDiagnosticService::ESCALATION_REASONS;

        ob_start();
        require config('paths.views') . '/chat/_escalation_view.php';

        return (string) ob_get_clean();
    }

    /**
     * Texto do resumo completo (copiar/colar), em texto puro.
     *
     * @param array<string, mixed> $s
     */
    public function summaryText(array $s): string
    {
        $lines = [
            'RESUMO DE ESCALONAMENTO — ' . $s['title'],
            'Data: ' . $s['date'] . ' · Técnico responsável: ' . $s['technician'],
            'Situação: ' . $s['status_label'] . ' — com as informações atuais, não foi possível confirmar a causa.',
            '',
            'Problema original: ' . $s['original_problem'],
            'Módulo: ' . $s['module'],
            'Contexto: ' . $s['context'],
            'Comportamento esperado: ' . $s['expected_behavior'],
            'Comportamento observado: ' . $s['observed_behavior'],
            '',
            'Sintomas:',
            ...$this->bullets($s['symptoms']),
            '',
            'Perguntas realizadas e respostas do usuário:',
            ...$this->bullets(array_map(static fn (array $q): string => $q['question'] . ' → ' . $q['answer'], $s['questions'])),
            '',
            'Hipóteses rejeitadas (não são a causa):',
            ...$this->bullets(array_map(static fn (array $h): string => $h['title'], $s['rejected'])),
            '',
            'Hipóteses ainda abertas (não confirmadas):',
            ...$this->bullets(array_map(static fn (array $h): string => $h['title'] . ' (' . $h['status_label'] . ')', $s['open'])),
            'Hipótese mais sustentada (não confirmada): ' . ($s['most_supported'] ?? 'nenhuma'),
            '',
            'Fontes consultadas:',
            ...$this->bullets(array_map(static fn (array $f): string => $f['badge'] . ': ' . $f['title'], $s['sources'])),
            '',
            'Procedimentos já tentados:',
            ...$this->bullets(array_map(static fn (array $p): string => $p['title'] . ' — ' . $p['result'], $s['procedures'])),
        ];

        if ($s['has_conflict']) {
            $lines[] = '';
            $lines[] = 'Há divergência entre as fontes consultadas:';
            array_push($lines, ...$this->bullets(array_map(static fn (array $c): string => $c['badge'] . ' ' . $c['title'] . ': ' . $c['claim'], $s['conflicts'])));
        }

        $lines = array_merge(
            $lines,
            ['', 'Histórico da investigação:'],
            array_map(static fn (int $i, string $t): string => ($i + 1) . '. ' . $t, array_keys($s['timeline']), $s['timeline']),
            ['', 'Informações que ainda podem ser necessárias:'],
            $this->bullets($s['pending_info']),
            ['', 'Motivo do escalonamento: ' . ($s['reason'] ?? 'a definir pelo técnico')]
        );

        return implode("\n", $lines);
    }

    /**
     * Texto pronto para o chamado no suporte UNIMESTRE. Nunca afirma causa.
     *
     * @param array<string, mixed> $s
     */
    public function supportText(array $s): string
    {
        $official = array_values(array_filter($s['sources'], static fn (array $f): bool => $f['type'] === EvidenceType::OFFICIAL));
        $results = array_merge(
            array_map(static fn (array $h): string => 'Descartado: ' . $h['title'], $s['rejected']),
            array_map(static fn (array $p): string => 'Procedimento interno "' . $p['title'] . '": ' . $p['result'], $s['procedures'])
        );

        $extra = [];

        if ($s['has_conflict']) {
            $extra[] = 'Há divergência entre as fontes consultadas: ' . implode('; ', array_map(static fn (array $c): string => $c['title'] . ' — ' . $c['claim'], $s['conflicts'])) . '.';
        }

        $extra[] = 'A causa ainda não foi confirmada.';

        return implode("\n", [
            'ASSUNTO:',
            $s['title'],
            '',
            'PROBLEMA:',
            $s['original_problem'],
            '',
            'MÓDULO:',
            $s['module'],
            '',
            'CENÁRIO:',
            $s['context'],
            '',
            'JÁ VERIFICADO:',
            ...$this->bullets($s['checks']),
            '',
            'RESULTADOS:',
            ...$this->bullets($results),
            '',
            'HIPÓTESES AINDA ABERTAS (não confirmadas):',
            ...$this->bullets(array_map(static fn (array $h): string => $h['title'], $s['open'])),
            '',
            'DOCUMENTAÇÃO CONSULTADA:',
            ...$this->bullets(array_map(static fn (array $f): string => $f['title'], $official)),
            '',
            'INFORMAÇÕES ADICIONAIS:',
            implode("\n", $extra),
        ]);
    }

    /**
     * Linha do tempo factual: relato, perguntas/respostas e mudanças de status
     * registradas. Nenhum raciocínio do modelo.
     *
     * @param array<string, mixed> $session
     * @param array<int, array<string, mixed>> $hypotheses
     * @return array<int, string>
     */
    private function timeline(array $session, array $hypotheses): array
    {
        $titles = [];

        foreach ($hypotheses as $h) {
            $titles[$h['id']] = (string) $h['title'];
        }

        $items = [];
        $seq = 0;

        foreach ((array) $session['questions'] as $q) {
            if (($q['answer'] ?? null) !== null) {
                $items[] = [(int) ($q['answer_message_id'] ?? $q['assistant_message_id'] ?? PHP_INT_MAX), $seq++, 'Verificado: ' . $q['question'] . ' — usuário respondeu: ' . $q['answer']];
            }
        }

        foreach ($this->sessions->eventsForSession((int) $session['id']) as $event) {
            if ($event['from_status'] === null || !isset($titles[(int) $event['diagnostic_hypothesis_id']])) {
                continue;
            }

            $label = CopilotResponseValidator::STATUS_LABELS[$event['to_status']] ?? (string) $event['to_status'];
            $items[] = [(int) ($event['message_id'] ?? PHP_INT_MAX), $seq++, 'Hipótese "' . $titles[(int) $event['diagnostic_hypothesis_id']] . '" passou a: ' . $label];
        }

        usort($items, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $out = ['Usuário informou: ' . $session['original_problem']];

        foreach ($items as $item) {
            $out[] = $item[2];
        }

        foreach ($hypotheses as $h) {
            if (!in_array($h['status'], ['rejected', 'confirmed'], true)) {
                $out[] = 'Hipótese "' . $h['title'] . '" continua aberta';
            }
        }

        $out[] = 'Sem evidência suficiente para concluir.';

        return $out;
    }

    /**
     * @param array<int, string> $items
     * @return array<int, string>
     */
    private function bullets(array $items): array
    {
        return $items === [] ? ['- (nenhum)'] : array_map(static fn (string $i): string => '- ' . $i, $items);
    }
}
