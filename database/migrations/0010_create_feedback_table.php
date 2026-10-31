<?php

declare(strict_types=1);

use App\Core\Database;

$up = static function (): void {
    $db = Database::connection();
    $db->exec('CREATE TABLE IF NOT EXISTS feedback (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        message_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NULL,
        rating ENUM(\'util\', \'nao_util\') NOT NULL DEFAULT \'util\',
        comment TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_feedback_message (message_id),
        KEY idx_feedback_user (user_id),
        CONSTRAINT fk_feedback_message FOREIGN KEY (message_id)
            REFERENCES messages (id) ON DELETE CASCADE,
        CONSTRAINT fk_feedback_user FOREIGN KEY (user_id)
            REFERENCES users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};

$down = static function (): void {
    $db = Database::connection();
    $db->exec('DROP TABLE IF EXISTS feedback');
};

return [
    'version' => '0010',
    'name' => 'create_feedback_table',
    'up' => $up,
    'down' => $down,
];