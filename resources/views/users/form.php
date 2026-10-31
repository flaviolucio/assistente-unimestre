<?php
$isEdit = $user !== null;
$error = static fn (string $field): string => isset($errors[$field]) ? e($errors[$field]) : '';
$oldValue = static function (string $field) use ($old, $user): string {
    $value = $old[$field] ?? ($user[$field] ?? '');

    return e((string) $value);
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?php echo $isEdit ? 'Editar usuário' : 'Novo usuário'; ?></h1>
    <a href="<?php echo app_url('usuarios'); ?>" class="btn btn-outline-secondary btn-sm">&larr; Voltar</a>
</div>

<div class="row">
    <div class="col-lg-8">
        <form method="post" action="<?php echo $isEdit ? app_url('usuarios/' . (int) $user['id'] . '/editar') : app_url('usuarios'); ?>" autocomplete="off">
            <?php echo csrf_field(); ?>
            <div class="card app-card mb-4">
                <div class="card-header bg-transparent fw-semibold">Dados</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Nome completo</label>
                        <input type="text" class="form-control" id="name" name="name"
                               value="<?php echo $oldValue('name'); ?>" required>
                        <?php if ($error('name') !== ''): ?><div class="text-danger small"><?php echo $error('name'); ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">E-mail</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?php echo $oldValue('email'); ?>" required>
                        <?php if ($error('email') !== ''): ?><div class="text-danger small"><?php echo $error('email'); ?></div><?php endif; ?>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="role" class="form-label">Perfil</label>
                            <select class="form-select" id="role" name="role">
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo e($role); ?>" <?php echo $oldValue('role') === $role ? 'selected' : ''; ?>><?php echo e($role); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($error('role') !== ''): ?><div class="text-danger small"><?php echo $error('role'); ?></div><?php endif; ?>
                            <div class="form-text">Perfis: admin (gerencial), tecnico, usuario.</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="status" class="form-label">Situação</label>
                            <select class="form-select" id="status" name="status">
                                <option value="active" <?php echo $oldValue('status') === 'active' ? 'selected' : ''; ?>>Ativo</option>
                                <option value="inactive" <?php echo $oldValue('status') === 'inactive' ? 'selected' : ''; ?>>Inativo</option>
                            </select>
                            <?php if ($error('status') !== ''): ?><div class="text-danger small"><?php echo $error('status'); ?></div><?php endif; ?>
                        </div>
                    </div>
                    <?php if (!$isEdit): ?>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label">Senha inicial</label>
                                <input type="password" class="form-control" id="password" name="password"
                                       minlength="<?php echo (int) config('auth.password_min_length', 8); ?>" required>
                                <?php if ($error('password') !== ''): ?><div class="text-danger small"><?php echo $error('password'); ?></div><?php endif; ?>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="password_confirmation" class="form-label">Confirmar senha</label>
                                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                                       required>
                                <?php if ($error('password_confirmation') !== ''): ?><div class="text-danger small"><?php echo $error('password_confirmation'); ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="form-text mb-2">O usuário criado precisará trocar a senha no primeiro acesso.</div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent">
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </div>
        </form>
    </div>

    <?php if ($isEdit): ?>
        <div class="col-lg-4">
            <div class="card app-card" id="senha">
                <div class="card-header bg-transparent fw-semibold">Redefinir senha</div>
                <div class="card-body">
                    <form method="post" action="<?php echo app_url('usuarios/' . (int) $user['id'] . '/resetar-senha'); ?>" autocomplete="off">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label for="r_password" class="form-label">Nova senha</label>
                            <input type="password" class="form-control" id="r_password" name="password"
                                   minlength="<?php echo (int) config('auth.password_min_length', 8); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="r_password_confirmation" class="form-label">Confirmar nova senha</label>
                            <input type="password" class="form-control" id="r_password_confirmation" name="password_confirmation" required>
                        </div>
                        <button type="submit" class="btn btn-outline-primary w-100">Redefinir senha</button>
                        <div class="form-text mt-2">O usuário precisará trocá-la no próximo login.</div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>