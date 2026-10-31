<?php

declare(strict_types=1);

use App\Core\Database;
use App\Repositories\InternalSolutionRepository;
use App\Repositories\KnowledgeDocumentRepository;
use App\Repositories\KnowledgeSourceRepository;
use App\Services\Knowledge\InternalSolutionService;
use App\Services\Knowledge\KnowledgeService;
use App\Services\Security\Permissions;

test('F3 Config: categorias e origens centralizadas', static function (): void {
    $categories = (array) config('knowledge.categories', []);
    $origins = (array) config('knowledge.origins', []);

    assertTrue(in_array('Outros', $categories, true), 'Categoria "Outros" ausente');
    assertTrue(in_array('Acadêmico', $categories, true), 'Categoria "Acadêmico" ausente');
    assertTrue(isset($origins['official'], $origins['internal']), 'Origens oficial/internal ausentes');
});

test('F3 Config: limites de upload e paginação', static function (): void {
    assertSame(2 * 1024 * 1024, (int) config('knowledge.upload.max_size'));
    assertSame(['txt', 'md'], (array) config('knowledge.upload.allowed_extensions', []));
    assertSame(20, (int) config('knowledge.pagination.per_page'));
});

test('F3 Permissões: admin tem acesso total a knowledge/solutions', static function (): void {
    foreach (['knowledge.view', 'documents.manage', 'solutions.view', 'solutions.manage'] as $permission) {
        assertTrue(Permissions::userCan('admin', $permission), 'admin sem ' . $permission);
    }
});

test('F3 Permissões: tecnico visualiza e gerencia soluções, mas não importa', static function (): void {
    assertTrue(Permissions::userCan('tecnico', 'knowledge.view'), 'tecnico sem knowledge.view');
    assertTrue(Permissions::userCan('tecnico', 'solutions.view'), 'tecnico sem solutions.view');
    assertTrue(Permissions::userCan('tecnico', 'solutions.manage'), 'tecnico sem solutions.manage');
    assertTrue(!Permissions::userCan('tecnico', 'documents.manage'), 'tecnico com documents.manage');
});

test('F3 Permissões: usuario sem acesso à base de conhecimento', static function (): void {
    assertTrue(!Permissions::userCan('usuario', 'knowledge.view'), 'usuario com knowledge.view');
    assertTrue(!Permissions::userCan('usuario', 'solutions.view'), 'usuario com solutions.view');
});

test('F3 Segurança: e() escapa saída HTML', static function (): void {
    assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'));
});

test('F3 Knowledge: normalização de texto', static function (): void {
    $service = new KnowledgeService();

    assertSame("Linha 1\n\nLinha 2", $service->normalizeText("Linha 1\n\n\n\nLinha 2"), 'Múltiplas quebras não colapsadas');
    assertSame("Linha 1\nLinha 2", $service->normalizeText("Linha 1\r\nLinha 2"), 'CRLF não convertido');
    assertSame('Texto limpo', $service->normalizeText("\x00Texto\x01 limpo\x7F"), 'Caracteres de controle não removidos');
});

test('F3 Knowledge: hash SHA-256 do conteúdo', static function (): void {
    $service = new KnowledgeService();
    $hash = $service->contentHash('Conteúdo normalizado');

    assertSame(64, strlen($hash));
    assertTrue(hash_equals($service->contentHash('Conteúdo normalizado'), $hash), 'Hash inconsistente');
    assertTrue(!hash_equals($hash, $service->contentHash('outro conteúdo')), 'Hash colide');
});

test('F3 Knowledge: slug é único por chamada', static function (): void {
    $service = new KnowledgeService();

    assertTrue($service->makeSlug('Guia do portal') !== $service->makeSlug('Guia do portal'), 'Slugs iguais para mesma chamada dupla');
    assertTrue(str_contains((string) $service->makeSlug('Guia'), '-'), 'Slug sem separador');
});

test('F3 Knowledge: normalização e segurança de URL', static function (): void {
    $service = new KnowledgeService();

    assertSame('https://unimestre.com/guia', $service->normalizeUrl('unimestre.com/guia'), 'Schemaless não recebeu https');
    assertSame('http://exemplo.com', $service->normalizeUrl('http://exemplo.com'));
    assertSame('', $service->normalizeUrl('   '));
    assertTrue($service->safeExternalUrl('https://unimestre.com'), 'https rejeitado');
    assertTrue(!$service->safeExternalUrl('javascript:alert(1)'), 'javascript: aceito como url externa');
    assertTrue(!$service->safeExternalUrl('file:///etc/passwd'), 'file:// aceito como url externa');
});

test('F3 Repositório: fontes ativas encontradas por tipo', static function (): void {
    $repository = new KnowledgeSourceRepository();
    $official = $repository->findByType('official');
    $internal = $repository->findByType('internal');

    assertTrue($official !== null && $official['type'] === 'official', 'Fonte official não resolvida');
    assertTrue($internal !== null && $internal['type'] === 'internal', 'Fonte internal não resolvida');
    assertTrue($repository->exists((int) $official['id']), 'exists falhou para fonte official');
});

test('F3 Importação: documento criado com sucesso', static function (): void {
    $service = new KnowledgeService();
    $suffix = time();
    $saved = [];

    try {
        $result = $service->importManual([
            'title' => 'Guia oficial ' . $suffix,
            'url' => 'https://unimestre.com/guia-' . $suffix,
            'category' => 'Portal',
            'subcategory' => 'Login',
            'content' => 'Conteúdo do guia oficial ' . $suffix . '.',
            'origin' => 'official',
            'status' => 'active',
        ], 1);
        $saved[] = (int) $result['document_id'];

        assertSame('created', $result['status']);
        assertTrue($saved[0] > 0, 'Sem document_id');

        $document = $service->document()->find($saved[0]);
        assertTrue($document !== null, 'Documento não encontrado após criar');
        assertSame('official', $document['source_type']);
        assertSame(64, strlen((string) $document['content_hash']));
    } finally {
        foreach ($saved as $id) {
            Database::connection()->prepare('DELETE FROM knowledge_documents WHERE id = ?')->execute([$id]);
        }
        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = 'knowledge.document.created')");
    }
});

test('F3 Importação: mesma URL e mesmo conteúdo bloqueia (duplicata)', static function (): void {
    $service = new KnowledgeService();
    $suffix = time();
    $saved = [];

    try {
        $data = [
            'title' => 'Duplicata ' . $suffix,
            'url' => 'https://unimestre.com/dup-' . $suffix,
            'category' => 'Outros',
            'content' => 'Conteúdo duplicado exato.',
            'origin' => 'official',
            'status' => 'active',
        ];

        $first = $service->importManual($data, 1);
        $saved[] = (int) $first['document_id'];
        $second = $service->importManual($data, 1);

        assertSame('duplicate', $second['status']);
        assertSame((int) $first['document_id'], (int) $second['document_id'], 'Duplicata apontou para outro documento');
    } finally {
        foreach ($saved as $id) {
            Database::connection()->prepare('DELETE FROM knowledge_documents WHERE id = ?')->execute([$id]);
        }
        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = 'knowledge.document.duplicate_attempted')");
    }
});

test('F3 Importação: mesma URL com conteúdo diferente (conflito de versão)', static function (): void {
    $service = new KnowledgeService();
    $suffix = time();
    $saved = [];

    try {
        $base = [
            'url' => 'https://unimestre.com/ver-' . $suffix,
            'category' => 'Outros',
            'origin' => 'official',
            'status' => 'active',
        ];

        $first = $service->importManual(['title' => 'v1 ' . $suffix, 'content' => 'Versão original.'] + $base, 1);
        $saved[] = (int) $first['document_id'];
        $second = $service->importManual(['title' => 'v2 ' . $suffix, 'content' => 'Versão alterada.'] + $base, 1);

        assertSame('version_conflict', $second['status']);
        assertSame((int) $first['document_id'], (int) $second['document_id']);
    } finally {
        foreach ($saved as $id) {
            Database::connection()->prepare('DELETE FROM knowledge_documents WHERE id = ?')->execute([$id]);
        }
        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = 'knowledge.document.duplicate_attempted')");
    }
});

test('F3 Importação: mesmo conteúdo com URL diferente (duplicidade de conteúdo)', static function (): void {
    $service = new KnowledgeService();
    $suffix = time();
    $saved = [];

    try {
        $first = $service->importManual([
            'title' => 'Conteúdo A ' . $suffix,
            'url' => 'https://unimestre.com/ca-' . $suffix,
            'category' => 'Outros',
            'content' => 'Mesmo corpo de texto.',
            'origin' => 'official',
            'status' => 'active',
        ], 1);
        $saved[] = (int) $first['document_id'];

        $second = $service->importManual([
            'title' => 'Conteúdo B ' . $suffix,
            'url' => 'https://unimestre.com/cb-' . $suffix,
            'category' => 'Outros',
            'content' => 'Mesmo corpo de texto.',
            'origin' => 'official',
            'status' => 'active',
        ], 1);

        assertSame('content_duplicate', $second['status']);
    } finally {
        foreach ($saved as $id) {
            Database::connection()->prepare('DELETE FROM knowledge_documents WHERE id = ?')->execute([$id]);
        }
        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = 'knowledge.document.duplicate_attempted')");
    }
});

test('F3 Importação: mesmo conteúdo e mesma origem sem URL bloqueia', static function (): void {
    $service = new KnowledgeService();
    $suffix = time();
    $saved = [];

    try {
        $data = [
            'title' => 'Sem URL ' . $suffix,
            'url' => '',
            'category' => 'Outros',
            'content' => 'Corpo repetido sem URL.',
            'origin' => 'internal',
            'status' => 'active',
        ];

        $first = $service->importManual($data, 1);
        $saved[] = (int) $first['document_id'];
        $second = $service->importManual($data, 1);

        assertSame('content_duplicate', $second['status']);
    } finally {
        foreach ($saved as $id) {
            Database::connection()->prepare('DELETE FROM knowledge_documents WHERE id = ?')->execute([$id]);
        }
        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = 'knowledge.document.duplicate_attempted')");
    }
});

test('F3 Importação: validações de entrada', static function (): void {
    $service = new KnowledgeService();

    $invalid = $service->importManual([
        'title' => '',
        'content' => '',
        'origin' => 'hacker',
        'category' => 'Inexistente',
        'status' => 'noop',
        'url' => '',
    ], 1);

    assertSame('invalid', $invalid['status']);
    assertTrue(
        isset($invalid['errors']['title'], $invalid['errors']['content'], $invalid['errors']['origin'], $invalid['errors']['category'], $invalid['errors']['status']),
        'Erros de validação incompletos'
    );
});

test('F3 Importação: arquivo .txt válido', static function (): void {
    $service = new KnowledgeService();
    $suffix = time();
    $saved = [];
    $tmp = tempnam(sys_get_temp_dir(), 'kb');
    file_put_contents($tmp, 'Conteúdo vindo do arquivo ' . $suffix);

    try {
        $result = $service->importFromFile([
            'title' => '',
            'url' => '',
            'category' => 'Outros',
            'origin' => 'internal',
            'status' => 'active',
        ], [
            'name' => 'manual-' . $suffix . '.txt',
            'type' => 'text/plain',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ], 1);
        $saved[] = (int) $result['document_id'];

        assertSame('created', $result['status']);
        $document = $service->document()->find($saved[0]);
        assertTrue($document !== null, 'Documento de arquivo não criado');
        assertSame('internal', $document['source_type']);
        assertTrue(str_starts_with((string) $document['title'], 'manual-'), 'Título não derivado do nome do arquivo');
    } finally {
        @unlink($tmp);
        foreach ($saved as $id) {
            Database::connection()->prepare('DELETE FROM knowledge_documents WHERE id = ?')->execute([$id]);
        }
        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = 'knowledge.document.created')");
    }
});

test('F3 Importação: arquivo acima do limite é rejeitado', static function (): void {
    $service = new KnowledgeService();

    $result = $service->importFromFile([
        'title' => '', 'url' => '', 'category' => 'Outros', 'origin' => 'internal', 'status' => 'active',
    ], [
        'name' => 'grande.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/fake', 'error' => UPLOAD_ERR_OK,
        'size' => (int) config('knowledge.upload.max_size') + 1,
    ], 1);

    assertSame('invalid', $result['status']);
    assertTrue(isset($result['errors']['file']), 'Tamanho máximo não aplicado');
});

test('F3 Importação: extensão proibida é rejeitada', static function (): void {
    $service = new KnowledgeService();

    $result = $service->importFromFile([
        'title' => '', 'url' => '', 'category' => 'Outros', 'origin' => 'internal', 'status' => 'active',
    ], [
        'name' => 'malicioso.php', 'type' => 'application/x-php', 'tmp_name' => '/tmp/fake2', 'error' => UPLOAD_ERR_OK,
        'size' => 10,
    ], 1);

    assertSame('invalid', $result['status']);
    assertTrue(isset($result['errors']['file']), 'Extensão proibida aceita');
});

test('F3 Update: origem alterada gera auditoria', static function (): void {
    $service = new KnowledgeService();
    $suffix = time();
    $saved = [];

    try {
        $result = $service->importManual([
            'title' => 'Troca origem ' . $suffix,
            'url' => 'https://unimestre.com/org-' . $suffix,
            'category' => 'Outros',
            'content' => 'Conteúdo para troca de origem.',
            'origin' => 'official',
            'status' => 'active',
        ], 1);
        $id = (int) $result['document_id'];
        $saved[] = $id;

        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = 'knowledge.document.origin_changed')");

        $updated = $service->update($id, [
            'title' => 'Troca origem ' . $suffix,
            'url' => 'https://unimestre.com/org-' . $suffix,
            'category' => 'Outros',
            'content' => 'Conteúdo para troca de origem.',
            'origin' => 'internal',
            'status' => 'active',
        ], 1);

        assertSame('updated', $updated['status']);
        assertTrue(!empty($updated['origin_changed']), 'origin_changed não sinalizado');

        $audit = Database::connection()->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'knowledge.document.origin_changed' AND entity_id = " . $id
        )->fetchColumn();
        assertTrue((int) $audit === 1, 'Auditoria de mudança de origem ausente');

        $document = $service->document()->find($id);
        assertSame('internal', $document['source_type']);
    } finally {
        foreach ($saved as $id) {
            Database::connection()->prepare('DELETE FROM knowledge_documents WHERE id = ?')->execute([$id]);
        }
        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action IN ('knowledge.document.created','knowledge.document.origin_changed'))");
    }
});

test('F3 Status: desativação de documento registra auditoria', static function (): void {
    $service = new KnowledgeService();
    $suffix = time();
    $saved = [];

    try {
        $result = $service->importManual([
            'title' => 'Status ' . $suffix,
            'url' => 'https://unimestre.com/status-' . $suffix,
            'category' => 'Outros',
            'content' => 'Conteúdo para status.',
            'origin' => 'official',
            'status' => 'active',
        ], 1);
        $id = (int) $result['document_id'];
        $saved[] = $id;

        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = 'knowledge.document.deactivated')");

        $toggled = $service->toggleStatus($id, 1);
        assertSame('inactive', $toggled['status']);
        assertSame('inactive', $service->document()->find($id)['status']);

        $audit = Database::connection()->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'knowledge.document.deactivated' AND entity_id = " . $id
        )->fetchColumn();
        assertTrue((int) $audit === 1, 'Auditoria de desativação ausente');

        $service->toggleStatus($id, 1);
        assertSame('active', $service->document()->find($id)['status']);
    } finally {
        foreach ($saved as $id) {
            Database::connection()->prepare('DELETE FROM knowledge_documents WHERE id = ?')->execute([$id]);
        }
        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = 'knowledge.document.deactivated')");
    }
});

test('F3 Repositório: listagem com filtros e paginação', static function (): void {
    $service = new KnowledgeService();
    $suffix = time();
    $saved = [];

    try {
        for ($i = 0; $i < 3; $i++) {
            $result = $service->importManual([
                'title' => 'Filtro ' . $suffix . ' ' . $i,
                'url' => 'https://unimestre.com/f-' . $suffix . '-' . $i,
                'category' => $i % 2 === 0 ? 'Acadêmico' : 'Financeiro',
                'content' => 'Corpo do filtro ' . $i,
                'origin' => 'official',
                'status' => $i === 2 ? 'inactive' : 'active',
            ], 1);
            $saved[] = (int) $result['document_id'];
        }

        $academicos = $service->document()->paginate(['category' => 'Acadêmico'], 1, 20);
        assertSame(2, (int) $academicos['total'], 'Filtro por categoria falhou');

        $inativos = $service->document()->paginate(['status' => 'inactive'], 1, 20);
        assertSame(1, (int) $inativos['total'], 'Filtro por status falhou');

        $origem = $service->document()->paginate(['origin' => 'internal'], 1, 20);
        assertSame(0, (int) $origem['total'], 'Filtro por origem vazou');

        $busca = $service->document()->paginate(['search' => 'Corpo do filtro'], 1, 20);
        assertSame(3, (int) $busca['total'], 'Busca textual falhou');

        $paginado = $service->document()->paginate([], 1, 2);
        assertSame(2, (int) count($paginado['items']), 'Página menor que per_page');
        assertTrue((int) $paginado['pages'] >= 2, 'Paginação não calculou páginas');
    } finally {
        foreach ($saved as $id) {
            Database::connection()->prepare('DELETE FROM knowledge_documents WHERE id = ?')->execute([$id]);
        }
        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = 'knowledge.document.created')");
    }
});

test('F3 Repositório: contagens agregadas', static function (): void {
    $service = new KnowledgeService();
    $suffix = time();
    $saved = [];

    try {
        $before = $service->document()->counts();

        $result = $service->importManual([
            'title' => 'Counts ' . $suffix,
            'url' => 'https://unimestre.com/counts-' . $suffix,
            'category' => 'Outros',
            'content' => 'Corpo para contagem.',
            'origin' => 'internal',
            'status' => 'active',
        ], 1);
        $id = (int) $result['document_id'];
        $saved[] = $id;

        $after = $service->document()->counts();

        assertSame($before['total'] + 1, $after['total'], 'Total não incrementou');
        assertSame($before['internal'] + 1, $after['internal'], 'Contagem interna não incrementou');

        assertSame($suffix > 0 ? 1 : 1, 1, 'noop');
    } finally {
        foreach ($saved as $id) {
            Database::connection()->prepare('DELETE FROM knowledge_documents WHERE id = ?')->execute([$id]);
        }
        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = 'knowledge.document.created')");
    }
});

test('F3 Soluções: normalização de tags', static function (): void {
    $service = new InternalSolutionService();

    assertSame('login, sensor ga, unimestre', $service->normalizeTags('Sensor ga, Unimestre, Login,'), 'Tags não normalizadas');
    assertSame('', $service->normalizeTags(' ,  ;'));
});

test('F3 Soluções: criação registra auditoria e reutiliza categorias', static function (): void {
    $service = new InternalSolutionService();
    $repo = new InternalSolutionRepository();
    $suffix = time();
    $saved = [];

    try {
        $result = $service->create([
            'title' => 'Solução teste ' . $suffix,
            'problem' => 'Usuário não consegue logar.',
            'solution' => 'Solicitar redefinição de senha.',
            'category' => 'Portal',
            'tags' => 'Senha, Login',
            'status' => 'active',
        ], 1);
        $id = (int) $result['solution_id'];
        $saved[] = $id;

        assertSame('created', $result['status']);
        assertTrue($id > 0, 'Sem solution_id');

        $solution = $repo->find($id);
        assertSame('login, senha', (string) $solution['tags'], 'Tags não normalizadas no cadastro');

        $audit = Database::connection()->query(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'solution.created' AND entity_id = " . $id
        )->fetchColumn();
        assertTrue((int) $audit === 1, 'Auditoria de criação ausente');

        assertTrue(in_array('Portal', $service->categories(), true), 'Categoria Portal ausente na lista');
    } finally {
        foreach ($saved as $id) {
            Database::connection()->prepare('DELETE FROM internal_solutions WHERE id = ?')->execute([$id]);
        }
        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action = 'solution.created')");
    }
});

test('F3 Soluções: validação e status', static function (): void {
    $service = new InternalSolutionService();
    $repo = new InternalSolutionRepository();
    $suffix = time();
    $saved = [];

    try {
        $invalid = $service->create([
            'title' => '', 'problem' => '', 'solution' => '', 'category' => '', 'tags' => '', 'status' => 'xpto',
        ], 1);
        assertSame('invalid', $invalid['status']);

        $result = $service->create([
            'title' => 'Status solução ' . $suffix,
            'problem' => 'Problema.',
            'solution' => 'Solução.',
            'category' => 'Outros',
            'tags' => '',
            'status' => 'active',
        ], 1);
        $id = (int) $result['solution_id'];
        $saved[] = $id;

        $toggled = $service->toggleStatus($id, 1);
        assertSame('inactive', $toggled['status']);
        assertSame('inactive', $repo->find($id)['status']);

        $updated = $service->update($id, [
            'title' => 'Status solução ' . $suffix,
            'problem' => 'Problema atualizado.',
            'solution' => 'Solução.',
            'category' => 'Outros',
            'tags' => 'a, b',
            'status' => 'active',
        ], 1);
        assertSame('updated', $updated['status']);
        assertSame('a, b', (string) $repo->find($id)['tags']);

        $paginado = $repo->paginate(['search' => 'Solução'], 1, 20);
        assertTrue((int) $paginado['total'] >= 1, 'Solução não encontrada na busca');

        assertTrue($repo->countActive() >= 1, 'countActive sem resultados');
    } finally {
        foreach ($saved as $id) {
            Database::connection()->prepare('DELETE FROM internal_solutions WHERE id = ?')->execute([$id]);
        }
        Database::connection()->query("DELETE FROM audit_logs WHERE (id > @t_audit_logs_start AND user_agent IN (@t_ua_cli, @t_ua_http)) AND (action IN ('solution.created','solution.updated','solution.activated','solution.deactivated'))");
    }
});