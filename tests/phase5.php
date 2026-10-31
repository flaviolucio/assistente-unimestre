<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\AiProviderRepository;
use App\Services\AI\AIException;
use App\Services\AI\AIService;
use App\Services\AI\AIResponse;
use App\Services\AI\Http\HttpTransportInterface;
use App\Services\AI\Providers\OpenRouterProvider;
use App\Services\Security\EncryptionService;

/**
 * Transporte HTTP fake (memoria) para a camada de IA. Usado em todos os testes
 * da Fase 5: nenhuma chamada real / internet e exigida.
 */
final class FakeTransport implements HttpTransportInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $queue = [];

    public int $calls = 0;

    public array $lastResult = [];

    public string $lastUrl = '';

    /** @var array<string, string> */
    public array $lastHeaders = [];

    /** @var array<mixed> */
    public array $lastPayload = [];

    public function setResults(array $results): void
    {
        $this->queue = $results;
    }

    public function postJson(string $url, array $headers, array $payload, int $connectTimeout, int $totalTimeout): array
    {
        $this->calls++;
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastPayload = $payload;

        if ($this->queue === []) {
            $this->queue = [$this->lastResult];
        }

        $this->lastResult = array_shift($this->queue) ?? $this->lastResult;

        return $this->lastResult;
    }
}

function f5Result(int $status, string $body, array $extra = []): array
{
    return array_merge([
        'status' => $status,
        'body' => $body,
        'error' => '',
        'error_no' => 0,
        'headers' => [],
        'latency_ms' => 123.4,
    ], $extra);
}

function f5OkBody(string $content = 'Resposta final da IA', string $model = 'openrouter/free'): string
{
    return json_encode([
        'id' => 'gen-f5-abc123',
        'model' => $model,
        'choices' => [
            [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ],
        ],
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 7, 'total_tokens' => 19],
    ], JSON_UNESCAPED_UNICODE);
}

function f5ErrorBody(string $message): string
{
    return json_encode(['error' => ['message' => $message, 'type' => 'error']], JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
// Snapshot do provider OpenRouter e limpeza de residuos (fator de reexecucao).
// ---------------------------------------------------------------------------
$f5Repo = new AiProviderRepository();
$GLOBALS['f5OriginalRow'] = $f5Repo->findByProvider('openrouter');

Database::connection()->exec("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action LIKE 'ai.%' OR entity_type = 'ai_provider')");

$f5TestKey = 'sk-f5-test-8f3d' . substr((string) uniqid('', true), 0, 6) . '-segredo';

// ============================= FASE 5 — CRIPTOGRAFIA =============================

test('F5 Cripto: chave apresentada e reconhecida como configurada', static function (): void {
    $service = new EncryptionService('f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1');
    assertTrue($service->isConfigured(), 'Chave de 64 hex deveria ser aceita');
});

test('F5 Cripto: chave ausente ou curta nao e aceita', static function (): void {
    $semChave = new EncryptionService('');
    $curta = new EncryptionService('chave-curta');
    $comEspacos = new EncryptionService('   ');

    assertTrue(!$semChave->isConfigured(), 'Chave vazia considerada configurada');
    assertTrue(!$curta->isConfigured(), 'Chave curta considerada configurada');
    assertTrue(!$comEspacos->isConfigured(), 'Chave com espacos considerada configurada');

    $falhou = false;

    try {
        $semChave->encrypt('secreto');
    } catch (RuntimeException $exception) {
        $falhou = $exception->getMessage() !== '';
    }

    assertTrue($falhou, 'Encrypt deveria falhar sem APP_ENCRYPTION_KEY');
});

test('F5 Cripto: roundtrip, formato v1 e nao determinismo', static function (): void {
    $service = new EncryptionService('f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1');
    $plain = 'sk-or-segredo-do-admin-123456';

    $primeiro = $service->encrypt($plain);
    $segundo = $service->encrypt($plain);

    assertTrue(str_starts_with($primeiro, 'v1:'), 'Payload sem prefixo v1:');
    assertTrue($primeiro !== $segundo, 'Mesmo texto gerou ciphertexts identicos (nonce nao aplicado)');
    assertTrue(!str_contains($primeiro, $plain), 'Plaintext vazou para dentro do payload');
    assertSame($plain, $service->decrypt($segundo), 'Roundtrip falhou');
});

test('F5 Cripto: adulteracao e chave errada falham de forma segura', static function (): void {
    $service = new EncryptionService('f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1');
    $payload = $service->encrypt('dado-sensivel');

    $tampered = substr($payload, 0, -4) . 'zzzz';
    $wrongKey = new EncryptionService('1111111111111111111111111111111111111111111111111111111111111111');

    $tamperFalhou = false;
    $wrongFalhou = false;

    try {
        $service->decrypt($tampered);
    } catch (RuntimeException $exception) {
        $tamperFalhou = $exception->getMessage() !== '';
    }

    try {
        $wrongKey->decrypt($payload);
    } catch (RuntimeException $exception) {
        $wrongFalhou = $exception->getMessage() !== '';
    }

    assertTrue($tamperFalhou, 'Dados adulterados nao foram rejeitados');
    assertTrue($wrongFalhou, 'Chave incorreta aceitou o decrypt');
});

test('F5 Cripto: formato invalido e rejeitado', static function (): void {
    $service = new EncryptionService('f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1f1');
    $falhou = false;

    try {
        $service->decrypt('sem-verao');
    } catch (RuntimeException $exception) {
        $falhou = $exception->getMessage() !== '';
    }

    assertTrue($falhou, 'Payload sem versao aceito');
});

test('F5 Logs: segredos sao redigidos e nunca persistem em arquivos', static function (): void {
    $segredo = 'sk-ultrasecreto-f5-' . bin2hex(random_bytes(6));
    Logger::error('ai', 'ensaio de redacao', [
        'api_key' => $segredo,
        'authorization' => 'Bearer ' . $segredo,
        'APP_ENCRYPTION_KEY' => $segredo,
        'extra' => 'visivel',
    ]);

    $aiLog = (string) @file_get_contents((string) config('paths.logs') . '/ai.log');
    $appLog = (string) @file_get_contents((string) config('paths.logs') . '/app.log');

    assertTrue(!str_contains($aiLog, $segredo), 'Segredo persistiu no ai.log');
    assertTrue(!str_contains($appLog, $segredo), 'Segredo persistiu no app.log');
    assertContains('visivel', $aiLog, 'Contexto legítimo sumiu');
});

// ============================= FASE 5 — DTO =============================

test('F5 DTO: AIResponse normaliza resposta e erro', static function (): void {
    $ok = AIResponse::success([
        'content' => 'ola',
        'provider' => 'openrouter',
        'model' => 'openrouter/free',
        'prompt_tokens' => 5,
        'completion_tokens' => 2,
        'total_tokens' => 7,
        'latency_ms' => 42.5,
        'finish_reason' => 'stop',
        'request_id' => 'req-1',
    ]);

    assertTrue($ok->isSuccess(), 'Sucesso nao reconhecido');
    assertSame('ola', $ok->content());
    assertSame('openrouter', $ok->provider());
    assertSame('openrouter/free', $ok->model());
    assertSame(7, $ok->totalTokens());
    assertSame('req-1', $ok->requestId());

    $erro = AIResponse::error('Falha generica', 'http_401');
    assertTrue(!$erro->isSuccess(), 'Erro marcado como sucesso');
    assertSame('http_401', $erro->errorCode());
});

test('F5 Config: parametros de IA centralizados', static function (): void {
    $ai = (array) config('ai', []);
    assertSame('openrouter', (string) $ai['default_provider']);
    assertSame('aes-256-gcm', (string) $ai['cipher']);
    assertSame(30, (int) $ai['timeout']);
    assertTrue((int) $ai['connect_timeout'] >= 1, 'connect_timeout ausente');
    assertSame(1, (int) $ai['retry_count']);
    assertTrue((string) config('ai.encryption_key') !== '', 'APP_ENCRYPTION_KEY vazio no config');
});

// ============================= FASE 5 — PROVIDER (transporte fake) =============================

function f5ProviderConfig(array $overrides = []): array
{
    return array_merge([
        'api_key' => 'sk-test-provider-f5',
        'model' => 'openrouter/free',
        'temperature' => 0.2,
        'max_tokens' => 1500,
        'timeout_seconds' => 30,
        'base_url' => (string) config('ai.openrouter_base_url', 'https://openrouter.ai/api/v1'),
    ], $overrides);
}

test('F5 Provider: URI, payload e Authorization corretos', static function (): void {
    $transport = new FakeTransport();
    $transport->setResults([f5Result(200, f5OkBody())]);

    $provider = new OpenRouterProvider(f5ProviderConfig(['api_key' => 'sk-test-provider-f5', 'max_tokens' => 1500]), $transport);
    $response = $provider->chat([['role' => 'user', 'content' => 'Qual o capital do Brasil?']]);

    assertTrue($response->isSuccess(), 'Chat falhou');
    assertSame('https://openrouter.ai/api/v1/chat/completions', $transport->lastUrl, 'Endpoint errado');
    assertSame('openrouter/free', $transport->lastPayload['model'], 'Modelo ausente no payload');
    assertSame(1500, $transport->lastPayload['max_tokens'], 'max_tokens ausente no payload');

    $authorization = $transport->lastHeaders['Authorization'] ?? '';
    assertTrue(str_starts_with($authorization, 'Bearer sk-test-provider-f5'), 'Authorization nao chegou ao transporte');
    assertSame('application/json', $transport->lastHeaders['Content-Type'] ?? '', 'Content-Type ausente');
    assertTrue(isset($transport->lastHeaders['HTTP-Referer']), 'HTTP-Referer ausente');
    assertTrue(!array_key_exists('api_key', $transport->lastPayload), 'Chave vazou para o payload');
});

test('F5 Provider: resposta 200 e convertida em AIResponse completo', static function (): void {
    $transport = new FakeTransport();
    $transport->setResults([f5Result(200, f5OkBody(), ['headers' => ['x-request-id' => 'req-999']])]);

    $provider = new OpenRouterProvider(f5ProviderConfig(), $transport);
    $response = $provider->chat([['role' => 'user', 'content' => 'oi']]);

    assertTrue($response->isSuccess(), 'Falha no parse de sucesso');
    assertSame('Resposta final da IA', $response->content());
    assertSame('openrouter/free', $response->model());
    assertSame(12, $response->promptTokens());
    assertSame(7, $response->completionTokens());
    assertSame(19, $response->totalTokens());
    assertSame('stop', $response->finishReason());
    assertSame('req-999', $response->requestId());
    assertTrue($response->latencyMs() >= 100, 'Latencia nao propagada');
});

test('F5 Provider: 401 mapeia para mensagem amigavel exata', static function (): void {
    $transport = new FakeTransport();
    $transport->setResults([f5Result(401, f5ErrorBody('Invalid API key'))]);

    $provider = new OpenRouterProvider(f5ProviderConfig(), $transport);
    $falhou = false;

    try {
        $provider->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $exception) {
        $falhou = true;
        assertSame('http_401', $exception->errorCode(), 'Codigo de 401 errado');
        assertSame(OpenRouterProvider::MSG_UNAUTHORIZED, $exception->getMessage(), 'Mensagem 401 divergente');
        assertSame(401, $exception->httpStatus());
        assertTrue(!$exception->isTransient(), '401 nao deve ser transitorio');
    }

    assertTrue($falhou, '401 nao lancou AIException');
});

test('F5 Provider: 429 mapeia para mensagem amigavel exata', static function (): void {
    $transport = new FakeTransport();
    $transport->setResults([f5Result(429, f5ErrorBody('Rate limit'))]);

    $provider = new OpenRouterProvider(f5ProviderConfig(), $transport);
    $falhou = false;

    try {
        $provider->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $exception) {
        $falhou = true;
        assertSame('http_429', $exception->errorCode(), 'Codigo de 429 errado');
        assertSame(OpenRouterProvider::MSG_RATE_LIMITED, $exception->getMessage(), 'Mensagem 429 divergente');
        assertTrue($exception->isTransient(), '429 deveria ser transitorio');
    }

    assertTrue($falhou, '429 nao lancou AIException');
});

test('F5 Provider: erros de transporte (timeout/DNS/SSL) mapeados', static function (): void {
    $cases = [
        ['error_no' => 28, 'error' => 'Operation timed out', 'code' => 'timeout', 'msg' => OpenRouterProvider::MSG_TIMEOUT],
        ['error_no' => 6, 'error' => 'Could not resolve host', 'code' => 'dns_error', 'msg' => OpenRouterProvider::MSG_CONNECTION],
        ['error_no' => 60, 'error' => 'SSL certificate problem', 'code' => 'ssl_error', 'msg' => OpenRouterProvider::MSG_SSL],
        ['error_no' => 7, 'error' => 'Connection refused', 'code' => 'connection_error', 'msg' => OpenRouterProvider::MSG_CONNECTION],
    ];

    foreach ($cases as $case) {
        $transport = new FakeTransport();
        $transport->setResults([[
            'status' => 0,
            'body' => '',
            'error' => $case['error'],
            'error_no' => $case['error_no'],
            'headers' => [],
            'latency_ms' => 5.0,
        ]]);

        $provider = new OpenRouterProvider(f5ProviderConfig(), $transport);
        $error = null;

        try {
            $provider->chat([['role' => 'user', 'content' => 'oi']]);
        } catch (AIException $exception) {
            $error = $exception;
        }

        assertTrue($error !== null, 'Nenhuma excecao para "' . $case['error'] . '"');
        assertSame($case['code'], $error->errorCode(), 'Codigo errado para "' . $case['error'] . '"');
        assertSame($case['msg'], $error->getMessage(), 'Mensagem errada para "' . $case['error'] . '"');
        assertTrue($error->isTransient(), 'Erro de transporte deveria ser transitorio');
    }
});

test('F5 Provider: JSON invalido e resposta sem choices', static function (): void {
    $invalidJson = new FakeTransport();
    $invalidJson->setResults([f5Result(200, 'isso nao e json')]);
    $providerInvalid = new OpenRouterProvider(f5ProviderConfig(), $invalidJson);
    $erroJson = null;

    try {
        $providerInvalid->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $exception) {
        $erroJson = $exception;
    }

    assertTrue($erroJson !== null, 'JSON invalido nao lancou excecao');
    assertSame('invalid_json', $erroJson->errorCode());

    $noChoices = new FakeTransport();
    $noChoices->setResults([f5Result(200, json_encode(['foo' => 'bar']))]);
    $providerVazio = new OpenRouterProvider(f5ProviderConfig(), $noChoices);
    $erroVazio = null;

    try {
        $providerVazio->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $exception) {
        $erroVazio = $exception;
    }

    assertTrue($erroVazio !== null, 'Resposta sem choices nao lancou excecao');
    assertSame('empty_response', $erroVazio->errorCode());
});

test('F5 Provider: 200 com campo error mapeia para provider_error sanitizado', static function (): void {
    $transport = new FakeTransport();
    $transport->setResults([f5Result(200, json_encode([
        'error' => ['message' => 'Some gateway upstream error details', 'code' => 502],
    ]))]);

    $provider = new OpenRouterProvider(f5ProviderConfig(), $transport);
    $erro = null;

    try {
        $provider->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $exception) {
        $erro = $exception;
    }

    assertTrue($erro !== null, '200 com error nao lancou excecao');
    assertSame('provider_error', $erro->errorCode());
    assertSame(OpenRouterProvider::MSG_PROVIDER_ERROR, $erro->getMessage());
    assertSame('Some gateway upstream error details', $erro->safeContext()['summary']['provider_error'] ?? null);
});

test('F5 Provider: conteudo vazio apenas com reasoning vira reasoning_only', static function (): void {
    $transport = new FakeTransport();
    $transport->setResults([f5Result(200, json_encode([
        'id' => 'gen-reason-f5',
        'model' => 'nex-agi/nex-n2.5-mini:free',
        'choices' => [
            [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => null, 'reasoning' => 'etapas do raciocinio'],
                'finish_reason' => 'stop',
            ],
        ],
    ]))]);
    $provider = new OpenRouterProvider(f5ProviderConfig(['model' => 'nex-agi/nex-n2.5-mini:free']), $transport);
    $erro = null;

    try {
        $provider->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $exception) {
        $erro = $exception;
    }

    assertTrue($erro !== null, 'Conteudo vazio com reasoning nao lancou excecao');
    assertSame('reasoning_only', $erro->errorCode());
    assertSame(OpenRouterProvider::MSG_REASONING_ONLY, $erro->getMessage());
    assertTrue(!empty($erro->safeContext()['summary']['has_reasoning']), 'has_reasoning ausente no diagnostico');
});

test('F5 Provider: finish length com conteúdo vazio/nulo vira truncated_response', static function (): void {
    $transport = new FakeTransport();
    $transport->setResults([f5Result(200, json_encode([
        'id' => 'gen-trunc-f5',
        'model' => 'nex-agi/nex-n2.5-mini:free',
        'choices' => [
            [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => null, 'reasoning' => 'raciocinio em andamento'],
                'finish_reason' => 'length',
            ],
        ],
    ]))]);
    $provider = new OpenRouterProvider(f5ProviderConfig(['model' => 'nex-agi/nex-n2.5-mini:free']), $transport);
    $erro = null;

    try {
        $provider->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $exception) {
        $erro = $exception;
    }

    assertTrue($erro !== null, 'finish length com conteudo nulo deveria lancar excecao');
    assertSame('truncated_response', $erro->errorCode());
    assertSame(OpenRouterProvider::MSG_TRUNCATED_RESPONSE, $erro->getMessage());
    assertSame('length', $erro->safeContext()['summary']['finish_reason'] ?? null);

    $transport->setResults([f5Result(200, json_encode([
        'id' => 'gen-trunc2-f5',
        'model' => 'nex-agi/nex-n2.5-mini:free',
        'choices' => [
            ['index' => 0, 'message' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => 'length'],
        ],
    ]))]);
    $erro2 = null;

    try {
        $provider->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $exception) {
        $erro2 = $exception;
    }

    assertTrue($erro2 !== null && $erro2->errorCode() === 'truncated_response', 'content vazio com finish length deveria virar truncated_response');
});

test('F5 Provider: finish length com conteúdo parcial é sucesso com metadado truncated', static function (): void {
    $transport = new FakeTransport();
    $transport->setResults([f5Result(200, json_encode([
        'id' => 'gen-partial-f5',
        'model' => 'nex-agi/nex-n2.5-mini:free',
        'choices' => [
            [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'A capital do Brasil é Brasília e'],
                'finish_reason' => 'length',
            ],
        ],
    ]))]);
    $provider = new OpenRouterProvider(f5ProviderConfig(['model' => 'nex-agi/nex-n2.5-mini:free']), $transport);

    $response = $provider->chat([['role' => 'user', 'content' => 'oi']]);

    assertTrue($response->isSuccess(), 'Conteúdo parcial com finish length deveria ser sucesso');
    assertSame('A capital do Brasil é Brasília e', $response->content());
    assertSame('length', $response->finishReason());
    assertTrue($response->truncated(), 'Metadado truncated deveria estar marcado');
});

test('F5 Provider: finish stop com conteúdo textual é sucesso normal', static function (): void {
    $transport = new FakeTransport();
    $transport->setResults([f5Result(200, json_encode([
        'id' => 'gen-stop-f5',
        'model' => 'nex-agi/nex-n2.5-mini:free',
        'choices' => [
            [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Resposta final completa.'],
                'finish_reason' => 'stop',
            ],
        ],
    ]))]);
    $provider = new OpenRouterProvider(f5ProviderConfig(['model' => 'nex-agi/nex-n2.5-mini:free']), $transport);

    $response = $provider->chat([['role' => 'user', 'content' => 'oi']]);

    assertTrue($response->isSuccess(), 'Resposta normal deveria ser sucesso');
    assertSame('Resposta final completa.', $response->content());
    assertSame('stop', $response->finishReason());
    assertTrue(!$response->truncated(), 'Resposta completa nao deveria estar marcada como truncated');
});

test('F5 Provider: 404 com "model" mapeia para modelo indisponivel', static function (): void {
    $transport = new FakeTransport();
    $transport->setResults([f5Result(404, f5ErrorBody('No model with id: openrouter/free'))]);

    $provider = new OpenRouterProvider(f5ProviderConfig(), $transport);
    $erro = null;

    try {
        $provider->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $exception) {
        $erro = $exception;
    }

    assertTrue($erro !== null, '404 nao lancou excecao');
    assertSame('model_unavailable', $erro->errorCode());
    assertSame(OpenRouterProvider::MSG_MODEL_UNAVAILABLE, $erro->getMessage());
});

test('F5 Provider: sem modelo configurado e sem chave', static function (): void {
    $provider = new OpenRouterProvider(f5ProviderConfig(['model' => '']), new FakeTransport());
    $erro = null;

    try {
        $provider->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $exception) {
        $erro = $exception;
    }

    assertTrue($erro !== null, 'Falta de modelo nao gerou erro');
    assertSame('model_not_configured', $erro->errorCode());

    $semChave = new OpenRouterProvider(f5ProviderConfig(['api_key' => '']), new FakeTransport());
    $erroChave = null;

    try {
        $semChave->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $exception) {
        $erroChave = $exception;
    }

    assertTrue($erroChave !== null, 'Falta de chave nao gerou erro');
    assertSame('missing_api_key', $erroChave->errorCode());
    assertSame(OpenRouterProvider::MSG_MISSING_KEY, $erroChave->getMessage());
});

test('F5 Provider: retry transitorio refaz a chamada', static function (): void {
    $transport = new FakeTransport();

    $transport->setResults([
        ['status' => 0, 'body' => '', 'error' => 'Operation timed out', 'error_no' => 28, 'headers' => [], 'latency_ms' => 5.0],
        f5Result(200, f5OkBody()),
    ]);

    $provider = new OpenRouterProvider(f5ProviderConfig(['retry_transient' => true, 'retry_count' => 1]), $transport);
    $response = $provider->chat([['role' => 'user', 'content' => 'oi']]);

    assertTrue($response->isSuccess(), 'Retry nao recuperou a chamada');
    assertSame(2, $transport->calls, 'Esperava 2 chamadas (1 falha + 1 retry)');
});

test('F5 Provider: erro nao transitorio nao e reintentado', static function (): void {
    $transport = new FakeTransport();
    $transport->setResults([
        f5Result(401, f5ErrorBody('Invalid API key')),
        f5Result(200, f5OkBody()),
    ]);

    $provider = new OpenRouterProvider(f5ProviderConfig(['retry_transient' => true, 'retry_count' => 1]), $transport);
    $erro = null;

    try {
        $provider->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $exception) {
        $erro = $exception;
    }

    assertTrue($erro !== null && $erro->errorCode() === 'http_401', '401 deveria falhar sem retry');
    assertSame(1, $transport->calls, '401 foi reintentado indevidamente');
});

test('F5 Provider: testConnection usa max_tokens minimizado', static function (): void {
    $transport = new FakeTransport();
    $transport->setResults([f5Result(200, f5OkBody('OK'))]);

    $provider = new OpenRouterProvider(f5ProviderConfig(['max_tokens' => 500]), $transport);
    $response = $provider->testConnection();

    assertTrue($response->isSuccess(), 'Teste de conexao falhou');
    assertSame(64, $transport->lastPayload['max_tokens'], 'testConnection deveria limitar max_tokens');
});

test('F5 Provider: base URL invalida ou host nao permitido e bloqueado', static function (): void {
    $falhou = false;

    try {
        new OpenRouterProvider(f5ProviderConfig(['base_url' => 'https://evil.example.com/api/v1']), new FakeTransport());
    } catch (AIException $exception) {
        $falhou = $exception->errorCode() === 'invalid_base_url';
    }

    assertTrue($falhou, 'Host fora da lista permitida deveria ser bloqueado');

    $falhouHttp = false;

    try {
        new OpenRouterProvider(f5ProviderConfig(['base_url' => 'http://openrouter.ai/api/v1']), new FakeTransport());
    } catch (AIException $exception) {
        $falhouHttp = $exception->errorCode() === 'invalid_base_url';
    }

    assertTrue($falhouHttp, 'Base URL http (sem TLS) deveria ser bloqueada');
});

// ============================= FASE 5 — AIService (banco + transporte fake) =============================

/**
 * Restaura o provider OpenRouter ao estado original do snapshot.
 */
function f5RestoreProvider(array $original): void
{
    $db = Database::connection();
    $stmt = $db->prepare(
        'UPDATE ai_providers
         SET name = ?, model = ?, api_key_encrypted = ?, base_url = ?,
             temperature = ?, max_tokens = ?, timeout_seconds = ?, status = ?, is_default = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $original['name'],
        $original['model'],
        $original['api_key_encrypted'],
        $original['base_url'],
        $original['temperature'],
        $original['max_tokens'],
        $original['timeout_seconds'],
        $original['status'],
        $original['is_default'],
        (int) $original['id'],
    ]);
}

/**
 * Coerce o provider OpenRouter a um estado limpo (inativo, sem chave)
 * independente do estado real do banco, pois os cenarios abaixo partem
 * da premissa de provider inativo/sem chave.
 */
function f5ResetProviderKeyless(): void
{
    $db = Database::connection();
    $db->prepare(
        'UPDATE ai_providers SET status = ?, api_key_encrypted = NULL, is_default = 0 WHERE id = ?'
    )->execute(['inactive', (int) $GLOBALS['f5OriginalRow']['id']]);
}

test('F5 Service: validacao rejeita dados invalidos sem alterar o banco', static function (): void {
    $service = new AIService(null, null, static fn () => new FakeTransport());
    $id = (int) $GLOBALS['f5OriginalRow']['id'];

    $result = $service->save($id, [
        'name' => ' ',
        'model' => 'modelo invalido!!',
        'temperature' => '3.5',
        'max_tokens' => '0',
        'timeout_seconds' => '1',
        'status' => 'active',
    ]);

    assertSame('invalid', $result['status'], 'Validacao deveria falhar');
    assertTrue(isset($result['errors']['name']), 'Erro de nome ausente');
    assertTrue(isset($result['errors']['model']), 'Erro de modelo ausente');
    assertTrue(isset($result['errors']['temperature']), 'Erro de temperatura ausente');
    assertTrue(isset($result['errors']['max_tokens']), 'Erro de max_tokens ausente');
    assertTrue(isset($result['errors']['timeout_seconds']), 'Erro de timeout ausente');

    f5RestoreProvider($GLOBALS['f5OriginalRow']);
});

test('F5 Service: ativar sem API Key e bloqueado', static function (): void {
    f5RestoreProvider($GLOBALS['f5OriginalRow']);
    f5ResetProviderKeyless();
    $service = new AIService(null, null, static fn () => new FakeTransport());
    $id = (int) $GLOBALS['f5OriginalRow']['id'];

    $result = $service->save($id, [
        'name' => 'OpenRouter',
        'model' => 'openrouter/free',
        'temperature' => '0.2',
        'max_tokens' => '1500',
        'timeout_seconds' => '30',
        'status' => 'active',
        'api_key' => '',
    ]);

    assertSame('error', $result['status'], 'Ativacao sem chave deveria falhar');
    assertSame('Configure a API Key do OpenRouter antes de ativar o provider.', $result['message']);

    $row = (new AiProviderRepository())->find($id);
    assertSame('inactive', $row['status'], 'Provider nao deveria ter sido ativado');

    $toggle = $service->toggleStatus($id);
    assertTrue(isset($toggle['error']), 'toggleStatus deveria ter bloqueado a ativacao');

    f5RestoreProvider($GLOBALS['f5OriginalRow']);
});

test('F5 Service: salvar chave criptografa, marca has_key e nunca expoe', static function (): void {
    global $f5TestKey;
    f5RestoreProvider($GLOBALS['f5OriginalRow']);
    $service = new AIService(null, null, static fn () => new FakeTransport());
    $id = (int) $GLOBALS['f5OriginalRow']['id'];

    $result = $service->save($id, [
        'name' => 'OpenRouter',
        'model' => 'openrouter/free',
        'temperature' => '0.2',
        'max_tokens' => '1500',
        'timeout_seconds' => '30',
        'status' => 'inactive',
        'api_key' => $f5TestKey,
    ]);

    assertSame('ok', $result['status'], 'Salvamento falhou: ' . ($result['message'] ?? ''));

    $provider = $service->provider($id);
    assertTrue($provider['has_key'], 'has_key deveria ser true');
    assertTrue(!isset($provider['api_key_encrypted']), 'Payload criptografado vazou para o controller/visao');
    assertTrue(!str_contains((string) $provider['api_key_masked'], $f5TestKey), 'Chave visivel na mascara');
    assertTrue(!in_array($f5TestKey, $provider, true), 'Chave retornada em algum campo do provider');

    $row = (new AiProviderRepository())->find($id);
    assertTrue(str_starts_with((string) $row['api_key_encrypted'], 'v1:'), 'Chave nao criptografada no formato v1:');
    assertSame($f5TestKey, (new EncryptionService())->decrypt((string) $row['api_key_encrypted']), 'Roundtrip da chave falhou');

    f5RestoreProvider($GLOBALS['f5OriginalRow']);
});

test('F5 Service: sem APP_ENCRYPTION_KEY salvar credencial e bloqueado', static function (): void {
    f5RestoreProvider($GLOBALS['f5OriginalRow']);
    f5ResetProviderKeyless();
    $semChave = new EncryptionService('');
    $service = new AIService(null, $semChave, static fn () => new FakeTransport());
    $id = (int) $GLOBALS['f5OriginalRow']['id'];

    $result = $service->save($id, [
        'name' => 'OpenRouter',
        'model' => 'openrouter/free',
        'temperature' => '0.2',
        'max_tokens' => '1500',
        'timeout_seconds' => '30',
        'status' => 'inactive',
        'api_key' => 'sk-sem-chave-mestre',
    ]);

    assertSame('error', $result['status']);
    assertContains('APP_ENCRYPTION_KEY', (string) $result['message']);

    $row = (new AiProviderRepository())->find($id);
    assertTrue($row['api_key_encrypted'] === null || $row['api_key_encrypted'] === '', 'Credencial deveria permanecer nula');

    f5RestoreProvider($GLOBALS['f5OriginalRow']);
});

test('F5 Service: teste de conexao sem provider ativo', static function (): void {
    f5RestoreProvider($GLOBALS['f5OriginalRow']);
    f5ResetProviderKeyless();
    $service = new AIService(null, null, static fn () => new FakeTransport());
    $id = (int) $GLOBALS['f5OriginalRow']['id'];

    $result = $service->test($id);
    assertTrue(isset($result['error_code']) && $result['error_code'] === 'provider_inactive', 'Teste com provider inativo deveria falhar');

    f5RestoreProvider($GLOBALS['f5OriginalRow']);
});

test('F5 Service: teste de conexao com sucesso (transporte fake)', static function (): void {
    global $f5TestKey;
    f5RestoreProvider($GLOBALS['f5OriginalRow']);
    $transport = new FakeTransport();
    $transport->setResults([f5Result(200, f5OkBody('OK'))]);

    $service = new AIService(null, null, static fn () => $transport);
    $id = (int) $GLOBALS['f5OriginalRow']['id'];

    $service->save($id, [
        'name' => 'OpenRouter',
        'model' => 'openrouter/free',
        'temperature' => '0.2',
        'max_tokens' => '1500',
        'timeout_seconds' => '30',
        'status' => 'active',
        'api_key' => $f5TestKey,
        'is_default' => true,
    ]);

    try {
        $result = $service->test($id);

        assertTrue($result['success'], 'Teste deveria ter sucesso: ' . ($result['message'] ?? ''));
        assertSame('OK', $result['content']);
        assertSame('openrouter/free', $result['model']);
        assertSame(1, $transport->calls, 'Esperava exatamente uma chamada de teste');
    } finally {
        f5RestoreProvider($GLOBALS['f5OriginalRow']);
    }
});

test('F5 Service: teste de conexao com 401 (transporte fake)', static function (): void {
    global $f5TestKey;
    f5RestoreProvider($GLOBALS['f5OriginalRow']);
    $transport = new FakeTransport();
    $transport->setResults([f5Result(401, f5ErrorBody('Invalid key'))]);

    $service = new AIService(null, null, static fn () => $transport);
    $id = (int) $GLOBALS['f5OriginalRow']['id'];

    $service->save($id, [
        'name' => 'OpenRouter',
        'model' => 'openrouter/free',
        'temperature' => '0.2',
        'max_tokens' => '1500',
        'timeout_seconds' => '30',
        'status' => 'active',
        'api_key' => $f5TestKey,
    ]);

    try {
        $result = $service->test($id);
        assertTrue(!$result['success'], 'Teste deveria falhar');
        assertSame(OpenRouterProvider::MSG_UNAUTHORIZED, $result['message'], 'Mensagem 401 divergente');
        assertSame('http_401', $result['error_code']);
    } finally {
        f5RestoreProvider($GLOBALS['f5OriginalRow']);
    }
});

test('F5 Service: chat com sucesso e auditoria registrada', static function (): void {
    global $f5TestKey;
    f5RestoreProvider($GLOBALS['f5OriginalRow']);
    $transport = new FakeTransport();
    $transport->setResults([f5Result(200, f5OkBody('Resposta do chat'))]);

    $service = new AIService(null, null, static fn () => $transport);
    $id = (int) $GLOBALS['f5OriginalRow']['id'];

    $service->save($id, [
        'name' => 'OpenRouter',
        'model' => 'openrouter/free',
        'temperature' => '0.2',
        'max_tokens' => '1500',
        'timeout_seconds' => '30',
        'status' => 'active',
        'api_key' => $f5TestKey,
    ]);

    try {
        $result = $service->chat($id, [
            ['role' => 'system', 'content' => 'Voce e um assistente.'],
            ['role' => 'user', 'content' => 'Qual o capital?'],
        ]);

        assertTrue($result['success'], 'Chat falhou: ' . ($result['message'] ?? ''));
        assertSame('Resposta do chat', $result['content']);
        assertSame(2, count($transport->lastPayload['messages']), 'Mensagens nao foram transmitidas');

        $audit = Database::connection()->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'ai.chat_requested'"
        )->fetchColumn();
        assertTrue((int) $audit >= 1, 'Auditoria ai.chat_requested ausente');
    } finally {
        f5RestoreProvider($GLOBALS['f5OriginalRow']);
    }
});

test('F5 Seguranca: chave de teste nao persiste em logs nem auditoria', static function (): void {
    global $f5TestKey;

    foreach (glob((string) config('paths.logs') . '/*.log') ?: [] as $logFile) {
        $content = (string) @file_get_contents($logFile);
        assertTrue(!str_contains($content, $f5TestKey), 'Chave de teste encontrada em ' . basename((string) $logFile));
    }

    $auditCount = Database::connection()->prepare(
        'SELECT COUNT(*) FROM audit_logs WHERE details LIKE ?'
    );
    $auditCount->execute(['%' . $f5TestKey . '%']);
    assertSame(0, (int) $auditCount->fetchColumn(), 'Chave de teste persistiu em audit_logs');
});

test('F5 Limpeza: provider restaurado ao estado original', static function (): void {
    f5RestoreProvider($GLOBALS['f5OriginalRow']);

    $row = (new AiProviderRepository())->find((int) $GLOBALS['f5OriginalRow']['id']);
    assertSame($GLOBALS['f5OriginalRow']['status'], $row['status'], 'Status do openrouter divergente');
    assertSame($GLOBALS['f5OriginalRow']['api_key_encrypted'], $row['api_key_encrypted'], 'Chave do openrouter divergente');
    assertSame($GLOBALS['f5OriginalRow']['is_default'], $row['is_default'], 'Provider padrao divergente');
});

Database::connection()->exec("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action LIKE 'ai.%' OR entity_type = 'ai_provider')");

// helper local (evita conflito de nomes com asserts do harness)
function assertFalseFl(bool $value): void
{
    if ($value) {
        throw new RuntimeException('assertFalseFl esperava false');
    }
}