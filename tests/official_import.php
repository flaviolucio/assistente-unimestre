<?php

declare(strict_types=1);

/**
 * Importação oficial em massa (arquivos locais) — testes locais, SEM rede.
 * Execução isolada: php tests/official_import.php
 * Fixtures em pasta temporária; documentos criados são rastreados e removidos
 * pelo TestIsolation (chunks em cascata). O documento 39922 é só lido.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/TestIsolation.php';
require_once __DIR__ . '/support/PdfFixture.php';

use App\Core\Database;
use App\Services\Knowledge\Import\HtmlContentExtractor;
use App\Services\Knowledge\Import\OfficialImportService;
use App\Services\Knowledge\Import\OfficialUrlPolicy;
use App\Services\Knowledge\KnowledgeService;
use App\Services\RAG\ChunkingService;
use App\Services\Security\Permissions;

error_reporting(E_ALL & ~E_DEPRECATED);

TestIsolation::begin();

$importLog = config('paths.logs') . '/knowledge_import.log';
$importLogExisted = is_file($importLog);
$importLogSize = $importLogExisted ? (int) filesize($importLog) : 0;
$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oi_test_' . bin2hex(random_bytes(4));
$dir = $tmpRoot . '/import';
$stateDir = $tmpRoot . '/state';
mkdir($dir, 0775, true);
mkdir($stateDir, 0775, true);

register_shutdown_function(static function () use ($tmpRoot, $importLog, $importLogExisted, $importLogSize): void {
    TestIsolation::finish();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmpRoot);
    // O log de importação não pode guardar resíduo dos testes.
    if (!$importLogExisted) {
        @unlink($importLog);
    } elseif (is_file($importLog)) {
        $h = fopen($importLog, 'r+');
        ftruncate($h, $importLogSize);
        fclose($h);
    }
});

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

function oiPage(string $canonical, string $title, string $body, string $category = 'Notas', bool $canonicalTag = true): string
{
    $link = $canonicalTag ? '<link rel="canonical" href="' . htmlspecialchars($canonical) . '">' : '';

    return '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . ' – UNIMESTRE</title>' . $link . '
<script>var tracker = "SCRIPT_RUIDO";</script><style>.x{color:red}</style></head><body>
<header class="site-header"><nav class="main-menu"><a href="/">Início</a> <a href="/menu">MENU_RUIDO</a></nav></header>
<div class="cookie-banner">Aceitar cookies COOKIE_RUIDO</div>
<nav class="breadcrumbs"><a href="/">Início</a> › <a href="/basedeconhecimento/">Base de Conhecimento</a></nav>
<article><h1>' . $title . '</h1><span class="cat-links"><a rel="category tag" href="/cat">' . $category . '</a></span>
<div class="entry-content">' . $body . '<div class="share-buttons">Compartilhar SHARE_RUIDO</div></div></article>
<aside class="sidebar">Posts relacionados SIDEBAR_RUIDO <a href="https://blog.unimestre.com/x">blog</a></aside>
<footer class="site-footer">Todos os direitos FOOTER_RUIDO</footer></body></html>';
}

$notasBody = '<p>Para lançar as notas da avaliação, o professor acessa o diário da turma e informa a nota de cada aluno.</p>
<h2>Passo a passo</h2><ol><li>Abra o <strong>Diário</strong> da disciplina.<ul><li>Selecione a turma</li><li>Selecione a avaliação</li></ul></li><li>Informe as notas e clique em Salvar.</li></ol>
<table><tr><th>Parâmetro</th><th>Descrição</th></tr><tr><td>nota_maxima</td><td>Nota máxima da avaliação</td></tr></table>
<p>Veja também <a href="https://unimestre.com/basedeconhecimento/calculo-da-media/">cálculo da média</a>.</p>
<p><strong>Importante:</strong> a média só é recalculada após salvar todas as avaliações do período.</p>';

$senha = Database::connection()->query('SELECT url, content, content_hash FROM knowledge_documents WHERE id = 39922')->fetch();

// ---------------------------------------------------------------- fixtures
file_put_contents($dir . '/01_notas.html', oiPage('https://www.unimestre.com/basedeconhecimento/lancamento-de-notas?utm_source=news#topo', 'Lançamento de notas', $notasBody));
// Mesma URL (variação www/barra) → duplicado no lote
file_put_contents($dir . '/02_notas_copia.html', oiPage('https://unimestre.com/basedeconhecimento/lancamento-de-notas/', 'Lançamento de notas (cópia)', $notasBody . '<p>texto extra</p>'));
// Mesmo conteúdo, outra URL → duplicado por hash
file_put_contents($dir . '/03_notas_outra_url.html', oiPage('https://unimestre.com/basedeconhecimento/notas-outra-url/', 'Lançamento de notas', $notasBody));
// Documento 39922: mesma URL e MESMO conteúdo (txt via manifest) → JÁ EXISTE
file_put_contents($dir . '/04_senha.txt', (string) $senha['content']);
// Mesma URL do 39922, conteúdo diferente (HTML) → ATUALIZAÇÃO DISPONÍVEL
mkdir($dir . '/sub');
file_put_contents($dir . '/sub/05_senha_nova.html', oiPage((string) $senha['url'], 'Esqueci minha senha', '<p>Versão nova do procedimento de recuperação de senha pelo portal, com validade de 2 dias para a solicitação enviada por e-mail.</p>', 'Portal'));
// Hosts não autorizados / SSRF
file_put_contents($dir . '/06_externo.html', oiPage('https://evil.example.org/basedeconhecimento/x/', 'Externo', '<p>' . str_repeat('conteúdo externo ', 20) . '</p>'));
file_put_contents($dir . '/07_blog.html', oiPage('https://blog.unimestre.com/post/', 'Blog', '<p>' . str_repeat('conteúdo do blog ', 20) . '</p>'));
file_put_contents($dir . '/08_local.html', oiPage('http://127.0.0.1/admin/', 'Local', '<p>' . str_repeat('conteúdo local ', 20) . '</p>'));
// Sem URL → ignorado
file_put_contents($dir . '/09_sem_url.html', oiPage('', 'Sem URL', '<p>' . str_repeat('conteúdo sem url ', 20) . '</p>', 'Outros', false));
// Conteúdo curto → ignorado
file_put_contents($dir . '/10_curto.html', oiPage('https://unimestre.com/basedeconhecimento/curto/', 'Curto', '<p>Oi.</p>'));
// URL pelo comentário "saved from" (sem canonical) + categoria financeira
file_put_contents($dir . '/11_boleto.html', "<!-- saved from url=(0058)https://unimestre.com/basedeconhecimento/segunda-via-boleto -->\n"
    . oiPage('', 'Segunda via de boleto', '<p>A segunda via do boleto é emitida pelo portal financeiro informando o título em aberto e o vencimento atualizado para pagamento.</p>', 'Financeiro', false));
// "PDF" inválido (não é um PDF de verdade) → EXTRAÇÃO FALHOU, nunca importado
file_put_contents($dir . '/12_manual.pdf', "%PDF-1.4\n% fixture\n" . str_repeat('x', 200));
// Não permitido (.php) é ignorado pela listagem
file_put_contents($dir . '/13_script.php', '<?php echo 1;');
file_put_contents($dir . '/manifest.csv', "file,url,title,category\n04_senha.txt," . $senha['url'] . ",Esqueci minha senha,Portal\n12_manual.pdf,https://manual.unimestre.com/manual-academico.pdf,Manual Acadêmico,Acadêmico\n");

$docsBefore = (int) Database::connection()->query('SELECT COUNT(*) FROM knowledge_documents')->fetchColumn();
$service = new OfficialImportService($dir, $stateDir);
$state = null;

// ---------------------------------------------------------------- políticas
test('URL: canonicalização (https, sem www, sem utm/fragmento, barra final)', static function (): void {
    $p = new OfficialUrlPolicy();
    assertSame('https://unimestre.com/basedeconhecimento/lancamento-de-notas/', $p->canonicalize('http://WWW.Unimestre.com//basedeconhecimento/lancamento-de-notas?utm_source=x&fbclid=1#topo'));
    assertSame('https://manual.unimestre.com/doc/manual.pdf', $p->canonicalize('https://manual.unimestre.com/doc/manual.pdf'));
    assertSame('https://unimestre.com/?a=1&b=2', $p->canonicalize('https://unimestre.com/?b=2&a=1'));
});

test('URL: allowlist exata e bloqueio de SSRF/esquemas', static function (): void {
    $p = new OfficialUrlPolicy();
    assertTrue($p->isAllowed('https://unimestre.com/x') && $p->isAllowed('https://www.unimestre.com/x') && $p->isAllowed('https://manual.unimestre.com/x'));
    foreach (['https://blog.unimestre.com/x', 'https://unimestre.com.evil.org/x', 'http://127.0.0.1/', 'http://10.0.0.5/', 'http://[::1]/', 'file:///etc/passwd',
        'ftp://unimestre.com/x', 'data:text/html,oi', 'https://user:pass@unimestre.com/', 'https://unimestre.com:8443/', 'javascript:alert(1)'] as $bad) {
        assertTrue(!$p->isAllowed($bad), 'Aceitou: ' . $bad);
    }
    assertTrue($p->isUnlistedOfficialSubdomain('https://blog.unimestre.com/x'));
});

test('HTML: extrai só o artigo, remove ruído e preserva estrutura', static function () use ($notasBody): void {
    $r = (new HtmlContentExtractor())->extract(oiPage('https://unimestre.com/basedeconhecimento/a/', 'Lançamento de notas', $notasBody));
    $c = $r['content'];

    assertSame('Lançamento de notas', $r['title']);
    assertSame('Notas', $r['category']);
    assertSame('canonical', $r['url_source']);
    foreach (['SCRIPT_RUIDO', 'MENU_RUIDO', 'COOKIE_RUIDO', 'SHARE_RUIDO', 'SIDEBAR_RUIDO', 'FOOTER_RUIDO', 'color:red'] as $noise) {
        assertTrue(!str_contains($c, $noise), 'Ruído não removido: ' . $noise);
    }
    assertTrue(str_contains($c, '## Passo a passo'), 'Subtítulo perdido');
    assertTrue(str_contains($c, '1. Abra o Diário da disciplina.'), 'Lista numerada perdida');
    assertTrue(str_contains($c, '  - Selecione a turma'), 'Sublista perdida');
    assertTrue(str_contains($c, 'nota_maxima | Nota máxima da avaliação'), 'Tabela perdida');
    assertTrue(str_contains($c, 'cálculo da média (https://unimestre.com/basedeconhecimento/calculo-da-media/)'), 'Link perdido');
    assertTrue(str_contains($c, 'Importante: a média só é recalculada'), 'Aviso perdido');
});

// ---------------------------------------------------------------- dry run
test('DRY RUN: classifica tudo e NÃO grava documentos', static function () use ($service, $docsBefore, &$state): void {
    $state = $service->analyze(null);
    $byFile = [];
    foreach ($state['items'] as $item) {
        $byFile[$item['file']] = $item;
    }

    assertSame($docsBefore, (int) Database::connection()->query('SELECT COUNT(*) FROM knowledge_documents')->fetchColumn(), 'Dry run gravou documentos');
    assertSame(12, count($state['items']), 'Arquivos listados (o .php e o manifest não entram)');
    assertTrue(!isset($byFile['13_script.php']));
    assertSame('new', $byFile['01_notas.html']['status']);
    assertSame('https://unimestre.com/basedeconhecimento/lancamento-de-notas/', $byFile['01_notas.html']['canonical_url']);
    assertSame('Acadêmico', $byFile['01_notas.html']['category'], 'Mapeamento de categoria');
    assertSame('Notas', $byFile['01_notas.html']['category_original'], 'Categoria original da fonte perdida');
    assertSame('duplicate', $byFile['02_notas_copia.html']['status'], 'Mesma URL no lote');
    assertSame('duplicate', $byFile['03_notas_outra_url.html']['status'], 'Mesmo conteúdo no lote');
    assertSame('exists', $byFile['04_senha.txt']['status'], '39922 não reconhecido como existente');
    assertSame(39922, $byFile['04_senha.txt']['existing_document_id']);
    assertSame('update', $byFile['sub/05_senha_nova.html']['status'], 'Atualização não detectada');
    assertSame('blocked', $byFile['06_externo.html']['status']);
    assertSame('blocked', $byFile['07_blog.html']['status']);
    assertSame('blocked', $byFile['08_local.html']['status']);
    assertSame('ignored', $byFile['09_sem_url.html']['status']);
    assertSame('ignored', $byFile['10_curto.html']['status']);
    assertSame('new', $byFile['11_boleto.html']['status']);
    assertSame('saved_from', $byFile['11_boleto.html']['url_source']);
    assertSame('Financeiro', $byFile['11_boleto.html']['category']);
    assertSame('pdf_failed', $byFile['12_manual.pdf']['status'], 'PDF inválido deve ser EXTRAÇÃO FALHOU');
    assertSame('Manual Acadêmico', $byFile['12_manual.pdf']['title']);
    assertTrue(in_array('blog.unimestre.com', $state['unlisted_hosts'], true), 'Subdomínio não listado para análise');

    $s = $state['summary'];
    assertSame([2, 1, 1, 2, 1, 3, 2], [$s['new'], $s['exists'], $s['update'], $s['duplicate'], $s['pdf'], $s['blocked'], $s['ignored']]);
    assertSame(1, $s['pdf_failed']);
    assertTrue($byFile['01_notas.html']['est_chunks'] >= 1, 'Estimativa de chunks');
});

// ---------------------------------------------------------------- importação
test('IMPORTAR SELECIONADOS: só o selecionado entra na fila', static function () use ($service, &$state): void {
    $notasId = null;
    foreach ($state['items'] as $item) {
        if ($item['file'] === '01_notas.html') {
            $notasId = $item['id'];
        }
    }
    $q = $service->queue($state['batch_id'], [$notasId], null);
    assertSame(1, $q['summary']['queued']);
});

test('Lote + RETOMADA: nova instância continua de onde parou; mesmo pipeline de chunking', static function () use ($dir, $stateDir, &$state): void {
    // "Navegador fechou": uma nova instância do serviço retoma pelo estado salvo.
    $resumed = (new OfficialImportService($dir, $stateDir))->importNext($state['batch_id'], 1, null);
    assertSame(0, $resumed['summary']['queued']);
    assertSame(1, $resumed['summary']['imported']);

    $item = null;
    foreach ($resumed['items'] as $i) {
        if ($i['import_status'] === 'imported') {
            $item = $i;
        }
    }
    TestIsolation::track('knowledge_documents', (int) $item['document_id']);
    $doc = Database::connection()->query('SELECT d.*, s.type FROM knowledge_documents d JOIN knowledge_sources s ON s.id = d.source_id WHERE d.id = ' . (int) $item['document_id'])->fetch();
    $meta = json_decode((string) $doc['metadata'], true);

    assertSame('official', $doc['type']);
    assertSame('https://unimestre.com/basedeconhecimento/lancamento-de-notas/', $doc['url']);
    assertSame('Acadêmico', $doc['category']);
    assertSame('Notas', $doc['subcategory']);
    assertSame($item['hash'], $doc['content_hash']);
    assertSame('official_unimestre', $meta['source_type'] ?? null);
    assertSame('html', $meta['import_format'] ?? null);
    assertSame('https://unimestre.com/basedeconhecimento/lancamento-de-notas/', $meta['canonical_url'] ?? null);
    assertTrue(!empty($meta['fetched_at']) && !empty($meta['last_verified_at']), 'Datas de coleta/verificação ausentes');

    $chunks = (int) Database::connection()->query('SELECT COUNT(*) FROM knowledge_chunks WHERE document_id = ' . (int) $doc['id'])->fetchColumn();
    assertSame(count((new ChunkingService())->chunkText((string) $doc['content'])), $chunks, 'Chunking diferente do pipeline aprovado');
    assertSame($chunks, $item['chunks']);
});

test('IMPORTAR TODOS OS NOVOS + erro parcial (arquivo alterado após a análise)', static function () use ($service, $dir, &$state): void {
    // O boleto é alterado depois da análise → deve FALHAR sem importar às cegas.
    file_put_contents($dir . '/11_boleto.html', str_replace('vencimento atualizado', 'vencimento ALTERADO', (string) file_get_contents($dir . '/11_boleto.html')));
    $q = $service->queue($state['batch_id'], null, null);
    assertSame(1, $q['summary']['queued'], 'Só os novos ainda não importados entram');
    $r = $service->importNext($state['batch_id'], 25, null);

    assertSame(0, $r['summary']['queued']);
    assertSame(1, $r['summary']['failed']);
    assertSame(1, $r['summary']['imported'], 'Importado anterior preservado');
    foreach ($r['items'] as $i) {
        if ($i['import_status'] === 'failed') {
            assertTrue(str_contains((string) $i['error'], 'alterado depois da análise'), 'Motivo do erro');
        }
    }
    assertTrue(!empty($r['import']['finished_at']), 'Lote não finalizado');
});

test('Documento 39922 intacto; nenhum existente alterado', static function () use ($senha, $docsBefore): void {
    $now = Database::connection()->query('SELECT url, content_hash FROM knowledge_documents WHERE id = 39922')->fetch();
    assertSame($senha['content_hash'], $now['content_hash']);
    assertSame($senha['url'], $now['url']);
    assertSame($docsBefore + 1, (int) Database::connection()->query('SELECT COUNT(*) FROM knowledge_documents')->fetchColumn(), 'Mais documentos que o esperado');
});

test('Concorrência: lote travado recusa segundo processamento', static function () use ($service, $stateDir, &$state): void {
    $lock = fopen($stateDir . '/batch_' . $state['batch_id'] . '.json.lock', 'c');
    flock($lock, LOCK_EX);
    $thrown = false;
    try {
        $service->importNext($state['batch_id'], 1, null);
    } catch (RuntimeException) {
        $thrown = true;
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    assertTrue($thrown, 'Processamento concorrente permitido');
    $bad = false;
    try {
        $service->importNext('../../etc', 1, null);
    } catch (RuntimeException) {
        $bad = true;
    }
    assertTrue($bad, 'batch_id malicioso aceito');
});

test('ZIP: bloqueia path traversal, extensões proibidas e mantém tudo dentro da pasta', static function () use ($tmpRoot): void {
    $zipPath = $tmpRoot . '/pkg.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('../fora.html', '<p>fora</p>');
    $zip->addFromString('pasta/../../fora2.html', '<p>fora</p>');
    $zip->addFromString('shell.php', '<?php echo 1;');
    $zip->addFromString('ok/pagina.html', '<p>ok</p>');
    $zip->addFromString('manifest.csv', "file,url\n");
    $zip->close();

    $zipDir = $tmpRoot . '/zipimport';
    $r = (new OfficialImportService($zipDir, $tmpRoot . '/zipstate'))->uploadZip($zipPath, 'pkg.zip');

    assertSame('ok', $r['status']);
    assertSame(2, $r['extracted'], 'Arquivos extraídos');
    assertSame(3, $r['skipped'], 'Entradas maliciosas não bloqueadas');
    assertTrue(!is_file($tmpRoot . '/fora.html') && !is_file(dirname($tmpRoot) . '/fora.html') && !is_file($tmpRoot . '/fora2.html'), 'Zip slip!');
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($zipDir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $found[] = $f->getFilename();
    }
    sort($found);
    assertSame(['manifest.csv', 'pagina.html'], $found);
});

test('Segurança: somente admin; rotas protegidas; sem rede e sem IA no código', static function (): void {
    assertTrue(Permissions::userCan('admin', 'knowledge.bulk_import'));
    assertTrue(!Permissions::userCan('tecnico', 'knowledge.bulk_import') && !Permissions::userCan('usuario', 'knowledge.bulk_import'));
    $routes = (string) file_get_contents(APP_PATH . '/routes/web.php');
    assertSame(6, substr_count($routes, "'permission:knowledge.bulk_import'"), 'Rota sem permissão');
    foreach (glob(APP_PATH . '/app/Services/Knowledge/Import/*.php') as $file) {
        $code = (string) file_get_contents($file);
        foreach (['curl_', 'CurlTransport', 'AIService', 'fsockopen', 'stream_socket_client', "file_get_contents('http", 'file_get_contents("http', 'shell_exec', 'proc_open', 'exec('] as $forbidden) {
            assertTrue(!str_contains($code, $forbidden), basename($file) . ' contém ' . $forbidden);
        }
    }
});

// ---------------------------------------------------------------- V2: DokuWiki + PDF + mídia + manifest v2
$v2 = $tmpRoot . '/v2';
$v2State = $tmpRoot . '/v2state';
foreach (['data/pages/academico', 'data/pages/atualizacoes', 'data/media/manual', 'html', 'txt'] as $d) {
    mkdir($v2 . '/' . $d, 0775, true);
}
file_put_contents($v2 . '/data/pages/academico/notas.txt', "====== Fechamento de Notas ======\n\nO fechamento consolida as **avaliações** do período letivo da turma.\n\n===== Passos =====\n\n  * Conferir as notas lançadas\n    * Revisar pendências\n  * Fechar o diário\n\nVeja [[calculo_media|cálculo da média]] e {{:manual:fechamento_notas.pdf|Manual em PDF}}.\n");
file_put_contents($v2 . '/data/pages/academico/calculo_media.txt', "====== Cálculo da Média ======\n\nA média final é calculada pela soma ponderada das avaliações dividida pela soma dos pesos.\n\n^ Avaliação ^ Peso ^\n| P1 | 4 |\n| P2 | 6 |\n");
file_put_contents($v2 . '/data/pages/atualizacoes/versao_5_2.txt', "====== Versão 5.2 ======\n\n===== Novidades =====\n\n  * Novo relatório de frequência por disciplina e por período letivo\n  * Ajuste no cálculo da média ponderada das avaliações\n");
file_put_contents($v2 . '/data/media/manual/fechamento_notas.pdf', PdfFixture::text([['Manual de fechamento de notas', 'O coordenador confere os diários e confirma o fechamento do período letivo.']], 'Manual de Fechamento de Notas'));
file_put_contents($v2 . '/data/media/manual/digitalizado.pdf', PdfFixture::scanned());
file_put_contents($v2 . '/data/media/manual/tela.png', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64));
$boletoHtml = oiPage('https://unimestre.com/basedeconhecimento/boleto-v2/', 'Boleto registrado', '<p>O boleto registrado é emitido pelo portal financeiro com o valor e o vencimento atualizados para pagamento.</p>', 'Financeiro');
file_put_contents($v2 . '/html/boleto.html', $boletoHtml);
file_put_contents($v2 . '/txt/boleto.txt', (new HtmlContentExtractor())->extract($boletoHtml)['content']);
file_put_contents($v2 . '/manifest.csv', "file,source_url,title,category,subcategory,published_at,source_modified_at,source_modified_type\n"
    . "data/pages/academico/calculo_media.txt,https://manual.unimestre.com/academico/calculo_media,Cálculo da Média Final,Acadêmico,Notas,10/01/2024,05/03/2024 14:22,edição externa\n"
    . "txt/boleto.txt,https://unimestre.com/basedeconhecimento/boleto-txt/,Boleto (txt),Financeiro,,,,\n");

$v2Service = new OfficialImportService($v2, $v2State);
$v2Batch = null;
$v2ByFile = static function (array $state): array {
    $out = [];
    foreach ($state['items'] as $item) {
        $out[$item['file']] = $item;
    }
    return $out;
};

test('V2 DRY RUN: formatos, namespace/slug, manifest v2, datas, tipo, PDF e mídia', static function () use ($v2Service, $v2ByFile, $docsBefore, &$v2Batch): void {
    $st = $v2Service->analyze(null);
    $v2Batch = $st['batch_id'];
    $f = $v2ByFile($st);
    $notas = $f['data/pages/academico/notas.txt'];
    $calc = $f['data/pages/academico/calculo_media.txt'];
    $versao = $f['data/pages/atualizacoes/versao_5_2.txt'];
    $pdf = $f['data/media/manual/fechamento_notas.pdf'];

    assertSame($docsBefore + 1, (int) Database::connection()->query('SELECT COUNT(*) FROM knowledge_documents')->fetchColumn(), 'Dry run V2 gravou');
    assertSame(['dokuwiki', 'academico', 'notas', 'dokuwiki:academico:notas', 'new', null], [$notas['format'], $notas['namespace'], $notas['slug'], $notas['source_identifier'], $notas['status'], $notas['canonical_url']], 'DokuWiki sem URL');
    assertSame('Fechamento de Notas', $notas['title']);
    assertSame(['UNIMESTRE', 'Academico', 'Fechamento de Notas'], $notas['hierarchy']);
    assertSame('Cálculo da Média Final', $calc['title'], 'Manifest não prevaleceu');
    assertSame('https://manual.unimestre.com/academico/calculo_media/', $calc['canonical_url']);
    assertSame(['2024-01-10 00:00:00', '2024-03-05 14:22:00', 'external_edit'], [$calc['published_at'], $calc['source_modified_at'], $calc['source_modified_type']]);
    assertSame(['release_notes', true, '5.2'], [$versao['document_kind'], $versao['is_historical'], $versao['version']]);
    assertSame(['documentation', false], [$notas['document_kind'], $notas['is_historical']]);
    assertSame(['pdf', 'new', 'guide', 'Manual de Fechamento de Notas'], [$pdf['format'], $pdf['status'], $pdf['document_kind'], $pdf['title']]);
    assertSame(1, $pdf['pdf']['pdf_pages']);
    assertSame(64, strlen((string) $pdf['file_hash']));
    assertSame(['data/pages/academico/notas.txt'], $pdf['related_documents'], 'PDF não vinculado à página');
    assertSame('pdf_ocr', $f['data/media/manual/digitalizado.pdf']['status']);
    assertSame('media_ignored', $f['data/media/manual/tela.png']['status']);
    assertSame(['html', 'new'], [$f['html/boleto.html']['format'], $f['html/boleto.html']['status']]);
    assertSame(['text', 'duplicate'], [$f['txt/boleto.txt']['format'], $f['txt/boleto.txt']['status']], 'Dedup HTML×TXT');

    $s = $st['summary'];
    assertSame([3, 1, 1, 2, 1, 1, 1], [$s['dokuwiki'], $s['html'], $s['txt'], $s['pdf'], $s['pdf_text'], $s['pdf_ocr'], $s['media']]);
    assertSame(5, $s['new']);
});

test('V2 PRÉVIA: texto exato para o RAG, link interno resolvido pelo manifest, sem gravar', static function () use ($v2Service, $v2ByFile, $docsBefore, &$v2Batch): void {
    $f = $v2ByFile($v2Service->state($v2Batch));
    $notas = $f['data/pages/academico/notas.txt'];
    $p = $v2Service->preview($v2Batch, (int) $notas['id']);

    assertTrue(str_contains($p['content'], "- Conferir as notas lançadas\n  - Revisar pendências"), 'Sublista');
    assertTrue(str_contains($p['content'], 'cálculo da média (https://manual.unimestre.com/academico/calculo_media/)'), 'Link interno não resolvido: ' . $p['content']);
    assertTrue(str_contains($p['content'], 'Manual em PDF [arquivo: manual:fechamento_notas.pdf]'));
    assertSame($notas['hash'], (new KnowledgeService())->contentHash($p['content']), 'Prévia difere do que será importado');
    assertSame(null, $v2Service->preview($v2Batch, 9999));
    assertSame($docsBefore + 1, (int) Database::connection()->query('SELECT COUNT(*) FROM knowledge_documents')->fetchColumn(), 'Prévia gravou');
});

test('V2 IMPORTAÇÃO: só novos; OCR/mídia/duplicados nunca entram; proveniência em metadata', static function () use ($v2Service, &$v2Batch): void {
    $q = $v2Service->queue($v2Batch, null, null);
    assertSame(5, $q['summary']['queued'], 'Fila deve conter só os novos');
    $r = $v2Service->importNext($v2Batch, 25, null);
    assertSame([5, 0], [$r['summary']['imported'], $r['summary']['failed']]);

    $metas = [];
    foreach ($r['items'] as $i) {
        if ($i['import_status'] === 'imported') {
            TestIsolation::track('knowledge_documents', (int) $i['document_id']);
            $metas[$i['file']] = json_decode((string) Database::connection()->query('SELECT metadata FROM knowledge_documents WHERE id = ' . (int) $i['document_id'])->fetchColumn(), true);
        } else {
            assertTrue(in_array($i['status'], ['duplicate', 'pdf_ocr', 'media_ignored'], true) && $i['import_status'] === 'none', 'Item indevido na importação: ' . $i['file']);
        }
    }

    $n = $metas['data/pages/academico/notas.txt'];
    assertSame(['official_unimestre', 'dokuwiki', 'academico', 'dokuwiki:academico:notas', 'notas.txt'], [$n['source_type'], $n['import_format'], $n['namespace'], $n['source_identifier'], $n['original_filename']]);
    assertSame('data/pages/academico/notas.txt', $n['source_file']);
    assertTrue(!empty($n['file_hash']) && !empty($n['content_hash']) && !empty($n['fetched_at']) && !empty($n['last_verified_at']));
    $c = $metas['data/pages/academico/calculo_media.txt'];
    assertSame(['2024-01-10 00:00:00', 'external_edit'], [$c['published_at'], $c['source_modified_type']]);
    $v = $metas['data/pages/atualizacoes/versao_5_2.txt'];
    assertSame(['release_notes', true, '5.2'], [$v['document_kind'], $v['is_historical'], $v['version']]);
    $p = $metas['data/media/manual/fechamento_notas.pdf'];
    assertSame(['pdf', 1, true, 'php-native-pdf-v1'], [$p['import_format'], $p['pdf']['pdf_pages'], $p['pdf']['pdf_text_extracted'], $p['pdf']['pdf_extractor']]);
});

test('V2 RE-ANÁLISE: documentos sem URL reconhecidos pelo identificador de origem (sem duplicar)', static function () use ($v2Service, $v2ByFile): void {
    $f = $v2ByFile($v2Service->analyze(null));
    foreach (['data/pages/academico/notas.txt', 'data/pages/academico/calculo_media.txt', 'data/pages/atualizacoes/versao_5_2.txt', 'data/media/manual/fechamento_notas.pdf', 'html/boleto.html'] as $file) {
        assertSame('exists', $f[$file]['status'], $file);
    }
    assertSame('duplicate', $f['txt/boleto.txt']['status']);
});

test('ZIP endurecido: extensão dupla, oculto, nome reservado, symlink e zip bomb', static function () use ($tmpRoot): void {
    $zipPath = $tmpRoot . '/hard.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('pagina.php.html', '<p>x</p>');
    $zip->addFromString('docs/.htaccess', 'Allow from all');
    $zip->addFromString('docs/CON.txt', 'x');
    $zip->addFromString('docs/run.sh', 'x');
    $zip->addFromString('docs/app.js', 'x');
    $zip->addFromString('docs/link.html', '/etc/passwd');
    $zip->setExternalAttributesName('docs/link.html', ZipArchive::OPSYS_UNIX, (0120777 << 16));
    $zip->addFromString('docs/bomba.txt', str_repeat("\0", 3 * 1024 * 1024));
    $zip->addFromString('docs/ok.txt', 'conteúdo ok');
    $zip->addFromString('docs/figura.png', "\x89PNG");
    $zip->close();

    $r = (new OfficialImportService($tmpRoot . '/hardimport', $tmpRoot . '/hardstate'))->uploadZip($zipPath, 'hard.zip');
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpRoot . '/hardimport', FilesystemIterator::SKIP_DOTS)) as $file) {
        $found[] = $file->getFilename();
    }
    sort($found);

    assertSame('ok', $r['status']);
    assertSame(['figura.png', 'ok.txt'], $found, 'Entradas perigosas extraídas');
    assertSame(7, $r['skipped']);
});

test('ESTADO: análise recém-feita é a carregada (lote de nome "futuro" não vence; redirect leva ?lote)', static function () use ($tmpRoot): void {
    $sd = $tmpRoot . '/tzstate';
    mkdir($sd);
    // Lote antigo e vazio cujo NOME tem hora maior (gerado em outro fuso).
    file_put_contents($sd . '/batch_20260925_231812_361896.json', json_encode(['batch_id' => '20260925_231812_361896', 'items' => [], 'summary' => ['files' => 0]]));
    touch($sd . '/batch_20260925_231812_361896.json', time() - 3600);
    $svc = new OfficialImportService($tmpRoot . '/v2', $sd);
    $st = $svc->analyze(null);

    assertSame($st['batch_id'], $svc->latestState()['batch_id'], 'latestState elegeu lote antigo pelo nome');
    assertSame(count($st['items']), count($svc->state($st['batch_id'])['items']), 'Estado persistido difere do analisado');
    assertTrue($st['summary']['files'] > 0);
    $ctrl = (string) file_get_contents(APP_PATH . '/app/Controllers/KnowledgeImportController.php');
    assertTrue(str_contains($ctrl, "importacao-oficial?lote=' . rawurlencode((string) \$state['batch_id'])"), 'Redirect da análise sem o lote');
    assertTrue(str_contains($ctrl, "\$this->service->state(\$batch)"), 'index não carrega o lote informado');
});

test('UPLOADS ISOLADOS: analisar B não percorre A (mesmos 9 docs); upload_id adulterado recusado', static function () use ($tmpRoot, $docsBefore): void {
    $base = $tmpRoot . '/uploads';
    $mk = static function (string $id) use ($base): void {
        $pkg = $base . '/' . $id . '/pacote';
        mkdir($pkg . '/paginas', 0775, true);
        $csv = "file,source_url,title\n";
        for ($i = 1; $i <= 9; $i++) {
            file_put_contents($pkg . "/paginas/pagina$i.txt", "====== Página $i ======\n\nConteúdo placeholder número $i com texto suficiente para passar do mínimo exigido.\n\n  * item $i\n");
            $csv .= "paginas/pagina$i.txt,,Página $i (manifest $id)\n";
        }
        file_put_contents($pkg . '/manifest.csv', $csv);
    };
    $mk('upload_20260101_000000_aaaaaa');
    $mk('upload_20260102_000000_bbbbbb');
    touch($base . '/upload_20260101_000000_aaaaaa', time() - 60);
    $svc = new OfficialImportService($base, $tmpRoot . '/upstate');
    $count = static fn (): int => (int) Database::connection()->query('SELECT COUNT(*) FROM knowledge_documents')->fetchColumn();
    $docsAtStart = $count();

    assertSame(['upload_20260102_000000_bbbbbb', 'upload_20260101_000000_aaaaaa'], $svc->uploads());
    $st = $svc->analyze(null, ['upload_id' => 'upload_20260102_000000_bbbbbb']);
    assertSame('upload_20260102_000000_bbbbbb', $st['upload_id'], 'Lote sem o upload de origem');
    assertSame(9, count($st['items']), 'Analisou arquivos de outro upload');
    foreach ($st['items'] as $item) {
        assertTrue(str_starts_with($item['file'], 'upload_20260102_000000_bbbbbb/'), 'Arquivo de outro upload: ' . $item['file']);
        assertTrue(str_contains($item['title'], 'manifest upload_20260102_000000_bbbbbb'), 'Manifest de outro upload aplicado');
    }
    assertSame([9, 0, 0, 9], [$st['summary']['new'], $st['summary']['duplicate'], $st['summary']['error'], $st['summary']['dokuwiki']]);
    $p = $svc->preview($st['batch_id'], 1);
    assertTrue($p !== null && str_starts_with($p['item']['file'], 'upload_20260102_000000_bbbbbb/') && $p['content'] !== '', 'Prévia fora do upload');
    assertSame('upload_20260102_000000_bbbbbb', $svc->state($st['batch_id'])['upload_id']);
    assertSame(9, count($svc->analyze(null, ['upload_id' => 'upload_20260101_000000_aaaaaa'])['items']), 'Upload A isolado');
    assertSame('upload_20260102_000000_bbbbbb', $svc->analyze(null)['upload_id'], 'Sem upload_id deve usar o mais recente');

    foreach (['../upload_20260102_000000_bbbbbb', 'upload_20260102_000000_bbbbbb/..', '..', $base . '/upload_20260102_000000_bbbbbb',
        'C:\\Windows', '/etc', 'upload_20260102_000000_bbbbbb\\..\\..', 'upload_20260103_000000_cccccc', 'upload_x'] as $bad) {
        assertSame(null, $svc->uploadPath($bad), 'Aceitou upload_id: ' . $bad);
        $thrown = false;
        try {
            $svc->analyze(null, ['upload_id' => $bad]);
        } catch (RuntimeException) {
            $thrown = true;
        }
        assertTrue($thrown, 'analyze aceitou: ' . $bad);
    }
    assertSame($docsAtStart, $count(), 'Dry run gravou');
});

// ---------------------------------------------------------------- isolamento
TestIsolation::finish();
$isolation = TestIsolation::verify();
test('ISOLAMENTO: pré-existentes preservados (inclui 39922), sem resíduos', static function () use ($isolation): void {
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
