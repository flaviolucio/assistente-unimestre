<?php

declare(strict_types=1);

/**
 * Extratores do importador oficial (HTML, TXT, Markdown, DokuWiki, PDF).
 * Testes puros: sem banco, sem rede, sem IA.
 * Execução: php tests/import_extractors.php
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/support/PdfFixture.php';

use App\Services\Knowledge\Import\ContentExtractorInterface;
use App\Services\Knowledge\Import\DokuWikiContentExtractor;
use App\Services\Knowledge\Import\HtmlContentExtractor;
use App\Services\Knowledge\Import\MarkdownContentExtractor;
use App\Services\Knowledge\Import\PdfContentExtractor;
use App\Services\Knowledge\Import\TextContentExtractor;

error_reporting(E_ALL & ~E_DEPRECATED);

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

function has(string $content, string $needle): void
{
    assertTrue(str_contains($content, $needle), 'Ausente: "' . $needle . "\" em:\n" . $content);
}

$doku = <<<'DOKU'
~~NOTOC~~
====== Notas e Frequências ======

Texto de **abertura** com //ênfase// e __sublinhado__ e ''mono''.

===== Média Final =====

  * Item A
    * Sub A1
  * Item B
  - Passo 1
  - Passo 2

^ Parâmetro ^ Valor ^
| nota_maxima | 10 |

<code php>
$media = ($n1 + $n2) / 2;
</code>

<note important>Salve antes de fechar o período.</note>

Veja [[calculo_media|cálculo da média]], [[:portal:senha]] e [[https://unimestre.com/x|site]].
Baixe {{:manual:fechamento_notas.pdf|Manual}} e {{:manual:tela.png|Tela}}.
DOKU;

// ---------------------------------------------------------------- contrato
test('Contrato: todos os extratores devolvem o documento normalizado', static function (): void {
    $keys = ['title', 'content', 'url', 'url_source', 'category', 'subcategory', 'published_at', 'source_modified_at', 'source_modified_type', 'hosts', 'media_refs', 'metadata', 'quality'];
    $formats = [];

    foreach ([new HtmlContentExtractor(), new TextContentExtractor(), new MarkdownContentExtractor(), new DokuWikiContentExtractor(), new PdfContentExtractor()] as $e) {
        assertTrue($e instanceof ContentExtractorInterface);
        $r = $e->extract($e->format() === 'pdf' ? PdfFixture::text([['Oi']]) : 'Título', []);
        assertSame([], array_diff($keys, array_keys($r)), get_class($e) . ' sem chaves normalizadas');
        $formats[] = $e->format();
    }

    assertSame(['html', 'text', 'markdown', 'dokuwiki', 'pdf'], $formats);
});

// ---------------------------------------------------------------- DokuWiki
test('DokuWiki: detecção conservadora', static function () use ($doku): void {
    assertTrue(DokuWikiContentExtractor::looksLikeDokuWiki($doku));
    assertTrue(!DokuWikiContentExtractor::looksLikeDokuWiki("Texto simples\n\nsem marcação nenhuma * não é lista"));
    assertTrue(!DokuWikiContentExtractor::looksLikeDokuWiki("== só um cabeçalho ==\ntexto comum"), 'Só cabeçalho não basta');
});

test('DokuWiki: títulos, listas/sublistas, tabela, código, ênfase, notas', static function () use ($doku): void {
    $r = (new DokuWikiContentExtractor())->extract($doku, ['dokuwiki_id' => 'academico:notas']);
    $c = $r['content'];

    assertSame('Notas e Frequências', $r['title']);
    has($c, '## Média Final');
    has($c, "- Item A\n  - Sub A1\n- Item B");
    has($c, "1. Passo 1\n2. Passo 2");
    has($c, 'Parâmetro | Valor');
    has($c, 'nota_maxima | 10');
    has($c, '$media = ($n1 + $n2) / 2;');
    has($c, 'Texto de abertura com ênfase e sublinhado e mono.');
    has($c, 'Importante: Salve antes de fechar o período.');
    assertTrue(!str_contains($c, '~~NOTOC~~') && !str_contains($c, '**') && !str_contains($c, '<code'), 'Marcação residual');
});

test('DokuWiki: links internos (id relativo/absoluto, link_map), externos e mídia', static function () use ($doku): void {
    $r = (new DokuWikiContentExtractor())->extract($doku, [
        'dokuwiki_id' => 'academico:notas',
        'link_map' => ['academico:calculo_media' => 'https://manual.unimestre.com/academico/calculo_media'],
    ]);
    $c = $r['content'];

    has($c, 'cálculo da média (https://manual.unimestre.com/academico/calculo_media)');
    has($c, 'senha [página DokuWiki: portal:senha]');
    has($c, 'site (https://unimestre.com/x)');
    has($c, 'Manual [arquivo: manual:fechamento_notas.pdf]');
    has($c, '[imagem: Tela]');
    assertSame(['manual:fechamento_notas.pdf', 'manual:tela.png'], $r['media_refs']);
    assertSame(['academico:calculo_media', 'portal:senha'], $r['metadata']['internal_links']);
    assertSame(['unimestre.com'], $r['hosts']);
    assertSame(null, $r['url'], 'URL oficial nunca é inventada');
});

// ---------------------------------------------------------------- HTML / TXT / MD
test('HTML: datas da fonte e "edição externa" (nunca vira data de publicação)', static function (): void {
    $html = '<html><head><meta property="article:published_time" content="2023-02-10T09:00:00-03:00"><link rel="canonical" href="https://unimestre.com/a/"></head>'
        . '<body><article><h1>Doc</h1><p>' . str_repeat('conteúdo ', 20) . '</p></article>'
        . '<div class="docInfo">academico/notas.txt · Última modificação: 05/03/2024 14:22 (edição externa)</div></body></html>';
    $r = (new HtmlContentExtractor())->extract($html);

    assertSame('2023-02-10 09:00:00', substr((string) $r['published_at'], 0, 19));
    assertSame('2024-03-05 14:22:00', $r['source_modified_at']);
    assertSame('external_edit', $r['source_modified_type']);

    $only = (new HtmlContentExtractor())->extract(str_replace('<meta property="article:published_time" content="2023-02-10T09:00:00-03:00">', '', $html));
    assertSame(null, $only['published_at'], 'Edição externa usada como publicação');
});

test('TXT e Markdown: título, seções, links e limpeza', static function (): void {
    $t = (new TextContentExtractor())->extract("\n\nComo emitir boleto\n\nAcesse o portal financeiro.");
    assertSame('Como emitir boleto', $t['title']);

    $m = (new MarkdownContentExtractor())->extract("# Guia do Portal\n\n## Acesso\n\nUse **login** e *senha* em [portal](https://unimestre.com/p).\n![img](a.png)\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\n```\ncodigo  literal\n```");
    assertSame('Guia do Portal', $m['title']);
    has($m['content'], '## Acesso');
    has($m['content'], 'Use login e senha em portal (https://unimestre.com/p).');
    has($m['content'], '1 | 2');
    has($m['content'], 'codigo  literal');
    assertTrue(!str_contains($m['content'], '---') && !str_contains($m['content'], 'a.png'));
});

// ---------------------------------------------------------------- PDF
test('PDF textual: texto, acentos, páginas e título', static function (): void {
    $pdf = PdfFixture::text([['Fechamento de notas', 'O professor confere as avaliações antes do fechamento do período.'], ['Passo 2: salvar o diário da turma.']], 'Manual de Fechamento');
    $r = (new PdfContentExtractor())->extract($pdf);

    assertSame('text', $r['quality']);
    assertSame('Manual de Fechamento', $r['title']);
    has($r['content'], 'avaliações antes do fechamento do período');
    has($r['content'], 'diário da turma');
    assertSame(2, $r['metadata']['pdf_pages']);
    assertSame(true, $r['metadata']['pdf_text_extracted']);
    assertSame('php-native-pdf-v1', $r['metadata']['pdf_extractor']);
});

test('PDF sem texto → OCR necessário; inválido → falhou; cifrado → não suportado', static function (): void {
    $e = new PdfContentExtractor();
    $scan = $e->extract(PdfFixture::scanned());
    assertSame('ocr_needed', $scan['quality']);
    assertSame('', trim($scan['content']), 'Texto inventado em PDF sem texto');
    assertSame(false, $scan['metadata']['pdf_text_extracted']);

    assertSame('failed', $e->extract('isto não é um pdf')['quality']);
    assertSame('unsupported', $e->extract("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Encrypt 5 0 R /Root 1 0 R >>\n%%EOF")['quality']);
});

$pass = 0;
$fail = 0;

foreach ($tests as $t) {
    printf("%-4s %s%s\n", $t['pass'] ? 'PASS' : 'FAIL', $t['name'], $t['pass'] ? '' : ' — ' . $t['detail']);
    $t['pass'] ? $pass++ : $fail++;
}

printf("TOTAL: %d | PASS: %d | FAIL: %d\n", $pass + $fail, $pass, $fail);
exit($fail > 0 ? 1 : 0);
