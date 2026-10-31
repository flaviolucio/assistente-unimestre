<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\KnowledgeChunkRepository;
use App\Repositories\KnowledgeDocumentRepository;
use App\Repositories\KnowledgeSourceRepository;
use App\Services\Knowledge\InternalSolutionService;
use App\Services\Knowledge\KnowledgeService;
use App\Services\RAG\ChunkingService;
use App\Services\RAG\RetrievalService;
use App\Services\RAG\SearchQueryService;

// ---------------------------------------------------------------------------
// Limpeza de residuos de execucoes anteriores da Fase 4 (fator de reexecucao).
// ---------------------------------------------------------------------------
$dbCleanup = Database::connection();
$leftoverDocs = $dbCleanup->query("SELECT id FROM knowledge_documents WHERE title LIKE 'F4-%'")->fetchAll();

foreach ($leftoverDocs as $row) {
    $dbCleanup->prepare('DELETE FROM knowledge_documents WHERE id = ?')->execute([(int) $row['id']]);
}

$leftoverSolutions = $dbCleanup->query("SELECT id FROM internal_solutions WHERE title LIKE 'F4-SOL-%'")->fetchAll();

foreach ($leftoverSolutions as $row) {
    $dbCleanup->prepare('DELETE FROM internal_solutions WHERE id = ?')->execute([(int) $row['id']]);
}

$dbCleanup->exec("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (entity_type = 'knowledge_document' AND action LIKE 'knowledge.document.%')");
$dbCleanup->exec("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (entity_type = 'internal_solution' AND action LIKE 'solution.%')");

/**
 * Remove documentos de teste e seus chunks (limpeza garantida em finally).
 *
 * @param array<int, int> $ids
 */
function f4RemoveDocuments(array $ids): void
{
    $ids = array_values(array_filter(array_map('intval', $ids)));

    if ($ids === []) {
        return;
    }

    $db = Database::connection();

    foreach ($ids as $id) {
        $db->prepare('DELETE FROM knowledge_documents WHERE id = ?')->execute([$id]);
    }

    $in = implode(',', $ids);
    $db->exec("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (entity_type = 'knowledge_document' AND entity_id IN ({$in}))");
}

/**
 * @param array<int, \App\Services\RAG\SearchResult> $results
 * @return array<int, mixed>
 */
function f4ResultsArrays(array $results): array
{
    return array_map(static fn ($result) => $result->toArray(), $results);
}

// ============================= FASE 4 =============================

test('F4 Configuracao: parametros de chunking centralizados', static function (): void {
    $chunking = (array) config('knowledge.chunking', []);
    assertSame(450, (int) $chunking['target_tokens']);
    assertSame(650, (int) $chunking['max_tokens']);
    assertSame(80, (int) $chunking['overlap_tokens']);
    assertSame(60, (int) $chunking['min_chunk_tokens']);
    assertSame(4, (int) $chunking['chars_per_token']);
});

test('F4 Configuracao: parametros de recuperacao centralizados', static function (): void {
    $retrieval = (array) config('knowledge.retrieval', []);
    $weights = (array) ($retrieval['weights'] ?? []);

    assertSame(5, (int) $retrieval['top_k']);
    assertSame(2.0, (float) $retrieval['minimum_relevance_score']);
    assertSame(0.5, (float) $retrieval['official_source_boost']);
    assertTrue((int) $retrieval['candidate_limit'] >= 1, 'candidate_limit invalido');

    assertSame(5.0, (float) $weights['fulltext']);
    assertSame(2.0, (float) $weights['title']);
    assertSame(1.0, (float) $weights['category']);
    assertSame(0.5, (float) $weights['subcategory']);
    assertSame(1.0, (float) $weights['tags']);
    assertSame(1.0, (float) $weights['content']);
    assertSame(0.5, (float) $weights['exact']);
});

test('F4 Configuracao: regras de busca (stopwords, tamanho minimo de termo)', static function (): void {
    $search = (array) config('knowledge.search', []);
    $stopwords = (array) ($search['stopwords'] ?? []);

    assertSame(2, (int) $search['min_term_length']);
    assertTrue(in_array('para', $stopwords, true), 'stopword "para" ausente');
    assertTrue(in_array('como', $stopwords, true), 'stopword "como" ausente');
    assertTrue(in_array('um', $stopwords, true), 'stopword "um" ausente');
});

test('F4 Chunking: estimativa de tokens consistente e documentada', static function (): void {
    $chunker = new ChunkingService();

    assertSame(25, $chunker->estimateTokens(str_repeat('a', 100)));
    assertSame(1, $chunker->estimateTokens('a'));
    assertSame(3, $chunker->estimateTokens('abcdefghij'));
    assertSame(0, $chunker->estimateTokens(''));
    assertSame(
        $chunker->estimateTokens('conteudo de exemplo para busca'),
        $chunker->estimateTokens('conteudo de exemplo para busca'),
        'Estimativa nao deterministica'
    );
});

test('F4 Chunking: texto curto vira um unico chunk indexavel', static function (): void {
    $chunker = new ChunkingService();
    $chunks = $chunker->chunkText("Configuracao de matricula:  procedimento simples.\n\nEtapa unica do fluxo oficial.");

    assertSame(1, count($chunks));
    assertTrue($chunks[0]['token_estimate'] >= 1, 'token_estimate zero');
    assertTrue($chunks[0]['search_text'] === mb_strtolower(trim($chunks[0]['search_text'])), 'search_text com caixa alta');
    assertTrue(!str_contains($chunks[0]['search_text'], '  '), 'search_text com espacos duplos');
    assertContains('etapa unica', $chunks[0]['search_text'], 'search_text nao preserva o conteudo');
});

test('F4 Chunking: titulo e lista numerada permanecem no mesmo chunk', static function (): void {
    $chunker = new ChunkingService();
    $content = "CONFIGURACAO DE MATRICULA\n\n"
        . "Para alterar a situacao de vinculo do estudante no sistema academico, "
        . "o tecnico precisa estar habilitado no modulo de registros. O procedimento "
        . "abaixo segue o fluxo oficial de configuracao da matricula.\n\n"
        . "1. Acesse o Academico no menu lateral.\n"
        . "2. Entre em Matriculas e localize o estudante.\n"
        . "3. Altere a situacao e salve.";

    $chunks = $chunker->chunkText($content);

    assertSame(1, count($chunks));
    $joined = $chunks[0]['content'];
    assertContains('CONFIGURACAO DE MATRICULA', $joined, 'heading perdido');
    assertContains('Acesse o Academico', $joined, 'item de lista perdido');
    assertContains('3.', $joined, 'item numerado perdido');
    assertContains('Altere a situacao e salve.', $joined, 'fim da lista perdido');
});

test('F4 Chunking: quebra em titulos e teto tecnico respeitado', static function (): void {
    $chunker = new ChunkingService();
    $paragraph = 'Sobre a configuracao do sistema industrial integrado, o procedimento '
        . 'padrao deve ser seguido com atencao total. Cada etapa da homologacao precisa '
        . 'ser registrada no controle de versoes. Qualquer divergencia deve ser reportada '
        . 'no chamado de suporte tecnico imediatamente.';

    $content = "# Introducao\n\n";

    for ($i = 1; $i <= 22; $i++) {
        $content .= $paragraph . "\n\n";
    }

    $content .= "# Conclusao\n\n" . $paragraph;

    $chunks = $chunker->chunkText($content);

    assertTrue(count($chunks) >= 3, 'Esperava multiplos chunks, veio: ' . count($chunks));
    assertContains('# Introducao', $chunks[0]['content'], 'Reabertura nao inicia pela introducao');
    assertTrue(!str_contains($chunks[0]['content'], 'Conclusao'), 'Conclusao entrou no chunk de introducao');

    $foundConclusion = null;

    foreach ($chunks as $i => $chunk) {
        assertTrue($chunk['token_estimate'] >= 1, "Chunk {$i} com token_estimate < 1");
        assertTrue($chunk['token_estimate'] <= 760, "Chunk {$i} excedeu o teto tecnico de tokens");

        if (str_contains($chunk['content'], '# Conclusao')) {
            $foundConclusion = $i;
        }
    }

    assertTrue($foundConclusion !== null && $foundConclusion > 0, 'Conclusao nao iniciou novo chunk');
    assertTrue(str_starts_with(trim($chunks[$foundConclusion]['content']), '# Conclusao'), 'Conclusao fora do inicio do chunk');
});

test('F4 Chunking: overlap preserva contexto entre chunks', static function (): void {
    $chunker = new ChunkingService();
    $content = '';

    for ($i = 1; $i <= 30; $i++) {
        $content .= "Torre {$i}: sobre a configuracao do sistema industrial integrado numero tres, o "
            . 'procedimento padrao deve ser seguido com atencao total. Cada etapa da '
            . 'homologacao de cada equipamento precisa ser registrada no controle de versoes '
            . 'do documento. Qualquer divergencia deve ser reportada no chamado de suporte '
            . 'tecnico imediatamente pelo operador responsavel.'
            . "\n\n";
    }

    $chunks = $chunker->chunkText($content);

    assertTrue(count($chunks) >= 2, 'Esperava multiplos chunks');

    $prefix = mb_substr($chunks[1]['content'], 0, 32);
    assertTrue(str_contains($chunks[0]['content'], $prefix), 'Overlap de contexto ausente na fronteira');
});

test('F4 Chunking: overlap desativa pela configuracao', static function (): void {
    $chunker = new ChunkingService([
        'overlap_tokens' => 0,
        'target_tokens' => 200,
        'max_tokens' => 400,
        'min_chunk_tokens' => 60,
    ]);

    $content = '';

    for ($i = 1; $i <= 20; $i++) {
        $content .= "Torre {$i}: o procedimento de manutencao preventiva do sistema deve ser executado "
            . 'pela equipe de redes no periodo noturno. A janela de manutencao precisa estar '
            . 'agendada no calendario oficial da area.'
            . "\n\n";
    }

    $chunks = $chunker->chunkText($content);

    assertTrue(count($chunks) >= 2, 'Esperava multiplos chunks');

    $prefix = mb_substr($chunks[1]['content'], 0, 32);
    assertTrue(!str_contains($chunks[0]['content'], $prefix), 'Overlap deveria estar desativado');
});

test('F4 Busca: normalizacao de consulta com stopwords', static function (): void {
    $qs = new SearchQueryService();
    $normalized = $qs->normalize('Como faco para liberar uma disciplina para um aluno?');

    assertSame('como faco para liberar uma disciplina para um aluno', $normalized['normalized']);
    assertSame(['liberar', 'disciplina', 'aluno'], $normalized['terms']);
    assertSame(['%liberar%', '%disciplina%', '%aluno%'], $normalized['like_patterns']);
});

test('F4 Busca: variante singular e dobra de acentos', static function (): void {
    $qs = new SearchQueryService();

    assertSame('disciplina', $qs->singularOf('disciplinas'));
    assertSame('aluno', $qs->singularOf('alunos'));
    assertSame('matricula', $qs->singularOf('matriculas'));
    assertSame('portal', $qs->singularOf('portal'));

    assertSame('matricula', $qs->accentFold('Matricula'));
    assertSame('turma', $qs->accentFold('TURMA'));
    assertTrue($qs->contains('Disciplinas obrigatorias', 'disciplina'), 'contains falhou no plural');
});

test('F4 Busca: consulta composta apenas de stopwords retorna vazio', static function (): void {
    $result = (new RetrievalService())->search('como para uma de e');

    assertSame([], $result['results']);
    assertSame(RetrievalService::MESSAGE_EMPTY, $result['message']);
});

test('F4 FULLTEXT: MATCH/AGAINST responde na tabela real', static function (): void {
    $service = new KnowledgeService();
    $created = $service->importManual([
        'title' => 'F4-Rede-Controle de equipamento de rede',
        'content' => 'O cabeamento estruturado de rede deve ser verificado anualmente pela equipe de infraestrutura.',
        'origin' => 'official',
        'category' => 'Configurações',
        'subcategory' => 'Rede',
        'status' => 'active',
    ], null);

    assertSame('created', $created['status']);

    try {
        $stmt = Database::connection()->prepare(
            'SELECT MATCH(content, search_text) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevancia
             FROM knowledge_chunks
             WHERE document_id = ?
               AND MATCH(content, search_text) AGAINST (? IN NATURAL LANGUAGE MODE) > 0'
        );
        $stmt->execute(['rede', (int) $created['document_id'], 'rede']);
        $row = $stmt->fetch();

        assertTrue($row !== false, 'FULLTEXT nao retornou o chunk inserido');
        assertTrue((float) $row['relevancia'] > 0.0, 'Relevancia FULLTEXT zero');
    } finally {
        f4RemoveDocuments([(int) $created['document_id']]);
    }
});

test('F4 Indexacao: import cria chunks encadeados e indexaveis', static function (): void {
    $service = new KnowledgeService();
    $paragraph = 'O procedimento oficial de configuracao de acesso do aluno ao portal '
        . 'unificado da universidade deve ser executado pelo tecnico responsavel pela '
        . 'area de suporte. E necessario validar o vinculo, conferir os dados cadastrais '
        . 'e liberar o usuario nos sistemas integrados de matricula e financeiro.';
    $content = str_repeat($paragraph . "\n\n", 8);

    $created = $service->importManual([
        'title' => 'F4-Index-Liberacao de acesso',
        'content' => $content,
        'origin' => 'official',
        'category' => 'Configurações',
        'subcategory' => 'Portal',
        'status' => 'active',
    ], null);

    assertSame('created', $created['status']);

    try {
        $id = (int) $created['document_id'];
        $repo = new KnowledgeChunkRepository();
        $chunks = $repo->findByDocument($id);

        assertTrue(count($chunks) >= 2, 'Esperava multiplos chunks');

        foreach ($chunks as $i => $chunk) {
            assertSame($i, (int) $chunk['chunk_index'], "chunk_index incorreto na posicao {$i}");
            assertTrue((int) $chunk['token_estimate'] >= 1, "token_estimate zero na posicao {$i}");
        }

        assertSame(count($chunks), $repo->countForDocument($id), 'Contagem de chunks divergente');
    } finally {
        f4RemoveDocuments([(int) $created['document_id']]);
    }
});

test('F4 Indexacao: atualizacao reindexa e substitui chunks antigos', static function (): void {
    $service = new KnowledgeService();
    $contentA = str_repeat('Conteudo original sobre o sistema academico e o registro de matricula dos estudantes ativos. ', 6);
    $contentB = str_repeat('Novo conteudo substituido focado em configuracao financeira de boletos e parcelas do estudante. ', 9);

    $created = $service->importManual([
        'title' => 'F4-Index-Documento reindexavel',
        'content' => $contentA,
        'origin' => 'internal',
        'category' => 'Configurações',
        'status' => 'active',
    ], null);

    assertSame('created', $created['status']);
    $id = (int) $created['document_id'];

    try {
        $firstChunks = (new KnowledgeChunkRepository())->findByDocument($id);
        assertTrue(count($firstChunks) >= 1, 'Import sem chunks');

        $updated = $service->update($id, [
            'title' => 'F4-Index-Documento reindexavel atualizado',
            'content' => $contentB,
            'origin' => 'internal',
            'category' => 'Financeiro',
            'status' => 'active',
        ], null);

        assertSame('updated', $updated['status']);
        assertTrue((bool) $updated['reindexed'], 'Esperava reindexacao');

        $secondChunks = (new KnowledgeChunkRepository())->findByDocument($id);
        assertTrue(count($secondChunks) >= 1, 'Chunks vazios apos reindexar');

        foreach ($secondChunks as $i => $chunk) {
            assertSame($i, (int) $chunk['chunk_index'], 'chunk_index reiniciado incorretamente');
        }

        $joined = implode(' ', array_column($secondChunks, 'content'));
        assertContains('boletos', $joined, 'Conteudo novo ausente apos reindexar');
        assertTrue(!str_contains($joined, 'original'), 'Conteudo antigo permaneceu apos reindexar');
    } finally {
        f4RemoveDocuments([$id]);
    }
});

test('F4 Indexacao: atualizacao sem mudanca de conteudo conserva chunks', static function (): void {
    $service = new KnowledgeService();
    $content = 'Conteudo estavel sobre a configuracao padrao do ambiente de producao da universidade. ';

    $created = $service->importManual([
        'title' => 'F4-Index-Conteudo estavel',
        'content' => $content,
        'origin' => 'internal',
        'category' => 'Configurações',
        'status' => 'active',
    ], null);

    assertSame('created', $created['status']);
    $id = (int) $created['document_id'];

    try {
        $before = (new KnowledgeChunkRepository())->findByDocument($id);
        assertTrue(count($before) >= 1, 'Sem chunks indexados');

        $updated = $service->update($id, [
            'title' => 'F4-Index-Titulo novo sem mudar o conteudo',
            'content' => $content,
            'origin' => 'internal',
            'category' => 'Configurações',
            'status' => 'active',
        ], null);

        assertSame('updated', $updated['status']);
        assertTrue(!(bool) $updated['reindexed'], 'Nao deveria reindexar com conteudo igual');
        assertSame($before, (new KnowledgeChunkRepository())->findByDocument($id), 'Chunks deveriam permanecer identicos');
    } finally {
        f4RemoveDocuments([$id]);
    }
});

test('F4 Recuperacao: ranking coloca o documento mais relevante em primeiro', static function (): void {
    $service = new KnowledgeService();
    $createdA = $service->importManual([
        'title' => 'F4-Rank-Mudanca de matricula do aluno',
        'content' => 'Procedimento de mudanca de matricula do aluno no sistema academico, com validacao do vinculo ativo.',
        'origin' => 'official',
        'category' => 'Acadêmico',
        'subcategory' => 'Matrícula',
        'status' => 'active',
    ], null);

    $createdC = $service->importManual([
        'title' => 'F4-Rank-Acesso ao Portal do Aluno',
        'content' => 'Como o aluno obtem acesso diario ao portal unificado e consulta suas informacoes cadastrais.',
        'origin' => 'official',
        'category' => 'Portal',
        'subcategory' => 'Portal',
        'status' => 'active',
    ], null);

    $createdD = $service->importManual([
        'title' => 'F4-Rank-Solicitacao de impressoras',
        'content' => 'Fluxo de solicitacao e troca de impressoras do campus.',
        'origin' => 'internal',
        'category' => 'Outros',
        'subcategory' => 'Matrícula',
        'status' => 'active',
    ], null);

    assertSame('created', $createdA['status']);
    assertSame('created', $createdC['status']);
    assertSame('created', $createdD['status']);

    try {
        $result = (new RetrievalService())->search('mudanca de matricula do aluno');
        $rows = f4ResultsArrays($result['results']);

        assertTrue($rows !== [], 'Nenhum resultado para a consulta');

        $ids = array_map(static fn ($row) => (int) $row['document_id'], $rows);
        assertSame((int) $createdA['document_id'], $ids[0], 'Documento mais relevante nao veio primeiro');
        assertTrue(in_array((int) $createdA['document_id'], $ids, true), 'Documento principal ausente');

        $top = $rows[0];
        assertTrue((float) $top['score'] >= 2.0, 'Score abaixo do limite minimo');
        assertTrue((float) $top['score'] >= (float) $rows[1]['score'], 'Ranking descorder');
        assertTrue(isset($top['breakdown']['title'], $top['breakdown']['content']), 'Breakdown incompleto');
        assertTrue((float) $top['breakdown']['title'] > 0.0, 'Boost de titulo nao aplicado');
    } finally {
        f4RemoveDocuments([(int) $createdA['document_id'], (int) $createdC['document_id'], (int) $createdD['document_id']]);
    }
});

test('F4 Recuperacao: boost oficial diferencia fontes equivalentes', static function (): void {
    $service = new KnowledgeService();
    $sharedTitle = 'F4-Boost-Configuracao de acesso seguro';
    $sharedContent = 'Passo a passo para liberar o acesso do aluno ao portal seguro da instituicao de ensino.';

    $official = $service->importManual([
        'title' => $sharedTitle,
        'content' => $sharedContent,
        'origin' => 'official',
        'category' => 'Configurações',
        'status' => 'active',
    ], null);

    $internal = $service->importManual([
        'title' => $sharedTitle,
        'content' => $sharedContent,
        'origin' => 'internal',
        'category' => 'Configurações',
        'status' => 'active',
    ], null);

    assertSame('created', $official['status']);
    assertSame('created', $internal['status']);

    try {
        $result = (new RetrievalService())->search('acesso portal');
        $rows = f4ResultsArrays($result['results']);

        assertTrue($rows !== [], 'Consulta sem resultados');

        $officialRow = null;
        $internalRow = null;

        foreach ($rows as $row) {
            if ((int) $row['document_id'] === (int) $official['document_id']) {
                $officialRow = $row;
            }

            if ((int) $row['document_id'] === (int) $internal['document_id']) {
                $internalRow = $row;
            }
        }

        assertTrue($officialRow !== null && $internalRow !== null, 'Fonte oficial ou interna ausente do resultado');
        assertSame(0.5, (float) $officialRow['breakdown']['official'], 'Boost oficial ausente');
        assertSame(0.0, (float) $internalRow['breakdown']['official'], 'Boost aplicado a fonte interna');
        assertTrue((float) $officialRow['score'] > (float) $internalRow['score'], 'Oficial deveria vencer pelo boost');
    } finally {
        f4RemoveDocuments([(int) $official['document_id'], (int) $internal['document_id']]);
    }
});

test('F4 Recuperacao: documento inativo excluido da busca', static function (): void {
    $service = new KnowledgeService();

    $active = $service->importManual([
        'title' => 'F4-Inat-Situacao do cadastro de aluno',
        'content' => 'O aluno visualiza sua situacao de matricula no portal do aluno por meio do cadastro pessoal.',
        'origin' => 'internal',
        'category' => 'Acadêmico',
        'subcategory' => 'Matrícula',
        'status' => 'active',
    ], null);

    $inactive = $service->importManual([
        'title' => 'F4-Inat-Copia da situacao de matricula',
        'content' => 'O estudante consulta a situacao de matricula pelo portal em ambiente de teste.',
        'origin' => 'internal',
        'category' => 'Acadêmico',
        'subcategory' => 'Matrícula',
        'status' => 'inactive',
    ], null);

    assertSame('created', $active['status']);
    assertSame('created', $inactive['status']);

    try {
        $result = (new RetrievalService())->search('situacao de matricula do aluno');
        $rows = f4ResultsArrays($result['results']);
        $ids = array_map(static fn ($row) => (int) $row['document_id'], $rows);

        assertTrue(in_array((int) $active['document_id'], $ids, true), 'Documento ativo ausente');
        assertTrue(!in_array((int) $inactive['document_id'], $ids, true), 'Documento inativo apareceu na busca');
    } finally {
        f4RemoveDocuments([(int) $active['document_id'], (int) $inactive['document_id']]);
    }
});

test('F4 Recuperacao: acentos sao equivalentes (matricula x matricula)', static function (): void {
    $service = new KnowledgeService();
    $created = $service->importManual([
        'title' => 'F4-Acento-Procedimento academico',
        'content' => 'O procedimento de alteracao de matricula do academico segue o fluxo oficial do registro academico.',
        'origin' => 'official',
        'category' => 'Acadêmico',
        'status' => 'active',
    ], null);

    assertSame('created', $created['status']);

    try {
        $semAcento = f4ResultsArrays((new RetrievalService())->search('matricula')['results']);
        $comAcento = f4ResultsArrays((new RetrievalService())->search('matrícula')['results']);
        $idsSem = array_map(static fn ($row) => (int) $row['document_id'], $semAcento);
        $idsCom = array_map(static fn ($row) => (int) $row['document_id'], $comAcento);

        assertTrue(in_array((int) $created['document_id'], $idsSem, true), 'Busca sem acento nao encontrou');
        assertTrue(in_array((int) $created['document_id'], $idsCom, true), 'Busca com acento nao encontrou');
    } finally {
        f4RemoveDocuments([(int) $created['document_id']]);
    }
});

test('F4 Recuperacao: plural encontrado pelo fallback textual', static function (): void {
    $service = new KnowledgeService();

    $createdJ = $service->importManual([
        'title' => 'F4-Plural-Lista de disciplinas disponiveis',
        'content' => 'As disciplinas do semestre atual sao apresentadas no portal do aluno apos a matricula.',
        'origin' => 'official',
        'category' => 'Acadêmico',
        'subcategory' => 'Disciplinas',
        'status' => 'active',
    ], null);

    $createdK = $service->importManual([
        'title' => 'F4-Plural-Guia da disciplina',
        'content' => 'A disciplina de calculo e obrigatoria para o curso e aparece na grade curricular.',
        'origin' => 'official',
        'category' => 'Acadêmico',
        'subcategory' => 'Disciplinas',
        'status' => 'active',
    ], null);

    assertSame('created', $createdJ['status']);
    assertSame('created', $createdK['status']);

    try {
        $rowsJ = f4ResultsArrays((new RetrievalService())->search('disciplina')['results']);
        $idsJ = array_map(static fn ($row) => (int) $row['document_id'], $rowsJ);

        assertTrue(in_array((int) $createdJ['document_id'], $idsJ, true), 'Plural no conteudo nao encontrado no singular');
        assertTrue((float) $rowsJ[array_search((int) $createdJ['document_id'], $idsJ, true)]['breakdown']['fulltext'] === 0.0, 'Fallback nao foi o unico caminho');

        $rowsK = f4ResultsArrays((new RetrievalService())->search('disciplinas')['results']);
        $idsK = array_map(static fn ($row) => (int) $row['document_id'], $rowsK);

        assertTrue(in_array((int) $createdK['document_id'], $idsK, true), 'Singular no conteudo nao encontrado no plural');
    } finally {
        f4RemoveDocuments([(int) $createdJ['document_id'], (int) $createdK['document_id']]);
    }
});

test('F4 Recuperacao: termos curtos EAD/AVA/CPF encontrados', static function (): void {
    $service = new KnowledgeService();

    $createdG = $service->importManual([
        'title' => 'F4-Curto-Ambiente virtual de apoio',
        'content' => 'O ambiente EAD utiliza AVA para disponibilizar disciplinas e materiais de apoio.',
        'origin' => 'official',
        'category' => 'EAD',
        'status' => 'active',
    ], null);

    $createdH = $service->importManual([
        'title' => 'F4-Curto-Cadastro academico de pessoa',
        'content' => 'O preenchimento do CPF do estudante e obrigatorio no cadastro academico da instituicao.',
        'origin' => 'official',
        'category' => 'Outros',
        'status' => 'active',
    ], null);

    assertSame('created', $createdG['status']);
    assertSame('created', $createdH['status']);

    try {
        foreach (['EAD', 'AVA'] as $term) {
            $ids = array_map(
                static fn ($row) => (int) $row['document_id'],
                f4ResultsArrays((new RetrievalService())->search($term)['results'])
            );
            assertTrue(in_array((int) $createdG['document_id'], $ids, true), "Termo curto {$term} nao encontrou o documento");
        }

        $idsCpf = array_map(
            static fn ($row) => (int) $row['document_id'],
            f4ResultsArrays((new RetrievalService())->search('CPF')['results'])
        );
        assertTrue(in_array((int) $createdH['document_id'], $idsCpf, true), 'Termo curto CPF nao encontrou o documento');
    } finally {
        f4RemoveDocuments([(int) $createdG['document_id'], (int) $createdH['document_id']]);
    }
});

test('F4 Recuperacao: termo curto de 2 letras via fallback e filtro de score', static function (): void {
    $service = new KnowledgeService();

    $createdStrong = $service->importManual([
        'title' => 'F4-RA-RA academico do estudante',
        'content' => 'O registro academico (RA) identifica o vinculo atual do estudante ativo na instituicao.',
        'origin' => 'official',
        'category' => 'Acadêmico',
        'status' => 'active',
    ], null);

    $createdWeak = $service->importManual([
        'title' => 'F4-WK-Manutencao do sistema de iluminacao da sala',
        'content' => 'A distribuicao central de energia precisa de manutencao preventiva periodica.',
        'origin' => 'official',
        'category' => 'Outros',
        'status' => 'active',
    ], null);

    assertSame('created', $createdStrong['status']);
    assertSame('created', $createdWeak['status']);

    try {
        $result = (new RetrievalService())->search('RA');
        $rows = f4ResultsArrays($result['results']);
        $ids = array_map(static fn ($row) => (int) $row['document_id'], $rows);

        assertTrue(in_array((int) $createdStrong['document_id'], $ids, true), 'RA nao encontrado pelo fallback');
        assertTrue(!in_array((int) $createdWeak['document_id'], $ids, true), 'Candidato fraco abaixo do score minimo nao descartado');
    } finally {
        f4RemoveDocuments([(int) $createdStrong['document_id'], (int) $createdWeak['document_id']]);
    }
});

test('F4 Recuperacao: sem resultado relevante retorna mensagem padrao', static function (): void {
    $result = (new RetrievalService())->search('Como configurar impressora termica Zebra?');

    assertSame([], $result['results']);
    assertSame(RetrievalService::MESSAGE_EMPTY, $result['message']);
});

test('F4 Recuperacao: top_k limita a quantidade de resultados', static function (): void {
    $service = new KnowledgeService();
    $created = [];

    try {
        $inserted = 7;

        for ($i = 1; $i <= $inserted; $i++) {
            $doc = $service->importManual([
                'title' => "F4-Top-Acesso controlado numero {$i}",
                'content' => "Acesso ao portal do aluno no sistema unificado numero {$i}, validado pela equipe de suporte.",
                'origin' => 'official',
                'category' => 'Portal',
                'status' => 'active',
            ], null);

            assertSame('created', $doc['status']);
            $created[] = (int) $doc['document_id'];
        }

        $result = (new RetrievalService())->search('acesso portal aluno');
        $rows = f4ResultsArrays($result['results']);

        assertTrue(count($rows) >= 1, 'Nenhum resultado esperado');
        assertSame(5, count($rows), 'top_k (5) nao limitou os resultados');

        foreach ($rows as $row) {
            assertTrue((float) $row['score'] >= 2.0, 'Resultado abaixo do score minimo');
        }

        assertTrue((int) $result['total_candidates'] >= 1, 'Sem candidatos');
    } finally {
        f4RemoveDocuments($created);
    }
});

test('F4 Recuperacao: solucao interna aparece como fonte propria', static function (): void {
    $service = new InternalSolutionService();
    $created = $service->create([
        'title' => 'F4-SOL-Matricula do aluno sumiu',
        'problem' => 'O aluno relata que a matricula nao aparece no portal do aluno.',
        'solution' => 'Confirmar o vinculo ativo no modulo academico e reliberar o acesso no portal.',
        'category' => 'Academico',
        'tags' => 'matricula, portal',
        'status' => 'active',
    ], null);

    assertSame('created', $created['status']);

    try {
        $rows = f4ResultsArrays((new RetrievalService())->search('matricula')['results']);

        $found = null;

        foreach ($rows as $row) {
            if ($row['source_type'] === 'internal' && $row['solution_id'] !== null) {
                $found = $row;
                break;
            }
        }

        assertTrue($found !== null, 'Solucao interna ausente dos resultados');
        assertSame((int) $created['solution_id'], (int) $found['solution_id']);
        assertSame('internal', $found['source_type']);
        assertTrue($found['chunk_id'] === null, 'Solucao interna deveria vir sem chunk');
        assertTrue((float) $found['score'] >= 2.0, 'Solucao interna abaixo do score minimo');

        $db = Database::connection();
        $db->prepare('DELETE FROM internal_solutions WHERE id = ?')->execute([(int) $created['solution_id']]);
        $db->exec('DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = \'solution.created\')');
    } finally {
        Database::connection()->prepare('DELETE FROM internal_solutions WHERE id = ?')->execute([(int) $created['solution_id']]);
        Database::connection()->exec('DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = \'solution.created\')');
    }
});

test('F4 Seguranca: injecao SQL neutralizada e sem efeito', static function (): void {
    $attacks = [
        "'; DROP TABLE knowledge_chunks; --",
        "' OR 1=1 --",
        '1 OR 1=1',
        'x" OR "1"="1',
    ];

    $chunkCountBefore = (int) Database::connection()
        ->query('SELECT COUNT(*) FROM knowledge_chunks')
        ->fetchColumn();

    foreach ($attacks as $attack) {
        $result = (new RetrievalService())->search($attack);

        assertTrue(is_array($result['results']), 'Resultado invalido na tentativa: ' . $attack);
        assertTrue(array_key_exists('message', $result), 'Estrutura sem a chave message na tentativa: ' . $attack);
        // `message` so preenche quando nao ha resultados; com resultados ele e
        // legitimamente null (nao e ausencia de campo, muito menos erro).
        assertTrue($result['message'] === null || is_string($result['message']), 'message invalido na tentativa: ' . $attack);
        assertTrue(isset($result['metrics']) && is_array($result['metrics']), 'Metricas ausentes na tentativa: ' . $attack);
    }

    $stmt = Database::connection()->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = '" . (string) config('db.name') . "' AND table_name = 'knowledge_chunks'");
    assertSame(1, (int) $stmt->fetchColumn(), 'Tabela knowledge_chunks foi afetada pela injecao');

    $chunkCountAfter = (int) Database::connection()
        ->query('SELECT COUNT(*) FROM knowledge_chunks')
        ->fetchColumn();
    assertSame($chunkCountBefore, $chunkCountAfter, 'Tabela knowledge_chunks perdeu linhas apos as tentativas de injecao');
});

test('F4 Estabilidade: ranking determinista para a mesma consulta', static function (): void {
    $service = new KnowledgeService();
    $created = [];

    try {
        for ($i = 1; $i <= 4; $i++) {
            $doc = $service->importManual([
                'title' => "F4-Det-Procedimento geral numero {$i}",
                'content' => "Passo a passo do procedimento de acesso numero {$i} para o aluno do portal.",
                'origin' => 'official',
                'category' => 'Configurações',
                'status' => 'active',
            ], null);

            assertSame('created', $doc['status']);
            $created[] = (int) $doc['document_id'];
        }

        $keysA = array_map(
            static fn ($row) => $row['key'],
            f4ResultsArrays((new RetrievalService())->search('procedimento de acesso do aluno')['results'])
        );
        $keysB = array_map(
            static fn ($row) => $row['key'],
            f4ResultsArrays((new RetrievalService())->search('procedimento de acesso do aluno')['results'])
        );

        assertSame($keysA, $keysB, 'Ranking variou entre execucoes');

        $unique = array_unique($keysA);
        assertSame(count($keysA), count($unique), 'Chunk duplicado na lista de resultados');
    } finally {
        f4RemoveDocuments($created);
    }
});

test('F4 Transacao: rollback nao deixa documento parcialmente indexado', static function (): void {
    $db = Database::connection();
    $officialSource = (new KnowledgeSourceRepository())->findByType('official');
    $sourceId = $officialSource !== null ? (int) $officialSource['id'] : 1;
    $title = 'F4-Tx-' . substr((string) uniqid('', true), 0, 12);
    $slug = 'f4-tx-rollback';
    $content = 'Conteudo do documento que sera descartado no rollback.';
    $hash = hash('sha256', $content);

    $db->beginTransaction();

    try {
        $stmt = $db->prepare(
            'INSERT INTO knowledge_documents (source_id, title, slug, content, content_hash, origin, status, collected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([$sourceId, $title, $slug, $content, $hash, 'Documentacao oficial UNIMESTRE', 'active']);
        $documentId = (int) $db->lastInsertId();

        $badChunk = $db->prepare(
            'INSERT INTO knowledge_chunks (document_id, chunk_index, content, token_estimate, search_text, metadata)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $badChunk->execute([$documentId, 0, $content, 10, $content, '{json invalido']);

        $db->rollBack();
    } catch (Throwable $exception) {
        $db->rollBack();
    }

    $countDoc = $db->prepare('SELECT COUNT(*) FROM knowledge_documents WHERE title = ?');
    $countDoc->execute([$title]);
    assertSame(0, (int) $countDoc->fetchColumn(), 'Documento permaneceu apos rollback');

    $countChunks = $db->prepare('SELECT COUNT(*) FROM knowledge_chunks WHERE document_id = (SELECT id FROM knowledge_documents WHERE title = ?)');
    $countChunks->execute([$title]);
    assertSame(0, (int) $countChunks->fetchColumn(), 'Chunks permaneceram apos rollback');
});

test('F4 Performance: 1.000 documentos indexados e pesquisados dentro do limite', static function (): void {
    $db = Database::connection();
    $repo = new KnowledgeDocumentRepository();
    $chunkRepo = new KnowledgeChunkRepository();
    $chunker = new ChunkingService();
    $suffix = substr((string) uniqid('', true), 0, 8);
    $officialSource = (new KnowledgeSourceRepository())->findByType('official');
    $officialSourceId = $officialSource !== null ? (int) $officialSource['id'] : 1;

    $bases = [
        'Configuracao de boleto e parcela numero {i}: o pagamento mensal do estudante pode ser gerado no modulo financeiro. A emissao do documento de cobranca segue as regras de vencimento configuradas pela unidade.',
        'Liberacao de acesso ao portal numero {i}: o aluno autentica com a matricula no ambiente academico e consulta notas, faltas e boletos. Qualquer bloqueio deve ser tratado pelo suporte.',
        'Registro de atendimento numero {i}: o atendimento presencial ou remoto deve registrar o protocolo e a solucao aplicada no sistema de chamados da instituicao.',
    ];

    try {
        $insertedDocs = 0;
        $insertedIds = [];

        for ($i = 1; $i <= 1000; $i++) {
            $content = str_replace('{i}', (string) $i, $bases[($i - 1) % count($bases)]);
            $id = $repo->create([
                'source_id' => $officialSourceId,
                'title' => "F4-PERF-{$suffix}-Documento {$i}",
                'slug' => 'f4-perf-' . $suffix . '-' . $i,
                'url' => 'https://perf.local/' . $suffix . '/' . $i,
                'category' => ['Financeiro', 'Academico', 'Atendimento'][($i - 1) % 3],
                'subcategory' => '',
                'content' => $content,
                'content_hash' => hash('sha256', $content),
                'origin' => 'Documentacao oficial UNIMESTRE',
                'status' => 'active',
            ]);
            $chunkRepo->replaceForDocument($id, $chunker->chunkText($content));
            $insertedDocs++;
            $insertedIds[] = $id;
        }

        $queries = [
            'pagamento boleto',
            'acesso ao portal do aluno',
            'emissao de cobranca',
            'registro de atendimento protocolo',
            'consulta de notas e faltas',
        ];

        $times = [];
        $retrieval = new RetrievalService();

        foreach ($queries as $query) {
            $started = microtime(true);
            $result = $retrieval->search($query);
            $times[] = round((microtime(true) - $started) * 1000, 2);
            assertTrue(is_array($result['results']), 'Retrieval falhou na consulta: ' . $query);
        }

        $avg = array_sum($times) / max(1, count($times));
        $worst = max($times);

        assertSame(1000, $insertedDocs, 'Quantidade de documentos inseridos divergente');
        assertTrue($chunkRepo->countTotal() >= 1000, 'Chunks abaixo do esperado');

        // Métrica vai para a saída da suíte, não para storage/logs (sem resíduo).
        printf("    [perf4] docs=%d chunks=%d queries=%d avg_ms=%.2f worst_ms=%.2f\n",
            $insertedDocs, $chunkRepo->countTotal(), count($queries), $avg, $worst);

        assertTrue($avg < 1500.0, 'Tempo medio excedeu o limite: ' . $avg);
        assertTrue($worst < 3000.0, 'Pior tempo excedeu o limite: ' . $worst);
    } finally {
        f4RemoveDocuments($insertedIds);

        $stmt = $db->prepare("DELETE FROM knowledge_documents WHERE title LIKE 'F4-PERF-%'");
        $stmt->execute();
    }
});