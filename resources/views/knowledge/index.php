<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h4 mb-0">Base de Conhecimento
        <span class="badge text-bg-secondary align-middle"><?php echo (int) $pagination['total']; ?></span>
    </h1>
    <div class="d-flex gap-2">
        <?php if (\App\Services\Security\Auth::instance()->can('knowledge.bulk_import')): ?>
            <a href="<?php echo app_url('admin/knowledge/importacao-oficial'); ?>" class="btn btn-outline-primary btn-sm">Importação oficial em massa</a>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <a href="<?php echo app_url('admin/knowledge/import'); ?>" class="btn btn-primary btn-sm">Importar documento</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-4 col-xl-2 mb-2">
        <div class="card app-card app-stat h-100">
            <div class="card-body py-2">
                <div class="text-muted small text-uppercase">Documentos</div>
                <div class="fs-5 fw-semibold"><?php echo (int) $stats['total']; ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2 mb-2">
        <div class="card app-card app-stat h-100">
            <div class="card-body py-2">
                <div class="text-muted small text-uppercase">Oficiais</div>
                <div class="fs-5 fw-semibold"><?php echo (int) $stats['official']; ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2 mb-2">
        <div class="card app-card app-stat h-100">
            <div class="card-body py-2">
                <div class="text-muted small text-uppercase">Internos</div>
                <div class="fs-5 fw-semibold"><?php echo (int) $stats['internal']; ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2 mb-2">
        <div class="card app-card app-stat h-100">
            <div class="card-body py-2">
                <div class="text-muted small text-uppercase">Ativos</div>
                <div class="fs-5 fw-semibold text-success"><?php echo (int) $stats['active']; ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2 mb-2">
        <div class="card app-card app-stat h-100">
            <div class="card-body py-2">
                <div class="text-muted small text-uppercase">Inativos</div>
                <div class="fs-5 fw-semibold text-muted"><?php echo (int) $stats['inactive']; ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card app-card mb-3">
    <div class="card-body py-3">
        <form method="get" action="<?php echo app_url('knowledge'); ?>" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label small mb-1">Pesquisar</label>
                <input type="text" class="form-control form-control-sm" id="search" name="search"
                       value="<?php echo e($filters['search'] ?? ''); ?>" placeholder="Título, categoria, conteúdo...">
            </div>
            <div class="col-md-2">
                <label for="category" class="form-label small mb-1">Categoria</label>
                <select class="form-select form-select-sm" id="category" name="category">
                    <option value="">Todas</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo e($category); ?>" <?php echo ($filters['category'] ?? '') === $category ? 'selected' : ''; ?>><?php echo e($category); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="subcategory" class="form-label small mb-1">Subcategoria</label>
                <input type="text" class="form-control form-control-sm" id="subcategory" name="subcategory"
                       value="<?php echo e($filters['subcategory'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label for="origin" class="form-label small mb-1">Origem</label>
                <select class="form-select form-select-sm" id="origin" name="origin">
                    <option value="">Todas</option>
                    <option value="official" <?php echo ($filters['origin'] ?? '') === 'official' ? 'selected' : ''; ?>>OFICIAL UNIMESTRE</option>
                    <option value="internal" <?php echo ($filters['origin'] ?? '') === 'internal' ? 'selected' : ''; ?>>PROCEDIMENTO INTERNO</option>
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
                    <th>Origem</th>
                    <th>Categoria</th>
                    <th>Subcategoria</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Última atualização</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($documents === []): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Nenhum documento encontrado.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($documents as $document): ?>
                    <tr>
                        <td class="text-truncate" style="max-width: 320px;">
                            <a href="<?php echo app_url('knowledge/' . (int) $document['id']); ?>" class="app-link"><?php echo e($document['title']); ?></a>
                        </td>
                        <td>
                            <?php if ($document['source_type'] === 'official'): ?>
                                <span class="badge app-badge-official">OFICIAL UNIMESTRE</span>
                            <?php else: ?>
                                <span class="badge app-badge-internal">PROCEDIMENTO INTERNO</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($document['category'] ?? '—'); ?></td>
                        <td class="small text-muted"><?php echo e($document['subcategory'] ?? '—'); ?></td>
                        <td class="text-center">
                            <?php if ($document['status'] === 'active'): ?>
                                <span class="badge text-bg-success">ATIVO</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">INATIVO</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end small text-muted">
                            <?php echo e(date('d/m/Y H:i', strtotime($document['updated_at']))); ?>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="<?php echo app_url('knowledge/' . (int) $document['id']); ?>" class="btn btn-outline-secondary">Ver</a>
                                <?php if ($canManage): ?>
                                    <a href="<?php echo app_url('admin/knowledge/' . (int) $document['id'] . '/edit'); ?>" class="btn btn-outline-secondary">Editar</a>
                                    <form method="post" action="<?php echo app_url('admin/knowledge/' . (int) $document['id'] . '/status'); ?>"
                                          class="d-inline" onsubmit="return confirm('Alterar o status deste documento?');">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" class="btn btn-outline-secondary" title="Ativar/Inativar">
                                            <?php echo $document['status'] === 'active' ? 'Inativar' : 'Ativar'; ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
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
                $url = './knowledge?' . http_build_query($query);
                ?>
                <li class="page-item <?php echo $i === $pagination['page'] ? 'active' : ''; ?>">
                    <a class="page-link" href="<?php echo e($url); ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>