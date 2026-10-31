<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class SystemSettingRepository
{
    public function get(string $key): ?string
    {
        $stmt = Database::connection()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $stmt->execute([$key]);

        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function byCategory(string $category): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT setting_key, setting_value FROM system_settings WHERE category = ? ORDER BY setting_key ASC'
        );
        $stmt->execute([$category]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[$row['setting_key']] = $row['setting_value'];
        }

        return $rows;
    }
    /** Upsert de uma configuração (chave única). */
    public function set(string $key, string $value, string $category): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?');
        $stmt->execute([$value, $key]);

        if ($stmt->rowCount() === 0 && $this->get($key) === null) {
            $db->prepare('INSERT INTO system_settings (setting_key, setting_value, category) VALUES (?, ?, ?)')
                ->execute([$key, $value, $category]);
        }
    }
}
