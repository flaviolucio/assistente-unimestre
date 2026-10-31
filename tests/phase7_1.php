<?php

declare(strict_types=1);

/**
 * Etapa 7.1 — evidência tipada, resposta estruturada validada e citações seguras.
 *
 * Execução isolada:  php tests/phase7_1.php
 * Sem rede: o provider é chamado por um transporte fake. Fixtures
 * (usuário f71-*@assistente.test e suas conversas) são removidas pelo
 * TestIsolation.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/TestIsolation.php';

use App\Core\Database;
use App\Repositories\ConversationRepository;
use App\Repositories\UserRepository;
use App\Services\AI\AIService;
use App\Services\AI\Http\HttpTransportInterface;
use App\Services\Chat\ChatService;
use App\Services\Copilot\CopilotResponseValidator;
use App\Services\Copilot\EvidenceType;
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

final class Phase71Transport implements HttpTransportInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $payloads = [];

    /** @var array<int, string> */
    private array $queue = [];

    public function queue(string ...$contents): void
    {
        $this->queue = $contents;
    }

    public function postJson(string $url, array $headers, array $payload, int $connectTimeout, int $totalTimeout): array
    {
        $this->payloads[] = $payload;
        $content = array_shift($this->queue) ?? 'Resposta fake.';

        return [
            'status' => 200,
            'body' => json_encode([
                'id' => 'gen-f71',
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

$validator = new CopilotResponseValidator();
$sources = [
    ['title' => 'Cadastro de Contas no Gestão On-Line', 'source_type' => 'official', 'url' => 'https://unimestre.com/basedeconhecimento/contas'],
    ['title' => 'Procedimento TI boletos', 'source_type' => 'internal', 'url' => null],
];

// ========================= Evidência tipada =========================

test('Evidência: tipo derivado da origem; desconhecido nunca vira oficial', static function (): void {
    assertSame('official', EvidenceType::fromSource(['source_type' => 'official']));
    assertSame('internal_procedure', EvidenceType::fromSource(['source_type' => 'internal']), 'Legado internal = PROCEDIMENTO INTERNO');
    assertSame('internal_procedure', EvidenceType::fromSource(['source_type' => 'internal_procedure']));
    assertSame('resolved_case', EvidenceType::fromSource(['source_type' => 'resolved_case']));
    assertSame('internal_procedure', EvidenceType::fromSource(['source_type' => 'qualquer']));
    assertSame('internal_procedure', EvidenceType::fromSource(['source_type' => 'ai_hypothesis']), 'Hipótese não pode ser fonte documental');
    assertSame('📘 OFICIAL UNIMESTRE', EvidenceType::badge('official'));
    assertSame('🛠️ PROCEDIMENTO INTERNO', EvidenceType::badge('internal_procedure'));
    assertSame('🧾 SUPORTE UNIMESTRE', EvidenceType::badge('vendor_support'));
    assertSame('🧩 CASO RESOLVIDO', EvidenceType::badge('resolved_case'));
    assertSame('🔎 HIPÓTESE DA IA', EvidenceType::badge('ai_hypothesis'));
});

test('Migration 0016: structured_json existe e ENUM aceita tipos novos (sem ai_hypothesis)', static function (): void {
    $db = Database::connection();
    assertTrue($db->query("SHOW COLUMNS FROM messages LIKE 'structured_json'")->fetch() !== false, 'Coluna ausente');
    $type = (string) $db->query("SHOW COLUMNS FROM message_sources LIKE 'source_type'")->fetch()['Type'];
    assertTrue(str_contains($type, 'resolved_case') && str_contains($type, 'vendor_support'), 'ENUM sem tipos novos: ' . $type);
    assertTrue(!str_contains($type, 'hypothesis'), 'Hipótese não pode ser tipo de fonte');
});

// ========================= Validador =========================

test('Cenário 1 (validador): resposta direta em JSON vira modo answer sem hipóteses', static function () use ($validator, $sources): void {
    $v = $validator->validate('{"mode":"answer","answer":"Acesse a tela de login e clique em Esqueci minha senha [Fonte 1].","hypotheses":[],"next_question":null,"source_ids":[1]}', $sources);
    assertTrue($v['structured'], 'Deveria ser estruturada');
    assertSame('answer', $v['mode']);
    assertSame([], $v['hypotheses']);
    assertSame([1], $v['source_ids']);
    assertTrue(str_contains($validator->render($v), '[Fonte 1]'));
});

test('Cenário 2 (validador): diagnóstico com hipóteses + uma pergunta', static function () use ($validator, $sources): void {
    $raw = "```json\n" . json_encode([
        'mode' => 'diagnostic',
        'answer' => 'Vamos verificar por etapas.',
        'hypotheses' => [
            ['title' => 'Conta financeira não publicada no Portal', 'status' => 'possible', 'reason' => 'Ver cadastro de contas [Fonte 1]', 'source_ids' => [1]],
            ['title' => 'Título não gerado', 'status' => 'possible', 'reason' => '', 'source_ids' => []],
        ],
        'next_question' => 'O título aparece normalmente no Gestão Online, mas não no Portal?',
        'resolved' => false,
    ], JSON_UNESCAPED_UNICODE) . "\n```";
    $v = $validator->validate($raw, $sources);
    $text = $validator->render($v);

    assertSame('diagnostic', $v['mode']);
    assertSame(2, count($v['hypotheses']));
    assertSame('ai_hypothesis', $v['hypotheses'][0]['evidence_type']);
    assertTrue(str_contains($text, '🔎 HIPÓTESE DA IA'), 'Hipóteses devem ser rotuladas');
    assertTrue(str_contains($text, 'Possível'), 'Status traduzido ausente');
    assertTrue(str_contains($text, 'Pergunta de diagnóstico:** O título aparece'), 'Pergunta ausente');
    assertTrue(!str_contains($text, '```') && !str_contains($text, '"mode"'), 'JSON bruto vazou');
});

test('Citações: [Fonte n] e source_ids inexistentes são removidos', static function () use ($validator, $sources): void {
    $v = $validator->validate('{"mode":"answer","answer":"Veja [Fonte 1] e [Fonte 9] e [Fonte 1, 7].","source_ids":[1,9,"x",0]}', $sources);
    assertTrue(!str_contains($v['answer'], 'Fonte 9') && !str_contains($v['answer'], 'Fonte 7'), 'Citação inventada sobreviveu: ' . $v['answer']);
    assertSame([1], $v['source_ids']);
    assertTrue(in_array('invalid_citation_removed', $v['warnings'], true));
    assertTrue(in_array('invalid_source_id_removed', $v['warnings'], true));
});

test('Citações: sem fontes enviadas, nenhuma citação sobrevive', static function () use ($validator): void {
    $v = $validator->validate('{"mode":"answer","answer":"Conforme [Fonte 1], faça X.","source_ids":[1]}', []);
    assertTrue(!str_contains($v['answer'], '[Fonte'), 'Citação sem fonte sobreviveu');
    assertSame([], $v['source_ids']);
});

test('URLs: só URL de fonte confiável sobrevive (solta ou markdown)', static function () use ($validator, $sources): void {
    $raw = '{"mode":"answer","answer":"Oficial: https://unimestre.com/basedeconhecimento/contas. Falsa: https://evil.example.org/x e [manual](https://phish.com/a) e [ok](https://unimestre.com/basedeconhecimento/contas)"}';
    $v = $validator->validate($raw, $sources);
    assertTrue(str_contains($v['answer'], 'https://unimestre.com/basedeconhecimento/contas.'), 'URL confiável removida');
    assertTrue(!str_contains($v['answer'], 'evil.example') && !str_contains($v['answer'], 'phish.com'), 'URL inventada sobreviveu: ' . $v['answer']);
    assertTrue(str_contains($v['answer'], 'manual') && str_contains($v['answer'], '[ok](https://unimestre.com'), 'Rótulos/links confiáveis perdidos');
});

test('Sem porcentagem de confiança em hipóteses ou texto', static function () use ($validator, $sources): void {
    $raw = '{"mode":"diagnostic","answer":"Hipótese principal com confiança de 85%.","hypotheses":[{"title":"Conta não publicada (80%)","status":"supported","reason":"probabilidade: 70% segundo [Fonte 1]","source_ids":[1]}]}';
    $v = $validator->validate($raw, $sources);
    $text = $validator->render($v);
    assertTrue(!preg_match('/\d+\s*%/', $text), 'Porcentagem sobreviveu: ' . $text);
    assertSame('supported', $v['hypotheses'][0]['status']);
});

test('Cenário 4 (validador): status weakened/rejected preservados; status inválido vira possible', static function () use ($validator, $sources): void {
    $v = $validator->validate('{"mode":"diagnostic","answer":"Atualizando.","hypotheses":[{"title":"A","status":"weakened","reason":"O título existe no Gestão"},{"title":"B","status":"rejected","reason":"contradiz"},{"title":"C","status":"probable"}]}', $sources);
    assertSame(['weakened', 'rejected', 'possible'], array_column($v['hypotheses'], 'status'));
    assertSame(['Enfraquecida', 'Rejeitada', 'Possível'], array_column($v['hypotheses'], 'status_label'));
});

test('Cenário 5 (validador): confirmed com evidência + resolved com causa', static function () use ($validator, $sources): void {
    $v = $validator->validate('{"mode":"diagnostic","answer":"Causa encontrada.","hypotheses":[{"title":"Conta não publicada","status":"confirmed","reason":"Usuário confirmou a flag desmarcada [Fonte 1]","source_ids":[1]}],"recommended_action":"Marcar a conta para exibição conforme [Fonte 1].","resolved":true,"confirmed_cause":"Conta não publicada no Portal","source_ids":[1]}', $sources);
    assertTrue($v['resolved'], 'Deveria estar resolvido');
    assertSame('confirmed', $v['hypotheses'][0]['status']);
    $text = $validator->render($v);
    assertTrue(str_contains($text, 'Causa confirmada:**') && str_contains($text, 'Próxima ação sugerida:**'));
});

test('Sem evidência: confirmed rebaixado e resolved sem causa recusado', static function () use ($validator, $sources): void {
    $v = $validator->validate('{"mode":"diagnostic","answer":"Talvez.","hypotheses":[{"title":"X","status":"confirmed"}],"resolved":true,"confirmed_cause":null}', $sources);
    assertSame('supported', $v['hypotheses'][0]['status']);
    assertTrue($v['resolved'] === false, 'resolved sem causa aceito');
    assertTrue(in_array('confirmed_without_evidence', $v['warnings'], true));

    $v2 = $validator->validate('{"mode":"diagnostic","answer":"Resolvido.","hypotheses":[],"resolved":true,"confirmed_cause":"Algo","source_ids":[]}', $sources);
    assertTrue($v2['resolved'] === false && $v2['confirmed_cause'] === null, 'resolved sem evidência aceito');
});

test('JSON truncado/inválido nunca é exibido cru', static function () use ($validator, $sources): void {
    $v = $validator->validate('{"mode":"diagnostic","answer":"Vamos verif', $sources);
    assertSame(CopilotResponseValidator::MSG_INVALID_OUTPUT, $v['answer']);
    assertTrue(!$v['structured']);
});

test('Texto livre (modelo sem JSON) continua funcionando, sanitizado', static function () use ($validator, $sources): void {
    $v = $validator->validate('Para recuperar a senha, [Fonte 1] acesse a tela de login. Ver [Fonte 5] e http://fake.test/x', $sources);
    assertTrue(!$v['structured']);
    assertSame('answer', $v['mode']);
    assertTrue(str_contains($v['answer'], '[Fonte 1]') && !str_contains($v['answer'], '[Fonte 5]') && !str_contains($v['answer'], 'fake.test'));
    assertSame([1], $v['source_ids']);
});

// ========================= Ponta a ponta (sem rede) =========================

$userId = (new UserRepository())->create([
    'name' => 'F71 Teste',
    'email' => 'f71-' . bin2hex(random_bytes(4)) . '@assistente.test',
    'password_hash' => password_hash('F71Senha@123', PASSWORD_DEFAULT),
    'role' => 'tecnico',
    'status' => 'active',
    'must_change_password' => 0,
]);

function f71Chat(Phase71Transport $transport): ChatService
{
    $providerId = (int) Database::connection()
        ->query("SELECT id FROM ai_providers WHERE status = 'active' AND is_default = 1 ORDER BY id LIMIT 1")
        ->fetchColumn();
    $ai = new AIService(null, null, static fn () => $transport);
    $rag = new RAGService($ai, null, null, null, static fn () => $providerId > 0 ? $providerId : 1);

    return new ChatService(null, $rag);
}

test('E2E: contrato no prompt antes do CONTEXTO; resposta validada e gravada com structured_json', static function () use ($userId): void {
    $transport = new Phase71Transport();
    $transport->queue('{"mode":"answer","answer":"Use a opção de recuperação [Fonte 1]. Veja também https://golpe.test/senha e [Fonte 42].","hypotheses":[],"next_question":null,"source_ids":[1,42]}');

    $response = f71Chat($transport)->ask($userId, 'Como recuperar a senha no Portal UNIMESTRE?');
    $result = $response['result'];

    if ($result->sources() === []) {
        throw new RuntimeException('Base sem fonte para "recuperar senha" — cenário não aplicável neste banco');
    }

    $system = (string) ($transport->payloads[0]['messages'][0]['content'] ?? '');
    $contract = mb_strpos($system, 'FORMATO DE SAÍDA');
    assertTrue($contract !== false && $contract < mb_strpos($system, 'CONTEXTO:'), 'Contrato ausente ou após o contexto');

    $answer = (string) $result->answer();
    assertTrue(!str_contains($answer, 'golpe.test') && !str_contains($answer, 'Fonte 42') && !str_contains($answer, '"mode"'), 'Saída não sanitizada: ' . $answer);
    assertSame('answer', $result->structured()['mode']);

    $row = Database::connection()->query('SELECT content, structured_json FROM messages WHERE id = ' . (int) $response['assistant_message_id'])->fetch();
    assertSame($answer, (string) $row['content'], 'Conteúdo gravado difere do exibido');
    $stored = json_decode((string) $row['structured_json'], true);
    assertTrue(is_array($stored) && $stored['mode'] === 'answer' && $stored['source_ids'] === [1], 'structured_json inválido');

    $types = Database::connection()->query('SELECT DISTINCT source_type FROM message_sources WHERE message_id = ' . (int) $response['assistant_message_id'])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($types as $type) {
        assertTrue(in_array($type, EvidenceType::DOCUMENTARY, true), 'Tipo de fonte inválido: ' . $type);
    }

    $thread = (new ConversationRepository())->messagesWithSources((int) $response['conversation_id']);
    assertSame('answer', end($thread)['structured']['mode'] ?? null, 'Thread não devolve structured');
});

test('E2E Cenário 2: problema gera diagnóstico com hipóteses rotuladas', static function () use ($userId): void {
    $transport = new Phase71Transport();
    $transport->queue(json_encode([
        'mode' => 'diagnostic',
        'answer' => 'Vamos verificar por etapas.',
        'hypotheses' => [['title' => 'Conta não publicada no Portal', 'status' => 'possible', 'reason' => '', 'source_ids' => []]],
        'next_question' => 'O título aparece no Gestão Online?',
    ], JSON_UNESCAPED_UNICODE));

    $response = f71Chat($transport)->ask($userId, 'O boleto do aluno não aparece no Portal.');
    $result = $response['result'];

    if (!$result->aiCalled()) {
        throw new RuntimeException('Base sem fonte para boleto/portal — IA não chamada (no_context)');
    }

    assertSame('diagnostic', $result->structured()['mode']);
    assertTrue(str_contains((string) $result->answer(), '🔎 HIPÓTESE DA IA'));
    assertSame('O título aparece no Gestão Online?', $result->structured()['next_question']);
});

// ========================= Relatório =========================

$passed = count(array_filter($tests, static fn (array $t): bool => $t['pass']));

foreach ($tests as $t) {
    echo ($t['pass'] ? '[OK]   ' : '[FALHA] ') . $t['name'] . ($t['pass'] ? '' : ' — ' . $t['detail']) . PHP_EOL;
}

echo PHP_EOL . "Resultado: {$passed}/" . count($tests) . ' testes OK' . PHP_EOL;

exit($passed === count($tests) ? 0 : 1);
