<?php

declare(strict_types=1);

/**
 * Correção pontual pós-diagnóstico 7.0B: follow-up × pergunta independente,
 * mensagem de falha coerente com as fontes e política de 429/Retry-After.
 *
 * Execução isolada: php tests/fix70b.php — sem rede (transporte fake), sem
 * alterar o documento 39922 nem o provider. Leitura do log "ai" só das linhas
 * escritas por esta execução.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/TestIsolation.php';

use App\Core\Database;
use App\Services\AI\AIException;
use App\Services\AI\AIService;
use App\Services\AI\Http\HttpTransportInterface;
use App\Services\AI\Providers\OpenRouterProvider;
use App\Services\RAG\ContextualRetrievalQueryBuilder;
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

final class FixTransport implements HttpTransportInterface
{
    public int $calls = 0;

    /** @var array<int, array<string, mixed>> */
    private array $queue = [];

    public function queue(array ...$results): void
    {
        $this->queue = $results;
    }

    public function postJson(string $url, array $headers, array $payload, int $connectTimeout, int $totalTimeout): array
    {
        $this->calls++;

        return array_shift($this->queue) ?? self::ok('Resposta fake.');
    }

    public static function ok(string $content): array
    {
        return ['status' => 200, 'body' => json_encode([
            'id' => 'gen-fix', 'model' => 'fake/model',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]), 'error' => '', 'error_no' => 0, 'headers' => [], 'latency_ms' => 5.0];
    }

    public static function http(int $status, array $headers = []): array
    {
        return ['status' => $status, 'body' => json_encode(['error' => ['message' => 'Provider returned error', 'code' => $status]]),
            'error' => '', 'error_no' => 0, 'headers' => $headers, 'latency_ms' => 5.0];
    }
}

/** @return array{rag: RAGService, transport: FixTransport} */
function fixRag(): array
{
    $transport = new FixTransport();
    $providerId = (int) Database::connection()
        ->query("SELECT id FROM ai_providers WHERE status = 'active' AND is_default = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $rag = new RAGService(new AIService(null, null, static fn () => $transport), null, null, null, static fn () => $providerId > 0 ? $providerId : null);

    return ['rag' => $rag, 'transport' => $transport];
}

function fixProvider(FixTransport $transport): OpenRouterProvider
{
    return new OpenRouterProvider([
        'api_key' => 'chave-ficticia-de-teste',
        'model' => 'fix/modelo-teste:free',
        'base_url' => 'https://openrouter.ai/api/v1',
        'retry_transient' => true,
        'retry_count' => 1,
        'timeout_seconds' => 10,
    ], $transport);
}

/** Linhas do log "ai" escritas depois do offset informado. */
function fixAiLogSince(int $offset): string
{
    $file = config('paths.logs') . '/ai.log';
    clearstatcache();

    return is_file($file) ? (string) file_get_contents($file, false, null, $offset) : '';
}

function fixAiLogOffset(): int
{
    $file = config('paths.logs') . '/ai.log';
    clearstatcache();

    return is_file($file) ? (int) filesize($file) : 0;
}

const FIX_SENHA = 'Como recuperar minha senha no Portal UNIMESTRE?';
const FIX_NOTAS = 'Um professor lançou as notas, mas a média final do aluno não foi calculada. O que devo verificar?';

$senhaHistory = [
    ['role' => 'user', 'content' => FIX_SENHA],
    ['role' => 'assistant', 'content' => 'Clique em "Esqueci minha senha", confirme seus dados e use a opção "Alterar senha" do e-mail. A solicitação dura 1 dia.'],
];

// ============================= Regra de follow-up =============================

test('Regra: follow-ups reais são reconhecidos', static function () use ($senhaHistory): void {
    $b = new ContextualRetrievalQueryBuilder();

    foreach ([
        'Por quanto tempo essa solicitação é válida?', 'Por quanto tempo isso é válido?', 'E onde fica essa opção?',
        'Não encontrei.', 'Qual parâmetro?', 'E depois?', 'Como faço isso?', 'Ele não aparece.',
        'Isso vale para aluno também?', 'Sim.',
    ] as $message) {
        assertTrue($b->isFollowUp($message, $senhaHistory), 'Não reconhecido como follow-up: ' . $message);
    }
});

test('Regra: pergunta completa e independente NÃO é follow-up, mesmo com histórico', static function () use ($senhaHistory): void {
    $b = new ContextualRetrievalQueryBuilder();

    assertTrue(!$b->isFollowUp(FIX_NOTAS, $senhaHistory), 'Pergunta de notas tratada como follow-up');
    assertTrue(!$b->isFollowUp(FIX_SENHA, $senhaHistory), 'Pergunta de senha completa tratada como follow-up');
    assertTrue(!$b->isFollowUp('Não.', []), 'Sem histórico nada é follow-up');
    assertSame(FIX_NOTAS, $b->build(FIX_NOTAS, $senhaHistory, FIX_SENHA), 'Pergunta independente foi contaminada pelo histórico');
});

// ============================= Cenário A =============================

test('A: pergunta independente sem conhecimento → sem IA, sem 39922, conhecimento insuficiente', static function () use ($senhaHistory): void {
    $f = fixRag();
    $result = $f['rag']->answer(FIX_NOTAS, 1, null, $senhaHistory, null, FIX_SENHA);

    assertSame(0, $result->retrievalCount(), 'retrieval_count deveria ser 0');
    assertTrue(!$result->aiCalled(), 'IA foi chamada');
    assertSame(0, $f['transport']->calls, 'Provider recebeu chamada');
    assertTrue($result->noContext(), 'no_context esperado');
    assertSame(RAGService::MSG_NO_CONTEXT, $result->answer());
    assertTrue(!in_array(39922, array_map(static fn ($s) => (int) ($s['document_id'] ?? 0), $result->sources()), true), 'Doc 39922 nas fontes');
});

// ============================= Cenário B =============================

test('B: follow-up "Por quanto tempo essa solicitação é válida?" usa o contexto e chama a IA', static function () use ($senhaHistory): void {
    $f = fixRag();
    $result = $f['rag']->answer('Por quanto tempo essa solicitação é válida?', 1, null, $senhaHistory, null, FIX_SENHA);
    $query = $f['rag']->lastRetrievalQuery();

    assertTrue(str_contains($query, 'senha'), 'Consulta não ancorada no problema original: ' . $query);
    assertTrue($result->aiCalled() && $f['transport']->calls === 1, 'IA não chamada no follow-up');
    assertTrue(!$result->noContext(), 'Follow-up encerrado como no_context');

    $docs = array_map(static fn ($s) => (int) ($s['document_id'] ?? 0), $result->sources());
    echo '    [B] consulta: ' . $query . ' | retrieval_count=' . $result->retrievalCount() . ' | docs=' . json_encode($docs) . PHP_EOL;
    assertTrue(in_array(39922, $docs, true), 'Doc 39922 não recuperado pelo follow-up contextual');
});

// ============================= Cenários C e D =============================

test('C: falha do provider COM fonte → pode dizer que encontrou conteúdo relacionado', static function (): void {
    $f = fixRag();
    $f['transport']->queue(FixTransport::http(500), FixTransport::http(500));
    $result = $f['rag']->answer(FIX_SENHA, 1, null, [], null, null);

    assertTrue(!$result->isSuccess() && $result->retrievalCount() > 0, 'Pré-condição: falha com fonte');
    assertSame(RAGService::MSG_AI_UNAVAILABLE, RAGService::failureMessage('provider_failure', $result));
});

test('D: falha do provider SEM fonte → NÃO afirma conteúdo relacionado', static function () use ($senhaHistory): void {
    $f = fixRag();
    $f['transport']->queue(FixTransport::http(500), FixTransport::http(500));
    $notasHistory = [
        ['role' => 'user', 'content' => 'As notas não entram para um aluno específico.'],
        ['role' => 'assistant', 'content' => 'O aluno aparece normalmente no diário?'],
    ];
    $result = $f['rag']->answer('Não.', 1, null, $notasHistory, null, 'As notas não entram para um aluno específico.');

    assertTrue(!$result->isSuccess() && $result->retrievalCount() === 0 && $result->aiCalled(), 'Pré-condição: falha sem fonte com IA chamada');
    $message = RAGService::failureMessage('provider_failure', $result);
    assertSame(RAGService::MSG_AI_UNAVAILABLE_NO_SOURCE, $message);
    assertTrue(!str_contains($message, 'Encontrei conteúdo'), 'Mensagem afirma conteúdo inexistente');
    assertSame(RAGService::MSG_PROVIDER_UNAVAILABLE, RAGService::failureMessage('provider_unavailable', $result));
});

// ============================= Cenário E — 429 =============================

test('E1: 429 sem Retry-After → uma única tentativa, http_429, modelo no log', static function (): void {
    $t = new FixTransport();
    $t->queue(FixTransport::http(429), FixTransport::ok('não deveria chegar aqui'));
    $offset = fixAiLogOffset();
    $error = null;

    try {
        fixProvider($t)->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $e) {
        $error = $e;
    }

    assertTrue($error !== null, '429 não lançou erro');
    assertSame('http_429', $error->errorCode());
    assertSame(1, $t->calls, 'Houve nova tentativa imediata');
    assertSame(1, $error->safeContext()['attempts'] ?? null, 'attempts ausente');
    $log = fixAiLogSince($offset);
    assertTrue(str_contains($log, '"model":"fix/modelo-teste:free"') && str_contains($log, '"http_status":429'), 'Modelo/status ausentes no log');
    assertTrue(str_contains($log, '"attempt":1'), 'attempt ausente no log');
    assertTrue(!str_contains($log, 'chave-ficticia-de-teste') && stripos($log, 'authorization') === false, 'Segredo no log');
});

test('E2: 429 com Retry-After alto (30s) → sem nova tentativa, Retry-After registrado', static function (): void {
    $t = new FixTransport();
    $t->queue(FixTransport::http(429, ['retry-after' => '30']), FixTransport::ok('não deveria chegar aqui'));
    $offset = fixAiLogOffset();
    $started = microtime(true);
    $error = null;

    try {
        fixProvider($t)->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $e) {
        $error = $e;
    }

    assertSame(1, $t->calls, 'Nova tentativa com Retry-After alto');
    assertSame(30, $error->safeContext()['retry_after_seconds'] ?? null, 'Retry-After não propagado');
    assertTrue(microtime(true) - $started < 1.5, 'Requisição ficou esperando');
    assertTrue(str_contains(fixAiLogSince($offset), '"retry_after_seconds":30'), 'Retry-After não registrado');
});

test('E3: 429 com Retry-After curto (1s) → exatamente uma nova tentativa controlada', static function (): void {
    $t = new FixTransport();
    $t->queue(FixTransport::http(429, ['retry-after' => '1']), FixTransport::ok('ok após espera'));
    $started = microtime(true);

    $response = fixProvider($t)->chat([['role' => 'user', 'content' => 'oi']]);
    $elapsed = microtime(true) - $started;

    assertTrue($response->isSuccess(), 'Nova tentativa não recuperou');
    assertSame(2, $t->calls, 'Esperava exatamente 2 chamadas');
    assertTrue($elapsed >= 0.9 && $elapsed < 3.0, 'Espera não respeitou Retry-After: ' . round($elapsed, 2) . 's');
});

test('E4: Retry-After inválido é ignorado (sanitização)', static function (): void {
    $t = new FixTransport();
    $t->queue(FixTransport::http(429, ['retry-after' => 'abc; drop']), FixTransport::ok('não deveria chegar aqui'));

    try {
        fixProvider($t)->chat([['role' => 'user', 'content' => 'oi']]);
    } catch (AIException $e) {
        $context = $e->safeContext();
        assertTrue(array_key_exists('retry_after_seconds', $context), 'Chave retry_after_seconds ausente');
        assertSame(null, $context['retry_after_seconds'], 'Valor inválido não foi descartado');
    }

    assertSame(1, $t->calls);
});

test('E5: via RAG, falha 429 registra model_requested e Retry-After no log rag', static function (): void {
    $f = fixRag();
    $f['transport']->queue(FixTransport::http(429, ['retry-after' => '45']));
    $ragLog = config('paths.logs') . '/rag.log';
    clearstatcache();
    $offset = is_file($ragLog) ? (int) filesize($ragLog) : 0;

    $result = $f['rag']->answer(FIX_SENHA, 1, null, [], null, null);
    clearstatcache();
    $log = (string) file_get_contents($ragLog, false, null, $offset);
    $model = (string) Database::connection()->query('SELECT model FROM ai_providers WHERE is_default = 1 LIMIT 1')->fetchColumn();

    assertSame('http_429', $result->errorCode());
    assertSame(1, $f['transport']->calls, 'Nova tentativa via RAG');
    assertTrue(str_contains($log, '"model_requested":' . json_encode($model, JSON_UNESCAPED_SLASHES)), 'model_requested ausente no log rag');
    assertTrue(str_contains($log, '"retry_after_seconds":45'), 'Retry-After ausente no log rag');
});

// ============================= Isolamento =============================

TestIsolation::finish();
$isolation = TestIsolation::verify();
test('ISOLAMENTO: pré-existentes preservados, sem resíduos', static function () use ($isolation): void {
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
