<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Logger;
use App\Services\Copilot\EvidenceType;

/**
 * Persistência das conversas do chat: conversations, messages,
 * message_sources, feedback e o guard de idempotência (chat_request_guards).
 *
 * A escrita de uma conversa é sempre feita dentro da transação do
 * ChatService; estes métodos não abrem transações próprias.
 */
final class ConversationRepository
{
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, title, is_favorite, created_at, updated_at
             FROM conversations WHERE id = ?'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByUser(int $id, int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, title, is_favorite, created_at, updated_at
             FROM conversations WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function createForUser(int $userId, string $title): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO conversations (user_id, title, is_favorite) VALUES (?, ?, 0)'
        );
        $stmt->execute([$userId, $title]);

        return (int) Database::connection()->lastInsertId();
    }

    public function touch(int $conversationId): void
    {
        Database::connection()->prepare(
            'UPDATE conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        )->execute([$conversationId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT c.id, c.title, c.is_favorite, c.created_at, c.updated_at,
                    (SELECT COUNT(*) FROM messages m
                     WHERE m.conversation_id = c.id AND m.role = 'user') AS questions,
                    (SELECT MAX(m.created_at) FROM messages m
                     WHERE m.conversation_id = c.id) AS last_message_at
             FROM conversations c
             WHERE c.user_id = ?
             ORDER BY COALESCE(last_message_at, c.updated_at) DESC, c.id DESC"
        );
        $stmt->execute([$userId]);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'is_favorite' => (bool) $row['is_favorite'],
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
                'questions' => (int) $row['questions'],
                'last_message_at' => $row['last_message_at'] !== null ? (string) $row['last_message_at'] : null,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function addMessage(int $conversationId, string $role, string $content, array $meta = []): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO messages
                (conversation_id, role, content, provider, model,
                 tokens_input, tokens_output, tokens_total, latency_ms,
                 request_id, status, finish_reason, truncated, structured_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $conversationId,
            $role,
            $content,
            isset($meta['provider']) ? (string) $meta['provider'] : null,
            isset($meta['model']) ? (string) $meta['model'] : null,
            isset($meta['tokens_input']) ? (int) $meta['tokens_input'] : null,
            isset($meta['tokens_output']) ? (int) $meta['tokens_output'] : null,
            isset($meta['tokens_total']) ? (int) $meta['tokens_total'] : null,
            isset($meta['latency_ms']) ? (int) round((float) $meta['latency_ms']) : null,
            isset($meta['request_id']) ? (string) $meta['request_id'] : null,
            (string) ($meta['status'] ?? 'ok'),
            isset($meta['finish_reason']) ? (string) $meta['finish_reason'] : null,
            (int) (bool) ($meta['truncated'] ?? false),
            isset($meta['structured']) && is_array($meta['structured'])
                ? json_encode($meta['structured'], JSON_UNESCAPED_UNICODE)
                : null,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Ajusta o texto exibido e a estrutura validada de uma resposta já gravada
     * (7.2: o estado do diagnóstico pode descartar uma pergunta repetida).
     *
     * @param array<string, mixed> $structured
     */
    public function updateAssistantOutput(int $messageId, string $content, array $structured): void
    {
        Database::connection()->prepare(
            "UPDATE messages SET content = ?, structured_json = ? WHERE id = ? AND role = 'assistant'"
        )->execute([$content, json_encode($structured, JSON_UNESCAPED_UNICODE), $messageId]);
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     */
    public function addMessageSources(int $messageId, array $sources): void
    {
        if ($sources === []) {
            return;
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO message_sources
                (message_id, document_id, chunk_id, solution_id, source_type, relevance_score)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($sources as $source) {
            $sourceType = EvidenceType::fromSource($source);

            $stmt->execute([
                $messageId,
                isset($source['document_id']) && $source['document_id'] !== null ? (int) $source['document_id'] : null,
                isset($source['chunk_id']) && $source['chunk_id'] !== null ? (int) $source['chunk_id'] : null,
                isset($source['solution_id']) && $source['solution_id'] !== null ? (int) $source['solution_id'] : null,
                $sourceType,
                isset($source['relevance_score']) ? (float) $source['relevance_score'] : null,
            ]);
        }
    }

    /**
     * Primeira pergunta do usuário na conversa (o problema original), usada
     * para manter a consulta de retrieval ancorada no assunto do atendimento.
     */
    public function firstUserMessage(int $conversationId): ?string
    {
        $stmt = Database::connection()->prepare(
            "SELECT content FROM messages
             WHERE conversation_id = ? AND role = 'user' AND TRIM(content) <> ''
             ORDER BY created_at ASC, id ASC
             LIMIT 1"
        );
        $stmt->execute([$conversationId]);

        $content = $stmt->fetchColumn();

        return $content === false ? null : (string) $content;
    }

    /**
     * Histórico para o CONTEXTO DA IA: somente turnos completos (pergunta do
     * usuário imediatamente seguida de resposta do assistente), os últimos
     * $limit itens, em ordem cronológica.
     *
     * Perguntas "órfãs" (sem resposta, ex.: provider_failure/429) ficam de
     * fora do contexto, mas continuam no banco e no histórico visual.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function recentCompletedTurns(int $conversationId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        // Janela maior que o limite: órfãs descartadas não podem encurtar o histórico útil.
        $window = min(200, $limit * 4);

        $stmt = Database::connection()->prepare(
            "SELECT role, content FROM (
                 SELECT id, role, content, created_at FROM messages
                 WHERE conversation_id = ?
                   AND role IN ('user', 'assistant')
                   AND TRIM(content) <> ''
                 ORDER BY created_at DESC, id DESC
                 LIMIT {$window}
             ) recent
             ORDER BY created_at ASC, id ASC"
        );
        $stmt->execute([$conversationId]);
        $rows = $stmt->fetchAll();

        $turns = [];
        $count = count($rows);

        for ($i = 0; $i < $count; $i++) {
            $role = (string) $rows[$i]['role'];
            $next = $rows[$i + 1]['role'] ?? null;

            if ($role === 'user' && $next === 'assistant') {
                $turns[] = ['role' => 'user', 'content' => (string) $rows[$i]['content']];
                $turns[] = ['role' => 'assistant', 'content' => (string) $rows[$i + 1]['content']];
                $i++;
            }
        }

        return array_slice($turns, -$limit);
    }

    /**
     * Mensagens recentes para servir de histórico de follow-up.
     * Retorna apenas papéis user/assistant com conteúdo não vazio.
     *
     * Seleciona as ÚLTIMAS $limit mensagens (DESC + LIMIT) e as devolve em
     * ordem cronológica (mais antiga → mais recente), como a IA espera.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function recentMessages(int $conversationId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $stmt = Database::connection()->prepare(
            "SELECT role, content FROM (
                 SELECT id, role, content, created_at FROM messages
                 WHERE conversation_id = ?
                   AND role IN ('user', 'assistant')
                   AND TRIM(content) <> ''
                 ORDER BY created_at DESC, id DESC
                 LIMIT {$limit}
             ) recent
             ORDER BY created_at ASC, id ASC"
        );
        $stmt->execute([$conversationId]);

        $messages = [];

        foreach ($stmt->fetchAll() as $row) {
            $messages[] = [
                'role' => (string) $row['role'],
                'content' => (string) $row['content'],
            ];
        }

        return $messages;
    }

    /**
     * Mensagens da conversa com as fontes persistidas (títulos/URLs reais
     * vindos do banco via JOIN, nunca do LLM).
     *
     * @return array<int, array<string, mixed>>
     */
    public function messagesWithSources(int $conversationId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, role, content, provider, model,
                    tokens_input, tokens_output, tokens_total, latency_ms,
                    request_id, status, finish_reason, truncated, structured_json, created_at
             FROM messages
             WHERE conversation_id = ?
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$conversationId]);
        $rows = $stmt->fetchAll();

        $messages = [];

        foreach ($rows as $row) {
            $messages[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'role' => (string) $row['role'],
                'content' => (string) $row['content'],
                'provider' => $row['provider'] !== null ? (string) $row['provider'] : null,
                'model' => $row['model'] !== null ? (string) $row['model'] : null,
                'tokens_input' => $row['tokens_input'] !== null ? (int) $row['tokens_input'] : null,
                'tokens_output' => $row['tokens_output'] !== null ? (int) $row['tokens_output'] : null,
                'tokens_total' => $row['tokens_total'] !== null ? (int) $row['tokens_total'] : null,
                'latency_ms' => $row['latency_ms'] !== null ? (int) $row['latency_ms'] : null,
                'request_id' => $row['request_id'] !== null ? (string) $row['request_id'] : null,
                'status' => (string) $row['status'],
                'finish_reason' => $row['finish_reason'] !== null ? (string) $row['finish_reason'] : null,
                'truncated' => (bool) $row['truncated'],
                'structured' => $row['structured_json'] !== null ? json_decode((string) $row['structured_json'], true) : null,
                'created_at' => (string) $row['created_at'],
                'sources' => [],
            ];
        }

        if ($messages === []) {
            return array_values($messages);
        }

        $ids = array_keys($messages);
        $in = implode(',', array_map('intval', $ids));

        $sourcesStmt = Database::connection()->query(
            "SELECT ms.message_id, ms.source_type, ms.relevance_score,
                    ms.document_id, ms.chunk_id, ms.solution_id,
                    d.title AS doc_title, d.url AS doc_url, d.category AS doc_category,
                    sol.title AS sol_title, sol.category AS sol_category
             FROM message_sources ms
             LEFT JOIN knowledge_documents d ON d.id = ms.document_id
             LEFT JOIN internal_solutions sol ON sol.id = ms.solution_id
             WHERE ms.message_id IN ({$in})
             ORDER BY ms.id ASC"
        );

        foreach ($sourcesStmt->fetchAll() as $source) {
            $messageId = (int) $source['message_id'];

            if (!isset($messages[$messageId])) {
                continue;
            }

            $messages[$messageId]['sources'][] = [
                'source_type' => (string) $source['source_type'],
                'relevance_score' => $source['relevance_score'] !== null ? (float) $source['relevance_score'] : null,
                'document_id' => $source['document_id'] !== null ? (int) $source['document_id'] : null,
                'chunk_id' => $source['chunk_id'] !== null ? (int) $source['chunk_id'] : null,
                'solution_id' => $source['solution_id'] !== null ? (int) $source['solution_id'] : null,
                'title' => (string) ($source['doc_title'] ?? $source['sol_title'] ?? 'Sem título'),
                'url' => $source['doc_url'] !== null ? (string) $source['doc_url'] : null,
                'category' => $source['doc_category'] !== null
                    ? (string) $source['doc_category']
                    : ($source['sol_category'] !== null ? (string) $source['sol_category'] : null),
            ];
        }

        return array_values($messages);
    }

    public function findMessage(int $messageId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM messages WHERE id = ?');
        $stmt->execute([$messageId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Pergunta do usuário imediatamente anterior a uma mensagem da conversa.
     */
    public function findPreviousUserMessage(int $conversationId, string $createdAt, int $beforeMessageId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id, conversation_id, content FROM messages
             WHERE conversation_id = ?
               AND role = 'user'
               AND (created_at < ? OR (created_at = ? AND id < ?))
             ORDER BY created_at DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute([$conversationId, $createdAt, $createdAt, $beforeMessageId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function messageBelongsToUser(int $messageId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.id FROM messages m
             JOIN conversations c ON c.id = m.conversation_id
             WHERE m.id = ? AND c.user_id = ?'
        );
        $stmt->execute([$messageId, $userId]);

        return $stmt->fetchColumn() !== false;
    }

    public function upsertFeedback(int $messageId, int $userId, string $rating, ?string $comment): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO feedback (message_id, user_id, rating, comment)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = COALESCE(VALUES(comment), comment)'
        );
        $stmt->execute([$messageId, $userId, $rating, $comment]);
    }

    public function feedbackForMessage(int $messageId, int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT rating, comment FROM feedback WHERE message_id = ? AND user_id = ?'
        );
        $stmt->execute([$messageId, $userId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Claim de idempotência (melhor esforço, documentado). Retorna true se a
     * requisição ainda não havia sido processada para o usuário.
     */
    public function claimRequest(int $userId, string $clientRequestId): bool
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO chat_request_guards (user_id, client_request_id, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)'
            )->execute([$userId, $clientRequestId]);

            return true;
        } catch (\PDOException $exception) {
            if (str_contains((string) $exception->getMessage(), 'Duplicate entry')) {
                return false;
            }

            // Fail-open documentado: em falha de infraestrutura do guard, a
            // requisição segue adiante (a idempotência primária é o bloqueio
            // do botão no frontend).
            Logger::warning('chat', 'Guard de idempotência indisponível', [
                'user_id' => $userId,
            ]);

            return true;
        }
    }

    public function clearRequestGuard(int $userId, string $clientRequestId): void
    {
        Database::connection()->prepare(
            'DELETE FROM chat_request_guards WHERE user_id = ? AND client_request_id = ?'
        )->execute([$userId, $clientRequestId]);
    }
}