<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use PDO;

final class DiagnosticService
{
    private const REQUIRED_TABLES = [
        'users',
        'knowledge_sources',
        'knowledge_documents',
        'knowledge_chunks',
        'internal_solutions',
        'ai_providers',
        'conversations',
        'messages',
        'message_sources',
        'feedback',
        'system_settings',
        'audit_logs',
    ];

    public function run(): array
    {
        $checks = [
            'php' => $this->checkPhp(),
            'extensions' => $this->checkExtensions(),
            'database' => $this->checkDatabase(),
            'migrations' => $this->checkMigrations(),
            'logs' => $this->checkLogs(),
            'config' => $this->checkConfig(),
        ];

        $allOk = true;

        foreach ($checks as $check) {
            if ($check['ok'] !== true) {
                $allOk = false;
                break;
            }
        }

        return [
            'app' => [
                'name' => config('app.name'),
                'env' => config('app.env'),
                'url' => config('app.url'),
                'debug' => (bool) config('app.debug'),
            ],
            'timestamp' => date('Y-m-d H:i:s'),
            'all_ok' => $allOk,
            'checks' => $checks,
        ];
    }

    private function checkPhp(): array
    {
        return [
            'label' => 'PHP',
            'ok' => PHP_VERSION_ID >= 80000,
            'detail' => PHP_VERSION,
        ];
    }

    private function checkExtensions(): array
    {
        $required = ['pdo_mysql', 'openssl', 'mbstring', 'curl', 'fileinfo'];
        $missing = [];

        foreach ($required as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        return [
            'label' => 'Extensões PHP',
            'ok' => $missing === [],
            'detail' => $missing === [] ? implode(', ', $required) : 'Faltando: ' . implode(', ', $missing),
        ];
    }

    private function checkDatabase(): array
    {
        try {
            $version = Database::serverVersion();

            return [
                'label' => 'Banco de dados (' . config('db.name') . ')',
                'ok' => $version !== 'indisponível',
                'detail' => 'MariaDB/MySQL ' . $version,
            ];
        } catch (\Throwable $exception) {
            return [
                'label' => 'Banco de dados (' . config('db.name') . ')',
                'ok' => false,
                'detail' => 'Falha ao conectar: ' . $exception->getMessage(),
            ];
        }
    }

    private function checkMigrations(): array
    {
        try {
            $db = Database::connection();
            $files = glob(base_path('database/migrations') . '/[0-9]*_*.php');

            $stmt = $db->query('SELECT COUNT(*) FROM migrations');
            $applied = (int) $stmt->fetchColumn();

            return [
                'label' => 'Migrations',
                'ok' => $files !== false && count($files) === $applied,
                'detail' => $applied . ' de ' . ($files !== false ? count($files) : 0) . ' aplicadas',
            ];
        } catch (\Throwable $exception) {
            return [
                'label' => 'Migrations',
                'ok' => false,
                'detail' => 'Falha ao verificar: ' . $exception->getMessage(),
            ];
        }
    }

    private function checkLogs(): array
    {
        try {
            Logger::info('diagnostico', 'Teste de escrita em log');

            $file = config('paths.logs') . '/diagnostico.log';

            return [
                'label' => 'Escrita em logs',
                'ok' => is_file($file),
                'detail' => is_file($file) ? 'storage/logs ok' : 'Falha ao criar log',
            ];
        } catch (\Throwable $exception) {
            return [
                'label' => 'Escrita em logs',
                'ok' => false,
                'detail' => $exception->getMessage(),
            ];
        }
    }

    private function checkConfig(): array
    {
        $problems = [];

        if (config('app.name') === '') {
            $problems[] = 'APP_NAME vazio';
        }

        if (config('app.key') === '' || strlen((string) config('app.key')) < 16) {
            $problems[] = 'APP_KEY ausente ou curta (gerar no .env)';
        }

        if (config('db.name') === '') {
            $problems[] = 'DB_NAME vazio';
        }

        return [
            'label' => 'Configurações',
            'ok' => $problems === [],
            'detail' => $problems === [] ? 'Configuração válida' : implode('; ', $problems),
        ];
    }
}