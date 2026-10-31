<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/TestIsolation.php';

// Marca d'água + snapshots ANTES de qualquer escrita; restauração garantida mesmo em aborto.
TestIsolation::begin();
// finish() é chamado ao fim de httpSafeCleanup() (shutdown), depois das limpezas.

use App\Core\Database;
use App\Repositories\KnowledgeChunkRepository;
use App\Repositories\KnowledgeDocumentRepository;
use App\Repositories\KnowledgeSourceRepository;
use App\Repositories\UserRepository;
use App\Services\RAG\ChunkingService;

error_reporting(E_ALL & ~E_DEPRECATED);

$results = [];

function t(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = ['name' => $name, 'pass' => $pass, 'detail' => $detail];
}

function check(bool $condition, string $message = ''): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function newSession(): string
{
    $path = sys_get_temp_dir() . '/http_teste_' . bin2hex(random_bytes(6)) . '.jar';

    return $path;
}

function cleanSession(string $jar): void
{
    if (is_file($jar)) {
        @unlink($jar);
    }
}

/**
 * Restaura o provider real e remove artefatos de teste MESMO em aborto
 * (fatal, excecao fora de try/catch, requisicao invalida, etc).
 *
 * Eh idempotente: se o fluxo normal ja fez o cleanup, apenas repete o
 * mesmo estado (nada muda duas vezes).
 */
function httpSafeCleanup(): void
{
    try {
        $db = Database::connection();

        if (isset($GLOBALS['httpF5OriginalProvider']) && is_array($GLOBALS['httpF5OriginalProvider'])) {
            $p = $GLOBALS['httpF5OriginalProvider'];
            $restore = $db->prepare('UPDATE ai_providers
                SET name = ?, model = ?, api_key_encrypted = ?, base_url = ?,
                    temperature = ?, max_tokens = ?, timeout_seconds = ?, status = ?, is_default = ?
                WHERE id = ?');
            $restore->execute([
                $p['name'],
                $p['model'],
                $p['api_key_encrypted'],
                $p['base_url'],
                $p['temperature'],
                $p['max_tokens'],
                $p['timeout_seconds'],
                $p['status'],
                $p['is_default'],
                (int) $p['id'],
            ]);
        }

        if (isset($GLOBALS['httpAdminConvIds']) && is_array($GLOBALS['httpAdminConvIds'])) {
            foreach (array_values(array_unique(array_filter(array_map('intval', $GLOBALS['httpAdminConvIds'])))) as $convId) {
                if ($convId <= TestIsolation::startId('conversations')) {
                    continue; // nunca remove conversa pré-existente
                }

                $db->prepare('DELETE FROM message_sources WHERE message_id IN (SELECT id FROM messages WHERE conversation_id = ?)')->execute([$convId]);
                $db->prepare('DELETE FROM messages WHERE conversation_id = ?')->execute([$convId]);
                $db->prepare('DELETE FROM conversations WHERE id = ?')->execute([$convId]);
            }
        }

        $db->exec("DELETE FROM knowledge_documents WHERE title LIKE 'F6 HTTP Boleto %'");
        $db->exec("DELETE FROM knowledge_documents WHERE url LIKE 'https://unimestre.com/http-%'");
        $db->exec("DELETE FROM internal_solutions WHERE title LIKE 'Solução HTTP %'");
        $db->exec("DELETE FROM internal_solutions WHERE title LIKE 'F4 HTTP %' OR title LIKE 'Solução F4 HTTP %'");
        $db->exec('DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action LIKE \'chat.%\')');
        $db->exec("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action LIKE 'ai.%' OR entity_type = 'ai_provider')");

        $db->exec("DELETE FROM chat_limits WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'f6-%@assistente.test')");
        $db->exec("DELETE FROM chat_request_guards WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'f6-%@assistente.test')");
        // Bucket do admin usado nos testes F6 de chat (F6-07/F6-08) tambem volta ao zero.
        $db->prepare('DELETE FROM chat_limits WHERE user_id = ? AND id > @t_chat_limits_start')->execute([$GLOBALS['httpAdminId'] ?? 1]);
        $db->prepare('DELETE FROM chat_request_guards WHERE user_id = ? AND id > @t_chat_request_guards_start')->execute([$GLOBALS['httpAdminId'] ?? 1]);
        $db->exec("DELETE FROM message_sources WHERE message_id IN (SELECT id FROM messages WHERE conversation_id IN (SELECT id FROM conversations WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'f6-%@assistente.test')))");
        $db->exec("DELETE FROM messages WHERE conversation_id IN (SELECT id FROM conversations WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'f6-%@assistente.test'))");
        $db->exec("DELETE FROM conversations WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'f6-%@assistente.test')");
        $db->exec("DELETE FROM users WHERE email LIKE 'f6-%@assistente.test'");
        $db->exec("DELETE FROM users WHERE email LIKE 'http-%-%@assistente.test'");
        TestIsolation::purgeLoginAttempts();
    } catch (Throwable $e) {
        fwrite(STDERR, '[httpSafeCleanup] ' . $e->getMessage() . PHP_EOL);
    }

    // Admin real e providers voltam EXATAMENTE ao snapshot do início (hash de
    // senha, must_change_password, bloqueio, chave, modelo, status...).
    TestIsolation::finish();
}

function request(string $method, string $path, array $data = [], string $jar = '', bool $json = false, string $csrf = ''): array
{
    $url = preg_match('#^https?://#i', (string) $path) ? $path : app_url($path);
    $ch = curl_init($url);

    $headers = [
        'User-Agent: http-teste-unimestre/1.0',
    ];

    if ($csrf !== '') {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($jar !== '') {
        $options[CURLOPT_COOKIEJAR] = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }

    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST] = true;

        if ($json) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        } else {
            $options[CURLOPT_POSTFIELDS] = http_build_query($data);
        }
    }

    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);

    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);

        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $error];
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $headerSize = strpos($raw, "\r\n\r\n");
    $headerBlock = $headerSize === false ? $raw : substr($raw, 0, $headerSize);
    $body = $headerSize === false ? '' : substr($raw, $headerSize + 4);

    $headers = [];
    foreach (explode("\r\n", $headerBlock) as $line) {
        if (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($key))] = trim($value);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

function extractCsrf(string $html): string
{
    if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $matches)) {
        return $matches[1];
    }

    if (preg_match('/data-csrf="([a-f0-9]{64})"/', $html, $matches)) {
        return $matches[1];
    }

    throw new RuntimeException('Token CSRF não encontrado na página');
}

$adminEmail = (string) env('APP_ADMIN_EMAIL', 'admin@assistente.local');
$adminDefault = (string) env('APP_ADMIN_PASSWORD', 'admin123');
$newPassword = 'TesteAdmin@1234';
$base = app_url('/');

// Garantia de restauracao do ambiente mesmo se a suíte abortar no meio do caminho.
$GLOBALS['httpAdminEmail'] = $adminEmail;
$GLOBALS['httpAdminPassword'] = $adminDefault;
$GLOBALS['httpF5OriginalProvider'] = null;
$GLOBALS['httpAdminConvIds'] = [];

register_shutdown_function('httpSafeCleanup');

// ============================= SETUP =============================
echo str_repeat('=', 80) . PHP_EOL;
echo 'FASE 2 — TESTES HTTP (Apache ' . parse_url($base, PHP_URL_PORT) . ')' . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;

$setupError = '';

try {
    TestIsolation::purgeLoginAttempts();

    $repo = new UserRepository();
    $repo->updatePassword(
        (int) $repo->findByEmail($adminEmail)['id'],
        password_hash($adminDefault, PASSWORD_DEFAULT),
        1
    );
    $repo->unlockUser((int) $repo->findByEmail($adminEmail)['id']);

    $admin = $repo->findByEmail($adminEmail);
    $adminId = (int) $admin['id'];
    $GLOBALS['httpAdminId'] = $adminId;

    echo 'Setup OK (admin: ' . $adminEmail . ', id ' . $adminId . ')' . PHP_EOL;
} catch (Throwable $exception) {
    $setupError = $exception->getMessage();
    echo 'Setup FALHOU: ' . $setupError . PHP_EOL;
}

// ============================= CENÁRIOS =============================

// 1. Página pública de login
if ($setupError === '') {
    try {
        $jar = newSession();
        $res = request('GET', '/login', [], $jar);

        check($res['status'] === 200, 'GET /login retornou ' . $res['status']);
        check(str_contains($res['body'], 'id="email"'), 'Campo e-mail ausente');
        check(str_contains($res['body'], 'name="_csrf"'), 'CSRF ausente no formulário');
        cleanSession($jar);

        t('GET /login responde com formulário + CSRF', true);
    } catch (Throwable $e) {
        t('GET /login responde com formulário + CSRF', false, $e->getMessage());
    }
}

// 2. POST sem CSRF é rejeitado (419)
try {
    $jar = newSession();
    $res = request('POST', '/login', ['email' => $adminEmail, 'password' => 'x'], $jar);

    check($res['status'] === 419, 'POST /login sem CSRF retornou ' . $res['status']);
    cleanSession($jar);

    t('POST sem CSRF bloqueado com 419', true);
} catch (Throwable $e) {
    t('POST sem CSRF bloqueado com 419', false, $e->getMessage());
}

// 3. Login com credenciais erradas
try {
    $jar = newSession();
    $csrf = extractCsrf(request('GET', '/login', [], $jar)['body']);
    $res = request('POST', '/login', ['email' => $adminEmail, 'password' => 'senha-errada', '_csrf' => $csrf], $jar);

    check($res['status'] === 302, 'Login inválido retornou ' . $res['status']);
    check(str_contains($res['headers']['location'] ?? '', '/login'), 'Não redirecionou para /login');
    cleanSession($jar);

    t('Login inválido rejeitado', true);
} catch (Throwable $e) {
    t('Login inválido rejeitado', false, $e->getMessage());
}

// 4. Login admin redireciona para troca forçada de senha
$jar = '';
try {
    $jar = newSession();
    $csrf = extractCsrf(request('GET', '/login', [], $jar)['body']);
    $res = request('POST', '/login', ['email' => $adminEmail, 'password' => $adminDefault, '_csrf' => $csrf], $jar);

    check($res['status'] === 302, 'Login admin retornou ' . $res['status']);
    check(str_contains($res['headers']['location'] ?? '', '/alterar-senha'), 'Não forçou troca de senha');

    t('Login admin força troca de senha', true);
} catch (Throwable $e) {
    t('Login admin força troca de senha', false, $e->getMessage());
}

// 5. Admin tenta acessar painel antes de trocar senha e é redirecionado
if ($jar !== '') {
    try {
        $res = request('GET', '/dashboard', [], $jar);

        check($res['status'] === 302, 'Dashboard retornou ' . $res['status']);
        check(str_contains($res['headers']['location'] ?? '', '/alterar-senha'), 'Não redirecionou para alterar senha');

        t('Painel bloqueado até troca de senha', true);
    } catch (Throwable $e) {
        t('Painel bloqueado até troca de senha', false, $e->getMessage());
    }
}

// 6a. Renderização da página /alterar-senha (regressão do bug de corrupção visual)
if ($jar !== '') {
    try {
        $res = request('GET', '/alterar-senha', [], $jar);
        $body = $res['body'];

        check($res['status'] === 200, 'GET /alterar-senha retornou ' . $res['status']);
        check(str_contains($body, '>Alterar senha<'), "'Alterar senha' ausente no h1");
        check(str_contains($body, 'Senha atual'), "'Senha atual' ausente");
        check(str_contains($body, 'Nova senha'), "'Nova senha' ausente");
        check(str_contains($body, 'Confirmar nova senha'), "'Confirmar nova senha' ausente");
        check(str_contains($body, 'Salvar nova senha'), "'Salvar nova senha' ausente");
        check(str_contains($body, 'Mínimo de 8 caracteres, contendo letra e número.'), 'Ajuda de política ausente');
        check(str_contains($body, 'class="form-control"'), 'Input sem form-control (Bootstrap)');
        check(str_contains($body, 'class="form-label"'), 'Label sem form-label (Bootstrap)');
        check(str_contains($body, 'class="btn btn-primary w-100"'), 'Botão sem Bootstrap');
        check(!str_contains($body, '/admin/admin/'), 'action com /admin/admin/');
        check(!str_contains($body, 'clcss'), 'Markup corrompido (clcss) presente');
        check(!str_contains($body, 'lcbel'), 'Markup corrompido (lcbel) presente');
        check(!str_contains($body, 'Altercr senhc'), 'Texto corrupto "Altercr senhc" presente');
        check(!str_contains($body, 'Sclvcr novc senhc'), 'Texto corrupto "Sclvcr novc senhc" presente');
        check(!str_contains($body, '>$</div>'), 'Caractere $ residual acima do título');

        t('Página alterar-senha íntegra (corrupção visual corrigida)', true);
    } catch (Throwable $e) {
        t('Página alterar-senha íntegra (corrupção visual corrigida)', false, $e->getMessage());
    }
}

// 6b. Senha atual incorreta
if ($jar !== '') {
    try {
        $csrf = extractCsrf(request('GET', '/alterar-senha', [], $jar)['body']);
        $res = request('POST', '/alterar-senha', [
            'current_password' => 'senha-errada',
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
            '_csrf' => $csrf,
        ], $jar);

        check($res['status'] === 302, 'Senha incorreta retornou ' . $res['status']);
        check(str_contains($res['headers']['location'] ?? '', '/alterar-senha'), 'Não rejeitou senha atual incorreta');
        check(password_verify($adminDefault, (new UserRepository())->findByEmail($adminEmail)['password_hash']) === true, 'Senha alterada mesmo com atual incorreta');

        $res = request('GET', '/alterar-senha', [], $jar);
        check(str_contains($res['body'], 'A senha atual está incorreta.'), 'Flash de senha incorreta ausente');

        t('Senha atual incorreta rejeitada', true);
    } catch (Throwable $e) {
        t('Senha atual incorreta rejeitada', false, $e->getMessage());
    }
}

// 6c. Senha fraca (só dígitos)
if ($jar !== '') {
    try {
        $csrf = extractCsrf(request('GET', '/alterar-senha', [], $jar)['body']);
        $res = request('POST', '/alterar-senha', [
            'current_password' => $adminDefault,
            'new_password' => '12345678',
            'new_password_confirmation' => '12345678',
            '_csrf' => $csrf,
        ], $jar);

        check($res['status'] === 302, 'Senha fraca retornou ' . $res['status']);
        check(str_contains($res['headers']['location'] ?? '', '/alterar-senha'), 'Senha fraca não rejeitada');
        check(password_verify($adminDefault, (new UserRepository())->findByEmail($adminEmail)['password_hash']) === true, 'Senha fraca gravada');

        $res = request('GET', '/alterar-senha', [], $jar);
        check(str_contains($res['body'], 'A senha deve conter letras e números.'), 'Flash de política ausente');

        t('Senha fraca rejeitada (letra e número exigidos)', true);
    } catch (Throwable $e) {
        t('Senha fraca rejeitada (letra e número exigidos)', false, $e->getMessage());
    }
}

// 6d. Confirmação diferente
if ($jar !== '') {
    try {
        $csrf = extractCsrf(request('GET', '/alterar-senha', [], $jar)['body']);
        $res = request('POST', '/alterar-senha', [
            'current_password' => $adminDefault,
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword . 'x',
            '_csrf' => $csrf,
        ], $jar);

        check($res['status'] === 302, 'Confirmação divergente retornou ' . $res['status']);
        check(str_contains($res['headers']['location'] ?? '', '/alterar-senha'), 'Confirmação divergente não rejeitada');
        check(password_verify($adminDefault, (new UserRepository())->findByEmail($adminEmail)['password_hash']) === true, 'Senha gravada com confirmação divergente');

        $res = request('GET', '/alterar-senha', [], $jar);
        check(str_contains($res['body'], 'A confirmação da nova senha não confere.'), 'Flash de confirmação ausente');

        t('Confirmação diferente rejeitada', true);
    } catch (Throwable $e) {
        t('Confirmação diferente rejeitada', false, $e->getMessage());
    }
}

// 6. Troca forçada de senha (fluxo real de sucesso)
if ($jar !== '') {
    try {
        $csrf = extractCsrf(request('GET', '/alterar-senha', [], $jar)['body']);
        $res = request('POST', '/alterar-senha', [
            'current_password' => $adminDefault,
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
            '_csrf' => $csrf,
        ], $jar);

        check($res['status'] === 302, 'Troca retornou ' . $res['status']);
        check(str_contains($res['headers']['location'] ?? '', '/dashboard'), 'Não redirecionou para o painel');
        check(password_verify($newPassword, (new UserRepository())->findByEmail($adminEmail)['password_hash']), 'Senha nova não gravada');
        check((int) (new UserRepository())->findByEmail($adminEmail)['must_change_password'] === 0, 'must_change_password não zerado após troca');

        t('Troca forçada de senha funciona', true);
    } catch (Throwable $e) {
        t('Troca forçada de senha funciona', false, $e->getMessage());
    }
}

// 6e. Login com a nova senha após a troca
if ($jar !== '') {
    try {
        request('POST', '/logout', [], $jar);
        $jar = newSession();
        $csrf = extractCsrf(request('GET', '/login', [], $jar)['body']);
        $res = request('POST', '/login', ['email' => $adminEmail, 'password' => $newPassword, '_csrf' => $csrf], $jar);

        check($res['status'] === 302, 'Login nova senha retornou ' . $res['status']);
        check(!str_contains($res['headers']['location'] ?? '', '/alterar-senha'), 'Redirecionou de novo para troca de senha');

        $res = request('GET', '/dashboard', [], $jar);
        check($res['status'] === 200, 'Dashboard bloqueado após novo login');

        t('Login com a nova senha acessa o painel', true);
    } catch (Throwable $e) {
        t('Login com a nova senha acessa o painel', false, $e->getMessage());
    }
}

// 7. Dashboard autenticado + usuários como admin
if ($jar !== '') {
    try {
        $res = request('GET', '/dashboard', [], $jar);
        check($res['status'] === 200, 'Dashboard retornou ' . $res['status']);
        check(str_contains($res['body'], 'Bem-vindo(a)'), 'Dashboard sem saudação');

        $res = request('GET', '/usuarios', [], $jar);
        check($res['status'] === 200, 'GET /usuarios retornou ' . $res['status']);
        check(str_contains($res['body'], 'Usuários'), 'Listagem de usuários ausente');

        t('Dashboard + listagem de usuários (admin)', true);
    } catch (Throwable $e) {
        t('Dashboard + listagem de usuários (admin)', false, $e->getMessage());
    }
}

// 8. Admin cria usuário (CRUD - create) via HTTP
$tecnicoEmail = 'http-tecnico-' . time() . '@assistente.test';
$tecnicoId = 0;
if ($jar !== '') {
    try {
        $csrf = extractCsrf(request('GET', '/usuarios/novo', [], $jar)['body']);
        $res = request('POST', '/usuarios', [
            'name' => 'Tecnico HTTP',
            'email' => $tecnicoEmail,
            'role' => 'tecnico',
            'status' => 'active',
            'password' => 'SenhaInicial@123',
            'password_confirmation' => 'SenhaInicial@123',
            '_csrf' => $csrf,
        ], $jar);

        check($res['status'] === 302, 'Criação retornou ' . $res['status']);
        check(str_contains($res['headers']['location'] ?? '', '/usuarios'), 'Não redirecionou para /usuarios');

        $created = (new UserRepository())->findByEmail($tecnicoEmail);
        check($created !== null, 'Usuário não criado no banco');
        check((int) $created['must_change_password'] === 1, 'must_change_password não forçado no novo usuário');
        $tecnicoId = (int) $created['id'];

        t('Admin cria usuário (must_change_password=1)', true);
    } catch (Throwable $e) {
        t('Admin cria usuário (must_change_password=1)', false, $e->getMessage());
    }
}

// 9. Admin edita usuário (CRUD - update) via HTTP
if ($jar !== '' && $tecnicoId > 0) {
    try {
        $csrf = extractCsrf(request('GET', '/usuarios/' . $tecnicoId . '/editar', [], $jar)['body']);
        $res = request('POST', '/usuarios/' . $tecnicoId . '/editar', [
            'name' => 'Tecnico HTTP Editado',
            'email' => $tecnicoEmail,
            'role' => 'tecnico',
            'status' => 'active',
            '_csrf' => $csrf,
        ], $jar);

        check($res['status'] === 302, 'Edição retornou ' . $res['status']);
        check((new UserRepository())->find($tecnicoId)['name'] === 'Tecnico HTTP Editado', 'Nome não atualizado');

        t('Admin edita usuário', true);
    } catch (Throwable $e) {
        t('Admin edita usuário', false, $e->getMessage());
    }
}

// 10. Técnico sem permissão recebe 403 em /usuarios
$jarTecnico = '';
if ($tecnicoId > 0) {
    try {
        $jarTecnico = newSession();
        $csrf = extractCsrf(request('GET', '/login', [], $jarTecnico)['body']);
        $res = request('POST', '/login', ['email' => $tecnicoEmail, 'password' => 'SenhaInicial@123', '_csrf' => $csrf], $jarTecnico);

        check($res['status'] === 302, 'Login técnico retornou ' . $res['status']);
        check(str_contains($res['headers']['location'] ?? '', '/alterar-senha'), 'Técnico deveria trocar senha');

        $csrf = extractCsrf(request('GET', '/alterar-senha', [], $jarTecnico)['body']);
        $res = request('POST', '/alterar-senha', [
            'current_password' => 'SenhaInicial@123',
            'new_password' => 'SenhaTecnico@1234',
            'new_password_confirmation' => 'SenhaTecnico@1234',
            '_csrf' => $csrf,
        ], $jarTecnico);
        check(str_contains($res['headers']['location'] ?? '', '/dashboard'), 'Técnico não chegou ao painel');

        $res = request('GET', '/usuarios', [], $jarTecnico);
        check($res['status'] === 403, 'Técnico em /usuarios retornou ' . $res['status']);

        $res = request('GET', '/dashboard', [], $jarTecnico);
        check($res['status'] === 200, 'Dashboard do técnico retornou ' . $res['status']);

        t('Técnico: 403 em usuários, acesso ao painel', true);
    } catch (Throwable $e) {
        t('Técnico: 403 em usuários, acesso ao painel', false, $e->getMessage());
    }
}

// 11. Rate limit bloqueia login após exceder tentativas
if ($tecnicoId > 0) {
    try {
        TestIsolation::purgeLoginAttempts();

        $jarLim = newSession();
        for ($i = 0; $i < 5; $i++) {
            $csrf = extractCsrf(request('GET', '/login', [], $jarLim)['body']);
            request('POST', '/login', ['email' => $tecnicoEmail, 'password' => 'SenhaErrada@123', '_csrf' => $csrf], $jarLim);
        }

        $user = (new UserRepository())->find($tecnicoId);
        check((int) $user['failed_login_attempts'] >= 5, 'Tentativas não contadas no banco');

        $locked = (new UserRepository())->isLocked($tecnicoId);
        check($locked, 'Técnico não bloqueado no banco');

        $csrf = extractCsrf(request('GET', '/login', [], $jarLim)['body']);
        $res = request('POST', '/login', ['email' => $tecnicoEmail, 'password' => 'SenhaTecnico@1234', '_csrf' => $csrf], $jarLim);
        check(!str_contains($res['headers']['location'] ?? '', '/dashboard'), 'Login correto sob bloqueio foi aceito');

        TestIsolation::purgeLoginAttempts();
        (new UserRepository())->unlockUser($tecnicoId);

        t('Rate limit bloqueia conta (lock no banco)', true);
    } catch (Throwable $e) {
        TestIsolation::purgeLoginAttempts();
        t('Rate limit bloqueia conta (lock no banco)', false, $e->getMessage());
    }

    cleanSession($jarTecnico);
}

// 12. Guarda do último admin ativo (não pode desativar nem rebaixar)
if ($jar !== '' && $setupError === '') {
    try {
        $csrf = extractCsrf(request('GET', '/usuarios', [], $jar)['body']);
        $res = request('POST', '/usuarios/' . $adminId . '/status', ['_csrf' => $csrf], $jar);
        check($res['status'] === 302, 'Desativação retornou ' . $res['status']);
        check((new UserRepository())->find($adminId)['status'] === 'active', 'Último admin foi desativado!');

        $csrf = extractCsrf(request('GET', '/usuarios/' . $adminId . '/editar', [], $jar)['body']);
        $res = request('POST', '/usuarios/' . $adminId . '/editar', [
            'name' => 'Administrador',
            'email' => $adminEmail,
            'role' => 'tecnico',
            'status' => 'active',
            '_csrf' => $csrf,
        ], $jar);
        check($res['status'] === 302, 'Rebaixamento retornou ' . $res['status']);
        check((new UserRepository())->find($adminId)['role'] === 'admin', 'Último admin foi rebaixado!');

        t('Última admin protegido contra desativação/rebaixamento', true);
    } catch (Throwable $e) {
        t('Última admin protegido contra desativação/rebaixamento', false, $e->getMessage());
    }
}

// ============================= FASE 3 (HTTP) =============================
$suffix = time();
$docUrl = 'https://unimestre.com/http-' . $suffix;
$docTitle = 'Documento HTTP ' . $suffix;
$docId = 0;
$usuarioHttpId = 0;
$usuarioHttpEmail = 'http-usuario-' . $suffix . '@assistente.test';

// 14. Admin acessa base de conhecimento e formulário de importação
try {
    $res = request('GET', '/knowledge', [], $jar);
    check($res['status'] === 200, 'GET /knowledge retornou ' . $res['status']);
    check(str_contains($res['body'], 'Base de Conhecimento'), 'Título da listagem ausente');
    check(str_contains($res['body'], 'Importar documento'), 'Botão de importação ausente');

    $res = request('GET', '/admin/knowledge/import', [], $jar);
    check($res['status'] === 200, 'GET import retornou ' . $res['status']);
    check(str_contains($res['body'], 'name="_csrf"'), 'CSRF ausente no formulário de import');

    t('Admin: listagem e formulário de importação', true);
} catch (Throwable $e) {
    t('Admin: listagem e formulário de importação', false, $e->getMessage());
}

// 15. Admin importa documento; segunda tentativa é bloqueada como duplicata
try {
    $csrf = extractCsrf(request('GET', '/admin/knowledge/import', [], $jar)['body']);
    $data = [
        'title' => $docTitle,
        'url' => $docUrl,
        'category' => 'Portal',
        'subcategory' => 'HTTP',
        'content' => 'Conteúdo de teste HTTP ' . $suffix . '.',
        'origin' => 'official',
        'status' => 'active',
        '_csrf' => $csrf,
    ];

    $res = request('POST', '/admin/knowledge/import', $data, $jar);
    check($res['status'] === 302, 'Import retornou ' . $res['status']);
    check(str_contains($res['headers']['location'] ?? '', '/knowledge/'), 'Não redirecionou para o documento');

    $row = Database::connection()->prepare('SELECT id FROM knowledge_documents WHERE url = ?');
    $row->execute([$docUrl]);
    $docId = (int) $row->fetchColumn();
    check($docId > 0, 'Documento não persistido no banco');

    $second = request('POST', '/admin/knowledge/import', $data, $jar);
    check($second['status'] === 302, 'Reenvio duplicado retornou ' . $second['status']);

    $count = Database::connection()->prepare('SELECT COUNT(*) FROM knowledge_documents WHERE url = ?');
    $count->execute([$docUrl]);
    check((int) $count->fetchColumn() === 1, 'Documento duplicado não foi bloqueado');

    t('Importação e bloqueio de duplicata', true);
} catch (Throwable $e) {
    t('Importação e bloqueio de duplicata', false, $e->getMessage());
}

// 16. Admin edita e desativa documento
if ($docId > 0) {
    try {
        $csrf = extractCsrf(request('GET', '/admin/knowledge/' . $docId . '/edit', [], $jar)['body']);
        $res = request('POST', '/admin/knowledge/' . $docId . '/edit', [
            'title' => $docTitle . ' (revisto)',
            'url' => $docUrl,
            'category' => 'Portal',
            'subcategory' => 'HTTP',
            'content' => 'Conteúdo de teste HTTP ' . $suffix . '.',
            'origin' => 'official',
            'status' => 'active',
            '_csrf' => $csrf,
        ], $jar);
        check($res['status'] === 302, 'Edição retornou ' . $res['status']);

        $title = Database::connection()->prepare('SELECT title FROM knowledge_documents WHERE id = ?');
        $title->execute([$docId]);
        check(str_contains((string) $title->fetchColumn(), 'revisto'), 'Título não atualizado');

        $csrf = extractCsrf(request('GET', '/knowledge', [], $jar)['body']);
        $res = request('POST', '/admin/knowledge/' . $docId . '/status', ['_csrf' => $csrf], $jar);
        check($res['status'] === 302, 'Desativação retornou ' . $res['status']);

        $status = Database::connection()->prepare('SELECT status FROM knowledge_documents WHERE id = ?');
        $status->execute([$docId]);
        check($status->fetchColumn() === 'inactive', 'Documento não desativado');

        t('Edição e desativação via HTTP', true);
    } catch (Throwable $e) {
        t('Edição e desativação via HTTP', false, $e->getMessage());
    }
}

// 17. Técnico visualiza base, gerencia soluções e é barrado na importação
if ($tecnicoId > 0) {
    try {
        $jarTecnico = newSession();
        $csrf = extractCsrf(request('GET', '/login', [], $jarTecnico)['body']);
        $res = request('POST', '/login', [
            'email' => $tecnicoEmail,
            'password' => 'SenhaTecnico@1234',
            '_csrf' => $csrf,
        ], $jarTecnico);
        check($res['status'] === 302, 'Relogin técnico retornou ' . $res['status']);
        check(str_contains($res['headers']['location'] ?? '', '/dashboard'), 'Técnico não voltou ao painel');

        $res = request('GET', '/knowledge', [], $jarTecnico);
        check($res['status'] === 200, 'Técnico em /knowledge retornou ' . $res['status']);

        $res = request('GET', '/admin/knowledge/import', [], $jarTecnico);
        check($res['status'] === 403, 'Técnico em import retornou ' . $res['status']);

        $res = request('GET', '/solutions', [], $jarTecnico);
        check($res['status'] === 200, 'Técnico em /solutions retornou ' . $res['status']);

        t('Técnico: visualiza base, 403 em importação', true);
    } catch (Throwable $e) {
        t('Técnico: visualiza base, 403 em importação', false, $e->getMessage());
    }
}

// 18. Usuário comum recebe 403 em /knowledge e /solutions
if ($setupError === '') {
    try {
        $csrf = extractCsrf(request('GET', '/usuarios/novo', [], $jar)['body']);
        $res = request('POST', '/usuarios', [
            'name' => 'Usuario HTTP',
            'email' => $usuarioHttpEmail,
            'role' => 'usuario',
            'status' => 'active',
            'password' => 'SenhaUsuario@123',
            'password_confirmation' => 'SenhaUsuario@123',
            '_csrf' => $csrf,
        ], $jar);
        check($res['status'] === 302, 'Criação de usuário retornou ' . $res['status']);

        $usuarioHttpId = (int) (new UserRepository())->findByEmail($usuarioHttpEmail)['id'];

        $jarUsu = newSession();
        $csrf = extractCsrf(request('GET', '/login', [], $jarUsu)['body']);
        $res = request('POST', '/login', [
            'email' => $usuarioHttpEmail,
            'password' => 'SenhaUsuario@123',
            '_csrf' => $csrf,
        ], $jarUsu);
        check(str_contains($res['headers']['location'] ?? '', '/alterar-senha'), 'Usuário não forçado a trocar senha');

        $csrf = extractCsrf(request('GET', '/alterar-senha', [], $jarUsu)['body']);
        $res = request('POST', '/alterar-senha', [
            'current_password' => 'SenhaUsuario@123',
            'new_password' => 'SenhaUsuario@1234',
            'new_password_confirmation' => 'SenhaUsuario@1234',
            '_csrf' => $csrf,
        ], $jarUsu);
        check(str_contains($res['headers']['location'] ?? '', '/dashboard'), 'Usuário não chegou ao painel');

        $res = request('GET', '/knowledge', [], $jarUsu);
        check($res['status'] === 403, 'Usuário em /knowledge retornou ' . $res['status']);

        $res = request('GET', '/solutions', [], $jarUsu);
        check($res['status'] === 403, 'Usuário em /solutions retornou ' . $res['status']);

        cleanSession($jarUsu);
        t('Usuario: 403 em knowledge/solutions', true);
    } catch (Throwable $e) {
        t('Usuario: 403 em knowledge/solutions', false, $e->getMessage());
    }
}

// 19. Técnico cadastra e desativa solução interna
if ($tecnicoId > 0 && isset($jarTecnico) && $jarTecnico !== '') {
    $solTitle = 'Solução HTTP ' . $suffix;
    $solId = 0;

    try {
        $csrf = extractCsrf(request('GET', '/solutions/novo', [], $jarTecnico)['body']);
        $res = request('POST', '/solutions', [
            'title' => $solTitle,
            'problem' => 'Problema HTTP ' . $suffix,
            'solution' => 'Solução HTTP ' . $suffix,
            'category' => 'Portal',
            'tags' => 'senha, redefinicao',
            'status' => 'active',
            '_csrf' => $csrf,
        ], $jarTecnico);
        check($res['status'] === 302, 'Criação de solução retornou ' . $res['status']);

        $row = Database::connection()->prepare('SELECT id, tags FROM internal_solutions WHERE title = ?');
        $row->execute([$solTitle]);
        $found = $row->fetch();
        $solId = (int) $found['id'];
        check($solId > 0, 'Solução não persistida');
        check($found['tags'] === 'redefinicao, senha', 'Tags não normalizadas no cadastro HTTP');

        $csrf = extractCsrf(request('GET', '/solutions', [], $jarTecnico)['body']);
        $res = request('POST', '/solutions/' . $solId . '/status', ['_csrf' => $csrf], $jarTecnico);
        check($res['status'] === 302, 'Desativação de solução retornou ' . $res['status']);

        $status = Database::connection()->prepare('SELECT status FROM internal_solutions WHERE id = ?');
        $status->execute([$solId]);
        check($status->fetchColumn() === 'inactive', 'Solução não desativada');

        if ($solId > 0) {
            Database::connection()->prepare('DELETE FROM internal_solutions WHERE id = ?')->execute([$solId]);
            Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action LIKE 'solution.%')");
        }

        t('Soluções internas: cadastro e status via HTTP', true);
    } catch (Throwable $e) {
        if ($solId > 0) {
            Database::connection()->prepare('DELETE FROM internal_solutions WHERE id = ?')->execute([$solId]);
        }
        t('Soluções internas: cadastro e status via HTTP', false, $e->getMessage());
    }
}

// 20. CSRF inválido bloqueia importação com 419
try {
    $jarCsrf = newSession();
    $csrf = extractCsrf(request('GET', '/login', [], $jarCsrf)['body']);
    request('POST', '/login', ['email' => $adminEmail, 'password' => $newPassword, '_csrf' => $csrf], $jarCsrf);

    $res = request('POST', '/admin/knowledge/import', [
        'title' => 'Sem CSRF',
        'url' => 'https://unimestre.com/csrf-' . $suffix,
        'category' => 'Outros',
        'content' => 'Conteúdo sem token.',
        'origin' => 'official',
        'status' => 'active',
        '_csrf' => 'token-invalido',
    ], $jarCsrf);

    check($res['status'] === 419, 'Import sem CSRF válido retornou ' . $res['status']);
    cleanSession($jarCsrf);

    t('Importação exige CSRF válido (419)', true);
} catch (Throwable $e) {
    t('Importação exige CSRF válido (419)', false, $e->getMessage());
}

// ============================= FASE 4 (HTTP) =============================
$f4Suffix = (string) time();
$f4Token = 'zirconio' . $f4Suffix;
$f4DocUrlBoleto = 'https://unimestre.com/http-f4-boleto-' . $f4Suffix;
$f4DocUrlMatricula = 'https://unimestre.com/http-f4-matricula-' . $f4Suffix;
$f4DocUrlGenerico = 'https://unimestre.com/http-f4-generico-' . $f4Suffix;
$f4DocBoletoTitle = 'F4 HTTP Boleto ' . $f4Suffix;
$f4DocMatriculaTitle = 'F4 HTTP Matrícula ' . $f4Suffix;
$f4DocGenericoTitle = 'F4 HTTP Genérico ' . $f4Suffix;
$f4SolTitle = 'F4 HTTP Solução ' . $f4Suffix;

// Resíduos de execuções anteriores (abortadas) usam URLs/títulos distintos por run,
// mas (source_id, content_hash) é UNIQUE — sem limpeza a reimportação falharia.
Database::connection()->exec("DELETE FROM knowledge_documents WHERE title LIKE 'F4 HTTP %'");
Database::connection()->exec('DELETE FROM internal_solutions WHERE title LIKE \'F4 HTTP %\'');

// 21. Admin acessa a ferramenta de teste de recuperação (RAG local)
try {
    $res = request('GET', '/admin/search-test', [], $jar);
    check($res['status'] === 200, 'GET /admin/search-test retornou ' . $res['status']);
    check(str_contains($res['body'], 'Testar recuperação'), 'Título da ferramenta ausente');
    check(str_contains($res['body'], 'name="query"'), 'Campo pergunta ausente');
    check(str_contains($res['body'], 'name="_csrf"'), 'CSRF ausente na ferramenta');

    t('Admin acessa ferramenta de teste de recuperação', true);
} catch (Throwable $e) {
    t('Admin acessa ferramenta de teste de recuperação', false, $e->getMessage());
}

// 22. Busca vazia é rejeitada com aviso na tela
try {
    $csrf = extractCsrf(request('GET', '/admin/search-test', [], $jar)['body']);
    $res = request('POST', '/admin/search-test', ['query' => '   ', '_csrf' => $csrf], $jar);
    check($res['status'] === 200, 'Busca vazia retornou ' . $res['status']);
    check(str_contains($res['body'], 'Informe a pergunta para pesquisar.'), 'Aviso de busca vazia ausente');

    t('Busca vazia rejeitada com aviso', true);
} catch (Throwable $e) {
    t('Busca vazia rejeitada com aviso', false, $e->getMessage());
}

// 23. Documento oficial com HTML é importado e a busca exibe fonte + conteúdo escapado
$f4BoletoDocId = 0;
try {
    $csrf = extractCsrf(request('GET', '/admin/knowledge/import', [], $jar)['body']);
    $res = request('POST', '/admin/knowledge/import', [
        'title' => $f4DocBoletoTitle,
        'url' => $f4DocUrlBoleto,
        'category' => 'Financeiro',
        'subcategory' => 'HTTP',
        'content' => '<b>independente</b> processo de emissão de boleto de cobrança do aluno. Procedimento padrão para o setor financeiro.',
        'origin' => 'official',
        'status' => 'active',
        '_csrf' => $csrf,
    ], $jar);
    check($res['status'] === 302, 'Import boleto retornou ' . $res['status']);

    $row = Database::connection()->prepare('SELECT id FROM knowledge_documents WHERE url = ?');
    $row->execute([$f4DocUrlBoleto]);
    $f4BoletoDocId = (int) $row->fetchColumn();
    check($f4BoletoDocId > 0, 'Documento boleto não persistido');

    $csrf = extractCsrf(request('GET', '/admin/search-test', [], $jar)['body']);
    $res = request('POST', '/admin/search-test', ['query' => 'emissão de boleto', '_csrf' => $csrf], $jar);
    check($res['status'] === 200, 'Busca boleto retornou ' . $res['status']);
    check(str_contains($res['body'], 'OFICIAL UNIMESTRE'), 'Badge de fonte oficial ausente');
    check(str_contains($res['body'], 'Trecho encontrado'), 'Trecho encontrado ausente');
    check(str_contains($res['body'], '&lt;b&gt;independente&lt;/b&gt;'), 'HTML não escapado no trecho');
    check(!str_contains($res['body'], '<b>independente</b>'), 'HTML cru exibido no trecho');

    t('Busca exibe resultado oficial com fonte e conteúdo escapado', true);
} catch (Throwable $e) {
    t('Busca exibe resultado oficial com fonte e conteúdo escapado', false, $e->getMessage());
}

// 24. Busca sem resultados exibe a mensagem padrão
try {
    $csrf = extractCsrf(request('GET', '/admin/search-test', [], $jar)['body']);
    $res = request('POST', '/admin/search-test', ['query' => 'xzqwxzfgh', '_csrf' => $csrf], $jar);
    check($res['status'] === 200, 'Busca sem resultados retornou ' . $res['status']);
    check(str_contains($res['body'], 'Nenhum conteúdo suficientemente relevante encontrado.'), 'Mensagem vazia ausente');

    t('Busca sem resultados mostra mensagem padrão', true);
} catch (Throwable $e) {
    t('Busca sem resultados mostra mensagem padrão', false, $e->getMessage());
}

// 25. Técnico não acessa a ferramenta de recuperação (403)
if ($tecnicoId > 0) {
    try {
        $jarTecF4 = newSession();
        $csrf = extractCsrf(request('GET', '/login', [], $jarTecF4)['body']);
        $res = request('POST', '/login', [
            'email' => $tecnicoEmail,
            'password' => 'SenhaTecnico@1234',
            '_csrf' => $csrf,
        ], $jarTecF4);
        check(str_contains($res['headers']['location'] ?? '', '/dashboard'), 'Login técnico F4 falhou');

        $res = request('GET', '/admin/search-test', [], $jarTecF4);
        check($res['status'] === 403, 'Técnico em search-test retornou ' . $res['status']);
        cleanSession($jarTecF4);

        t('Técnico: 403 na ferramenta de recuperação', true);
    } catch (Throwable $e) {
        t('Técnico: 403 na ferramenta de recuperação', false, $e->getMessage());
    }
}

// 26. Usuário comum não acessa a ferramenta de recuperação (403)
if ($usuarioHttpId > 0) {
    try {
        $jarUsuF4 = newSession();
        $csrf = extractCsrf(request('GET', '/login', [], $jarUsuF4)['body']);
        $res = request('POST', '/login', [
            'email' => $usuarioHttpEmail,
            'password' => 'SenhaUsuario@1234',
            '_csrf' => $csrf,
        ], $jarUsuF4);
        check(str_contains($res['headers']['location'] ?? '', '/dashboard'), 'Login usuário F4 falhou');

        $res = request('GET', '/admin/search-test', [], $jarUsuF4);
        check($res['status'] === 403, 'Usuário em search-test retornou ' . $res['status']);
        cleanSession($jarUsuF4);

        t('Usuário: 403 na ferramenta de recuperação', true);
    } catch (Throwable $e) {
        t('Usuário: 403 na ferramenta de recuperação', false, $e->getMessage());
    }
}

// 27. Busca com CSRF inválido é bloqueada (419)
try {
    $res = request('POST', '/admin/search-test', [
        'query' => 'boleto',
        '_csrf' => 'token-invalido',
    ], $jar);
    check($res['status'] === 419, 'Busca com CSRF inválido retornou ' . $res['status']);

    t('Recuperação exige CSRF válido (419)', true);
} catch (Throwable $e) {
    t('Recuperação exige CSRF válido (419)', false, $e->getMessage());
}

// 28. Tela ordena resultados por relevância (título + recorrência do termo)
try {
    $csrf = extractCsrf(request('GET', '/admin/knowledge/import', [], $jar)['body']);
    $res = request('POST', '/admin/knowledge/import', [
        'title' => $f4DocMatriculaTitle,
        'url' => $f4DocUrlMatricula,
        'category' => 'Acadêmico',
        'subcategory' => 'HTTP',
        'content' => $f4Token . ' procedimento de alteração de situação de matrícula do aluno, com emissão de documentos e rotina completa de atendimento à matrícula.',
        'origin' => 'official',
        'status' => 'active',
        '_csrf' => $csrf,
    ], $jar);
    check($res['status'] === 302, 'Import matrícula retornou ' . $res['status']);

    $res = request('POST', '/admin/knowledge/import', [
        'title' => $f4DocGenericoTitle,
        'url' => $f4DocUrlGenerico,
        'category' => 'Outros',
        'subcategory' => 'HTTP',
        'content' => $f4Token . ' rotina auxiliar genérica, sem relação direta, matrícula mencionada apenas de passagem no texto.',
        'origin' => 'official',
        'status' => 'active',
        '_csrf' => $csrf,
    ], $jar);
    check($res['status'] === 302, 'Import genérico retornou ' . $res['status']);

    $csrf = extractCsrf(request('GET', '/admin/search-test', [], $jar)['body']);
    $res = request('POST', '/admin/search-test', ['query' => $f4Token . ' matricula', '_csrf' => $csrf], $jar);
    check($res['status'] === 200, 'Busca ranking retornou ' . $res['status']);

    $posA = strpos($res['body'], $f4DocMatriculaTitle);
    $posB = strpos($res['body'], $f4DocGenericoTitle);
    check($posA !== false && $posB !== false, 'Resultados não exibidos (A=' . var_export($posA, true) . ', B=' . var_export($posB, true) . ')');
    check($posA < $posB, 'Ordem de relevância não respeitada na tela');

    t('Tela ordena resultados por relevância', true);
} catch (Throwable $e) {
    t('Tela ordena resultados por relevância', false, $e->getMessage());
}

// 29. Solução interna aparece como PROCEDIMENTO INTERNO na busca
try {
    Database::connection()->prepare(
        'INSERT INTO internal_solutions (title, problem, solution, category, tags, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $f4SolTitle,
        'Problema HTTP ' . $f4Suffix . ' boleto cobranca',
        'Solução HTTP ' . $f4Suffix . ' boleto cobranca',
        'Financeiro',
        'boleto, cobranca',
        $adminId,
        'active',
    ]);

    $csrf = extractCsrf(request('GET', '/admin/search-test', [], $jar)['body']);
    $res = request('POST', '/admin/search-test', ['query' => 'boleto cobranca', '_csrf' => $csrf], $jar);
    check($res['status'] === 200, 'Busca solução retornou ' . $res['status']);
    check(str_contains($res['body'], 'PROCEDIMENTO INTERNO'), 'Badge de solução interna ausente');
    check(str_contains($res['body'], $f4SolTitle), 'Solução interna não retornada');

    t('Solução interna exibida com fonte PROCEDIMENTO INTERNO', true);
} catch (Throwable $e) {
    t('Solução interna exibida com fonte PROCEDIMENTO INTERNO', false, $e->getMessage());
}

// ============================= FASE 5 (HTTP) =============================
$f5HttpOriginal = Database::connection()
    ->query("SELECT * FROM ai_providers WHERE provider = 'openrouter' LIMIT 1")
    ->fetch();

$f5HttpKey = 'sk-http-f5-' . bin2hex(random_bytes(8));
$f5ProviderId = (int) $f5HttpOriginal['id'];

$GLOBALS['httpF5OriginalProvider'] = $f5HttpOriginal;

Database::connection()->prepare(
    'UPDATE ai_providers SET status = ?, is_default = ?, api_key_encrypted = ? WHERE id = ?'
)->execute(['inactive', 0, null, $f5ProviderId]);

// 30. Admin acessa a tela de integração com IA (sem expor a chave)
try {
    $res = request('GET', '/admin/ai', [], $jar);
    check($res['status'] === 200, 'GET /admin/ai retornou ' . $res['status']);
    check(str_contains($res['body'], 'Integração com IA'), 'Título ausente');
    check(str_contains($res['body'], 'name="_csrf"'), 'CSRF ausente na tela de IA');
    check(str_contains($res['body'], 'Playground'), 'Link do playground ausente');

    $res = request('GET', '/admin/ai/playground', [], $jar);
    check($res['status'] === 200, 'GET /admin/ai/playground retornou ' . $res['status']);
    check(str_contains($res['body'], 'Testar IA — Playground'), 'Título do playground ausente');
    check(str_contains($res['body'], 'name="_csrf"'), 'CSRF ausente no playground');

    t('Admin acessa IA (configuração + playground)', true);
} catch (Throwable $e) {
    t('Admin acessa IA (configuração + playground)', false, $e->getMessage());
}

// 30b. HTML renderizado aponta para as rotas reais (regressão bug /admin/admin/)
try {
    $res = request('GET', '/admin/ai', [], $jar);
    check($res['status'] === 200, 'GET /admin/ai retornou ' . $res['status']);

    check(!str_contains($res['body'], '/admin/admin/'), 'HTML contém "/admin/admin/" (URL duplicada)!');

    $acaoSalvar = app_url('admin/ai/' . $f5ProviderId . '/salvar');
    $acaoTestar = app_url('admin/ai/' . $f5ProviderId . '/test');
    $acaoStatus = app_url('admin/ai/' . $f5ProviderId . '/status');
    $acaoPlayground = app_url('admin/ai/playground');

    check(str_contains($res['body'], 'action="' . $acaoSalvar . '"'), 'Action de salvar não aponta para a rota real');
    check(str_contains($res['body'], 'action="' . $acaoTestar . '"'), 'Action de testar não aponta para a rota real');
    check(str_contains($res['body'], 'action="' . $acaoStatus . '"'), 'Action de status não aponta para a rota real');

    $csrf = extractCsrf($res['body']);
    check(preg_match('~action="([^"]*/salvar)"~', $res['body'], $m) === 1, 'Action de salvar inexistente no HTML');

    $salvarUrl = $m[1];
    $post = request('POST', $salvarUrl, [
        'name' => 'OpenRouter',
        'model' => 'openrouter/free',
        'temperature' => '0.2',
        'max_tokens' => '1500',
        'timeout_seconds' => '30',
        '_csrf' => $csrf,
    ], $jar);
    check($post['status'] === 302, 'POST na action renderizada retornou ' . $post['status'] . ' (esperado 302, não 404)');
    check(!str_contains($post['headers']['location'] ?? '', '/admin/admin/'), 'Redirect de salvar duplicado /admin/admin/');

    $res = request('GET', '/admin/ai', [], $jar);
    check(str_contains($res['body'], 'href="' . $acaoPlayground . '"'), 'Playground não linkado com a rota real');

    t('HTML renderizado aponta para rotas reais (bug /admin/admin/)', true);
} catch (Throwable $e) {
    t('HTML renderizado aponta para rotas reais (bug /admin/admin/)', false, $e->getMessage());
}

// 31. Playground sem provider ativo com chave mostra aviso (sem internet)
try {
    $csrf = extractCsrf(request('GET', '/admin/ai/playground', [], $jar)['body']);
    $res = request('POST', '/admin/ai/playground', ['question' => 'Como acessar o portal?', '_csrf' => $csrf], $jar);
    check($res['status'] === 200, 'Playground respondeu ' . $res['status']);
    check(str_contains($res['body'], 'Configure a API Key do OpenRouter antes de utilizar a IA.'), 'Aviso de chave ausente');

    t('Playground exige chave antes de chamar a IA', true);
} catch (Throwable $e) {
    t('Playground exige chave antes de chamar a IA', false, $e->getMessage());
}

// 32. Pergunta vazia no playground é rejeitada
try {
    $csrf = extractCsrf(request('GET', '/admin/ai/playground', [], $jar)['body']);
    $res = request('POST', '/admin/ai/playground', ['question' => '   ', '_csrf' => $csrf], $jar);
    check($res['status'] === 200, 'Playground vazio respondeu ' . $res['status']);
    check(str_contains($res['body'], 'Informe a pergunta antes de enviar.'), 'Aviso de pergunta vazia ausente');

    t('Playground rejeita pergunta vazia', true);
} catch (Throwable $e) {
    t('Playground rejeita pergunta vazia', false, $e->getMessage());
}

// 33. Ativar provider sem API Key é bloqueado
try {
    $csrf = extractCsrf(request('GET', '/admin/ai', [], $jar)['body']);
    $res = request('POST', '/admin/ai/' . $f5ProviderId . '/salvar', [
        'name' => 'OpenRouter',
        'model' => 'openrouter/free',
        'temperature' => '0.2',
        'max_tokens' => '1500',
        'timeout_seconds' => '30',
        'status' => 'active',
        '_csrf' => $csrf,
    ], $jar);
    check($res['status'] === 302, 'Salvar sem chave retornou ' . $res['status']);

    $res = request('GET', '/admin/ai', [], $jar);
    check(str_contains($res['body'], 'Configure a API Key do OpenRouter antes de ativar o provider.'), 'Mensagem de bloqueio ausente');

    $status = Database::connection()->prepare('SELECT status FROM ai_providers WHERE id = ?');
    $status->execute([$f5ProviderId]);
    check($status->fetchColumn() === 'inactive', 'Provider ativado sem chave');

    t('Ativação sem API Key bloqueada', true);
} catch (Throwable $e) {
    t('Ativação sem API Key bloqueada', false, $e->getMessage());
}

// 34. Salvar API Key criptografa e nunca devolve o valor ao navegador
try {
    $csrf = extractCsrf(request('GET', '/admin/ai', [], $jar)['body']);
    $res = request('POST', '/admin/ai/' . $f5ProviderId . '/salvar', [
        'name' => 'OpenRouter',
        'model' => 'openrouter/free',
        'temperature' => '0.2',
        'max_tokens' => '1500',
        'timeout_seconds' => '30',
        'status' => 'inactive',
        'api_key' => $f5HttpKey,
        '_csrf' => $csrf,
    ], $jar);
    check($res['status'] === 302, 'Salvar com chave retornou ' . $res['status']);

    $statement = Database::connection()->prepare('SELECT api_key_encrypted FROM ai_providers WHERE id = ?');
    $statement->execute([$f5ProviderId]);
    $encrypted = (string) $statement->fetchColumn();
    check(str_starts_with($encrypted, 'v1:'), 'Chave não criptografada no formato v1:');

    $res = request('GET', '/admin/ai', [], $jar);
    check($res['status'] === 200, 'Index após salvar retornou ' . $res['status']);
    check(str_contains($res['body'], 'Chave configurada'), 'Badge de chave configurada ausente');
    check(!str_contains($res['body'], $f5HttpKey), 'Chave em texto plano na tela!');
    check(!str_contains($res['body'], 'api_key_encrypted'), 'Coluna criptografada vazou para a tela!');
    check(str_contains($res['body'], 'openrouter/free'), 'Modelo não preservado no formulário');

    t('API Key criptografada e nunca exposta ao navegador', true);
} catch (Throwable $e) {
    t('API Key criptografada e nunca exposta ao navegador', false, $e->getMessage());
}

// 35. Teste de conexão com provider inativo não chama a rede
try {
    $csrf = extractCsrf(request('GET', '/admin/ai', [], $jar)['body']);
    $res = request('POST', '/admin/ai/' . $f5ProviderId . '/test', ['_csrf' => $csrf], $jar);
    check($res['status'] === 302, 'Teste retornou ' . $res['status']);

    $res = request('GET', '/admin/ai', [], $jar);
    check(str_contains($res['body'], 'Ative o provider antes de testar a conexão.'), 'Aviso de provider inativo ausente');

    t('Teste de conexão bloqueado com provider inativo', true);
} catch (Throwable $e) {
    t('Teste de conexão bloqueado com provider inativo', false, $e->getMessage());
}

// 36. Alternância de status (ativar/desativar) via HTTP
try {
    $csrf = extractCsrf(request('GET', '/admin/ai', [], $jar)['body']);
    $res = request('POST', '/admin/ai/' . $f5ProviderId . '/status', ['_csrf' => $csrf], $jar);
    check($res['status'] === 302, 'Ativação retornou ' . $res['status']);

    $status = Database::connection()->prepare('SELECT status FROM ai_providers WHERE id = ?');
    $status->execute([$f5ProviderId]);
    check($status->fetchColumn() === 'active', 'Provider não ativado');

    $res = request('GET', '/admin/ai/playground', [], $jar);
    check($res['status'] === 200, 'Playground ativo retornou ' . $res['status']);
    check(str_contains($res['body'], 'name="question"'), 'Campo pergunta ausente com provider ativo');
    check(str_contains($res['body'], 'Enviar ao modelo'), 'Botão enviar ausente com provider ativo');

    $csrf = extractCsrf(request('GET', '/admin/ai', [], $jar)['body']);
    $res = request('POST', '/admin/ai/' . $f5ProviderId . '/status', ['_csrf' => $csrf], $jar);
    check($res['status'] === 302, 'Desativação retornou ' . $res['status']);

    $status->execute([$f5ProviderId]);
    check($status->fetchColumn() === 'inactive', 'Provider não desativado');

    t('Alternância de status do provider via HTTP', true);
} catch (Throwable $e) {
    t('Alternância de status do provider via HTTP', false, $e->getMessage());
}

// 37. Auditoria registra alterações de IA
try {
    $audit = Database::connection()->query(
        "SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'ai.%' AND entity_id = " . $f5ProviderId
    )->fetchColumn();

    check((int) $audit >= 1, 'Nenhuma auditoria de IA registrada');

    t('Auditoria registra alterações de IA', true);
} catch (Throwable $e) {
    t('Auditoria registra alterações de IA', false, $e->getMessage());
}

// 38. Técnico e usuário não acessam as telas de IA (403)
try {
    $jarTecF5 = newSession();
    $csrf = extractCsrf(request('GET', '/login', [], $jarTecF5)['body']);
    $res = request('POST', '/login', [
        'email' => $tecnicoEmail,
        'password' => 'SenhaTecnico@1234',
        '_csrf' => $csrf,
    ], $jarTecF5);
    check(str_contains($res['headers']['location'] ?? '', '/dashboard'), 'Login técnico F5 falhou');

    $res = request('GET', '/admin/ai', [], $jarTecF5);
    check($res['status'] === 403, 'Técnico em /admin/ai retornou ' . $res['status']);

    $res = request('GET', '/admin/ai/playground', [], $jarTecF5);
    check($res['status'] === 403, 'Técnico em playground retornou ' . $res['status']);
    cleanSession($jarTecF5);

    $jarUsuF5 = newSession();
    $csrf = extractCsrf(request('GET', '/login', [], $jarUsuF5)['body']);
    request('POST', '/login', [
        'email' => $usuarioHttpEmail,
        'password' => 'SenhaUsuario@1234',
        '_csrf' => $csrf,
    ], $jarUsuF5);

    $res = request('GET', '/admin/ai', [], $jarUsuF5);
    check($res['status'] === 403, 'Usuário em /admin/ai retornou ' . $res['status']);

    $res = request('GET', '/admin/ai/playground', [], $jarUsuF5);
    check($res['status'] === 403, 'Usuário em playground retornou ' . $res['status']);
    cleanSession($jarUsuF5);

    t('Técnico e usuário: 403 nas telas de IA', true);
} catch (Throwable $e) {
    t('Técnico e usuário: 403 nas telas de IA', false, $e->getMessage());
}

// 39. CSRF inválido bloqueia salvar e playground (419)
try {
    $res = request('POST', '/admin/ai/' . $f5ProviderId . '/salvar', [
        'name' => 'OpenRouter',
        'model' => 'openrouter/free',
        'temperature' => '0.2',
        'max_tokens' => '1500',
        'timeout_seconds' => '30',
        '_csrf' => 'token-invalido',
    ], $jar);
    check($res['status'] === 419, 'Salvar sem CSRF retornou ' . $res['status']);

    $res = request('POST', '/admin/ai/playground', [
        'question' => 'oie',
        '_csrf' => 'token-invalido',
    ], $jar);
    check($res['status'] === 419, 'Playground sem CSRF retornou ' . $res['status']);

    t('Telas de IA exigem CSRF válido (419)', true);
} catch (Throwable $e) {
    t('Telas de IA exigem CSRF válido (419)', false, $e->getMessage());
}

// ============================= FASE 2 (13) =============================

// 13. Logout encerra sessão
if ($jar !== '') {
    try {
        $csrf = extractCsrf(request('GET', '/dashboard', [], $jar)['body']);
        $res = request('POST', '/logout', ['_csrf' => $csrf], $jar);
        check($res['status'] === 302, 'Logout retornou ' . $res['status']);
        check(str_contains($res['headers']['location'] ?? '', '/login'), 'Logout não foi para /login');

        $res = request('GET', '/dashboard', [], $jar);
        check($res['status'] === 302, 'Dashboard logado retornou ' . $res['status']);

        t('Logout encerra sessão', true);
    } catch (Throwable $e) {
        t('Logout encerra sessão', false, $e->getMessage());
    }
}

// ============================= FASE 6 (HTTP) =============================

// Estados de ambiente isolados para a Fase 6:
//  - O provider real (id 1) fica DESATIVADO durante os testes HTTP de chat:
//    garantia de determinismo OFFLINE (sem créditos e sem internet). O caminho
//    "contexto → IA chamada" com transporte mockado é coberto pela suíte CLI
//    (tests/phase6.php). Aqui validamos o fluxo real sobre a rede: rotas,
//    CSRF, validação, rate limit, ownership, fontes reais e XSS.
//  - Os buckets de rate limit/guards são limpos para não herdar estado de
//    execuções anteriores.

$f6AdminJar = newSession();
$f6Csrf = extractCsrf(request('GET', '/login', [], $f6AdminJar)['body']);
$f6Res = request('POST', '/login', ['email' => $adminEmail, 'password' => $newPassword, '_csrf' => $f6Csrf], $f6AdminJar);
check($f6Res['status'] === 302, 'Login admin F6 falhou');

// Após login, extrai CSRF de página com formulário (o token persiste na sessão)
$f6ChatCsrf = extractCsrf(request('GET', '/alterar-senha', [], $f6AdminJar)['body']);

Database::connection()->exec('DELETE FROM chat_limits WHERE id > @t_chat_limits_start');
Database::connection()->exec('DELETE FROM chat_request_guards WHERE id > @t_chat_request_guards_start');

$f6Repo = new UserRepository();
$f6AdminConvIds = [];
$f6AdminUserMsgId = 0;

// Isola o provider real durante a Fase 6 (desativado = sem chamada externa).
$f6ProviderOriginal = Database::connection()
    ->query('SELECT id, status, is_default FROM ai_providers WHERE id = 1')
    ->fetch(PDO::FETCH_ASSOC);

if ($f6ProviderOriginal !== false && $f6ProviderOriginal['status'] === 'active') {
    Database::connection()->prepare(
        "UPDATE ai_providers SET status = 'inactive', is_default = 0, updated_at = CURRENT_TIMESTAMP WHERE id = 1"
    )->execute();
}

// Documento oficial dedicado para o cenário "com contexto" (fonte real do banco).
$f6DocId = 0;

try {
    $f6DocTitle = 'F6 HTTP Boleto ' . time();
    $f6DocUrl = 'https://unimestre.com/http-f6-boleto-' . time();
    $f6DocContent = 'Boleto de cobranca do aluno: a emissao do boleto e realizada no modulo financeiro do UNIMESTRE. '
        . 'Para gerar o boleto de cobranca, o profissional acessa a tela de cobrancas, localiza o aluno, '
        . 'seleciona o contrato e aciona a geracao do documento de cobranca. O boleto segue as regras de '
        . 'vencimento configuradas pela unidade. Processo padrao para o setor financeiro.';
    $f6Source = (new KnowledgeSourceRepository())->findByType('official');
    Database::connection()->exec("DELETE FROM knowledge_documents WHERE title LIKE 'F6 HTTP Boleto %'"); // idempotência entre execuções
    $f6DocId = (int) (new KnowledgeDocumentRepository())->create([
        'source_id' => $f6Source !== null ? (int) $f6Source['id'] : 1,
        'title' => $f6DocTitle,
        'slug' => 'f6-http-' . md5($f6DocTitle . microtime()),
        'url' => $f6DocUrl,
        'category' => 'Financeiro',
        'subcategory' => 'Cobrancas',
        'content' => $f6DocContent,
        'content_hash' => hash('sha256', $f6DocContent),
        'origin' => 'Documentacao oficial UNIMESTRE',
        'status' => 'active',
    ]);
    (new KnowledgeChunkRepository())->replaceForDocument($f6DocId, (new ChunkingService())->chunkText($f6DocContent));
    check($f6DocId > 0, 'Documento F6 não indexado');
} catch (Throwable $e) {
    check(false, 'Falha ao indexar documento F6: ' . $e->getMessage());
}

$f6AbortMessage = null;
$rlJar = '';
$ownerJar = '';
$noPermJar = '';

try {
// F6-01: GET /chat sem auth redireciona para login
$jarNoAuth = newSession();
$res = request('GET', '/chat', [], $jarNoAuth);
check($res['status'] === 302, 'GET /chat sem auth retornou ' . $res['status']);
check(str_contains($res['headers']['location'] ?? '', '/login'), 'Não redirecionou para login');
cleanSession($jarNoAuth);
t('F6: GET /chat sem auth → 302 /login', true);

// F6-02: GET /history sem auth redireciona para login
$jarNoAuth = newSession();
$res = request('GET', '/history', [], $jarNoAuth);
check($res['status'] === 302, 'GET /history sem auth retornou ' . $res['status']);
check(str_contains($res['headers']['location'] ?? '', '/login'), 'Não redirecionou para login');
cleanSession($jarNoAuth);
t('F6: GET /history sem auth → 302 /login', true);

// F6-03: POST /api/chat sem auth → 419 (CSRF falha antes do auth check global)
$jarNoAuth = newSession();
$res = request('POST', '/api/chat', ['question' => 'teste'], $jarNoAuth, true);
check($res['status'] === 419, 'POST /api/chat sem auth retornou ' . $res['status']);
cleanSession($jarNoAuth);
t('F6: POST /api/chat sem auth → 419 (CSRF global)', true);

// F6-04: POST /api/chat autenticado, pergunta vazia → 422
$res = request('POST', '/api/chat', ['question' => '   '], $f6AdminJar, true, $f6ChatCsrf);
check($res['status'] === 422, 'Pergunta vazia retornou ' . $res['status']);
$body = json_decode($res['body'], true);
check($body['error'] === 'question_required', 'Código de erro incorreto');
t('F6: Pergunta vazia → 422', true);

// F6-05: POST /api/chat autenticado, pergunta muito longa → 422
$longQuestion = str_repeat('a', 5000);
$res = request('POST', '/api/chat', ['question' => $longQuestion], $f6AdminJar, true, $f6ChatCsrf);
check($res['status'] === 422, 'Pergunta longa retornou ' . $res['status']);
$body = json_decode($res['body'], true);
check($body['error'] === 'question_too_long', 'Código de erro incorreto');
t('F6: Pergunta longa → 422', true);

// F6-06: POST /api/chat autenticado, sem CSRF → 419
$res = request('POST', '/api/chat', ['question' => 'teste'], $f6AdminJar, true);
check($res['status'] === 419, 'Sem CSRF retornou ' . $res['status']);
t('F6: Sem CSRF → 419', true);

// F6-07: POST /api/chat autenticado, sem contexto (pergunta sem match) → 200 ai_called=false
$res = request('POST', '/api/chat', ['question' => 'Como configurar impressora fiscal Zebra modelo 9999?'], $f6AdminJar, true, $f6ChatCsrf);
check($res['status'] === 200, 'Sem contexto retornou ' . $res['status']);
$body = json_decode($res['body'], true);
check($body['ok'] === true, 'Resposta não é sucesso');
check($body['ai_called'] === false, 'IA deveria não ter sido chamada');
check($body['gateway_state'] === 'no_context', 'Estado gateway incorreto');
check(str_contains($body['answer'], 'Não encontrei informações suficientes'), 'Mensagem de no_context ausente');
$f6AdminConvIds[] = (int) $body['conversation_id'];
$GLOBALS['httpAdminConvIds'][] = (int) $body['conversation_id'];
t('F6: Sem contexto → 200 + ai_called=false + mensagem local', true);

// F6-08: POST /api/chat autenticado, com contexto → 200 com fontes reais.
// O provider está desativado nesta seção (offline, sem créditos): o RAG
// encontra o documento F6, lista as fontes reais do banco e NÃO consulta a IA.
// (O caminho "contexto → IA chamada → resposta" é coberto no phase6.php com
// transporte mockado.)
$res = request('POST', '/api/chat', ['question' => 'Como emitir boleto de cobranca do aluno?'], $f6AdminJar, true, $f6ChatCsrf);
check($res['status'] === 200, 'Com contexto retornou ' . $res['status']);
$body = json_decode($res['body'], true);
check($body['ok'] === true, 'Resposta não é sucesso');
check($body['gateway_state'] === 'provider_unavailable', 'Estado gateway não foi provider_unavailable (era ' . ($body['gateway_state'] ?? '?') . ')');
check($body['error_code'] === 'provider_unavailable', 'error_code diagnóstico não refletiu provider_unavailable (era ' . ($body['error_code'] ?? '?') . ')');
check($body['ai_called'] === false, 'IA não deveria ser consultada com provider desativado');
check(is_array($body['sources']) && count($body['sources']) > 0, 'Fontes ausentes mesmo com contexto');
check(in_array($f6DocId, array_map('intval', array_filter(array_column($body['sources'], 'document_id'))), true), 'Fonte real F6 ausente na resposta');
check(isset($body['conversation_id']) && $body['conversation_id'] > 0, 'conversation_id ausente');
check((int) $body['user_message_id'] > 0, 'Pergunta não persistida');
check($body['assistant_message_id'] === null, 'Mensagem assistente não deveria existir sem sucesso da IA');
$f6AdminConvIds[] = (int) $body['conversation_id'];
$GLOBALS['httpAdminConvIds'][] = (int) $body['conversation_id'];
$f6AdminUserMsgId = (int) $body['user_message_id'];
t('F6: Com contexto → 200 + fontes reais + pergunta persistida (IA offline)', true);

// F6-09: Rate limit - 11ª requisição → 429 (usuário dedicado, bucket limpo)
$f6RlEmail = 'f6-rl-' . time() . '@assistente.test';
$f6Repo->create([
    'name' => 'F6 Rate Limiter',
    'email' => $f6RlEmail,
    'password_hash' => password_hash('F6Senha@123', PASSWORD_DEFAULT),
    'role' => 'tecnico',
    'status' => 'active',
    'must_change_password' => 0,
]);
$rlJar = newSession();
$csrf = extractCsrf(request('GET', '/login', [], $rlJar)['body']);
$res = request('POST', '/login', ['email' => $f6RlEmail, 'password' => 'F6Senha@123', '_csrf' => $csrf], $rlJar);
check($res['status'] === 302, 'Login usuário rate limit falhou');
$rlChatCsrf = extractCsrf(request('GET', '/alterar-senha', [], $rlJar)['body']);
for ($i = 0; $i < 10; $i++) {
    $res = request('POST', '/api/chat', ['question' => 'Pergunta rate limit ' . $i], $rlJar, true, $rlChatCsrf);
    check($res['status'] === 200, "Requisição {$i} falhou com " . $res['status']);
}
$res = request('POST', '/api/chat', ['question' => 'Pergunta 11 - rate limit'], $rlJar, true, $rlChatCsrf);
check($res['status'] === 429, '11ª requisição retornou ' . $res['status']);
$body = json_decode($res['body'], true);
check($body['error'] === 'rate_limited', 'Código de erro rate limit incorreto');
cleanSession($rlJar);
t('F6: Rate limit 11ª requisição → 429', true);

// F6-10: Ownership - GET /chat/{id} de outro usuário → 404
// Usuário "dono" persiste até o cleanup (reusado em F6-11 e F6-12).
$f6OwnerEmail = 'f6-own-' . time() . '@assistente.test';
$f6OwnerId = $f6Repo->create([
    'name' => 'F6 Owner',
    'email' => $f6OwnerEmail,
    'password_hash' => password_hash('F6Senha@123', PASSWORD_DEFAULT),
    'role' => 'tecnico',
    'status' => 'active',
    'must_change_password' => 0,
]);

// Owner cria conversa
$ownerJar = newSession();
$csrf = extractCsrf(request('GET', '/login', [], $ownerJar)['body']);
$res = request('POST', '/login', ['email' => $f6OwnerEmail, 'password' => 'F6Senha@123', '_csrf' => $csrf], $ownerJar);
check($res['status'] === 302, 'Login owner falhou');
$ownerChatCsrf = extractCsrf(request('GET', '/alterar-senha', [], $ownerJar)['body']);
$res = request('POST', '/api/chat', ['question' => 'Conversa do dono'], $ownerJar, true, $ownerChatCsrf);
check($res['status'] === 200, 'Criação da conversa do dono retornou ' . $res['status']);
$ownerBody = json_decode($res['body'], true);
$ownerConvId = (int) $ownerBody['conversation_id'];
check($ownerConvId > 0, 'Conversa do dono não criada');

// Intruso (admin) tenta acessar
$res = request('GET', '/chat/' . $ownerConvId, [], $f6AdminJar);
check($res['status'] === 404, 'GET /chat/{id} de outro usuário retornou ' . $res['status']);
t('F6: Ownership GET /chat/{id} → 404', true);

// F6-11: Feedback ownership - POST /api/chat/{id}/feedback de outro usuário → 404
// Provider offline não gera mensagem assistente: insere uma no banco para o teste.
$stmt = Database::connection()->prepare(
    'INSERT INTO messages (conversation_id, role, content, provider, model, request_id, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([$ownerConvId, 'assistant', 'Resposta mock para feedback.', 'mock', 'mock/model', 'mock-req-feedback', 'ok']);
$assistantMsgId = (int) Database::connection()->lastInsertId();
check($assistantMsgId > 0, 'Mensagem assistente de teste não criada');

$f6ChatCsrf = extractCsrf(request('GET', '/alterar-senha', [], $f6AdminJar)['body']);
$res = request('POST', '/api/chat/' . $assistantMsgId . '/feedback', ['rating' => 'util'], $f6AdminJar, true, $f6ChatCsrf);
check($res['status'] === 404, 'Feedback de outro usuário retornou ' . $res['status']);
t('F6: Feedback ownership → 404', true);

// F6-12: XSS - mensagem com <script> no banco, GET /chat/{id} renderiza escapado
$ownerChatCsrf = extractCsrf(request('GET', '/alterar-senha', [], $ownerJar)['body']);
$res = request('POST', '/api/chat', ['question' => 'Pergunta XSS'], $ownerJar, true, $ownerChatCsrf);
check($res['status'] === 200, 'Pergunta XSS retornou ' . $res['status']);
$ownerBody = json_decode($res['body'], true);
$convId = (int) $ownerBody['conversation_id'];
check($convId > 0, 'Conversa XSS não criada');

$xssContent = "<script>alert('xss')</script><img src=x onerror=alert(1)>";
$stmt->execute([$convId, 'assistant', $xssContent, 'mock', 'mock/model', 'mock-req-xss', 'ok']);

$res = request('GET', '/chat/' . $convId, [], $ownerJar);
check($res['status'] === 200, 'GET /chat/{id} com XSS retornou ' . $res['status']);
check(str_contains($res['body'], '&lt;script&gt;'), 'Conteúdo <script> não escapado na renderização');
check(!str_contains($res['body'], "<script>alert('xss')</script>"), 'Tag <script> cru exibida');
check(!str_contains($res['body'], '<img src=x onerror'), 'Tag <img> executável exibida');
t('F6: XSS na resposta da IA é escapado na view', true);

// F6-13: GET /chat/{id}/salvar-solucao sem permissão solutions.manage → 403
$f6NoPermEmail = 'f6-noperm-' . time() . '@assistente.test';
$f6NoPermId = $f6Repo->create([
    'name' => 'F6 No Perm',
    'email' => $f6NoPermEmail,
    'password_hash' => password_hash('F6Senha@123', PASSWORD_DEFAULT),
    'role' => 'usuario',
    'status' => 'active',
    'must_change_password' => 0,
]);
$noPermJar = newSession();
$csrf = extractCsrf(request('GET', '/login', [], $noPermJar)['body']);
$res = request('POST', '/login', ['email' => $f6NoPermEmail, 'password' => 'F6Senha@123', '_csrf' => $csrf], $noPermJar);
check($res['status'] === 302, 'Login sem permissão falhou');
$noPermChatCsrf = extractCsrf(request('GET', '/alterar-senha', [], $noPermJar)['body']);
$res = request('POST', '/api/chat', ['question' => 'Pergunta sem permissao'], $noPermJar, true, $noPermChatCsrf);
check($res['status'] === 200, 'Pergunta sem permissão retornou ' . $res['status']);
$noPermBody = json_decode($res['body'], true);
$noPermMsgId = (int) $noPermBody['user_message_id'];
check($noPermMsgId > 0, 'Mensagem do usuário sem permissão não persistida');
$res = request('GET', '/chat/' . $noPermMsgId . '/salvar-solucao', [], $noPermJar);
check($res['status'] === 403, 'Salvar solução sem permissão retornou ' . $res['status']);
t('F6: Salvar solução sem permissão → 403', true);

// F6-14: GET /chat/{id}/salvar-solucao com permissão → 200 form pré-preenchido
// Admin tem permissão solutions.manage e usa a própria mensagem da F6-08.
$f6ChatCsrf = extractCsrf(request('GET', '/alterar-senha', [], $f6AdminJar)['body']);
$res = request('GET', '/chat/' . $f6AdminUserMsgId . '/salvar-solucao', [], $f6AdminJar);
check($res['status'] === 200, 'Salvar solução com permissão retornou ' . $res['status']);
check(str_contains($res['body'], 'Salvar como solução interna'), 'Título do form ausente');
check(str_contains($res['body'], 'Problema / Sintoma'), 'Campo problema ausente');
check(str_contains($res['body'], 'Solução / Passos'), 'Campo solução ausente');
t('F6: Salvar solução com permissão → form pré-preenchido', true);
} catch (Throwable $e) {
    $f6AbortMessage = $e->getMessage();
    echo '  [AVISO] Fase 6 interrompida: ' . $e->getMessage() . PHP_EOL;
    t('F6: sequência completa sem aborto', false, $e->getMessage());
}

// Cleanup F6 HTTP
$f6AdminConvIds = array_values(array_unique(array_filter($f6AdminConvIds)));

foreach ($f6AdminConvIds as $convId) {
    Database::connection()->prepare(
        'DELETE FROM message_sources WHERE message_id IN (SELECT id FROM messages WHERE conversation_id = ?)'
    )->execute([$convId]);
    Database::connection()->prepare('DELETE FROM messages WHERE conversation_id = ?')->execute([$convId]);
    Database::connection()->prepare('DELETE FROM conversations WHERE id = ?')->execute([$convId]);
}

if ($f6DocId > 0) {
    Database::connection()->prepare('DELETE FROM knowledge_documents WHERE id = ?')->execute([$f6DocId]);
}

if ($f6ProviderOriginal !== false && $f6ProviderOriginal['status'] === 'active') {
    Database::connection()->prepare(
        'UPDATE ai_providers SET status = ?, is_default = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
    )->execute([$f6ProviderOriginal['status'], (int) $f6ProviderOriginal['is_default'], 1]);
}

Database::connection()->exec("DELETE FROM chat_limits WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'f6-%@assistente.test')");
Database::connection()->exec("DELETE FROM chat_request_guards WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'f6-%@assistente.test')");
Database::connection()->prepare('DELETE FROM chat_limits WHERE user_id = ? AND id > @t_chat_limits_start')->execute([$adminId]);
Database::connection()->prepare('DELETE FROM chat_request_guards WHERE user_id = ? AND id > @t_chat_request_guards_start')->execute([$adminId]);
Database::connection()->exec("DELETE FROM message_sources WHERE message_id IN (SELECT id FROM messages WHERE conversation_id IN (SELECT id FROM conversations WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'f6-%@assistente.test')))");
Database::connection()->exec("DELETE FROM messages WHERE conversation_id IN (SELECT id FROM conversations WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'f6-%@assistente.test'))");
Database::connection()->exec("DELETE FROM conversations WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'f6-%@assistente.test')");
Database::connection()->exec("DELETE FROM users WHERE email LIKE 'f6-%@assistente.test'");
Database::connection()->exec("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action LIKE 'chat.%')");
cleanSession($f6AdminJar);
cleanSession($ownerJar);
cleanSession($noPermJar);

// ============================= HARDENING FASE 6 (HTTP) =============================
// Usuários "f6-hard-*@assistente.test" são removidos pelo cleanup F6 padrão.

$hardRepo = new UserRepository();
$hardSuffix = time() . '-' . bin2hex(random_bytes(2));
$hardPass = 'HardSenha@123';
$hardSensitiveMarkers = ['APP_ENCRYPTION_KEY', 'DB_PASS', 'APP_ADMIN_PASSWORD', '<?php', 'declare(strict_types', '[SECURITY]'];

$hardUser = static function (string $tag, string $role, int $mustChange, string $status = 'active') use ($hardRepo, $hardSuffix, $hardPass): array {
    $email = 'f6-hard-' . $tag . '-' . $hardSuffix . '@assistente.test';
    $id = $hardRepo->create([
        'name' => 'F6 Hard ' . $tag,
        'email' => $email,
        'password_hash' => password_hash($hardPass, PASSWORD_DEFAULT),
        'role' => $role,
        'status' => $status,
        'must_change_password' => $mustChange,
    ]);

    return ['id' => $id, 'email' => $email];
};

$hardLogin = static function (string $email) use ($hardPass): string {
    $jar = newSession();
    $csrf = extractCsrf(request('GET', '/login', [], $jar)['body']);
    request('POST', '/login', ['email' => $email, 'password' => $hardPass, '_csrf' => $csrf], $jar);

    return $jar;
};

// Evita que falhas de cenários anteriores acionem o throttle por IP local.
TestIsolation::purgeLoginAttempts();

// HARD-01: arquivos sensíveis bloqueados pelo Apache
foreach ([
    '/.env', '/.env.example', '/.htaccess',
    '/storage/logs/security.log', '/storage/logs/ai.log', '/storage/logs/app.log',
    '/tests/run.php', '/tests/http.php',
    '/database/migrate.php', '/database/seed.php',
    '/app/Core/Application.php', '/config/app.php', '/routes/web.php',
    '/bootstrap/app.php', '/resources/views/home.php',
    '/README.md', '/RELATORIO_FASE_6.md',
] as $hardPath) {
    try {
        $res = request('GET', $hardPath);
        check(in_array($res['status'], [403, 404], true), $hardPath . ' retornou ' . $res['status']);

        foreach ($hardSensitiveMarkers as $marker) {
            check(!str_contains($res['body'], $marker), $hardPath . ' vazou "' . $marker . '"');
        }

        t('HARD: GET ' . $hardPath . ' bloqueado (' . $res['status'] . ')', true);
    } catch (Throwable $e) {
        t('HARD: GET ' . $hardPath . ' bloqueado', false, $e->getMessage());
    }
}

// HARD-02: aplicação e assets públicos continuam acessíveis
foreach (['/' => 200, '/login' => 200, '/assets/css/app.css' => 200, '/assets/js/app.js' => 200] as $hardPath => $hardStatus) {
    try {
        $res = request('GET', $hardPath);
        check($res['status'] === $hardStatus, $hardPath . ' retornou ' . $res['status']);
        t('HARD: GET ' . $hardPath . ' → ' . $hardStatus, true);
    } catch (Throwable $e) {
        t('HARD: GET ' . $hardPath . ' → ' . $hardStatus, false, $e->getMessage());
    }
}

// HARD-03: PHP dentro de public/assets não executa (sonda temporária, removida no finally)
$hardProbeName = 'zz_hard_probe_' . bin2hex(random_bytes(6)) . '.php';
$hardProbe = APP_PATH . '/public/assets/' . $hardProbeName;
try {
    file_put_contents($hardProbe, '<?php echo "HARD_EXEC_" . "OK";');

    foreach (['/assets/' . $hardProbeName, '/public/assets/' . $hardProbeName] as $hardPath) {
        $res = request('GET', $hardPath);
        check(!str_contains($res['body'], 'HARD_EXEC_OK'), 'PHP executou em ' . $hardPath);
        check(in_array($res['status'], [403, 404], true), $hardPath . ' retornou ' . $res['status']);
    }

    t('HARD: PHP em public/assets não executa', true);
} catch (Throwable $e) {
    t('HARD: PHP em public/assets não executa', false, $e->getMessage());
} finally {
    @unlink($hardProbe);
}

// HARD-04: diagnóstico — visitante, usuário, técnico, admin
try {
    $res = request('GET', '/diagnostico');
    check($res['status'] === 302 && str_contains($res['headers']['location'] ?? '', '/login'), 'Visitante /diagnostico: ' . $res['status']);
    check(!str_contains($res['body'], PHP_VERSION), 'Visitante viu versão do PHP');

    $res = request('GET', '/api/diagnostico');
    check($res['status'] === 401, 'Visitante /api/diagnostico: ' . $res['status']);
    check(!str_contains($res['body'], PHP_VERSION) && !str_contains($res['body'], 'migrations'), 'Visitante viu dados do diagnóstico');

    t('HARD: diagnóstico bloqueado para visitante', true);
} catch (Throwable $e) {
    t('HARD: diagnóstico bloqueado para visitante', false, $e->getMessage());
}

$hardUsuario = $hardUser('usr', 'usuario', 0);
$hardTecnico = $hardUser('tec', 'tecnico', 0);
$hardAdmin = $hardUser('adm', 'admin', 0);

foreach (['usuario' => $hardUsuario, 'tecnico' => $hardTecnico] as $hardRole => $hardAccount) {
    try {
        $jar = $hardLogin($hardAccount['email']);

        $res = request('GET', '/diagnostico', [], $jar);
        check($res['status'] === 403, $hardRole . ' /diagnostico: ' . $res['status']);
        check(!str_contains($res['body'], PHP_VERSION), $hardRole . ' viu versão do PHP');

        $res = request('GET', '/api/diagnostico', [], $jar);
        check($res['status'] === 403, $hardRole . ' /api/diagnostico: ' . $res['status']);

        cleanSession($jar);
        t('HARD: diagnóstico negado para ' . $hardRole . ' (403)', true);
    } catch (Throwable $e) {
        t('HARD: diagnóstico negado para ' . $hardRole . ' (403)', false, $e->getMessage());
    }
}

try {
    $jar = $hardLogin($hardAdmin['email']);

    $res = request('GET', '/diagnostico', [], $jar);
    check($res['status'] === 200, 'Admin /diagnostico: ' . $res['status']);

    $res = request('GET', '/api/diagnostico', [], $jar);
    check($res['status'] === 200, 'Admin /api/diagnostico: ' . $res['status']);
    check(is_array(json_decode($res['body'], true)), 'JSON do diagnóstico inválido');

    cleanSession($jar);
    t('HARD: diagnóstico permitido para admin (200)', true);
} catch (Throwable $e) {
    t('HARD: diagnóstico permitido para admin (200)', false, $e->getMessage());
}

// HARD-05: /api/chat e feedback — visitante com CSRF válido → 401 JSON
try {
    $jar = newSession();
    $csrf = extractCsrf(request('GET', '/login', [], $jar)['body']);

    $res = request('POST', '/api/chat', ['question' => 'visitante'], $jar, true, $csrf);
    check($res['status'] === 401, 'Visitante /api/chat: ' . $res['status']);
    check((json_decode($res['body'], true)['error'] ?? '') === 'unauthenticated', 'Código de erro visitante');

    $res = request('POST', '/api/chat/1/feedback', ['rating' => 'util'], $jar, true, $csrf);
    check($res['status'] === 401, 'Visitante feedback: ' . $res['status']);

    cleanSession($jar);
    t('HARD: /api/chat e feedback visitante → 401', true);
} catch (Throwable $e) {
    t('HARD: /api/chat e feedback visitante → 401', false, $e->getMessage());
}

// HARD-06: must_change_password não contorna a troca obrigatória
$hardMust = $hardUser('must', 'tecnico', 1);
try {
    $jar = $hardLogin($hardMust['email']);
    $csrf = extractCsrf(request('GET', '/alterar-senha', [], $jar)['body']);

    $res = request('POST', '/api/chat', ['question' => 'contornar troca'], $jar, true, $csrf);
    check($res['status'] === 403, 'must_change /api/chat: ' . $res['status']);
    check((json_decode($res['body'], true)['error'] ?? '') === 'password_change_required', 'Código must_change /api/chat');

    $res = request('POST', '/api/chat/1/feedback', ['rating' => 'util'], $jar, true, $csrf);
    check($res['status'] === 403, 'must_change feedback: ' . $res['status']);
    check((json_decode($res['body'], true)['error'] ?? '') === 'password_change_required', 'Código must_change feedback');

    foreach (['/chat', '/history', '/chat/1', '/dashboard', '/knowledge'] as $hardPath) {
        $res = request('GET', $hardPath, [], $jar);
        check($res['status'] === 302 && str_contains($res['headers']['location'] ?? '', '/alterar-senha'), 'must_change ' . $hardPath . ': ' . $res['status']);
    }

    $count = (int) Database::connection()->query(
        'SELECT COUNT(*) FROM conversations WHERE user_id = ' . (int) $hardMust['id']
    )->fetchColumn();
    check($count === 0, 'Conversa criada apesar do bloqueio');

    $res = request('GET', '/alterar-senha', [], $jar);
    check($res['status'] === 200, '/alterar-senha deveria continuar acessível');

    t('HARD: must_change_password bloqueia /api/chat, feedback, /chat, /history', true);

    // Após trocar a senha, must_change_password = 0 e o acesso normal volta.
    $res = request('POST', '/alterar-senha', [
        'current_password' => $hardPass,
        'new_password' => 'HardNova@4567',
        'new_password_confirmation' => 'HardNova@4567',
        '_csrf' => $csrf,
    ], $jar);
    check($res['status'] === 302 && str_contains($res['headers']['location'] ?? '', '/dashboard'), 'Troca de senha: ' . $res['status']);

    $flag = (int) Database::connection()->query(
        'SELECT must_change_password FROM users WHERE id = ' . (int) $hardMust['id']
    )->fetchColumn();
    check($flag === 0, 'must_change_password não zerou');

    foreach (['/chat', '/history'] as $hardPath) {
        $res = request('GET', $hardPath, [], $jar);
        check($res['status'] === 200, 'Após troca ' . $hardPath . ': ' . $res['status']);
    }

    $csrf = extractCsrf(request('GET', '/chat', [], $jar)['body']);
    $res = request('POST', '/api/chat', ['question' => ''], $jar, true, $csrf);
    check($res['status'] === 422, 'Após troca /api/chat: ' . $res['status']);

    cleanSession($jar);
    t('HARD: após trocar a senha o acesso a /chat, /history e /api/chat volta', true);
} catch (Throwable $e) {
    t('HARD: must_change_password — bloqueio e liberação após troca', false, $e->getMessage());
}

// HARD-07: usuário normal continua passando pela auth da rota
try {
    $jar = $hardLogin($hardUsuario['email']);
    $csrf = extractCsrf(request('GET', '/chat', [], $jar)['body']);

    $res = request('POST', '/api/chat', ['question' => ''], $jar, true, $csrf);
    check($res['status'] === 422, 'Usuário normal /api/chat (pergunta vazia): ' . $res['status']);
    check((json_decode($res['body'], true)['error'] ?? '') === 'question_required', 'Validação não alcançada');

    cleanSession($jar);
    t('HARD: usuário normal alcança o ChatController (422 validação)', true);
} catch (Throwable $e) {
    t('HARD: usuário normal alcança o ChatController (422 validação)', false, $e->getMessage());
}

// HARD-08: login não enumera contas
$hardInactive = $hardUser('inat', 'usuario', 0, 'inactive');
$hardLocked = $hardUser('lock', 'usuario', 0);
$hardRepo->lockUser((int) $hardLocked['id'], 15);

try {
    $messages = [];

    foreach ([
        'inexistente' => ['f6-hard-naoexiste-' . $hardSuffix . '@assistente.test', 'Qualquer@123'],
        'senha_errada' => [$hardUsuario['email'], 'Errada@999'],
        'inativo' => [$hardInactive['email'], $hardPass],
        'bloqueado' => [$hardLocked['email'], $hardPass],
    ] as $case => [$email, $password]) {
        $jar = newSession();
        $csrf = extractCsrf(request('GET', '/login', [], $jar)['body']);
        $res = request('POST', '/login', ['email' => $email, 'password' => $password, '_csrf' => $csrf], $jar);
        check($res['status'] === 302 && str_contains($res['headers']['location'] ?? '', '/login'), $case . ' não voltou ao login');

        $page = request('GET', '/login', [], $jar)['body'];
        check(str_contains($page, 'E-mail ou senha inválidos.'), $case . ': mensagem genérica ausente');

        foreach (['inativo', 'bloqueada', 'bloqueado', 'não encontrado', 'inexistente'] as $leak) {
            check(stripos($page, $leak) === false, $case . ': página revela "' . $leak . '"');
        }

        $messages[$case] = 'E-mail ou senha inválidos.';
        cleanSession($jar);
    }

    check(count(array_unique($messages)) === 1, 'Mensagens públicas divergentes');
    t('HARD: login não enumera contas (inexistente/senha/inativo/bloqueado)', true);
} catch (Throwable $e) {
    t('HARD: login não enumera contas (inexistente/senha/inativo/bloqueado)', false, $e->getMessage());
}

Database::connection()->exec("DELETE FROM login_attempts WHERE email LIKE 'f6-hard-%@assistente.test'");
Database::connection()->exec("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (user_id IN (SELECT id FROM users WHERE email LIKE 'f6-hard-%@assistente.test') OR (entity_type = 'user' AND entity_id IN (SELECT id FROM users WHERE email LIKE 'f6-hard-%@assistente.test')))");
Database::connection()->exec("DELETE FROM users WHERE email LIKE 'f6-hard-%@assistente.test'");

// ============================= CLEANUP =============================
try {
    $repo = new UserRepository();

    if ($tecnicoId > 0) {
        $repo->delete($tecnicoId);
        $stmt = Database::connection()->prepare('DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (entity_type = ? AND entity_id = ?)');
        $stmt->execute(['user', $tecnicoId]);
    }

    TestIsolation::purgeLoginAttempts();

    if ($docId > 0) {
        Database::connection()->prepare('DELETE FROM knowledge_documents WHERE id = ?')->execute([$docId]);
        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action LIKE 'knowledge.document.%')");
    }

    if ($usuarioHttpId > 0) {
        $repo->delete($usuarioHttpId);
        $stmt = Database::connection()->prepare('DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (entity_type = ? AND entity_id = ?)');
        $stmt->execute(['user', $usuarioHttpId]);
    }

    Database::connection()->prepare('DELETE FROM knowledge_documents WHERE url = ?')->execute([$docUrl]);
    Database::connection()->prepare('DELETE FROM internal_solutions WHERE title = ?')->execute(['Solução HTTP ' . $suffix]);
    Database::connection()->prepare('DELETE FROM knowledge_documents WHERE url IN (?, ?, ?)')
        ->execute([$f4DocUrlBoleto, $f4DocUrlMatricula, $f4DocUrlGenerico]);
    Database::connection()->prepare('DELETE FROM internal_solutions WHERE title = ?')->execute([$f4SolTitle]);

    // Usuarios de teste HTTP de execucoes anteriores (abortadas) tambem saem.
    Database::connection()->exec("DELETE FROM users WHERE email LIKE 'http-%-%@assistente.test'");

    if (is_array($f5HttpOriginal)) {
        $restore = Database::connection()->prepare(
            'UPDATE ai_providers
             SET name = ?, model = ?, api_key_encrypted = ?, base_url = ?,
                 temperature = ?, max_tokens = ?, timeout_seconds = ?, status = ?, is_default = ?
             WHERE id = ?'
        );
        $restore->execute([
            $f5HttpOriginal['name'],
            $f5HttpOriginal['model'],
            $f5HttpOriginal['api_key_encrypted'],
            $f5HttpOriginal['base_url'],
            $f5HttpOriginal['temperature'],
            $f5HttpOriginal['max_tokens'],
            $f5HttpOriginal['timeout_seconds'],
            $f5HttpOriginal['status'],
            $f5HttpOriginal['is_default'],
            (int) $f5HttpOriginal['id'],
        ]);
    }

    Database::connection()->exec("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action LIKE 'ai.%' OR entity_type = 'ai_provider')");

    $admin = $repo->findByEmail($adminEmail);
    $repo->updatePassword((int) $admin['id'], password_hash($adminDefault, PASSWORD_DEFAULT), 1);
    $repo->unlockUser((int) $admin['id']);

    if (is_file($jar)) {
        @unlink($jar);
    }

    echo 'Cleanup OK (admin restaurado, testes removidos)' . PHP_EOL;
} catch (Throwable $exception) {
    echo 'Cleanup FALHOU: ' . $exception->getMessage() . PHP_EOL;
}

// ============================= ISOLAMENTO =============================
TestIsolation::finish();
$isolation = TestIsolation::verify();
t('ISOLAMENTO: IDs pré-existentes preservados, sem resíduos, provider/usuários restaurados', $isolation['ok'], json_encode(array_diff_key($isolation, ['pre_counts' => 1, 'post_counts' => 1])));
echo 'ISOLAMENTO ' . json_encode($isolation) . PHP_EOL;

// ============================= RELATÓRIO =============================
$passCount = 0;
$failCount = 0;

echo str_repeat('=', 80) . PHP_EOL;

foreach ($results as $result) {
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