<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
        <div class="card app-card">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <div class="app-hero-icon mx-auto mb-3">?</div>
                    <h1 class="h5 fw-semibold mb-1">Acessar</h1>
                    <p class="text-muted small mb-0">Entre com suas credenciais</p>
                </div>
                <form method="post" action="<?php echo app_url('login'); ?>" autocomplete="off">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label for="email" class="form-label">E-mail</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?php echo e((string) ($old['email'] ?? '')); ?>" required autofocus>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Senha</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Entrar</button>
                </form>
                <p class="text-muted small text-center mt-3 mb-0">A primeira senha fornecida pelo administrador precisa ser alterada no primeiro acesso.</p>
            </div>
        </div>
    </div>
</div>