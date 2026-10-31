<?php
/**
 * Seção "Fallback automático" da tela Integração com IA.
 *
 * @var bool $fallbackEnabled
 * @var array<int, array<string, mixed>> $priorityList
 * @var array<int, array<string, mixed>> $attemptOrder
 * @var array<string, mixed>|null $defaultProvider
 * @var int $fallbackBudget
 * @var bool $canManage
 */
$fallbackEnabled = (bool) ($fallbackEnabled ?? false);
$priorityList = (array) ($priorityList ?? []);
$attemptOrder = (array) ($attemptOrder ?? []);
$defaultProvider = $defaultProvider ?? null;
$fallbackBudget = (int) ($fallbackBudget ?? 90);
$canManage = !empty($canManage);
?>
<div class="card app-card mb-4" id="fallback">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong>Fallback automático entre providers</strong>
        <span class="badge <?php echo $fallbackEnabled ? 'text-bg-success' : 'text-bg-secondary'; ?>">
            <?php echo $fallbackEnabled ? 'Ativado' : 'Desativado'; ?>
        </span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-5">
                <p class="small mb-2">
                    <strong>Provider padrão</strong> é sempre a <strong>primeira tentativa</strong>:
                    <span class="badge text-bg-primary"><?php echo e($defaultProvider['name'] ?? 'nenhum padrão ativo'); ?></span>
                </p>
                <p class="small text-muted mb-2">
                    O fallback é apenas <strong>contingência</strong>: se o padrão falhar por erro temporário
                    (timeout, conexão, 429, 5xx, modelo indisponível), a mesma pergunta — com o mesmo contexto e as
                    mesmas fontes — é enviada ao próximo provider ativo, na ordem de prioridade. Cada provider é tentado
                    no máximo uma vez. Erros de requisição inválida ou bloqueio de conteúdo não trocam de provider.
                    Erros de chave (401/403) trocam de provider, mas ficam registrados para o administrador; a chave não é alterada.
                </p>
                <p class="small text-muted mb-3">Tempo total máximo aproximado por pergunta: <?php echo (int) $fallbackBudget; ?> s.</p>

                <?php if ($canManage): ?>
                    <form method="post" action="<?php echo app_url('admin/ai/fallback'); ?>">
                        <?php echo csrf_field(); ?>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="fallback_enabled" name="fallback_enabled" value="1" <?php echo $fallbackEnabled ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="fallback_enabled">Ativar fallback automático entre providers</label>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary">Salvar</button>
                    </form>
                <?php endif; ?>

                <div class="mt-3 small">
                    <strong>Ordem efetiva de tentativa<?php echo $fallbackEnabled ? '' : ' (com o fallback desativado, só a 1ª é usada)'; ?>:</strong>
                    <?php if ($attemptOrder === []): ?>
                        <div class="text-danger">Nenhum provider elegível (ativo, com chave e modelo).</div>
                    <?php else: ?>
                        <ol class="mb-0 ps-3">
                            <?php foreach ($attemptOrder as $i => $p): ?>
                                <li><?php echo e($p['name']); ?><?php echo $i === 0 ? ' — primária' : ' — contingência'; ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <caption class="small">Prioridade de contingência (1 = primeiro depois do padrão)</caption>
                        <thead>
                            <tr><th scope="col">Prioridade</th><th scope="col">Provider</th><th scope="col">Status</th><th scope="col">Modelo</th><?php if ($canManage): ?><th scope="col"><span class="visually-hidden">Mover</span></th><?php endif; ?></tr>
                        </thead>
                        <tbody>
                            <?php $last = count($priorityList) - 1; ?>
                            <?php foreach ($priorityList as $i => $p): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td>
                                        <?php echo e($p['name']); ?>
                                        <?php if ((int) $p['is_default'] === 1): ?><span class="badge text-bg-primary ms-1">Padrão</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($p['eligible']): ?>
                                            <span class="badge text-bg-success">Ativo</span>
                                        <?php elseif ($p['status'] === 'active'): ?>
                                            <span class="badge text-bg-warning">Incompleto</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-break"><?php echo e((string) ($p['model'] ?? '—')); ?></td>
                                    <?php if ($canManage): ?>
                                        <td class="text-nowrap">
                                            <form method="post" action="<?php echo app_url('admin/ai/' . (int) $p['id'] . '/prioridade'); ?>" class="d-inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="direction" value="up">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary" <?php echo $i === 0 ? 'disabled' : ''; ?> aria-label="Subir <?php echo e($p['name']); ?>">↑</button>
                                            </form>
                                            <form method="post" action="<?php echo app_url('admin/ai/' . (int) $p['id'] . '/prioridade'); ?>" class="d-inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="direction" value="down">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary" <?php echo $i === $last ? 'disabled' : ''; ?> aria-label="Descer <?php echo e($p['name']); ?>">↓</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
