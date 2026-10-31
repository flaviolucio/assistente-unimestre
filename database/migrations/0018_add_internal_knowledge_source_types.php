<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Etapa 7.3 — casos resolvidos e procedimentos internos como conhecimento.
 *
 * Nenhuma tabela nova: os registros viram knowledge_documents (indexados
 * pelo mesmo ChunkingService/knowledge_chunks) em duas fontes próprias, e o
 * tipo de evidência vem de knowledge_sources.type — o mesmo caminho que o
 * MySqlFullTextStrategy já usa para OFICIAL.
 *
 * 1) knowledge_sources.type aceita 'internal_procedure' e 'resolved_case';
 * 2) cria as duas fontes (idempotente);
 * 3) message_sources.source_type aceita 'internal_procedure' (o valor legado
 *    'internal' continua válido e é lido como PROCEDIMENTO INTERNO).
 */
$up = static function (): void {
    $db = Database::connection();

    $db->exec("ALTER TABLE knowledge_sources
        MODIFY type ENUM('official', 'internal', 'internal_procedure', 'resolved_case') NOT NULL");

    $insert = $db->prepare(
        "INSERT INTO knowledge_sources (name, type, base_url, status)
         SELECT ?, ?, NULL, 'active' FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM knowledge_sources WHERE type = ?)"
    );
    $insert->execute(['Procedimentos internos (equipe)', 'internal_procedure', 'internal_procedure']);
    $insert->execute(['Casos resolvidos (equipe)', 'resolved_case', 'resolved_case']);

    $db->exec("ALTER TABLE message_sources
        MODIFY source_type ENUM('official', 'internal', 'internal_procedure', 'vendor_support', 'resolved_case') NOT NULL DEFAULT 'official'");
};

$down = static function (): void {
    $db = Database::connection();

    $db->exec("UPDATE message_sources SET source_type = 'internal' WHERE source_type = 'internal_procedure'");
    $db->exec("ALTER TABLE message_sources
        MODIFY source_type ENUM('official', 'internal', 'vendor_support', 'resolved_case') NOT NULL DEFAULT 'official'");
    $db->exec("DELETE FROM knowledge_sources WHERE type IN ('internal_procedure', 'resolved_case')");
    $db->exec("ALTER TABLE knowledge_sources MODIFY type ENUM('official', 'internal') NOT NULL");
};

return [
    'version' => '0018',
    'name' => 'add_internal_knowledge_source_types',
    'up' => $up,
    'down' => $down,
];
