<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class InternalSolutionRepository
{
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.id, s.title, s.problem, s.solution, s.category, s.tags,
                    s.created_by, u.name AS created_by_name, s.status,
                    s.created_at, s.updated_at
             FROM internal_solutions s
             LEFT JOIN users u ON u.id = s.created_by
             WHERE s.id = ?'
        );
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(array $data): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO internal_solutions (title, problem, solution, category, tags, created_by, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['title'],
            $data['problem'],
            $data['solution'],
            $data['category'] !== '' ? $data['category'] : null,
            $data['tags'],
            $data['created_by'] !== null ? (int) $data['created_by'] : null,
            $data['status'],
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $sets = [];
        $params = [];

        foreach (['title', 'problem', 'solution', 'category', 'tags', 'status'] as $field) {
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
            'UPDATE internal_solutions SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );

        return $stmt->execute($params);
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE internal_solutions SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$status, $id]);

        return $stmt->rowCount() > 0;
    }

    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(title LIKE ? OR problem LIKE ? OR solution LIKE ? OR tags LIKE ? OR category LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        if (!empty($filters['category'])) {
            $where[] = 'category = ?';
            $params[] = $filters['category'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }

        $sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = Database::connection()->prepare('SELECT COUNT(*) FROM internal_solutions' . $sqlWhere);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;

        $listStmt = Database::connection()->prepare(
            'SELECT id, title, problem, solution, category, tags, created_by, status, created_at, updated_at
             FROM internal_solutions'
            . $sqlWhere
            . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $listStmt->execute($params);

        return [
            'items' => $listStmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    public function countActive(): int
    {
        return (int) Database::connection()->query(
            "SELECT COUNT(*) FROM internal_solutions WHERE status = 'active'"
        )->fetchColumn();
    }
}