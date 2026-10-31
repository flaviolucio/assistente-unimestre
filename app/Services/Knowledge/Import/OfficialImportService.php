<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Import;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\KnowledgeDocumentRepository;
use App\Services\Knowledge\KnowledgeService;
use App\Services\RAG\ChunkingService;
use App\Services\Security\Audit;
use RuntimeException;
use ZipArchive;

/**
 * Importação em massa da documentação OFICIAL do UNIMESTRE a partir de
 * ARQUIVOS LOCAIS (nenhuma requisição ao site; nenhuma chamada de IA).
 *
 * Formatos (um extrator por formato, todos no MESMO pipeline):
 *   html · dokuwiki · text · markdown · pdf  (+ mídia não textual catalogada)
 *
 * Fluxo:
 *   1. analyze()    → DRY RUN: extração + classificação; NADA é gravado na base.
 *   2. preview()    → mostra o texto exato que seria enviado ao RAG (sem gravar).
 *   3. queue()      → marca itens NOVOS (todos ou selecionados).
 *   4. importNext() → em lotes, via KnowledgeService::importManual() (mesma
 *      validação, hash, deduplicação, chunking e auditoria) + metadados.
 *      Estado salvo por item: retomável.
 *
 * Classificação:
 *   new · exists · update · duplicate · blocked · ignored · error
 *   pdf_ocr (OCR necessário) · pdf_failed · pdf_unsupported · media_ignored
 *   (pdf_pending: legado de análises anteriores)
 */
final class OfficialImportService
{
    public const ACTIONS = [
        'new' => 'IMPORTAR',
        'exists' => 'IGNORAR',
        'update' => 'ATUALIZAÇÃO DISPONÍVEL',
        'duplicate' => 'IGNORAR (duplicado)',
        'pdf_pending' => 'PDF — EXTRAÇÃO PENDENTE',
        'pdf_ocr' => 'NÃO IMPORTAR (OCR necessário)',
        'pdf_failed' => 'NÃO IMPORTAR (extração falhou)',
        'pdf_unsupported' => 'NÃO IMPORTAR (PDF não suportado)',
        'media_ignored' => 'IGNORAR (mídia não textual)',
        'blocked' => 'BLOQUEADO (host não autorizado)',
        'ignored' => 'IGNORAR',
        'error' => 'ERRO',
    ];

    public const FORMAT_LABELS = [
        'html' => 'HTML', 'dokuwiki' => 'DokuWiki', 'text' => 'TXT', 'markdown' => 'Markdown', 'pdf' => 'PDF', 'media' => 'Mídia',
    ];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const MEDIA_DIRS = ['_media', 'media'];
    private const RELEASE_SEGMENTS = ['atualizacoes', 'atualizacao', 'versoes', 'versao', 'releases', 'release', 'release-notes', 'release_notes', 'changelog', 'notas-de-versao', 'notas_de_versao'];

    private string $dir;
    private string $stateDir;
    /** Raiz varrida pela análise atual: a pasta do upload (ou $dir no modo legado). */
    private string $scanRoot = '';
    private OfficialUrlPolicy $policy;
    private KnowledgeService $knowledge;
    private KnowledgeDocumentRepository $documents;
    private ChunkingService $chunker;

    /** @var array<string, ContentExtractorInterface> */
    private array $extractors;

    public function __construct(
        ?string $dir = null,
        ?string $stateDir = null,
        ?OfficialUrlPolicy $policy = null,
        ?KnowledgeService $knowledge = null
    ) {
        $this->dir = rtrim($dir ?? (string) config('knowledge.bulk_import.dir'), '/\\');
        $this->stateDir = rtrim($stateDir ?? (string) config('knowledge.bulk_import.state_dir'), '/\\');
        $this->policy = $policy ?? new OfficialUrlPolicy();
        $this->knowledge = $knowledge ?? new KnowledgeService();
        $this->documents = new KnowledgeDocumentRepository();
        $this->chunker = new ChunkingService();
        $this->extractors = [];

        foreach ([new HtmlContentExtractor(), new DokuWikiContentExtractor(), new TextContentExtractor(), new MarkdownContentExtractor(), new PdfContentExtractor()] as $extractor) {
            $this->extractors[$extractor->format()] = $extractor;
        }
    }

    public function importDir(): string
    {
        return $this->dir;
    }

    // ================================================================== análise

    /**
     * DRY RUN. Não grava nenhum documento; apenas o inventário (estado).
     *
     * Cada análise pertence a UM upload: somente a pasta desse upload é
     * varrida (arquivos e manifest). Sem upload_id, usa o upload mais recente;
     * sem nenhum upload, a pasta de importação inteira (modo legado).
     *
     * @param array{dokuwiki?: string, upload_id?: ?string} $options
     * @return array<string, mixed> estado do lote
     */
    public function analyze(?int $userId = null, array $options = []): array
    {
        $this->ensureDirs();
        $uploadId = isset($options['upload_id']) && $options['upload_id'] !== '' ? (string) $options['upload_id'] : ($this->uploads()[0] ?? null);
        $scanRoot = $uploadId !== null ? $this->uploadPath($uploadId) : $this->dir;

        if ($scanRoot === null) {
            throw new RuntimeException('Upload inválido ou inexistente.');
        }

        $this->scanRoot = $scanRoot;
        $batchId = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $started = microtime(true);
        $dokuMode = in_array($options['dokuwiki'] ?? 'auto', ['auto', 'force', 'off'], true) ? ($options['dokuwiki'] ?? 'auto') : 'auto';

        Audit::record('knowledge.bulk_scan_started', 'knowledge_import', null, ['batch_id' => $batchId, 'upload_id' => $uploadId, 'dokuwiki' => $dokuMode]);
        Logger::info('knowledge_import', 'Análise iniciada', ['batch_id' => $batchId, 'upload_id' => $uploadId, 'dokuwiki' => $dokuMode]);

        $files = $this->listFiles();
        $manifest = $this->readManifest();
        $context = $this->buildContext($files, $manifest, $dokuMode);
        $officialSource = $this->knowledge->sourceForType('official');
        $officialSourceId = $officialSource !== null ? (string) $officialSource['id'] : '';
        $items = [];
        $seen = ['url' => [], 'ident' => [], 'hash' => [], 'file' => []];
        $unlisted = [];

        foreach ($files as $index => $relative) {
            $item = $this->analyzeFile($relative, $index + 1, $manifest, $context, $officialSourceId, $seen, $unlisted);
            $item['id'] = $index + 1;
            $items[] = $item;
        }

        $this->linkMedia($items);

        $state = [
            'batch_id' => $batchId,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $userId,
            'source' => 'local_files',
            'dir' => $this->dir,
            'upload_id' => $uploadId,
            'options' => ['dokuwiki' => $dokuMode],
            'unlisted_hosts' => array_keys($unlisted),
            'items' => $items,
            'import' => null,
        ];
        $state['summary'] = $this->summarize($state);
        $this->saveState($state);

        Audit::record('knowledge.bulk_scan_finished', 'knowledge_import', null, [
            'batch_id' => $batchId, 'files' => count($items), 'new' => $state['summary']['new'],
        ]);
        Logger::info('knowledge_import', 'Análise concluída', [
            'batch_id' => $batchId, 'files' => count($items), 'summary' => $state['summary'],
            'duration_ms' => round((microtime(true) - $started) * 1000, 1),
        ]);

        return $state;
    }

    /**
     * Prévia da extração de um item (título, metadados e o texto exato que
     * seria enviado ao RAG). NÃO grava nada.
     */
    public function preview(string $batchId, int $itemId): ?array
    {
        $state = $this->state($batchId);

        foreach ((array) ($state['items'] ?? []) as $item) {
            if ((int) $item['id'] !== $itemId) {
                continue;
            }

            if (($item['format'] ?? '') === 'media') {
                return ['item' => $item, 'document' => null, 'content' => ''];
            }

            $doc = $this->extractItem($item, $state);

            return ['item' => $item, 'document' => $doc, 'content' => $this->knowledge->normalizeText((string) $doc['content'])];
        }

        return null;
    }

    /**
     * Contexto do lote: formato e identidade DokuWiki de cada arquivo e o
     * mapa id DokuWiki → URL oficial (vindo SOMENTE do manifest).
     */
    private function buildContext(array $files, array $manifest, string $dokuMode): array
    {
        $formats = [];
        $candidates = [];

        foreach ($files as $relative) {
            $meta = $manifest[$this->manifestKey($relative)] ?? [];
            $format = $this->detectFormat($relative, $meta, $dokuMode);
            $formats[$relative] = $format;

            if ($format === 'dokuwiki') {
                $candidates[] = $relative;
            }
        }

        $ids = $this->dokuWikiIds($candidates);

        foreach ($candidates as $relative) {
            $given = $manifest[$this->manifestKey($relative)]['dokuwiki_id'] ?? null;

            if ($given !== null) {
                $ids[$relative] = mb_strtolower(trim(str_replace('/', ':', $given), ':'));
            }
        }

        $linkMap = [];

        foreach ($ids as $relative => $id) {
            $url = $manifest[$this->manifestKey($relative)]['url'] ?? null;

            if ($url !== null && $this->policy->isAllowed($url)) {
                $linkMap[$id] = $this->policy->canonicalize($url);
            }
        }

        return ['formats' => $formats, 'ids' => $ids, 'link_map' => $linkMap];
    }

    private function detectFormat(string $relative, array $meta, string $dokuMode): string
    {
        $declared = strtolower((string) ($meta['format'] ?? ''));

        if (isset($this->extractors[$declared])) {
            return $declared;
        }

        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

        if (in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return 'media';
        }

        if ($ext === 'pdf') {
            return 'pdf';
        }

        if ($ext === 'html' || $ext === 'htm') {
            return 'html';
        }

        if ($ext === 'md') {
            return 'markdown';
        }

        if ($dokuMode === 'force') {
            return 'dokuwiki';
        }

        if ($dokuMode === 'off') {
            return 'text';
        }

        $segments = explode('/', strtolower($relative));

        if (in_array('pages', array_slice($segments, 0, -1), true)) {
            return 'dokuwiki';
        }

        $head = (string) @file_get_contents($this->dir . DIRECTORY_SEPARATOR . $relative, false, null, 0, 65536);

        return DokuWikiContentExtractor::looksLikeDokuWiki($head) ? 'dokuwiki' : 'text';
    }

    /**
     * id DokuWiki a partir do caminho: remove "upload_*", "data/pages" ou
     * "pages" e uma pasta raiz comum ao pacote; "/" → ":", sem ".txt".
     *
     * @param array<int, string> $files
     * @return array<string, string>
     */
    private function dokuWikiIds(array $files): array
    {
        $paths = [];

        foreach ($files as $relative) {
            $segments = explode('/', $relative);

            if (preg_match('/^upload_\d{8}_\d{6}$/', $segments[0]) === 1) {
                array_shift($segments);
            }

            $pagesAt = array_search('pages', array_map('strtolower', array_slice($segments, 0, -1)), true);

            if ($pagesAt !== false) {
                $segments = array_slice($segments, (int) $pagesAt + 1);
            }

            $paths[$relative] = $segments;
        }

        // Pasta raiz comum (ex.: "manual/") quando TODOS os arquivos estão dentro dela.
        $firsts = array_unique(array_map(static fn ($s) => count($s) > 1 ? $s[0] : '', $paths));

        if (count($paths) > 1 && count($firsts) === 1 && reset($firsts) !== '') {
            $paths = array_map(static fn ($s) => array_slice($s, 1), $paths);
        }

        $ids = [];

        foreach ($paths as $relative => $segments) {
            $last = count($segments) - 1;
            $segments[$last] = preg_replace('/\.txt$/i', '', $segments[$last]) ?? $segments[$last];
            $ids[$relative] = mb_strtolower(implode(':', $segments));
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeFile(string $relative, int $fileNo, array $manifest, array $context, string $officialSourceId, array &$seen, array &$unlisted): array
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $relative;
        $size = (int) @filesize($path);
        $meta = $manifest[$this->manifestKey($relative)] ?? [];
        $format = $context['formats'][$relative] ?? 'text';
        $dokuId = $context['ids'][$relative] ?? null;
        $segments = explode('/', $relative);
        $item = [
            'file' => $relative,
            'original_filename' => basename($relative),
            'format' => $format,
            'type' => self::FORMAT_LABELS[$format] ?? strtoupper($format),
            'size' => $size,
            'file_hash' => null,
            'title' => '',
            'url' => null,
            'canonical_url' => null,
            'url_source' => null,
            'source_identifier' => null,
            'dokuwiki_id' => $dokuId,
            'namespace' => $dokuId !== null && str_contains($dokuId, ':') ? substr($dokuId, 0, (int) strrpos($dokuId, ':')) : null,
            'slug' => $dokuId !== null ? (string) substr($dokuId, (int) strrpos($dokuId, ':') + (str_contains($dokuId, ':') ? 1 : 0)) : null,
            'is_media_dir' => array_intersect(array_map('strtolower', array_slice($segments, 0, -1)), self::MEDIA_DIRS) !== [],
            'category' => 'Outros',
            'category_original' => null,
            'subcategory' => null,
            'published_at' => $meta['published_at'] ?? null,
            'source_modified_at' => $meta['source_modified_at'] ?? null,
            'source_modified_type' => $meta['source_modified_type'] ?? null,
            'document_kind' => 'documentation',
            'is_historical' => false,
            'version' => null,
            'hierarchy' => [],
            'hash' => null,
            'chars' => 0,
            'est_chunks' => 0,
            'pdf' => null,
            'media_refs' => [],
            'internal_links' => [],
            'related_documents' => [],
            'status' => 'error',
            'reason' => null,
            'existing_document_id' => null,
            'fetched_at' => date('Y-m-d H:i:s', (int) (@filemtime($path) ?: time())),
            'import_status' => 'none',
            'document_id' => null,
            'chunks' => null,
            'error' => null,
        ];

        if ($size <= 0 || $size > (int) config('knowledge.bulk_import.max_file_bytes', 5242880)) {
            $item['reason'] = $size <= 0 ? 'Arquivo vazio ou ilegível.' : 'Arquivo acima do limite de tamanho.';

            return $item;
        }

        $item['file_hash'] = hash_file('sha256', $path) ?: null;

        if ($format === 'media') {
            $item['title'] = $this->titleFromFile($relative);
            $item['status'] = 'media_ignored';
            $item['reason'] = 'Mídia não textual — ignorada (não entra no RAG).';

            return $item;
        }

        $doc = $this->extractItem($item, ['dir' => $this->dir], $context['link_map']);

        // Manifest tem precedência sobre o que foi inferido do arquivo.
        $url = $meta['url'] ?? $doc['url'];
        $urlSource = isset($meta['url']) ? 'manifest' : $doc['url_source'];
        $item['title'] = ($meta['title'] ?? '') !== '' ? $meta['title'] : ($doc['title'] !== '' ? $doc['title'] : $this->titleFromFile($relative));
        $item['category_original'] = $this->clip($meta['category'] ?? $doc['category']);
        $item['subcategory'] = $this->clip($meta['subcategory'] ?? $doc['subcategory'] ?? $item['category_original']);
        $item['category'] = $this->mapCategory($item['category_original'], $item['title'], $item['namespace']);
        $item['published_at'] ??= $doc['published_at'];
        $item['source_modified_at'] ??= $doc['source_modified_at'];
        $item['source_modified_type'] ??= $doc['source_modified_type'];
        $item['media_refs'] = $doc['media_refs'];
        $item['internal_links'] = (array) ($doc['metadata']['internal_links'] ?? []);
        $item['pdf'] = $format === 'pdf' ? array_intersect_key((array) $doc['metadata'], array_flip(['pdf_pages', 'pdf_pages_without_text', 'pdf_text_extracted', 'pdf_extractor', 'pdf_printable_ratio', 'pdf_error'])) : null;
        $this->classifyKind($item);
        $item['hierarchy'] = $this->hierarchy($item);

        foreach ($doc['hosts'] as $host) {
            if (($host === 'unimestre.com' || str_ends_with($host, '.unimestre.com')) && !$this->policy->isAllowed('https://' . $host . '/')) {
                $unlisted[$host] = true;
            }
        }

        // PDF sem texto útil: catalogado, nunca "inventado".
        if ($format === 'pdf' && $doc['quality'] !== 'text') {
            [$item['status'], $item['reason']] = match ($doc['quality']) {
                'ocr_needed' => ['pdf_ocr', 'PDF — OCR NECESSÁRIO: sem camada de texto útil.'],
                'unsupported' => ['pdf_unsupported', 'PDF — NÃO SUPORTADO: ' . ($doc['metadata']['pdf_error'] ?? 'protegido.')],
                default => ['pdf_failed', 'PDF — EXTRAÇÃO FALHOU: ' . ($doc['metadata']['pdf_error'] ?? 'estrutura não reconhecida.')],
            };
            $this->applyUrl($item, $url, $urlSource, $unlisted, false);

            return $item;
        }

        // URL oficial: HTML/TXT/MD exigem URL; DokuWiki e PDF podem usar o
        // identificador de origem (nunca se inventa URL).
        $identifier = $format === 'dokuwiki' && $dokuId !== null
            ? 'dokuwiki:' . $dokuId
            : ($format === 'pdf' ? 'file:' . $this->portablePath($relative) : null);
        $this->applyUrl($item, $url, $urlSource, $unlisted, $identifier === null);

        if (in_array($item['status'], ['blocked', 'ignored'], true)) {
            return $item;
        }

        $item['source_identifier'] = $identifier;
        $normalized = $this->knowledge->normalizeText((string) $doc['content']);
        $item['chars'] = mb_strlen($normalized);

        if ($item['chars'] < (int) config('knowledge.bulk_import.min_content_chars', 80)) {
            $item['status'] = 'ignored';
            $item['reason'] = 'Conteúdo útil insuficiente após a extração.';

            return $item;
        }

        // Mesmo hash do importManual (normalizeText + SHA-256).
        $item['hash'] = $this->knowledge->contentHash($normalized);
        $item['est_chunks'] = count($this->chunker->chunkText($normalized));

        return $this->deduplicate($item, $fileNo, $officialSourceId, $seen);
    }

    /**
     * Deduplicação determinística. 1º a BASE (independe da ordem dos
     * arquivos): URL canônica → identificador de origem → hash do conteúdo →
     * hash do arquivo. 2º o próprio lote, só entre documentos novos.
     */
    private function deduplicate(array $item, int $fileNo, string $officialSourceId, array &$seen): array
    {
        $canonical = (string) ($item['canonical_url'] ?? '');

        foreach ($this->policy->lookupVariants($canonical) as $variant) {
            if (($existing = $this->documents->findByUrl($variant)) !== null) {
                return $this->existing($item, $existing);
            }
        }

        if ($item['source_identifier'] !== null && ($existing = $this->documents->findByMetadata('source_identifier', (string) $item['source_identifier'])) !== null) {
            return $this->existing($item, $existing);
        }

        if ($officialSourceId !== '' && ($byHash = $this->documents->findByHash($officialSourceId, (string) $item['hash'])) !== null) {
            return ['status' => 'duplicate', 'reason' => 'Mesmo conteúdo já existe na base (documento #' . $byHash['id'] . ').', 'existing_document_id' => (int) $byHash['id']] + $item;
        }

        if ($item['file_hash'] !== null && ($byFile = $this->documents->findByMetadata('file_hash', (string) $item['file_hash'])) !== null) {
            return ['status' => 'duplicate', 'reason' => 'Mesmo arquivo já importado (documento #' . $byFile['id'] . ').', 'existing_document_id' => (int) $byFile['id']] + $item;
        }

        foreach ([
            'url' => [$canonical, 'Mesma URL'],
            'ident' => [(string) $item['source_identifier'], 'Mesmo identificador de origem'],
            'hash' => [(string) $item['hash'], 'Mesmo conteúdo'],
            'file' => [(string) $item['file_hash'], 'Mesmo arquivo'],
        ] as $key => [$value, $label]) {
            if ($value !== '' && isset($seen[$key][$value])) {
                return ['status' => 'duplicate', 'reason' => $label . ' já aparece no arquivo #' . $seen[$key][$value] . ' deste lote.'] + $item;
            }
        }

        foreach (['url' => $canonical, 'ident' => (string) $item['source_identifier'], 'hash' => (string) $item['hash'], 'file' => (string) $item['file_hash']] as $key => $value) {
            if ($value !== '') {
                $seen[$key][$value] = $fileNo;
            }
        }

        $item['status'] = 'new';
        $item['reason'] = null;
        $item['import_status'] = 'pending';

        return $item;
    }

    private function existing(array $item, array $existing): array
    {
        $same = hash_equals((string) $existing['content_hash'], (string) $item['hash']);

        return [
            'status' => $same ? 'exists' : 'update',
            'reason' => $same ? 'Já existe na base com o mesmo conteúdo.' : 'Já existe na base; o conteúdo oficial difere (não será substituído automaticamente).',
            'existing_document_id' => (int) $existing['id'],
        ] + $item;
    }

    /** Valida e canonicaliza a URL declarada; marca blocked/ignored quando for o caso. */
    private function applyUrl(array &$item, ?string $url, ?string $source, array &$unlisted, bool $urlRequired): void
    {
        $item['url'] = $url;
        $item['url_source'] = $url !== null ? $source : null;

        if ($url === null || trim($url) === '') {
            if ($urlRequired) {
                $item['status'] = 'ignored';
                $item['reason'] = 'Sem URL oficial identificável (informe no manifest.csv).';
            }

            return;
        }

        if (!$this->policy->isAllowed($url)) {
            if ($this->policy->isUnlistedOfficialSubdomain($url)) {
                $unlisted[strtolower((string) parse_url($url, PHP_URL_HOST))] = true;
            }

            if (!str_starts_with((string) $item['status'], 'pdf_')) {
                $item['status'] = 'blocked';
                $item['reason'] = 'Host não autorizado: ' . (string) parse_url($url, PHP_URL_HOST);
            }

            return;
        }

        $item['canonical_url'] = $this->policy->canonicalize($url);
    }

    /** Extração pelo extrator do formato do item (análise, prévia e importação). */
    private function extractItem(array $item, array $state, ?array $linkMap = null): array
    {
        $raw = (string) @file_get_contents($this->dir . DIRECTORY_SEPARATOR . $item['file']);
        $format = (string) ($item['format'] ?? 'html');

        if ($format !== 'pdf' && !mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        if ($linkMap === null) {
            $linkMap = [];

            foreach ((array) ($state['items'] ?? []) as $other) {
                if (!empty($other['dokuwiki_id']) && !empty($other['canonical_url'])) {
                    $linkMap[$other['dokuwiki_id']] = $other['canonical_url'];
                }
            }
        }

        return $this->extractors[$format]->extract($raw, [
            'relative' => $item['file'],
            'dokuwiki_id' => $item['dokuwiki_id'] ?? null,
            'link_map' => $linkMap,
        ]);
    }

    /** document_kind por evidência OBJETIVA (namespace, categoria, título), sem IA. */
    private function classifyKind(array &$item): void
    {
        $segments = array_map([$this, 'fold'], array_merge(
            explode(':', (string) ($item['namespace'] ?? '')),
            array_slice(explode('/', (string) $item['file']), 0, -1),
            [(string) $item['category_original'], (string) $item['subcategory']]
        ));
        $isRelease = array_intersect(array_map(static fn ($s) => str_replace(' ', '-', trim($s)), $segments), self::RELEASE_SEGMENTS) !== []
            || preg_match('/^(notas de )?vers(ao|oes|ão|ões)\s+\d|release notes|changelog/iu', (string) $item['title']) === 1
            || preg_match('/^(versao|versoes|release|changelog)[_-]?\d/i', $this->fold((string) ($item['slug'] ?? pathinfo((string) $item['file'], PATHINFO_FILENAME)))) === 1;

        if (preg_match('/(\d+)[._](\d+)(?:[._](\d+))?(?:[._](\d+))?/', (string) ($item['slug'] ?? $item['title']), $m) === 1 && $isRelease) {
            $item['version'] = implode('.', array_filter(array_slice($m, 1), static fn ($v) => $v !== ''));
        }

        $item['document_kind'] = $isRelease ? 'release_notes' : ($item['format'] === 'pdf' ? 'guide' : 'documentation');
        $item['is_historical'] = $isRelease;
    }

    /** @return array<int, string> ex.: UNIMESTRE › Online › Notas e Frequências */
    private function hierarchy(array $item): array
    {
        $levels = ['UNIMESTRE'];

        if (!empty($item['namespace'])) {
            foreach (explode(':', (string) $item['namespace']) as $segment) {
                $levels[] = mb_convert_case(str_replace(['_', '-'], ' ', $segment), MB_CASE_TITLE, 'UTF-8');
            }
        } else {
            $levels[] = (string) $item['category'];

            if (!empty($item['subcategory']) && $item['subcategory'] !== $item['category']) {
                $levels[] = (string) $item['subcategory'];
            }
        }

        $levels[] = (string) $item['title'];

        return array_values(array_filter($levels, static fn ($l) => $l !== ''));
    }

    /** Vincula PDFs às páginas DokuWiki que os citam ({{…arquivo.pdf}}), por nome de arquivo. */
    private function linkMedia(array &$items): void
    {
        $pdfByName = [];

        foreach ($items as $i => $item) {
            if ($item['format'] === 'pdf') {
                $pdfByName[mb_strtolower((string) $item['original_filename'])][] = $i;
            }
        }

        foreach ($items as $item) {
            foreach ((array) $item['media_refs'] as $ref) {
                $name = mb_strtolower((string) substr($ref, (int) strrpos($ref, ':') + (str_contains($ref, ':') ? 1 : 0)));

                if (count($pdfByName[$name] ?? []) === 1) {
                    $items[$pdfByName[$name][0]]['related_documents'][] = $item['file'];
                }
            }
        }
    }

    // ================================================================== importação

    /**
     * Marca para importação os itens NOVOS (todos ou os ids selecionados).
     *
     * @param array<int, int>|null $ids
     */
    public function queue(string $batchId, ?array $ids, ?int $userId = null): array
    {
        return $this->withLockedState($batchId, function (array $state) use ($ids, $userId): array {
            $wanted = $ids !== null ? array_flip(array_map('intval', $ids)) : null;
            $queued = 0;

            foreach ($state['items'] as &$item) {
                if ($item['status'] === 'new' && in_array($item['import_status'], ['pending', 'failed'], true)
                    && ($wanted === null || isset($wanted[(int) $item['id']]))) {
                    $item['import_status'] = 'queued';
                    $item['error'] = null;
                    $queued++;
                }
            }
            unset($item);

            $state['import'] ??= ['started_at' => date('Y-m-d H:i:s'), 'started_by' => $userId, 'finished_at' => null];
            $state['import']['finished_at'] = null;

            Audit::record('knowledge.bulk_import_started', 'knowledge_import', null, [
                'batch_id' => $state['batch_id'], 'queued' => $queued, 'mode' => $ids === null ? 'all_new' : 'selected',
            ]);

            return $state;
        });
    }

    /** Processa o próximo lote de itens "queued". Retomável a qualquer momento. */
    public function importNext(string $batchId, ?int $limit = null, ?int $userId = null): array
    {
        $limit = max(1, min(100, $limit ?? (int) config('knowledge.bulk_import.batch_size', 25)));

        return $this->withLockedState($batchId, function (array $state) use ($limit, $userId): array {
            $processed = 0;
            $officialSourceName = (string) ($this->knowledge->sourceForType('official')['name'] ?? 'official');

            foreach ($state['items'] as &$item) {
                if ($processed >= $limit) {
                    break;
                }

                if ($item['import_status'] !== 'queued') {
                    continue;
                }

                $processed++;
                $this->importItem($item, $state, $officialSourceName, $userId);
                // Persistência a cada item: interrupção não perde o progresso.
                $this->saveState($state);
            }
            unset($item);

            $state['summary'] = $this->summarize($state);

            if ($state['summary']['queued'] === 0 && $state['import'] !== null && $state['import']['finished_at'] === null) {
                $state['import']['finished_at'] = date('Y-m-d H:i:s');
                Audit::record('knowledge.bulk_import_finished', 'knowledge_import', null, [
                    'batch_id' => $state['batch_id'], 'imported' => $state['summary']['imported'],
                    'failed' => $state['summary']['failed'], 'skipped' => $state['summary']['skipped'],
                ]);
            }

            return $state;
        });
    }

    private function importItem(array &$item, array $state, string $sourceName, ?int $userId): void
    {
        $started = microtime(true);
        $batchId = (string) $state['batch_id'];

        try {
            $format = (string) ($item['format'] ?? strtolower((string) $item['type']));
            $item['format'] = isset($this->extractors[$format]) ? $format : 'html';
            $doc = $this->extractItem($item, $state);
            $content = (string) $doc['content'];

            // O arquivo mudou desde a análise? Não importa às cegas.
            if ($this->knowledge->contentHash($this->knowledge->normalizeText($content)) !== $item['hash']) {
                throw new RuntimeException('O arquivo foi alterado depois da análise. Execute "Analisar" novamente.');
            }

            $result = $this->knowledge->importManual([
                'title' => $item['title'],
                'url' => (string) ($item['canonical_url'] ?? ''),
                'origin' => 'official',
                'category' => $item['category'],
                'subcategory' => (string) ($item['subcategory'] ?? $item['category_original'] ?? ''),
                'status' => 'active',
                'content' => $content,
            ], $userId);

            $status = (string) ($result['status'] ?? 'error');

            if ($status === KnowledgeService::STATUS_CREATED) {
                $documentId = (int) $result['document_id'];
                $this->documents->setImportMetadata($documentId, $this->importMetadata($item, $batchId), $item['fetched_at']);
                $chunks = (int) Database::connection()->query('SELECT COUNT(*) FROM knowledge_chunks WHERE document_id = ' . $documentId)->fetchColumn();
                $item['import_status'] = 'imported';
                $item['document_id'] = $documentId;
                $item['chunks'] = $chunks;
                Audit::record('knowledge.document_imported', 'knowledge_document', $documentId, [
                    'batch_id' => $batchId, 'chunks' => $chunks, 'source' => $sourceName, 'format' => $item['format'],
                ]);
            } elseif (in_array($status, [KnowledgeService::STATUS_DUPLICATE, KnowledgeService::STATUS_CONTENT_DUPLICATE, KnowledgeService::STATUS_VERSION_CONFLICT], true)) {
                $item['import_status'] = 'skipped';
                $item['document_id'] = isset($result['document_id']) ? (int) $result['document_id'] : null;
                $item['error'] = (string) ($result['message'] ?? $status);
                Audit::record('knowledge.document_skipped', 'knowledge_import', $item['document_id'], ['batch_id' => $batchId, 'reason' => $status]);
            } else {
                $message = isset($result['errors']) && is_array($result['errors']) ? implode(' ', $result['errors']) : (string) ($result['message'] ?? 'Falha na importação.');
                throw new RuntimeException($message);
            }
        } catch (\Throwable $exception) {
            $item['import_status'] = 'failed';
            $item['error'] = mb_substr($exception->getMessage(), 0, 300);
            Audit::record('knowledge.document_failed', 'knowledge_import', null, ['batch_id' => $batchId, 'file' => $item['file']]);
        }

        Logger::info('knowledge_import', 'Item processado', [
            'batch_id' => $batchId, 'file' => $item['file'], 'format' => $item['format'] ?? null,
            'url' => $item['canonical_url'], 'status' => $item['import_status'], 'http' => null,
            'document_id' => $item['document_id'], 'chunks' => $item['chunks'], 'hash' => $item['hash'],
            'duration_ms' => round((microtime(true) - $started) * 1000, 1), 'error' => $item['error'],
        ]);
    }

    /** Proveniência completa gravada em knowledge_documents.metadata (sem migration). */
    private function importMetadata(array $item, string $batchId): array
    {
        return array_filter([
            'source_type' => 'official_unimestre',
            'import_format' => $item['format'],
            'source_url' => $item['url'],
            'canonical_url' => $item['canonical_url'],
            'url_source' => $item['url_source'],
            'source_identifier' => $item['source_identifier'],
            'source_file' => $this->portablePath((string) $item['file']),
            'original_filename' => $item['original_filename'],
            'file_hash' => $item['file_hash'],
            'content_hash' => $item['hash'],
            'dokuwiki_id' => $item['dokuwiki_id'],
            'namespace' => $item['namespace'],
            'slug' => $item['slug'],
            'category_original' => $item['category_original'],
            'subcategory' => $item['subcategory'],
            'hierarchy' => $item['hierarchy'],
            'document_kind' => $item['document_kind'],
            'is_historical' => $item['is_historical'],
            'version' => $item['version'],
            'published_at' => $item['published_at'],
            'source_modified_at' => $item['source_modified_at'],
            'source_modified_type' => $item['source_modified_type'],
            'fetched_at' => $item['fetched_at'],
            'last_verified_at' => date('Y-m-d H:i:s'),
            'internal_links' => $item['internal_links'] ?: null,
            'media_refs' => $item['media_refs'] ?: null,
            'related_documents' => $item['related_documents'] ?: null,
            'pdf' => $item['pdf'],
            'import_batch' => $batchId,
            'importer' => 'local_files',
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    // ================================================================== upload ZIP

    /**
     * Extrai um ZIP enviado pelo admin para uma subpasta nova do diretório de
     * importação. Protege contra: zip bomb (tamanho total e razão de
     * compressão), excesso de entradas, link simbólico, path traversal,
     * nomes estranhos/reservados/ocultos, extensões não permitidas e
     * extensões executáveis (inclusive em extensão dupla).
     */
    public function uploadZip(string $tmpPath, string $originalName): array
    {
        $this->ensureDirs();

        if ((int) @filesize($tmpPath) > (int) config('knowledge.bulk_import.max_zip_bytes', 104857600)) {
            return ['status' => 'error', 'message' => 'O arquivo ZIP excede o tamanho máximo permitido.'];
        }

        $zip = new ZipArchive();

        if ($zip->open($tmpPath) !== true) {
            return ['status' => 'error', 'message' => 'Não foi possível abrir o arquivo ZIP.'];
        }

        $maxEntries = (int) config('knowledge.bulk_import.max_zip_entries', 10000);
        $maxTotal = (int) config('knowledge.bulk_import.max_uncompressed_bytes', 500 * 1024 * 1024);

        if ($zip->numFiles > $maxEntries) {
            $zip->close();

            return ['status' => 'error', 'message' => 'O ZIP contém entradas demais (limite: ' . $maxEntries . ').'];
        }

        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $total += (int) ($zip->statIndex($i)['size'] ?? 0);
        }

        if ($total > $maxTotal) {
            $zip->close();
            Logger::warning('knowledge_import', 'ZIP recusado: tamanho descompactado excessivo', ['file' => basename($originalName), 'uncompressed' => $total]);

            return ['status' => 'error', 'message' => 'O conteúdo descompactado do ZIP excede o limite de segurança (possível zip bomb).'];
        }

        $uploadId = 'upload_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $target = $this->dir . DIRECTORY_SEPARATOR . $uploadId;
        $allowed = array_merge((array) config('knowledge.bulk_import.extensions', []), ['csv']);
        $maxFile = (int) config('knowledge.bulk_import.max_file_bytes', 5242880);
        $maxFiles = (int) config('knowledge.bulk_import.max_files', 3000);
        $extracted = 0;
        $skipped = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));

            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            if (!$this->safeZipEntry($zip, $i, $stat, $name, $allowed, $maxFile) || $extracted >= $maxFiles) {
                $skipped++;
                continue;
            }

            $segments = explode('/', $name);
            $safe = implode('/', array_map(static fn ($s) => preg_replace('/[^\p{L}\p{N}._ -]/u', '_', $s) ?? '_', $segments));
            $dest = $target . '/' . $safe;

            if (!is_dir(dirname($dest))) {
                @mkdir(dirname($dest), 0775, true);
            }

            $in = $zip->getStream((string) $stat['name']);

            if ($in === false) {
                $skipped++;
                continue;
            }

            $out = fopen($dest, 'wb');
            $written = stream_copy_to_stream($in, $out, $maxFile + 1);
            fclose($in);
            fclose($out);

            if ($written === false || $written > $maxFile) {
                @unlink($dest);
                $skipped++;
                continue;
            }

            $extracted++;
        }

        $zip->close();
        Logger::info('knowledge_import', 'ZIP extraído', ['file' => basename($originalName), 'extracted' => $extracted, 'skipped' => $skipped]);

        return ['status' => 'ok', 'extracted' => $extracted, 'skipped' => $skipped, 'folder' => $uploadId, 'upload_id' => $uploadId];
    }

    private function safeZipEntry(ZipArchive $zip, int $index, array $stat, string $name, array $allowed, int $maxFile): bool
    {
        $segments = explode('/', $name);
        $base = (string) end($segments);
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        $size = (int) ($stat['size'] ?? 0);
        $compressed = max(1, (int) ($stat['comp_size'] ?? 1));

        if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1 || in_array('..', $segments, true)
            || in_array('', array_slice($segments, 0, -1), true) || strlen($name) > 240 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return false; // traversal / absoluto / nome estranho
        }

        foreach ($segments as $segment) {
            if (str_starts_with($segment, '.') || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i', $segment) === 1) {
                return false; // oculto (.htaccess…) ou nome reservado do Windows
            }
        }

        if (!in_array($ext, $allowed, true)
            || preg_match('/\.(php\d?|phtml|phar|phps|exe|bat|cmd|com|ps1|sh|js|mjs|vbs|cgi|pl|py|jar|msi|dll|scr|hta|asp|aspx|jsp|htaccess)(\.|$)/i', $base) === 1) {
            return false; // extensão proibida, inclusive dupla (arquivo.php.html)
        }

        if ($size > $maxFile || ($size > 1048576 && $size / $compressed > 200)) {
            return false; // grande demais ou razão de compressão típica de bomb
        }

        if ($zip->getExternalAttributesIndex($index, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
            return false; // link simbólico
        }

        return true;
    }

    // ================================================================== estado

    public function latestState(): ?array
    {
        $files = glob($this->stateDir . '/batch_*.json') ?: [];

        if ($files === []) {
            return null;
        }

        // O mais recente pelo horário de gravação, NÃO pelo nome: o nome leva
        // a hora do fuso do processo (CLI e Apache podem divergir), então a
        // ordem alfabética pode eleger um lote antigo.
        usort($files, static fn (string $x, string $y): int => [(int) filemtime($y), $y] <=> [(int) filemtime($x), $x]);

        return $this->loadStateFile($files[0]);
    }

    public function state(string $batchId): ?array
    {
        if (preg_match('/^[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', $batchId) !== 1) {
            return null;
        }

        return $this->loadStateFile($this->stateFile($batchId));
    }

    public function summarize(array $state): array
    {
        $summary = [
            'files' => 0, 'valid_html' => 0, 'new' => 0, 'exists' => 0, 'update' => 0, 'duplicate' => 0,
            'pdf' => 0, 'blocked' => 0, 'ignored' => 0, 'error' => 0,
            'html' => 0, 'dokuwiki' => 0, 'txt' => 0, 'markdown' => 0, 'media' => 0,
            'pdf_text' => 0, 'pdf_ocr' => 0, 'pdf_failed' => 0, 'pdf_unsupported' => 0,
            'queued' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0,
            'est_documents' => 0, 'est_chunks' => 0, 'categories' => [], 'kinds' => [],
        ];

        foreach ($state['items'] as $item) {
            $summary['files']++;
            $format = (string) ($item['format'] ?? ($item['type'] === 'PDF' ? 'pdf' : 'html'));
            $formatKey = ['text' => 'txt', 'media' => 'media'][$format] ?? $format;

            if (isset($summary[$formatKey])) {
                $summary[$formatKey]++;
            }

            $status = (string) $item['status'];

            if (isset($summary[$status]) && !str_starts_with($status, 'pdf')) {
                $summary[$status]++;
            }

            if ($format === 'pdf') {
                $bucket = match ($status) {
                    'pdf_ocr' => 'pdf_ocr', 'pdf_failed' => 'pdf_failed', 'pdf_unsupported' => 'pdf_unsupported', 'pdf_pending' => null,
                    default => 'pdf_text',
                };

                if ($bucket !== null) {
                    $summary[$bucket]++;
                }
            }

            if ($format !== 'pdf' && $format !== 'media' && in_array($status, ['new', 'exists', 'update', 'duplicate'], true)) {
                $summary['valid_html']++;
            }

            if ($status === 'new') {
                $summary['est_documents']++;
                $summary['est_chunks'] += (int) $item['est_chunks'];
                $label = $item['category'] . (!empty($item['category_original']) ? ' ← ' . $item['category_original'] : '');
                $summary['categories'][$label] = ($summary['categories'][$label] ?? 0) + 1;
                $kind = (string) ($item['document_kind'] ?? 'documentation');
                $summary['kinds'][$kind] = ($summary['kinds'][$kind] ?? 0) + 1;
            }

            foreach (['queued', 'imported', 'skipped', 'failed'] as $s) {
                if ($item['import_status'] === $s) {
                    $summary[$s]++;
                }
            }
        }

        arsort($summary['categories']);

        return $summary;
    }

    private function withLockedState(string $batchId, callable $fn): array
    {
        $file = $this->stateFile($batchId);

        if (preg_match('/^[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', $batchId) !== 1 || !is_file($file)) {
            throw new RuntimeException('Lote de importação não encontrado.');
        }

        $lock = fopen($file . '.lock', 'c');

        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Outro processamento deste lote está em andamento. Aguarde.');
        }

        try {
            $state = $this->loadStateFile($file);

            if ($state === null) {
                throw new RuntimeException('Estado do lote ilegível.');
            }

            $state = $fn($state);
            $state['summary'] = $this->summarize($state);
            $this->saveState($state);

            return $state;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function saveState(array $state): void
    {
        $file = $this->stateFile((string) $state['batch_id']);
        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
        rename($tmp, $file);
    }

    private function loadStateFile(string $file): ?array
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : null;
    }

    private function stateFile(string $batchId): string
    {
        return $this->stateDir . '/batch_' . $batchId . '.json';
    }

    // ================================================================== auxiliares

    /** @return array<int, string> caminhos relativos, ordenados */
    private function listFiles(): array
    {
        $scan = realpath($this->scanRoot !== '' ? $this->scanRoot : $this->dir);

        if ($scan === false || !is_dir($scan)) {
            return [];
        }

        $allowed = (array) config('knowledge.bulk_import.extensions', []);
        // Caminhos continuam relativos a $dir ("upload_x/..."), mas só o upload atual é percorrido.
        $root = realpath($this->dir);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scan, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }

            $real = (string) $file->getRealPath();

            if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (in_array(strtolower($file->getExtension()), $allowed, true)) {
                $files[] = str_replace('\\', '/', substr($real, strlen($root) + 1));
            }

            if (count($files) >= (int) config('knowledge.bulk_import.max_files', 3000)) {
                break;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * manifest.csv opcional (em qualquer subpasta até 3 níveis). Colunas:
     *   file, source_url (ou url), title, category, subcategory, published_at,
     *   source_modified_at, source_modified_type, format, dokuwiki_id
     * O manifest tem precedência sobre o que for inferido do arquivo.
     *
     * @return array<string, array<string, string>>
     */
    private function readManifest(): array
    {
        $manifest = [];
        $root = (string) realpath($this->dir);

        $scan = $this->scanRoot !== '' ? $this->scanRoot : $this->dir;

        foreach (glob($scan . '/{,*/,*/*/,*/*/*/}manifest.csv', GLOB_BRACE) ?: [] as $csv) {
            $base = trim(str_replace('\\', '/', substr(dirname((string) realpath($csv)), strlen($root))), '/');
            $handle = fopen($csv, 'rb');

            if ($handle === false) {
                continue;
            }

            $header = null;

            while (($row = fgetcsv($handle)) !== false) {
                if ($header === null) {
                    $header = array_map(static fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), $row);
                    continue;
                }

                $data = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), '')) ?: [];
                $url = trim((string) ($data['source_url'] ?? $data['url'] ?? ''));

                if (empty($data['file'])) {
                    continue;
                }

                $key = $this->manifestKey(($base !== '' ? $base . '/' : '') . trim($data['file']));
                $type = strtolower(trim((string) ($data['source_modified_type'] ?? '')));
                $manifest[$key] = array_filter([
                    'url' => $url,
                    'title' => trim((string) ($data['title'] ?? '')),
                    'category' => trim((string) ($data['category'] ?? '')),
                    'subcategory' => trim((string) ($data['subcategory'] ?? '')),
                    'published_at' => $this->manifestDate($data['published_at'] ?? ''),
                    'source_modified_at' => $this->manifestDate($data['source_modified_at'] ?? ''),
                    'source_modified_type' => in_array($type, ['external_edit', 'edicao externa', 'edição externa'], true) ? 'external_edit' : ($type !== '' ? 'edit' : ''),
                    'format' => strtolower(trim((string) ($data['format'] ?? ''))),
                    'dokuwiki_id' => trim((string) ($data['dokuwiki_id'] ?? '')),
                ], static fn ($v) => $v !== null && $v !== '');
            }

            fclose($handle);
        }

        return $manifest;
    }

    /** Aceita AAAA-MM-DD[ HH:MM] ou DD/MM/AAAA[ HH:MM]; outro formato → vazio. */
    private function manifestDate(string $value): string
    {
        $value = trim($value);

        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})(?:\s+(\d{2}):(\d{2}))?$#', $value, $m) === 1) {
            return sprintf('%s-%s-%s %s:%s:00', $m[3], $m[2], $m[1], $m[4] ?? '00' ?: '00', $m[5] ?? '00' ?: '00');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value) === 1 && ($ts = strtotime($value)) !== false) {
            return date('Y-m-d H:i:s', $ts);
        }

        return '';
    }

    private function manifestKey(string $relative): string
    {
        return mb_strtolower(str_replace('\\', '/', ltrim($relative, './')));
    }

    /** Caminho sem o prefixo "upload_AAAAMMDD_HHMMSS/" (estável entre uploads). */
    private function portablePath(string $relative): string
    {
        return preg_replace('#^upload_\d{8}_\d{6}/#', '', $relative) ?? $relative;
    }

    private function mapCategory(?string $original, string $title, ?string $namespace = null): string
    {
        $allowed = (array) config('knowledge.categories', []);
        $original = trim((string) $original);

        foreach ($allowed as $category) {
            if ($original !== '' && mb_strtolower($original) === mb_strtolower((string) $category)) {
                return (string) $category;
            }
        }

        $haystack = $this->fold(trim($original . ' ' . str_replace(':', ' ', (string) $namespace)) ?: $title);

        foreach ((array) config('knowledge.bulk_import.category_map', []) as $category => $keywords) {
            foreach ((array) $keywords as $keyword) {
                if (preg_match('/(^|[^a-z])' . preg_quote($this->fold((string) $keyword), '/') . '/', $haystack) === 1) {
                    return in_array($category, $allowed, true) ? (string) $category : 'Outros';
                }
            }
        }

        return 'Outros';
    }

    private function fold(string $value): string
    {
        return strtr(mb_strtolower($value), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
    }

    private function clip(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, 150) : null;
    }

    private function titleFromFile(string $relative): string
    {
        $name = trim(preg_replace('/[_-]+/', ' ', pathinfo($relative, PATHINFO_FILENAME)) ?? '');

        return mb_substr(mb_convert_case($name, MB_CASE_TITLE, 'UTF-8'), 0, 255);
    }

    /** @return array<int, string> upload_ids existentes, do mais recente para o mais antigo */
    public function uploads(): array
    {
        $ids = [];

        foreach (glob($this->dir . '/upload_*', GLOB_ONLYDIR) ?: [] as $path) {
            if (self::validUploadId(basename($path)) && !is_link($path)) {
                $ids[basename($path)] = (int) filemtime($path);
            }
        }

        uksort($ids, static fn (string $a, string $b): int => [$ids[$b], $b] <=> [$ids[$a], $a]);

        return array_keys($ids);
    }

    public static function validUploadId(string $id): bool
    {
        return preg_match('/^upload_\d{8}_\d{6}(_[a-f0-9]{6})?$/', $id) === 1;
    }

    /**
     * Resolve o upload SOMENTE dentro de $dir (nunca aceita caminho vindo do
     * navegador): formato estrito + realpath contido + não é link.
     */
    public function uploadPath(string $uploadId): ?string
    {
        if (!self::validUploadId($uploadId)) {
            return null;
        }

        $root = realpath($this->dir);
        $path = $this->dir . DIRECTORY_SEPARATOR . $uploadId;
        $real = is_dir($path) && !is_link($path) ? realpath($path) : false;

        return $root !== false && $real !== false && dirname($real) === $root ? $real : null;
    }

    private function ensureDirs(): void
    {
        foreach ([$this->dir, $this->stateDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        // Defesa extra: mesmo que a regra da raiz falhe, nada aqui é servido.
        $guard = dirname($this->dir) . '/.htaccess';

        if (is_dir(dirname($this->dir)) && !is_file($guard) && str_contains(str_replace('\\', '/', $this->dir), '/storage/imports')) {
            @file_put_contents($guard, "Require all denied\n");
        }
    }
}
