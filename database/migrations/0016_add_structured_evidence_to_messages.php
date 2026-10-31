<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Etapa 7.1 — modelo estruturado de evidência.
 *
 * 1) messages.structured_json: resposta estruturada JÁ VALIDADA pelo backend
 *    (modo, hipóteses, próxima pergunta, source_ids). Nunca o texto bruto.
 * 2) message_sources.source_type passa a aceitar os tipos documentais da
 *    taxonomia do copiloto (SUPORTE UNIMESTRE e CASO RESOLVIDO). HIPÓTESE DA
 *    IA não é tipo de fonte e não entra no ENUM.
 */
$up = static function (): void {
    $db = Database::connection();

    try {
        $db->exec('ALTER TABLE messages ADD COLUMN structured_json LONGTEXT NULL AFTER truncated');
    } catch (\PDOException $exception) {
        if (!str_contains((string) $exception->getMessage(), 'Duplicate column name')) {
            throw $exception;
        }
    }

    $db->exec("ALTER TABLE message_sources
        MODIFY source_type ENUM('official', 'internal', 'vendor_support', 'resolved_case') NOT NULL DEFAULT 'official'");
};

$down = static function (): void {
    $db = Database::connection();

    $db->exec("UPDATE message_sources SET source_type = 'internal' WHERE source_type IN ('vendor_support', 'resolved_case')");
    $db->exec("ALTER TABLE message_sources
        MODIFY source_type ENUM('official', 'internal') NOT NULL DEFAULT 'official'");
    $db->exec('ALTER TABLE messages DROP COLUMN structured_json');
};

return [
    'version' => '0016',
    'name' => 'add_structured_evidence_to_messages',
    'up' => $up,
    'down' => $down,
];
