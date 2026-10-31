<?php

declare(strict_types=1);

namespace App\Core;

final class Env
{
    private static array $values = [];

    public static function load(string $path): void
    {
        self::$values = array_merge(self::$values, self::parseFile($path));
    }

    public static function parseFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $out = [];

        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches) === 1) {
                $key = $matches[1];
                $value = trim($matches[2]);

                if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
                    $value = substr($value, 1, -1);
                }

                $out[$key] = $value;
            }
        }

        return $out;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$values[$key] ?? ($_ENV[$key] ?? $default);
    }
}