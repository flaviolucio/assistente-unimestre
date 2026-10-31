<?php
$error = static fn (string $field): string => isset($errors[$field]) ? e($errors[$field]) : '';
$oldValue = static fn (string $field, string $default = ''): string => e((string) ($old[$field] ?? $document[$field] ?? $default));
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Editar documento</h1>
    <div class="d-flex gap-2">
        <a href="<?php echo app_url('knowledge/' . (int) $document['id']); ?>" class="btn btn-outline-secondary btn-sm">Ver</a>
        <a href="<?php echo app_url('knowledge'); ?>" class="btn btn-outline-secondary btn-sm">&larr; Voltar</a>
    </div>
</div>

<div class="card app-card mb-3">
    <div class="card-body py-2">
        <span class="text-muted small">Origem atual: </span>
        <?php if ($document['source_type'] === 'official'): ?>
            <span class="badge app-badge-official">OFICIAL UNIMESTRE</span>
        <?php else: ?>
            <span class="badge app-badge-internal">PROCEDIMENTO INTERNO</span>
        <?php endif; ?>
        <span class="text-muted small ms-3">Hash atual: <code><?php echo e($document['content_hash']); ?></code></span>
    </div>
</div>

<form method="post" action="<?php echo app_url('admin/knowledge/' . (int) $document['id'] . '/edit'); ?>" autocomplete="off">
    <?php echo csrf_field(); ?>
    <div class="card app-card mb-4">
        <div class="card-header bg-transparent fw-semibold">Dados</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="title" class="form-label">Título</label>
                    <input type="text" class="form-control" id="title" name="title"
                           value="<?php echo $oldValue('title'); ?>" required>
                    <?php if ($error('title') !== ''): ?><div class="text-danger small"><?php echo $error('title'); ?></div><?php endif; ?>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="origin" class="form-label">Origem</label>
                    <select class="form-select" id="origin" name="origin">
                        <?php foreach ($origins as $type => $label): ?>
                            <option value="<?php echo e($type); ?>" <?php echo $oldValue('origin', $document['source_type']) === $type ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($error('origin') !== ''): ?><div class="text-danger small"><?php echo $error('origin'); ?></div><?php endif; ?>
                    <div class="form-text">Mudar a origem registra uma auditoria explícita.</div>
                </div>
            </div>
            <div class="mb-3">
                <label for="url" class="form-label">URL original</label>
                <input type="url" class="form-control" id="url" name="url"
                       value="<?php echo $oldValue('url'); ?>">
                <?php if ($error('url') !== ''): ?><div class="text-danger small"><?php echo $error('url'); ?></div><?php endif; ?>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="category" class="form-label">Categoria</label>
                    <select class="form-select" id="category" name="category">
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo e($category); ?>" <?php echo $oldValue('category') === $category ? 'selected' : ''; ?>><?php echo e($category); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($error('category') !== ''): ?><div class="text-danger small"><?php echo $error('category'); ?></div><?php endif; ?>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="subcategory" class="form-label">Subcategoria</label>
                    <input type="text" class="form-control" id="subcategory" name="subcategory"
                           value="<?php echo $oldValue('subcategory'); ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="active" <?php echo $oldValue('status') === 'active' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="inactive" <?php echo $oldValue('status') === 'inactive' ? 'selected' : ''; ?>>Inativo</option>
                    </select>
                    <?php if ($error('status') !== ''): ?><div class="text-danger small"><?php echo $error('status'); ?></div><?php endif; ?>
                </div>
            </div>
            <div class="mb-3">
                <label for="content" class="form-label">Conteúdo</label>
                <textarea class="form-control" id="content" name="content" rows="18" required><?php echo $oldValue('content'); ?></textarea>
                <?php if ($error('content') !== ''): ?><div class="text-danger small"><?php echo $error('content'); ?></div><?php endif; ?>
            </div>
        </div>
        <div class="card-footer bg-transparent">
            <button type="submit" class="btn btn-primary">Salvar alterações</button>
        </div>
    </div>
</form>