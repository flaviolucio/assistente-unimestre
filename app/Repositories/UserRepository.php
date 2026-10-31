<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserRepository
{
    private const BASE_SELECT = '
        SELECT id, name, email, password_hash, role, status,
               must_change_password, last_login_at, failed_login_attempts, locked_until,
               created_at, updated_at
        FROM users
    ';

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::BASE_SELECT . ' WHERE id = ?');
        $stmt->execute([$id]);

        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function findActive(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::BASE_SELECT . ' WHERE id = ? AND status = ?');
        $stmt->execute([$id, 'active']);

        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            self::BASE_SELECT . ' WHERE LOWER(email) = LOWER(?)'
        );
        $stmt->execute([$email]);

        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function all(array $filters = []): array
    {
        $sql = self::BASE_SELECT;
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(name LIKE ? OR email LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if (!empty($filters['role'])) {
            $where[] = 'role = ?';
            $params[] = $filters['role'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 200';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function emailExists(string $email, ?int $ignoreId = null): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> COALESCE(?, -1)'
        );
        $stmt->execute([$email, $ignoreId]);

        return $stmt->fetchColumn() !== false;
    }

    public function create(array $data): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (name, email, password_hash, role, status, must_change_password)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            mb_strtolower(trim($data['email'])),
            $data['password_hash'],
            $data['role'],
            $data['status'],
            $data['must_change_password'] ?? 1,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $sets = [];
        $params = [];

        foreach (['name', 'email', 'role', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = $field . ' = ?';
                $params[] = $data[$field];
            }
        }

        if ($sets === []) {
            return false;
        }

        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $params[] = $id;

        $stmt = Database::connection()->prepare(
            'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );

        return $stmt->execute($params);
    }

    public function updatePassword(int $id, string $passwordHash, int $mustChange = 0): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET password_hash = ?, must_change_password = ?, failed_login_attempts = 0, locked_until = NULL WHERE id = ?'
        );

        return $stmt->execute([$passwordHash, $mustChange, $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM users WHERE id = ?');

        return $stmt->execute([$id]);
    }

    public function countByStatus(string $status = 'active'): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE status = ?');
        $stmt->execute([$status]);

        return (int) $stmt->fetchColumn();
    }

    public function countByRole(string $role): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM users WHERE role = ? AND status = ?'
        );
        $stmt->execute([$role, 'active']);

        return (int) $stmt->fetchColumn();
    }

    public function total(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function isLastActiveAdmin(int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'"
        );
        $stmt->execute();
        $count = (int) $stmt->fetchColumn();

        if ($count !== 1) {
            return false;
        }

        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM users WHERE id = ? AND role = 'admin' AND status = 'active'"
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn() === 1;
    }

    public function isLocked(int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM users
             WHERE id = ? AND locked_until IS NOT NULL AND locked_until > CURRENT_TIMESTAMP'
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function recordSuccessfulLogin(int $userId): void
    {
        $stmt = Database::connection()->prepare(
            "INSERT INTO login_attempts (email, ip_address, success) SELECT email, ?, 1 FROM users WHERE id = ?"
        );
        $stmt->execute([client_ip(), $userId]);

        $update = Database::connection()->prepare(
            'UPDATE users SET last_login_at = CURRENT_TIMESTAMP, failed_login_attempts = 0, locked_until = NULL WHERE id = ?'
        );
        $update->execute([$userId]);
    }

    public function recordFailedAttempt(string $email, string $ip): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, 0)'
        );
        $stmt->execute([$email, $ip]);
    }

    public function incrementFailedAttempts(int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = ?'
        );
        $stmt->execute([$userId]);
    }

    public function lockUser(int $userId, int $minutes): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET locked_until = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? MINUTE) WHERE id = ?'
        );
        $stmt->execute([$minutes, $userId]);
    }

    public function unlockUser(int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET locked_until = NULL, failed_login_attempts = 0 WHERE id = ?'
        );
        $stmt->execute([$userId]);
    }

    public function countRecentFailures(string $email, int $windowMinutes): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE LOWER(email) = LOWER(?) AND success = 0
               AND attempted_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? MINUTE)'
        );
        $stmt->execute([$email, $windowMinutes]);

        return (int) $stmt->fetchColumn();
    }

    public function countRecentFailuresByIp(string $ip, int $windowMinutes): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = ? AND success = 0
               AND attempted_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? MINUTE)'
        );
        $stmt->execute([$ip, $windowMinutes]);

        return (int) $stmt->fetchColumn();
    }
}