<?php

declare(strict_types=1);

if (!defined('APP_PATH')) {
    define('APP_PATH', dirname(__DIR__));
}

require APP_PATH . '/bootstrap/autoload.php';

use App\Core\Config;
use App\Core\Env;
use App\Core\ErrorHandler;

Env::load(APP_PATH . '/.env');

if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return APP_PATH . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return App\Core\Csrf::field();
    }
}

if (!function_exists('flash')) {
    function flash(): array
    {
        return App\Core\Flash::getMessages();
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        return (string) $ip;
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}

Config::load();
ErrorHandler::register();