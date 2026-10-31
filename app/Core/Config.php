<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $items = [];

    public static function load(): void
    {
        self::$items = require APP_PATH . '/config/app.php';

        $knowledge = require APP_PATH . '/config/knowledge.php';

        if (is_array($knowledge)) {
            self::$items['knowledge'] = $knowledge;
        }
    }

    public static function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return self::$items;
        }

        $value = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }

    public static function all(): array
    {
        return self::$items;
    }
}