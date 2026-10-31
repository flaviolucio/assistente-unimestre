<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h4 mb-0">Integração com IA</h1>
    <div class="d-flex gap-2">
        <?php if (!empty($encryptionConfigured)): ?>
            <span class="badge text-bg-success">Criptografia AES-256-GCM ativa</span>
        <?php else: ?>
            <span class="badge text-bg-danger">APP_ENCRYPTION_KEY não configurada</span>
        <?php endif; ?>
        <?php if (!empty($canTest)): ?>
            <a class="btn btn-primary btn-sm" href="<?php echo app_url('admin/ai/playground'); ?>">Playground</a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($encryptionConfigured) && !empty($canManage)): ?>
    <div class="alert alert-warning app-flash" role="alert">
        A variável <code>APP_ENCRYPTION_KEY</code> não está definida no <code>.env</code>.
        Sem ela, a API Key não pode ser criptografada nem lida de forma segura.
        Gere com <code>php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"</code> e reinicie o Apache.
    </div>
<?php endif; ?>

<p class="text-muted small mb-4">
    Provedores de LLM disponíveis: <strong>OpenRouter</strong>, <strong>Google Gemini</strong>, <strong>Groq</strong> e <strong>NVIDIA</strong>.
    O assistente (chat/RAG) usa o <strong>provider padrão ativo</strong> como primeira tentativa. Com o fallback automático ativado,
    falhas temporárias passam a pergunta ao próximo provider da ordem de prioridade (ver seção abaixo).
    A API Key é criptografada com AES-256-GCM (formato <code>v1:...</code>) e nunca é exibida novamente após o salvamento.
</p>

<?php require __DIR__ . '/_fallback.php'; ?>

<?php if ($providers === []): ?>
    <div class="alert alert-info app-flash" role="alert">Nenhum provider cadastrado.</div>
<?php else: ?>
    <?php foreach ($providers as $provider): ?>
        <?php
        $pid = (int) $provider['id'];
        $isSupported = !empty($provider['supported']);
        $isActive = $provider['status'] === 'active';
        $isDefault = (int) $provider['is_default'] === 1;
        $testResult = $testResult[$pid] ?? null;
        ?>
        <div class="card app-card mb-4">
            <div class="card-header bg-transparent py-2">
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <div>
                        <strong><?php echo e($provider['label'] ?? $provider['provider']); ?></strong>
                        <span class="badge text-bg-secondary ms-1"><?php echo e($provider['provider']); ?></span>
                        <?php if (($provider['name'] ?? '') !== '' && $provider['name'] !== ($provider['label'] ?? '')): ?>
                            <span class="small text-muted ms-1">(<?php echo e($provider['name']); ?>)</span>
                        <?php endif; ?>
                        <?php if ($isDefault): ?>
                            <span class="badge app-badge-official">PROVIDER PADRÃO</span>
                        <?php endif; ?>
                        <?php if ($provider['has_key']): ?>
                            <span class="badge text-bg-success">Chave configurada</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">Sem chave</span>
                        <?php endif; ?>
                    </div>
                    <span class="badge <?php echo $isActive ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                        <?php echo $isActive ? 'ATIVO' : 'INATIVO'; ?>
                    </span>
                </div>
            </div>
            <div class="card-body">
                <?php if (!$isSupported): ?>
                    <div class="alert alert-secondary app-flash mb-3" role="alert">
                        O provider <strong><?php echo e($provider['provider']); ?></strong> não é suportado por esta versão.
                    </div>
                <?php endif; ?>

                <?php if ($testResult !== null): ?>
                    <div class="alert <?php echo !empty($testResult['success']) ? 'alert-success' : 'alert-danger'; ?> app-flash">
                        <strong>Resultado do teste:</strong> <?php echo e($testResult['message'] ?? '—'); ?>
                        <?php if (!empty($testResult['model'])): ?>
                            <br><span class="small">Modelo: <?php echo e($testResult['model']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($testResult['latency_ms'])): ?>
                            <span class="small ms-2">Duração: <?php echo e((string) round((float) $testResult['latency_ms'], 2)); ?> ms</span>
                        <?php endif; ?>
                        <?php if (!empty($testResult['total_tokens'])): ?>
                            <span class="small ms-2">Tokens: <?php echo (int) $testResult['total_tokens']; ?></span>
                        <?php endif; ?>
                        <?php if (!empty($testResult['finish_reason'])): ?>
                            <span class="small ms-2">finish_reason: <?php echo e($testResult['finish_reason']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($testResult['request_id'])): ?>
                            <br><span class="small">Request: <?php echo e($testResult['request_id']); ?></span>
                        <?php endif; ?>
                        <?php foreach ((array) ($testResult['warnings'] ?? []) as $warning): ?>
                            <br><span class="small text-warning-emphasis">⚠ <?php echo e((string) $warning); ?></span>
                        <?php endforeach; ?>
                        <?php if (!empty($testResult['detail']) && is_array($testResult['detail'])): ?>
                            <?php
                            $detailLines = [];

                            if (isset($testResult['detail']['summary']) && is_array($testResult['detail']['summary'])) {
                                $detailLines = $testResult['detail']['summary'];
                            }

                            if (!empty($testResult['detail']['provider_message'])) {
                                $detailLines['provider_message'] = $testResult['detail']['provider_message'];
                            }

                            $detailLines = array_filter($detailLines, static fn ($value) => $value !== null && $value !== '');
                            ?>
                            <?php if ($detailLines !== []): ?>
                                <br><span class="small text-muted">Detalhes (sanitizados): <?php foreach ($detailLines as $k => $v): ?><?php echo e($k) . ': ' . e((string) $v); ?><?php if (array_key_last($detailLines) !== $k): ?><?php echo '; '; ?><?php endif; ?><?php endforeach; ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo app_url('admin/ai/' . $pid . '/salvar'); ?>" class="row g-3">
                    <?php echo csrf_field(); ?>
                    <div class="col-md-4">
                        <label for="name_<?php echo $pid; ?>" class="form-label small mb-1">Nome</label>
                        <input type="text" class="form-control form-control-sm" id="name_<?php echo $pid; ?>"
                               name="name" value="<?php echo e($provider['name']); ?>" maxlength="150">
                    </div>
                    <div class="col-md-8">
                        <label for="model_<?php echo $pid; ?>" class="form-label small mb-1">Modelo</label>
                        <input type="text" class="form-control form-control-sm" id="model_<?php echo $pid; ?>"
                               name="model" value="<?php echo e($provider['model'] ?? ''); ?>"
                               placeholder="<?php echo e($provider['model_hint'] ?? ''); ?>" maxlength="150">
                        <div class="form-text">Use o identificador exato do modelo na documentação do provedor (<?php echo e($provider['model_hint'] ?? ''); ?>).</div>
                    </div>
                    <div class="col-md-3">
                        <label for="temperature_<?php echo $pid; ?>" class="form-label small mb-1">Temperatura</label>
                        <input type="number" step="0.01" min="0" max="2" class="form-control form-control-sm"
                               id="temperature_<?php echo $pid; ?>" name="temperature"
                               value="<?php echo e($provider['temperature']); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="max_tokens_<?php echo $pid; ?>" class="form-label small mb-1">Máx. tokens</label>
                        <input type="number" min="1" max="100000" class="form-control form-control-sm"
                               id="max_tokens_<?php echo $pid; ?>" name="max_tokens"
                               value="<?php echo e($provider['max_tokens'] ?? ''); ?>"
                               placeholder="1500">
                    </div>
                    <div class="col-md-3">
                        <label for="timeout_<?php echo $pid; ?>" class="form-label small mb-1">Timeout (s)</label>
                        <input type="number" min="5" max="120" class="form-control form-control-sm"
                               id="timeout_<?php echo $pid; ?>" name="timeout_seconds"
                               value="<?php echo e($provider['timeout_seconds']); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="api_key_<?php echo $pid; ?>" class="form-label small mb-1">API Key</label>
                        <input type="password" class="form-control form-control-sm" id="api_key_<?php echo $pid; ?>"
                               name="api_key" autocomplete="off"
                               placeholder="<?php echo $provider['has_key'] ? '•••••••••• (preencha apenas para substituir)' : 'Cole a API Key do provedor'; ?>">
                        <?php if ($provider['has_key']): ?>
                            <div class="form-text">Chave já configurada e criptografada. Deixe vazio para mantê-la.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 d-flex flex-wrap align-items-center gap-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="status_<?php echo $pid; ?>" name="status" value="active" <?php echo $isActive ? 'checked' : ''; ?>>
                            <label class="form-check-label small" for="status_<?php echo $pid; ?>">Ativo</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="default_<?php echo $pid; ?>" name="is_default" value="1" <?php echo $isDefault ? 'checked' : ''; ?>>
                            <label class="form-check-label small" for="default_<?php echo $pid; ?>">Provider padrão</label>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Salvar configuração</button>
                        <?php if (!empty($canTest) && $isSupported): ?>
                            <button type="submit" form="test_<?php echo $pid; ?>" class="btn btn-outline-primary btn-sm">
                                Testar conexão
                            </button>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($isSupported): ?>
                    <form id="test_<?php echo $pid; ?>" method="post" action="<?php echo app_url('admin/ai/' . $pid . '/test'); ?>" class="d-none">
                        <?php echo csrf_field(); ?>
                    </form>
                <?php endif; ?>

                <?php if (!empty($canManage)): ?>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <form method="post" action="<?php echo app_url('admin/ai/' . $pid . '/status'); ?>"
                              onsubmit="return confirm('<?php echo $isActive ? 'Desativar' : 'Ativar'; ?> este provider?');">
                            <?php echo csrf_field(); ?>
                            <button type="submit" class="btn btn-sm <?php echo $isActive ? 'btn-outline-danger' : 'btn-outline-success'; ?>">
                                <?php echo $isActive ? 'Desativar provider' : 'Ativar provider'; ?>
                            </button>
                        </form>
                        <?php if ($provider['has_key']): ?>
                            <form method="post" action="<?php echo app_url('admin/ai/' . $pid . '/remover-chave'); ?>"
                                  onsubmit="return confirm('Remover a API Key deste provider? Ele será desativado.');">
                                <?php echo csrf_field(); ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Remover API Key</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<p class="text-muted small">
    Auditoria: alterações de configuração, status, chave, modelo e testes de conexão são registradas em
    <code>audit_logs</code>. Detalhes técnicos ficam em <code>storage/logs/ai.log</code> (sem a API Key).
</p>