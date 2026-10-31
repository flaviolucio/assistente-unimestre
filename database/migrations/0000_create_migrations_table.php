<?php

declare(strict_types=1);

use App\Core\Database;

$up = static function (): void {
    $db = Database::connection();
    $db->exec('CREATE TABLE IF NOT EXISTS migrations (
        version VARCHAR(64) NOT NULL,
        name VARCHAR(190) NOT NULL,
        batch INT NOT NULL DEFAULT 1,
        ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (version),
        KEY idx_migrations_batch (batch)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};

$down = static function (): void {
    $db = Database::connection();
    $db->exec('DROP TABLE IF EXISTS migrations');
};

return [
    'version' => '0000',
    'name' => 'create_migrations_table',
    'up' => $up,
    'down' => $down,
];