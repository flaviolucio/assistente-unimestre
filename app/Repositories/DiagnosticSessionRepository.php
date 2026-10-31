<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Persistência das sessões de diagnóstico do Copiloto (Etapa 7.2):
 * diagnostic_sessions, diagnostic_hypotheses e diagnostic_hypothesis_events.
 * Não confundir com App\Services\DiagnosticService (saúde do sistema).
 */
final class DiagnosticSessionRepository
{
    /** escalation_needed continua ativa: nova informação volta para investigating. */
    public const ACTIVE_STATUSES = ['open', 'investigating', 'solution_proposed', 'escalation_needed'];

    /**
     * Sessão ativa (no máximo uma) da conversa.
     *
     * @return array<string, mixed>|null
     */
    public function activeForConversation(int $conversationId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM diagnostic_sessions
             WHERE conversation_id = ? AND status IN ('open', 'investigating', 'solution_proposed', 'escalation_needed')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$conversationId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestForConversation(int $conversationId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM diagnostic_sessions WHERE conversation_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$conversationId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM diagnostic_sessions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Sessão, desde que a conversa pertença ao usuário (ownership).
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(int $id, int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.* FROM diagnostic_sessions s
             JOIN conversations c ON c.id = s.conversation_id
             WHERE s.id = ? AND c.user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(int $conversationId, string $problem, ?string $module, ?string $context): int
    {
        $stmt = Database::connection()->prepare(
            "INSERT INTO diagnostic_sessions
                (conversation_id, status, original_problem, identified_module, identified_context,
                 current_stage, questions_json, sources_json)
             VALUES (?, 'open', ?, ?, ?, 'collecting_context', '[]', '[]')"
        );
        $stmt->execute([$conversationId, $problem, $module, $context]);

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields colunas permitidas; arrays viram JSON
     */
    public function update(int $id, array $fields): void
    {
        $allowed = [
            'status', 'identified_module', 'identified_context', 'current_summary', 'current_stage',
            'confirmed_cause', 'recommended_action', 'questions', 'sources', 'closed_at',
            'escalation', 'escalated_at', 'escalated_by', 'escalation_reason', 'escalation_summary', 'external_reference',
        ];
        $sets = [];
        $values = [];

        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }

            if ($column === 'questions' || $column === 'sources') {
                $column .= '_json';
                $value = json_encode(array_values((array) $value), JSON_UNESCAPED_UNICODE);
            } elseif ($column === 'escalation' || $column === 'escalation_summary') {
                $column .= '_json';
                $value = $value === null ? null : json_encode((array) $value, JSON_UNESCAPED_UNICODE);
            }

            $sets[] = $column . ' = ?';
            $values[] = $value;
        }

        if ($sets === []) {
            return;
        }

        $values[] = $id;
        Database::connection()->prepare('UPDATE diagnostic_sessions SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($values);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function hypotheses(int $sessionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM diagnostic_hypotheses WHERE diagnostic_session_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$sessionId]);

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['diagnostic_session_id'] = (int) $row['diagnostic_session_id'];
            $row['metadata'] = $row['metadata'] !== null ? (json_decode((string) $row['metadata'], true) ?: []) : [];

            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function createHypothesis(
        int $sessionId,
        string $title,
        string $titleKey,
        ?string $description,
        string $status,
        ?string $evidenceSummary,
        array $metadata
    ): int {
        $stmt = Database::connection()->prepare(
            'INSERT INTO diagnostic_hypotheses
                (diagnostic_session_id, title, title_key, description, status, evidence_summary, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sessionId,
            $title,
            $titleKey,
            $description,
            $status,
            $evidenceSummary,
            json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function updateHypothesis(int $id, string $status, ?string $evidenceSummary, array $metadata): void
    {
        Database::connection()->prepare(
            'UPDATE diagnostic_hypotheses SET status = ?, evidence_summary = ?, metadata = ? WHERE id = ?'
        )->execute([$status, $evidenceSummary, json_encode($metadata, JSON_UNESCAPED_UNICODE), $id]);
    }

    public function addEvent(int $hypothesisId, ?string $from, string $to, ?int $messageId, ?string $reason): void
    {
        Database::connection()->prepare(
            'INSERT INTO diagnostic_hypothesis_events
                (diagnostic_hypothesis_id, from_status, to_status, message_id, reason)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$hypothesisId, $from, $to, $messageId, $reason]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function events(int $hypothesisId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM diagnostic_hypothesis_events WHERE diagnostic_hypothesis_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$hypothesisId]);

        return $stmt->fetchAll();
    }

    /**
     * Histórico de status de todas as hipóteses da sessão (ordem cronológica).
     *
     * @return array<int, array<string, mixed>>
     */
    public function eventsForSession(int $sessionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT e.* FROM diagnostic_hypothesis_events e
             JOIN diagnostic_hypotheses h ON h.id = e.diagnostic_hypothesis_id
             WHERE h.diagnostic_session_id = ?
             ORDER BY e.id ASC'
        );
        $stmt->execute([$sessionId]);

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['conversation_id'] = (int) $row['conversation_id'];
        $row['questions'] = json_decode((string) ($row['questions_json'] ?? '[]'), true) ?: [];
        $row['sources'] = json_decode((string) ($row['sources_json'] ?? '[]'), true) ?: [];
        $row['escalation'] = json_decode((string) ($row['escalation_json'] ?? ''), true) ?: [];
        $row['escalation_summary'] = json_decode((string) ($row['escalation_summary_json'] ?? ''), true) ?: null;
        unset($row['questions_json'], $row['sources_json'], $row['escalation_json'], $row['escalation_summary_json']);

        return $row;
    }
}
