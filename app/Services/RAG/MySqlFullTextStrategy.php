<?php

declare(strict_types=1);

namespace App\Services\RAG;

use App\Core\Database;

final class MySqlFullTextStrategy implements RetrievalStrategyInterface
{
    private SearchQueryService $queryService;

    public function __construct(?SearchQueryService $queryService = null)
    {
        $this->queryService = $queryService ?? new SearchQueryService();
    }

    public function name(): string
    {
        return 'mysql-fulltext';
    }

    public function retrieve(array $query): array
    {
        $candidates = [];

        foreach ($this->retrieveFullText($query) as $candidate) {
            $candidates[$candidate['key']] = $candidate;
        }

        foreach ($this->retrieveLikeFallback($query) as $candidate) {
            $existing = $candidates[$candidate['key']] ?? null;

            if ($existing === null) {
                $candidates[$candidate['key']] = $candidate;
            } else {
                $existing['fulltext_raw'] = max((float) $existing['fulltext_raw'], (float) $candidate['fulltext_raw']);
                $candidates[$candidate['key']] = $existing;
            }
        }

        return array_values($candidates);
    }

    /**
     * Correspondência FULLTEXT (MATCH ... AGAINST NATURAL LANGUAGE MODE)
     * somente para termos com 3+ letras (limite do InnoDB).
     */
    private function retrieveFullText(array $query): array
    {
        $ftTerms = array_values(array_filter($query['ft_terms']));

        if ($ftTerms === []) {
            return [];
        }

        $ftQuery = implode(' ', $ftTerms);
        $limit = (int) config('knowledge.retrieval.candidate_limit', 30);

        $stmt = Database::connection()->prepare(
            "SELECT c.id AS chunk_id, c.document_id, c.chunk_index,
                    c.content AS chunk_content, c.search_text,
                    d.title, d.category, d.subcategory, d.updated_at AS doc_updated_at,
                    s.type AS source_type,
                    MATCH(c.content, c.search_text) AGAINST (? IN NATURAL LANGUAGE MODE) AS ft_score
             FROM knowledge_chunks c
             JOIN knowledge_documents d ON d.id = c.document_id
             JOIN knowledge_sources s ON s.id = d.source_id
             WHERE d.status = 'active'
               AND MATCH(c.content, c.search_text) AGAINST (? IN NATURAL LANGUAGE MODE) > 0
             ORDER BY ft_score DESC, d.id ASC, c.chunk_index ASC
             LIMIT {$limit}"
        );
        $stmt->execute([$ftQuery, $ftQuery]);

        return $this->mapChunkRows($stmt->fetchAll());
    }

    /**
     * Fallback por correspondência textual (LIKE), acento/caixa insensível
     * por collation. Cobre termos curtos (2 letras: RA, NF) e plurais que o
     * FULLTEXT ignora por indexar apenas tokens inteiros.
     */
    private function retrieveLikeFallback(array $query): array
    {
        $patterns = array_values(array_filter($query['like_patterns']));

        if ($patterns === []) {
            return [];
        }

        $limit = (int) config('knowledge.retrieval.candidate_limit', 30);
        $conditions = [];
        $bindings = [];

        foreach ($patterns as $index => $pattern) {
            $content = ':p' . $index . 'a';
            $search = ':p' . $index . 'b';
            $title = ':p' . $index . 'c';
            $conditions[] = "(c.content LIKE {$content} OR c.search_text LIKE {$search} OR d.title LIKE {$title})";
            $bindings[$content] = $pattern;
            $bindings[$search] = $pattern;
            $bindings[$title] = $pattern;
        }

        $stmt = Database::connection()->prepare(
            "SELECT c.id AS chunk_id, c.document_id, c.chunk_index,
                    c.content AS chunk_content, c.search_text,
                    d.title, d.category, d.subcategory, d.updated_at AS doc_updated_at,
                    s.type AS source_type,
                    0.0 AS ft_score
             FROM knowledge_chunks c
             JOIN knowledge_documents d ON d.id = c.document_id
             JOIN knowledge_sources s ON s.id = d.source_id
             WHERE d.status = 'active'
               AND (" . implode(' OR ', $conditions) . ")
             ORDER BY d.updated_at DESC, d.id ASC, c.chunk_index ASC
             LIMIT {$limit}"
        );

        foreach ($bindings as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value);
        }

        $stmt->execute();

        return $this->mapChunkRows($stmt->fetchAll());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapChunkRows(array $rows): array
    {
        $candidates = [];

        foreach ($rows as $row) {
            $chunkId = (int) $row['chunk_id'];

            $candidates[] = [
                'key' => 'knowledge:' . $chunkId,
                'strategy' => $this->name(),
                'chunk_id' => $chunkId,
                'document_id' => (int) $row['document_id'],
                'solution_id' => null,
                'title' => (string) $row['title'],
                'source_type' => (string) $row['source_type'],
                'category' => $row['category'] !== null ? (string) $row['category'] : null,
                'subcategory' => $row['subcategory'] !== null ? (string) $row['subcategory'] : null,
                'content' => (string) $row['chunk_content'],
                'updated_at' => (string) $row['doc_updated_at'],
                'fulltext_raw' => (float) $row['ft_score'],
                'tags' => null,
            ];
        }

        return $candidates;
    }
}