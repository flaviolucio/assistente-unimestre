<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class AiProviderRepository
{
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, provider, name, model, api_key_encrypted, base_url,
                    temperature, max_tokens, timeout_seconds, status, is_default, priority,
                    created_at, updated_at
             FROM ai_providers
             WHERE id = ?'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByProvider(string $provider): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ai_providers WHERE provider = ?');
        $stmt->execute([$provider]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function all(): array
    {
        return Database::connection()
            ->query(
                'SELECT id, provider, name, model, api_key_encrypted, base_url,
                        temperature, max_tokens, timeout_seconds, status, is_default, priority,
                        created_at, updated_at
                 FROM ai_providers
                 ORDER BY provider ASC'
            )
            ->fetchAll();
    }

    public function active(): array
    {
        return Database::connection()
            ->query(
                "SELECT id, provider, name, model, api_key_encrypted, base_url,
                        temperature, max_tokens, timeout_seconds, status, is_default, priority,
                        created_at, updated_at
                 FROM ai_providers
                 WHERE status = 'active'
                 ORDER BY is_default DESC, id ASC"
            )
            ->fetchAll();
    }

    public function defaultActiveProvider(): ?array
    {
        return $this->findByWhere("status = 'active' AND is_default = 1");
    }

    public function update(int $id, array $fields): bool
    {
        $sets = [];
        $params = [];

        foreach (['provider', 'name', 'model', 'api_key_encrypted', 'base_url', 'temperature', 'max_tokens', 'timeout_seconds', 'status', 'is_default', 'priority'] as $field) {
            if (array_key_exists($field, $fields)) {
                $sets[] = $field . ' = ?';
                $params[] = $fields[$field];
            }
        }

        if ($sets === []) {
            return false;
        }

        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $params[] = $id;

        $stmt = Database::connection()->prepare(
            'UPDATE ai_providers SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );

        return $stmt->execute($params);
    }

    /**
     * Torna $id o único provider default e preserva as demais linhas.
     */
    public function setDefault(int $id): void
    {
        $db = Database::connection();
        $db->exec('UPDATE ai_providers SET is_default = 0, updated_at = CURRENT_TIMESTAMP WHERE is_default = 1');
        $db->prepare('UPDATE ai_providers SET is_default = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$id]);
    }

    public function clearDefault(int $id): void
    {
        Database::connection()->prepare(
            'UPDATE ai_providers SET is_default = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        )->execute([$id]);
    }

    public function activeCount(): int
    {
        return (int) Database::connection()->query(
            "SELECT COUNT(*) FROM ai_providers WHERE status = 'active'"
        )->fetchColumn();
    }

    private function findByWhere(string $where): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ai_providers WHERE ' . $where . ' LIMIT 1'
        );
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}