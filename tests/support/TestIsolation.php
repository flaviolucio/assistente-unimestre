<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Isolamento das suítes: um teste só remove o que ele mesmo criou.
 *
 * begin()  — registra o maior id de cada tabela ("marca d'água" da execução),
 *            fotografa providers e usuários pré-existentes e os IDs das
 *            tabelas críticas. Também grava as marcas em variáveis de sessão
 *            MySQL (@t_*), usadas pelos DELETEs das suítes.
 * finish() — restaura EXATAMENTE providers e usuários pré-existentes e remove
 *            somente linhas criadas nesta execução pela própria suíte:
 *              · id > marca d'água  E
 *              · pertence a usuário de teste (*@assistente.test), ou foi
 *                gravada pela suíte (audit user_agent 'cli' ou o UA do
 *                harness HTTP), ou foi rastreada explicitamente (track()).
 * verify() — confirma que todo ID pré-existente das tabelas críticas continua
 *            existindo e que não sobrou fixture.
 *
 * Premissa documentada: não usar a aplicação pelo navegador enquanto a suíte
 * roda (tentativas de login locais durante a janela da execução poderiam ser
 * atribuídas à suíte).
 */
final class TestIsolation
{
    public const HTTP_USER_AGENT = 'http-teste-unimestre/1.0';
    public const TEST_EMAIL_LIKE = '%@assistente.test';

    /** Sem aspas: pode ser embutida em qualquer literal SQL das suítes. */
    public const AUDIT_SCOPE = '(id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http))';

    private const WATERMARK_TABLES = [
        'users', 'login_attempts', 'audit_logs', 'conversations', 'messages',
        'message_sources', 'feedback', 'knowledge_sources', 'knowledge_documents',
        'knowledge_chunks', 'internal_solutions', 'chat_limits', 'chat_request_guards',
    ];

    /** Tabelas cujos IDs pré-existentes precisam sobreviver à suíte. */
    public const CRITICAL_TABLES = [
        'users', 'login_attempts', 'audit_logs', 'conversations', 'messages',
        'message_sources', 'feedback', 'knowledge_documents', 'knowledge_chunks',
        'internal_solutions', 'ai_providers',
    ];

    private static bool $begun = false;
    private static bool $finished = false;
    /** @var array<string, int> */
    private static array $start = [];
    /** @var array<int, array<string, mixed>> */
    private static array $providers = [];
    /** @var array<int, array<string, mixed>> */
    private static array $users = [];
    /** @var array<string, array<int, true>> */
    private static array $preIds = [];
    /** @var array<string, array<int, true>> */
    private static array $tracked = [];
    /** @var array<string, int> */
    private static array $preCounts = [];
    /** @var array<string, array<int, array<string, mixed>>> Linhas pré-existentes de rate limit/idempotência. */
    private static array $stateRows = [];

    public static function begin(): void
    {
        if (self::$begun) {
            return;
        }

        $db = Database::connection();

        foreach (self::WATERMARK_TABLES as $table) {
            self::$start[$table] = (int) $db->query("SELECT COALESCE(MAX(id), 0) FROM {$table}")->fetchColumn();
            $db->exec('SET @t_' . $table . '_start = ' . self::$start[$table]);
        }

        $db->exec("SET @t_ua_http = CONVERT('" . self::HTTP_USER_AGENT . "' USING utf8mb4) COLLATE utf8mb4_unicode_ci, @t_ua_cli = CONVERT('cli' USING utf8mb4) COLLATE utf8mb4_unicode_ci");

        foreach ($db->query('SELECT * FROM ai_providers')->fetchAll() as $row) {
            self::$providers[(int) $row['id']] = $row;
        }

        foreach ($db->query('SELECT * FROM users')->fetchAll() as $row) {
            self::$users[(int) $row['id']] = $row;
        }

        foreach (['chat_limits', 'chat_request_guards'] as $table) {
            foreach ($db->query("SELECT * FROM {$table}")->fetchAll() as $row) {
                self::$stateRows[$table][(int) $row['id']] = $row;
            }
        }

        foreach (self::CRITICAL_TABLES as $table) {
            self::$preIds[$table] = array_fill_keys(
                array_map('intval', $db->query("SELECT id FROM {$table}")->fetchAll(PDO::FETCH_COLUMN)),
                true
            );
            self::$preCounts[$table] = count(self::$preIds[$table]);
        }

        self::$begun = true;
    }

    public static function startId(string $table): int
    {
        return self::$start[$table] ?? PHP_INT_MAX;
    }

    /** Registra uma linha criada pela suíte que não pertence a usuário de teste. */
    public static function track(string $table, int $id): void
    {
        if ($id > 0) {
            self::$tracked[$table][$id] = true;
        }
    }

    /**
     * Remove somente as tentativas de login criadas nesta execução pela suíte
     * (usada para "zerar" o throttle entre cenários sem tocar em registros reais).
     */
    public static function purgeLoginAttempts(): void
    {
        $emails = array_map(static fn (array $u): string => (string) $u['email'], self::$users);
        $in = $emails === [] ? "''" : implode(',', array_map([Database::connection(), 'quote'], $emails));

        Database::connection()->exec(
            "DELETE FROM login_attempts
             WHERE id > @t_login_attempts_start
               AND (email LIKE '" . self::TEST_EMAIL_LIKE . "'
                    OR (email IN ({$in}) AND ip_address IN ('127.0.0.1', '::1')))"
        );
    }

    /** Condição SQL: linha de auditoria gravada por esta execução da suíte. */
    public static function auditScopeSql(): string
    {
        return self::AUDIT_SCOPE;
    }

    public static function finish(): void
    {
        if (!self::$begun || self::$finished) {
            return;
        }

        self::$finished = true;
        $db = Database::connection();

        self::restoreProviders();
        self::restoreUsers();

        $testUsers = array_map('intval', $db->query(
            "SELECT id FROM users WHERE id > @t_users_start AND email LIKE '" . self::TEST_EMAIL_LIKE . "'"
        )->fetchAll(PDO::FETCH_COLUMN));
        $userIn = $testUsers === [] ? '0' : implode(',', $testUsers);

        $convIds = array_map('intval', $db->query(
            "SELECT id FROM conversations WHERE id > @t_conversations_start AND user_id IN ({$userIn})"
        )->fetchAll(PDO::FETCH_COLUMN));

        foreach (array_keys(self::$tracked['conversations'] ?? []) as $id) {
            if ($id > self::startId('conversations')) {
                $convIds[] = $id;
            }
        }

        $convIn = $convIds === [] ? '0' : implode(',', array_unique($convIds));

        $db->exec("DELETE FROM feedback WHERE id > @t_feedback_start AND message_id IN (SELECT id FROM messages WHERE conversation_id IN ({$convIn}))");
        $db->exec("DELETE FROM message_sources WHERE id > @t_message_sources_start AND message_id IN (SELECT id FROM messages WHERE conversation_id IN ({$convIn}))");
        $db->exec("DELETE FROM messages WHERE id > @t_messages_start AND conversation_id IN ({$convIn})");
        $db->exec("DELETE FROM conversations WHERE id > @t_conversations_start AND id IN ({$convIn})");

        foreach (['knowledge_documents', 'internal_solutions'] as $table) {
            foreach (array_keys(self::$tracked[$table] ?? []) as $id) {
                if ($id > self::startId($table)) {
                    $db->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
                }
            }
        }

        // Rate limit/idempotência: só linhas criadas nesta execução, de usuários
        // de teste ou de usuários pré-existentes usados pela suíte.
        $db->exec('DELETE FROM chat_limits WHERE id > @t_chat_limits_start');
        $db->exec('DELETE FROM chat_request_guards WHERE id > @t_chat_request_guards_start');

        foreach (self::$stateRows as $table => $rows) {
            self::restoreRows($table, $rows, true);
        }

        if ($testUsers !== []) {
            $db->exec("DELETE FROM users WHERE id IN ({$userIn})");
        }

        self::purgeLoginAttempts();
        $db->exec('DELETE FROM audit_logs WHERE ' . self::auditScopeSql());
    }

    /**
     * @return array{ok: bool, missing: array<string, int>, residual: array<string, int>, pre_counts: array<string, int>, post_counts: array<string, int>}
     */
    public static function verify(): array
    {
        $db = Database::connection();
        $missing = [];
        $post = [];

        foreach (self::CRITICAL_TABLES as $table) {
            $now = array_fill_keys(
                array_map('intval', $db->query("SELECT id FROM {$table}")->fetchAll(PDO::FETCH_COLUMN)),
                true
            );
            $post[$table] = count($now);
            $lost = count(array_diff_key(self::$preIds[$table] ?? [], $now));

            if ($lost > 0) {
                $missing[$table] = $lost;
            }
        }

        $residual = [
            'users' => (int) $db->query("SELECT COUNT(*) FROM users WHERE email LIKE '" . self::TEST_EMAIL_LIKE . "'")->fetchColumn(),
            'login_attempts' => (int) $db->query("SELECT COUNT(*) FROM login_attempts WHERE email LIKE '" . self::TEST_EMAIL_LIKE . "'")->fetchColumn(),
            'audit_logs' => (int) $db->query('SELECT COUNT(*) FROM audit_logs WHERE ' . self::auditScopeSql())->fetchColumn(),
            'knowledge_documents' => (int) $db->query("SELECT COUNT(*) FROM knowledge_documents WHERE id > @t_knowledge_documents_start")->fetchColumn(),
            'knowledge_chunks' => (int) $db->query("SELECT COUNT(*) FROM knowledge_chunks WHERE id > @t_knowledge_chunks_start")->fetchColumn(),
            'internal_solutions' => (int) $db->query("SELECT COUNT(*) FROM internal_solutions WHERE id > @t_internal_solutions_start")->fetchColumn(),
            'conversations' => (int) $db->query("SELECT COUNT(*) FROM conversations WHERE id > @t_conversations_start")->fetchColumn(),
            'messages' => (int) $db->query("SELECT COUNT(*) FROM messages WHERE id > @t_messages_start")->fetchColumn(),
            'chat_limits' => (int) $db->query('SELECT COUNT(*) FROM chat_limits WHERE id > @t_chat_limits_start')->fetchColumn(),
        ];

        $providersOk = true;

        foreach (self::$providers as $id => $row) {
            $now = $db->query('SELECT * FROM ai_providers WHERE id = ' . (int) $id)->fetch();
            $providersOk = $providersOk && $now !== false && $now == $row;
        }

        $usersOk = true;

        foreach (self::$users as $id => $row) {
            $now = $db->query('SELECT * FROM users WHERE id = ' . (int) $id)->fetch();
            $usersOk = $usersOk && $now !== false && $now == $row;
        }

        return [
            'ok' => $missing === [] && array_sum($residual) === 0 && $providersOk && $usersOk,
            'missing' => $missing,
            'residual' => array_filter($residual),
            'providers_restored' => $providersOk,
            'users_restored' => $usersOk,
            'pre_counts' => self::$preCounts,
            'post_counts' => $post,
        ];
    }

    private static function restoreProviders(): void
    {
        self::restoreRows('ai_providers', self::$providers);
    }

    private static function restoreUsers(): void
    {
        self::restoreRows('users', self::$users);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function restoreRows(string $table, array $rows, bool $reinsertMissing = false): void
    {
        $db = Database::connection();

        foreach ($rows as $id => $row) {
            $columns = array_values(array_filter(array_keys($row), static fn ($c): bool => is_string($c) && $c !== 'id'));
            $values = array_map(static fn (string $c) => $row[$c], $columns);

            $exists = $db->query("SELECT 1 FROM {$table} WHERE id = " . (int) $id)->fetchColumn() !== false;

            if (!$exists) {
                if ($reinsertMissing) {
                    $cols = '`id`, ' . implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
                    $marks = implode(', ', array_fill(0, count($columns) + 1, '?'));
                    $db->prepare("INSERT INTO {$table} ({$cols}) VALUES ({$marks})")->execute(array_merge([$id], $values));
                }

                continue;
            }

            $set = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", $columns));
            $values[] = $id;

            $db->prepare("UPDATE {$table} SET {$set} WHERE id = ?")->execute($values);
        }
    }
}
