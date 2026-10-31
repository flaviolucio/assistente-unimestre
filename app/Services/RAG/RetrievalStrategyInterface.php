<?php

declare(strict_types=1);

namespace App\Services\RAG;

interface RetrievalStrategyInterface
{
    /**
     * Recupera candidatos a resultado para a consulta normalizada.
     *
     * @param array{
     *     original: string,
     *     normalized: string,
     *     terms: array<int, string>,
     *     ft_terms: array<int, string>,
     *     like_terms: array<int, string>,
     *     like_patterns: array<int, string>
     * } $query
     * @return array<int, array<string, mixed>>
     */
    public function retrieve(array $query): array;

    public function name(): string;
}