<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Fase 6 — RAG + Chat + Fontes.
 *
 * 1) messages ganha tokens_total, finish_reason e truncated (metadados da
 *    resposta do provider, exigidos para o tratamento de respostas truncadas).
 * 2) message_sources ganha solution_id (fonte do tipo PROCEDIMENTO INTERNO
 *    vinda de internal_solutions) — os demais identificadores reais
 *    (document_id/chunk_id) já existiam.
 * 3) feedback ganha chave única por (message_id, user_id) para evitar
 *    registros conflitantes (upsert de avaliação).
 * 4) Nova tabela chat_limits (rate limit por usuário autenticado, sem Redis).
 * 5) Defaults de configuração da Fase 6 aplicados ao banco existente
 *    (rag.max_context_tokens, rag.history_messages, chat.rate_limit_per_minute,
 *    chat.max_question_length) — reutiliza a infra de system_settings.
 */
$up = static function (): void {
    $db = Database::connection();

    foreach ([
        "ALTER TABLE messages
            ADD COLUMN tokens_total INT UNSIGNED NULL AFTER tokens_output,
            ADD COLUMN finish_reason VARCHAR(30) NULL AFTER tokens_total,
            ADD COLUMN truncated TINYINT(1) NOT NULL DEFAULT 0 AFTER finish_reason",
        "ALTER TABLE message_sources
            ADD COLUMN solution_id INT UNSIGNED NULL AFTER chunk_id,
            ADD KEY idx_message_sources_solution (solution_id),
            ADD CONSTRAINT fk_message_sources_solution FOREIGN KEY (solution_id)
                REFERENCES internal_solutions (id) ON DELETE SET NULL",
        "ALTER TABLE feedback
            ADD UNIQUE KEY uq_feedback_message_user (message_id, user_id)",
    ] as $sql) {
        try {
            $db->exec($sql);
        } catch (\PDOException $exception) {
            if (str_contains((string) $exception->getMessage(), 'Duplicate column name')
                || str_contains((string) $exception->getMessage(), 'Duplicate key name')
                || str_contains((string) $exception->getMessage(), 'Duplicate entry')
            ) {
                continue;
            }

            throw $exception;
        }
    }

    $db->exec('CREATE TABLE IF NOT EXISTS chat_limits (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        window_started_at DATETIME NOT NULL,
        request_count INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uq_chat_limits_user (user_id),
        CONSTRAINT fk_chat_limits_user FOREIGN KEY (user_id)
            REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $db->exec('CREATE TABLE IF NOT EXISTS chat_request_guards (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        client_request_id VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_chat_request_guards_user_client (user_id, client_request_id),
        KEY idx_chat_request_guards_created (created_at),
        CONSTRAINT fk_chat_request_guards_user FOREIGN KEY (user_id)
            REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $settings = [
        ['rag.max_context_tokens', '4000', 'rag'],
        ['rag.history_messages', '6', 'rag'],
        ['chat.rate_limit_per_minute', '10', 'chat'],
        ['chat.max_question_length', '4000', 'chat'],
    ];

    $select = $db->prepare('SELECT setting_key FROM system_settings WHERE setting_key = ?');
    $insert = $db->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, category) VALUES (?, ?, ?)'
    );

    foreach ($settings as [$key, $value, $category]) {
        $select->execute([$key]);

        if ($select->fetchColumn() === false) {
            $insert->execute([$key, $value, $category]);
        }
    }
};

$down = static function (): void {
    $db = Database::connection();

    $db->exec('ALTER TABLE feedback DROP INDEX uq_feedback_message_user');
    $db->exec('ALTER TABLE message_sources
        DROP FOREIGN KEY fk_message_sources_solution,
        DROP INDEX idx_message_sources_solution,
        DROP COLUMN solution_id');
    $db->exec('ALTER TABLE messages
        DROP COLUMN truncated,
        DROP COLUMN finish_reason,
        DROP COLUMN tokens_total');
    $db->exec('DROP TABLE IF EXISTS chat_limits');
    $db->exec('DROP TABLE IF EXISTS chat_request_guards');

    $db->prepare('DELETE FROM system_settings WHERE setting_key IN (?, ?, ?, ?)')
        ->execute([
            'rag.max_context_tokens',
            'rag.history_messages',
            'chat.rate_limit_per_minute',
            'chat.max_question_length',
        ]);
};

return [
    'version' => '0015',
    'name' => 'add_chat_rag_columns_and_limits',
    'up' => $up,
    'down' => $down,
];