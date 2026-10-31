<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Logger;

require_once __DIR__ . '/../bootstrap/app.php';

const SEEDS_DIR = __DIR__ . '/seeds';

function seedFiles(): array
{
    $files = glob(SEEDS_DIR . '/[0-9]*_*.php');

    if ($files === false) {
        return [];
    }

    sort($files);

    return $files;
}

function loadSeed(string $file): array
{
    $seed = require $file;

    if (!is_array($seed) || !isset($seed['version'], $seed['name'], $seed['run'])) {
        throw new RuntimeException('Seed inválida: ' . $file);
    }

    return $seed;
}

function ensureSeedsTable(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS seeds (
        version VARCHAR(64) NOT NULL,
        name VARCHAR(190) NOT NULL,
        ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function runSeeds(PDO $db): void
{
    ensureSeedsTable($db);

    $applied = array_column(
        $db->query('SELECT version FROM seeds')->fetchAll(PDO::FETCH_ASSOC),
        'version'
    );

    $count = 0;
    $skipped = 0;

    foreach (seedFiles() as $file) {
        $seed = loadSeed($file);

        if (in_array($seed['version'], $applied, true)) {
            $skipped++;
            continue;
        }

        $db->beginTransaction();

        try {
            $seed['run']($db);
            $stmt = $db->prepare('INSERT INTO seeds (version, name) VALUES (?, ?)');
            $stmt->execute([$seed['version'], $seed['name']]);
            $db->commit();

            echo 'Seed executada: [' . $seed['version'] . '] ' . $seed['name'] . PHP_EOL;
            $count++;
        } catch (Throwable $exception) {
            $db->rollBack();
            Logger::error('app', 'Falha na seed ' . $seed['version'], ['error' => $exception->getMessage()]);
            fwrite(STDERR, 'Falha na seed [' . $seed['version'] . ']: ' . $exception->getMessage() . PHP_EOL);
            exit(1);
        }
    }

    echo $count > 0
        ? 'Fim: ' . $count . ' seed(s) executada(s), ' . $skipped . ' já executada(s).' . PHP_EOL
        : 'Nada a executar (' . $skipped . ' já executada(s)).' . PHP_EOL;
}

$action = $argv[1] ?? 'run';

try {
    Database::ensureDatabaseExists();
    $db = Database::connection();

    switch ($action) {
        case 'run':
        case 'seed':
            runSeeds($db);
            break;
        case 'status':
            ensureSeedsTable($db);
            $applied = array_column(
                $db->query('SELECT version FROM seeds')->fetchAll(PDO::FETCH_ASSOC),
                'version'
            );

            foreach (seedFiles() as $file) {
                $seed = loadSeed($file);
                $state = in_array($seed['version'], $applied, true) ? 'EXECUTADA' : 'PENDENTE ';
                printf("%-10s [%s] %s\n", $state, $seed['version'], $seed['name']);
            }
            break;
        default:
            fwrite(STDERR, 'Uso: php database/seed.php [run|status]' . PHP_EOL);
            exit(2);
    }
} catch (Throwable $exception) {
    Logger::error('app', 'Erro no runner de seeds', ['error' => $exception->getMessage()]);
    fwrite(STDERR, 'Erro: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}