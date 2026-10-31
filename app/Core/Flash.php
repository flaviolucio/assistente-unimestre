<?php

declare(strict_types=1);

namespace App\Core;

final class Flash
{
    public static function set(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_SESSION['_flash_messages'][$type][] = $message;
    }

    public static function input(string $key, string $value): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $_SESSION['_flash_old'][$key] = $value;
    }

    public static function old(string $key, string $default = ''): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return $default;
        }

        return is_string($_SESSION['_flash_old'][$key] ?? null)
            ? $_SESSION['_flash_old'][$key]
            : $default;
    }

    public static function getMessages(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }

        $messages = $_SESSION['_flash_messages'] ?? [];

        unset($_SESSION['_flash_messages'], $_SESSION['_flash_old']);

        return $messages;
    }

    public static function clear(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        unset($_SESSION['_flash_messages'], $_SESSION['_flash_old']);
    }
}