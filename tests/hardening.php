<?php

declare(strict_types=1);

/**
 * Hardening da Fase 6 — testes CLI (incluído por tests/run.php).
 * Não toca no banco real nem em storage/logs: rotação usa diretório temporário.
 */

use App\Core\Env;
use App\Core\Logger;
use App\Services\Security\Permissions;
use App\Support\Redirect;

/**
 * Avalia config/app.php com valores de .env simulados e restaura o estado.
 *
 * @param array<string, string> $overrides
 * @return array<string, mixed>
 */
function hardeningAppConfigWith(array $overrides): array
{
    $prop = new ReflectionProperty(Env::class, 'values');
    $prop->setAccessible(true);
    $original = $prop->getValue();

    try {
        $prop->setValue(null, array_merge($original, $overrides));

        return require APP_PATH . '/config/app.php';
    } finally {
        $prop->setValue(null, $original);
    }
}

test('HARD Redirect: query string preserva & (sem &amp;)', static function (): void {
    $url = Redirect::url('/chat?a=1&b=2');

    assertTrue(str_ends_with($url, '/chat?a=1&b=2'), 'Location divergente: ' . $url);
    assertTrue(!str_contains($url, '&amp;'), 'Escape HTML aplicado em header');
});

test('HARD Redirect: CRLF/header injection cai no fallback', static function (): void {
    assertSame('/', Redirect::safePath("/login\r\nSet-Cookie: x=1"));
    assertSame('/', Redirect::safePath("/login\nX: y"));
    assertSame('/', Redirect::safePath("/a\0b"));
});

test('HARD Redirect: destino externo bloqueado', static function (): void {
    assertSame('/', Redirect::safePath('//evil.example.org/x'));
    assertSame('/', Redirect::safePath('https://evil.example.org'));
    assertSame('/', Redirect::safePath('javascript:alert(1)'));
    assertSame('/', Redirect::safePath('/\\evil.example.org'));
    assertSame('/dashboard', Redirect::safePath('dashboard'));
    assertSame('/chat/5', Redirect::safePath('/chat/5'));
});

test('HARD APP_DEBUG: produção força debug desligado', static function (): void {
    $prod = hardeningAppConfigWith(['APP_ENV' => 'production', 'APP_DEBUG' => 'true']);
    assertSame(false, $prod['app']['debug'], 'Debug ligado em produção');

    $prod2 = hardeningAppConfigWith(['APP_ENV' => 'PROD', 'APP_DEBUG' => 'TRUE']);
    assertSame(false, $prod2['app']['debug'], 'Debug ligado em PROD (case)');
});

test('HARD APP_DEBUG: desenvolvimento respeita APP_DEBUG', static function (): void {
    $dev = hardeningAppConfigWith(['APP_ENV' => 'local', 'APP_DEBUG' => 'true']);
    assertSame(true, $dev['app']['debug']);

    $devOff = hardeningAppConfigWith(['APP_ENV' => 'local', 'APP_DEBUG' => 'false']);
    assertSame(false, $devOff['app']['debug']);
});

test('HARD Produção: página de erro não mostra mensagem/stack trace', static function (): void {
    $items = new ReflectionProperty(\App\Core\Config::class, 'items');
    $items->setAccessible(true);
    $original = $items->getValue();
    $secret = 'PDOException SQLSTATE[HY000] em C:\\xampp\\htdocs\\app\\Core\\Database.php:42 #0 {main}';

    $renderWith = static function (array $config) use ($items, $secret): string {
        $items->setValue(null, $config);
        $debugMessage = (bool) config('app.debug') ? $secret : 'Não foi possível processar a requisição neste momento.';
        ob_start();
        require config('paths.views') . '/errors/fatal.php';

        return (string) ob_get_clean();
    };

    try {
        $prod = hardeningAppConfigWith(['APP_ENV' => 'production', 'APP_DEBUG' => 'true']);
        $html = $renderWith(array_merge($original, ['app' => $prod['app']]));
        assertTrue(!str_contains($html, 'SQLSTATE') && !str_contains($html, 'Database.php') && !str_contains($html, '#0'), 'Produção exibiu detalhe interno');
        assertContains('Não foi possível processar a requisição', $html);

        // Controle: em desenvolvimento com debug o detalhe aparece (escapado)
        $dev = hardeningAppConfigWith(['APP_ENV' => 'local', 'APP_DEBUG' => 'true']);
        $html = $renderWith(array_merge($original, ['app' => $dev['app']]));
        assertContains('SQLSTATE', $html, 'Controle: debug local deveria mostrar o detalhe');
    } finally {
        $items->setValue(null, $original);
    }
});

test('HARD Permissão diagnostic.view: só admin', static function (): void {
    assertTrue(Permissions::userCan('admin', 'diagnostic.view'), 'Admin sem diagnóstico');
    assertTrue(!Permissions::userCan('tecnico', 'diagnostic.view'), 'Técnico com diagnóstico');
    assertTrue(!Permissions::userCan('usuario', 'diagnostic.view'), 'Usuário com diagnóstico');
});

test('HARD Rotas: chat API e diagnóstico exigem auth', static function (): void {
    $source = (string) file_get_contents(APP_PATH . '/routes/web.php');

    foreach ([
        "'/api/chat', 'ChatController@api', ['auth']",
        "'/api/chat/{id}/feedback', 'ChatController@feedback', ['auth']",
        "'/diagnostico', 'DiagnosticController@index', ['auth', 'permission:diagnostic.view']",
        "'/api/diagnostico', 'DiagnosticController@api', ['auth', 'permission:diagnostic.view']",
    ] as $expected) {
        assertTrue(str_contains($source, $expected), 'Rota sem middleware: ' . $expected);
    }
});

test('HARD Log rotation: arquiva, expira e ignora arquivos estranhos', static function (): void {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hard_logs_' . bin2hex(random_bytes(4));
    $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hard_outside_' . bin2hex(random_bytes(4)) . '.log';
    mkdir($dir);
    file_put_contents($outside, 'fora');

    $now = strtotime('2026-09-24 12:00:00');

    try {
        file_put_contents($dir . '/ai.log', 'ontem');
        touch($dir . '/ai.log', strtotime('2026-09-23 10:00:00'));
        file_put_contents($dir . '/app.log', 'hoje');
        touch($dir . '/app.log', strtotime('2026-09-24 08:00:00'));
        file_put_contents($dir . '/rag-2026-08-01.log', 'velho');
        file_put_contents($dir . '/rag-2026-09-20.log', 'recente');
        file_put_contents($dir . '/notas.txt', 'nao-log');
        file_put_contents($dir . '/.gitkeep', '');

        $report = Logger::rotateDirectory($dir, 30, $now);

        assertTrue(is_file($dir . '/ai-2026-09-23.log'), 'Canal de ontem não arquivado');
        assertTrue(!is_file($dir . '/ai.log'), 'Canal de ontem permaneceu ativo');
        assertTrue(is_file($dir . '/app.log'), 'Canal de hoje foi rotacionado');
        assertTrue(!is_file($dir . '/rag-2026-08-01.log'), 'Arquivo expirado não removido');
        assertTrue(is_file($dir . '/rag-2026-09-20.log'), 'Arquivo dentro da retenção removido');
        assertTrue(is_file($dir . '/notas.txt'), 'Arquivo não-log tocado');
        assertTrue(is_file($dir . '/.gitkeep'), '.gitkeep removido');
        assertTrue(is_file($outside), 'Arquivo fora do diretório tocado');
        assertSame(['rag-2026-08-01.log'], $report['deleted']);

        // Idempotente: segunda execução não muda nada
        $again = Logger::rotateDirectory($dir, 30, $now);
        assertSame([], $again['rotated']);
        assertSame([], $again['deleted']);
    } finally {
        foreach ((array) glob($dir . '/{,.}*', GLOB_BRACE) as $f) {
            if (is_file((string) $f)) {
                @unlink((string) $f);
            }
        }
        @rmdir($dir);
        @unlink($outside);
    }
});

test('HARD Log rotation: diretório inexistente não falha', static function (): void {
    $report = Logger::rotateDirectory(sys_get_temp_dir() . '/nao_existe_' . bin2hex(random_bytes(4)), 30, time());
    assertSame([], $report['rotated']);
    assertSame([], $report['deleted']);
});
