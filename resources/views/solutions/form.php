<?php
$isEdit = isset($solution) && $solution !== null;
$error = static fn (string $field): string => isset($errors[$field]) ? e($errors[$field]) : '';
$oldValue = static fn (string $field, string $default = ''): string => e((string) ($old[$field] ?? $solution[$field] ?? $default));
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?php echo $isEdit ? 'Editar solução interna' : 'Nova solução interna'; ?></h1>
    <a href="<?php echo app_url('solutions'); ?>" class="btn btn-outline-secondary btn-sm">&larr; Voltar</a>
</div>

<div class="row">
    <div class="col-lg-8">
        <form method="post"
              action="<?php echo $isEdit ? app_url('solutions/' . (int) $solution['id'] . '/editar') : app_url('solutions'); ?>"
              autocomplete="off">
            <?php echo csrf_field(); ?>
            <div class="card app-card">
                <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
                    <span>Dados da solução</span>
                    <span class="badge app-badge-internal">PROCEDIMENTO INTERNO</span>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">Título</label>
                        <input type="text" class="form-control" id="title" name="title"
                               value="<?php echo $oldValue('title'); ?>" required>
                        <?php if ($error('title') !== ''): ?><div class="text-danger small"><?php echo $error('title'); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="problem" class="form-label">Problema / Sintoma</label>
                        <textarea class="form-control" id="problem" name="problem" rows="4" required><?php echo $oldValue('problem'); ?></textarea>
                        <?php if ($error('problem') !== ''): ?><div class="text-danger small"><?php echo $error('problem'); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="solution" class="form-label">Solução / Passos</label>
                        <textarea class="form-control" id="solution" name="solution" rows="8" required><?php echo $oldValue('solution'); ?></textarea>
                        <?php if ($error('solution') !== ''): ?><div class="text-danger small"><?php echo $error('solution'); ?></div><?php endif; ?>
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
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="active" <?php echo $oldValue('status', 'active') === 'active' ? 'selected' : ''; ?>>Ativo</option>
                                <option value="inactive" <?php echo $oldValue('status', 'active') === 'inactive' ? 'selected' : ''; ?>>Inativo</option>
                            </select>
                            <?php if ($error('status') !== ''): ?><div class="text-danger small"><?php echo $error('status'); ?></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="tags" class="form-label">Tags</label>
                        <input type="text" class="form-control" id="tags" name="tags"
                               value="<?php echo $oldValue('tags'); ?>" placeholder="senha, esqueci, reset">
                        <div class="form-text">Separe por vírgula ou ponto e vírgula. Normalizadas automaticamente.</div>
                    </div>
                </div>
                <div class="card-footer bg-transparent">
                    <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Salvar alterações' : 'Cadastrar solução'; ?></button>
                </div>
            </div>
        </form>
    </div>
</div>