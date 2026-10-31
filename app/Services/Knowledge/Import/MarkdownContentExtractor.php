<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Import;

/**
 * Markdown (.md) → texto semântico para o RAG (não gera HTML).
 *   # Título / ## Seção  → título + "## Seção"
 *   [texto](url)         → "texto (url)"
 *   ![alt](img)          → removido (mídia não textual)
 *   **x** / *x* / `x`    → x (ênfase removida; código preservado)
 *   listas e tabelas     → mantidas como texto
 */
final class MarkdownContentExtractor implements ContentExtractorInterface
{
    public function format(): string
    {
        return 'markdown';
    }

    public function extract(string $raw, array $context = []): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        $title = '';
        $lines = [];
        $inFence = false;
        $hosts = [];

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inFence = !$inFence;
                continue;
            }

            if ($inFence) {
                $lines[] = $line;
                continue;
            }

            if (preg_match('/^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m) === 1) {
                $heading = $this->inline($m[2], $hosts);

                if ($title === '' && strlen($m[1]) === 1) {
                    $title = mb_substr($heading, 0, 255);
                    continue;
                }

                $lines[] = '';
                $lines[] = '## ' . $heading;
                $lines[] = '';
                continue;
            }

            if (preg_match('/^\s*([-*_]\s*){3,}$/', $line) === 1) {
                $lines[] = '';
                continue;
            }

            // Tabela: remove a linha separadora |---|---|
            if (preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', $line) === 1) {
                continue;
            }

            $line = preg_replace('/^(\s*)[*+]\s+/', '$1- ', $line) ?? $line;
            $line = preg_replace('/^\s*>\s?/', '', $line) ?? $line;

            if (preg_match('/^\s*\|.*\|\s*$/', $line) === 1) {
                $cells = array_map('trim', explode('|', trim(trim($line), '|')));
                $line = implode(' | ', array_map(fn ($c) => $this->inline($c, $hosts), $cells));
            } else {
                $line = $this->inline($line, $hosts);
            }

            $lines[] = $line;
        }

        if ($title === '' && preg_match('/^\s*(\S.*)$/mu', trim(implode("\n", $lines)), $m) === 1) {
            $title = mb_substr(trim($m[1]), 0, 255);
        }

        $content = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '');

        return TextContentExtractor::normalized($title, $content, ['hosts' => array_keys($hosts)]);
    }

    private function inline(string $text, array &$hosts): string
    {
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text) ?? $text;
        $text = preg_replace_callback('/\[([^\]]+)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/', static function (array $m) use (&$hosts): string {
            $host = strtolower((string) parse_url($m[2], PHP_URL_HOST));

            if ($host !== '') {
                $hosts[$host] = true;
            }

            return preg_match('#^https?://#i', $m[2]) === 1 && $m[1] !== $m[2] ? $m[1] . ' (' . $m[2] . ')' : $m[1];
        }, $text) ?? $text;
        $text = preg_replace('/(\*\*|__)(.+?)\1/', '$2', $text) ?? $text;
        $text = preg_replace('/(?<![\w*])\*(?!\s)([^*]+?)(?<!\s)\*(?![\w*])/', '$1', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/', '$1', $text) ?? $text;

        return rtrim($text);
    }
}
