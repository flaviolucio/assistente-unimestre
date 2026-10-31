<?php

declare(strict_types=1);

use App\Core\Database;

$up = static function (): void {
    $db = Database::connection();
    $db->exec('CREATE TABLE IF NOT EXISTS knowledge_documents (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        source_id INT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        url VARCHAR(500) NULL,
        category VARCHAR(150) NULL,
        subcategory VARCHAR(150) NULL,
        content LONGTEXT NOT NULL,
        content_hash CHAR(64) NOT NULL,
        origin VARCHAR(40) NOT NULL DEFAULT \'unimestre_oficial\',
        metadata JSON NULL,
        status VARCHAR(20) NOT NULL DEFAULT \'active\',
        collected_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_knowledge_documents_slug (slug),
        UNIQUE KEY uq_knowledge_documents_source_hash (source_id, content_hash),
        KEY idx_knowledge_documents_status (status),
        KEY idx_knowledge_documents_category (category),
        FULLTEXT KEY ft_knowledge_documents_title_content (title, content),
        CONSTRAINT fk_knowledge_documents_source FOREIGN KEY (source_id)
            REFERENCES knowledge_sources (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};

$down = static function (): void {
    $db = Database::connection();
    $db->exec('DROP TABLE IF EXISTS knowledge_documents');
};

return [
    'version' => '0003',
    'name' => 'create_knowledge_documents_table',
    'up' => $up,
    'down' => $down,
];