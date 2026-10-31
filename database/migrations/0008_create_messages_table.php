<?php

declare(strict_types=1);

use App\Core\Database;

$up = static function (): void {
    $db = Database::connection();
    $db->exec('CREATE TABLE IF NOT EXISTS messages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        conversation_id INT UNSIGNED NOT NULL,
        role ENUM(\'user\', \'assistant\', \'system\') NOT NULL,
        content LONGTEXT NOT NULL,
        provider VARCHAR(50) NULL,
        model VARCHAR(150) NULL,
        tokens_input INT UNSIGNED NULL,
        tokens_output INT UNSIGNED NULL,
        latency_ms INT UNSIGNED NULL,
        request_id VARCHAR(100) NULL,
        status VARCHAR(20) NOT NULL DEFAULT \'ok\',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_messages_conversation (conversation_id, created_at),
        CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id)
            REFERENCES conversations (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};

$down = static function (): void {
    $db = Database::connection();
    $db->exec('DROP TABLE IF EXISTS messages');
};

return [
    'version' => '0008',
    'name' => 'create_messages_table',
    'up' => $up,
    'down' => $down,
];