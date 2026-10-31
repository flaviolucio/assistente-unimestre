<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class KnowledgeSourceRepository
{
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, name, type, base_url, status, last_sync_at, created_at, updated_at
             FROM knowledge_sources WHERE id = ?'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByType(string $type): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id, name, type, base_url, status, created_at, updated_at
             FROM knowledge_sources
             WHERE type = ? AND status = 'active'
             ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([$type]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function all(): array
    {
        return Database::connection()
            ->query('SELECT id, name, type, base_url, status, created_at, updated_at FROM knowledge_sources ORDER BY name ASC')
            ->fetchAll();
    }

    public function exists(int $id): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM knowledge_sources WHERE id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn() > 0;
    }
}