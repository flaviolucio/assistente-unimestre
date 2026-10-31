<?php
/**
 * Conteúdo do modal de escalonamento (Etapa 7.5). Só apresentação dos dados
 * do EscalationSummaryService (já mascarados). Nada é enviado: o técnico
 * copia os textos e, se quiser, marca o caso como escalado.
 *
 * @var array<string, mixed> $summary
 * @var string $summaryText
 * @var string $supportText
 * @var bool $readOnly
 * @var bool $canReopen
 * @var array<string, string> $reasons
 * @var array<string, mixed>|null $escalated  ['at','by','reason','reference']
 */
$s = $summary;
$eid = 'esc-' . (int) $sessionId;
$list = static function (array $items): string {
    if ($items === []) {
        return '<p class="esc-empty">Nenhum registro.</p>';
    }

    return '<ul>' . implode('', array_map(static fn (string $i): string => '<li>' . e($i) . '</li>', $items)) . '</ul>';
};
?>
<div class="esc" data-esc-session="<?php echo (int) $sessionId; ?>">
    <div class="esc-head">
        <h2 class="esc-title" id="<?php echo $eid; ?>-title"><?php echo $readOnly ? 'Resumo do escalonamento' : 'Preparar escalonamento'; ?></h2>
        <button type="button" class="btn-close" data-esc-action="close" aria-label="Fechar"></button>
    </div>

    <p class="esc-note" role="note">
        Com as informações atuais, não foi possível confirmar a causa. Este resumo foi montado apenas com dados registrados
        no atendimento. <strong>Nada é enviado automaticamente</strong>: copie o texto e abra o chamado pelo canal de suporte.
    </p>
    <?php if ((int) $s['masked_count'] > 0): ?>
        <p class="esc-masked" role="status"><?php echo (int) $s['masked_count']; ?> valor(es) potencialmente sensível(is) foram omitidos (senhas, tokens, dados pessoais).</p>
    <?php endif; ?>

    <?php if ($escalated !== null): ?>
        <div class="esc-done" role="status">
            <strong>Caso escalado</strong> em <?php echo e($escalated['at']); ?> por <?php echo e($escalated['by']); ?>.
            Motivo: <?php echo e($escalated['reason']); ?>.
            <?php if (!empty($escalated['reference'])): ?>Protocolo externo: <?php echo e($escalated['reference']); ?>.<?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="esc-section" aria-labelledby="<?php echo $eid; ?>-case">
        <h3 id="<?php echo $eid; ?>-case">Resumo do caso</h3>
        <dl class="esc-facts">
            <div><dt>Problema original</dt><dd><?php echo e($s['original_problem']); ?></dd></div>
            <div><dt>Módulo</dt><dd><?php echo e($s['module']); ?></dd></div>
            <div><dt>Contexto</dt><dd><?php echo e($s['context']); ?></dd></div>
            <div><dt>Comportamento esperado</dt><dd><?php echo e($s['expected_behavior']); ?></dd></div>
            <div><dt>Comportamento observado</dt><dd><?php echo e($s['observed_behavior']); ?></dd></div>
            <div><dt>Técnico responsável</dt><dd><?php echo e($s['technician']); ?></dd></div>
        </dl>
        <?php if (!empty($s['suggested_reason'])): ?>
            <p class="esc-suggested"><span class="chat-badge chat-badge-hypothesis">🔎 HIPÓTESE DA IA</span> Motivo sugerido: <?php echo e($s['suggested_reason']); ?></p>
        <?php endif; ?>
    </section>

    <section class="esc-section" aria-labelledby="<?php echo $eid; ?>-checks">
        <h3 id="<?php echo $eid; ?>-checks">Verificações realizadas</h3>
        <?php echo $list($s['checks']); ?>
    </section>

    <section class="esc-section" aria-labelledby="<?php echo $eid; ?>-hyp">
        <h3 id="<?php echo $eid; ?>-hyp">Hipóteses <span class="dx-hint">(não confirmadas; não são fontes)</span></h3>
        <div class="esc-label">Ainda abertas</div>
        <?php echo $list(array_map(static fn (array $h): string => $h['title'] . ' — ' . $h['status_label'], $s['open'])); ?>
        <?php if ($s['most_supported'] !== null): ?>
            <div class="esc-label">Mais sustentada (não confirmada): <?php echo e($s['most_supported']); ?></div>
        <?php endif; ?>
        <div class="esc-label">Rejeitadas</div>
        <?php echo $list(array_map(static fn (array $h): string => $h['title'], $s['rejected'])); ?>
    </section>

    <section class="esc-section" aria-labelledby="<?php echo $eid; ?>-src">
        <h3 id="<?php echo $eid; ?>-src">Fontes consultadas</h3>
        <?php if ($s['sources'] === []): ?>
            <p class="esc-empty">Nenhum registro.</p>
        <?php else: ?>
            <ul class="dx-sources">
                <?php foreach ($s['sources'] as $f): ?>
                    <li><span class="chat-badge <?php echo e(\App\Services\Copilot\EvidenceType::cssClass($f['type'])); ?>"><?php echo e($f['badge']); ?></span> <?php echo e($f['title']); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($s['has_conflict']): ?>
            <div class="esc-conflict" role="note">
                <strong>Há divergência entre as fontes consultadas.</strong>
                <ul>
                    <?php foreach ($s['conflicts'] as $c): ?>
                        <li><?php echo e($c['badge']); ?> <em><?php echo e($c['title']); ?></em>: <?php echo e($c['claim']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </section>

    <section class="esc-section" aria-labelledby="<?php echo $eid; ?>-tl">
        <h3 id="<?php echo $eid; ?>-tl">Histórico da investigação</h3>
        <ol><?php foreach ($s['timeline'] as $t): ?><li><?php echo e($t); ?></li><?php endforeach; ?></ol>
    </section>

    <section class="esc-section" aria-labelledby="<?php echo $eid; ?>-pend">
        <h3 id="<?php echo $eid; ?>-pend">Informações que ainda podem ser necessárias</h3>
        <?php echo $list($s['pending_info']); ?>
    </section>

    <section class="esc-section" aria-labelledby="<?php echo $eid; ?>-support">
        <h3 id="<?php echo $eid; ?>-support"><label for="<?php echo $eid; ?>-support-text">Texto para o suporte UNIMESTRE</label></h3>
        <textarea class="form-control esc-text" id="<?php echo $eid; ?>-support-text" rows="12" readonly data-esc-text="support"><?php echo e($supportText); ?></textarea>
        <textarea hidden aria-hidden="true" data-esc-text="summary"><?php echo e($summaryText); ?></textarea>
    </section>

    <div class="esc-actions">
        <button type="button" class="btn btn-sm btn-outline-primary" data-esc-action="copy" data-esc-target="summary">Copiar resumo</button>
        <button type="button" class="btn btn-sm btn-outline-primary" data-esc-action="copy" data-esc-target="support">Copiar texto para suporte</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-esc-action="<?php echo $canReopen ? 'reopen' : 'close'; ?>">Voltar ao diagnóstico</button>
        <?php if (!$readOnly): ?>
            <button type="button" class="btn btn-sm btn-warning" data-esc-action="confirm-ask" aria-expanded="false" aria-controls="<?php echo $eid; ?>-confirm">Marcar como escalado</button>
        <?php endif; ?>
        <span class="esc-copied small" role="status" aria-live="polite"></span>
    </div>

    <?php if (!$readOnly): ?>
        <div class="esc-confirm" id="<?php echo $eid; ?>-confirm" hidden>
            <p class="fw-semibold mb-2">Confirma que este caso será encaminhado para outro atendimento? Nada é enviado automaticamente.</p>
            <div class="mb-2">
                <label class="form-label small" for="<?php echo $eid; ?>-reason">Motivo do escalonamento *</label>
                <select class="form-select form-select-sm" id="<?php echo $eid; ?>-reason" data-esc-field="reason" required>
                    <option value="">Selecione…</option>
                    <?php foreach ($reasons as $value => $label): ?>
                        <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-2">
                <label class="form-label small" for="<?php echo $eid; ?>-ref">Referência externa / protocolo (opcional)</label>
                <input type="text" class="form-control form-control-sm" id="<?php echo $eid; ?>-ref" data-esc-field="reference" maxlength="120" autocomplete="off">
            </div>
            <button type="button" class="btn btn-sm btn-warning" data-esc-action="confirm">Confirmar escalonamento</button>
            <button type="button" class="btn btn-sm btn-link" data-esc-action="confirm-cancel">Cancelar</button>
            <div class="esc-error small text-danger" role="alert" hidden></div>
        </div>
    <?php endif; ?>
</div>
