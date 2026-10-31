<div class="row justify-content-center">
    <div class="col-lg-6 text-center py-5">
        <div class="display-4 text-danger mb-2">!</div>
        <h1 class="h4">Não foi possível processar a requisição neste momento.</h1>
        <?php if (!empty($debugMessage) && (bool) config('app.debug')): ?>
            <p class="text-muted small mt-3"><?php echo htmlspecialchars($debugMessage, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <a class="btn btn-primary mt-3" href="./">Voltar ao início</a>
    </div>
</div>