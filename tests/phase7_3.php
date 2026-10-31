<?php

declare(strict_types=1);

/**
 * Etapa 7.3 — casos resolvidos e procedimentos internos como evidência.
 *
 * Execução isolada:  php tests/phase7_3.php
 * Sem rede (transporte fake). Documentos criados são rastreados pelo
 * TestIsolation e removidos no fim (chunks em CASCADE); conversas/sessões
 * saem com o usuário de teste f73-*@assistente.test.
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
use App\Services\Copilot\CopilotResponseValidator;
use App\Services\Copilot\EvidenceType;
use App\Services\Knowledge\InternalKnowledgeService;
use App\Services\Knowledge\KnowledgeService;
use App\Services\RAG\RAGService;
use App\Services\RAG\RetrievalService;
use App\Support\SourceUrl;

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

final class Phase73Transport implements HttpTransportInterface
{
    public array $payloads = [];

    public function postJson(string $url, array $headers, array $payload, int $connectTimeout, int $totalTimeout): array
    {
        $this->payloads[] = $payload;

        return [
            'status' => 200,
            'body' => json_encode([
                'id' => 'gen-f73',
                'model' => 'fake/model',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"mode":"answer","answer":"Ver [Fonte 1] em https://inventado.test/caso.","source_ids":[1]}'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
            'error' => '',
            'error_no' => 0,
            'headers' => [],
            'latency_ms' => 10.0,
        ];
    }
}

function f73User(string $role): int
{
    return (new UserRepository())->create([
        'name' => 'F73 ' . $role,
        'email' => 'f73-' . $role . '-' . bin2hex(random_bytes(4)) . '@assistente.test',
        'password_hash' => password_hash('F73Senha@123', PASSWORD_DEFAULT),
        'role' => $role,
        'status' => 'active',
        'must_change_password' => 0,
    ]);
}

/**
 * Sessão de diagnóstico montada diretamente no banco (determinística).
 *
 * @param array<int, array{0: string, 1: string}> $hypotheses [título, status]
 */
function f73Session(int $userId, string $status, array $hypotheses, string $problem): int
{
    $repo = new DiagnosticSessionRepository();
    $conversationId = (new ConversationRepository())->createForUser($userId, 'F73 ' . $problem);
    $sessionId = $repo->create($conversationId, $problem, 'Financeiro', 'Portal do aluno');

    foreach ($hypotheses as [$title, $hStatus]) {
        $repo->createHypothesis($sessionId, $title, mb_strtolower($title), null, $hStatus, null, []);
    }

    $repo->update($sessionId, [
        'status' => $status,
        'questions' => [['question' => 'O título aparece no Gestão Online?', 'answer' => 'Sim, aparece.', 'assistant_message_id' => null, 'answer_message_id' => null]],
        'sources' => [['type' => 'official', 'title' => 'Cadastro de Contas no Gestão On-Line', 'document_id' => null, 'chunk_id' => null, 'solution_id' => null]],
        'confirmed_cause' => $hypotheses !== [] && $hypotheses[0][1] === 'confirmed' ? $hypotheses[0][0] : null,
        'recommended_action' => 'Marcar a conta financeira para exibição no Portal e reenviar o título.',
    ]);

    return $sessionId;
}

function f73Track(array $result): int
{
    if (isset($result['document_id'])) {
        TestIsolation::track('knowledge_documents', (int) $result['document_id']);
    }

    return (int) ($result['document_id'] ?? 0);
}

$tag = 'zq' . bin2hex(random_bytes(3));
$tecnico = f73User('tecnico');
$outroTecnico = f73User('tecnico');
$service = new InternalKnowledgeService();
$sessions = new DiagnosticSessionRepository();
$state = [];

// ================================ TESTE 1 ================================
test('T1 sessão investigating → não permite registrar caso', static function () use ($service, $tecnico, $sessions): void {
    $sid = f73Session($tecnico, 'investigating', [['Conta não publicada', 'supported']], 'Boleto não aparece');
    assertTrue(!$service->canRegisterCase($sessions->find($sid)));
    $r = $service->saveResolvedCase($sid, ['title' => 'x', 'confirm_review' => '1'], $tecnico);
    assertSame('not_allowed', $r['status']);
});

// ================================ TESTE 2 ================================
test('T2 sessão resolved → formulário pré-preenchido e editável', static function () use ($service, $tecnico, $sessions, $tag, &$state): void {
    $sid = f73Session($tecnico, 'resolved', [['Conta financeira não publicada no Portal ' . $tag, 'confirmed'], ['Remessa bancária', 'rejected']], 'Boleto do aluno ' . $tag . ' não aparece no Portal');
    $prefill = $service->casePrefill($sessions->find($sid));

    assertTrue($service->canRegisterCase($sessions->find($sid)));
    assertSame('Conta financeira não publicada no Portal ' . $tag, $prefill['confirmed_cause']);
    assertSame(false, $prefill['cause_needs_review']);
    assertTrue(str_contains($prefill['symptoms'], 'Sim, aparece.'), 'Sintomas sem respostas do usuário');
    assertTrue(str_contains($prefill['evidence'], '📘 OFICIAL UNIMESTRE: Cadastro de Contas'), 'Evidências sem fonte oficial tipada');
    assertTrue(str_contains($prefill['solution'], 'Marcar a conta'), 'Solução não pré-preenchida');
    $state['sid'] = $sid;
    $state['prefill'] = $prefill;
});

// ================================ TESTE 3 ================================
test('T3 salvar exige confirmação humana; confirmado → resolved_case com proveniência', static function () use ($service, $tecnico, $sessions, &$state): void {
    $data = $state['prefill'];
    $data['solution'] = 'Marcamos a conta financeira para exibição no Portal e reenviamos o título ao aluno.';

    $semConfirmacao = $service->saveResolvedCase($state['sid'], $data, $tecnico);
    assertSame('invalid', $semConfirmacao['status']);
    assertTrue(isset($semConfirmacao['errors']['confirm_review']));

    $vazio = $service->saveResolvedCase($state['sid'], ['confirm_review' => '1'], $tecnico);
    assertSame('invalid', $vazio['status'], 'Caso vazio aceito');

    $r = $service->saveResolvedCase($state['sid'], $data + ['confirm_review' => '1'], $tecnico);
    assertSame('created', $r['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
    $docId = f73Track($r);

    $doc = $service->findInternal($docId);
    assertSame('resolved_case', $doc['source_type']);
    $prov = $doc['meta']['provenance'];
    assertSame($tecnico, $prov['created_by']);
    assertSame($state['sid'], $prov['diagnostic_session_id']);
    assertSame((int) $sessions->find($state['sid'])['conversation_id'], $prov['conversation_id']);
    assertSame(1, count($prov['confirmed_hypotheses']));
    assertSame(1, count($prov['official_sources']));
    assertSame(true, $prov['human_review']['confirmed']);

    $audit = (int) Database::connection()->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'resolved_case_created' AND entity_id = {$docId}")->fetchColumn();
    assertSame(1, $audit, 'Auditoria ausente');
    $state['case_doc'] = $docId;
});

// ================================ TESTE 4 ================================
test('T4 caso salvo → documento ativo, sem URL, com chunks', static function () use (&$state): void {
    $db = Database::connection();
    $doc = $db->query('SELECT status, url FROM knowledge_documents WHERE id = ' . $state['case_doc'])->fetch();
    assertSame('active', $doc['status']);
    assertSame(null, $doc['url']);
    $chunks = (int) $db->query('SELECT COUNT(*) FROM knowledge_chunks WHERE document_id = ' . $state['case_doc'])->fetchColumn();
    assertTrue($chunks >= 1, 'Sem chunks');
});

// ================================ TESTE 5 ================================
test('T5 busca futura encontra o caso como 🧩 CASO RESOLVIDO (retrieval + chat)', static function () use ($tag, $tecnico, &$state): void {
    $search = (new RetrievalService())->search('boleto ' . $tag . ' portal');
    $found = array_values(array_filter($search['results'], static fn ($r) => $r->documentId() === $state['case_doc']));
    assertTrue($found !== [], 'Caso não recuperado');
    assertSame('resolved_case', $found[0]->sourceType());
    assertSame('🧩 CASO RESOLVIDO', EvidenceType::badge(EvidenceType::fromSource(['source_type' => $found[0]->sourceType()])));

    $transport = new Phase73Transport();
    $providerId = (int) Database::connection()->query("SELECT id FROM ai_providers WHERE status = 'active' AND is_default = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $chat = new ChatService(null, new RAGService(new AIService(null, null, static fn () => $transport), null, null, null, static fn () => $providerId > 0 ? $providerId : 1));
    $r = $chat->ask($tecnico, 'Boleto ' . $tag . ' não aparece no Portal');

    $types = Database::connection()->query('SELECT source_type FROM message_sources WHERE message_id = ' . (int) $r['assistant_message_id'] . ' AND document_id = ' . $state['case_doc'])->fetchAll(PDO::FETCH_COLUMN);
    assertSame(['resolved_case'], $types, 'message_sources sem tipo resolved_case');
    $system = (string) $transport->payloads[0]['messages'][0]['content'];
    assertTrue(str_contains($system, 'TIPO: CASO RESOLVIDO'), 'Contexto sem rótulo de caso');
    $state['chat_answer'] = (string) $r['result']->answer();
});

// ================================ TESTE 6 / 7 ================================
test('T6/T7 procedimento interno → internal_procedure, sem link externo inventado', static function () use ($service, $tecnico, $tag, &$state): void {
    $semRevisao = $service->createProcedure(['title' => 'Reenvio de boleto ' . $tag, 'steps' => 'Passo 1: abrir a tela. Passo 2: reenviar.'], $tecnico);
    assertSame('invalid', $semRevisao['status']);

    $r = $service->createProcedure([
        'title' => 'Reenvio de boleto ' . $tag,
        'module' => 'Financeiro',
        'usage_context' => 'Aluno sem boleto no Portal',
        'steps' => "1. Conferir o título no Gestão.\n2. Reenviar o boleto ao aluno.",
        'prerequisites' => 'Acesso ao Gestão',
        'risks' => 'Não duplicar títulos.',
        'confirm_review' => '1',
    ], $tecnico);
    assertSame('created', $r['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
    $docId = f73Track($r);

    $doc = $service->findInternal($docId);
    assertSame('internal_procedure', $doc['source_type']);
    assertSame('🛠️ PROCEDIMENTO INTERNO', EvidenceType::badge(EvidenceType::fromSource($doc)));
    assertSame(null, $doc['url']);
    assertTrue(!SourceUrl::isTrusted($doc), 'Procedimento sem URL não pode virar link');

    $v = (new CopilotResponseValidator())->validate('{"mode":"answer","answer":"Veja https://unimestre.com/proc-inventado [Fonte 1]"}', [$doc]);
    assertTrue(!str_contains($v['answer'], 'unimestre.com'), 'URL inventada para fonte interna sobreviveu');
    assertTrue(!str_contains($state['chat_answer'] ?? '', 'inventado.test'), 'URL inventada no chat');
    $state['proc_doc'] = $docId;
});

// ================================ TESTE 8 ================================
test('T8 mesma sessão / mesmo título / mesmo conteúdo → duplicata recusada', static function () use ($service, $tecnico, $tag, &$state): void {
    $again = $service->saveResolvedCase($state['sid'], $state['prefill'] + ['confirm_review' => '1', 'solution' => 'Outra solução qualquer aplicada.'], $tecnico);
    assertSame('duplicate', $again['status']);
    assertSame($state['case_doc'], $again['document_id']);

    $sameTitle = $service->createProcedure(['title' => 'Reenvio de boleto  ' . strtoupper($tag), 'steps' => 'Outro passo a passo diferente.', 'confirm_review' => '1'], $tecnico);
    assertSame('duplicate', $sameTitle['status'], 'Título semelhante aceito');
    f73Track($sameTitle['status'] === 'created' ? $sameTitle : []);
});

// ================================ TESTE 9 ================================
test('T9 hipótese apenas possible não vira causa confirmada sem revisão explícita', static function () use ($service, $tecnico, $sessions, $tag): void {
    $sid = f73Session($tecnico, 'resolved', [['Regra de visualização ' . $tag, 'possible']], 'Aluno sem boleto ' . $tag . ' caso nove');
    $prefill = $service->casePrefill($sessions->find($sid));
    assertSame('', $prefill['confirmed_cause'], 'Hipótese possible pré-preenchida como causa');
    assertSame(true, $prefill['cause_needs_review']);

    $data = $prefill;
    $data['title'] = 'Caso nove ' . $tag;
    $data['confirmed_cause'] = 'Regra de visualização ' . $tag;
    $data['solution'] = 'Ajustada a regra de visualização financeira do curso.';

    $r = $service->saveResolvedCase($sid, $data + ['confirm_review' => '1'], $tecnico);
    assertSame('invalid', $r['status']);
    assertTrue(isset($r['errors']['cause_reviewed']));

    $ok = $service->saveResolvedCase($sid, $data + ['confirm_review' => '1', 'cause_reviewed' => '1'], $tecnico);
    assertSame('created', $ok['status']);
    $docId = f73Track($ok);
    assertSame(true, $service->findInternal($docId)['meta']['provenance']['cause_reviewed_explicitly']);
});

// ================================ TESTE 10 ================================
test('T10 conflito oficial × caso interno: ambos preservados com tipos distintos', static function () use ($service, $tecnico, $tag, &$state): void {
    $knowledge = new KnowledgeService();
    $official = $knowledge->importManual([
        'title' => 'Exibição de boletos ' . $tag . ' (oficial)',
        'content' => "Boleto {$tag}: a exibição no Portal depende somente da data de vencimento configurada.",
        'origin' => 'official',
        'category' => (string) ($knowledge->categories()[0] ?? 'Outros'),
        'status' => 'active',
        'url' => '',
    ], $tecnico);
    assertSame('created', $official['status'], json_encode($official, JSON_UNESCAPED_UNICODE));
    $officialId = f73Track($official);

    $results = (new RetrievalService())->search('boleto ' . $tag . ' exibição portal')['results'];
    $types = [];

    foreach ($results as $r) {
        if (in_array($r->documentId(), [$officialId, $state['case_doc']], true)) {
            $types[$r->documentId()] = $r->sourceType();
        }
    }

    assertSame('official', $types[$officialId] ?? null, 'Oficial não recuperado');
    assertSame('resolved_case', $types[$state['case_doc']] ?? null, 'Caso não recuperado');
    assertTrue(str_contains(RAGService::TRUST_RULE, 'explicite o conflito'), 'Regra de conflito ausente do prompt');
    assertTrue(str_contains((string) Database::connection()->query('SELECT content FROM knowledge_documents WHERE id = ' . $state['case_doc'])->fetchColumn(), 'não é documentação oficial UNIMESTRE'), 'Caso sem aviso de não-oficial');
});

// ============================ Edição / permissões ============================
test('Edição: técnico só edita os próprios; atualização reindexa e audita', static function () use ($service, $tecnico, $outroTecnico, &$state): void {
    $doc = $service->findInternal($state['proc_doc']);
    assertTrue($service->canEdit($doc, $tecnico, false));
    assertTrue(!$service->canEdit($doc, $outroTecnico, false), 'Técnico editou registro alheio');
    assertTrue($service->canEdit($doc, $outroTecnico, true), 'Admin deveria editar');

    $fields = $doc['meta']['fields'];
    $fields['steps'] .= "\n3. Registrar o atendimento.";
    $r = $service->update($state['proc_doc'], $fields + ['confirm_review' => '1'], $tecnico);
    assertSame('updated', $r['status'], json_encode($r, JSON_UNESCAPED_UNICODE));
    $content = (string) Database::connection()->query('SELECT GROUP_CONCAT(content) FROM knowledge_chunks WHERE document_id = ' . $state['proc_doc'])->fetchColumn();
    assertTrue(str_contains($content, 'Registrar o atendimento'), 'Chunks não reindexados');
    $audit = (int) Database::connection()->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'internal_procedure_updated' AND entity_id = {$state['proc_doc']}")->fetchColumn();
    assertSame(1, $audit);
});

// ================================ Relatório ================================
$passed = count(array_filter($tests, static fn (array $t): bool => $t['pass']));

foreach ($tests as $t) {
    echo ($t['pass'] ? '[OK]   ' : '[FALHA] ') . $t['name'] . ($t['pass'] ? '' : ' — ' . $t['detail']) . PHP_EOL;
}

echo PHP_EOL . "Resultado: {$passed}/" . count($tests) . ' testes OK' . PHP_EOL;

exit($passed === count($tests) ? 0 : 1);
