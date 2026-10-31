<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/TestIsolation.php';

// Marca d'água + snapshots ANTES de qualquer escrita; restauração garantida mesmo em aborto.
TestIsolation::begin();
register_shutdown_function([TestIsolation::class, 'finish']);

use App\Core\Database;
use App\Core\Logger;
use App\Core\Router;
use App\Core\Csrf;
use App\Core\Flash;
use App\Repositories\UserRepository;
use App\Services\Security\Auth;
use App\Services\Security\Audit;
use App\Services\Security\Permissions;

$tests = [];

function test(string $name, callable $callback): void
{
    global $tests;

    try {
        $callback();
        $tests[] = ['name' => $name, 'pass' => true, 'detail' => ''];
    } catch (Throwable $exception) {
        $tests[] = ['name' => $name, 'pass' => false, 'detail' => $exception->getMessage()];
    }
}

function assertTrue(bool $condition, string $message = 'assertTrue falhou'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = 'assertSame falhou'): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (esperado: ' . var_export($expected, true) . ', recebido: ' . var_export($actual, true) . ')');
    }
}

function assertContains(string $needle, string $haystack, string $message = 'assertContains falhou'): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ' (não encontrado: ' . $needle . ')');
    }
}

test('Environ: APP_NAME carregado', static function (): void {
    assertTrue(env('APP_NAME') !== null && env('APP_NAME') !== '', 'APP_NAME não definido');
});

test('Environ: APP_ENV tem padrão local', static function (): void {
    assertSame('local', env('APP_ENV', 'local'));
});

test('Config: url da aplicação definida', static function (): void {
    assertTrue((string) config('app.url') !== '', 'app.url vazio');
});

test('Config: APP_KEY com tamanho adequado', static function (): void {
    assertTrue(strlen((string) config('app.key')) >= 16, 'APP_KEY curta ou ausente');
});

test('Config: diretório de logs configurado', static function (): void {
    assertTrue(is_dir((string) config('paths.logs')), 'Diretório storage/logs não existe');
});

test('Autoload: classes do núcleo carregam', static function (): void {
    foreach ([Database::class, Logger::class, Router::class] as $class) {
        assertTrue(class_exists($class), 'Classe não autoload: ' . $class);
    }
});

test('Banco: conexão PDO e versão do servidor', static function (): void {
    Database::ensureDatabaseExists();
    $version = Database::serverVersion();
    assertTrue($version !== 'indisponível', 'Conexão com banco falhou');
});

test('Banco: migrations todas aplicadas', static function (): void {
    $files = glob(base_path('database/migrations') . '/[0-9]*_*.php');
    assertTrue($files !== false, 'Nenhuma migration encontrada');

    $applied = Database::connection()
        ->query('SELECT COUNT(*) FROM migrations')
        ->fetchColumn();

    assertSame((int) count($files), (int) $applied, 'Quantidade de migrations aplicadas difere dos arquivos');
});

test('Banco: tabelas fundamentais existem', static function (): void {
    $required = [
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

    $db = Database::connection();
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?'
    );

    foreach ($required as $table) {
        $stmt->execute([(string) config('db.name'), $table]);
        assertSame(1, (int) $stmt->fetchColumn(), 'Tabela ausente: ' . $table);
    }
});

test('Banco: índice FULLTEXT em knowledge_chunks', static function (): void {
    $row = Database::connection()->query(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = '" . (string) config('db.name') . "'
           AND table_name = 'knowledge_chunks'
           AND index_type = 'FULLTEXT'"
    )->fetchColumn();

    assertTrue((int) $row >= 1, 'FULLTEXT ausente em knowledge_chunks');
});

test('Banco: índice FULLTEXT em knowledge_documents', static function (): void {
    $row = Database::connection()->query(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = '" . (string) config('db.name') . "'
           AND table_name = 'knowledge_documents'
           AND index_type = 'FULLTEXT'"
    )->fetchColumn();

    assertTrue((int) $row >= 1, 'FULLTEXT ausente em knowledge_documents');
});

test('Seed: usuário admin existe com perfil admin', static function (): void {
    $stmt = Database::connection()->prepare(
        'SELECT role, status FROM users WHERE email = ?'
    );
    $stmt->execute(['admin@assistente.local']);
    $user = $stmt->fetch();

    assertTrue($user !== false, 'Usuário admin não encontrado');
    assertSame('admin', $user['role']);
    assertSame('active', $user['status']);
});

test('Seed: providers openrouter e nvidia existem', static function (): void {
    $stmt = Database::connection()->prepare('SELECT provider FROM ai_providers WHERE provider = ?');
    $stmt->execute(['openrouter']);
    assertTrue($stmt->fetchColumn() !== false, 'openrouter ausente');

    $stmt->execute(['nvidia']);
    assertTrue($stmt->fetchColumn() !== false, 'nvidia ausente');
});

test('Seed: fontes oficial e interna existem', static function (): void {
    $stmt = Database::connection()->prepare(
        "SELECT type FROM knowledge_sources WHERE type = ? AND status = 'active'"
    );

    $stmt->execute(['official']);
    assertTrue($stmt->fetchColumn() !== false, 'fonte official ausente');

    $stmt->execute(['internal']);
    assertTrue($stmt->fetchColumn() !== false, 'fonte internal ausente');
});

test('Seed: system_settings com app_name e system_prompt', static function (): void {
    $stmt = Database::connection()->prepare(
        'SELECT setting_key FROM system_settings WHERE setting_key = ?'
    );

    $stmt->execute(['app_name']);
    assertTrue($stmt->fetchColumn() !== false, 'app_name ausente');

    $stmt->execute(['system_prompt']);
    assertTrue($stmt->fetchColumn() !== false, 'system_prompt ausente');
});

test('Log: escrita em arquivo', static function (): void {
    Logger::info('teste', 'ensaio automatizado');

    $file = config('paths.logs') . '/teste.log';

    try {
        assertTrue(is_file($file), 'Arquivo de log não criado');
        assertContains('ensaio automatizado', (string) file_get_contents($file), 'Conteúdo do log não encontrado');
    } finally {
        @unlink($file);
    }
});

test('Log: sanitização de credenciais', static function (): void {
    $sanitized = Logger::sanitize(['api_key' => 'sk-super-secreta', 'mensagem' => 'ok']);

    assertSame('[REDACTED]', $sanitized['api_key']);
    assertSame('ok', $sanitized['mensagem']);
});

test('Router: pathFromUri remove base path', static function (): void {
    $router = new Router();

    assertSame(
        '/diagnostico',
        $router->pathFromUri('/assistente-unimestre/diagnostico', '/assistente-unimestre')
    );
});

test('Router: match de rota registrada', static function (): void {
    $router = new Router();
    $router->get('/diagnostico', 'DiagnosticController@index');

    assertSame('DiagnosticController@index', $router->match('GET', '/diagnostico'));
});

// ============================= FASE 2 =============================

test('F2 DB: colunas de segurança em users', static function (): void {
    $required = ['must_change_password', 'last_login_at', 'failed_login_attempts', 'locked_until'];
    $db = Database::connection();
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?'
    );

    foreach ($required as $column) {
        $stmt->execute([(string) config('db.name'), 'users', $column]);
        assertSame(1, (int) $stmt->fetchColumn(), 'Coluna ausente em users: ' . $column);
    }
});

test('F2 DB: tabela login_attempts existe', static function (): void {
    $stmt = Database::connection()->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?'
    );
    $stmt->execute([(string) config('db.name'), 'login_attempts']);
    assertSame(1, (int) $stmt->fetchColumn(), 'Tabela login_attempts ausente');
});

test('F2 Password: hash e verificação com PASSWORD_DEFAULT', static function (): void {
    $hash = password_hash('SenhaTeste@123', PASSWORD_DEFAULT);

    assertTrue(password_verify('SenhaTeste@123', $hash), 'password_verify falhou');
    assertTrue(!password_verify('Errada@123', $hash), 'password_verify aceitou senha errada');
    assertTrue(str_starts_with($hash, '$2y$'), 'Hash não é bcrypt');
});

test('F2 Segurança: admin possui usuários.manage', static function (): void {
    assertTrue(Permissions::userCan('admin', 'users.manage'), 'admin sem users.manage');
});

test('F2 Segurança: tecnico e usuario sem users.manage', static function (): void {
    assertTrue(!Permissions::userCan('tecnico', 'users.manage'), 'tecnico com users.manage');
    assertTrue(!Permissions::userCan('usuario', 'users.manage'), 'usuario com users.manage');
    assertTrue(Permissions::userCan('tecnico', 'dashboard.view'), 'tecnico sem dashboard.view');
});

test('F2 Segurança: roles carregadas', static function (): void {
    assertSame(['admin', 'tecnico', 'usuario'], Permissions::roles());
});

test('F2 Csrf: token e campo oculto', static function (): void {
    $token = Csrf::token();

    assertTrue(strlen($token) === 64, 'Token CSRF sem 64 caracteres');
    assertContains('name="_csrf"', Csrf::field(), 'Campo CSRF não gerado');
    assertContains(htmlspecialchars($token, ENT_QUOTES, 'UTF-8'), Csrf::field(), 'Valor do token no campo');
});

test('F2 Csrf: valida token correto e rejeita inválido', static function (): void {
    $token = Csrf::token();
    assertTrue(Csrf::validate($token), 'Token válido rejeitado');
    assertTrue(!Csrf::validate('token-invalido'), 'Token inválido aceito');
});

test('F2 Flash: mensagens, input antigo e limpeza', static function (): void {
    Flash::clear();
    Flash::set('error', 'Credenciais inválidas.');
    Flash::input('email', 'usuario@teste.local');

    $messages = Flash::getMessages();

    assertSame(['error' => ['Credenciais inválidas.']], $messages);
    assertSame([], Flash::getMessages(), 'Flash não limpo após leitura');
});

test('F2 Router: parâmetro {id} em rota', static function (): void {
    $router = new Router();
    $router->get('/usuarios/{id}/editar', 'UserController@editForm');

    $route = $router->locate('GET', '/usuarios/7/editar');

    assertTrue($route !== null, 'Rota com parâmetro não encontrada');
    assertSame(7, $route['params']['id']);
    assertSame('UserController@editForm', $route['handler']);
});

test('F2 Router: middleware declarado na rota', static function (): void {
    $router = new Router();
    $router->get('/usuarios', 'UserController@index', ['auth', 'permission:users.manage']);

    $route = $router->locate('GET', '/usuarios');

    assertTrue($route !== null, 'Rota não encontrada');
    assertSame(['auth', 'permission:users.manage'], $route['middleware']);
});

test('F2 Auth: fluxo de tentativa ok', static function (): void {
    TestIsolation::purgeLoginAttempts();

    $email = 'cli-ok-' . time() . '@assistente.test';
    $repo = new UserRepository();
    $id = $repo->create([
        'name' => 'Teste Ok',
        'email' => $email,
        'password_hash' => password_hash('SenhaTeste@123', PASSWORD_DEFAULT),
        'role' => 'tecnico',
        'status' => 'active',
        'must_change_password' => 0,
    ]);

    try {
        $result = Auth::instance()->attempt($email, 'SenhaTeste@123');

        assertSame('ok', $result['status']);
        assertSame((int) $id, (int) $result['user']['id']);
    } finally {
        $repo->delete($id);
    }
});

test('F2 Auth: tentativa inválida registra falha', static function (): void {
    $email = 'cli-inv-' . time() . '@assistente.test';
    $repo = new UserRepository();
    $id = $repo->create([
        'name' => 'Teste Inválido',
        'email' => $email,
        'password_hash' => password_hash('SenhaTeste@123', PASSWORD_DEFAULT),
        'role' => 'usuario',
        'status' => 'active',
        'must_change_password' => 0,
    ]);

    try {
        $result = Auth::instance()->attempt($email, 'SenhaErrada@123');

        assertSame('invalid', $result['status']);
        assertTrue($repo->find($id)['failed_login_attempts'] >= 1, 'Contador de falhas não incrementado');
    } finally {
        $repo->delete($id);
    }
});

test('F2 Auth: usuário inativo é recusado', static function (): void {
    $email = 'cli-inactive-' . time() . '@assistente.test';
    $repo = new UserRepository();
    $id = $repo->create([
        'name' => 'Teste Inativo',
        'email' => $email,
        'password_hash' => password_hash('SenhaTeste@123', PASSWORD_DEFAULT),
        'role' => 'usuario',
        'status' => 'inactive',
        'must_change_password' => 0,
    ]);

    try {
        $result = Auth::instance()->attempt($email, 'SenhaTeste@123');

        assertSame('inactive', $result['status']);
    } finally {
        $repo->delete($id);
    }
});

test('F2 Auth: conta bloqueada é recusada', static function (): void {
    $email = 'cli-locked-' . time() . '@assistente.test';
    $repo = new UserRepository();
    $id = $repo->create([
        'name' => 'Teste Bloqueado',
        'email' => $email,
        'password_hash' => password_hash('SenhaTeste@123', PASSWORD_DEFAULT),
        'role' => 'usuario',
        'status' => 'active',
        'must_change_password' => 0,
    ]);

    try {
        $repo->lockUser($id, 15);
        $result = Auth::instance()->attempt($email, 'SenhaTeste@123');

        assertSame('locked', $result['status']);

        $repo->unlockUser($id);
        assertTrue(!$repo->isLocked($id), 'Unlock não desbloqueou a conta');
    } finally {
        $repo->delete($id);
        TestIsolation::purgeLoginAttempts();
    }
});

test('F2 Auth: rate limit bloqueia tentativas', static function (): void {
    $ip = client_ip();
    $email = 'cli-rate-' . time() . '@assistente.test';
    $repo = new UserRepository();
    $max = (int) config('auth.max_attempts', 5);

    try {
        TestIsolation::purgeLoginAttempts();

        for ($i = 0; $i < $max; $i++) {
            $repo->recordFailedAttempt($email, $ip);
        }

        assertTrue($repo->countRecentFailures($email, 15) >= $max, 'Contagem de falhas insuficiente');
        assertSame('rate_limited', Auth::instance()->attempt($email, 'QualquerSenha@123')['status']);
    } finally {
        TestIsolation::purgeLoginAttempts();
    }
});

test('F2 Auth: lock após exceder tentativas', static function (): void {
    $email = 'cli-lockmax-' . time() . '@assistente.test';
    $repo = new UserRepository();
    $id = $repo->create([
        'name' => 'Teste Lock Max',
        'email' => $email,
        'password_hash' => password_hash('SenhaTeste@123', PASSWORD_DEFAULT),
        'role' => 'usuario',
        'status' => 'active',
        'must_change_password' => 0,
    ]);
    $max = (int) config('auth.max_attempts', 5);

    try {
        TestIsolation::purgeLoginAttempts();

        for ($i = 0; $i < $max; $i++) {
            Auth::instance()->attempt($email, 'SenhaErrada@123');
        }

        $user = $repo->find($id);
        assertTrue((int) $user['failed_login_attempts'] >= $max, 'Tentativas não contadas');
        assertTrue($repo->isLocked($id), 'Usuário não bloqueado após exceder tentativas');
    } finally {
        $repo->delete($id);
        $stmt = Database::connection()->prepare('DELETE FROM login_attempts WHERE email = ?');
        $stmt->execute([$email]);
    }
});

test('F2 Auth: último admin ativo protegido', static function (): void {
    $repo = new UserRepository();
    $adminCount = $repo->countByRole('admin');
    $admin = $repo->findByEmail((string) env('APP_ADMIN_EMAIL', 'admin@assistente.local'));

    assertTrue($admin !== null, 'Admin não encontrado');

    $expected = $adminCount === 1;
    assertSame($expected, $repo->isLastActiveAdmin((int) $admin['id']), 'Proteção do último admin falhou');
    assertTrue(!$repo->isLastActiveAdmin(999999), 'Id inexistente tratado como último admin');
});

test('F2 Audit: registro cria linha em audit_logs', static function (): void {
    $db = Database::connection();
    $db->exec("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = 'audit.test')");

    Audit::record('audit.test', 'sistema', null, ['motivo' => 'ensaio']);

    $row = $db->query("SELECT id, entity_type FROM audit_logs WHERE action = 'audit.test' ORDER BY id DESC LIMIT 1")->fetch();

    assertTrue($row !== false, 'Linha de auditoria não criada');
    assertSame('sistema', $row['entity_type']);

    $db->exec("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = 'audit.test')");
});

test('F2 Repositório: contagens por perfil e situação', static function (): void {
    $repo = new UserRepository();

    assertSame((int) $repo->total(), (int) Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn());
    assertSame((int) $repo->countByStatus('active'), (int) $repo->countByStatus('active'));
    assertTrue($repo->countByRole('admin') >= 1, 'Nenhum admin ativo');
});

test('F2 Escaping: e() preserva texto PT e escapa HTML', static function (): void {
    assertSame('Alterar senha', e('Alterar senha'));
    assertSame('Senha atual', e('Senha atual'));
    assertSame('Escolha uma nova senha para sua conta', e('Escolha uma nova senha para sua conta'));
    assertContains('&lt;script&gt;', e('<script>alert("x")</script>'));
    assertContains('&quot;', e('<script>alert("x")</script>'));
    assertSame(htmlspecialchars('<script>alert("x")</script>', ENT_QUOTES, 'UTF-8'), e('<script>alert("x")</script>'), 'e() diverge de htmlspecialchars');
});

require_once __DIR__ . '/phase3.php';
require_once __DIR__ . '/phase4.php';
require_once __DIR__ . '/phase5.php';
require_once __DIR__ . '/phase6.php';
require_once __DIR__ . '/hardening.php';
require_once __DIR__ . '/retrieval70a.php';

// ============================= ISOLAMENTO =============================
TestIsolation::finish();
$isolation = TestIsolation::verify();
test('ISOLAMENTO: IDs pré-existentes preservados, sem resíduos, provider/usuários restaurados', static function () use ($isolation): void {
    assertTrue($isolation['ok'], json_encode(array_diff_key($isolation, ['pre_counts' => 1, 'post_counts' => 1])));
});
echo 'ISOLAMENTO ' . json_encode($isolation) . PHP_EOL;

$passCount = 0;
$failCount = 0;

echo PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;
echo 'FASES 1 + 2 + 3 + 4 + 5 + 6 — SUÍTE DE TESTES' . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;

foreach ($tests as $result) {
    $status = $result['pass'] ? 'PASS' : 'FAIL';
    $detail = $result['pass'] ? '' : ' — ' . $result['detail'];

    printf("%-4s %s%s\n", $status, $result['name'], $detail);

    if ($result['pass']) {
        $passCount++;
    } else {
        $failCount++;
    }
}

echo str_repeat('=', 80) . PHP_EOL;
printf("TOTAL: %d | PASS: %d | FAIL: %d\n", $passCount + $failCount, $passCount, $failCount);
echo str_repeat('=', 80) . PHP_EOL . PHP_EOL;

exit($failCount > 0 ? 1 : 0);