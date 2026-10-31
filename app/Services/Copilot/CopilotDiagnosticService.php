<?php

declare(strict_types=1);

namespace App\Services\Copilot;

use App\Repositories\DiagnosticSessionRepository;
use App\Services\Security\Audit;

/**
 * Motor de estado do diagnóstico conversacional (Etapa 7.2).
 *
 * Não confundir com App\Services\DiagnosticService (saúde técnica do sistema).
 *
 * Responsabilidades:
 *  - abrir/continuar a sessão de diagnóstico da conversa (no máximo uma ativa);
 *  - registrar perguntas feitas e as respostas do usuário;
 *  - montar a MEMÓRIA do diagnóstico enviada à IA (dados, não instrução);
 *  - aplicar as hipóteses validadas (CopilotResponseValidator) ao banco,
 *    casando por id estável (h{id}) ou por título normalizado;
 *  - registrar mudanças de status (diagnostic_hypothesis_events);
 *  - impedir que a mesma pergunta seja repetida;
 *  - manter o resumo factual, montado SÓ com dados do backend;
 *  - nunca marcar "resolved" sozinho: resolved = true do modelo vira
 *    solution_proposed; resolved só com confirmação do usuário.
 */
final class CopilotDiagnosticService
{
    public const STAGE_COLLECTING = 'collecting_context';
    public const STAGE_TESTING = 'testing_hypotheses';
    public const STAGE_WAITING = 'waiting_confirmation';
    public const STAGE_ESCALATION = 'escalation_needed';
    public const STAGE_RESOLVED = 'resolved';
    public const STAGE_CLOSED = 'closed';

    public const STATUS_LABELS = [
        'open' => 'Aberto',
        'investigating' => 'Em investigação',
        'solution_proposed' => 'Solução proposta',
        'escalation_needed' => 'Inconclusivo',
        'escalated' => 'Escalado',
        'resolved' => 'Resolvido',
        'abandoned' => 'Encerrado',
    ];

    /** Motivos de escalonamento (escolha humana ao marcar como escalado). */
    public const ESCALATION_REASONS = [
        'insufficient_evidence' => 'Não há evidência suficiente para confirmar a causa',
        'checks_exhausted' => 'Todas as verificações disponíveis foram realizadas',
        'missing_access' => 'As hipóteses restantes dependem de acesso que o técnico não possui',
        'vendor_analysis' => 'Depende de análise interna da UNIMESTRE',
        'source_conflict' => 'Há conflito entre fontes que não pôde ser resolvido',
        'forbidden_action' => 'Exige uma ação que o Copiloto não deve executar',
        'product_error' => 'Erro aparentemente interno do produto',
        'docs_recommend' => 'A documentação orienta contato com o suporte',
    ];

    public const MAX_HYPOTHESES = 10;

    /** Similaridade (%) a partir da qual uma pergunta é considerada repetida. */
    public const REPEAT_SIMILARITY = 85.0;

    private DiagnosticSessionRepository $sessions;

    public function __construct(?DiagnosticSessionRepository $sessions = null)
    {
        $this->sessions = $sessions ?? new DiagnosticSessionRepository();
    }

    public function repository(): DiagnosticSessionRepository
    {
        return $this->sessions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeSession(int $conversationId): ?array
    {
        return $this->sessions->activeForConversation($conversationId);
    }

    /**
     * Registra a mensagem do usuário como resposta à última pergunta pendente.
     * Devolve true quando a mensagem respondeu a uma pergunta de diagnóstico.
     *
     * @param array<string, mixed> $session
     */
    public function recordUserAnswer(array $session, string $message, int $userMessageId): bool
    {
        $questions = (array) $session['questions'];
        $last = count($questions) - 1;

        if ($last < 0 || ($questions[$last]['answer'] ?? null) !== null) {
            return false;
        }

        $questions[$last]['answer'] = mb_substr(trim($message), 0, 1000);
        $questions[$last]['answer_message_id'] = $userMessageId;

        $fields = ['questions' => $questions];

        if ($session['status'] === 'open') {
            $fields['status'] = 'investigating';
        }

        $this->sessions->update((int) $session['id'], $fields);

        return true;
    }

    /**
     * Bloco de ESTADO DO DIAGNÓSTICO entregue à IA. Somente dados do backend.
     *
     * @param array<string, mixed> $session
     */
    public function memoryBlock(array $session): string
    {
        $lines = [
            '=== ESTADO DO DIAGNÓSTICO (dados do backend; não é fonte documental — nunca cite como [Fonte n]) ===',
            'PROBLEMA ORIGINAL: ' . $this->oneLine((string) $session['original_problem']),
            'MÓDULO IDENTIFICADO: ' . ($session['identified_module'] ?: 'Não identificado'),
            'CONTEXTO: ' . ($session['identified_context'] ?: 'Não identificado'),
            'STATUS: ' . $session['status'] . ' · ETAPA: ' . $session['current_stage'],
            'RESUMO: ' . ($session['current_summary'] ?: 'Investigação iniciada.'),
            'HIPÓTESES ATUAIS (use o id ao atualizar):',
        ];

        $hypotheses = $this->sessions->hypotheses((int) $session['id']);

        if ($hypotheses === []) {
            $lines[] = '- nenhuma';
        }

        foreach ($hypotheses as $h) {
            $line = '- id=h' . $h['id'] . ' | ' . $h['title'] . ' | status=' . $h['status'];

            if (trim((string) $h['evidence_summary']) !== '') {
                $line .= ' | evidência: ' . $this->oneLine((string) $h['evidence_summary']);
            }

            $lines[] = $line;
        }

        $lines[] = 'PERGUNTAS JÁ FEITAS (NÃO repita nenhuma delas):';

        if ($session['questions'] === []) {
            $lines[] = '- nenhuma';
        }

        foreach ((array) $session['questions'] as $q) {
            $lines[] = '- P: ' . $q['question'] . ' | R do usuário: ' . (($q['answer'] ?? null) !== null ? '"' . $this->oneLine((string) $q['answer']) . '"' : '(sem resposta ainda)');
        }

        $lines[] = 'FONTES JÁ UTILIZADAS:';

        if ($session['sources'] === []) {
            $lines[] = '- nenhuma';
        }

        foreach ((array) $session['sources'] as $s) {
            $lines[] = '- ' . EvidenceType::plainBadge((string) $s['type']) . ': ' . $s['title'];
        }

        $lines[] = '=== FIM DO ESTADO DO DIAGNÓSTICO ===';

        return implode("\n", $lines);
    }

    /**
     * Aplica a resposta estruturada validada ao estado do diagnóstico.
     *
     * @param array<string, mixed>|null $session sessão ativa antes do turno
     * @param array<string, mixed> $structured saída do CopilotResponseValidator
     * @param array<int, array<string, mixed>> $sources fontes enviadas (número = índice + 1)
     * @return array{session: array<string, mixed>, structured: array<string, mixed>}|null
     */
    public function applyTurn(
        int $conversationId,
        ?array $session,
        array $structured,
        array $sources,
        string $problem,
        ?int $assistantMessageId,
        ?string $userAnswer
    ): ?array {
        if (empty($structured['structured'])) {
            return null;
        }

        $isDiagnostic = ($structured['mode'] ?? '') === CopilotResponseValidator::MODE_DIAGNOSTIC
            && (($structured['hypotheses'] ?? []) !== [] || ($structured['next_question'] ?? null) !== null);

        if ($session === null) {
            // Pergunta simples / procedimento: nenhuma sessão.
            if (!$isDiagnostic) {
                return null;
            }

            $top = $sources[0] ?? [];
            $sessionId = $this->sessions->create(
                $conversationId,
                mb_substr(trim($problem), 0, 2000),
                $this->nullable($top['category'] ?? null, 150),
                $this->nullable($top['subcategory'] ?? ($top['title'] ?? null), 255)
            );
            $session = (array) $this->sessions->find($sessionId);

            Audit::record('copilot.diagnostico_aberto', 'diagnostic_session', $sessionId, [
                'conversation_id' => $conversationId,
                'diagnostic_session_id' => $sessionId,
            ]);
        }

        $sessionId = (int) $session['id'];

        $this->applyHypotheses($sessionId, (array) ($structured['hypotheses'] ?? []), $sources, $assistantMessageId, $userAnswer);

        // Pergunta repetida (já feita antes) é descartada pelo backend.
        $questions = (array) $session['questions'];
        $next = $structured['next_question'] ?? null;

        if ($next !== null && $this->isRepeated((string) $next, $questions)) {
            $structured['next_question'] = null;
            $structured['warnings'][] = 'repeated_question_removed';
            $next = null;
        }

        if ($next !== null) {
            $questions[] = [
                'question' => (string) $next,
                'assistant_message_id' => $assistantMessageId,
                'answer' => null,
                'answer_message_id' => null,
            ];
        }

        $hypotheses = $this->sessions->hypotheses($sessionId);
        $status = $session['status'];
        $cause = $session['confirmed_cause'] ?? null;
        $action = $session['recommended_action'] ?? null;

        if (!empty($structured['resolved']) && ($structured['confirmed_cause'] ?? null) !== null && ($structured['recommended_action'] ?? null) !== null) {
            // Nunca "resolved" automático: aguarda a confirmação do usuário.
            $status = 'solution_proposed';
            $cause = (string) $structured['confirmed_cause'];
            $action = (string) $structured['recommended_action'];
        } elseif (in_array($status, ['solution_proposed', 'escalation_needed'], true) || ($status === 'open' && $userAnswer !== null)) {
            // O usuário continuou após a proposta ("não resolveu") ou trouxe nova
            // informação depois do inconclusivo: volta a investigar (7.5).
            $status = 'investigating';
        }

        $answered = count(array_filter($questions, static fn (array $q): bool => ($q['answer'] ?? null) !== null));
        $open = array_filter($hypotheses, static fn (array $h): bool => $h['status'] !== 'rejected');
        $escalation = (array) ($session['escalation'] ?? []);

        // 7.5: escalation_needed (nunca "escalated": isso exige ação humana).
        // Sem escalar prematuramente: exige ao menos uma verificação respondida.
        $exhausted = $hypotheses !== [] && $open === [] && $next === null;
        $suggested = !empty($structured['escalation_recommended']) && $answered > 0;

        if ($status !== 'solution_proposed' && ($exhausted || $suggested)) {
            $status = 'escalation_needed';
            $escalation['suggested_reason'] = $suggested
                ? ($structured['escalation_reason'] ?? null)
                : 'Todas as hipóteses levantadas foram descartadas e não há próxima verificação.';
            $escalation['suggested_at'] = date('Y-m-d H:i:s');
        }

        foreach ((array) ($structured['source_conflicts'] ?? []) as $conflict) {
            $source = $sources[(int) $conflict['source_id'] - 1] ?? null;

            if ($source !== null) {
                $ref = $this->sourceRef($source) + ['claim' => (string) $conflict['claim']];
                $escalation['conflicts'][$ref['type'] . ':' . $ref['title']] = $ref;
            }
        }

        if ($status === 'solution_proposed') {
            $stage = self::STAGE_WAITING;
        } elseif ($status === 'escalation_needed') {
            $stage = self::STAGE_ESCALATION;
        } elseif ($answered > 0) {
            $stage = self::STAGE_TESTING;
        } else {
            $stage = self::STAGE_COLLECTING;
        }

        $fields = [
            'status' => $status,
            'current_stage' => $stage,
            'questions' => $questions,
            'sources' => $this->mergeSources((array) $session['sources'], $sources),
            'confirmed_cause' => $cause,
            'recommended_action' => $action,
            'escalation' => $escalation !== [] ? $escalation : null,
        ];
        $fields['current_summary'] = $this->summarize(
            (string) $session['original_problem'],
            $questions,
            $hypotheses,
            $next,
            $status === 'solution_proposed' ? $cause : null
        );

        $this->sessions->update($sessionId, $fields);

        return ['session' => (array) $this->sessions->find($sessionId), 'structured' => $structured];
    }

    /**
     * "Voltar ao diagnóstico": escalation_needed → investigating, sem perder histórico.
     *
     * @param array<string, mixed> $session
     */
    public function reopen(array $session, int $userId): bool
    {
        if ($session['status'] !== 'escalation_needed') {
            return false;
        }

        $this->sessions->update((int) $session['id'], ['status' => 'investigating', 'current_stage' => self::STAGE_TESTING]);
        Audit::record('copilot.diagnostico_retomado', 'diagnostic_session', (int) $session['id'], [
            'diagnostic_session_id' => (int) $session['id'],
            'conversation_id' => (int) $session['conversation_id'],
            'user_id' => $userId,
        ]);

        return true;
    }

    /**
     * Marca como escalado (AÇÃO HUMANA). Congela o resumo gerado pelo backend.
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed> $summary resumo de EscalationSummaryService
     */
    public function markEscalated(array $session, int $userId, string $reason, array $summary, ?string $externalReference): void
    {
        $now = date('Y-m-d H:i:s');

        $this->sessions->update((int) $session['id'], [
            'status' => 'escalated',
            'current_stage' => self::STAGE_CLOSED,
            'closed_at' => $now,
            'escalated_at' => $now,
            'escalated_by' => $userId,
            'escalation_reason' => mb_substr($reason, 0, 255),
            'escalation_summary' => $summary,
            'external_reference' => $externalReference,
        ]);

        Audit::record('copilot.diagnostico_escalado', 'diagnostic_session', (int) $session['id'], [
            'diagnostic_session_id' => (int) $session['id'],
            'conversation_id' => (int) $session['conversation_id'],
            'user_id' => $userId,
            'reason' => $reason,
            'external_reference' => $externalReference,
            'previous_status' => $session['status'],
            'timestamp' => $now,
        ]);
    }

    /**
     * Confirmação HUMANA de que o problema foi resolvido.
     *
     * @param array<string, mixed> $session
     */
    public function markResolved(array $session, int $userId): void
    {
        $this->close($session, 'resolved', self::STAGE_RESOLVED, $userId);
    }

    /**
     * @param array<string, mixed> $session
     */
    public function abandon(array $session, int $userId, ?string $reason = null): void
    {
        $this->close($session, 'abandoned', self::STAGE_CLOSED, $userId, $reason);
    }

    /**
     * Estrutura pública do diagnóstico para a API/UI (sem termos internos).
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function present(array $session): array
    {
        $questions = (array) $session['questions'];
        $last = $questions !== [] ? $questions[count($questions) - 1] : null;

        return [
            'session_id' => (int) $session['id'],
            'status' => (string) $session['status'],
            'status_label' => self::STATUS_LABELS[$session['status']] ?? (string) $session['status'],
            'stage' => (string) $session['current_stage'],
            'summary' => (string) ($session['current_summary'] ?? ''),
            'hypotheses' => array_map(static fn (array $h): array => [
                'id' => 'h' . $h['id'],
                'title' => (string) $h['title'],
                'status' => (string) $h['status'],
                'status_label' => CopilotResponseValidator::STATUS_LABELS[$h['status']] ?? (string) $h['status'],
                'evidence_summary' => (string) ($h['evidence_summary'] ?? ''),
            ], $this->sessions->hypotheses((int) $session['id'])),
            'next_question' => ($last !== null && ($last['answer'] ?? null) === null) ? (string) $last['question'] : null,
            'confirmed_cause' => $session['confirmed_cause'] ?? null,
            'recommended_action' => $session['recommended_action'] ?? null,
            'active' => in_array($session['status'], DiagnosticSessionRepository::ACTIVE_STATUSES, true),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items hipóteses validadas do turno
     * @param array<int, array<string, mixed>> $sources
     */
    private function applyHypotheses(int $sessionId, array $items, array $sources, ?int $messageId, ?string $userAnswer): void
    {
        $existing = $this->sessions->hypotheses($sessionId);
        $byId = [];
        $byKey = [];

        foreach ($existing as $h) {
            $byId[$h['id']] = $h;
            $byKey[$h['title_key']] = $h;
        }

        $count = count($existing);

        foreach ($items as $item) {
            $key = $this->titleKey((string) $item['title']);
            $ref = isset($item['ref']) ? (int) $item['ref'] : null;
            // O id só vale se pertencer a ESTA sessão (sem vazamento entre conversas).
            $current = ($ref !== null && isset($byId[$ref])) ? $byId[$ref] : ($byKey[$key] ?? null);

            $refs = $this->sourceRefs((array) $item['source_ids'], $sources);
            $reason = trim((string) ($item['reason'] ?? ''));
            $evidence = $reason;

            if ($userAnswer !== null && $current !== null && $current['status'] !== $item['status']) {
                $evidence = trim('Resposta do usuário: "' . mb_substr($this->oneLine($userAnswer), 0, 200) . '". ' . $reason);
            }

            $evidence = $evidence !== '' ? mb_substr($evidence, 0, 1000) : null;

            if ($current === null) {
                if ($count >= self::MAX_HYPOTHESES || $key === '') {
                    continue;
                }

                $id = $this->sessions->createHypothesis($sessionId, (string) $item['title'], $key, $reason !== '' ? $reason : null, (string) $item['status'], $evidence, [
                    'source_refs' => $refs,
                    'message_ids' => $messageId !== null ? [$messageId] : [],
                ]);
                $this->sessions->addEvent($id, null, (string) $item['status'], $messageId, $evidence);
                $byKey[$key] = ['id' => $id, 'status' => $item['status'], 'title_key' => $key];
                $byId[$id] = $byKey[$key];
                $count++;

                continue;
            }

            $metadata = (array) ($current['metadata'] ?? []);
            $metadata['source_refs'] = $this->mergeRefs((array) ($metadata['source_refs'] ?? []), $refs);
            $metadata['message_ids'] = array_values(array_unique(array_merge((array) ($metadata['message_ids'] ?? []), $messageId !== null ? [$messageId] : [])));

            $this->sessions->updateHypothesis((int) $current['id'], (string) $item['status'], $evidence ?? ($current['evidence_summary'] ?? null), $metadata);

            if ($current['status'] !== $item['status']) {
                $this->sessions->addEvent((int) $current['id'], (string) $current['status'], (string) $item['status'], $messageId, $evidence);
                Audit::record('copilot.hipotese_status', 'diagnostic_hypothesis', (int) $current['id'], [
                    'diagnostic_session_id' => $sessionId,
                    'from' => $current['status'],
                    'to' => $item['status'],
                    'message_id' => $messageId,
                ]);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     */
    private function isRepeated(string $question, array $questions): bool
    {
        $candidate = $this->titleKey($question);

        foreach ($questions as $q) {
            $previous = $this->titleKey((string) ($q['question'] ?? ''));

            if ($previous === '') {
                continue;
            }

            if ($previous === $candidate) {
                return true;
            }

            similar_text($previous, $candidate, $percent);

            if ($percent >= self::REPEAT_SIMILARITY) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resumo curto e factual, montado só com dados já registrados.
     *
     * @param array<int, array<string, mixed>> $questions
     * @param array<int, array<string, mixed>> $hypotheses
     */
    private function summarize(string $problem, array $questions, array $hypotheses, ?string $next, ?string $proposedCause): string
    {
        $parts = ['Problema relatado: ' . mb_substr($this->oneLine($problem), 0, 200) . '.'];

        $answered = array_values(array_filter($questions, static fn (array $q): bool => ($q['answer'] ?? null) !== null));

        foreach (array_slice($answered, -3) as $q) {
            $parts[] = 'Pergunta: "' . mb_substr($this->oneLine((string) $q['question']), 0, 150) . '" — usuário respondeu: "' . mb_substr($this->oneLine((string) $q['answer']), 0, 150) . '".';
        }

        if ($hypotheses !== []) {
            $parts[] = 'Hipóteses: ' . implode('; ', array_map(
                static fn (array $h): string => $h['title'] . ' (' . (CopilotResponseValidator::STATUS_LABELS[$h['status']] ?? $h['status']) . ')',
                $hypotheses
            )) . '.';
        }

        if ($proposedCause !== null) {
            $parts[] = 'Solução proposta, aguardando confirmação do usuário. Causa indicada: ' . mb_substr($this->oneLine($proposedCause), 0, 200) . '.';
        } elseif ($next !== null) {
            $parts[] = 'Próxima verificação: ' . mb_substr($this->oneLine($next), 0, 200);
        }

        return mb_substr(implode(' ', $parts), 0, 1500);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function close(array $session, string $status, string $stage, int $userId, ?string $reason = null): void
    {
        $fields = [
            'status' => $status,
            'current_stage' => $stage,
            'closed_at' => date('Y-m-d H:i:s'),
        ];

        if ($reason !== null) {
            // Motivo do encerramento fica no resumo (e na auditoria).
            $fields['current_summary'] = trim((string) ($session['current_summary'] ?? '') . ' Encerrado pelo usuário: ' . $reason . '.');
        }

        $this->sessions->update((int) $session['id'], $fields);

        Audit::record('copilot.diagnostico_' . $status, 'diagnostic_session', (int) $session['id'], [
            'diagnostic_session_id' => (int) $session['id'],
            'conversation_id' => (int) $session['conversation_id'],
            'user_id' => $userId,
            'previous_status' => $session['status'],
            'reason' => $reason,
        ]);
    }

    /**
     * @param array<int, int> $ids números de fonte (1..n)
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    private function sourceRefs(array $ids, array $sources): array
    {
        $refs = [];

        foreach ($ids as $id) {
            $source = $sources[$id - 1] ?? null;

            if ($source !== null) {
                $refs[] = $this->sourceRef($source);
            }
        }

        return $refs;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public function sourceRef(array $source): array
    {
        return [
            'type' => EvidenceType::fromSource($source),
            'title' => mb_substr((string) ($source['title'] ?? ''), 0, 200),
            'document_id' => isset($source['document_id']) ? (int) $source['document_id'] : null,
            'chunk_id' => isset($source['chunk_id']) ? (int) $source['chunk_id'] : null,
            'solution_id' => isset($source['solution_id']) ? (int) $source['solution_id'] : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $current
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    private function mergeSources(array $current, array $sources): array
    {
        return $this->mergeRefs($current, array_map(fn (array $s): array => $this->sourceRef($s), $sources));
    }

    /**
     * @param array<int, array<string, mixed>> $a
     * @param array<int, array<string, mixed>> $b
     * @return array<int, array<string, mixed>>
     */
    private function mergeRefs(array $a, array $b): array
    {
        $merged = [];

        foreach (array_merge($a, $b) as $ref) {
            $merged[($ref['document_id'] ?? '') . ':' . ($ref['solution_id'] ?? '') . ':' . ($ref['title'] ?? '')] = $ref;
        }

        return array_slice(array_values($merged), -20);
    }

    public function titleKey(string $text): string
    {
        $text = mb_strtolower($this->oneLine($text));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = is_string($ascii) && $ascii !== '' ? $ascii : $text;
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        return mb_substr(trim($text), 0, 160);
    }

    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param mixed $value
     */
    private function nullable($value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
