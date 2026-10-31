<?php

declare(strict_types=1);

use App\Core\Database;

$up = static function (): void {
    $db = Database::connection();
    $db->exec('ALTER TABLE users
        ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
        ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER must_change_password,
        ADD COLUMN IF NOT EXISTS failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_login_at,
        ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL AFTER failed_login_attempts');
};

$down = static function (): void {
    $db = Database::connection();
    $db->exec('ALTER TABLE users
        DROP COLUMN IF EXISTS locked_until,
        DROP COLUMN IF EXISTS failed_login_attempts,
        DROP COLUMN IF EXISTS last_login_at,
        DROP COLUMN IF EXISTS must_change_password');
};

return [
    'version' => '0013',
    'name' => 'add_security_columns_to_users',
    'up' => $up,
    'down' => $down,
];