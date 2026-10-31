<div class="row">
    <div class="col-12 mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1 class="h4 mb-1">Bem-vindo(a), <?php echo e($user['name']); ?></h1>
                <p class="text-muted small mb-0">
                    Perfil <strong class="text-uppercase"><?php echo e($user['role']); ?></strong>
                    <?php if (!empty($user['last_login_at'])): ?>
                        &middot; Último acesso: <?php echo e(date('d/m/Y H:i', strtotime($user['last_login_at']))); ?>
                    <?php endif; ?>
                </p>
            </div>
            <a class="btn btn-outline-primary btn-sm" href="./alterar-senha">Alterar senha</a>
        </div>
    </div>

    <?php if ((int) $user['must_change_password'] === 1): ?>
        <div class="col-12 mb-4">
            <div class="alert alert-warning app-flash mb-0">
                <strong>Atenção:</strong> você deve alterar sua senha antes de continuar.
                <a href="./alterar-senha" class="alert-link ms-1">Alterar agora</a>.
            </div>
        </div>
    <?php endif; ?>

    <div class="col-md-6 col-lg-3 mb-3">
        <div class="card app-card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Total de usuários</div>
                <div class="fs-3 fw-semibold"><?php echo (int) $totalUsers; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3 mb-3">
        <div class="card app-card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Ativos</div>
                <div class="fs-3 fw-semibold"><?php echo (int) $activeUsers; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3 mb-3">
        <div class="card app-card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Administradores</div>
                <div class="fs-3 fw-semibold"><?php echo (int) $adminCount; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3 mb-3">
        <div class="card app-card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Técnicos</div>
                <div class="fs-3 fw-semibold"><?php echo (int) $tecnicoCount; ?></div>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-lg-3 mb-3">
        <div class="card app-card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Documentos da base</div>
                <div class="fs-3 fw-semibold"><?php echo (int) $knowledgeDocuments['total']; ?></div>
                <div class="small text-muted">
                    <?php echo (int) $knowledgeDocuments['active']; ?> ativos &middot;
                    <?php echo (int) $knowledgeDocuments['official']; ?> oficiais &middot;
                    <?php echo (int) $knowledgeDocuments['internal']; ?> internos
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3 mb-3">
        <div class="card app-card h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">Soluções internas ativas</div>
                <div class="fs-3 fw-semibold"><?php echo (int) $internalSolutions; ?></div>
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-4">
        <div class="card app-card h-100">
            <div class="card-header bg-transparent fw-semibold">Suas permissões</div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($permissions as $permission): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?php echo e($permission); ?></span>
                        <span class="badge text-bg-success">permitido</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="col-lg-6 mb-4">
        <div class="card app-card h-100">
            <div class="card-header bg-transparent fw-semibold">Próximos passos</div>
            <ul class="list-group list-group-flush small">
                <?php if ($user['role'] === 'admin'): ?>
                    <li class="list-group-item">
                        <a href="./usuarios" class="app-link">Gerenciar usuários</a> &mdash; criar, editar, redefinir senhas e desativar contas.
                    </li>
                    <li class="list-group-item">
                        <a href="./admin/knowledge/import" class="app-link">Importar documentos</a> &mdash; alimentar a base de conhecimento (manual, .txt/.md).
                    </li>
                <?php endif; ?>
                <li class="list-group-item">
                    <a href="./knowledge" class="app-link">Consultar a base de conhecimento</a> &mdash; documentação oficial e procedimentos internos.
                </li>
                <li class="list-group-item">
                    <a href="./solutions" class="app-link">Soluções internas</a> &mdash; guia de resolução rápido para os atendentes.
                </li>
                <li class="list-group-item text-muted">
                    Provedores de IA, buscas avançadas e chat chegam nas Fases 4 a 7.
                </li>
            </ul>
        </div>
    </div>
</div>