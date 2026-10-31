<?php

declare(strict_types=1);

/**
 * Fallback automático entre providers de IA.
 *
 * Execução isolada:  php tests/provider_fallback.php
 * Sem rede: um transporte fake responde por host (api.groq.com,
 * openrouter.ai, generativelanguage.googleapis.com, integrate.api.nvidia.com)
 * com sucesso, 429, 5xx, timeout, 401 ou 400. As chaves reais são apenas
 * descriptografadas em memória e nunca saem do processo. ai_providers é
 * restaurado pelo TestIsolation; a configuração ai.fallback_enabled é
 * restaurada ao final.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/TestIsolation.php';

use App\Core\Database;
use App\Repositories\AiProviderRepository;
use App\Repositories\SystemSettingRepository;
use App\Repositories\UserRepository;
use App\Services\AI\AIService;
use App\Services\AI\Http\HttpTransportInterface;
use App\Services\AI\ProviderErrorClassifier;
use App\Services\Chat\ChatService;
use App\Services\RAG\RAGService;

error_reporting(E_ALL & ~E_DEPRECATED);

TestIsolation::begin();
$settings = new SystemSettingRepository();
$originalFallback = $settings->get('ai.fallback_enabled') ?? '0';
register_shutdown_function(static function () use ($settings, $originalFallback): void {
    $settings->set('ai.fallback_enabled', $originalFallback, 'ai');
    TestIsolation::finish();
});

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

/** Transporte fake roteado por provider (host). */
final class FallbackTransport implements HttpTransportInterface
{
    /** @var array<int, string> providers chamados, em ordem */
    public array $calls = [];

    /** @var array<int, array{provider: string, system: string}> */
    public array $systems = [];

    /** @var array<string, string> provider => comportamento */
    public array $behavior = [];

    public string $content = '{"mode":"answer","answer":"Resposta do fallback [Fonte 1].","source_ids":[1]}';

    private const HOSTS = [
        'api.groq.com' => 'groq',
        'openrouter.ai' => 'openrouter',
        'generativelanguage.googleapis.com' => 'gemini',
        'integrate.api.nvidia.com' => 'nvidia',
    ];

    public function postJson(string $url, array $headers, array $payload, int $connectTimeout, int $totalTimeout): array
    {
        $provider = self::HOSTS[(string) parse_url($url, PHP_URL_HOST)] ?? 'unknown';
        $this->calls[] = $provider;
        $this->systems[] = [
            'provider' => $provider,
            'system' => $provider === 'gemini'
                ? (string) ($payload['systemInstruction']['parts'][0]['text'] ?? '')
                : (string) ($payload['messages'][0]['content'] ?? ''),
        ];

        $mode = $this->behavior[$provider] ?? 'ok';

        return match ($mode) {
            'timeout' => ['status' => 0, 'body' => '', 'error' => 'Operation timed out', 'error_no' => 28, 'headers' => [], 'latency_ms' => 5.0],
            'ok' => ['status' => 200, 'body' => $this->okBody($provider), 'error' => '', 'error_no' => 0, 'headers' => [], 'latency_ms' => 5.0],
            default => ['status' => (int) $mode, 'body' => json_encode(['error' => ['message' => 'falha simulada ' . $mode]]), 'error' => '', 'error_no' => 0, 'headers' => [], 'latency_ms' => 5.0],
        };
    }

    private function okBody(string $provider): string
    {
        if ($provider === 'gemini') {
            return (string) json_encode([
                'candidates' => [['content' => ['parts' => [['text' => $this->content]], 'role' => 'model'], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
                'modelVersion' => 'fake-gemini',
            ]);
        }

        return (string) json_encode([
            'id' => 'gen-fb',
            'model' => 'fake/' . $provider,
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $this->content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
    }
}

$repo = new AiProviderRepository();
$ids = [];

foreach ($repo->all() as $row) {
    $ids[$row['provider']] = (int) $row['id'];
}

foreach (['groq', 'openrouter', 'gemini', 'nvidia'] as $p) {
    if (!isset($ids[$p]) || trim((string) ($repo->find($ids[$p])['api_key_encrypted'] ?? '')) === '') {
        fwrite(STDERR, "Provider {$p} sem chave configurada: cenário não aplicável.\n");
        exit(1);
    }
}

/** Estado conhecido: 4 ativos, Groq padrão, prioridades 1..4, fallback ligado. */
function resetProviders(array $ids, bool $fallback = true): void
{
    $repo = new AiProviderRepository();

    foreach (['groq' => 1, 'openrouter' => 2, 'gemini' => 3, 'nvidia' => 4] as $p => $priority) {
        $repo->update($ids[$p], ['status' => 'active', 'priority' => $priority, 'model' => $repo->find($ids[$p])['model'] ?: 'modelo-teste']);
    }

    $repo->setDefault($ids['groq']);
    (new SystemSettingRepository())->set('ai.fallback_enabled', $fallback ? '1' : '0', 'ai');
}

function service(FallbackTransport $t): AIService
{
    return new AIService(null, null, static fn () => $t);
}

$messages = [
    ['role' => 'system', 'content' => "SISTEMA\n\nCONTEXTO:\n=== FONTE 1 ===\nconteúdo"],
    ['role' => 'user', 'content' => 'Pergunta de teste'],
];

function auditCount(string $action): int
{
    return (int) Database::connection()->query("SELECT COUNT(*) FROM audit_logs WHERE action = '{$action}'")->fetchColumn();
}

// ================================ TESTES ================================
test('Classificação: recuperável × configuração × não recuperável', static function (): void {
    foreach (['timeout', 'connection_error', 'rate_limited', 'http_429', 'upstream_overloaded', 'model_unavailable', 'provider_unavailable', 'empty_response'] as $code) {
        assertSame(ProviderErrorClassifier::RECOVERABLE, ProviderErrorClassifier::classify($code), $code);
    }
    foreach ([500, 502, 503, 504] as $status) {
        assertSame(ProviderErrorClassifier::RECOVERABLE, ProviderErrorClassifier::classify('provider_error', $status), (string) $status);
    }
    assertSame(ProviderErrorClassifier::CONFIGURATION, ProviderErrorClassifier::classify('authentication_error', 401));
    assertSame(ProviderErrorClassifier::CONFIGURATION, ProviderErrorClassifier::classify('missing_api_key'));
    foreach (['invalid_request', 'http_400', 'content_filtered', 'internal_error'] as $code) {
        assertSame(ProviderErrorClassifier::NON_RECOVERABLE, ProviderErrorClassifier::classify($code), $code);
    }
});

test('T1 padrão responde → só ele é chamado, sem fallback nem auditoria', static function () use ($ids, $messages): void {
    resetProviders($ids);
    $t = new FallbackTransport();
    $before = auditCount('ai_provider_fallback');
    $r = service($t)->chatWithFallback($messages, null);
    assertTrue($r['success']);
    assertSame(['groq'], $t->calls);
    assertSame(false, $r['fallback_used']);
    assertSame($before, auditCount('ai_provider_fallback'), 'Auditoria em request normal');
});

test('T2 padrão 429 → segundo provider chamado e resposta dele usada; auditoria registrada', static function () use ($ids, $messages): void {
    resetProviders($ids);
    $t = new FallbackTransport();
    $t->behavior = ['groq' => '429'];
    $before = auditCount('ai_provider_fallback');
    $r = service($t)->chatWithFallback($messages, null);
    assertTrue($r['success']);
    assertSame(['groq', 'openrouter'], $t->calls);
    assertSame('openrouter', $r['final_provider']);
    assertSame('openrouter', $r['provider']);
    assertSame('rate_limited', $r['attempts'][0]['result']);
    assertSame(429, $r['attempts'][0]['http_status']);
    assertSame($before + 1, auditCount('ai_provider_fallback'));
    $details = (string) Database::connection()->query("SELECT details FROM audit_logs WHERE action = 'ai_provider_fallback' ORDER BY id DESC LIMIT 1")->fetchColumn();
    assertTrue(str_contains($details, '"from_provider":"groq"') && str_contains($details, '"to_provider":"openrouter"') && str_contains($details, '"reason":"rate_limited"'), $details);
    assertTrue(!preg_match('/sk-|gsk_|nvapi-|AIza|v1:/', $details), 'Chave na auditoria');
});

test('T3 padrão timeout → fallback', static function () use ($ids, $messages): void {
    resetProviders($ids);
    $t = new FallbackTransport();
    $t->behavior = ['groq' => 'timeout'];
    $r = service($t)->chatWithFallback($messages, null);
    assertTrue($r['success']);
    assertSame(['groq', 'openrouter'], $t->calls);
    assertSame('timeout', $r['attempts'][0]['result']);
});

test('T4 primeiro e segundo falham (503, 500) → terceiro é tentado', static function () use ($ids, $messages): void {
    resetProviders($ids);
    $t = new FallbackTransport();
    $t->behavior = ['groq' => '503', 'openrouter' => '500'];
    $r = service($t)->chatWithFallback($messages, null);
    assertTrue($r['success'], json_encode($r['attempts']));
    assertSame('gemini', $r['final_provider']);
    assertSame(['groq', 'openrouter', 'gemini'], array_values(array_unique($t->calls)));
});

test('T5 provider desativado → pulado', static function () use ($ids, $messages): void {
    resetProviders($ids);
    (new AiProviderRepository())->update($ids['openrouter'], ['status' => 'inactive']);
    $t = new FallbackTransport();
    $t->behavior = ['groq' => '429'];
    $r = service($t)->chatWithFallback($messages, null);
    assertSame(['groq', 'gemini'], $t->calls);
    assertTrue($r['success']);
});

test('T6 provider sem modelo configurado → pulado (não elegível)', static function () use ($ids, $messages): void {
    resetProviders($ids);
    Database::connection()->prepare("UPDATE ai_providers SET model = '' WHERE id = ?")->execute([$ids['openrouter']]);
    $t = new FallbackTransport();
    $t->behavior = ['groq' => '429'];
    $r = service($t)->chatWithFallback($messages, null);
    assertSame(['groq', 'gemini'], $t->calls);
    assertTrue(!in_array('openrouter', array_column($r['attempts'], 'provider'), true));
});

test('T7 todos falham → erro controlado, cada provider uma única vez (sem loop)', static function () use ($ids, $messages): void {
    resetProviders($ids);
    $t = new FallbackTransport();
    $t->behavior = ['groq' => '503', 'openrouter' => 'timeout', 'gemini' => '429', 'nvidia' => '502'];
    $r = service($t)->chatWithFallback($messages, null);
    assertTrue(!$r['success']);
    assertSame(AIService::MSG_ALL_FAILED, $r['message']);
    assertSame(4, count($r['attempts']));
    assertSame(['groq', 'openrouter', 'gemini', 'nvidia'], array_column($r['attempts'], 'provider'));
    $counts = array_count_values($t->calls);
    foreach ($counts as $provider => $n) {
        // retry interno do provider (ex.: 1 nova tentativa curta) é permitido; o fallback nunca volta ao mesmo provider
        assertTrue($n <= 2, "{$provider} chamado {$n} vezes");
    }
    assertTrue(!str_contains(json_encode($r), 'Stack') && !preg_match('/sk-|gsk_|nvapi-|AIza/', json_encode($r)), 'Detalhe sensível no retorno');
});

test('Não recuperável (400) → interrompe o fallback', static function () use ($ids, $messages): void {
    resetProviders($ids);
    $t = new FallbackTransport();
    $t->behavior = ['groq' => '400'];
    $r = service($t)->chatWithFallback($messages, null);
    assertSame(['groq'], $t->calls);
    assertTrue(!$r['success']);
    assertSame(ProviderErrorClassifier::NON_RECOVERABLE, $r['attempts'][0]['class']);
});

test('401 (chave/config) → pula para o próximo, registra alerta e NÃO altera a chave/status', static function () use ($ids, $messages): void {
    resetProviders($ids);
    $keyBefore = (new AiProviderRepository())->find($ids['groq'])['api_key_encrypted'];
    $before = auditCount('ai.provider_config_error');
    $t = new FallbackTransport();
    $t->behavior = ['groq' => '401'];
    $r = service($t)->chatWithFallback($messages, null);
    assertTrue($r['success']);
    assertSame('openrouter', $r['final_provider']);
    assertSame($before + 1, auditCount('ai.provider_config_error'));
    $row = (new AiProviderRepository())->find($ids['groq']);
    assertSame($keyBefore, $row['api_key_encrypted'], 'Chave alterada');
    assertSame('active', $row['status']);
    assertSame(1, (int) $row['is_default'], 'Padrão alterado');
});

test('T12 ordem Groq 1 → OpenRouter 2 → Gemini 3 → NVIDIA 4', static function () use ($ids, $messages): void {
    resetProviders($ids);
    $order = array_column(service(new FallbackTransport())->attemptOrder(), 'provider');
    assertSame(['groq', 'openrouter', 'gemini', 'nvidia'], $order);

    // Prioridade reordenada: padrão continua primeiro; demais pela prioridade.
    $repo = new AiProviderRepository();
    $repo->update($ids['nvidia'], ['priority' => 1]);
    $repo->update($ids['groq'], ['priority' => 4]);
    assertSame(['groq', 'nvidia', 'openrouter', 'gemini'], array_column(service(new FallbackTransport())->attemptOrder(), 'provider'));

    // Padrão desativado: primeiro elegível pela prioridade; padrão salvo não muda.
    $repo->update($ids['groq'], ['status' => 'inactive']);
    assertSame('nvidia', service(new FallbackTransport())->attemptOrder()[0]['provider']);
    assertSame(1, (int) $repo->find($ids['groq'])['is_default']);
});

test('movePriority ↑↓ renumera 1..n', static function () use ($ids): void {
    resetProviders($ids);
    $s = service(new FallbackTransport());
    assertTrue($s->movePriority($ids['gemini'], 'up'));
    assertSame(['groq', 'gemini', 'openrouter', 'nvidia'], array_column($s->priorityList(), 'provider'));
    assertTrue(!$s->movePriority($ids['groq'], 'up'), 'Primeiro não sobe');
});

test('T13 fallback desligado → somente o padrão (comportamento anterior)', static function () use ($ids, $messages): void {
    resetProviders($ids, false);
    $t = new FallbackTransport();
    $t->behavior = ['groq' => '429'];
    $r = service($t)->chatWithFallback($messages, null);
    assertSame(['groq'], $t->calls);
    assertTrue(!$r['success']);

    // RAG com fallback desligado usa o caminho antigo (AIService::chat) — sem campo attempts.
    $t2 = new FallbackTransport();
    $t2->behavior = ['groq' => '429'];
    $rag = new RAGService(service($t2));
    $result = $rag->answer('Como recuperar minha senha no Portal UNIMESTRE?', 1);
    assertSame(['groq'], $t2->calls);
    assertTrue(!$result->isSuccess());
});

// ============================ RAG / diagnóstico ============================
$userId = (new UserRepository())->create([
    'name' => 'FB Teste', 'email' => 'fb-' . bin2hex(random_bytes(4)) . '@assistente.test',
    'password_hash' => password_hash('FbSenha@123', PASSWORD_DEFAULT), 'role' => 'tecnico', 'status' => 'active', 'must_change_password' => 0,
]);

test('T8/T10 RAG com fallback: mesmo contexto/fontes enviado ao próximo provider; saída passa pelo validador', static function () use ($ids, $userId): void {
    resetProviders($ids);
    $t = new FallbackTransport();
    $t->behavior = ['groq' => '503'];
    $t->content = '{"mode":"answer","answer":"Siga [Fonte 1] e [Fonte 9] em https://inventado.test/x","source_ids":[1,9]}';
    $chat = new ChatService(null, new RAGService(service($t)));
    $r = $chat->ask($userId, 'Como recuperar minha senha no Portal UNIMESTRE?');
    $result = $r['result'];

    assertTrue($result->isSuccess());
    assertSame('openrouter', $result->provider());
    assertSame(2, count($t->systems));
    assertSame($t->systems[0]['system'], $t->systems[1]['system'], 'Contexto remontado entre providers');
    assertTrue(str_contains($t->systems[1]['system'], '=== FONTE 1 ==='), 'Fontes ausentes no fallback');
    assertTrue(str_contains($t->systems[1]['system'], 'FORMATO DE SAÍDA'), 'Contrato estruturado ausente');
    $answer = (string) $result->answer();
    assertTrue(!str_contains($answer, 'Fonte 9') && !str_contains($answer, 'inventado.test'), 'Validador não aplicado no fallback: ' . $answer);
    assertSame([1], $result->structured()['source_ids']);
    $stored = Database::connection()->query('SELECT provider, structured_json FROM messages WHERE id = ' . (int) $r['assistant_message_id'])->fetch();
    assertSame('openrouter', $stored['provider']);
    assertTrue($stored['structured_json'] !== null);
});

test('T9 fallback em diagnóstico ativo: memória do diagnóstico preservada', static function () use ($ids, $userId): void {
    resetProviders($ids);
    $t = new FallbackTransport();
    $t->content = json_encode(['mode' => 'diagnostic', 'answer' => 'Vamos por etapas.', 'hypotheses' => [['title' => 'Conta não publicada', 'status' => 'possible']], 'next_question' => 'O título aparece no Gestão?'], JSON_UNESCAPED_UNICODE);
    $chat = new ChatService(null, new RAGService(service($t)));
    $r1 = $chat->ask($userId, 'O boleto do aluno não aparece no Portal.');

    $t->behavior = ['groq' => 'timeout'];
    $t->systems = [];
    $t->calls = [];
    $t->content = json_encode(['mode' => 'diagnostic', 'answer' => 'Ok.', 'hypotheses' => [], 'next_question' => 'A conta está publicada?'], JSON_UNESCAPED_UNICODE);
    $chat->ask($userId, 'Sim, aparece no Gestão.', (int) $r1['conversation_id']);

    assertSame(['groq', 'openrouter'], $t->calls);
    assertTrue(str_contains($t->systems[1]['system'], '=== ESTADO DO DIAGNÓSTICO'), 'Memória perdida no fallback');
    assertSame($t->systems[0]['system'], $t->systems[1]['system']);
    assertTrue(str_contains($t->systems[1]['system'], 'R do usuário: "Sim, aparece no Gestão."'));
});

test('T11 padrão volta a funcionar → próxima requisição recomeça por ele (sem circuit breaker)', static function () use ($ids, $messages): void {
    resetProviders($ids);
    $t = new FallbackTransport();
    $s = service($t);
    $t->behavior = ['groq' => '429'];
    $s->chatWithFallback($messages, null);
    $t->behavior = [];
    $t->calls = [];
    $r = $s->chatWithFallback($messages, null);
    assertSame(['groq'], $t->calls);
    assertSame('groq', $r['final_provider']);
});

test('Orçamento total: tentativa seguinte é limitada pelo tempo restante', static function () use ($ids, $messages): void {
    resetProviders($ids);
    (new SystemSettingRepository())->set('ai.fallback_max_total_seconds', '10', 'ai');
    try {
        $s = service(new FallbackTransport());
        assertSame(10, $s->fallbackBudgetSeconds());
    } finally {
        (new SystemSettingRepository())->set('ai.fallback_max_total_seconds', '90', 'ai');
    }
});

// ================================ Relatório ================================
$passed = count(array_filter($tests, static fn (array $t): bool => $t['pass']));

foreach ($tests as $t) {
    echo ($t['pass'] ? '[OK]   ' : '[FALHA] ') . $t['name'] . ($t['pass'] ? '' : ' — ' . $t['detail']) . PHP_EOL;
}

echo PHP_EOL . "Resultado: {$passed}/" . count($tests) . ' testes OK' . PHP_EOL;

exit($passed === count($tests) ? 0 : 1);
