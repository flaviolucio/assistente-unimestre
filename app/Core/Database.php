<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        self::$connection = self::create();

        return self::$connection;
    }

    public static function create(): PDO
    {
        $host = config('db.host');
        $port = (int) config('db.port');
        $name = config('db.name');
        $user = config('db.user');
        $pass = (string) config('db.pass');
        $charset = config('db.charset');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        return new PDO($dsn, $user, $pass, self::options());
    }

    public static function ensureDatabaseExists(): void
    {
        $host = config('db.host');
        $port = (int) config('db.port');
        $name = config('db.name');
        $user = config('db.user');
        $pass = (string) config('db.pass');
        $charset = config('db.charset');
        $collation = config('db.collation');

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset),
                $user,
                $pass,
                self::options()
            );

            $safeName = str_replace(['`', ';'], '', (string) $name);
            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
                $safeName,
                $charset,
                $collation
            ));
        } catch (Throwable $exception) {
            Logger::error('database', 'Falha ao garantir existência do banco', [
                'error' => $exception->getMessage(),
                'error_code' => $exception->getCode(),
            ]);
            throw $exception;
        }
    }

    public static function serverVersion(): string
    {
        try {
            return (string) self::connection()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return 'indisponível';
        }
    }

    public static function reset(): void
    {
        self::$connection = null;
    }

    private static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
    }
}