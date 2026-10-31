<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h4 mb-0">Soluções Internas
        <span class="badge text-bg-secondary align-middle"><?php echo (int) $pagination['total']; ?></span>
    </h1>
    <?php if ($canManage): ?>
        <a href="<?php echo app_url('solutions/novo'); ?>" class="btn btn-primary btn-sm">Nova solução</a>
    <?php endif; ?>
</div>

<div class="card app-card mb-3">
    <div class="card-body py-3">
        <form method="get" action="<?php echo app_url('solutions'); ?>" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label for="search" class="form-label small mb-1">Pesquisar</label>
                <input type="text" class="form-control form-control-sm" id="search" name="search"
                       value="<?php echo e($filters['search'] ?? ''); ?>" placeholder="Título, problema, solução, tags...">
            </div>
            <div class="col-md-3">
                <label for="category" class="form-label small mb-1">Categoria</label>
                <select class="form-select form-select-sm" id="category" name="category">
                    <option value="">Todas</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo e($category); ?>" <?php echo ($filters['category'] ?? '') === $category ? 'selected' : ''; ?>><?php echo e($category); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label small mb-1">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Todos</option>
                    <option value="active" <?php echo ($filters['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Ativo</option>
                    <option value="inactive" <?php echo ($filters['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inativo</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">Filtrar</button>
            </div>
        </form>
    </div>
</div>

<div class="card app-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Título</th>
                    <th>Categoria</th>
                    <th>Tags</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Atualizado em</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($solutions === []): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">Nenhuma solução interna encontrada.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($solutions as $solution): ?>
                    <tr>
                        <td class="text-truncate" style="max-width: 320px;">
                            <span class="badge app-badge-internal me-1">PROCEDIMENTO INTERNO</span>
                            <?php echo e($solution['title']); ?>
                        </td>
                        <td><?php echo e($solution['category'] ?? '&mdash;'); ?></td>
                        <td class="small text-muted text-truncate" style="max-width: 220px;"><?php echo e((string) $solution['tags']); ?></td>
                        <td class="text-center">
                            <?php if ($solution['status'] === 'active'): ?>
                                <span class="badge text-bg-success">ATIVO</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">INATIVO</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end small text-muted"><?php echo e(date('d/m/Y H:i', strtotime($solution['updated_at']))); ?></td>
                        <td class="text-end">
                            <?php if ($canManage): ?>
                                <div class="btn-group btn-group-sm">
                                    <a href="<?php echo app_url('solutions/' . (int) $solution['id'] . '/editar'); ?>" class="btn btn-outline-secondary">Editar</a>
                                    <form method="post" action="<?php echo app_url('solutions/' . (int) $solution['id'] . '/status'); ?>"
                                          class="d-inline" onsubmit="return confirm('Alterar o status desta solução?');">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" class="btn btn-outline-secondary">
                                            <?php echo $solution['status'] === 'active' ? 'Inativar' : 'Ativar'; ?>
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pagination['pages'] > 1): ?>
    <nav class="mt-3" aria-label="Paginação">
        <ul class="pagination pagination-sm justify-content-center">
            <?php for ($i = 1; $i <= $pagination['pages']; $i++): ?>
                <?php
                $query = array_merge($filters, ['page' => $i]);
                ?>
                <li class="page-item <?php echo $i === $pagination['page'] ? 'active' : ''; ?>">
                    <a class="page-link" href="<?php echo app_url('solutions') . '?' . e(http_build_query($query)); ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>