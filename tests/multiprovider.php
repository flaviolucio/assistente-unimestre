<?php

declare(strict_types=1);

/**
 * Multi-provider de IA (OpenRouter, Gemini, Groq, NVIDIA) — testes locais.
 * Execução isolada: php tests/multiprovider.php
 *
 * ZERO rede: todo transporte é fake. Chaves são fictícias e óbvias (não
 * parecem reais). Linhas de ai_providers alteradas durante o teste são
 * restauradas EXATAMENTE pelo TestIsolation (inclusive a do OpenRouter).
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/TestIsolation.php';

use App\Core\Database;
use App\Repositories\AiProviderRepository;
use App\Services\AI\AIException;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\AIService;
use App\Services\AI\Http\HttpTransportInterface;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\GroqProvider;
use App\Services\AI\Providers\NvidiaProvider;
use App\Services\AI\Providers\OpenRouterProvider;
use App\Services\RAG\RAGService;

error_reporting(E_ALL & ~E_DEPRECATED);

TestIsolation::begin();
register_shutdown_function([TestIsolation::class, 'finish']);

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

const MP_FAKE_KEY = 'CHAVE-FICTICIA-TESTE-MULTIPROVIDER-000';

final class MpTransport implements HttpTransportInterface
{
    public int $calls = 0;
    public array $requests = [];
    private array $queue = [];

    public function queue(array ...$results): void
    {
        $this->queue = $results;
    }

    public function postJson(string $url, array $headers, array $payload, int $connectTimeout, int $totalTimeout): array
    {
        $this->calls++;
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'payload' => $payload];

        return array_shift($this->queue) ?? ['status' => 0, 'body' => '', 'error' => 'sem resposta programada', 'error_no' => 7, 'headers' => [], 'latency_ms' => 1.0];
    }
}

function mpResult(int $status, array|string $body, array $headers = []): array
{
    return ['status' => $status, 'body' => is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE),
        'error' => '', 'error_no' => 0, 'headers' => $headers, 'latency_ms' => 12.5];
}

function mpOpenAiOk(string $content, ?string $reasoningField = null, string $finish = 'stop'): array
{
    $message = ['role' => 'assistant', 'content' => $content];

    if ($reasoningField !== null) {
        $message[$reasoningField] = 'pensando...';
    }

    return mpResult(200, ['id' => 'chatcmpl-1', 'model' => 'modelo-x', 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]]);
}

function mpGeminiOk(array $parts, string $finish = 'STOP', int $thoughts = 0): array
{
    return mpResult(200, ['candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => $finish]],
        'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 7, 'totalTokenCount' => 27 + $thoughts, 'thoughtsTokenCount' => $thoughts],
        'modelVersion' => 'gemini-teste-001', 'responseId' => 'resp-abc']);
}

function mpConfig(string $model = 'modelo-teste'): array
{
    return ['api_key' => MP_FAKE_KEY, 'model' => $model, 'temperature' => 0.2, 'max_tokens' => 800, 'timeout_seconds' => 20,
        'retry_transient' => true, 'retry_count' => 1];
}

function mpCatch(callable $fn): ?AIException
{
    try {
        $fn();
    } catch (AIException $e) {
        return $e;
    }

    return null;
}

function mpLogOffset(string $channel): int
{
    $file = config('paths.logs') . '/' . $channel . '.log';
    clearstatcache();

    return is_file($file) ? (int) filesize($file) : 0;
}

function mpLogSince(string $channel, int $offset): string
{
    $file = config('paths.logs') . '/' . $channel . '.log';
    clearstatcache();

    return is_file($file) ? (string) file_get_contents($file, false, null, $offset) : '';
}

$history = [
    ['role' => 'system', 'content' => 'Você é o assistente. CONTEXTO: [Fonte 1] Esqueci minha senha...'],
    ['role' => 'user', 'content' => 'Como recuperar minha senha?'],
    ['role' => 'assistant', 'content' => 'Clique em "Esqueci minha senha".'],
    ['role' => 'user', 'content' => 'Por quanto tempo vale?'],
];

// ============================= Factory / resolver =============================

test('Factory: cada tipo cria a classe correta e o nome correto', static function (): void {
    $t = new MpTransport();
    foreach ([
        'openrouter' => OpenRouterProvider::class, 'gemini' => GeminiProvider::class,
        'groq' => GroqProvider::class, 'nvidia' => NvidiaProvider::class,
    ] as $type => $class) {
        $p = AIProviderFactory::create($type, mpConfig(), $t);
        assertTrue($p instanceof $class, $type . ' → ' . get_class($p));
        assertSame($type, $p->name());
    }
    assertTrue(!AIProviderFactory::supports('desconhecido'));
    $thrown = false;
    try {
        AIProviderFactory::create('desconhecido', mpConfig(), $t);
    } catch (RuntimeException) {
        $thrown = true;
    }
    assertTrue($thrown, 'Tipo desconhecido não foi recusado');
    assertSame(0, $t->calls, 'Factory fez chamada de rede');
});

// ============================= Gemini =============================

test('Gemini: adaptador systemInstruction/contents, roles user/model, chave só no cabeçalho', static function () use ($history): void {
    $t = new MpTransport();
    $t->queue(mpGeminiOk([['text' => 'Válida por 1 dia.']]));
    $response = AIProviderFactory::create('gemini', mpConfig('gemini-2.5-flash'), $t)->chat($history);
    $req = $t->requests[0];

    assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', $req['url']);
    assertTrue(!str_contains($req['url'], MP_FAKE_KEY) && !str_contains($req['url'], 'key='), 'Chave na URL');
    assertSame(MP_FAKE_KEY, $req['headers']['x-goog-api-key'] ?? null, 'Cabeçalho x-goog-api-key ausente');
    assertTrue(!isset($req['headers']['Authorization']), 'Authorization indevido no Gemini');
    assertSame('Você é o assistente. CONTEXTO: [Fonte 1] Esqueci minha senha...', $req['payload']['systemInstruction']['parts'][0]['text']);
    assertSame(['user', 'model', 'user'], array_column($req['payload']['contents'], 'role'));
    assertSame('Por quanto tempo vale?', $req['payload']['contents'][2]['parts'][0]['text']);
    assertSame(0.2, $req['payload']['generationConfig']['temperature']);
    assertSame(800, $req['payload']['generationConfig']['maxOutputTokens']);

    assertSame('Válida por 1 dia.', $response->content());
    assertSame('gemini', $response->provider());
    assertSame('gemini-teste-001', $response->model());
    assertSame('stop', $response->finishReason());
    assertSame(27, $response->totalTokens());
    assertSame('resp-abc', $response->requestId());
});

test('Gemini: mensagens consecutivas do mesmo papel são unidas; conversa começa por user', static function (): void {
    $p = new GeminiProvider(mpConfig('gemini-x'), new MpTransport());
    $out = $p->toGeminiFormat([
        ['role' => 'assistant', 'content' => 'A0'],
        ['role' => 'user', 'content' => 'U1'],
        ['role' => 'user', 'content' => 'U2'],
    ]);
    assertSame(['user', 'model', 'user'], array_column($out['contents'], 'role'));
    assertSame("U1\n\nU2", $out['contents'][2]['parts'][0]['text']);
    assertTrue(!isset($out['systemInstruction']));
});

test('Gemini: partes "thought" ficam fora do conteúdo; MAX_TOKENS vira length/truncated', static function (): void {
    $t = new MpTransport();
    $t->queue(mpGeminiOk([['text' => 'raciocínio interno', 'thought' => true], ['text' => 'Resposta final']], 'MAX_TOKENS', 30));
    $r = AIProviderFactory::create('gemini', mpConfig('gemini-x'), $t)->chat([['role' => 'user', 'content' => 'oi']]);

    assertSame('Resposta final', $r->content());
    assertTrue($r->hasReasoning());
    assertSame('length', $r->finishReason());
    assertTrue($r->truncated());
    assertSame('MAX_TOKENS', $r->metadata()['finish_reason_raw']);
});

test('Gemini: só raciocínio → reasoning_only; bloqueio → content_filtered', static function (): void {
    $t = new MpTransport();
    $t->queue(mpGeminiOk([['text' => 'pensando', 'thought' => true]], 'MAX_TOKENS', 50));
    $e = mpCatch(static fn () => AIProviderFactory::create('gemini', mpConfig('gemini-x'), $t)->chat([['role' => 'user', 'content' => 'oi']]));
    assertSame('reasoning_only', $e?->errorCode());

    $t2 = new MpTransport();
    $t2->queue(mpResult(200, ['promptFeedback' => ['blockReason' => 'SAFETY']]));
    $e2 = mpCatch(static fn () => AIProviderFactory::create('gemini', mpConfig('gemini-x'), $t2)->chat([['role' => 'user', 'content' => 'oi']]));
    assertSame('content_filtered', $e2?->errorCode());
});

test('Gemini: erros normalizados (429/400 chave/404/503) sem vazar a chave', static function (): void {
    $cases = [
        [429, ['error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']], 'rate_limited'],
        [400, ['error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key. ' . MP_FAKE_KEY, 'status' => 'INVALID_ARGUMENT']], 'authentication_error'],
        [404, ['error' => ['code' => 404, 'message' => 'models/xyz is not found', 'status' => 'NOT_FOUND']], 'model_unavailable'],
        [503, ['error' => ['code' => 503, 'message' => 'The model is overloaded', 'status' => 'UNAVAILABLE']], 'upstream_overloaded'],
    ];
    $offset = mpLogOffset('ai');

    foreach ($cases as [$status, $body, $expected]) {
        $t = new MpTransport();
        $t->queue(mpResult($status, $body));
        $e = mpCatch(static fn () => AIProviderFactory::create('gemini', mpConfig('gemini-x'), $t)->chat([['role' => 'user', 'content' => 'oi']]));
        assertSame($expected, $e?->errorCode(), 'HTTP ' . $status);
        assertSame(1, $t->calls, 'Nova tentativa indevida em ' . $status);
        assertTrue(!str_contains($e->getMessage(), MP_FAKE_KEY) && !str_contains(json_encode($e->safeContext()), MP_FAKE_KEY), 'Chave vazou no erro');
    }

    assertTrue(!str_contains(mpLogSince('ai', $offset), MP_FAKE_KEY), 'Chave vazou no log');
});

// ============================= Groq =============================

test('Groq: endpoint oficial, Bearer, max_completion_tokens, parse OpenAI', static function () use ($history): void {
    $t = new MpTransport();
    $t->queue(mpOpenAiOk('Resposta Groq'));
    $r = AIProviderFactory::create('groq', mpConfig('llama-teste'), $t)->chat($history);
    $req = $t->requests[0];

    assertSame('https://api.groq.com/openai/v1/chat/completions', $req['url']);
    assertSame('Bearer ' . MP_FAKE_KEY, $req['headers']['Authorization'] ?? null);
    assertSame(800, $req['payload']['max_completion_tokens'] ?? null);
    assertTrue(!isset($req['payload']['max_tokens']), 'max_tokens obsoleto enviado à Groq');
    assertSame(['system', 'user', 'assistant', 'user'], array_column($req['payload']['messages'], 'role'));
    assertSame('Resposta Groq', $r->content());
    assertSame('groq', $r->provider());
    assertSame(15, $r->totalTokens());
});

test('Groq: 401 → authentication_error; 429 sem Retry-After → 1 tentativa', static function (): void {
    $t = new MpTransport();
    $t->queue(mpResult(401, ['error' => ['message' => 'Invalid API Key', 'type' => 'invalid_request_error']]));
    assertSame('authentication_error', mpCatch(static fn () => AIProviderFactory::create('groq', mpConfig(), $t)->chat([['role' => 'user', 'content' => 'oi']]))?->errorCode());

    $t2 = new MpTransport();
    $t2->queue(mpResult(429, ['error' => ['message' => 'Rate limit reached']]), mpOpenAiOk('não deveria'));
    $e = mpCatch(static fn () => AIProviderFactory::create('groq', mpConfig(), $t2)->chat([['role' => 'user', 'content' => 'oi']]));
    assertSame('rate_limited', $e?->errorCode());
    assertSame(1, $t2->calls);
    assertSame(1, $e->safeContext()['attempts'] ?? null);
});

// ============================= NVIDIA =============================

test('NVIDIA: endpoint direto (sem OpenRouter), Bearer, max_tokens', static function () use ($history): void {
    $t = new MpTransport();
    $t->queue(mpOpenAiOk('Resposta NVIDIA'));
    $r = AIProviderFactory::create('nvidia', mpConfig('meta/llama-teste'), $t)->chat($history);
    $req = $t->requests[0];

    assertSame('https://integrate.api.nvidia.com/v1/chat/completions', $req['url']);
    assertTrue(!str_contains($req['url'], 'openrouter'), 'NVIDIA passou pelo OpenRouter');
    assertSame('Bearer ' . MP_FAKE_KEY, $req['headers']['Authorization'] ?? null);
    assertSame(800, $req['payload']['max_tokens'] ?? null);
    assertSame('meta/llama-teste', $req['payload']['model']);
    assertSame('Resposta NVIDIA', $r->content());
    assertSame('nvidia', $r->provider());
});

test('NVIDIA: reasoning_content sem content → reasoning_only; com content → aviso de raciocínio', static function (): void {
    $t = new MpTransport();
    $t->queue(mpOpenAiOk('', 'reasoning_content', 'length'));
    assertSame('reasoning_only', mpCatch(static fn () => AIProviderFactory::create('nvidia', mpConfig(), $t)->chat([['role' => 'user', 'content' => 'oi']]))?->errorCode());

    $t2 = new MpTransport();
    $t2->queue(mpOpenAiOk('OK', 'reasoning_content'));
    $r = AIProviderFactory::create('nvidia', mpConfig(), $t2)->chat([['role' => 'user', 'content' => 'oi']]);
    assertTrue($r->hasReasoning());
});

test('NVIDIA: HTTP 200 com erro "Service temporarily overloaded" → upstream_overloaded, sem retry', static function (): void {
    $t = new MpTransport();
    $t->queue(mpResult(200, ['error' => ['message' => 'Upstream error from Nvidia: Service temporarily overloaded']]), mpOpenAiOk('não deveria'));
    $e = mpCatch(static fn () => AIProviderFactory::create('nvidia', mpConfig(), $t)->chat([['role' => 'user', 'content' => 'oi']]));
    assertSame('upstream_overloaded', $e?->errorCode());
    assertSame('upstream_overloaded', $e->category());
    assertSame(1, $t->calls);
});

test('Transporte: timeout não repete; falha de conexão repete 1 vez', static function (): void {
    $t = new MpTransport();
    $t->queue(['status' => 0, 'body' => '', 'error' => 'Operation timed out', 'error_no' => 28, 'headers' => [], 'latency_ms' => 1.0]);
    assertSame('timeout', mpCatch(static fn () => AIProviderFactory::create('groq', mpConfig(), $t)->chat([['role' => 'user', 'content' => 'oi']]))?->errorCode());
    assertSame(1, $t->calls, 'Timeout foi repetido');

    $t2 = new MpTransport();
    $t2->queue(['status' => 0, 'body' => '', 'error' => 'Failed to connect', 'error_no' => 7, 'headers' => [], 'latency_ms' => 1.0], mpOpenAiOk('ok'));
    assertSame('ok', AIProviderFactory::create('groq', mpConfig(), $t2)->chat([['role' => 'user', 'content' => 'oi']])->content());
    assertSame(2, $t2->calls);
});

test('Segurança: host fora da allowlist é recusado antes de qualquer chamada', static function (): void {
    $t = new MpTransport();
    $p = new GroqProvider(array_merge(mpConfig(), ['base_url' => 'https://evil.example.org/openai/v1']), $t);
    assertSame('invalid_base_url', mpCatch(static fn () => $p->chat([['role' => 'user', 'content' => 'oi']]))?->errorCode());
    assertSame(0, $t->calls, 'Chamou host não permitido');
});

test('Erros: categorias normalizadas também para os códigos históricos do OpenRouter', static function (): void {
    foreach (['http_401' => 'authentication_error', 'http_429' => 'rate_limited', 'http_5xx' => 'upstream_overloaded',
        'timeout' => 'timeout', 'dns_error' => 'connection_error', 'http_400' => 'invalid_request',
        'model_unavailable' => 'model_unavailable', 'reasoning_only' => 'provider_error'] as $code => $cat) {
        assertSame($cat, AIException::categoryFor($code), $code);
    }
});

// ============================= AIService: persistência, chave, padrão =============================

$repo = new AiProviderRepository();
$ids = [];
foreach (['openrouter', 'gemini', 'groq', 'nvidia'] as $type) {
    $row = $repo->findByProvider($type);
    $ids[$type] = $row !== null ? (int) $row['id'] : 0;
}

test('Banco: os 4 providers existem e os novos começam sem chave', static function () use ($ids): void {
    foreach ($ids as $type => $id) {
        assertTrue($id > 0, 'Provider ausente: ' . $type);
    }
    $service = new AIService();
    foreach (['gemini', 'groq'] as $type) {
        $p = $service->provider($ids[$type]);
        assertTrue(!$p['has_key'] && $p['status'] === 'inactive' && (int) $p['is_default'] === 0, $type . ' não está "sem chave/inativo"');
        assertTrue(!array_key_exists('api_key_encrypted', $p), 'Ciphertext exposto no decorate');
    }
});

test('Chave: criptografada (AES-256-GCM v1:), nunca em texto puro; campo vazio não apaga; remoção explícita', static function () use ($ids): void {
    $service = new AIService();
    $id = $ids['gemini'];
    $base = ['name' => 'Google Gemini', 'model' => 'gemini-2.5-flash', 'temperature' => '0.2', 'max_tokens' => '1500', 'timeout_seconds' => '30', 'status' => 'inactive', 'is_default' => false];

    assertSame('ok', $service->save($id, $base + ['api_key' => MP_FAKE_KEY])['status']);
    $stored = (string) Database::connection()->query('SELECT api_key_encrypted FROM ai_providers WHERE id = ' . $id)->fetchColumn();
    assertTrue(str_starts_with($stored, 'v1:') && !str_contains($stored, MP_FAKE_KEY), 'Chave não criptografada');
    assertSame(MP_FAKE_KEY, $service->providerConfig($id)['api_key'], 'Descriptografia divergente');

    assertSame('ok', $service->save($id, $base + ['api_key' => ''])['status']);
    assertTrue($service->hasKey($id), 'Salvar com campo vazio apagou a chave');

    $audit = Database::connection()->query("SELECT GROUP_CONCAT(details) FROM audit_logs WHERE entity_type = 'ai_provider' AND entity_id = {$id} AND id > @t_audit_logs_start")->fetchColumn();
    assertTrue(!str_contains((string) $audit, MP_FAKE_KEY) && !str_contains((string) $audit, 'v1:'), 'Chave/ciphertext em audit_logs');

    assertSame('ok', $service->removeKey($id)['status']);
    assertTrue(!$service->hasKey($id), 'Remoção explícita não removeu');
});

test('Padrão: só um provider padrão; resolução estrita (sem fallback para outro ativo)', static function () use ($ids): void {
    $service = new AIService();
    $base = ['name' => 'Groq', 'model' => 'llama-teste', 'temperature' => '0.2', 'max_tokens' => '1500', 'timeout_seconds' => '30'];

    assertSame('ok', $service->save($ids['groq'], $base + ['api_key' => MP_FAKE_KEY, 'status' => 'active', 'is_default' => true])['status']);
    $defaults = (int) Database::connection()->query('SELECT COUNT(*) FROM ai_providers WHERE is_default = 1')->fetchColumn();
    assertSame(1, $defaults, 'Mais de um provider padrão');
    assertSame($ids['groq'], $service->defaultProviderId());

    // Desativar o padrão NÃO faz o sistema escolher outro ativo automaticamente.
    assertSame('ok', $service->save($ids['groq'], $base + ['api_key' => '', 'status' => 'inactive', 'is_default' => false])['status']);
    assertSame(null, $service->defaultProviderId(), 'Houve fallback para outro provider ativo');
});

test('RAG: usa o provider padrão (Gemini via fake) e registra o provider real', static function () use ($ids): void {
    $t = new MpTransport();
    $t->queue(mpGeminiOk([['text' => 'A solicitação vale 1 dia [Fonte 1].']]));
    $service = new AIService(null, null, static fn () => $t);
    $base = ['name' => 'Google Gemini', 'model' => 'gemini-2.5-flash', 'temperature' => '0.2', 'max_tokens' => '1500', 'timeout_seconds' => '30'];
    assertSame('ok', $service->save($ids['gemini'], $base + ['api_key' => MP_FAKE_KEY, 'status' => 'active', 'is_default' => true])['status']);

    $result = (new RAGService($service))->answer('Como recuperar minha senha no Portal UNIMESTRE?', 1);

    assertTrue($result->isSuccess() && $result->aiCalled(), 'RAG não chamou o provider padrão');
    assertSame('gemini', $result->provider());
    assertSame(1, $t->calls);
    assertTrue(str_contains($t->requests[0]['url'], 'generativelanguage.googleapis.com'), 'RAG não foi ao Gemini');
    assertTrue(str_contains($t->requests[0]['payload']['systemInstruction']['parts'][0]['text'], 'Esqueci minha senha'), 'Contexto RAG não chegou como systemInstruction');
    assertTrue(in_array(39922, array_map(static fn ($s) => (int) $s['document_id'], $result->sources()), true), 'Fonte 39922 ausente');
});

test('Teste de conexão: usa só o provider escolhido, com avisos de length/raciocínio', static function () use ($ids): void {
    $t = new MpTransport();
    $t->queue(mpOpenAiOk('OK', 'reasoning_content', 'length'));
    $service = new AIService(null, null, static fn () => $t);
    $base = ['name' => 'NVIDIA', 'model' => 'meta/llama-teste', 'temperature' => '0.2', 'max_tokens' => '1500', 'timeout_seconds' => '30'];
    assertSame('ok', $service->save($ids['nvidia'], $base + ['api_key' => MP_FAKE_KEY, 'status' => 'active', 'is_default' => false])['status']);

    $r = $service->test($ids['nvidia']);
    assertTrue($r['success'] === true, 'Teste falhou');
    assertSame(1, $t->calls, 'Teste fez mais de uma chamada / fallback');
    assertTrue(str_contains($t->requests[0]['url'], 'integrate.api.nvidia.com'));
    assertSame(256, $t->requests[0]['payload']['max_tokens'], 'Teste não foi mínimo');
    assertSame(2, count($r['warnings']), 'Avisos de length/raciocínio ausentes');

    $t->queue(mpResult(200, ['choices' => [['message' => ['content' => ''], 'finish_reason' => 'stop']]]));
    $empty = $service->test($ids['nvidia']);
    assertTrue($empty['success'] === false && $empty['error_code'] === 'empty_response', 'Resposta vazia aceita como sucesso');
});

// ============================= Painel =============================

test('Painel: 4 cards, rótulos, PROVIDER PADRÃO, sem chave/ciphertext no HTML, ações por provider', static function () use ($ids): void {
    $service = new AIService();
    $order = array_flip(array_keys(AIProviderFactory::PROVIDERS));
    $providers = $service->listProviders();
    usort($providers, static fn ($a, $b) => ($order[$a['provider']] ?? 99) <=> ($order[$b['provider']] ?? 99));
    $encryptionConfigured = true;
    $canManage = true;
    $canTest = true;
    $testResult = [];
    $_SESSION['_csrf'] = str_repeat('b', 64);

    ob_start();
    require APP_PATH . '/resources/views/ai/index.php';
    $html = (string) ob_get_clean();

    foreach (['OpenRouter', 'Google Gemini', 'Groq', 'NVIDIA'] as $label) {
        assertTrue(str_contains($html, '<strong>' . $label . '</strong>'), 'Card ausente: ' . $label);
    }
    assertSame(1, substr_count($html, 'PROVIDER PADRÃO'), 'Badge de padrão divergente');
    assertTrue(!str_contains($html, MP_FAKE_KEY), 'Chave no HTML');
    $ciphertexts = Database::connection()->query("SELECT api_key_encrypted FROM ai_providers WHERE api_key_encrypted IS NOT NULL AND api_key_encrypted <> ''")->fetchAll(PDO::FETCH_COLUMN);
    assertTrue($ciphertexts !== [], 'Pré-condição: ao menos um provider com chave');
    foreach ($ciphertexts as $cipher) {
        assertTrue(!str_contains($html, (string) $cipher) && !str_contains($html, substr((string) $cipher, 3, 24)), 'Ciphertext no HTML');
    }
    assertTrue(str_contains($html, 'Chave configurada'), 'Indicador "Chave configurada" ausente');
    foreach ($ids as $id) {
        assertTrue(str_contains($html, 'admin/ai/' . $id . '/test'), 'Botão de teste ausente para id ' . $id);
    }
    assertTrue(str_contains($html, 'Remover API Key'), 'Ação explícita de remoção ausente');
    assertTrue(!str_contains($html, 'sk-or-'), 'Placeholder com cara de chave');
});

// ============================= Isolamento =============================

TestIsolation::finish();
$isolation = TestIsolation::verify();
test('ISOLAMENTO: ai_providers restaurado exatamente, sem resíduos', static function () use ($isolation): void {
    assertTrue($isolation['ok'], json_encode(array_diff_key($isolation, ['pre_counts' => 1, 'post_counts' => 1])));
});

$pass = 0;
$fail = 0;

foreach ($tests as $t) {
    printf("%-4s %s%s\n", $t['pass'] ? 'PASS' : 'FAIL', $t['name'], $t['pass'] ? '' : ' — ' . $t['detail']);
    $t['pass'] ? $pass++ : $fail++;
}

printf("TOTAL: %d | PASS: %d | FAIL: %d\n", $pass + $fail, $pass, $fail);
exit($fail > 0 ? 1 : 0);
