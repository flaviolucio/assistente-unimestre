<?php

declare(strict_types=1);

namespace App\Services\RAG;

final class ChunkingService
{
    private int $targetTokens;
    private int $maxTokens;
    private int $overlapTokens;
    private int $minChunkTokens;
    private int $charsPerToken;

    public function __construct(?array $options = null)
    {
        $options = $options ?? (array) config('knowledge.chunking', []);

        $this->targetTokens = (int) ($options['target_tokens'] ?? 450);
        $this->maxTokens = (int) ($options['max_tokens'] ?? 650);
        $this->overlapTokens = (int) ($options['overlap_tokens'] ?? 80);
        $this->minChunkTokens = (int) ($options['min_chunk_tokens'] ?? 60);
        $this->charsPerToken = (int) ($options['chars_per_token'] ?? 4);
    }

    /**
     * Estimativa consistente de tokens: ~chars_per_token caracteres por token.
     * Não é contagem exata de nenhum tokenizador de LLM específico.
     */
    public function estimateTokens(string $text): int
    {
        $text = trim($text);

        if ($text === '') {
            return 0;
        }

        return max(1, (int) ceil(mb_strlen($text) / $this->charsPerToken));
    }

    /**
     * Conteúdo de busca normalizado: espaços colapsados, caixa baixa.
     * Acentos são preservados — a collation utf8mb4_unicode_ci do banco
     * já trata correspondências com/sem acento.
     */
    public function normalizeSearchText(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strtolower(trim($text));
    }

    /**
     * Divide o texto em trechos (chunks) priorizando limites naturais:
     * títulos, parágrafos, listas, quebras de linha, sentenças e, por
     * último, o limite técnico de caracteres.
     *
     * @return array<int, array{content: string, token_estimate: int, search_text: string}>
     */
    public function chunkText(string $text): array
    {
        $text = $this->normalizeText($text);

        if ($text === '') {
            return [];
        }

        $blocks = $this->blocks($text);

        if ($blocks === []) {
            return [];
        }

        $chunks = [];
        $buffer = [];
        $bufferTokens = 0;

        foreach ($blocks as $block) {
            $blockTokens = $this->estimateTokens($block);

            if ($blockTokens > $this->maxTokens) {
                $blockTokens = $this->maxTokens;
                $block = $this->hardCut($block);
            }

            if ($this->isHeading($block) && $buffer !== [] && $bufferTokens > 0) {
                $this->closeChunk($buffer, $chunks);
                $buffer = [];
                $bufferTokens = 0;
            }

            $tailSeeded = false;

            if ($bufferTokens + $blockTokens <= $this->targetTokens) {
                $buffer[] = $block;
                $bufferTokens += $blockTokens;
                continue;
            }

            if ($bufferTokens === 0) {
                $buffer[] = $block;
                $bufferTokens = $blockTokens;
                continue;
            }

            if ($bufferTokens < $this->minChunkTokens) {
                $buffer[] = $block;
                $bufferTokens += $blockTokens;
                continue;
            }

            $this->closeChunk($buffer, $chunks);
            $buffer = [];
            $bufferTokens = 0;

            if ($this->overlapTokens > 0 && $chunks !== []) {
                $tail = $this->overlapTailOf($chunks[count($chunks) - 1]['content']);
                $tailTokens = $this->estimateTokens($tail);

                if ($tailTokens > 0) {
                    $buffer[] = $tail;
                    $bufferTokens = $tailTokens;
                    $tailSeeded = true;
                }
            }

            if (!$tailSeeded || $bufferTokens + $blockTokens <= $this->targetTokens) {
                $buffer[] = $block;
                $bufferTokens += $blockTokens;
            } else {
                $this->closeChunk($buffer, $chunks);
                $buffer = [$block];
                $bufferTokens = $blockTokens;
            }
        }

        if ($buffer !== []) {
            $chunks[] = $this->buildChunk($buffer);
        }

        return $chunks;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Gera os blocos naturais do texto (limite preferencial de corte).
     *
     * @return array<int, string>
     */
    private function blocks(string $text): array
    {
        $paragraphs = preg_split('/\n\s*\n/u', $text) ?: [];
        $blocks = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if ($this->isHeading($paragraph)) {
                $blocks[] = $paragraph;
                continue;
            }

            $maxChars = $this->maxTokens * $this->charsPerToken;
            $paragraphBlocks = preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [$paragraph];

            foreach ($paragraphBlocks as $sentence) {
                $sentence = trim($sentence);

                if ($sentence === '') {
                    continue;
                }

                if (mb_strlen($sentence) <= $maxChars) {
                    $blocks[] = $sentence;
                    continue;
                }

                foreach ($this->wordSplit($sentence, $maxChars) as $piece) {
                    $blocks[] = $piece;
                }
            }
        }

        return $blocks;
    }

    /**
     * Título/heade detectado por: Markdown (#), linha toda em MAIÚSCULAS
     * ou linha curta terminada em ":".
     */
    private function isHeading(string $block): bool
    {
        $trimmed = trim($block);

        if ($trimmed === '') {
            return false;
        }

        if (str_starts_with($trimmed, '#')) {
            return true;
        }

        if (mb_strlen($trimmed) <= 80 && str_ends_with($trimmed, ':')) {
            return true;
        }

        $hasUppercase = (bool) preg_match('/\p{Lu}/u', $trimmed);
        $hasLowercase = (bool) preg_match('/\p{Ll}/u', $trimmed);

        return $hasUppercase && !$hasLowercase;
    }

    /**
     * Último recurso: corta em limite técnico de caracteres (evita perder conteúdo).
     */
    private function hardCut(string $block): string
    {
        $maxChars = $this->maxTokens * $this->charsPerToken;

        return mb_substr($block, 0, $maxChars);
    }

    /**
     * Divide texto longo em pedaços de até maxChars, preferindo cortes em espaços.
     *
     * @return array<int, string>
     */
    private function wordSplit(string $text, int $maxChars): array
    {
        $pieces = [];
        $rest = $text;

        while (mb_strlen($rest) > $maxChars) {
            $slice = mb_substr($rest, 0, $maxChars);
            $space = mb_strrpos($slice, ' ');

            if ($space === false) {
                $pieces[] = $slice;
                $rest = mb_substr($rest, $maxChars);
                continue;
            }

            $pieces[] = mb_substr($slice, 0, $space);
            $rest = trim(mb_substr($rest, $space + 1));
        }

        if ($rest !== '') {
            $pieces[] = $rest;
        }

        return $pieces;
    }

    /**
     * Cauda do chunk para overlap, cortada em uma quebra natural próxima
     * do limite de overlap_tokens.
     */
    private function overlapTailOf(string $content): string
    {
        $overlapChars = $this->maxTokens > 0
            ? $this->overlapTokens * $this->charsPerToken
            : 0;

        $length = mb_strlen($content);

        if ($overlapChars <= 0 || $overlapChars >= $length) {
            return '';
        }

        // Com offset negativo, mb_strrpos procura a partir de
        // (comprimento - overlapChars) em direção ao início, retornando a
        // última ocorrência antes da janela de overlap.
        $candidates = [
            mb_strrpos($content, '.', -$overlapChars),
            mb_strrpos($content, '?', -$overlapChars),
            mb_strrpos($content, '!', -$overlapChars),
            mb_strrpos($content, "\n", -$overlapChars),
        ];
        $boundary = null;

        foreach ($candidates as $position) {
            if ($position !== false) {
                $boundary = $boundary === null ? $position : max($boundary, $position);
            }
        }

        if ($boundary === null) {
            $space = mb_strrpos($content, ' ', -$overlapChars);
            $boundary = $space !== false ? $space : -1;
        }

        $tail = trim(mb_substr($content, $boundary + 1));

        if ($tail === '' || mb_strlen($tail) > $overlapChars * 2) {
            return '';
        }

        return $tail;
    }

    private function closeChunk(array $buffer, array &$chunks): void
    {
        $chunks[] = $this->buildChunk($buffer);
    }

    private function buildChunk(array $buffer): array
    {
        $content = trim(implode("\n", $buffer));
        $content = trim(preg_replace("/\n{3,}/", "\n\n", $content) ?? $content);
        $tokens = $this->estimateTokens($content);

        return [
            'content' => $content,
            'token_estimate' => $tokens,
            'search_text' => $this->normalizeSearchText($content),
        ];
    }
}