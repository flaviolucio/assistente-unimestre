<?php

declare(strict_types=1);

namespace App\Services\RAG;

use App\Core\Logger;

final class RetrievalService
{
    public const MESSAGE_EMPTY = 'Nenhum conteúdo suficientemente relevante encontrado.';

    /** @var array<int, RetrievalStrategyInterface> */
    private array $strategies;
    private SearchQueryService $queryService;

    public function __construct()
    {
        $this->queryService = new SearchQueryService();
        $this->strategies = [
            new MySqlFullTextStrategy($this->queryService),
            new InternalSolutionRetrievalStrategy(),
        ];
    }

    public function queryService(): SearchQueryService
    {
        return $this->queryService;
    }

    /**
     * Executa a recuperação híbrida local (FULLTEXT + fallback textual + pesos).
     * Sem IA e sem chamadas externas — 100% local.
     *
     * @return array{
     *     original: string,
     *     normalized: string,
     *     terms: array<int, string>,
     *     results: array<int, SearchResult>,
     *     total_candidates: int,
     *     metrics: array<string, mixed>,
     *     message: string|null
     * }
     */
    public function search(string $userQuery): array
    {
        $started = microtime(true);
        $query = $this->queryService->normalize($userQuery);
        $usedStrategies = [];

        if ($query['terms'] === []) {
            return $this->emptyResult($query, $started);
        }

        $candidates = [];

        foreach ($this->strategies as $strategy) {
            $rows = $strategy->retrieve($query);

            if ($rows !== []) {
                $usedStrategies[] = $strategy->name();
            }

            foreach ($rows as $candidate) {
                $existing = $candidates[$candidate['key']] ?? null;

                if ($existing === null) {
                    $candidates[$candidate['key']] = $candidate;
                } else {
                    $existing['fulltext_raw'] = max((float) $existing['fulltext_raw'], (float) $candidate['fulltext_raw']);
                    $candidates[$candidate['key']] = $existing;
                }
            }
        }

        $results = [];
        $maxFulltext = 0.0;
        $requiredEvidence = $this->requiredEvidenceTerms(count($query['terms']));
        $discardedByEvidence = 0;

        foreach ($candidates as $candidate) {
            $components = $this->components($candidate, $query['terms']);

            // Relevância base ANTES de qualquer bônus: o candidato precisa conter
            // uma fração mínima dos termos da pergunta em título/conteúdo/tags.
            // Categoria/subcategoria, fonte oficial e o componente FULLTEXT
            // relativo NÃO criam relevância sozinhos.
            if (count($components['evidence_terms']) < $requiredEvidence) {
                $discardedByEvidence++;

                continue;
            }

            $candidate['matched_terms'] = $components['matched_terms'];
            $result = SearchResult::fromCandidate($candidate);
            // O FULLTEXT é normalizado só entre candidatos com evidência: um
            // candidato descartado não pode inflar nem esvaziar a escala.
            $maxFulltext = max($maxFulltext, (float) $result->fulltextRaw());

            $results[] = ['result' => $result, 'components' => $components];
        }

        $weight = static fn (string $key): float => (float) (config('knowledge.retrieval.weights.' . $key) ?? 0.0);

        $scored = [];

        foreach ($results as $entry) {
            $result = $entry['result'];
            $components = $entry['components'];

            $fulltextComponent = $maxFulltext > 0.0
                ? (($result->fulltextRaw() / $maxFulltext) * $weight('fulltext'))
                : 0.0;

            $titleComponent = min(3, $components['title_matches']) * $weight('title');
            $categoryComponent = $components['category_match'] * $weight('category');
            $subcategoryComponent = $components['subcategory_match'] * $weight('subcategory');
            $tagsComponent = min(3, $components['tags_matches']) * $weight('tags');
            $contentComponent = min(3, $components['content_matches']) * $weight('content');
            $exactComponent = min(3, $components['exact_count']) * $weight('exact');
            $officialComponent = $result->sourceType() === 'official'
                ? (float) (config('knowledge.retrieval.official_source_boost') ?? 0.0)
                : 0.0;

            $final = round(
                $fulltextComponent
                + $titleComponent
                + $categoryComponent
                + $subcategoryComponent
                + $tagsComponent
                + $contentComponent
                + $exactComponent
                + $officialComponent,
                2
            );

            $result->finalize($final, [
                'fulltext' => round($fulltextComponent, 2),
                'title' => round($titleComponent, 2),
                'category' => round($categoryComponent, 2),
                'subcategory' => round($subcategoryComponent, 2),
                'tags' => round($tagsComponent, 2),
                'content' => round($contentComponent, 2),
                'exact' => round($exactComponent, 2),
                'official' => round($officialComponent, 2),
            ]);

            $scored[] = $result;
        }

        $minimum = $this->minimumScore();
        $topK = $this->topK();

        $scored = array_values(array_filter(
            $scored,
            static fn (SearchResult $result): bool => $result->finalScore() >= $minimum
        ));

        usort($scored, static function (SearchResult $a, SearchResult $b): int {
            $diff = $b->finalScore() <=> $a->finalScore();

            if ($diff !== 0) {
                return $diff;
            }

            $updated = strcmp($b->updatedAt(), $a->updatedAt());

            if ($updated !== 0) {
                return $updated;
            }

            return strcmp($a->key(), $b->key());
        });

        $top = array_slice($scored, 0, $topK);
        $totalCandidates = count($candidates);
        $durationMs = round((microtime(true) - $started) * 1000, 2);

        Logger::info('retrieval', 'Busca executada', [
            'normalized_query' => substr($query['normalized'], 0, 120),
            'terms' => $query['terms'],
            'results' => count($top),
            'duration_ms' => $durationMs,
            'strategies' => $usedStrategies,
            'top_ids' => array_map(static fn (SearchResult $result): string => $result->key(), $top),
            'required_evidence_terms' => $requiredEvidence,
            'discarded_by_evidence' => $discardedByEvidence,
            'min_score' => $minimum,
            'top_k' => $topK,
        ]);

        return [
            'original' => $query['original'],
            'normalized' => $query['normalized'],
            'terms' => $query['terms'],
            'results' => array_values($top),
            'total_candidates' => $totalCandidates,
            'metrics' => [
                'duration_ms' => $durationMs,
                'strategies' => $usedStrategies,
                'found_results' => count($top),
                'top_score' => $top === [] ? 0.0 : $top[0]->finalScore(),
                'required_evidence_terms' => $requiredEvidence,
                'discarded_by_evidence' => $discardedByEvidence,
                'min_score' => $minimum,
                'top_k' => $topK,
            ],
            'message' => $top === [] ? self::MESSAGE_EMPTY : null,
        ];
    }

    /**
     * Quantos termos distintos da pergunta o candidato precisa conter
     * (título/conteúdo/tags) para ter relevância base.
     * = max(1, ceil(min_term_coverage × termos)).
     */
    public function requiredEvidenceTerms(int $termCount): int
    {
        $coverage = (float) (config('knowledge.retrieval.min_term_coverage') ?? 0.0);
        $coverage = max(0.0, min(1.0, $coverage));

        return max(1, (int) ceil($coverage * $termCount));
    }

    /**
     * Fonte de verdade: system_settings (override administrativo)
     * → config/knowledge.php (padrão).
     */
    public function minimumScore(): float
    {
        $value = $this->setting('rag.min_score');

        return $value !== null
            ? max(0.0, (float) $value)
            : (float) (config('knowledge.retrieval.minimum_relevance_score') ?? 0.0);
    }

    public function topK(): int
    {
        $value = $this->setting('rag.top_k');

        return max(1, $value !== null ? (int) $value : (int) (config('knowledge.retrieval.top_k') ?? 5));
    }

    private function setting(string $key): ?string
    {
        try {
            $value = (new \App\Repositories\SystemSettingRepository())->get($key);
        } catch (\Throwable) {
            return null;
        }

        return $value !== null && is_numeric(trim($value)) ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<int, string> $terms
     * @return array{
     *     title_matches: int,
     *     category_match: int,
     *     subcategory_match: int,
     *     tags_matches: int,
     *     content_matches: int,
     *     exact_count: int,
     *     matched_terms: array<int, string>,
     *     evidence_terms: array<int, string>
     * }
     */
    private function components(array $candidate, array $terms): array
    {
        $qs = $this->queryService;

        $title = (string) $candidate['title'];
        $category = (string) ($candidate['category'] ?? '');
        $subcategory = (string) ($candidate['subcategory'] ?? '');
        $tags = (string) ($candidate['tags'] ?? '');
        $content = (string) $candidate['content'];

        $titleMatches = 0;
        $categoryMatch = 0;
        $subcategoryMatch = 0;
        $tagsMatches = 0;
        $contentMatches = 0;
        $matched = [];
        $evidence = [];

        foreach ($terms as $term) {
            $inTitle = $this->fieldMatches($qs, $title, $term);
            $inCategory = $this->fieldMatches($qs, $category, $term);
            $inSubcategory = $this->fieldMatches($qs, $subcategory, $term);
            $inTags = $this->fieldMatches($qs, $tags, $term);
            $inContent = $this->fieldMatches($qs, $content, $term);

            if ($inTitle) {
                $titleMatches++;
            }

            if ($inCategory) {
                $categoryMatch = 1;
            }

            if ($inSubcategory) {
                $subcategoryMatch = 1;
            }

            if ($inTags) {
                $tagsMatches++;
            }

            if ($inContent) {
                $contentMatches++;
            }

            if ($inTitle || $inCategory || $inSubcategory || $inTags || $inContent) {
                $matched[] = $term;
            }

            if ($inTitle || $inTags || $inContent) {
                $evidence[] = $term;
            }
        }

        return [
            'title_matches' => $titleMatches,
            'category_match' => $categoryMatch,
            'subcategory_match' => $subcategoryMatch,
            'tags_matches' => $tagsMatches,
            'content_matches' => $contentMatches,
            'exact_count' => $this->exactCount($title, $content, $terms),
            'matched_terms' => $matched,
            'evidence_terms' => $evidence,
        ];
    }

    /**
     * Verifica se o termo (ou sua variante no singular) aparece no campo,
     * ignorando acentos e caixa.
     */
    private function fieldMatches(SearchQueryService $qs, string $field, string $term): bool
    {
        if ($qs->contains($field, $term)) {
            return true;
        }

        $singular = $qs->singularOf($term);

        if ($singular !== $term) {
            return $qs->contains($field, $singular);
        }

        return false;
    }

    private function exactCount(string $title, string $content, array $terms): int
    {
        $qs = $this->queryService;
        $foldedFields = [$qs->accentFold($title), $qs->accentFold($content)];
        $count = 0;

        foreach ($terms as $term) {
            $needle = preg_quote($qs->accentFold($term), '~');

            foreach ($foldedFields as $field) {
                if (preg_match('~(?<![a-z0-9])' . $needle . '(?![a-z0-9])~', $field) === 1) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    private function emptyResult(array $query, float $started): array
    {
        $durationMs = round((microtime(true) - $started) * 1000, 2);

        return [
            'original' => $query['original'],
            'normalized' => $query['normalized'],
            'terms' => [],
            'results' => [],
            'total_candidates' => 0,
            'metrics' => [
                'duration_ms' => $durationMs,
                'strategies' => [],
                'found_results' => 0,
                'top_score' => 0.0,
            ],
            'message' => self::MESSAGE_EMPTY,
        ];
    }
}