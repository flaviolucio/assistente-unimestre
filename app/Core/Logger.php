<?php

declare(strict_types=1);

namespace App\Core;

final class Logger
{
    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
    ];

    private const REDACT_KEYS = [
        'password',
        'pass',
        'api_key',
        'apikey',
        'secret',
        'token',
        'authorization',
        'key',
        'credential',
        'credit_card',
        'card_number',
        'cvv',
    ];

    public static function log(string $channel, string $level, string $message, array $context = []): void
    {
        $minimum = config('log.level', 'debug');

        if (self::levelRank($level) < self::levelRank($minimum)) {
            return;
        }

        $dir = config('paths.logs', base_path('storage/logs'));

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        self::rotateOncePerDay((string) $dir);

        $channelName = preg_replace('/[^a-zA-Z0-9_-]/', '', $channel) ?: 'app';
        $file = rtrim((string) $dir, '/\\') . DIRECTORY_SEPARATOR . $channelName . '.log';
        $safeContext = self::sanitize($context);
        $encoded = $safeContext !== []
            ? ' ' . json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        $line = sprintf(
            '[%s] [%s] [%s] %s%s',
            date('Y-m-d H:i:s'),
            strtoupper($channelName),
            strtoupper($level),
            $message,
            $encoded
        );

        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function sanitize(array $context): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                $out[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::sanitize($value);
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public static function debug(string $channel, string $message, array $context = []): void
    {
        self::log($channel, 'debug', $message, $context);
    }

    public static function info(string $channel, string $message, array $context = []): void
    {
        self::log($channel, 'info', $message, $context);
    }

    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::log($channel, 'warning', $message, $context);
    }

    public static function error(string $channel, string $message, array $context = []): void
    {
        self::log($channel, 'error', $message, $context);
    }

    /**
     * Política de retenção (LOG_DAYS_TO_KEEP):
     *
     * - No máximo uma verificação por dia (marcador em storage/cache), e no
     *   máximo uma por processo: nenhuma varredura de diretório por request.
     * - Um canal ativo "{canal}.log" cuja última escrita foi em dia anterior
     *   é renomeado para "{canal}-AAAA-MM-DD.log" (data da última escrita).
     * - Arquivos arquivados com data anterior a (hoje - LOG_DAYS_TO_KEEP) são
     *   removidos. O conteúdo de um arquivo nunca é mais novo que sua data,
     *   então ele sempre fica guardado ao menos LOG_DAYS_TO_KEEP dias.
     * - O diretório vem só da configuração (paths.logs); só arquivos regulares
     *   com esses dois padrões de nome, diretamente nesse diretório, são tocados.
     */
    private static function rotateOncePerDay(string $dir): void
    {
        static $checked = false;

        if ($checked) {
            return;
        }

        $checked = true;

        try {
            $cacheDir = (string) config('paths.cache', base_path('storage/cache'));
            $stamp = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . 'log_rotation.stamp';
            $today = date('Y-m-d');

            if (is_file($stamp) && trim((string) @file_get_contents($stamp)) === $today) {
                return;
            }

            if (is_dir($cacheDir)) {
                @file_put_contents($stamp, $today, LOCK_EX);
            }

            self::rotateDirectory($dir, (int) config('log.days_to_keep', 30), time());
        } catch (\Throwable) {
            // Rotação nunca pode derrubar a escrita do log.
        }
    }

    /**
     * @return array{rotated: string[], deleted: string[]}
     */
    public static function rotateDirectory(string $dir, int $daysToKeep, int $now): array
    {
        $report = ['rotated' => [], 'deleted' => []];
        $realDir = realpath($dir);

        if ($realDir === false || !is_dir($realDir)) {
            return $report;
        }

        $daysToKeep = max(1, $daysToKeep);
        $today = date('Y-m-d', $now);
        $cutoff = date('Y-m-d', strtotime($today . ' -' . $daysToKeep . ' days'));

        foreach ((array) scandir($realDir) as $name) {
            $name = (string) $name;
            $path = $realDir . DIRECTORY_SEPARATOR . $name;

            if (!is_file($path) || is_link($path) || dirname((string) realpath($path)) !== $realDir) {
                continue;
            }

            if (preg_match('/^[A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)*-(\d{4}-\d{2}-\d{2})(?:-\d+)?\.log$/', $name, $m) === 1) {
                if ($m[1] < $cutoff && @unlink($path)) {
                    $report['deleted'][] = $name;
                }

                continue;
            }

            if (preg_match('/^([A-Za-z0-9_-]+)\.log$/', $name, $m) !== 1) {
                continue;
            }

            $mtime = (int) @filemtime($path);
            $lastWrite = date('Y-m-d', $mtime);

            if ($mtime === 0 || $lastWrite >= $today) {
                continue;
            }

            $target = $realDir . DIRECTORY_SEPARATOR . $m[1] . '-' . $lastWrite . '.log';
            $n = 1;

            while (file_exists($target)) {
                $target = $realDir . DIRECTORY_SEPARATOR . $m[1] . '-' . $lastWrite . '-' . $n++ . '.log';
            }

            if (@rename($path, $target)) {
                $report['rotated'][] = $name;
            }
        }

        return $report;
    }

    private static function levelRank(string $level): int
    {
        return self::LEVELS[strtolower($level)] ?? 0;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $low = strtolower($key);

        foreach (self::REDACT_KEYS as $sensitive) {
            if (str_contains($low, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}