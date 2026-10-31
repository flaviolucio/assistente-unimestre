<?php

declare(strict_types=1);

/**
 * Estabilização final 7.0B — histórico enviado à IA sem perguntas órfãs.
 * Execução isolada: php tests/fix70b2.php (sem rede; transporte fake).
 * Fixtures: usuário/conversas f7b2-*@assistente.test (TestIsolation).
 * A conversa real 482 NÃO é tocada: o padrão dela é reproduzido numa fixture.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/TestIsolation.php';

use App\Core\Database;
use App\Repositories\ConversationRepository;
use App\Repositories\UserRepository;
use App\Services\AI\AIService;
use App\Services\AI\Http\HttpTransportInterface;
use App\Services\Chat\ChatService;
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

final class Fix2Transport implements HttpTransportInterface
{
    public int $calls = 0;

    /** @var array<int, array<string, mixed>> */
    public array $payloads = [];

    public ?array $next = null;

    public function postJson(string $url, array $headers, array $payload, int $connectTimeout, int $totalTimeout): array
    {
        $this->calls++;
        $this->payloads[] = $payload;

        if ($this->next !== null) {
            $r = $this->next;
            $this->next = null;

            return $r;
        }

        return ['status' => 200, 'body' => json_encode([
            'id' => 'gen-fix2', 'model' => 'fake/model',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'A solicitação é válida por 1 dia [Fonte 1].'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]), 'error' => '', 'error_no' => 0, 'headers' => [], 'latency_ms' => 5.0];
    }
}

/** @return array{chat: ChatService, rag: RAGService, transport: Fix2Transport} */
function fix2Chat(): array
{
    $transport = new Fix2Transport();
    $providerId = (int) Database::connection()
        ->query("SELECT id FROM ai_providers WHERE status = 'active' AND is_default = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $rag = new RAGService(new AIService(null, null, static fn () => $transport), null, null, null, static fn () => $providerId > 0 ? $providerId : null);

    return ['chat' => new ChatService(null, $rag), 'rag' => $rag, 'transport' => $transport];
}

$userId = (new UserRepository())->create([
    'name' => 'F7B2 Teste',
    'email' => 'f7b2-' . bin2hex(random_bytes(4)) . '@assistente.test',
    'password_hash' => password_hash('F7b2Senha@123', PASSWORD_DEFAULT),
    'role' => 'tecnico',
    'status' => 'active',
    'must_change_password' => 0,
]);
$repo = new ConversationRepository();

const F2_SENHA = 'Como recuperar minha senha no Portal UNIMESTRE?';
const F2_RESP = 'Para recuperar a senha no Portal UNIMESTRE, siga o procedimento abaixo: clique em "Esqueci minha senha"...';
const F2_NOTAS = 'Um professor lançou as notas, mas a média final do aluno não foi calculada. O que devo verificar?';
const F2_FOLLOW = 'Por quanto tempo essa solicitação é válida';

/** Reproduz o padrão da conversa 482: 1 turno completo + 3 perguntas órfãs. */
function fix2Conversation482Pattern(ConversationRepository $repo, int $userId): int
{
    $id = $repo->createForUser($userId, 'F7B2 padrão 482');
    $repo->addMessage($id, 'user', F2_SENHA, ['status' => 'ok']);
    $repo->addMessage($id, 'assistant', F2_RESP, ['status' => 'ok']);
    $repo->addMessage($id, 'user', F2_NOTAS, ['status' => 'ok']);
    $repo->addMessage($id, 'user', F2_FOLLOW, ['status' => 'ok']);
    $repo->addMessage($id, 'user', F2_FOLLOW, ['status' => 'ok']);

    return $id;
}

// ============================= B / C — repositório =============================

test('B: USER/ASSISTANT completo + USER órfão → órfão fora do contexto', static function () use ($repo, $userId): void {
    $id = $repo->createForUser($userId, 'F7B2 B');
    $repo->addMessage($id, 'user', 'U1', ['status' => 'ok']);
    $repo->addMessage($id, 'assistant', 'A1', ['status' => 'ok']);
    $repo->addMessage($id, 'user', 'U2 órfã', ['status' => 'ok']);

    assertSame(['U1', 'A1'], array_column($repo->recentCompletedTurns($id, 6), 'content'));
});

test('C: vários USER órfãos (padrão 482) → nenhum entra; turno completo preservado', static function () use ($repo, $userId): void {
    $id = fix2Conversation482Pattern($repo, $userId);
    $turns = $repo->recentCompletedTurns($id, 6);

    assertSame([F2_SENHA, F2_RESP], array_column($turns, 'content'), 'Órfã entrou no contexto');
    assertSame(['user', 'assistant'], array_column($turns, 'role'));
    // Nada apagado: as 5 mensagens continuam no banco/histórico visual
    assertSame(5, count($repo->messagesWithSources($id)), 'Mensagem apagada do histórico visual');
});

test('C2: órfã entre turnos completos é descartada e os turnos seguem pareados', static function () use ($repo, $userId): void {
    $id = $repo->createForUser($userId, 'F7B2 C2');
    foreach ([['user', 'U1'], ['assistant', 'A1'], ['user', 'U2 órfã'], ['user', 'U3'], ['assistant', 'A3']] as [$role, $text]) {
        $repo->addMessage($id, $role, $text, ['status' => 'ok']);
    }

    assertSame(['U1', 'A1', 'U3', 'A3'], array_column($repo->recentCompletedTurns($id, 6), 'content'));
    assertSame(['U3', 'A3'], array_column($repo->recentCompletedTurns($id, 2), 'content'), 'Limite não aplicado sobre turnos completos');
});

// ============================= D — follow-up real sobre o padrão 482 =============================

test('D: follow-up após órfãs → contexto da IA = 877/878 + atual; consulta de senha; doc 39922', static function () use ($repo, $userId): void {
    $id = fix2Conversation482Pattern($repo, $userId);
    $f = fix2Chat();

    $response = $f['chat']->ask($userId, 'Por quanto tempo essa solicitação é válida?', $id);

    assertSame(1, $f['transport']->calls, 'IA não chamada');
    $messages = $f['transport']->payloads[0]['messages'];
    $sent = array_map(static fn ($m) => $m['role'] . ':' . $m['content'], array_slice($messages, 1));

    assertSame([
        'user:' . F2_SENHA,
        'assistant:' . F2_RESP,
        'user:Por quanto tempo essa solicitação é válida?',
    ], $sent, 'Histórico enviado à IA incorreto');

    foreach ($messages as $m) {
        assertTrue(!str_contains((string) $m['content'], 'média final'), 'Pergunta órfã de notas foi enviada à IA');
    }

    $query = $f['rag']->lastRetrievalQuery();
    assertTrue(str_contains($query, 'senha') && !str_contains($query, 'notas'), 'Consulta não ancorada em senha: ' . $query);

    $docs = array_map(static fn ($s) => (int) ($s['document_id'] ?? 0), $response['result']->sources());
    assertTrue(in_array(39922, $docs, true), 'Doc 39922 não recuperado');
    echo '    [D] consulta: ' . $query . ' | docs=' . json_encode($docs) . PHP_EOL;
});

// ============================= E — resposta normal =============================

test('E: resposta normal continua gravando assistant + fontes e entra no próximo contexto', static function () use ($repo, $userId): void {
    $f = fix2Chat();
    $first = $f['chat']->ask($userId, F2_SENHA);
    $id = (int) $first['conversation_id'];

    assertTrue($first['assistant_message_id'] !== null, 'Resposta não gravada');
    $counts = Database::connection()->query("SELECT COUNT(*) FROM message_sources ms JOIN messages m ON m.id = ms.message_id WHERE m.conversation_id = {$id}")->fetchColumn();
    assertTrue((int) $counts >= 1, 'Fontes não gravadas');

    $second = $f['chat']->ask($userId, 'E onde fica essa opção?', $id);
    assertTrue($second['assistant_message_id'] !== null, 'Segunda resposta não gravada');
    $sent = array_column(array_slice($f['transport']->payloads[1]['messages'], 1), 'role');
    assertSame(['user', 'assistant', 'user'], $sent, 'Turno completo anterior não enviado');
});

test('E2: provider_failure não cria assistant_message (só a pergunta)', static function () use ($userId): void {
    $f = fix2Chat();
    $f['transport']->next = ['status' => 429, 'body' => json_encode(['error' => ['message' => 'Provider returned error']]), 'error' => '', 'error_no' => 0, 'headers' => [], 'latency_ms' => 5.0];

    $response = $f['chat']->ask($userId, F2_SENHA);
    $id = (int) $response['conversation_id'];

    assertSame(null, $response['assistant_message_id']);
    assertSame(1, $f['transport']->calls, 'Retry automático');
    $roles = Database::connection()->query("SELECT role FROM messages WHERE conversation_id = {$id}")->fetchAll(PDO::FETCH_COLUMN);
    assertSame(['user'], $roles, 'Mensagem falsa de assistente gravada');
});

// ============================= Isolamento =============================

TestIsolation::finish();
$isolation = TestIsolation::verify();
test('ISOLAMENTO: pré-existentes preservados (inclui conversa 482), sem resíduos', static function () use ($isolation): void {
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
