<?php

declare(strict_types=1);

use App\Core\Database;

$up = static function (): void {
    $db = Database::connection();
    $db->exec('CREATE TABLE IF NOT EXISTS internal_solutions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        problem TEXT NOT NULL,
        solution TEXT NOT NULL,
        category VARCHAR(150) NULL,
        tags VARCHAR(300) NULL,
        created_by INT UNSIGNED NULL,
        status VARCHAR(20) NOT NULL DEFAULT \'draft\',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_internal_solutions_status (status),
        KEY idx_internal_solutions_category (category),
        KEY idx_internal_solutions_created_by (created_by),
        CONSTRAINT fk_internal_solutions_created_by FOREIGN KEY (created_by)
            REFERENCES users (id) ON DELETE SET NULL,
        INDEX idx_internal_solutions_title (title)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};

$down = static function (): void {
    $db = Database::connection();
    $db->exec('DROP TABLE IF EXISTS internal_solutions');
};

return [
    'version' => '0005',
    'name' => 'create_internal_solutions_table',
    'up' => $up,
    'down' => $down,
];