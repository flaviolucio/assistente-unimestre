<?php

declare(strict_types=1);

use App\Core\Database;

$up = static function (): void {
    $db = Database::connection();
    $db->exec('CREATE TABLE IF NOT EXISTS message_sources (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        message_id INT UNSIGNED NOT NULL,
        document_id INT UNSIGNED NULL,
        chunk_id INT UNSIGNED NULL,
        source_type ENUM(\'official\', \'internal\') NOT NULL DEFAULT \'official\',
        relevance_score DECIMAL(6, 4) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_message_sources_message (message_id),
        CONSTRAINT fk_message_sources_message FOREIGN KEY (message_id)
            REFERENCES messages (id) ON DELETE CASCADE,
        CONSTRAINT fk_message_sources_document FOREIGN KEY (document_id)
            REFERENCES knowledge_documents (id) ON DELETE SET NULL,
        CONSTRAINT fk_message_sources_chunk FOREIGN KEY (chunk_id)
            REFERENCES knowledge_chunks (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};

$down = static function (): void {
    $db = Database::connection();
    $db->exec('DROP TABLE IF EXISTS message_sources');
};

return [
    'version' => '0009',
    'name' => 'create_message_sources_table',
    'up' => $up,
    'down' => $down,
];