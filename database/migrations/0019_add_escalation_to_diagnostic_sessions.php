<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Etapa 7.5 — escalonamento de diagnósticos inconclusivos.
 *
 * Sem tabela nova: o escalonamento pertence à sessão (1:1).
 *  - status ganha 'escalation_needed' (ativo: precisa de ajuda externa/humana;
 *    pode voltar a 'investigating') e 'escalated' (encaminhado por AÇÃO HUMANA);
 *  - escalation_json: sugestão da IA (motivo) e divergências entre fontes;
 *  - escalated_at/by, escalation_reason, escalation_summary_json (resumo
 *    congelado no momento do encaminhamento), external_reference (opcional).
 *
 * Ponto de extensão (sem UI nesta etapa): knowledge_sources.type aceita
 * 'vendor_support' e existe a fonte "Orientações do suporte UNIMESTRE", para
 * que respostas reais do suporte entrem futuramente como 🧾 SUPORTE UNIMESTRE
 * pelo mesmo pipeline dos casos/procedimentos (InternalKnowledgeService).
 */
$up = static function (): void {
    $db = Database::connection();

    $db->exec("ALTER TABLE diagnostic_sessions
        MODIFY status ENUM('open', 'investigating', 'solution_proposed', 'escalation_needed', 'escalated', 'resolved', 'abandoned') NOT NULL DEFAULT 'open'");

    foreach ([
        'ADD COLUMN escalation_json LONGTEXT NULL AFTER sources_json',
        'ADD COLUMN escalated_at DATETIME NULL AFTER escalation_json',
        'ADD COLUMN escalated_by INT UNSIGNED NULL AFTER escalated_at',
        'ADD COLUMN escalation_reason VARCHAR(255) NULL AFTER escalated_by',
        'ADD COLUMN escalation_summary_json LONGTEXT NULL AFTER escalation_reason',
        'ADD COLUMN external_reference VARCHAR(120) NULL AFTER escalation_summary_json',
    ] as $column) {
        try {
            $db->exec('ALTER TABLE diagnostic_sessions ' . $column);
        } catch (\PDOException $exception) {
            if (!str_contains((string) $exception->getMessage(), 'Duplicate column name')) {
                throw $exception;
            }
        }
    }

    try {
        $db->exec('ALTER TABLE diagnostic_sessions ADD CONSTRAINT fk_diagnostic_sessions_escalated_by
            FOREIGN KEY (escalated_by) REFERENCES users (id) ON DELETE SET NULL');
    } catch (\PDOException $exception) {
        if (!str_contains((string) $exception->getMessage(), 'Duplicate')) {
            throw $exception;
        }
    }

    $db->exec("ALTER TABLE knowledge_sources
        MODIFY type ENUM('official', 'internal', 'internal_procedure', 'resolved_case', 'vendor_support') NOT NULL");
    $db->prepare(
        "INSERT INTO knowledge_sources (name, type, base_url, status)
         SELECT ?, 'vendor_support', NULL, 'active' FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM knowledge_sources WHERE type = 'vendor_support')"
    )->execute(['Orientações do suporte UNIMESTRE']);
};

$down = static function (): void {
    $db = Database::connection();

    $db->exec("DELETE FROM knowledge_sources WHERE type = 'vendor_support'");
    $db->exec("ALTER TABLE knowledge_sources
        MODIFY type ENUM('official', 'internal', 'internal_procedure', 'resolved_case') NOT NULL");
    $db->exec('ALTER TABLE diagnostic_sessions DROP FOREIGN KEY fk_diagnostic_sessions_escalated_by');
    $db->exec('ALTER TABLE diagnostic_sessions
        DROP COLUMN external_reference, DROP COLUMN escalation_summary_json, DROP COLUMN escalation_reason,
        DROP COLUMN escalated_by, DROP COLUMN escalated_at, DROP COLUMN escalation_json');
    $db->exec("UPDATE diagnostic_sessions SET status = 'investigating' WHERE status IN ('escalation_needed', 'escalated')");
    $db->exec("ALTER TABLE diagnostic_sessions
        MODIFY status ENUM('open', 'investigating', 'solution_proposed', 'resolved', 'abandoned') NOT NULL DEFAULT 'open'");
};

return [
    'version' => '0019',
    'name' => 'add_escalation_to_diagnostic_sessions',
    'up' => $up,
    'down' => $down,
];
