<?php
$summary = $state['summary'] ?? null;
$items = $state['items'] ?? [];
$batchId = (string) ($state['batch_id'] ?? '');
$statusLabels = [
    'new' => ['NOVO', 'text-bg-success'],
    'exists' => ['JÁ EXISTE', 'text-bg-secondary'],
    'update' => ['ATUALIZAÇÃO DISPONÍVEL', 'text-bg-warning'],
    'duplicate' => ['DUPLICADO', 'text-bg-light border'],
    'pdf_pending' => ['PDF — EXTRAÇÃO PENDENTE', 'text-bg-info'],
    'pdf_ocr' => ['PDF — OCR NECESSÁRIO', 'text-bg-warning'],
    'pdf_failed' => ['PDF — EXTRAÇÃO FALHOU', 'text-bg-danger'],
    'pdf_unsupported' => ['PDF — NÃO SUPORTADO', 'text-bg-secondary'],
    'media_ignored' => ['MÍDIA NÃO TEXTUAL — IGNORADA', 'text-bg-light border'],
    'blocked' => ['BLOQUEADO', 'text-bg-danger'],
    'ignored' => ['IGNORADO', 'text-bg-light border'],
    'error' => ['ERRO', 'text-bg-danger'],
];
$kindLabels = ['documentation' => 'Documentação', 'release_notes' => 'Notas de versão', 'guide' => 'Guia/PDF'];
$fmtDate = static fn (?string $d): string => $d ? date('d/m/Y', (int) strtotime($d)) : '—';
$importLabels = ['none' => '—', 'pending' => 'pendente', 'queued' => 'na fila', 'imported' => 'importado', 'skipped' => 'ignorado', 'failed' => 'falhou'];
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h4 mb-0">Importação oficial UNIMESTRE</h1>
    <a href="<?php echo e(app_url('knowledge')); ?>" class="btn btn-outline-secondary btn-sm">Base de Conhecimento</a>
</div>

<div class="card app-card mb-3">
    <div class="card-body small">
        <p class="mb-2">
            A importação lê <strong>arquivos locais</strong> (páginas salvas pelo navegador ou exportação oficial).
            <strong>Nenhuma requisição é feita ao site do UNIMESTRE</strong> e nenhuma IA é usada: o conteúdo é extraído de forma determinística,
            deduplicado por URL canônica e hash SHA-256 e passa pelo mesmo fluxo da importação manual (chunking e auditoria).
        </p>
        <ol class="mb-2">
            <li>Salve as páginas oficiais no navegador (<em>Salvar como → Página da Web, somente HTML</em>) ou use uma exportação oficial.</li>
            <li>Envie um <code>.zip</code> abaixo, ou coloque os arquivos em <code><?php echo e($importDir); ?></code>.</li>
            <li>Clique em <strong>Analisar base</strong> (não grava nada) e revise o inventário.</li>
            <li>Só então importe os <strong>novos</strong> (todos ou selecionados), em lotes de <?php echo (int) $batchSize; ?>.</li>
        </ol>
        <p class="mb-0 text-muted">
            Hosts aceitos: <?php echo e(implode(', ', $allowedHosts)); ?>. A URL oficial vem de <code>&lt;link rel="canonical"&gt;</code>,
            <code>og:url</code> ou do comentário <code>saved from url</code> gravado pelo navegador. Para <code>.txt</code>/<code>.md</code>/<code>.pdf</code>
            ou páginas sem URL, use um <code>manifest.csv</code> com as colunas <code>file,source_url,title,category,subcategory,published_at,source_modified_at,source_modified_type</code> (o manifest prevalece sobre o que for inferido).
            Exportações DokuWiki (<code>data/pages/*.txt</code>) são convertidas em texto semântico; páginas sem URL usam o identificador DokuWiki. PDFs têm o texto extraído localmente (sem OCR); imagens são catalogadas e ignoradas.
        </p>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-7">
        <form method="post" action="<?php echo e(app_url('admin/knowledge/importacao-oficial/upload')); ?>" enctype="multipart/form-data" class="card app-card h-100">
            <div class="card-body">
                <?php echo csrf_field(); ?>
                <label for="package" class="form-label small mb-1">Pacote .zip com as páginas oficiais</label>
                <div class="d-flex gap-2">
                    <input type="file" class="form-control form-control-sm" id="package" name="package" accept=".zip" required>
                    <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap">Enviar ZIP</button>
                </div>
            </div>
        </form>
    </div>
    <div class="col-md-5">
        <form method="post" action="<?php echo e(app_url('admin/knowledge/importacao-oficial/analisar')); ?>" class="card app-card h-100">
            <div class="card-body d-flex align-items-center gap-2">
                <?php echo csrf_field(); ?>
                <?php if (!empty($uploads)): ?>
                    <select name="upload_id" class="form-select form-select-sm w-auto" title="Somente este upload será analisado">
                        <?php foreach ($uploads as $u): ?>
                            <option value="<?php echo e($u); ?>" <?php echo $u === ($currentUpload ?? '') ? 'selected' : ''; ?>><?php echo e($u); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <select name="dokuwiki" class="form-select form-select-sm w-auto" title="Detecção de sintaxe DokuWiki em .txt">
                    <option value="auto">DokuWiki: automático</option>
                    <option value="force">.txt são DokuWiki</option>
                    <option value="off">.txt são texto simples</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Analisar base</button>
                <span class="small text-muted">Somente leitura: descobre e classifica, sem importar.</span>
            </div>
        </form>
    </div>
</div>

<?php if ($summary === null): ?>
    <div class="alert alert-info app-flash">Nenhuma análise realizada ainda.</div>
<?php else: ?>
    <div class="card app-card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
                <strong>Análise <?php echo e($batchId); ?></strong><?php if (!empty($state['upload_id'])): ?> <span class="small text-muted">· upload <?php echo e($state['upload_id']); ?></span><?php endif; ?>
                <span class="small text-muted"><?php echo e((string) $state['created_at']); ?></span>
            </div>
            <div class="row row-cols-2 row-cols-md-5 g-2 small" id="import-summary">
                <?php foreach ([
                    'files' => 'Arquivos analisados', 'valid_html' => 'Páginas válidas', 'new' => 'Novos', 'exists' => 'Já existentes',
                    'update' => 'Atualizações disponíveis', 'duplicate' => 'Duplicados', 'pdf' => 'PDFs', 'blocked' => 'Bloqueados',
                    'error' => 'Erros', 'ignored' => 'Ignorados',
                    'html' => 'HTML', 'dokuwiki' => 'DokuWiki', 'txt' => 'TXT', 'markdown' => 'Markdown', 'media' => 'Mídias ignoradas',
                    'pdf_text' => 'PDF com texto', 'pdf_ocr' => 'PDF — OCR necessário', 'pdf_failed' => 'PDF — falha', 'pdf_unsupported' => 'PDF — não suportado',
                ] as $key => $label): ?>
                    <div class="col"><div class="border rounded p-2 h-100"><div class="text-muted"><?php echo e($label); ?></div><div class="fs-5 fw-semibold" data-sum="<?php echo e($key); ?>"><?php echo (int) ($summary[$key] ?? 0); ?></div></div></div>
                <?php endforeach; ?>
            </div>
            <p class="small text-muted mt-2 mb-0">
                Estimativa: <?php echo (int) $summary['est_documents']; ?> documento(s) e <?php echo (int) $summary['est_chunks']; ?> chunk(s) novos.
                <?php if (!empty($state['unlisted_hosts'])): ?>
                    <br>Subdomínios UNIMESTRE encontrados fora da allowlist (não importados, para análise): <?php echo e(implode(', ', $state['unlisted_hosts'])); ?>.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="card app-card mb-3" id="import-panel" data-batch="<?php echo e($batchId); ?>"
         data-queue-url="<?php echo e(app_url('admin/knowledge/importacao-oficial/' . $batchId . '/fila')); ?>"
         data-batch-url="<?php echo e(app_url('admin/knowledge/importacao-oficial/' . $batchId . '/lote')); ?>"
         data-csrf="<?php echo e(\App\Core\Csrf::token()); ?>">
        <div class="card-body d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="btn btn-success btn-sm" id="btn-import-selected" <?php echo $summary['new'] > 0 ? '' : 'disabled'; ?>>Importar selecionados</button>
            <button type="button" class="btn btn-outline-success btn-sm" id="btn-import-all" <?php echo $summary['new'] > 0 ? '' : 'disabled'; ?>>Importar todos os novos</button>
            <button type="button" class="btn btn-outline-primary btn-sm <?php echo $summary['queued'] > 0 ? '' : 'd-none'; ?>" id="btn-continue">Continuar importação</button>
            <span class="small ms-md-2" id="import-progress">
                Importados: <span data-sum="imported"><?php echo (int) $summary['imported']; ?></span> ·
                Ignorados: <span data-sum="skipped"><?php echo (int) $summary['skipped']; ?></span> ·
                Erros: <span data-sum="failed"><?php echo (int) $summary['failed']; ?></span> ·
                Na fila: <span data-sum="queued"><?php echo (int) $summary['queued']; ?></span>
            </span>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
        <span class="text-muted">Filtrar:</span>
        <select data-filter="status" class="form-select form-select-sm w-auto">
            <option value="">Status: todos</option>
            <?php foreach ($statusLabels as $key => $label): ?>
                <option value="<?php echo e($key); ?>"><?php echo e(is_array($label) ? $label[0] : $label); ?></option>
            <?php endforeach; ?>
        </select>
        <select data-filter="format" class="form-select form-select-sm w-auto">
            <option value="">Formato: todos</option>
            <?php foreach ($formatLabels as $key => $label): ?>
                <option value="<?php echo e($key); ?>"><?php echo e(is_array($label) ? $label[0] : $label); ?></option>
            <?php endforeach; ?>
        </select>
        <select data-filter="category" class="form-select form-select-sm w-auto">
            <option value="">Categoria: todas</option>
            <?php foreach (array_combine($catList = array_values(array_unique(array_column($items, 'category'))), $catList) ?: [] as $key => $label): ?>
                <option value="<?php echo e($key); ?>"><?php echo e(is_array($label) ? $label[0] : $label); ?></option>
            <?php endforeach; ?>
        </select>
        <select data-filter="kind" class="form-select form-select-sm w-auto">
            <option value="">Tipo: todos</option>
            <?php foreach ($kindLabels as $key => $label): ?>
                <option value="<?php echo e($key); ?>"><?php echo e(is_array($label) ? $label[0] : $label); ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="table-responsive app-card mb-4">
        <table class="table table-sm align-middle mb-0 small" id="import-table">
            <thead class="table-light">
                <tr>
                    <th><input type="checkbox" class="form-check-input" id="select-all-new" title="Selecionar todos os novos"></th>
                    <th>Arquivo / Título / URL oficial</th><th>Formato</th><th>Categoria</th><th>Tipo</th><th>Publicação</th><th>Últ. modif. fonte</th><th>Status</th><th>Ação prevista</th><th>Importação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <?php [$label, $badge] = $statusLabels[$item['status']] ?? [$item['status'], 'text-bg-light']; ?>
                    <tr data-status="<?php echo e($item['status']); ?>" data-format="<?php echo e((string) ($item['format'] ?? '')); ?>" data-category="<?php echo e((string) $item['category']); ?>" data-kind="<?php echo e((string) ($item['document_kind'] ?? '')); ?>" data-id="<?php echo (int) $item['id']; ?>">
                        <td><?php if ($item['status'] === 'new'): ?><input type="checkbox" class="form-check-input item-select" value="<?php echo (int) $item['id']; ?>"><?php endif; ?></td>
                        <td class="text-break">
                            <div class="text-muted"><code><?php echo e($item['file']); ?></code></div>
                            <div class="fw-semibold"><?php echo e($item['title']); ?><?php if (!empty($item['is_historical'])): ?> <span class="badge text-bg-light border">histórico</span><?php endif; ?></div>
                            <div class="text-muted"><?php echo e((string) ($item['canonical_url'] ?? $item['source_identifier'] ?? 'sem URL oficial')); ?></div>
                            <?php if (!in_array($item['format'] ?? '', ['media', ''], true)): ?><a class="app-link" href="<?php echo e(app_url('admin/knowledge/importacao-oficial/' . $batchId . '/previa/' . (int) $item['id'])); ?>">Visualizar extração</a><?php endif; ?>
                            <?php if (!empty($item['reason'])): ?><div class="text-muted fst-italic"><?php echo e($item['reason']); ?></div><?php endif; ?>
                        </td>
                        <td><?php echo e($item['category']); ?><?php if (!empty($item['category_original'])): ?><div class="text-muted">fonte: <?php echo e($item['category_original']); ?></div><?php endif; ?></td>
                        <td><?php echo e($kindLabels[$item['document_kind'] ?? ''] ?? '—'); ?><?php if (!empty($item['version'])): ?><div class="text-muted">v<?php echo e($item['version']); ?></div><?php endif; ?></td>
                        <td><?php echo e($fmtDate($item['published_at'] ?? null)); ?></td>
                        <td><?php echo e($fmtDate($item['source_modified_at'] ?? null)); ?><?php if (($item['source_modified_type'] ?? '') === 'external_edit'): ?><div class="text-muted">edição externa</div><?php endif; ?></td>
                        <td><?php echo e($formatLabels[$item['format'] ?? ''] ?? $item['type']); ?></td>
                        <td><span class="badge <?php echo e($badge); ?>"><?php echo e($label); ?></span></td>
                        <td><?php echo e($actions[$item['status']] ?? '—'); ?></td>
                        <td data-import-cell>
                            <?php echo e($importLabels[$item['import_status']] ?? $item['import_status']); ?>
                            <?php if (!empty($item['document_id']) && $item['import_status'] === 'imported'): ?>
                                · <a class="app-link" href="<?php echo e(app_url('knowledge/' . (int) $item['document_id'])); ?>">doc <?php echo (int) $item['document_id']; ?></a> (<?php echo (int) $item['chunks']; ?> chunks)
                            <?php endif; ?>
                            <?php if (!empty($item['error'])): ?><div class="text-danger"><?php echo e($item['error']); ?></div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script>
(function () {
    'use strict';
    var panel = document.getElementById('import-panel');
    var filters = Array.prototype.slice.call(document.querySelectorAll('[data-filter]'));
    var rows = Array.prototype.slice.call(document.querySelectorAll('#import-table tbody tr'));
    var labels = { none: '—', pending: 'pendente', queued: 'na fila', imported: 'importado', skipped: 'ignorado', failed: 'falhou' };

    filters.forEach(function (f) {
        f.addEventListener('change', function () {
            rows.forEach(function (r) {
                r.hidden = filters.some(function (x) { return x.value !== '' && r.getAttribute('data-' + x.getAttribute('data-filter')) !== x.value; });
            });
        });
    });

    var selectAll = document.getElementById('select-all-new');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.item-select').forEach(function (c) { if (!c.closest('tr').hidden) { c.checked = selectAll.checked; } });
        });
    }

    if (!panel) { return; }

    var csrf = panel.getAttribute('data-csrf');
    var running = false;

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify(body || {})
        }).then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); });
    }

    function render(data) {
        Object.keys(data.summary).forEach(function (k) {
            document.querySelectorAll('[data-sum="' + k + '"]').forEach(function (el) { el.textContent = data.summary[k]; });
        });
        (data.items || []).forEach(function (item) {
            var row = document.querySelector('tr[data-id="' + item.id + '"]');
            if (!row) { return; }
            var cell = row.querySelector('[data-import-cell]');
            cell.textContent = (labels[item.import_status] || item.import_status)
                + (item.document_id && item.import_status === 'imported' ? ' · doc ' + item.document_id + ' (' + item.chunks + ' chunks)' : '')
                + (item.error ? ' — ' + item.error : '');
            if (item.import_status === 'imported') {
                var box = row.querySelector('.item-select');
                if (box) { box.checked = false; box.disabled = true; }
            }
        });
        document.getElementById('btn-continue').classList.toggle('d-none', data.summary.queued === 0);
    }

    function loop() {
        post(panel.getAttribute('data-batch-url')).then(function (res) {
            if (!res.data.ok) { alert(res.data.message || 'Falha no lote.'); running = false; return; }
            render(res.data);
            if (res.data.summary.queued > 0) { loop(); } else { running = false; }
        }).catch(function () { running = false; alert('Conexão interrompida. Use "Continuar importação".'); });
    }

    function start(body) {
        if (running) { return; }
        running = true;
        post(panel.getAttribute('data-queue-url'), body).then(function (res) {
            if (!res.data.ok) { alert(res.data.message || 'Não foi possível enfileirar.'); running = false; return; }
            render(res.data);
            loop();
        }).catch(function () { running = false; });
    }

    document.getElementById('btn-import-selected').addEventListener('click', function () {
        var ids = Array.prototype.map.call(document.querySelectorAll('.item-select:checked'), function (c) { return parseInt(c.value, 10); });
        if (ids.length === 0) { alert('Selecione ao menos um documento novo.'); return; }
        if (confirm('Importar ' + ids.length + ' documento(s) selecionado(s)?')) { start({ mode: 'selected', ids: ids }); }
    });
    document.getElementById('btn-import-all').addEventListener('click', function () {
        if (confirm('Importar TODOS os documentos NOVOS e válidos desta análise? (nenhum existente é alterado)')) { start({ mode: 'all' }); }
    });
    document.getElementById('btn-continue').addEventListener('click', function () {
        if (!running) { running = true; loop(); }
    });
})();
</script>
