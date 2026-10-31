<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Monta o destino do header Location de forma segura.
 *
 * - Somente caminhos internos da aplicação ("/login", "/chat/5?x=1&y=2").
 * - Nunca usa escape HTML: header HTTP não é HTML, "&" deve seguir literal.
 * - CR/LF, bytes de controle, esquemas ("https:", "javascript:") e
 *   "//host" (protocol-relative) caem no fallback interno.
 */
final class Redirect
{
    public const FALLBACK = '/';

    public static function safePath(string $path): string
    {
        if ($path === '' || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return self::FALLBACK;
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        if (str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('~^/[^/?#]*:~', $path) === 1) {
            return self::FALLBACK;
        }

        return $path;
    }

    public static function url(string $path): string
    {
        return app_url(self::safePath($path));
    }
}
