<?php

declare(strict_types=1);

/**
 * Etapa 7.2 — sessões de diagnóstico, hipóteses persistidas, perguntas
 * progressivas, atualização de status e memória do diagnóstico.
 *
 * Execução isolada:  php tests/phase7_2.php
 * Sem rede: provider via transporte fake com respostas JSON determinísticas.
 * Fixtures (usuário f72-*@assistente.test + conversas) removidas pelo
 * TestIsolation; sessões/hipóteses/eventos saem em CASCADE com a conversa.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/TestIsolation.php';

use App\Core\Database;
use App\Repositories\UserRepository;
use App\Services\AI\AIService;
use App\Services\AI\Http\HttpTransportInterface;
use App\Services\Chat\ChatService;
use App\Services\Copilot\CopilotDiagnosticService;
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

final class Phase72Transport implements HttpTransportInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $payloads = [];

    /** @var array<int, string> */
    private array $queue = [];

    public function push(array|string $content): void
    {
        $this->queue[] = is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE) : $content;
    }

    public function lastSystem(): string
    {
        $payload = end($this->payloads);

        return (string) ($payload['messages'][0]['content'] ?? '');
    }

    public function postJson(string $url, array $headers, array $payload, int $connectTimeout, int $totalTimeout): array
    {
        $this->payloads[] = $payload;
        $content = array_shift($this->queue) ?? '{"mode":"answer","answer":"ok"}';

        return [
            'status' => 200,
            'body' => json_encode([
                'id' => 'gen-f72',
                'model' => 'fake/model',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ], JSON_UNESCAPED_UNICODE),
            'error' => '',
            'error_no' => 0,
            'headers' => [],
            'latency_ms' => 10.0,
        ];
    }
}

$userId = (new UserRepository())->create([
    'name' => 'F72 Teste',
    'email' => 'f72-' . bin2hex(random_bytes(4)) . '@assistente.test',
    'password_hash' => password_hash('F72Senha@123', PASSWORD_DEFAULT),
    'role' => 'tecnico',
    'status' => 'active',
    'must_change_password' => 0,
]);

$transport = new Phase72Transport();
$providerId = (int) Database::connection()
    ->query("SELECT id FROM ai_providers WHERE status = 'active' AND is_default = 1 ORDER BY id LIMIT 1")
    ->fetchColumn();
$chat = new ChatService(
    null,
    new RAGService(new AIService(null, null, static fn () => $transport), null, null, null, static fn () => $providerId > 0 ? $providerId : 1)
);
$diagnostics = new CopilotDiagnosticService();

const Q1 = 'O título aparece normalmente no Gestão Online, mas não aparece somente no Portal?';
const Q2 = 'A conta financeira do título está marcada para exibição no Portal?';

function sessionsOf(int $conversationId): array
{
    return Database::connection()->query('SELECT * FROM diagnostic_sessions WHERE conversation_id = ' . $conversationId . ' ORDER BY id')->fetchAll();
}

/** @return array<string, array<string, mixed>> hipóteses por título */
function hypothesesOf(int $sessionId): array
{
    $out = [];

    foreach ((new CopilotDiagnosticService())->repository()->hypotheses($sessionId) as $h) {
        $out[$h['title']] = $h;
    }

    return $out;
}

function boletoTurn1(): array
{
    return [
        'mode' => 'diagnostic',
        'answer' => 'Vamos verificar por etapas.',
        'hypotheses' => [
            ['title' => 'Conta financeira não publicada no Portal', 'status' => 'possible', 'reason' => '', 'source_ids' => []],
            ['title' => 'Título não gerado corretamente', 'status' => 'possible', 'reason' => '', 'source_ids' => []],
            ['title' => 'Erro de remessa bancária', 'status' => 'possible', 'reason' => '', 'source_ids' => []],
        ],
        'next_question' => Q1,
        'resolved' => false,
    ];
}

$state = [];

// ================================ TESTE 1 ================================
test('T1 pergunta simples → resposta direta, nenhuma diagnostic_session', static function () use ($chat, $transport, $userId): void {
    $transport->push(['mode' => 'answer', 'answer' => 'Use "Esqueci minha senha" [Fonte 1].', 'hypotheses' => [], 'next_question' => null, 'source_ids' => [1]]);
    $r = $chat->ask($userId, 'Como recuperar minha senha?');

    assertTrue($r['result']->isSuccess(), 'Falha no RAG');
    assertSame([], sessionsOf((int) $r['conversation_id']), 'Sessão criada para pergunta simples');
    assertSame(null, $r['diagnostic']);
});

test('T1b modo diagnostic sem hipótese nem pergunta não abre sessão', static function () use ($chat, $transport, $userId): void {
    $transport->push(['mode' => 'diagnostic', 'answer' => 'Informação geral.', 'hypotheses' => [], 'next_question' => null]);
    $r = $chat->ask($userId, 'Como recuperar minha senha no portal?');
    assertSame([], sessionsOf((int) $r['conversation_id']));
});

// ================================ TESTE 2 ================================
test('T2 problema → cria sessão, hipóteses e next_question', static function () use ($chat, $transport, $userId, &$state): void {
    $transport->push(boletoTurn1());
    $r = $chat->ask($userId, 'O boleto não aparece no Portal.');

    if (!$r['result']->aiCalled()) {
        throw new RuntimeException('Base sem fonte para boleto/portal: IA não chamada (no_context)');
    }

    $sessions = sessionsOf((int) $r['conversation_id']);
    assertSame(1, count($sessions));
    assertSame('open', $sessions[0]['status']);
    assertSame('collecting_context', $sessions[0]['current_stage']);
    assertSame('O boleto não aparece no Portal.', $sessions[0]['original_problem']);
    assertSame(3, count(hypothesesOf((int) $sessions[0]['id'])));
    assertSame(Q1, $r['diagnostic']['next_question']);
    assertSame('possible', $r['diagnostic']['hypotheses'][0]['status']);
    assertSame('Possível', $r['diagnostic']['hypotheses'][0]['status_label']);

    $state['conv'] = (int) $r['conversation_id'];
    $state['session'] = (int) $sessions[0]['id'];
});

// ============================ TESTE 3 / 4 / 5 ============================
test('T3/T4/T5 resposta do usuário → mesma sessão, status atualizados, pergunta nova', static function () use ($chat, $transport, $userId, &$state): void {
    $h = hypothesesOf($state['session']);
    $transport->push([
        'mode' => 'diagnostic',
        'answer' => 'Certo. Isso enfraquece a hipótese de o título não ter sido gerado.',
        'hypotheses' => [
            ['id' => 'h' . $h['Título não gerado corretamente']['id'], 'title' => 'Título não gerado corretamente', 'status' => 'weakened', 'reason' => 'O usuário informou que o título existe no Gestão.'],
            ['id' => 'h' . $h['Conta financeira não publicada no Portal']['id'], 'title' => 'Conta financeira não publicada no Portal', 'status' => 'supported', 'reason' => 'Cadastro de contas controla a exibição [Fonte 1]', 'source_ids' => [1]],
            ['id' => 'h' . $h['Erro de remessa bancária']['id'], 'title' => 'Erro de remessa bancária', 'status' => 'rejected', 'reason' => 'Remessa não afeta a exibição de título já gerado no Gestão.'],
        ],
        'next_question' => Q2,
    ]);

    $r = $chat->ask($userId, 'O título aparece no Gestão.', $state['conv']);

    $sessions = sessionsOf($state['conv']);
    assertSame(1, count($sessions), 'Sessão nova criada');
    assertSame($state['session'], (int) $sessions[0]['id']);
    assertSame('investigating', $sessions[0]['status']);
    assertSame('testing_hypotheses', $sessions[0]['current_stage']);

    $after = hypothesesOf($state['session']);
    assertSame(3, count($after), 'Hipótese duplicada em vez de atualizada');
    assertSame('weakened', $after['Título não gerado corretamente']['status'], 'T4');
    assertSame('rejected', $after['Erro de remessa bancária']['status'], 'T4');
    assertSame('supported', $after['Conta financeira não publicada no Portal']['status'], 'T5');
    assertTrue(str_contains((string) $after['Título não gerado corretamente']['evidence_summary'], 'O título aparece no Gestão'), 'Evidência sem a resposta do usuário');
    assertTrue(($after['Conta financeira não publicada no Portal']['metadata']['source_refs'] ?? []) !== [], 'source_refs não gravado');

    assertSame(Q2, $r['diagnostic']['next_question']);
    assertTrue($r['diagnostic']['next_question'] !== Q1, 'Pergunta repetida');

    $questions = json_decode((string) $sessions[0]['questions_json'], true);
    assertSame(2, count($questions));
    assertSame('O título aparece no Gestão.', $questions[0]['answer']);

    $events = (int) Database::connection()->query('SELECT COUNT(*) FROM diagnostic_hypothesis_events e JOIN diagnostic_hypotheses h ON h.id = e.diagnostic_hypothesis_id WHERE h.diagnostic_session_id = ' . $state['session'] . ' AND e.from_status IS NOT NULL')->fetchColumn();
    assertSame(3, $events, 'Histórico de mudança de status');
    assertTrue(str_contains((string) $sessions[0]['current_summary'], 'usuário respondeu: "O título aparece no Gestão."'), 'Resumo sem a resposta');
});

test('T3b modelo repete pergunta já feita → backend descarta', static function () use ($chat, $transport, $userId, &$state): void {
    $transport->push(['mode' => 'diagnostic', 'answer' => 'Preciso confirmar.', 'hypotheses' => [], 'next_question' => 'O título aparece normalmente no Gestão Online, mas não aparece somente no Portal ?']);
    $r = $chat->ask($userId, 'Não sei se a conta está marcada.', $state['conv']);

    assertSame(null, $r['result']->structured()['next_question']);
    assertTrue(in_array('repeated_question_removed', $r['result']->structured()['warnings'], true));
    assertTrue(!str_contains((string) $r['result']->answer(), 'Pergunta de diagnóstico'), 'Pergunta repetida exibida');
    $content = (string) Database::connection()->query('SELECT content FROM messages WHERE id = ' . (int) $r['assistant_message_id'])->fetchColumn();
    assertSame((string) $r['result']->answer(), $content, 'Mensagem gravada não foi ajustada');
    assertSame(2, count(json_decode((string) sessionsOf($state['conv'])[0]['questions_json'], true)));
});

// ================================ TESTE 8 ================================
test('T8 memória do diagnóstico no contexto da próxima mensagem', static function () use ($transport, $state): void {
    $system = $transport->lastSystem();
    assertTrue(str_contains($system, '=== ESTADO DO DIAGNÓSTICO'), 'Bloco ausente');
    assertTrue(str_contains($system, 'PROBLEMA ORIGINAL: O boleto não aparece no Portal.'));
    assertTrue(str_contains($system, 'RESUMO: Problema relatado'));
    assertTrue(str_contains($system, 'Título não gerado corretamente | status=weakened'));
    assertTrue(str_contains($system, 'P: ' . Q1 . ' | R do usuário: "O título aparece no Gestão."'), 'Pergunta+resposta ausentes');
    assertTrue(str_contains($system, 'P: ' . Q2), 'Pergunta 2 ausente');
    assertTrue(str_contains($system, 'FONTES JÁ UTILIZADAS'));
    assertTrue(mb_strpos($system, '=== ESTADO DO DIAGNÓSTICO') < mb_strpos($system, "CONTEXTO:\n"), 'Memória depois do contexto');
});

// ================================ TESTE 6 ================================
test('T6 confirmed sem evidência válida não é aceito', static function () use ($chat, $transport, $userId, &$state): void {
    $h = hypothesesOf($state['session']);
    $transport->push([
        'mode' => 'diagnostic',
        'answer' => 'Confirmado.',
        'hypotheses' => [['id' => 'h' . $h['Conta financeira não publicada no Portal']['id'], 'title' => 'Conta financeira não publicada no Portal', 'status' => 'confirmed', 'reason' => '', 'source_ids' => [99]]],
        'next_question' => 'Em qual tela a conta foi cadastrada?',
    ]);
    $chat->ask($userId, 'Ok.', $state['conv']);
    assertSame('supported', hypothesesOf($state['session'])['Conta financeira não publicada no Portal']['status']);

    // Primeira mensagem, com justificativa mas sem fonte nem resposta do usuário.
    $transport->push(['mode' => 'diagnostic', 'answer' => 'x', 'hypotheses' => [['title' => 'Regra de visualização', 'status' => 'confirmed', 'reason' => 'acho que é isso']], 'next_question' => 'Qual o curso do aluno?']);
    $r = $chat->ask($userId, 'O boleto do aluno não aparece no Portal do aluno.');
    $sid = (int) sessionsOf((int) $r['conversation_id'])[0]['id'];
    assertSame('supported', hypothesesOf($sid)['Regra de visualização']['status']);
    $state['conv_other'] = (int) $r['conversation_id'];
    $state['session_other'] = $sid;
});

// ================================ TESTE 7 ================================
test('T7 solução proposta → solution_proposed (nunca resolved automático)', static function () use ($chat, $transport, $userId, &$state, $diagnostics): void {
    $h = hypothesesOf($state['session']);
    $transport->push([
        'mode' => 'diagnostic',
        'answer' => 'A causa foi identificada.',
        'hypotheses' => [['id' => 'h' . $h['Conta financeira não publicada no Portal']['id'], 'title' => 'Conta financeira não publicada no Portal', 'status' => 'confirmed', 'reason' => 'O usuário verificou que a conta não está marcada para o Portal.']],
        'next_question' => null,
        'recommended_action' => 'Revisar o cadastro da conta conforme a documentação [Fonte 1].',
        'resolved' => true,
        'confirmed_cause' => 'Conta financeira não marcada para exibição no Portal',
        'source_ids' => [1],
    ]);
    $r = $chat->ask($userId, 'Verifiquei: a conta não está marcada para o Portal.', $state['conv']);

    $session = sessionsOf($state['conv'])[0];
    assertSame('solution_proposed', $session['status']);
    assertSame('waiting_confirmation', $session['current_stage']);
    assertSame(null, $session['closed_at']);
    assertSame('confirmed', hypothesesOf($state['session'])['Conta financeira não publicada no Portal']['status']);
    assertSame('Conta financeira não marcada para exibição no Portal', $r['diagnostic']['confirmed_cause']);
    assertTrue($r['diagnostic']['active'], 'Sessão deveria continuar ativa até a confirmação');

    // Confirmação humana → resolved.
    $diagnostics->markResolved($diagnostics->activeSession($state['conv']), $userId);
    $closed = sessionsOf($state['conv'])[0];
    assertSame('resolved', $closed['status']);
    assertTrue($closed['closed_at'] !== null);
});

// ================================ TESTE 9 ================================
test('T9 conversas diferentes não compartilham sessão nem hipóteses', static function () use ($chat, $transport, $userId, &$state): void {
    assertTrue($state['session_other'] !== $state['session']);

    // Conversa B tenta atualizar hipótese da conversa A pelo id.
    $foreign = hypothesesOf($state['session'])['Erro de remessa bancária'];
    $transport->push(['mode' => 'diagnostic', 'answer' => 'x', 'hypotheses' => [['id' => 'h' . $foreign['id'], 'title' => 'Erro de remessa bancária', 'status' => 'supported', 'reason' => 'Usuário citou remessa.']], 'next_question' => 'Qual banco?']);
    $chat->ask($userId, 'Foi gerada remessa ontem.', $state['conv_other']);

    assertSame('rejected', hypothesesOf($state['session'])['Erro de remessa bancária']['status'], 'Hipótese de outra conversa alterada');
    assertTrue(isset(hypothesesOf($state['session_other'])['Erro de remessa bancária']), 'Deveria criar na própria sessão');
    $system = $transport->lastSystem();
    assertTrue(!str_contains($system, 'Conta financeira não publicada no Portal'), 'Memória vazou entre conversas');
});

// ================================ TESTE 10 ================================
test('T10 sessão encerrada não é continuada automaticamente', static function () use ($chat, $transport, $userId, &$state, $diagnostics): void {
    $diagnostics->abandon($diagnostics->activeSession($state['conv_other']), $userId);

    $transport->push(['mode' => 'answer', 'answer' => 'Certo.', 'hypotheses' => [], 'next_question' => null]);
    $before = count($transport->payloads);
    $chat->ask($userId, 'O boleto some do Portal depois de pago?', $state['conv_other']);

    assertSame($before + 1, count($transport->payloads), 'IA não chamada — cenário sem fonte');
    assertTrue(!str_contains($transport->lastSystem(), '=== ESTADO DO DIAGNÓSTICO'), 'Memória de sessão encerrada enviada');
    $sessions = sessionsOf($state['conv_other']);
    assertSame(1, count($sessions));
    assertSame('abandoned', $sessions[0]['status']);

    // Novo problema na mesma conversa abre uma NOVA sessão.
    $transport->push(boletoTurn1());
    $chat->ask($userId, 'Agora o boleto de outro aluno também não aparece no Portal.', $state['conv_other']);
    $sessions = sessionsOf($state['conv_other']);
    assertSame(2, count($sessions));
    assertSame('abandoned', $sessions[0]['status']);
    assertSame('open', $sessions[1]['status']);
});

test('Fechamento sem sessão ativa não altera nada; present() sem termos internos', static function () use ($diagnostics, &$state): void {
    assertSame(null, $diagnostics->activeSession($state['conv']));
    $p = $diagnostics->present($diagnostics->repository()->latestForConversation($state['conv']));
    assertSame('Resolvido', $p['status_label']);
    assertTrue($p['active'] === false && $p['next_question'] === null);
    foreach ($p['hypotheses'] as $h) {
        assertTrue(!array_key_exists('metadata', $h) && !array_key_exists('title_key', $h), 'Campo interno exposto');
    }
});

// ================================ Relatório ================================
$passed = count(array_filter($tests, static fn (array $t): bool => $t['pass']));

foreach ($tests as $t) {
    echo ($t['pass'] ? '[OK]   ' : '[FALHA] ') . $t['name'] . ($t['pass'] ? '' : ' — ' . $t['detail']) . PHP_EOL;
}

echo PHP_EOL . "Resultado: {$passed}/" . count($tests) . ' testes OK' . PHP_EOL;

exit($passed === count($tests) ? 0 : 1);
