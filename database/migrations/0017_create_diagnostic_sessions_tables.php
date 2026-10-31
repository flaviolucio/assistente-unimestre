<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Etapa 7.2 — sessões de diagnóstico persistidas.
 *
 * diagnostic_sessions: estado da investigação de uma conversa. Uma conversa
 *   pode ter várias sessões ao longo do tempo, mas no máximo uma ativa
 *   (open/investigating/solution_proposed) — regra imposta pelo serviço.
 *   questions_json guarda as perguntas feitas e as respostas do usuário;
 *   sources_json, as fontes documentais já usadas (ids reais do backend).
 * diagnostic_hypotheses: hipóteses da sessão (status qualitativo, sem %).
 * diagnostic_hypothesis_events: histórico de mudança de status (auditoria).
 *
 * As sessões seguem o ciclo de vida da conversa (CASCADE): nada é apagado
 * automaticamente enquanto a conversa existir.
 */
$up = static function (): void {
    $db = Database::connection();

    $db->exec("CREATE TABLE IF NOT EXISTS diagnostic_sessions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        conversation_id INT UNSIGNED NOT NULL,
        status ENUM('open', 'investigating', 'solution_proposed', 'resolved', 'abandoned') NOT NULL DEFAULT 'open',
        original_problem TEXT NOT NULL,
        identified_module VARCHAR(150) NULL,
        identified_context VARCHAR(255) NULL,
        current_summary TEXT NULL,
        current_stage VARCHAR(30) NOT NULL DEFAULT 'collecting_context',
        confirmed_cause TEXT NULL,
        recommended_action TEXT NULL,
        questions_json LONGTEXT NULL,
        sources_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        closed_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_diagnostic_sessions_conversation (conversation_id, status),
        CONSTRAINT fk_diagnostic_sessions_conversation FOREIGN KEY (conversation_id)
            REFERENCES conversations (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS diagnostic_hypotheses (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        diagnostic_session_id INT UNSIGNED NOT NULL,
        title VARCHAR(160) NOT NULL,
        title_key VARCHAR(160) NOT NULL,
        description TEXT NULL,
        status ENUM('possible', 'supported', 'weakened', 'rejected', 'confirmed') NOT NULL DEFAULT 'possible',
        evidence_summary TEXT NULL,
        metadata LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_diagnostic_hypotheses_key (diagnostic_session_id, title_key),
        CONSTRAINT fk_diagnostic_hypotheses_session FOREIGN KEY (diagnostic_session_id)
            REFERENCES diagnostic_sessions (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS diagnostic_hypothesis_events (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        diagnostic_hypothesis_id INT UNSIGNED NOT NULL,
        from_status VARCHAR(20) NULL,
        to_status VARCHAR(20) NOT NULL,
        message_id INT UNSIGNED NULL,
        reason TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_diagnostic_hypothesis_events_hypothesis (diagnostic_hypothesis_id),
        CONSTRAINT fk_diagnostic_hypothesis_events_hypothesis FOREIGN KEY (diagnostic_hypothesis_id)
            REFERENCES diagnostic_hypotheses (id) ON DELETE CASCADE,
        CONSTRAINT fk_diagnostic_hypothesis_events_message FOREIGN KEY (message_id)
            REFERENCES messages (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};

$down = static function (): void {
    $db = Database::connection();
    $db->exec('DROP TABLE IF EXISTS diagnostic_hypothesis_events');
    $db->exec('DROP TABLE IF EXISTS diagnostic_hypotheses');
    $db->exec('DROP TABLE IF EXISTS diagnostic_sessions');
};

return [
    'version' => '0017',
    'name' => 'create_diagnostic_sessions_tables',
    'up' => $up,
    'down' => $down,
];
