<?php
use App\Services\Copilot\EvidenceType;

$isCase = $kind === EvidenceType::RESOLVED_CASE;
$error = static fn (string $field): string => isset($errors[$field]) ? e($errors[$field]) : '';
$val = static fn (string $field): string => e((string) ($old[$field] ?? ''));

$fields = $isCase
    ? [
        ['title', 'Título do caso', 'input', true],
        ['problem', 'Problema', 3, true],
        ['module', 'Módulo', 'input', false],
        ['context', 'Contexto', 'input', false],
        ['symptoms', 'Sintomas observados', 4, false],
        ['confirmed_cause', 'Causa confirmada', 3, true],
        ['solution', 'Solução aplicada', 5, true],
        ['evidence', 'Evidências usadas', 4, false],
        ['observations', 'Observações', 3, false],
    ]
    : [
        ['title', 'Título do procedimento', 'input', true],
        ['module', 'Módulo', 'input', false],
        ['usage_context', 'Contexto de uso', 'input', false],
        ['prerequisites', 'Pré-requisitos', 3, false],
        ['steps', 'Passo a passo', 8, true],
        ['risks', 'Riscos e cuidados', 3, false],
        ['observations', 'Observações', 3, false],
    ];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?php echo e($appTitle); ?></h1>
    <a href="<?php echo e($backUrl); ?>" class="btn btn-outline-secondary btn-sm">&larr; Voltar</a>
</div>

<div class="row">
    <div class="col-lg-8">
        <form method="post" action="<?php echo e($action); ?>" autocomplete="off">
            <?php echo csrf_field(); ?>
            <?php if (!empty($old['from_message_id'])): ?>
                <input type="hidden" name="from_message_id" value="<?php echo $val('from_message_id'); ?>">
            <?php endif; ?>
            <div class="card app-card">
                <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
                    <span><?php echo $isCase ? 'Dados do caso' : 'Dados do procedimento'; ?></span>
                    <span class="chat-badge <?php echo e(EvidenceType::cssClass($kind)); ?>"><?php echo e(EvidenceType::badge($kind)); ?></span>
                </div>
                <div class="card-body">
                    <div class="alert alert-secondary small">
                        <?php if ($isCase): ?>
                            Registro factual de um problema real que foi resolvido. Os campos foram pré-preenchidos a partir do diagnóstico
                            e podem conter texto sugerido pela IA: revise tudo antes de salvar. Não é documentação oficial UNIMESTRE.
                        <?php else: ?>
                            Orientação criada pela equipe para execução recorrente. Fica separada da documentação oficial UNIMESTRE.
                        <?php endif; ?>
                    </div>

                    <?php foreach ($fields as [$name, $label, $type, $required]): ?>
                        <div class="mb-3">
                            <label for="<?php echo $name; ?>" class="form-label"><?php echo e($label); ?><?php echo $required ? ' *' : ''; ?></label>
                            <?php if ($type === 'input'): ?>
                                <input type="text" class="form-control" id="<?php echo $name; ?>" name="<?php echo $name; ?>" value="<?php echo $val($name); ?>" <?php echo $required ? 'required' : ''; ?>>
                            <?php else: ?>
                                <textarea class="form-control" id="<?php echo $name; ?>" name="<?php echo $name; ?>" rows="<?php echo (int) $type; ?>" <?php echo $required ? 'required' : ''; ?>><?php echo $val($name); ?></textarea>
                            <?php endif; ?>
                            <?php if ($error($name) !== ''): ?><div class="text-danger small"><?php echo $error($name); ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($isCase && !$editing): ?>
                        <?php if (!empty($causeNeedsReview)): ?>
                            <div class="alert alert-warning small mb-2">O diagnóstico terminou sem hipótese confirmada. A causa precisa de revisão explícita.</div>
                        <?php endif; ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" value="1" id="cause_reviewed" name="cause_reviewed" <?php echo ($old['cause_reviewed'] ?? '') === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="cause_reviewed">Revisei a causa e confirmo que ela é a causa real do problema.</label>
                            <?php if ($error('cause_reviewed') !== ''): ?><div class="text-danger small"><?php echo $error('cause_reviewed'); ?></div><?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" value="1" id="confirm_review" name="confirm_review">
                        <label class="form-check-label" for="confirm_review">
                            <?php echo $isCase ? 'Revisei o conteúdo e confirmo que o problema foi resolvido com esta solução.' : 'Revisei o procedimento e confirmo que ele pode ser usado pela equipe.'; ?>
                        </label>
                        <?php if ($error('confirm_review') !== ''): ?><div class="text-danger small"><?php echo $error('confirm_review'); ?></div><?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo $editing ? 'Salvar alterações' : ($isCase ? 'Registrar caso resolvido' : 'Salvar procedimento'); ?></button>
                </div>
            </div>
        </form>
    </div>
</div>
