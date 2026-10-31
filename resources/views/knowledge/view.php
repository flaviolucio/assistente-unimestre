<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h4 mb-0"><?php echo e($document['title']); ?></h1>
    <div class="d-flex gap-2">
        <a href="<?php echo app_url('knowledge'); ?>" class="btn btn-outline-secondary btn-sm">&larr; Voltar</a>
        <?php if ($canManage): ?>
            <a href="<?php echo app_url('admin/knowledge/' . (int) $document['id'] . '/edit'); ?>" class="btn btn-outline-primary btn-sm">Editar</a>
        <?php endif; ?>
    </div>
</div>

<div class="card app-card mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="text-muted small text-uppercase mb-1">Origem</div>
                <?php if ($document['source_type'] === 'official'): ?>
                    <span class="badge app-badge-official">OFICIAL UNIMESTRE</span>
                <?php else: ?>
                    <span class="badge app-badge-internal">PROCEDIMENTO INTERNO</span>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <div class="text-muted small text-uppercase mb-1">Status</div>
                <?php if ($document['status'] === 'active'): ?>
                    <span class="badge text-bg-success">ATIVO</span>
                <?php else: ?>
                    <span class="badge text-bg-secondary">INATIVO</span>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <div class="text-muted small text-uppercase mb-1">Categoria / Subcategoria</div>
                <span class="badge text-bg-light border"><?php echo e((string) ($document['category'] ?? '—')); ?></span>
                <?php if (!empty($document['subcategory'])): ?>
                    <span class="badge text-bg-light border"><?php echo e($document['subcategory']); ?></span>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <div class="text-muted small text-uppercase mb-1">URL original</div>
                <?php if (\App\Support\SourceUrl::isTrusted(['url' => (string) ($document['url'] ?? ''), 'title' => (string) ($document['title'] ?? '')])): ?>
                    <a href="<?php echo e((string) $document['url']); ?>" target="_blank" rel="noopener noreferrer" class="app-link small">
                        Abrir documentação original
                    </a>
                <?php else: ?>
                    <span class="text-muted small">&mdash;</span>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <div class="text-muted small text-uppercase mb-1">Importado em</div>
                <div class="small"><?php echo e(date('d/m/Y H:i', strtotime((string) $document['collected_at']))); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small text-uppercase mb-1">Última atualização</div>
                <div class="small"><?php echo e(date('d/m/Y H:i', strtotime((string) $document['updated_at']))); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card app-card">
    <div class="card-header bg-transparent fw-semibold">Conteúdo</div>
    <div class="card-body">
        <div class="app-prose">
            <?php echo nl2br(e($document['content'])); ?>
        </div>
    </div>
</div>