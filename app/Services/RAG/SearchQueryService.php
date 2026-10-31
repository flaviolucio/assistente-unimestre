<?php

declare(strict_types=1);

namespace App\Services\RAG;

final class SearchQueryService
{
    private array $stopwords;
    private int $minTermLength;

    public function __construct(?array $stopwords = null, ?int $minTermLength = null)
    {
        $this->stopwords = $stopwords ?? (array) config('knowledge.search.stopwords', []);
        $this->minTermLength = $minTermLength ?? (int) config('knowledge.search.min_term_length', 2);
    }

    /**
     * Normaliza a pergunta do usuário em uma estrutura de pesquisa determinística.
     *
     * @return array{
     *     original: string,
     *     normalized: string,
     *     terms: array<int, string>,
     *     ft_terms: array<int, string>,
     *     like_terms: array<int, string>,
     *     like_patterns: array<int, string>
     * }
     */
    public function normalize(string $query): array
    {
        $original = trim($query);
        $normalized = $this->clean($original);
        $terms = $this->extractTerms($normalized);

        $ftTerms = [];
        $likeTerms = [];

        foreach ($terms as $term) {
            // FULLTEXT InnoDB (innodb_ft_min_token_size=3) ignora termos < 3 letras.
            if (mb_strlen($term) >= 3) {
                $ftTerms[] = $term;
            }

            $likeTerms[] = $term;
        }

        $likePatterns = [];

        foreach ($likeTerms as $term) {
            $likePatterns[] = '%' . $term . '%';

            $singular = $this->singularOf($term);

            if ($singular !== $term) {
                $likePatterns[] = '%' . $singular . '%';
            }
        }

        return [
            'original' => $original,
            'normalized' => $normalized,
            'terms' => $terms,
            'ft_terms' => $ftTerms,
            'like_terms' => $likeTerms,
            'like_patterns' => array_values(array_unique($likePatterns)),
        ];
    }

    /**
     * Limpa e prepara a pergunta: espaços, caixa, pontuação irrelevante.
     */
    public function clean(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        // Remove pontuação e símbolos, mantendo letras (com acento) e números.
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Extrai termos relevantes removendo stopwords conservadoras.
     * Termos técnicos curtos (EAD, AVA, CPF, RA, NF) são preservados.
     *
     * @return array<int, string>
     */
    public function extractTerms(string $normalized): array
    {
        $words = preg_split('/\s+/u', trim($normalized)) ?: [];
        $terms = [];

        foreach ($words as $word) {
            $word = trim($word);

            if ($word === '') {
                continue;
            }

            if (mb_strlen($word) < $this->minTermLength) {
                continue;
            }

            if (in_array($word, $this->stopwords, true)) {
                continue;
            }

            $terms[$word] = $word;
        }

        return array_values($terms);
    }

    /**
     * Normalização conservadora de plural: remove apenas o "s"/"es" final.
     * Não aplica stemming agressivo para evitar falsos positivos.
     */
    public function singularOf(string $term): string
    {
        $length = mb_strlen($term);

        if ($length < 4) {
            return $term;
        }

        if (mb_substr($term, -2) === 'es') {
            return mb_substr($term, 0, -2);
        }

        if (mb_substr($term, -1) === 's') {
            return mb_substr($term, 0, -1);
        }

        return $term;
    }

    private const ACCENT_MAP = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
    ];

    /**
     * Remove acentos e caixa de forma conservadora, espelhando o
     * comportamento da collation utf8mb4_unicode_ci do banco.
     */
    public function accentFold(string $value): string
    {
        $value = mb_strtolower($value);

        return strtr($value, self::ACCENT_MAP);
    }

    /**
     * Corresponde um termo a um texto de forma acento/caixa insensível.
     */
    public function contains(string $haystack, string $term): bool
    {
        return str_contains($this->accentFold($haystack), $this->accentFold($term));
    }
}