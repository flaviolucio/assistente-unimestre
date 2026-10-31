<?php
$error = static fn (string $field): string => isset($errors[$field]) ? e($errors[$field]) : '';
$oldValue = static fn (string $field, string $default = ''): string => e((string) ($old[$field] ?? $default));
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Importar documento</h1>
    <a href="<?php echo app_url('knowledge'); ?>" class="btn btn-outline-secondary btn-sm">&larr; Voltar</a>
</div>

<?php if (!empty($old['duplicate_document_id'])): ?>
    <div class="alert alert-warning app-flash">
        <strong>Possível duplicidade:</strong> o documento existente pode ser verificado antes da decisão.
        <a class="alert-link" href="<?php echo app_url('knowledge/' . (int) $old['duplicate_document_id']); ?>">Ver documento existente</a>.
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <form method="post" action="<?php echo app_url('admin/knowledge/import'); ?>" enctype="multipart/form-data" autocomplete="off">
            <?php echo csrf_field(); ?>
            <div class="card app-card mb-4">
                <div class="card-header bg-transparent fw-semibold">Origem</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="origin" class="form-label">Origem do documento</label>
                            <select class="form-select" id="origin" name="origin" required>
                                <?php foreach ($origins as $type => $label): ?>
                                    <option value="<?php echo e($type); ?>" <?php echo $oldValue('origin', 'official') === $type ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($error('origin') !== ''): ?><div class="text-danger small"><?php echo $error('origin'); ?></div><?php endif; ?>
                            <div class="form-text">
                                <span class="badge app-badge-official">OFICIAL UNIMESTRE</span> e
                                <span class="badge app-badge-internal">PROCEDIMENTO INTERNO</span> são mantidos separados.
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="active" <?php echo $oldValue('status', 'active') === 'active' ? 'selected' : ''; ?>>Ativo</option>
                                <option value="inactive" <?php echo $oldValue('status', 'active') === 'inactive' ? 'selected' : ''; ?>>Inativo</option>
                            </select>
                            <?php if ($error('status') !== ''): ?><div class="text-danger small"><?php echo $error('status'); ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card app-card mb-4">
                <div class="card-header bg-transparent fw-semibold">Dados</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">Título</label>
                        <input type="text" class="form-control" id="title" name="title"
                               value="<?php echo $oldValue('title'); ?>" required>
                        <?php if ($error('title') !== ''): ?><div class="text-danger small"><?php echo $error('title'); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="url" class="form-label">URL original</label>
                        <input type="url" class="form-control" id="url" name="url"
                               value="<?php echo $oldValue('url'); ?>" placeholder="https://unimestre.com/...">
                        <div class="form-text">Obrigatória para documentação oficial; opcional para base interna.</div>
                        <?php if ($error('url') !== ''): ?><div class="text-danger small"><?php echo $error('url'); ?></div><?php endif; ?>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="category" class="form-label">Categoria</label>
                            <select class="form-select" id="category" name="category">
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo e($category); ?>" <?php echo $oldValue('category') === $category ? 'selected' : ''; ?>><?php echo e($category); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($error('category') !== ''): ?><div class="text-danger small"><?php echo $error('category'); ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="subcategory" class="form-label">Subcategoria</label>
                            <input type="text" class="form-control" id="subcategory" name="subcategory"
                                   value="<?php echo $oldValue('subcategory'); ?>">
                            <div class="form-text">Opcional (ex.: nome do módulo).</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="content" class="form-label">Conteúdo</label>
                        <textarea class="form-control" id="content" name="content" rows="14"
                                  placeholder="Cole o conteúdo textual do documento aqui..."><?php echo $oldValue('content'); ?></textarea>
                        <?php if ($error('content') !== ''): ?><div class="text-danger small"><?php echo $error('content'); ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="card-footer bg-transparent">
                    <button type="submit" class="btn btn-primary">Importar documento</button>
                </div>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="card app-card">
            <div class="card-header bg-transparent fw-semibold">Importar arquivo .txt/.md</div>
            <div class="card-body">
                <form method="post" action="<?php echo app_url('admin/knowledge/import'); ?>" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="title" value="">
                    <input type="hidden" name="url" value="">
                    <input type="hidden" name="category" value="Outros">
                    <input type="hidden" name="subcategory" value="">
                    <input type="hidden" name="status" value="active">
                    <select class="form-select mb-3" name="origin">
                        <?php foreach ($origins as $type => $label): ?>
                            <option value="<?php echo e($type); ?>"><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="mb-3">
                        <label for="file" class="form-label">Arquivo de texto</label>
                        <input type="file" class="form-control" id="file" name="file"
                               accept=".txt,.md,text/plain,text/markdown">
                        <div class="form-text">Máximo <?php echo (int) ((int) config('knowledge.upload.max_size') / 1024 / 1024); ?> MB. O título será derivado do nome do arquivo.</div>
                    </div>
                    <button type="submit" class="btn btn-outline-primary w-100">Importar arquivo</button>
                </form>
            </div>
        </div>
    </div>
</div>