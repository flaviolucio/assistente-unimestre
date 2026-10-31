<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class KnowledgeDocumentRepository
{
    private const BASE_JOIN = '
        SELECT d.id, d.source_id, s.name AS source_name, s.type AS source_type,
               d.title, d.slug, d.url, d.category, d.subcategory,
               d.content, d.content_hash, d.origin, d.status,
               d.collected_at, d.created_at, d.updated_at
        FROM knowledge_documents d
        JOIN knowledge_sources s ON s.id = d.source_id
    ';

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(self::BASE_JOIN . ' WHERE d.id = ?');
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByUrl(string $url): ?array
    {
        $stmt = Database::connection()->prepare(
            self::BASE_JOIN . ' WHERE d.url = ? ORDER BY d.id ASC LIMIT 1'
        );
        $stmt->execute([$url]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function urlExists(string $url, ?int $ignoreId = null): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM knowledge_documents WHERE url = ? AND id <> COALESCE(?, -1) LIMIT 1'
        );
        $stmt->execute([$url, $ignoreId]);

        return $stmt->fetchColumn() !== false;
    }

    public function findByHash(string $sourceId, string $hash): ?array
    {
        $stmt = Database::connection()->prepare(
            self::BASE_JOIN . ' WHERE d.source_id = ? AND d.content_hash = ? LIMIT 1'
        );
        $stmt->execute([$sourceId, $hash]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(array $data): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO knowledge_documents
                (source_id, title, slug, url, category, subcategory, content, content_hash, origin, status, collected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            $data['source_id'],
            $data['title'],
            $data['slug'],
            $data['url'] !== '' ? $data['url'] : null,
            $data['category'] !== '' ? $data['category'] : null,
            $data['subcategory'] !== '' ? $data['subcategory'] : null,
            $data['content'],
            $data['content_hash'],
            $data['origin'],
            $data['status'],
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Documento cujo metadata.$key = $value (ex.: source_identifier, file_hash).
     * Usado pela deduplicação da importação oficial; somente leitura.
     */
    public function findByMetadata(string $key, string $value): ?array
    {
        if (preg_match('/^[a-z_]+$/', $key) !== 1 || $value === '') {
            return null;
        }

        $stmt = Database::connection()->prepare(
            self::BASE_JOIN . " WHERE JSON_VALID(d.metadata) AND JSON_UNQUOTE(JSON_EXTRACT(d.metadata, '$." . $key . "')) = ? ORDER BY d.id ASC LIMIT 1"
        );
        $stmt->execute([$value]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Metadados da importação oficial (JSON) e data de coleta. Não altera
     * conteúdo, hash nem chunks.
     */
    public function setImportMetadata(int $id, array $metadata, ?string $collectedAt): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE knowledge_documents SET metadata = ?, collected_at = COALESCE(?, collected_at) WHERE id = ?'
        );

        return $stmt->execute([
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $collectedAt,
            $id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function metadataOf(int $id): array
    {
        $stmt = Database::connection()->prepare('SELECT metadata FROM knowledge_documents WHERE id = ?');
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();

        return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }

    /**
     * Títulos de uma fonte (deduplicação de conhecimento interno; conjuntos pequenos).
     *
     * @return array<int, array{id: int, title: string}>
     */
    public function titlesInSource(int $sourceId): array
    {
        $stmt = Database::connection()->prepare('SELECT id, title FROM knowledge_documents WHERE source_id = ? ORDER BY id DESC LIMIT 1000');
        $stmt->execute([$sourceId]);

        return $stmt->fetchAll();
    }

    public function update(int $id, array $data): bool
    {
        $sets = [];
        $params = [];

        foreach (['source_id', 'title', 'url', 'category', 'subcategory', 'content', 'content_hash', 'origin', 'status'] as $field) {
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
            'UPDATE knowledge_documents SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );

        return $stmt->execute($params);
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE knowledge_documents SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
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
            $where[] = '(d.title LIKE ? OR d.category LIKE ? OR d.subcategory LIKE ? OR d.content LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }

        if (!empty($filters['category'])) {
            $where[] = 'd.category = ?';
            $params[] = $filters['category'];
        }

        if (!empty($filters['subcategory'])) {
            $where[] = 'd.subcategory = ?';
            $params[] = $filters['subcategory'];
        }

        if (!empty($filters['origin'])) {
            $where[] = 's.type = ?';
            $params[] = $filters['origin'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'd.status = ?';
            $params[] = $filters['status'];
        }

        $sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM knowledge_documents d JOIN knowledge_sources s ON s.id = d.source_id' . $sqlWhere
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;

        $listStmt = Database::connection()->prepare(
            "SELECT d.id, d.source_id, s.name AS source_name, s.type AS source_type,
                    d.title, d.slug, d.url, d.category, d.subcategory,
                    d.status, d.collected_at, d.created_at, d.updated_at
             FROM knowledge_documents d
             JOIN knowledge_sources s ON s.id = d.source_id"
            . $sqlWhere
            . ' ORDER BY d.updated_at DESC, d.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
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

    public function counts(): array
    {
        $stats = [
            'total' => 0,
            'official' => 0,
            'internal' => 0,
            'active' => 0,
            'inactive' => 0,
        ];

        $stmt = Database::connection()->query(
            "SELECT s.type AS source_type, d.status, COUNT(*) AS qtd
             FROM knowledge_documents d
             JOIN knowledge_sources s ON s.id = d.source_id
             GROUP BY s.type, d.status"
        );

        foreach ($stmt->fetchAll() as $row) {
            $stats['total'] += (int) $row['qtd'];
            $stats[$row['source_type']] = ($stats[$row['source_type']] ?? 0) + (int) $row['qtd'];

            if ($row['status'] === 'active') {
                $stats['active'] += (int) $row['qtd'];
            } else {
                $stats['inactive'] += (int) $row['qtd'];
            }
        }

        return $stats;
    }

    public function totalActive(): int
    {
        return (int) Database::connection()->query(
            "SELECT COUNT(*) FROM knowledge_documents WHERE status = 'active'"
        )->fetchColumn();
    }
}