<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Import;

/**
 * Texto puro (.txt que NÃO é DokuWiki). Mantém o conteúdo como está; o
 * título é a primeira linha não vazia.
 */
final class TextContentExtractor implements ContentExtractorInterface
{
    public function format(): string
    {
        return 'text';
    }

    public function extract(string $raw, array $context = []): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        $title = '';

        if (preg_match('/^\s*#*\s*(\S.*)$/mu', $text, $m) === 1) {
            $title = mb_substr(trim($m[1]), 0, 255);
        }

        return self::normalized($title, $text);
    }

    /** Documento normalizado com todas as chaves (reuso pelos extratores de texto). */
    public static function normalized(string $title, string $content, array $extra = []): array
    {
        return array_merge([
            'title' => $title,
            'content' => $content,
            'url' => null,
            'url_source' => null,
            'category' => null,
            'subcategory' => null,
            'published_at' => null,
            'source_modified_at' => null,
            'source_modified_type' => null,
            'hosts' => [],
            'media_refs' => [],
            'metadata' => [],
            'quality' => null,
        ], $extra);
    }
}
