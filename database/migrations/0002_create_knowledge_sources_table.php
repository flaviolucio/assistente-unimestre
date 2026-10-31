<?php

declare(strict_types=1);

use App\Core\Database;

$up = static function (): void {
    $db = Database::connection();
    $db->exec('CREATE TABLE IF NOT EXISTS knowledge_sources (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(190) NOT NULL,
        type ENUM(\'official\', \'internal\') NOT NULL,
        base_url VARCHAR(500) NULL,
        status VARCHAR(20) NOT NULL DEFAULT \'active\',
        last_sync_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_knowledge_sources_name (name),
        KEY idx_knowledge_sources_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};

$down = static function (): void {
    $db = Database::connection();
    $db->exec('DROP TABLE IF EXISTS knowledge_sources');
};

return [
    'version' => '0002',
    'name' => 'create_knowledge_sources_table',
    'up' => $up,
    'down' => $down,
];