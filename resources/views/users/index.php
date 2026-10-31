<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h4 mb-0">Usuários <span class="badge text-bg-secondary align-middle"><?php echo count($users); ?></span></h1>
    <a href="<?php echo app_url('usuarios/novo'); ?>" class="btn btn-primary btn-sm">Novo usuário</a>
</div>

<div class="card app-card mb-3">
    <div class="card-body py-3">
        <form method="get" action="<?php echo app_url('usuarios'); ?>" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label small mb-1">Buscar</label>
                <input type="text" class="form-control form-control-sm" id="search" name="search"
                       value="<?php echo e($filters['search'] ?? ''); ?>" placeholder="Nome ou e-mail">
            </div>
            <div class="col-md-3">
                <label for="role" class="form-label small mb-1">Perfil</label>
                <select class="form-select form-select-sm" id="role" name="role">
                    <option value="">Todos</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo e($role); ?>" <?php echo ($filters['role'] ?? '') === $role ? 'selected' : ''; ?>><?php echo e($role); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label small mb-1">Situação</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Todas</option>
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
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th class="text-center">Perfil</th>
                    <th class="text-center">Situação</th>
                    <th class="text-end">Último acesso</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users === []): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">Nenhum usuário encontrado.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($users as $row): ?>
                    <tr>
                        <td><?php echo e($row['name']); ?></td>
                        <td class="small text-muted"><?php echo e($row['email']); ?></td>
                        <td class="text-center">
                            <span class="badge app-role-badge"><?php echo e($row['role']); ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($row['status'] === 'active'): ?>
                                <span class="badge text-bg-success">Ativo</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end small text-muted">
                            <?php echo !empty($row['last_login_at']) ? e(date('d/m/Y H:i', strtotime($row['last_login_at']))) : '&mdash;'; ?>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="<?php echo app_url('usuarios/' . (int) $row['id'] . '/editar'); ?>" class="btn btn-outline-secondary">Editar</a>
                                <a href="<?php echo app_url('usuarios/' . (int) $row['id'] . '/editar#senha'); ?>" class="btn btn-outline-secondary">Senha</a>
                                <form method="post" action="<?php echo app_url('usuarios/' . (int) $row['id'] . '/status'); ?>" class="d-inline" onsubmit="return confirm('Alterar a situação deste usuário?');">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" class="btn btn-outline-secondary" title="Ativar/Inativar">
                                        <?php echo $row['status'] === 'active' ? 'Inativar' : 'Ativar'; ?>
                                    </button>
                                </form>
                                <form method="post" action="<?php echo app_url('usuarios/' . (int) $row['id'] . '/excluir'); ?>" class="d-inline" onsubmit="return confirm('Excluir definitivamente este usuário?');">
                                    <?php echo csrf_field(); ?>
                                    <button type="submit" class="btn btn-outline-danger">Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>