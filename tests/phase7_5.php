<?php

declare(strict_types=1);

/**
 * Etapa 7.5 — escalonamento de diagnósticos inconclusivos.
 *
 * Execução isolada:  php tests/phase7_5.php
 * Sem rede (transporte fake). Nada é enviado para fora: o teste confirma que
 * o escalonamento só muda estado local por ação humana. Fixtures
 * f75-*@assistente.test removidas pelo TestIsolation (sessões em CASCADE).
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/TestIsolation.php';

use App\Core\Database;
use App\Repositories\ConversationRepository;
use App\Repositories\DiagnosticSessionRepository;
use App\Repositories\UserRepository;
use App\Services\AI\AIService;
use App\Services\AI\Http\HttpTransportInterface;
use App\Services\Chat\ChatService;
use App\Services\Copilot\CopilotDiagnosticService;
use App\Services\Copilot\CopilotResponseValidator;
use App\Services\Copilot\DiagnosticPanelPresenter;
use App\Services\Copilot\EscalationSummaryService;
use App\Services\RAG\RAGService;
use App\Support\SensitiveDataMasker;

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

final class Phase75Transport implements HttpTransportInterface
{
    public array $payloads = [];
    private array $queue = [];

    public function push(array $content): void
    {
        $this->queue[] = json_encode($content, JSON_UNESCAPED_UNICODE);
    }

    public function postJson(string $url, array $headers, array $payload, int $connectTimeout, int $totalTimeout): array
    {
        $this->payloads[] = $payload;

        return [
            'status' => 200,
            'body' => json_encode([
                'id' => 'gen-f75', 'model' => 'fake/model',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => array_shift($this->queue) ?? '{"mode":"answer","answer":"ok"}'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ], JSON_UNESCAPED_UNICODE),
            'error' => '', 'error_no' => 0, 'headers' => [], 'latency_ms' => 10.0,
        ];
    }
}

function f75User(string $role): int
{
    return (new UserRepository())->create([
        'name' => 'F75 ' . $role,
        'email' => 'f75-' . $role . '-' . bin2hex(random_bytes(4)) . '@assistente.test',
        'password_hash' => password_hash('F75Senha@123', PASSWORD_DEFAULT),
        'role' => $role,
        'status' => 'active',
        'must_change_password' => 0,
    ]);
}

$tecnico = f75User('tecnico');
$transport = new Phase75Transport();
$providerId = (int) Database::connection()->query("SELECT id FROM ai_providers WHERE status = 'active' AND is_default = 1 ORDER BY id LIMIT 1")->fetchColumn();
$chat = new ChatService(null, new RAGService(new AIService(null, null, static fn () => $transport), null, null, null, static fn () => $providerId > 0 ? $providerId : 1));
$diag = new CopilotDiagnosticService();
$repo = new DiagnosticSessionRepository();
$summaries = new EscalationSummaryService();
$presenter = new DiagnosticPanelPresenter();
$state = [];

const SECRET = 'Zq9!segredoSuperSecreto';

function sessionOf(int $conversationId): array
{
    return (array) (new DiagnosticSessionRepository())->latestForConversation($conversationId);
}

function hyp(string $title, string $status, string $reason = '', array $ids = []): array
{
    return ['title' => $title, 'status' => $status, 'reason' => $reason, 'source_ids' => $ids];
}

// ============================ TESTE 1 / 2 ============================
test('T2 IA sugere escalonamento sem investigação → não escala prematuramente', static function () use ($chat, $transport, $tecnico): void {
    $transport->push(['mode' => 'diagnostic', 'answer' => 'Vamos investigar.', 'hypotheses' => [hyp('Conta não publicada', 'possible')],
        'next_question' => 'O título aparece no Gestão Online?', 'escalation' => ['recommended' => true, 'reason' => 'sem dados']]);
    $r = $chat->ask($tecnico, 'O boleto do aluno não aparece no Portal.');
    if (!$r['result']->aiCalled()) {
        throw new RuntimeException('Base sem fonte para boleto/portal (no_context)');
    }
    assertSame('open', sessionOf((int) $r['conversation_id'])['status'], 'Escalou sem nenhuma verificação');
});

test('T1/T2 sem evidência suficiente + IA sugere → escalation_needed (nunca escalated automático)', static function () use ($chat, $transport, $tecnico, &$state): void {
    $transport->push(['mode' => 'diagnostic', 'answer' => 'Vamos investigar.', 'hypotheses' => [
        hyp('Conta financeira não publicada no Portal', 'possible'),
        hyp('Título não gerado corretamente', 'possible'),
        hyp('Erro de remessa bancária', 'possible'),
    ], 'next_question' => 'O título aparece normalmente no Gestão Online?']);
    $r1 = $chat->ask($tecnico, 'O boleto do aluno não aparece no Portal.');
    $conv = (int) $r1['conversation_id'];
    $s = sessionOf($conv);
    $h = [];
    foreach ($repo = (new DiagnosticSessionRepository())->hypotheses((int) $s['id']) as $row) {
        $h[$row['title']] = 'h' . $row['id'];
    }

    $transport->push([
        'mode' => 'diagnostic',
        'answer' => 'Com as informações atuais, não foi possível confirmar a causa.',
        'hypotheses' => [
            ['id' => $h['Título não gerado corretamente'], 'title' => 'Título não gerado corretamente', 'status' => 'rejected', 'reason' => 'O usuário confirmou que o título existe no Gestão.'],
            ['id' => $h['Erro de remessa bancária'], 'title' => 'Erro de remessa bancária', 'status' => 'weakened', 'reason' => 'Sem relação aparente.'],
            ['id' => $h['Conta financeira não publicada no Portal'], 'title' => 'Conta financeira não publicada no Portal', 'status' => 'supported', 'reason' => 'Cadastro de contas controla a exibição.', 'source_ids' => [1]],
        ],
        'next_question' => null,
        'escalation' => ['recommended' => true, 'reason' => 'Depende de análise interna da UNIMESTRE'],
    ]);
    $r2 = $chat->ask($tecnico, 'Sim, aparece no Gestão. A senha do aluno é ' . SECRET . ' e o token=abc123def456 e o CPF 123.456.789-09.', $conv);

    $s = sessionOf($conv);
    assertSame('escalation_needed', $s['status']);
    assertSame('escalation_needed', $s['current_stage']);
    assertSame(null, $s['escalated_at'], 'Escalado automaticamente');
    assertSame('Depende de análise interna da UNIMESTRE', $s['escalation']['suggested_reason']);
    assertTrue(str_contains((string) $r2['result']->answer(), 'Recomendo preparar o escalonamento'), 'Recomendação não exibida');
    assertTrue($r2['diagnostic']['active'], 'escalation_needed deve continuar ativo');
    $state['conv'] = $conv;
});

test('T1b todas as hipóteses rejeitadas e sem próxima verificação → escalation_needed', static function () use ($chat, $transport, $tecnico): void {
    $transport->push(['mode' => 'diagnostic', 'answer' => 'Investigando.', 'hypotheses' => [hyp('Hipótese única F75', 'possible')], 'next_question' => 'O erro ocorre para todos os alunos do curso?']);
    $r1 = $chat->ask($tecnico, 'Boleto não aparece no Portal para um aluno.');
    $transport->push(['mode' => 'diagnostic', 'answer' => 'Descartada.', 'hypotheses' => [hyp('Hipótese única F75', 'rejected', 'O usuário informou que ocorre só para um aluno.')], 'next_question' => null]);
    $chat->ask($tecnico, 'Não, só para um aluno.', (int) $r1['conversation_id']);
    assertSame('escalation_needed', sessionOf((int) $r1['conversation_id'])['status']);
});

// ============================ TESTE 3 / 4 / 5 / 7 ============================
test('T3/T4 Preparar escalonamento → resumo estruturado com problema, verificações, abertas e fontes', static function () use ($summaries, &$state): void {
    $s = sessionOf($state['conv']);
    $sum = $summaries->build($s, 'Técnico F75');
    foreach (['title', 'original_problem', 'module', 'context', 'symptoms', 'expected_behavior', 'observed_behavior', 'questions', 'checks', 'rejected', 'open', 'most_supported', 'sources', 'procedures', 'pending_info', 'timeline', 'conflicts'] as $key) {
        assertTrue(array_key_exists($key, $sum), 'Campo ausente: ' . $key);
    }
    assertSame('O boleto do aluno não aparece no Portal.', $sum['original_problem']);
    assertSame(1, count($sum['checks']));
    assertSame(['Conta financeira não publicada no Portal', 'Erro de remessa bancária'], array_column($sum['open'], 'title'));
    assertSame('Conta financeira não publicada no Portal', $sum['most_supported']);
    assertTrue($sum['sources'] !== [], 'Fontes ausentes');
    assertTrue(!in_array('ai_hypothesis', array_column($sum['sources'], 'type'), true), 'Hipótese como fonte');
    assertSame(EscalationSummaryService::NOT_RECORDED, $sum['expected_behavior'], 'Comportamento esperado inventado');
    assertSame('Sem evidência suficiente para concluir.', end($sum['timeline']));
    assertTrue(str_contains(implode("\n", $sum['timeline']), 'passou a: Rejeitada'), 'Linha do tempo sem eventos');

    $html = $summaries->renderView($s, 'Técnico F75');
    foreach (['Preparar escalonamento', 'Verificações realizadas', 'Ainda abertas', 'Fontes consultadas', 'Informações que ainda podem ser necessárias', 'Texto para o suporte UNIMESTRE', 'Copiar resumo', 'Copiar texto para suporte', 'Voltar ao diagnóstico', 'Marcar como escalado'] as $label) {
        assertTrue(str_contains($html, $label), 'Modal sem: ' . $label);
    }
});

test('T5 hipótese rejeitada nunca aparece como causa; texto de suporte não afirma causa', static function () use ($summaries, &$state): void {
    $sum = $summaries->build(sessionOf($state['conv']), 'Técnico F75');
    assertSame(['Título não gerado corretamente'], array_column($sum['rejected'], 'title'));
    assertTrue(!in_array('Título não gerado corretamente', array_column($sum['open'], 'title'), true));
    $support = $summaries->supportText($sum);
    foreach (['ASSUNTO:', 'PROBLEMA:', 'MÓDULO:', 'CENÁRIO:', 'JÁ VERIFICADO:', 'RESULTADOS:', 'HIPÓTESES AINDA ABERTAS', 'DOCUMENTAÇÃO CONSULTADA:', 'INFORMAÇÕES ADICIONAIS:'] as $section) {
        assertTrue(str_contains($support, $section), 'Seção ausente: ' . $section);
    }
    assertTrue(str_contains($support, 'Descartado: Título não gerado corretamente'));
    assertTrue(str_contains($support, 'A causa ainda não foi confirmada.'));
    assertTrue(!preg_match('/causa (confirmada|é|foi)\s*:/iu', $support), 'Texto afirma causa');
});

test('T7 segredo/token/CPF na conversa não aparece no texto exportável', static function () use ($summaries, &$state): void {
    $sum = $summaries->build(sessionOf($state['conv']), 'Técnico F75');
    $all = $summaries->summaryText($sum) . "\n" . $summaries->supportText($sum) . "\n" . $summaries->renderView(sessionOf($state['conv']), 'x');
    assertTrue(!str_contains($all, SECRET), 'Senha vazou');
    assertTrue(!str_contains($all, 'abc123def456'), 'Token vazou');
    assertTrue(!str_contains($all, '123.456.789-09'), 'CPF vazou');
    assertTrue($sum['masked_count'] >= 3, 'Contagem de mascaramento');
    assertTrue(str_contains($all, 'valor(es) potencialmente sensível(is) foram omitidos'), 'Aviso de mascaramento ausente');

    $m = new SensitiveDataMasker();
    $masked = $m->mask('Authorization: Bearer abcdefghijklmnop sk-or-v1-0123456789abcdef e-mail joao@escola.com.br eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.abcdefghijklm');
    assertTrue(!preg_match('/abcdefghijklmnop|sk-or-v1|joao@|eyJhbGci/', $masked), $masked);
    assertSame('Aluno com título 4521 no período 2024/1.', $m->mask('Aluno com título 4521 no período 2024/1.'), 'Mascaramento excessivo');
});

// ================================ TESTE 6 ================================
test('T6 fonte oficial × caso resolvido divergentes → conflito explícito no resumo', static function () use ($summaries, $repo, &$state): void {
    $v = (new CopilotResponseValidator())->validate(
        '{"mode":"diagnostic","answer":"x","hypotheses":[],"next_question":"Qual o vencimento?","source_conflicts":[{"source_id":1,"claim":"Exibição depende do vencimento"},{"source_id":2,"claim":"Exibição depende da conta"},{"source_id":9,"claim":"inventada"}]}',
        [['title' => 'Oficial', 'source_type' => 'official'], ['title' => 'Caso', 'source_type' => 'resolved_case']]
    );
    assertSame([1, 2], array_column($v['source_conflicts'], 'source_id'), 'Fonte inventada aceita no conflito');
    assertTrue(str_contains((new CopilotResponseValidator())->render($v), 'Há divergência entre as fontes consultadas'));

    $s = sessionOf($state['conv']);
    $escalation = $s['escalation'];
    $escalation['conflicts'] = [
        'official:Exibição de boletos' => ['type' => 'official', 'title' => 'Exibição de boletos', 'claim' => 'Depende só do vencimento'],
        'resolved_case:Caso boleto' => ['type' => 'resolved_case', 'title' => 'Caso boleto', 'claim' => 'Depende da conta publicada'],
    ];
    $repo->update((int) $s['id'], ['escalation' => $escalation]);
    $sum = $summaries->build(sessionOf($state['conv']), 'T');
    assertTrue($sum['has_conflict']);
    $text = $summaries->summaryText($sum);
    assertTrue(str_contains($text, 'Há divergência entre as fontes consultadas:'), 'Conflito ausente');
    assertTrue(str_contains($text, '📘 OFICIAL UNIMESTRE Exibição de boletos: Depende só do vencimento') && str_contains($text, '🧩 CASO RESOLVIDO Caso boleto: Depende da conta publicada'), $text);
    assertTrue(str_contains($summaries->supportText($sum), 'Há divergência entre as fontes consultadas'));
});

// ================================ TESTE 9 ================================
test('T9 nova informação após escalation_needed → volta para investigating sem perder histórico', static function () use ($chat, $transport, $tecnico, $diag, &$state): void {
    $before = count(sessionOf($state['conv'])['questions']);
    $transport->push(['mode' => 'diagnostic', 'answer' => 'Nova informação ajuda.', 'hypotheses' => [], 'next_question' => 'A conta aparece marcada para o Portal?']);
    $chat->ask($tecnico, 'Descobri que só acontece com boletos de acordo.', $state['conv']);
    $s = sessionOf($state['conv']);
    assertSame('investigating', $s['status']);
    assertSame($before + 1, count($s['questions']), 'Histórico perdido');
    assertSame('Depende de análise interna da UNIMESTRE', $s['escalation']['suggested_reason'], 'Sugestão anterior perdida');

    // Botão "Voltar ao diagnóstico": escalation_needed → investigating.
    (new DiagnosticSessionRepository())->update((int) $s['id'], ['status' => 'escalation_needed', 'current_stage' => 'escalation_needed']);
    assertTrue($diag->reopen(sessionOf($state['conv']), $tecnico));
    assertSame('investigating', sessionOf($state['conv'])['status']);
    assertTrue(!$diag->reopen(sessionOf($state['conv']), $tecnico), 'Reabriu sessão que não estava em escalation_needed');
});

// ============================ TESTE 11 / painel ============================
test('T11 painel inconclusivo: "Preparar escalonamento" para o dono; ações internas só com permissão', static function () use ($presenter, $repo, &$state): void {
    $s = sessionOf($state['conv']);
    $repo->update((int) $s['id'], ['status' => 'escalation_needed', 'current_stage' => 'escalation_needed']);
    $s = sessionOf($state['conv']);

    $html = $presenter->render($s, false);
    assertTrue(str_contains($html, 'Diagnóstico inconclusivo'));
    assertTrue(str_contains($html, 'data-dx-escalation'), 'Botão Preparar ausente');
    assertTrue(str_contains($html, 'data-dx-action="reopen"'), 'Voltar ao diagnóstico ausente');
    assertTrue(str_contains($html, 'Informações que ainda podem ser necessárias'));
    assertTrue(!str_contains($html, 'data-dx-procedure'), 'Ação interna sem permissão');
    assertTrue(str_contains($presenter->render($s, true), 'data-dx-procedure'));
});

// ============================ TESTE 8 / 12 ============================
test('T8 Marcar como escalado → status escalated, resumo congelado e auditoria', static function () use ($diag, $summaries, $tecnico, &$state): void {
    $s = sessionOf($state['conv']);
    $reason = CopilotDiagnosticService::ESCALATION_REASONS['vendor_analysis'];
    $summary = $summaries->build($s, 'Técnico F75', $reason);
    $diag->markEscalated($s, $tecnico, $reason, $summary, 'CH-2026-0042');

    $s = sessionOf($state['conv']);
    assertSame('escalated', $s['status']);
    assertSame($tecnico, (int) $s['escalated_by']);
    assertSame($reason, $s['escalation_reason']);
    assertSame('CH-2026-0042', $s['external_reference']);
    assertTrue($s['escalated_at'] !== null && $s['closed_at'] !== null);
    assertSame($reason, $s['escalation_summary']['reason']);
    assertTrue(!str_contains(json_encode($s['escalation_summary'], JSON_UNESCAPED_UNICODE), SECRET), 'Segredo no resumo congelado');
    assertSame(null, (new DiagnosticSessionRepository())->activeForConversation($state['conv']), 'Sessão escalada continua ativa');

    $audit = Database::connection()->prepare("SELECT details FROM audit_logs WHERE action = 'copilot.diagnostico_escalado' AND entity_id = ? ORDER BY id DESC LIMIT 1");
    $audit->execute([(int) $s['id']]);
    $details = (string) $audit->fetchColumn();
    assertTrue($details !== '' && str_contains($details, 'vendor') === false && str_contains($details, (string) $tecnico), 'Auditoria ausente: ' . $details);
});

test('T12 painel escalated → "Caso escalado", sem "Preparar" nem resolver; modal somente leitura', static function () use ($presenter, $summaries, &$state): void {
    $s = sessionOf($state['conv']);
    $html = $presenter->render($s, true);
    assertTrue(str_contains($html, 'Caso escalado') && str_contains($html, 'CH-2026-0042') && str_contains($html, 'Responsável'), 'Bloco escalado incompleto');
    assertTrue(!str_contains($html, 'data-dx-escalation'), 'Botão Preparar em sessão escalada');
    assertTrue(!str_contains($html, 'data-dx-action="resolve-ask"'), 'Resolver em sessão escalada');
    $view = $summaries->renderView($s, 'x');
    assertTrue(str_contains($view, 'Resumo do escalonamento') && !str_contains($view, 'data-esc-action="confirm-ask"'), 'Modal deveria ser somente leitura');
});

// ================================ TESTE 10 ================================
test('T10 conversa de outro usuário: sem acesso à sessão/escalonamento', static function () use ($tecnico, &$state): void {
    $intruso = f75User('usuario');
    $s = sessionOf($state['conv']);
    assertSame(null, (new ConversationRepository())->findByUser($state['conv'], $intruso), 'Conversa alheia acessível');
    assertSame(null, (new DiagnosticSessionRepository())->findForUser((int) $s['id'], $intruso), 'Sessão alheia acessível');
    assertTrue((new DiagnosticSessionRepository())->findForUser((int) $s['id'], $tecnico) !== null);
    // Rotas de escalonamento usam ChatController::ownedSessionOrFail (findByUser); verificado também via HTTP no teste visual.
});

// ================================ Relatório ================================
$passed = count(array_filter($tests, static fn (array $t): bool => $t['pass']));

foreach ($tests as $t) {
    echo ($t['pass'] ? '[OK]   ' : '[FALHA] ') . $t['name'] . ($t['pass'] ? '' : ' — ' . $t['detail']) . PHP_EOL;
}

echo PHP_EOL . "Resultado: {$passed}/" . count($tests) . ' testes OK' . PHP_EOL;

exit($passed === count($tests) ? 0 : 1);
