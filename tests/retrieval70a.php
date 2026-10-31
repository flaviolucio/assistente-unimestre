<?php

declare(strict_types=1);

/**
 * Fase 7.0A — relevância do retrieval, matched_terms e fonte de verdade das
 * configurações RAG. Incluído por tests/run.php e por tests/run_70a.php.
 *
 * Fixtures: documentos "T70A …" importados pelo fluxo real (KnowledgeService),
 * rastreados no TestIsolation e removidos ao final (chunks em cascata).
 * O documento real 39922 é só LIDO.
 */

use App\Core\Database;
use App\Services\Knowledge\KnowledgeService;
use App\Services\RAG\RetrievalService;
use App\Services\RAG\SearchQueryService;

const T70A_SENHA_QUERY = 'Como recuperar minha senha no Portal UNIMESTRE?';
const T70A_NOTAS_QUERY = 'Um professor lançou notas para a turma inteira, mas a média de um aluno não está sendo calculada.';

/** Cria (uma vez) as fixtures temáticas pelo fluxo real de importação. */
function t70aFixtures(): array
{
    static $ids = null;

    if ($ids !== null) {
        return $ids;
    }

    $service = new KnowledgeService();
    $docs = [
        'media' => ['T70A Média do aluno não calculada', 'Acadêmico',
            "Quando o professor lança as notas das avaliações no diário e a média de um aluno não é calculada, "
            . "verifique se as notas de todas as avaliações foram gravadas, se a fórmula de média da disciplina "
            . "está configurada e se o aluno está vinculado à turma. Depois recalcule a média na ficha individual."],
        'frequencia' => ['T70A Lançamento de frequência no diário', 'Acadêmico',
            "O professor registra a frequência dos alunos no diário de classe. Se o professor não consegue lançar "
            . "frequência, confira se a aula está cadastrada no horário, se o diário está aberto para o período "
            . "e se o professor está vinculado à disciplina."],
        'boleto' => ['T70A Emissão de segunda via de boleto', 'Financeiro',
            "A segunda via do boleto é emitida pelo portal financeiro. Informe o título em aberto, confira o "
            . "vencimento atualizado e gere novamente o boleto bancário para pagamento."],
    ];

    $ids = [];

    foreach ($docs as $key => [$title, $category, $content]) {
        $res = $service->importManual([
            'title' => $title,
            'url' => '',
            'origin' => 'internal',
            'category' => $category,
            'subcategory' => '',
            'status' => 'active',
            'content' => $content,
        ], null);

        if (($res['document_id'] ?? 0) <= 0) {
            throw new RuntimeException('Falha ao criar fixture ' . $key . ': ' . json_encode($res));
        }

        $ids[$key] = (int) $res['document_id'];
        TestIsolation::track('knowledge_documents', $ids[$key]);
    }

    return $ids;
}

/** @return array<int, int> document_ids em ordem de ranking */
function t70aDocIds(array $search): array
{
    return array_values(array_unique(array_map(static fn ($r) => (int) $r->documentId(), $search['results'])));
}

/**
 * Altera temporariamente um system_setting e SEMPRE restaura o valor exato.
 */
function t70aWithSetting(string $key, string $value, callable $fn): void
{
    $db = Database::connection();
    $original = $db->prepare('SELECT setting_value, updated_at FROM system_settings WHERE setting_key = ?');
    $original->execute([$key]);
    $row = $original->fetch();

    if ($row === false) {
        throw new RuntimeException('Setting inexistente: ' . $key);
    }

    try {
        $db->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
        $fn();
    } finally {
        // Restaura valor E updated_at (a coluna tem ON UPDATE CURRENT_TIMESTAMP).
        $db->prepare('UPDATE system_settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?')
            ->execute([$row['setting_value'], $row['updated_at'], $key]);
    }
}

test('7.0A CASO A: senha → documento real "Esqueci minha senha" (39922) no topo', static function (): void {
    $search = (new RetrievalService())->search(T70A_SENHA_QUERY);
    $top = $search['results'][0] ?? null;

    assertTrue($top !== null, 'Nenhum resultado');
    assertSame(39922, $top->documentId(), 'Documento de senha fora do topo');
    assertSame('official', $top->sourceType());
});

test('7.0A CASO B: notas/média → documento de senha NÃO recuperado', static function (): void {
    $search = (new RetrievalService())->search(T70A_NOTAS_QUERY);

    assertTrue(!in_array(39922, t70aDocIds($search), true), 'Documento de senha recuperado para pergunta de notas');
});

test('7.0A CASO C: notas/média → fixture de média no topo e senha ausente', static function (): void {
    $ids = t70aFixtures();
    $search = (new RetrievalService())->search(T70A_NOTAS_QUERY);
    $docs = t70aDocIds($search);

    assertTrue($docs !== [], 'Nenhum resultado para pergunta de média');
    assertSame($ids['media'], $docs[0], 'Documento de média não está no topo: ' . json_encode($docs));
    assertTrue(!in_array(39922, $docs, true), 'Senha recuperada');
    assertTrue(!in_array($ids['boleto'], $docs, true), 'Boleto recuperado');
});

test('7.0A CASO D: ranking temático com múltiplos candidatos', static function (): void {
    $ids = t70aFixtures();
    $retrieval = new RetrievalService();

    $freq = t70aDocIds($retrieval->search('O professor não consegue lançar frequência no diário'));
    assertTrue($freq !== [] && $freq[0] === $ids['frequencia'], 'Frequência fora do topo: ' . json_encode($freq));
    assertTrue(!in_array(39922, $freq, true) && !in_array($ids['boleto'], $freq, true), 'Senha/boleto em pergunta de frequência');

    $boleto = t70aDocIds($retrieval->search('Como emitir a segunda via do boleto?'));
    assertTrue($boleto !== [] && $boleto[0] === $ids['boleto'], 'Boleto fora do topo: ' . json_encode($boleto));
    assertTrue(!in_array(39922, $boleto, true) && !in_array($ids['media'], $boleto, true), 'Senha/média em pergunta de boleto');

    $senha = t70aDocIds($retrieval->search(T70A_SENHA_QUERY));
    assertSame(39922, $senha[0] ?? null, 'Senha perdeu o topo com mais documentos na base');
    assertTrue(!in_array($ids['media'], $senha, true) && !in_array($ids['boleto'], $senha, true), 'Fixtures em pergunta de senha');
});

test('7.0A relevância base: palavra genérica sozinha não cria candidato', static function (): void {
    $search = (new RetrievalService())->search('Está tudo sendo feito mas ainda não está pronto');

    assertTrue(!in_array(39922, t70aDocIds($search), true), 'Documento recuperado por palavras genéricas');
});

test('7.0A relevância base: bônus oficial/categoria não substituem evidência textual', static function (): void {
    // "portal" casa a CATEGORIA do doc 39922 e ele é oficial, mas os demais termos não aparecem no texto.
    $search = (new RetrievalService())->search('portal matrícula rematrícula disciplina optativa horário');

    assertTrue(!in_array(39922, t70aDocIds($search), true), 'Categoria + bônus oficial bastaram para recuperar');
});

test('7.0A matched_terms: preenchido, subconjunto da pergunta e presente no documento', static function (): void {
    $qs = new SearchQueryService();
    $search = (new RetrievalService())->search(T70A_SENHA_QUERY);
    $top = $search['results'][0];
    $terms = $top->matchedTerms();

    assertTrue($terms !== [], 'matched_terms vazio');
    assertTrue(in_array('senha', $terms, true), 'Termo "senha" ausente de matched_terms');

    foreach ($terms as $term) {
        assertTrue(in_array($term, $search['terms'], true), 'Termo fora da pergunta: ' . $term);
        $haystack = $top->title() . ' ' . $top->category() . ' ' . $top->content();
        assertTrue($qs->contains($haystack, $term) || $qs->contains($haystack, $qs->singularOf($term)), 'Termo sem ocorrência real: ' . $term);
    }

    assertSame($terms, $top->toArray()['matched_terms'], 'matched_terms não propagado na serialização');
});

test('7.0A rag.top_k (system_settings) controla a quantidade de resultados', static function (): void {
    t70aFixtures();
    $query = 'professor diário notas frequência média';

    t70aWithSetting('rag.top_k', '1', static function () use ($query): void {
        $r = new RetrievalService();
        assertSame(1, $r->topK());
        assertTrue(count($r->search($query)['results']) <= 1, 'top_k=1 ignorado');
    });

    t70aWithSetting('rag.top_k', '5', static function () use ($query): void {
        assertTrue(count((new RetrievalService())->search($query)['results']) >= 2, 'top_k=5 não devolveu múltiplos');
    });
});

test('7.0A rag.min_score (system_settings) controla o corte; sem valor numérico usa config', static function (): void {
    t70aWithSetting('rag.min_score', '999', static function (): void {
        $r = new RetrievalService();
        assertSame(999.0, $r->minimumScore());
        assertSame(0, count($r->search(T70A_SENHA_QUERY)['results']), 'min_score=999 ignorado');
    });

    t70aWithSetting('rag.min_score', '', static function (): void {
        assertSame((float) config('knowledge.retrieval.minimum_relevance_score'), (new RetrievalService())->minimumScore(), 'Fallback para config falhou');
    });

    assertSame(2.0, (new RetrievalService())->minimumScore(), 'Valor efetivo atual deveria ser 2.0');
});
