<?php

declare(strict_types=1);

/**
 * Etapa 7.4 — UX do diagnóstico: painel, hipóteses visuais, linha do tempo,
 * próxima pergunta, evidências por hipótese, ações e estado final.
 *
 * Execução isolada:  php tests/phase7_4.php
 * O painel é HTML gerado no servidor (DiagnosticPanelPresenter + partial);
 * os testes renderizam e inspecionam o DOM. Sem rede. A verificação visual
 * real (viewports) é feita no navegador e registrada no relatório.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/TestIsolation.php';

use App\Repositories\ConversationRepository;
use App\Repositories\DiagnosticSessionRepository;
use App\Repositories\UserRepository;
use App\Services\Copilot\DiagnosticPanelPresenter;
use App\Services\Knowledge\InternalKnowledgeService;

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

function dom(string $html): DOMXPath
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
    libxml_clear_errors();

    return new DOMXPath($doc);
}

function q(DOMXPath $x, string $query): DOMNodeList
{
    return $x->query($query);
}

function text(DOMXPath $x, string $query): string
{
    $out = [];

    foreach (q($x, $query) as $n) {
        $out[] = trim(preg_replace('/\s+/u', ' ', $n->textContent));
    }

    return implode(' | ', $out);
}

$userId = (new UserRepository())->create([
    'name' => 'F74 Teste',
    'email' => 'f74-' . bin2hex(random_bytes(4)) . '@assistente.test',
    'password_hash' => password_hash('F74Senha@123', PASSWORD_DEFAULT),
    'role' => 'tecnico',
    'status' => 'active',
    'must_change_password' => 0,
]);

$repo = new DiagnosticSessionRepository();
$presenter = new DiagnosticPanelPresenter();

/**
 * @param array<int, array{0: string, 1: string, 2?: string}> $hyps [título, status, motivo]
 * @param array<string, mixed> $fields
 */
function f74Session(int $userId, array $hyps, array $fields): array
{
    $repo = new DiagnosticSessionRepository();
    $conversationId = (new ConversationRepository())->createForUser($userId, 'F74');
    $sid = $repo->create($conversationId, 'O boleto não aparece no Portal.', 'Financeiro', 'Portal do aluno');

    foreach ($hyps as $h) {
        $hid = $repo->createHypothesis($sid, $h[0], mb_strtolower($h[0]), null, $h[1], $h[2] ?? null, [
            'source_refs' => [['type' => 'official', 'title' => 'Cadastro de Contas no Gestão On-Line']],
        ]);
        $repo->addEvent($hid, null, 'possible', null, null);

        if ($h[1] !== 'possible') {
            $repo->addEvent($hid, 'possible', $h[1], null, $h[2] ?? null);
        }
    }

    $repo->update($sid, $fields);

    return (array) $repo->find($sid);
}

$hyps = [
    ['Título não gerado', 'weakened', 'Resposta do usuário: "O título aparece no Gestão." Enfraquece.'],
    ['Conta financeira não publicada no Portal', 'supported', 'Cadastro de contas controla a exibição.'],
    ['Configuração de exibição', 'possible'],
    ['Erro de remessa bancária', 'rejected', 'Remessa não afeta título já gerado.'],
];
$q1 = 'O título aparece normalmente no Gestão Online?';
$q2 = 'A conta está marcada para exibição no Portal?';
$investigating = f74Session($userId, $hyps, [
    'status' => 'investigating',
    'current_stage' => 'testing_hypotheses',
    'current_summary' => 'Problema relatado: boleto não aparece no Portal.',
    'questions' => [
        ['question' => $q1, 'answer' => 'Sim', 'assistant_message_id' => null, 'answer_message_id' => null],
        ['question' => $q2, 'answer' => null, 'assistant_message_id' => null, 'answer_message_id' => null],
    ],
]);

// ================================ TESTE 1 ================================
test('T1 sessão investigating → painel visível, expandido, com problema/módulo/etapa', static function () use ($presenter, $investigating): void {
    $x = dom($presenter->render($investigating, true));
    assertSame(1, q($x, '//section[contains(@class,"dx-panel")]')->length);
    assertTrue(str_contains(text($x, '//*[contains(@class,"dx-title")]'), 'Diagnóstico em andamento'));
    assertSame('true', q($x, '//button[contains(@class,"dx-toggle")]')->item(0)->getAttribute('aria-expanded'));
    assertSame(0, q($x, '//div[contains(@class,"dx-body")][@hidden]')->length, 'Corpo deveria estar visível');
    $facts = text($x, '//dl[contains(@class,"dx-facts")]');
    assertTrue(str_contains($facts, 'O boleto não aparece no Portal.') && str_contains($facts, 'Financeiro') && str_contains($facts, 'Testando hipóteses'), $facts);
    assertSame('Hipóteses em análise', text($x, '//li[@aria-current="step"]//*[contains(@class,"dx-step-label")]'), 'Etapa atual da linha do tempo');
});

// ================================ TESTE 2 ================================
test('T2 hipóteses com vários status → rótulos em português, sem enum técnico', static function () use ($presenter, $investigating): void {
    $x = dom($presenter->render($investigating, true));
    $badges = [];

    foreach (q($x, '//*[@data-dx-status]') as $n) {
        $badges[$n->getAttribute('data-dx-status')] = trim(preg_replace('/\s+/u', ' ', $n->textContent));
    }

    assertSame('▲ Sustentada', $badges['supported'] ?? null);
    assertSame('○ Possível', $badges['possible'] ?? null);
    assertSame('▽ Enfraquecida', $badges['weakened'] ?? null);
    assertSame('✕ Rejeitada', $badges['rejected'] ?? null);
    $visible = text($x, '//ul[contains(@class,"dx-hyps")]');
    foreach (['possible', 'supported', 'weakened', 'rejected', 'confirmed'] as $enum) {
        assertTrue(!str_contains($visible, $enum), 'Enum visível: ' . $enum);
    }
    // Ordem: sustentada antes de possível, rejeitada por último.
    $order = [];
    foreach (q($x, '//*[@data-dx-status]') as $n) {
        $order[] = $n->getAttribute('data-dx-status');
    }
    assertSame(['supported', 'possible', 'weakened', 'rejected'], $order);
});

test('T2b evidência por hipótese: botão real com aria-expanded e só fatos registrados', static function () use ($presenter, $investigating): void {
    $x = dom($presenter->render($investigating, true));
    $why = q($x, '//li[contains(@class,"dx-hyp-weakened")]//button[contains(@class,"dx-why")]')->item(0);
    assertTrue($why !== null, 'Botão "Por que" ausente');
    assertSame('false', $why->getAttribute('aria-expanded'));
    $box = q($x, '//*[@id="' . $why->getAttribute('aria-controls') . '"]')->item(0);
    assertTrue($box !== null && $box->hasAttribute('hidden'));
    $ev = trim(preg_replace('/\s+/u', ' ', $box->textContent));
    assertTrue(str_contains($ev, 'Enfraquece:') && str_contains($ev, 'O título aparece no Gestão.'), $ev);
    assertTrue(str_contains($ev, '📘 OFICIAL UNIMESTRE'), 'Fonte relacionada sem tipo');
});

// ================================ TESTE 3 ================================
test('T3 next_question presente → bloco destacado + botão Responder; resposta anterior marcada', static function () use ($presenter, $investigating, $q1, $q2): void {
    $x = dom($presenter->render($investigating, true));
    assertTrue(str_contains(text($x, '//div[contains(@class,"dx-next")]'), 'Para continuar o diagnóstico, preciso confirmar:'));
    assertSame($q2, text($x, '//p[contains(@class,"dx-next-question")]'));
    assertSame(1, q($x, '//div[contains(@class,"dx-next")]//button[@data-dx-action="answer"]')->length);
    $checks = text($x, '//ul[contains(@class,"dx-checks")]');
    assertTrue(str_contains($checks, $q1) && str_contains($checks, '✓ Respondida') && str_contains($checks, 'Sim'), $checks);
    assertTrue(!str_contains($checks, $q2), 'Pergunta pendente listada como respondida');
});

// ================================ TESTE 4 ================================
test('T4 solution_proposed → "Problema resolvido" com confirmação e "Encerrar" com motivos', static function () use ($presenter, $userId): void {
    $s = f74Session($userId, [['Conta não publicada', 'confirmed', 'Usuário verificou a conta.']], [
        'status' => 'solution_proposed', 'current_stage' => 'waiting_confirmation',
        'confirmed_cause' => 'Conta não publicada', 'recommended_action' => 'Publicar a conta.',
    ]);
    $x = dom($presenter->render($s, false));
    $resolve = q($x, '//button[@data-dx-action="resolve-ask"]')->item(0);
    assertTrue($resolve !== null && str_contains($resolve->textContent, 'Problema resolvido'));
    $box = q($x, '//*[@id="' . $resolve->getAttribute('aria-controls') . '"]')->item(0);
    assertTrue($box->hasAttribute('hidden'), 'Confirmação deveria começar oculta (não fecha em silêncio)');
    assertTrue(str_contains($box->textContent, 'Confirma que o problema foi resolvido com a orientação aplicada?'));
    assertSame(1, q($x, '//button[@data-dx-action="resolve-confirm"][normalize-space()="Confirmar resolução"]')->length);
    assertSame(4, q($x, '//fieldset//input[@type="radio"]')->length, 'Motivos de encerramento');
    assertSame('Confirmação do usuário', text($x, '//li[@aria-current="step"]//*[contains(@class,"dx-step-label")]'));
});

// ================================ TESTE 5 / 6 ================================
test('T5 resolved → "Diagnóstico concluído", causa, solução, fontes e registrar caso', static function () use ($presenter, $userId, &$resolvedSession): void {
    $resolvedSession = f74Session($userId, [['Conta financeira não publicada no Portal F74', 'confirmed', 'Usuário verificou.']], [
        'status' => 'resolved', 'current_stage' => 'resolved', 'closed_at' => date('Y-m-d H:i:s'),
        'confirmed_cause' => 'Conta financeira não publicada no Portal', 'recommended_action' => 'Publicar a conta e reenviar o boleto.',
        'sources' => [['type' => 'internal_procedure', 'title' => 'Proc TI'], ['type' => 'official', 'title' => 'Cadastro de Contas']],
    ]);
    $x = dom($presenter->render($resolvedSession, true));
    $final = text($x, '//div[contains(@class,"dx-final")]');
    assertTrue(str_contains($final, 'Diagnóstico concluído') && str_contains($final, 'Causa confirmada: Conta financeira') && str_contains($final, 'Solução aplicada: Publicar a conta'), $final);
    assertTrue(str_contains(text($x, '//ul[contains(@class,"dx-sources")]/li[1]'), '📘 OFICIAL UNIMESTRE'), 'Oficial deveria vir primeiro');
    assertSame(1, q($x, '//a[@data-dx-register]')->length);
    assertSame(0, q($x, '//button[@data-dx-action="resolve-ask"]')->length, 'Ação de resolver em sessão resolvida');
    assertSame(0, q($x, '//li[@aria-current="step"]')->length, 'Resolvido: todas as etapas concluídas');
});

test('T6 caso já registrado → sem botão de registrar; mostra "Caso resolvido registrado"', static function () use ($presenter, $userId, &$resolvedSession): void {
    $service = new InternalKnowledgeService();
    $prefill = $service->casePrefill($resolvedSession);
    $r = $service->saveResolvedCase((int) $resolvedSession['id'], $prefill + ['confirm_review' => '1', 'title' => 'Caso F74 ' . bin2hex(random_bytes(3))], $userId);
    assertSame('created', $r['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
    TestIsolation::track('knowledge_documents', (int) $r['document_id']);

    $x = dom($presenter->render($resolvedSession, true));
    assertSame(0, q($x, '//a[@data-dx-register]')->length, 'Botão duplicado');
    assertTrue(str_contains(text($x, '//*[@data-dx-registered]'), 'Caso resolvido registrado'));
});

// ================================ TESTE 7 ================================
test('T7 usuário sem permissão → sem ações internas', static function () use ($presenter, $investigating, &$resolvedSession): void {
    $x = dom($presenter->render($investigating, false));
    assertSame(0, q($x, '//a[@data-dx-procedure]')->length, 'Criar procedimento visível sem permissão');
    assertSame(1, q($x, '//button[@data-dx-action="resolve-ask"]')->length, 'Dono da conversa ainda pode resolver');

    $session = (new DiagnosticSessionRepository())->find((int) $resolvedSession['id']);
    $session['id'] = (int) $session['id'];
    $x2 = dom($presenter->render($session, false));
    assertSame(0, q($x2, '//a[@data-dx-register]')->length);
    assertSame(0, q($x2, '//*[@data-dx-registered]//a')->length, 'Link de edição sem permissão');

    $x3 = dom($presenter->render($investigating, true));
    assertSame(1, q($x3, '//a[@data-dx-procedure]')->length);
    assertTrue(str_contains(q($x3, '//a[@data-dx-procedure]')->item(0)->getAttribute('href'), 'from_session=' . $investigating['id']));
});

test('Estado inconclusivo → bloco "Diagnóstico ainda inconclusivo" com abertas/verificado/falta', static function () use ($presenter, $userId): void {
    $s = f74Session($userId, [['Hipótese A', 'rejected', 'Contradita.'], ['Hipótese B', 'weakened', 'Pouco provável.']], [
        'status' => 'investigating', 'current_stage' => 'escalation_needed',
        'questions' => [['question' => 'Pergunta X?', 'answer' => 'Não', 'assistant_message_id' => null, 'answer_message_id' => null]],
    ]);
    $t = text(dom($presenter->render($s, false)), '//div[contains(@class,"dx-inconclusive")]');
    assertTrue(str_contains($t, 'Diagnóstico ainda inconclusivo') && str_contains($t, 'Hipótese B') && str_contains($t, 'Pergunta X? → Não') && str_contains($t, 'Falta confirmar'), $t);
    assertTrue(!str_contains($t, 'Hipótese A'), 'Rejeitada listada como aberta');
});

test('Sessão encerrada → painel recolhido, sem ações; XSS escapado', static function () use ($presenter, $userId): void {
    $s = f74Session($userId, [['<img src=x onerror=alert(1)>', 'possible']], ['status' => 'abandoned', 'current_stage' => 'closed']);
    $html = $presenter->render($s, true);
    $x = dom($html);
    assertSame('false', q($x, '//button[contains(@class,"dx-toggle")]')->item(0)->getAttribute('aria-expanded'));
    assertSame(0, q($x, '//button[@data-dx-action="resolve-ask"] | //button[@data-dx-action="abandon-ask"]')->length);
    assertTrue(!str_contains($html, '<img src=x'), 'HTML do título não escapado');
});

// ============================ TESTE 8 / 9 (fontes) ============================
function f74RenderThread(array $sources): DOMXPath
{
    $conversation = ['id' => 1, 'title' => 'F74', 'created_at' => date('Y-m-d H:i:s')];
    $messages = [
        ['id' => 1, 'role' => 'user', 'content' => 'pergunta', 'sources' => [], 'truncated' => 0, 'created_at' => date('Y-m-d H:i:s')],
        ['id' => 2, 'role' => 'assistant', 'content' => 'resposta [Fonte 1]', 'sources' => $sources, 'truncated' => 0, 'created_at' => date('Y-m-d H:i:s'), 'model' => null, 'status' => 'ok'],
    ];
    $feedback = [];
    $chatCsrf = 't';
    $canManageSolutions = false;
    $isTechSupport = false;
    $diagnosticHtml = null;

    ob_start();
    require config('paths.views') . '/chat/thread.php';

    return dom((string) ob_get_clean());
}

test('T8 fonte interna sem URL → sem link externo; rótulo correto', static function (): void {
    $x = f74RenderThread([
        ['source_type' => 'internal_procedure', 'title' => 'Reenvio de boleto', 'url' => null, 'category' => 'Financeiro', 'relevance_score' => 4.2],
        ['source_type' => 'resolved_case', 'title' => 'Caso boleto', 'url' => 'https://unimestre.com/inventado', 'category' => null, 'relevance_score' => null],
    ]);
    assertSame(0, q($x, '//div[@data-source-number="1"]//a')->length, 'Link para fonte interna sem URL');
    $head = text($x, '//div[contains(@class,"chat-source-head")]');
    assertTrue(str_contains($head, '🛠️ PROCEDIMENTO INTERNO') && str_contains($head, '🧩 CASO RESOLVIDO'), $head);
    assertTrue(str_contains(text($x, '//div[contains(@class,"chat-source-meta")]'), 'Financeiro · Relevância 4.20'));
});

test('T9 fonte oficial com URL confiável → botão "Abrir fonte oficial"', static function (): void {
    $x = f74RenderThread([['source_type' => 'official', 'title' => 'Cadastro de Contas', 'url' => 'https://unimestre.com/basedeconhecimento/contas', 'category' => 'Financeiro', 'relevance_score' => 6.0]]);
    $a = q($x, '//a[contains(@class,"chat-source-open")]')->item(0);
    assertTrue($a !== null, 'Botão ausente');
    assertSame('https://unimestre.com/basedeconhecimento/contas', $a->getAttribute('href'));
    assertSame('noopener noreferrer', $a->getAttribute('rel'));
    assertTrue(str_contains($a->textContent, 'Abrir fonte oficial'));
});

// ================================ TESTE 10 ================================
test('T10 mobile: CSS do painel sem larguras fixas, com quebra e empilhamento < 576px', static function (): void {
    $css = (string) file_get_contents(APP_PATH . '/public/assets/css/app.css');
    $block = substr($css, (int) strpos($css, 'Etapa 7.4'));
    assertTrue(!preg_match('/(?<![-\w])width:\s*\d{3,}px/', $block), 'Largura fixa em px no painel');
    assertTrue(str_contains($block, 'overflow-wrap: anywhere'), 'Sem quebra de palavras longas');
    assertTrue(str_contains($block, 'flex-wrap: wrap'), 'Sem flex-wrap');
    assertTrue(str_contains($block, '@media (max-width: 575.98px)') && str_contains($block, '.dx-timeline { flex-direction: column'), 'Sem empilhamento mobile');
    assertTrue(str_contains($block, ':focus-visible'), 'Sem foco visível');
    // Medida real de overflow nos viewports: teste visual no navegador (relatório).
});

// ================================ Relatório ================================
$passed = count(array_filter($tests, static fn (array $t): bool => $t['pass']));

foreach ($tests as $t) {
    echo ($t['pass'] ? '[OK]   ' : '[FALHA] ') . $t['name'] . ($t['pass'] ? '' : ' — ' . $t['detail']) . PHP_EOL;
}

echo PHP_EOL . "Resultado: {$passed}/" . count($tests) . ' testes OK' . PHP_EOL;

exit($passed === count($tests) ? 0 : 1);
