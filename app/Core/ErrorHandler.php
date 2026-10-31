<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        Logger::warning('error', $message, [
            'file' => $file,
            'line' => $line,
            'severity' => $severity,
        ]);

        return false;
    }

    public static function handleException(Throwable $exception): void
    {
        Logger::error('error', $exception->getMessage(), [
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
        ]);

        self::render($exception->getMessage(), 500);
    }

    public static function handleShutdown(): void
    {
        $last = error_get_last();

        if ($last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            Logger::error('error', $last['message'], [
                'file' => $last['file'],
                'line' => $last['line'],
                'type' => $last['type'],
            ]);

            if (!headers_sent()) {
                http_response_code(500);
            }

            self::render(null, 500);
        }
    }

    public static function render(?string $message, int $status = 500): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, PHP_EOL . 'ERRO: ' . ($message ?? 'Falha interna') . PHP_EOL);

            return;
        }

        if (self::wantsJson()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code($status);
            }

            echo json_encode([
                'error' => true,
                'message' => 'Não foi possível processar a requisição neste momento.',
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        if (!headers_sent()) {
            http_response_code($status);
        }

        $debugMessage = (bool) config('app.debug') ? $message : ('Não foi possível processar a requisição neste momento.');

        require config('paths.views') . '/errors/fatal.php';
    }

    private static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        return str_contains($accept, 'application/json') || str_starts_with($uri, '/api/');
    }
}