<?php

declare(strict_types=1);

/**
 * Fase 7A — testes direcionados de B1, B2, B3 e B4 (+ regressão do RAG real).
 *
 * Execução isolada:  php tests/phase7a.php
 * Sem rede: o provider é chamado através de um transporte fake (nenhuma
 * requisição ao OpenRouter). Fixtures: usuário f7a-*@assistente.test, removido
 * pelo TestIsolation junto com suas conversas/mensagens/fontes/auditoria.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/TestIsolation.php';

use App\Core\Database;
use App\Repositories\ConversationRepository;
use App\Repositories\UserRepository;
use App\Services\AI\AIService;
use App\Services\AI\Http\HttpTransportInterface;
use App\Services\Chat\ChatService;
use App\Services\RAG\ContextualRetrievalQueryBuilder;
use App\Services\RAG\RAGService;
use App\Services\RAG\RetrievalService;

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

/**
 * Transporte fake: registra se havia transação aberta NO MOMENTO da chamada
 * ao provider. Nunca expõe cabeçalhos (contêm a Authorization real).
 */
final class Phase7aTransport implements HttpTransportInterface
{
    public int $calls = 0;

    /** @var array<int, bool> */
    public array $inTransactionAtCall = [];

    /** @var array<int, array<string, mixed>> */
    public array $payloads = [];

    /** @var array<int, array<string, mixed>|Throwable> */
    private array $queue = [];

    public function queue(array|Throwable ...$results): void
    {
        $this->queue = $results;
    }

    public function postJson(string $url, array $headers, array $payload, int $connectTimeout, int $totalTimeout): array
    {
        $this->calls++;
        $this->inTransactionAtCall[] = Database::connection()->inTransaction();
        $this->payloads[] = $payload;

        $next = array_shift($this->queue) ?? self::ok('Resposta fake da IA.');

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public static function ok(string $content): array
    {
        return [
            'status' => 200,
            'body' => json_encode([
                'id' => 'gen-f7a',
                'model' => 'fake/model',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ], JSON_UNESCAPED_UNICODE),
            'error' => '',
            'error_no' => 0,
            'headers' => [],
            'latency_ms' => 12.3,
        ];
    }

    public static function httpError(int $status): array
    {
        return [
            'status' => $status,
            'body' => json_encode(['error' => ['message' => 'falha simulada', 'type' => 'error']]),
            'error' => '',
            'error_no' => 0,
            'headers' => [],
            'latency_ms' => 5.0,
        ];
    }
}

/** @return array{rag: RAGService, transport: Phase7aTransport} */
function f7aRag(): array
{
    $transport = new Phase7aTransport();
    $providerId = (int) Database::connection()
        ->query("SELECT id FROM ai_providers WHERE status = 'active' AND is_default = 1 ORDER BY id LIMIT 1")
        ->fetchColumn();

    $ai = new AIService(null, null, static fn () => $transport);
    $rag = new RAGService($ai, null, null, null, static fn () => $providerId > 0 ? $providerId : null);

    return ['rag' => $rag, 'transport' => $transport];
}

function f7aUser(): int
{
    return (new UserRepository())->create([
        'name' => 'F7A Teste',
        'email' => 'f7a-' . bin2hex(random_bytes(4)) . '@assistente.test',
        'password_hash' => password_hash('F7aSenha@123', PASSWORD_DEFAULT),
        'role' => 'tecnico',
        'status' => 'active',
        'must_change_password' => 0,
    ]);
}

/** @return array<string, int> */
function f7aConversationCounts(int $conversationId): array
{
    $db = Database::connection();

    return [
        'user' => (int) $db->query("SELECT COUNT(*) FROM messages WHERE conversation_id = {$conversationId} AND role = 'user'")->fetchColumn(),
        'assistant' => (int) $db->query("SELECT COUNT(*) FROM messages WHERE conversation_id = {$conversationId} AND role = 'assistant'")->fetchColumn(),
        'sources' => (int) $db->query("SELECT COUNT(*) FROM message_sources ms JOIN messages m ON m.id = ms.message_id WHERE m.conversation_id = {$conversationId}")->fetchColumn(),
        'empty_assistant' => (int) $db->query("SELECT COUNT(*) FROM messages WHERE conversation_id = {$conversationId} AND role = 'assistant' AND TRIM(content) = ''")->fetchColumn(),
    ];
}

$userId = f7aUser();
$repo = new ConversationRepository();

$notasProblema = 'As notas estão entrando para todos os alunos menos para um.';
$notasPergunta = 'O aluno está matriculado corretamente?';
$senhaPergunta = 'Como recuperar minha senha no Portal UNIMESTRE?';

// ============================= B1 =============================

test('B1 recentMessages(6) devolve M5..M10 em ordem cronológica', static function () use ($repo, $userId): void {
    $conversationId = $repo->createForUser($userId, 'F7A B1');

    for ($i = 1; $i <= 10; $i++) {
        $repo->addMessage($conversationId, $i % 2 === 1 ? 'user' : 'assistant', 'M' . $i, ['status' => 'ok']);
    }

    $contents = array_column($repo->recentMessages($conversationId, 6), 'content');

    assertSame(['M5', 'M6', 'M7', 'M8', 'M9', 'M10'], $contents, 'Janela de histórico incorreta');
});

test('B1 limite maior que a conversa devolve tudo em ordem; limite 0 devolve vazio', static function () use ($repo, $userId): void {
    $conversationId = $repo->createForUser($userId, 'F7A B1b');

    foreach (['A', 'B', 'C'] as $i => $content) {
        $repo->addMessage($conversationId, $i % 2 === 0 ? 'user' : 'assistant', $content, ['status' => 'ok']);
    }

    assertSame(['A', 'B', 'C'], array_column($repo->recentMessages($conversationId, 6), 'content'));
    assertSame([], $repo->recentMessages($conversationId, 0));
});

// ============================= B2 =============================

test('B2 follow-up "Sim." gera consulta com termos do problema (não só "Sim")', static function () use ($notasProblema, $notasPergunta): void {
    $builder = new ContextualRetrievalQueryBuilder();
    $history = [
        ['role' => 'user', 'content' => $notasProblema],
        ['role' => 'assistant', 'content' => $notasPergunta],
    ];

    $query = $builder->build('Sim.', $history, $notasProblema);
    $terms = explode(' ', $query);

    assertTrue(mb_strtolower(trim($query, " .")) !== 'sim', 'Consulta ficou só "Sim"');
    assertTrue(!in_array('sim', $terms, true), 'Termo de preenchimento "sim" entrou na consulta: ' . $query);
    assertTrue(in_array('notas', $terms, true), 'Termo "notas" do problema ausente: ' . $query);
    assertTrue(in_array('aluno', $terms, true) || in_array('alunos', $terms, true), 'Termo "aluno" ausente: ' . $query);
    assertTrue(count($terms) <= ContextualRetrievalQueryBuilder::MAX_TERMS, 'Consulta excedeu o limite de termos');
});

test('B2 é determinística e sem histórico mantém a pergunta original', static function () use ($notasProblema, $notasPergunta, $senhaPergunta): void {
    $builder = new ContextualRetrievalQueryBuilder();
    $history = [['role' => 'user', 'content' => $notasProblema], ['role' => 'assistant', 'content' => $notasPergunta]];

    assertSame($builder->build('Sim.', $history, $notasProblema), $builder->build('Sim.', $history, $notasProblema));
    assertSame($senhaPergunta, $builder->build($senhaPergunta, []), 'Sem histórico a consulta deve ser a pergunta');
    assertSame($senhaPergunta, $builder->build($senhaPergunta, $history, $notasProblema), 'Pergunta autossuficiente não deve ser contaminada');
});

test('B2 RAGService usa a consulta contextual no retrieval', static function () use ($userId, $notasProblema, $notasPergunta): void {
    $f = f7aRag();
    $history = [['role' => 'user', 'content' => $notasProblema], ['role' => 'assistant', 'content' => $notasPergunta]];

    $f['rag']->answer('Sim.', $userId, null, $history, null, $notasProblema);

    $query = $f['rag']->lastRetrievalQuery();
    assertTrue(str_contains($query, 'notas'), 'RAGService não usou a consulta contextual: ' . $query);
});

// ============================= B3 =============================

test('B3 continuação ("Não.") sem fonte nova segue para a IA, sem no_context', static function () use ($userId): void {
    $f = f7aRag();
    $problema = 'As notas não entram para um aluno específico.';
    $history = [
        ['role' => 'user', 'content' => $problema],
        ['role' => 'assistant', 'content' => 'O aluno aparece normalmente no diário?'],
    ];

    $result = $f['rag']->answer('Não.', $userId, null, $history, null, $problema);

    assertSame(0, $result->retrievalCount(), 'Pré-condição: retrieval deveria ser 0 (consulta: ' . $f['rag']->lastRetrievalQuery() . ')');
    assertTrue(!$result->noContext(), 'Retornou no_context em continuação de conversa');
    assertTrue($result->aiCalled(), 'IA não foi chamada');
    assertSame(1, $f['transport']->calls, 'Provider fake não recebeu a chamada');
    assertTrue($result->isSuccess() && trim((string) $result->answer()) !== '', 'Resposta vazia');
    assertSame([], $result->sources(), 'Não deveria haver fontes');

    $system = (string) ($f['transport']->payloads[0]['messages'][0]['content'] ?? '');
    assertTrue(str_contains($system, 'Nenhuma nova fonte documental foi encontrada para esta mensagem.'), 'Aviso de ausência de fonte não enviado');
    assertTrue(str_contains($system, 'NÃO afirme procedimentos'), 'Regra anti-invenção ausente');

    $roles = array_column($f['transport']->payloads[0]['messages'], 'role');
    assertSame(['system', 'user', 'assistant', 'user'], $roles, 'Histórico não enviado corretamente');
});

test('B3 primeira pergunta sem conhecimento continua protegida (no_context, IA não chamada)', static function () use ($userId): void {
    $f = f7aRag();

    $result = $f['rag']->answer('Como configurar o módulo de biblioteca digital?', $userId, null, [], null, null);

    assertSame(0, $result->retrievalCount(), 'Pré-condição: retrieval deveria ser 0');
    assertTrue($result->noContext(), 'Proteção de falta de conhecimento perdida');
    assertTrue(!$result->aiCalled(), 'IA foi chamada sem contexto');
    assertSame(0, $f['transport']->calls, 'Provider chamado indevidamente');
    assertSame(RAGService::MSG_NO_CONTEXT, $result->answer());
});

test('B3 histórico só com saudação/"ok" não conta como contexto útil', static function () use ($userId): void {
    $f = f7aRag();
    $history = [['role' => 'user', 'content' => 'Oi, bom dia'], ['role' => 'assistant', 'content' => 'Olá! Como posso ajudar?']];

    $result = $f['rag']->answer('Ok.', $userId, null, $history, null, 'Oi, bom dia');

    assertTrue($result->noContext(), 'Saudação tratada como contexto útil');
    assertSame(0, $f['transport']->calls);
});

// ============================= B4 =============================

test('B4 pergunta com fonte: IA chamada FORA de transação e persistência completa', static function () use ($userId, $senhaPergunta): void {
    $f = f7aRag();
    $chat = new ChatService(null, $f['rag']);

    $response = $chat->ask($userId, $senhaPergunta);
    $conversationId = (int) $response['conversation_id'];

    assertSame(1, $f['transport']->calls, 'Provider não chamado');
    assertSame([false], $f['transport']->inTransactionAtCall, 'Havia transação aberta durante a chamada ao provider');
    assertTrue(!Database::connection()->inTransaction(), 'Transação ficou aberta após ask()');
    assertTrue($response['assistant_message_id'] !== null, 'Resposta do assistente não persistida');

    $counts = f7aConversationCounts($conversationId);
    assertSame(1, $counts['user']);
    assertSame(1, $counts['assistant']);
    assertTrue($counts['sources'] >= 1, 'message_sources não persistidas');

    $docs = Database::connection()->query("SELECT DISTINCT ms.document_id FROM message_sources ms JOIN messages m ON m.id = ms.message_id WHERE m.conversation_id = {$conversationId}")->fetchAll(PDO::FETCH_COLUMN);
    assertTrue(in_array('39922', array_map('strval', $docs), true), 'Fonte persistida não é o documento real 39922');

    $GLOBALS['f7aSenhaConversation'] = $conversationId;
});

test('B4 + B3 follow-up "Sim." pelo ChatService: fora de transação, histórico recente, sem fonte órfã', static function () use ($userId): void {
    $conversationId = (int) ($GLOBALS['f7aSenhaConversation'] ?? 0);
    assertTrue($conversationId > 0, 'Pré-condição: conversa do teste anterior');

    $f = f7aRag();
    $chat = new ChatService(null, $f['rag']);
    $before = f7aConversationCounts($conversationId);

    $response = $chat->ask($userId, 'Sim.', $conversationId);

    assertSame([false], $f['transport']->inTransactionAtCall, 'Transação aberta durante a chamada');
    assertTrue(!Database::connection()->inTransaction());
    assertTrue(!str_contains($f['rag']->lastRetrievalQuery(), 'sim'), 'Consulta contextual contém "sim"');
    assertTrue(str_contains($f['rag']->lastRetrievalQuery(), 'senha'), 'Consulta não ancorada no problema original');

    $after = f7aConversationCounts($conversationId);
    assertSame($before['user'] + 1, $after['user']);
    assertSame($before['assistant'] + 1, $after['assistant']);
    assertSame(0, $after['empty_assistant']);
    assertTrue($response['assistant_message_id'] !== null);

    // Histórico enviado = as mensagens anteriores (pergunta + resposta), a atual por último
    $messages = $f['transport']->payloads[0]['messages'];
    assertSame('Sim.', end($messages)['content']);
});

test('B4 falha HTTP do provider: sem transação aberta, só a pergunta, nenhuma resposta/fonte', static function () use ($userId, $senhaPergunta): void {
    $f = f7aRag();
    $f['transport']->queue(Phase7aTransport::httpError(500), Phase7aTransport::httpError(500));
    $chat = new ChatService(null, $f['rag']);

    $response = $chat->ask($userId, $senhaPergunta);
    $conversationId = (int) $response['conversation_id'];

    assertTrue(!$response['result']->isSuccess(), 'Falha simulada não refletida');
    assertTrue(!in_array(true, $f['transport']->inTransactionAtCall, true), 'Transação aberta durante a chamada');
    assertTrue(!Database::connection()->inTransaction(), 'Transação ficou aberta');

    $counts = f7aConversationCounts($conversationId);
    assertSame(['user' => 1, 'assistant' => 0, 'sources' => 0, 'empty_assistant' => 0], $counts);
});

test('B4 exceção no transporte: sem transação aberta, pergunta preservada, nada órfão', static function () use ($userId, $senhaPergunta): void {
    $f = f7aRag();
    $f['transport']->queue(new RuntimeException('queda simulada'), new RuntimeException('queda simulada'));
    $chat = new ChatService(null, $f['rag']);
    $maxBefore = (int) Database::connection()->query('SELECT COALESCE(MAX(id), 0) FROM conversations')->fetchColumn();

    $thrown = false;
    $response = null;

    try {
        $response = $chat->ask($userId, $senhaPergunta);
    } catch (Throwable) {
        $thrown = true;
    }

    assertTrue(!Database::connection()->inTransaction(), 'Transação ficou aberta após exceção');

    $conversationId = $response !== null
        ? (int) $response['conversation_id']
        : (int) Database::connection()->query("SELECT MAX(id) FROM conversations WHERE user_id = {$userId} AND id > {$maxBefore}")->fetchColumn();

    assertTrue($conversationId > 0, 'Pergunta não foi preservada');
    $counts = f7aConversationCounts($conversationId);
    assertSame(1, $counts['user'], 'Pergunta deveria estar preservada');
    assertSame(0, $counts['assistant'], 'Resposta fantasma gravada');
    assertSame(0, $counts['sources'], 'Fonte órfã gravada');
    echo '    [B4 exceção] ' . ($thrown ? 'exceção propagada ao controller (500 genérico)' : 'convertida em falha do provider pelo AIService') . PHP_EOL;
});

// ============================= Regressão RAG real =============================

test('REG retrieval real: "Como recuperar minha senha no Portal UNIMESTRE?" → doc 39922', static function () use ($senhaPergunta): void {
    $search = (new RetrievalService())->search($senhaPergunta);
    $top = $search['results'][0] ?? null;

    assertTrue($top !== null, 'Nenhum resultado');
    assertSame(39922, $top->documentId(), 'Documento incorreto no topo');
    assertTrue(in_array($top->chunkId(), [39917, 39918], true), 'Chunk inesperado: ' . $top->chunkId());
    assertSame('official', $top->sourceType());
    assertSame(2.0, (float) config('knowledge.retrieval.minimum_relevance_score'), 'Threshold alterado');
    echo '    [REG] score=' . $top->finalScore() . ' chunk=' . $top->chunkId() . ' resultados=' . count($search['results']) . PHP_EOL;
});

// ============================= Isolamento =============================

TestIsolation::finish();
$isolation = TestIsolation::verify();
test('ISOLAMENTO: pré-existentes preservados, sem resíduos, provider/usuários restaurados', static function () use ($isolation): void {
    assertTrue($isolation['ok'], json_encode(array_diff_key($isolation, ['pre_counts' => 1, 'post_counts' => 1])));
});

$pass = 0;
$fail = 0;

echo str_repeat('=', 80) . PHP_EOL . 'FASE 7A — B1/B2/B3/B4 + regressão' . PHP_EOL . str_repeat('=', 80) . PHP_EOL;

foreach ($tests as $t) {
    printf("%-4s %s%s\n", $t['pass'] ? 'PASS' : 'FAIL', $t['name'], $t['pass'] ? '' : ' — ' . $t['detail']);
    $t['pass'] ? $pass++ : $fail++;
}

echo str_repeat('=', 80) . PHP_EOL;
printf("TOTAL: %d | PASS: %d | FAIL: %d\n", $pass + $fail, $pass, $fail);

exit($fail > 0 ? 1 : 0);
