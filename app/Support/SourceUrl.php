<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Valida se a URL de uma fonte pode ser exibida como link clicável.
 *
 * Regras (link SOMENTE quando):
 *   1. URL http/https válida e preenchida;
 *   2. NÃO for domínio reservado para teste (.example, example.com, example.org, example.net);
 *   3. A fonte não for fixture de teste (títulos F4/F5/F6 usados pela suíte);
 *   4. documento/provider interno não tem URL (fica apenas o título).
 */
final class SourceUrl
{
    private const RESERVED_HOSTS = ['example.com', 'example.org', 'example.net'];

    /**
     * @param array<string, mixed> $source
     */
    public static function isTrusted(array $source): bool
    {
        $url = isset($source['url']) ? trim((string) $source['url']) : '';

        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        if ($host === 'example' || str_ends_with($host, '.example')) {
            return false;
        }

        if (in_array($host, self::RESERVED_HOSTS, true)) {
            return false;
        }

        foreach (self::RESERVED_HOSTS as $reserved) {
            if (str_ends_with($host, '.' . $reserved)) {
                return false;
            }
        }

        $title = isset($source['title']) ? (string) $source['title'] : '';

        if (trim($title) !== '' && preg_match('/^\s*(F4|F5|F6)[\s-]/i', $title)) {
            return false;
        }

        return true;
    }
}