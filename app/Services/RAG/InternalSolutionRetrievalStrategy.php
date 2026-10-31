<?php

declare(strict_types=1);

namespace App\Services\RAG;

use App\Core\Database;

final class InternalSolutionRetrievalStrategy implements RetrievalStrategyInterface
{
    public function name(): string
    {
        return 'internal-solutions';
    }

    public function retrieve(array $query): array
    {
        $patterns = array_values(array_filter($query['like_patterns']));

        if ($patterns === []) {
            return [];
        }

        $limit = (int) config('knowledge.retrieval.candidate_limit', 30);
        $conditions = [];
        $bindings = [];

        foreach ($patterns as $index => $pattern) {
            $problem = ':p' . $index . 'a';
            $solution = ':p' . $index . 'b';
            $title = ':p' . $index . 'c';
            $tags = ':p' . $index . 'd';
            $category = ':p' . $index . 'e';
            $conditions[] = "(s.problem LIKE {$problem} OR s.solution LIKE {$solution}
                              OR s.title LIKE {$title} OR s.tags LIKE {$tags} OR s.category LIKE {$category})";
            $bindings[$problem] = $pattern;
            $bindings[$solution] = $pattern;
            $bindings[$title] = $pattern;
            $bindings[$tags] = $pattern;
            $bindings[$category] = $pattern;
        }

        $stmt = Database::connection()->prepare(
            "SELECT s.id AS solution_id, s.title, s.category, s.tags,
                    s.problem, s.solution, s.updated_at
             FROM internal_solutions s
             WHERE s.status = 'active'
               AND (" . implode(' OR ', $conditions) . ")
             ORDER BY s.updated_at DESC, s.id ASC
             LIMIT {$limit}"
        );

        foreach ($bindings as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value);
        }

        $stmt->execute();

        $candidates = [];

        foreach ($stmt->fetchAll() as $row) {
            $candidates[] = [
                'key' => 'solution:' . $row['solution_id'],
                'strategy' => $this->name(),
                'chunk_id' => null,
                'document_id' => null,
                'solution_id' => (int) $row['solution_id'],
                'title' => (string) $row['title'],
                'source_type' => 'internal',
                'category' => $row['category'] !== null ? (string) $row['category'] : null,
                'subcategory' => null,
                'content' => trim((string) $row['problem'] . "\n" . (string) $row['solution']),
                'updated_at' => (string) $row['updated_at'],
                'fulltext_raw' => 0.0,
                'tags' => $row['tags'] !== null ? (string) $row['tags'] : null,
            ];
        }

        return $candidates;
    }
}