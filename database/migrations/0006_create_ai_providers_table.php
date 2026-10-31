<?php

declare(strict_types=1);

use App\Core\Database;

$up = static function (): void {
    $db = Database::connection();
    $db->exec('CREATE TABLE IF NOT EXISTS ai_providers (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        provider VARCHAR(50) NOT NULL,
        name VARCHAR(150) NOT NULL,
        model VARCHAR(150) NULL,
        api_key_encrypted TEXT NULL,
        base_url VARCHAR(500) NULL,
        temperature DECIMAL(3, 2) NOT NULL DEFAULT 0.20,
        max_tokens INT UNSIGNED NULL,
        timeout_seconds INT UNSIGNED NOT NULL DEFAULT 60,
        status VARCHAR(20) NOT NULL DEFAULT \'inactive\',
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_ai_providers_provider (provider),
        KEY idx_ai_providers_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};

$down = static function (): void {
    $db = Database::connection();
    $db->exec('DROP TABLE IF EXISTS ai_providers');
};

return [
    'version' => '0006',
    'name' => 'create_ai_providers_table',
    'up' => $up,
    'down' => $down,
];