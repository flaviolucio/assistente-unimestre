<?php
$item = $preview['item'];
$doc = $preview['document'];
$content = (string) $preview['content'];
$show = [
    'Arquivo' => $item['file'],
    'Formato' => $formatLabels[$item['format'] ?? ''] ?? ($item['type'] ?? ''),
    'Status' => $actions[$item['status']] ?? $item['status'],
    'URL oficial' => $item['canonical_url'] ?? '—',
    'Identificador de origem' => $item['source_identifier'] ?? '—',
    'Categoria' => $item['category'] . (!empty($item['category_original']) ? ' (fonte: ' . $item['category_original'] . ')' : ''),
    'Subcategoria' => $item['subcategory'] ?? '—',
    'Tipo de documento' => ($item['document_kind'] ?? '—') . (!empty($item['is_historical']) ? ' · histórico' : '') . (!empty($item['version']) ? ' · v' . $item['version'] : ''),
    'Namespace DokuWiki' => $item['namespace'] ?? '—',
    'Hierarquia' => implode(' › ', (array) ($item['hierarchy'] ?? [])) ?: '—',
    'Data de publicação' => $item['published_at'] ?? '—',
    'Última modificação na fonte' => ($item['source_modified_at'] ?? '—') . (($item['source_modified_type'] ?? '') === 'external_edit' ? ' (edição externa)' : ''),
    'Coletado em (arquivo)' => $item['fetched_at'] ?? '—',
    'SHA-256 do arquivo' => $item['file_hash'] ?? '—',
    'Hash do conteúdo' => $item['hash'] ?? '—',
];

if (!empty($item['pdf'])) {
    $show['PDF'] = 'páginas: ' . ($item['pdf']['pdf_pages'] ?? '?') . ' · sem texto: ' . ($item['pdf']['pdf_pages_without_text'] ?? '?') . ' · extrator: ' . ($item['pdf']['pdf_extractor'] ?? '?');
}
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h4 mb-0">Prévia da extração</h1>
    <a href="<?php echo e(app_url('admin/knowledge/importacao-oficial') . '?lote=' . rawurlencode($batchId)); ?>" class="btn btn-outline-secondary btn-sm">Voltar ao inventário</a>
</div>

<div class="alert alert-info app-flash small">Somente leitura: nada foi gravado. Abaixo está exatamente o texto que seria enviado ao RAG.</div>

<div class="card app-card mb-3">
    <div class="card-body">
        <h2 class="h5"><?php echo e((string) $item['title']); ?></h2>
        <dl class="row small mb-0">
            <?php foreach ($show as $label => $value): ?>
                <dt class="col-md-3 text-muted"><?php echo e($label); ?></dt>
                <dd class="col-md-9 text-break"><?php echo e((string) $value); ?></dd>
            <?php endforeach; ?>
        </dl>
    </div>
</div>

<div class="card app-card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between small text-muted mb-2">
            <span>Texto para o RAG</span>
            <span><?php echo mb_strlen($content); ?> caracteres</span>
        </div>
        <?php if ($content === ''): ?>
            <p class="text-muted small mb-0"><?php echo e((string) ($item['reason'] ?? 'Nenhum texto extraído.')); ?></p>
        <?php else: ?>
            <pre class="small mb-0" style="white-space: pre-wrap; max-height: 70vh; overflow: auto;"><?php echo e($content); ?></pre>
        <?php endif; ?>
    </div>
</div>
