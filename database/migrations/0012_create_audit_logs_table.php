<?php

declare(strict_types=1);

use App\Core\Database;

$up = static function (): void {
    $db = Database::connection();
    $db->exec('CREATE TABLE IF NOT EXISTS audit_logs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NULL,
        action VARCHAR(80) NOT NULL,
        entity_type VARCHAR(60) NULL,
        entity_id INT UNSIGNED NULL,
        details TEXT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_audit_logs_user (user_id),
        KEY idx_audit_logs_action (action),
        KEY idx_audit_logs_created_at (created_at),
        CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id)
            REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};

$down = static function (): void {
    $db = Database::connection();
    $db->exec('DROP TABLE IF EXISTS audit_logs');
};

return [
    'version' => '0012',
    'name' => 'create_audit_logs_table',
    'up' => $up,
    'down' => $down,
];