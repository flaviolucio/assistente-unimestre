<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
        <div class="card app-card">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <div class="app-hero-icon mx-auto mb-3">?</div>
                    <h1 class="h5 fw-semibold mb-1">Alterar senha</h1>
                    <?php if ((int) $user['must_change_password'] === 1): ?>
                        <p class="text-warning small mb-0">Neste primeiro acesso, a troca de senha é obrigatória.</p>
                    <?php else: ?>
                        <p class="text-muted small mb-0">Escolha uma nova senha para sua conta.</p>
                    <?php endif; ?>
                </div>
                <form method="post" action="<?php echo app_url('alterar-senha'); ?>" autocomplete="off">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Senha atual</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label for="new_password" class="form-label">Nova senha</label>
                        <input type="password" class="form-control" id="new_password" name="new_password"
                               minlength="<?php echo (int) config('auth.password_min_length', 8); ?>" required>
                        <div class="form-text">Mínimo de <?php echo (int) config('auth.password_min_length', 8); ?> caracteres, contendo letra e número.</div>
                    </div>
                    <div class="mb-4">
                        <label for="new_password_confirmation" class="form-label">Confirmar nova senha</label>
                        <input type="password" class="form-control" id="new_password_confirmation" name="new_password_confirmation" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Salvar nova senha</button>
                </form>
            </div>
        </div>
    </div>
</div>