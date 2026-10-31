<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Logger;

require_once __DIR__ . '/../bootstrap/app.php';

const MIGRATIONS_DIR = __DIR__ . '/migrations';

function migrationFiles(): array
{
    $files = glob(MIGRATIONS_DIR . '/[0-9]*_*.php');

    if ($files === false) {
        return [];
    }

    sort($files);

    return $files;
}

function loadMigration(string $file): array
{
    $migration = require $file;

    if (
        !is_array($migration)
        || !isset($migration['version'], $migration['name'], $migration['up'], $migration['down'])
    ) {
        throw new RuntimeException('Migration inválida: ' . $file);
    }

    return $migration;
}

function ensureMigrationsTable(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS migrations (
        version VARCHAR(64) NOT NULL,
        name VARCHAR(190) NOT NULL,
        batch INT NOT NULL DEFAULT 1,
        ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (version),
        KEY idx_migrations_batch (batch)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function appliedVersions(PDO $db): array
{
    $rows = $db->query('SELECT version FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

    return $rows ?: [];
}

function runUp(PDO $db): void
{
    ensureMigrationsTable($db);

    $applied = appliedVersions($db);
    $maxBatch = (int) $db->query('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn();
    $batch = $maxBatch + 1;
    $count = 0;
    $skipped = 0;

    foreach (migrationFiles() as $file) {
        $migration = loadMigration($file);

        if (in_array($migration['version'], $applied, true)) {
            $skipped++;
            continue;
        }

        try {
            $migration['up']($db);
            $stmt = $db->prepare('INSERT INTO migrations (version, name, batch) VALUES (?, ?, ?)');
            $stmt->execute([$migration['version'], $migration['name'], $batch]);

            echo 'Migrado: [' . $migration['version'] . '] ' . $migration['name'] . PHP_EOL;
            $count++;
        } catch (Throwable $exception) {
            Logger::error('app', 'Falha na migration ' . $migration['version'], ['error' => $exception->getMessage()]);
            fwrite(STDERR, 'Falha na migration [' . $migration['version'] . '] ' . $migration['name'] . ': ' . $exception->getMessage() . PHP_EOL);
            exit(1);
        }
    }

    if ($count > 0) {
        echo 'Fim: ' . $count . ' migration(s) aplicada(s), ' . $skipped . ' já aplicada(s).' . PHP_EOL;
    } else {
        echo 'Nada a migrar (' . $skipped . ' já aplicada(s)).' . PHP_EOL;
    }
}

function runRollback(PDO $db): void
{
    ensureMigrationsTable($db);

    $maxBatch = (int) $db->query('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn();

    if ($maxBatch === 0) {
        echo 'Nada a reverter.' . PHP_EOL;

        return;
    }

    $stmt = $db->prepare('SELECT version, name FROM migrations WHERE batch = ? ORDER BY version DESC');
    $stmt->execute([$maxBatch]);
    $list = $stmt->fetchAll();

    $byVersion = [];

    foreach (migrationFiles() as $file) {
        $migration = loadMigration($file);
        $byVersion[$migration['version']] = $migration;
    }

    foreach ($list as $row) {
        $migration = $byVersion[$row['version']] ?? null;

        if ($migration === null) {
            fwrite(STDERR, 'Migração ' . $row['version'] . ' sem arquivo local; removendo registro.' . PHP_EOL);
            $db->prepare('DELETE FROM migrations WHERE version = ?')->execute([$row['version']]);
            continue;
        }

        try {
            $migration['down']($db);
            $db->prepare('DELETE FROM migrations WHERE version = ?')->execute([$row['version']]);
            echo 'Revertido: [' . $row['version'] . '] ' . $row['name'] . PHP_EOL;
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Falha ao reverter ' . $row['version'] . ': ' . $exception->getMessage() . PHP_EOL);
            exit(1);
        }
    }
}

function showStatus(PDO $db): void
{
    ensureMigrationsTable($db);

    $applied = appliedVersions($db);

    foreach (migrationFiles() as $file) {
        $migration = loadMigration($file);
        $state = in_array($migration['version'], $applied, true) ? 'APLICADA ' : 'PENDENTE ';

        printf("%-10s [%s] %s\n", $state, $migration['version'], $migration['name']);
    }
}

$action = $argv[1] ?? 'up';

try {
    Database::ensureDatabaseExists();
    $db = Database::connection();

    switch ($action) {
        case 'up':
        case 'migrate':
            runUp($db);
            break;
        case 'status':
            showStatus($db);
            break;
        case 'rollback':
        case 'down':
            runRollback($db);
            break;
        default:
            fwrite(STDERR, 'Uso: php database/migrate.php [up|status|rollback]' . PHP_EOL);
            exit(2);
    }
} catch (Throwable $exception) {
    Logger::error('app', 'Erro no runner de migrations', ['error' => $exception->getMessage()]);
    fwrite(STDERR, 'Erro: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}