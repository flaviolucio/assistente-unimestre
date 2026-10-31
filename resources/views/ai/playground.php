<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h4 mb-0">Testar IA — Playground</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?php echo app_url('admin/ai'); ?>">Configuração</a>
</div>

<p class="text-muted small mb-4">
    Envia uma pergunta ao modelo: (A) somente ao provider selecionado ou (B) pela estratégia automática com fallback
    (padrão primeiro, depois a ordem de prioridade), apenas neste teste.
    O system prompt da base é aplicado automaticamente. A resposta é exibida como texto puro
    (sem formatação HTML) e o conteúdo nunca é armazenado em conversas nesta fase.
</p>

<?php if (isset($errors['question'])): ?>
    <div class="alert alert-danger app-flash" role="alert"><?php echo e($errors['question']); ?></div>
<?php endif; ?>

<?php if (isset($errors['provider'])): ?>
    <div class="alert alert-warning app-flash" role="alert"><?php echo e($errors['provider']); ?></div>
<?php endif; ?>

<?php if ($providers === []): ?>
    <div class="alert alert-info app-flash" role="alert">
        Nenhum provider ativo. Ative o OpenRouter e configure a API Key em
        <a class="app-link" href="<?php echo app_url('admin/ai'); ?>">Integração com IA</a>.
    </div>
<?php else: ?>
    <div class="card app-card mb-4">
        <div class="card-body">
            <form method="post" action="<?php echo app_url('admin/ai/playground'); ?>">
                <?php echo csrf_field(); ?>
                <label for="question" class="form-label small mb-1">Pergunta</label>
                <textarea class="form-control" id="question" name="question" rows="4"
                          placeholder="Ex.: Como alterar a situação de matrícula de um aluno?"><?php echo e($question ?? ''); ?></textarea>
                <fieldset class="mt-3">
                    <legend class="form-label small mb-1">Modo de teste</legend>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="mode" id="mode-single" value="single" <?php echo ($mode ?? 'single') === 'single' ? 'checked' : ''; ?>>
                        <label class="form-check-label small" for="mode-single">A) Somente o provider selecionado</label>
                    </div>
                    <div class="ms-4 mb-2" style="max-width: 22rem">
                        <label class="visually-hidden" for="provider_id">Provider</label>
                        <select class="form-select form-select-sm" id="provider_id" name="provider_id">
                            <?php foreach ($providers as $p): ?>
                                <option value="<?php echo (int) $p['id']; ?>" <?php echo (int) $p['id'] === (int) ($selectedProviderId ?? 0) ? 'selected' : ''; ?>><?php echo e($p['name']); ?><?php echo (int) $p['is_default'] === 1 ? ' (padrão)' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="mode" id="mode-auto" value="auto" <?php echo ($mode ?? 'single') === 'auto' ? 'checked' : ''; ?>>
                        <label class="form-check-label small" for="mode-auto">B) Estratégia automática com fallback (padrão → ordem de prioridade)</label>
                    </div>
                </fieldset>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary btn-sm">Enviar ao modelo</button>
                </div>
            </form>
            <p class="form-text mb-0 mt-2">
                <?php $defaultRow = array_values(array_filter($providers, static fn (array $p): bool => (int) $p['is_default'] === 1))[0] ?? null; ?>
                Provider padrão (1ª tentativa):
                <strong><?php echo e($defaultRow['name'] ?? 'nenhum'); ?></strong>
                (<?php echo !empty($defaultRow['model']) ? e($defaultRow['model']) : 'modelo não configurado'; ?>).
                <?php if (!empty($systemPrompt)): ?>
                    System prompt aplicado (<?php echo mb_strlen((string) $systemPrompt); ?> caracteres).
                <?php endif; ?>
            </p>
        </div>
    </div>
<?php endif; ?>

<?php if ($result !== null): ?>
    <div class="card app-card mb-4">
        <div class="card-header bg-transparent py-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <strong>Resposta</strong>
                <span class="small text-muted">
                    <?php if (!empty($result['model'])): ?>
                        <span class="badge text-bg-secondary"><?php echo e($result['model']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($result['latency_ms'])): ?>
                        <span class="ms-2"><?php echo e((string) round((float) $result['latency_ms'], 2)); ?> ms</span>
                    <?php endif; ?>
                    <?php if (!empty($result['total_tokens'])): ?>
                        <span class="ms-2"><?php echo (int) $result['total_tokens']; ?> tokens</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <div class="card-body">
            <?php if (($result['mode'] ?? '') === 'auto'): ?>
                <div class="small mb-3">
                    <div><strong>Provider utilizado:</strong> <?php echo e($result['final_provider'] !== null ? ($labels[$result['final_provider']] ?? $result['final_provider']) : 'nenhum'); ?></div>
                    <div><strong>Tentativas:</strong>
                        <?php if (empty($result['attempts'])): ?>nenhuma (sem provider elegível)<?php endif; ?>
                        <?php foreach ((array) ($result['attempts'] ?? []) as $i => $a): ?>
                            <?php echo $i > 0 ? ' → ' : ''; ?><?php echo e($labels[$a['provider']] ?? $a['provider']); ?>
                            <span class="badge <?php echo $a['result'] === 'success' ? 'text-bg-success' : 'text-bg-danger'; ?>"><?php echo $a['result'] === 'success' ? 'sucesso' : 'falhou: ' . e($a['result']); ?><?php echo !empty($a['http_status']) ? ' (HTTP ' . (int) $a['http_status'] . ')' : ''; ?></span>
                            <span class="text-muted"><?php echo (int) $a['duration_ms']; ?> ms</span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!empty($result['success'])): ?>
                <div class="app-prose bg-light border rounded p-3"><?php echo e($result['content'] ?? ''); ?></div>
                <?php if (!empty($result['request_id'])): ?>
                    <p class="form-text mb-0 mt-2 small">Request: <?php echo e($result['request_id']); ?></p>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-danger app-flash mb-0" role="alert">
                    <?php echo e($result['message'] ?? 'Falha na chamada ao provedor de IA.'); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>