<?php

declare(strict_types=1);

use App\Core\Database;

$up = static function (): void {
    $db = Database::connection();
    $db->exec('CREATE TABLE IF NOT EXISTS knowledge_chunks (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        document_id INT UNSIGNED NOT NULL,
        chunk_index INT NOT NULL,
        content TEXT NOT NULL,
        token_estimate INT UNSIGNED NOT NULL DEFAULT 0,
        search_text TEXT NULL,
        metadata JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_knowledge_chunks_document_index (document_id, chunk_index),
        KEY idx_knowledge_chunks_document (document_id),
        FULLTEXT KEY ft_knowledge_chunks_content_search (content, search_text),
        CONSTRAINT fk_knowledge_chunks_document FOREIGN KEY (document_id)
            REFERENCES knowledge_documents (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};

$down = static function (): void {
    $db = Database::connection();
    $db->exec('DROP TABLE IF EXISTS knowledge_chunks');
};

return [
    'version' => '0004',
    'name' => 'create_knowledge_chunks_table',
    'up' => $up,
    'down' => $down,
];