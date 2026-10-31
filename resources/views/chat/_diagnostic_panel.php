<?php
/**
 * Painel do diagnóstico conversacional (Etapa 7.4).
 * Recebe $panel de DiagnosticPanelPresenter::build(). Só apresentação:
 * todo texto dinâmico passa por e(). Ações são <button> reais tratadas por
 * ChatUI.bindDiagnosticPanel (chat.js), que apenas chama o backend.
 *
 * @var array<string, mixed> $panel
 */
$p = $panel;
$uid = 'dx-' . (int) $p['session_id'];
$expanded = (bool) $p['expanded'];
?>
<section class="dx-panel dx-status-<?php echo e($p['status']); ?>" aria-labelledby="<?php echo $uid; ?>-title"
         data-dx-session="<?php echo (int) $p['session_id']; ?>" data-dx-conversation="<?php echo (int) $p['conversation_id']; ?>">
    <h2 class="dx-heading">
        <button type="button" class="dx-toggle" data-dx-action="toggle"
                aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>" aria-controls="<?php echo $uid; ?>-body">
            <span class="dx-title" id="<?php echo $uid; ?>-title"><span aria-hidden="true">🔎</span> <?php echo e($p['heading']); ?></span>
            <span class="dx-chip"><?php echo e($p['status_label']); ?></span>
            <span class="dx-caret" aria-hidden="true"></span>
        </button>
    </h2>

    <div class="dx-body" id="<?php echo $uid; ?>-body"<?php echo $expanded ? '' : ' hidden'; ?>>
        <dl class="dx-facts">
            <div><dt>Problema</dt><dd><?php echo e($p['problem']); ?></dd></div>
            <?php if ($p['module'] !== ''): ?>
                <div><dt>Módulo</dt><dd><?php echo e($p['module']); ?></dd></div>
            <?php endif; ?>
            <div><dt>Etapa</dt><dd><?php echo e($p['stage_label']); ?></dd></div>
        </dl>

        <ol class="dx-timeline" aria-label="Progresso do diagnóstico">
            <?php foreach ($p['timeline'] as $i => $step): ?>
                <li class="dx-step dx-step-<?php echo e($step['state']); ?>"<?php echo $step['state'] === 'current' ? ' aria-current="step"' : ''; ?>>
                    <span class="dx-step-dot" aria-hidden="true"><?php echo $step['state'] === 'done' ? '✓' : (int) $i + 1; ?></span>
                    <span class="dx-step-label"><?php echo e($step['label']); ?></span>
                    <span class="visually-hidden"><?php echo $step['state'] === 'done' ? '(concluída)' : ($step['state'] === 'current' ? '(etapa atual)' : '(pendente)'); ?></span>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($p['next_question'] !== null): ?>
            <div class="dx-next" role="note">
                <div class="dx-next-label">Para continuar o diagnóstico, preciso confirmar:</div>
                <p class="dx-next-question"><?php echo e($p['next_question']); ?></p>
                <button type="button" class="btn btn-sm btn-primary" data-dx-action="answer">Responder</button>
            </div>
        <?php endif; ?>

        <?php if ($p['inconclusive']): ?>
            <div class="dx-inconclusive" role="status">
                <div class="dx-block-title">Diagnóstico ainda inconclusivo</div>
                <?php if ($p['open_hypotheses'] !== []): ?>
                    <div class="small">Hipóteses ainda abertas: <?php echo e(implode('; ', $p['open_hypotheses'])); ?>.</div>
                <?php endif; ?>
                <div class="small">Já verificado: <?php echo $p['answered'] !== [] ? e(implode('; ', array_map(static fn (array $q): string => $q['question'] . ' → ' . $q['answer'], $p['answered']))) : 'nenhuma verificação respondida ainda'; ?>.</div>
                <div class="small">Falta confirmar: <?php echo $p['next_question'] !== null ? e($p['next_question']) : 'com as informações atuais, não foi possível confirmar a causa.'; ?></div>
                <?php if ($p['pending_info'] !== []): ?>
                    <div class="small mt-1 fw-semibold">Informações que ainda podem ser necessárias:</div>
                    <ul class="small mb-1"><?php foreach ($p['pending_info'] as $info): ?><li><?php echo e($info); ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
                <?php if ($p['actions']['prepare_escalation']): ?>
                    <div class="dx-actions mt-2">
                        <button type="button" class="btn btn-sm btn-warning" data-dx-action="escalation-open" data-dx-escalation>Preparar escalonamento</button>
                        <?php if ($p['actions']['reopen']): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-dx-action="reopen">Voltar ao diagnóstico</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($p['escalated'] !== null): ?>
            <div class="dx-escalated" role="status">
                <div class="dx-block-title"><span aria-hidden="true">↗</span> Caso escalado</div>
                <div class="small"><strong>Data:</strong> <?php echo e($p['escalated']['at']); ?> · <strong>Responsável:</strong> <?php echo e($p['escalated']['by']); ?></div>
                <div class="small"><strong>Resumo:</strong> <?php echo e($p['escalated']['title']); ?></div>
                <div class="small"><strong>Motivo:</strong> <?php echo e($p['escalated']['reason']); ?></div>
                <?php if ($p['escalated']['reference'] !== ''): ?>
                    <div class="small"><strong>Protocolo externo:</strong> <?php echo e($p['escalated']['reference']); ?></div>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-dx-action="escalation-open">Ver resumo do escalonamento</button>
            </div>
        <?php endif; ?>

        <?php if ($p['resolved']): ?>
            <div class="dx-final">
                <div class="dx-block-title"><span aria-hidden="true">✓</span> Diagnóstico concluído</div>
                <?php if (!empty($p['confirmed_cause'])): ?>
                    <div class="small"><strong>Causa confirmada:</strong> <?php echo e((string) $p['confirmed_cause']); ?></div>
                <?php endif; ?>
                <?php if (!empty($p['recommended_action'])): ?>
                    <div class="small"><strong>Solução aplicada:</strong> <?php echo e((string) $p['recommended_action']); ?></div>
                <?php endif; ?>
                <?php if ($p['main_sources'] !== []): ?>
                    <ul class="dx-sources" aria-label="Fontes principais">
                        <?php foreach ($p['main_sources'] as $s): ?>
                            <li><span class="chat-badge <?php echo e($s['css']); ?>"><?php echo e($s['badge']); ?></span> <?php echo e($s['title']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($p['actions']['case_registered']): ?>
                    <div class="dx-registered" data-dx-registered>
                        <span aria-hidden="true">🧩</span> Caso resolvido registrado
                        <?php if ($p['urls']['case_edit'] !== null): ?>
                            · <a href="<?php echo e($p['urls']['case_edit']); ?>">ver/editar</a>
                        <?php endif; ?>
                    </div>
                <?php elseif ($p['actions']['register_case']): ?>
                    <a class="btn btn-sm btn-success mt-2" href="<?php echo e($p['urls']['register_case']); ?>" data-dx-register>🧩 Registrar como caso resolvido</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($p['summary'] !== ''): ?>
            <p class="dx-summary"><?php echo e($p['summary']); ?></p>
        <?php endif; ?>

        <?php if ($p['hypotheses'] !== []): ?>
            <h3 class="dx-subtitle">Possíveis causas <span class="dx-hint">(hipóteses, não documentação)</span></h3>
            <ul class="dx-hyps">
                <?php foreach ($p['hypotheses'] as $h): ?>
                    <?php $hid = $uid . '-h' . (int) $h['id']; ?>
                    <li class="dx-hyp dx-hyp-<?php echo e($h['status']); ?>">
                        <div class="dx-hyp-row">
                            <span class="dx-hyp-title"><?php echo e($h['title']); ?></span>
                            <span class="dx-hyp-badge" data-dx-status="<?php echo e($h['status']); ?>"><span aria-hidden="true"><?php echo e($h['icon']); ?></span> <?php echo e($h['status_label']); ?></span>
                        </div>
                        <?php if ($h['has_evidence']): ?>
                            <button type="button" class="dx-why" data-dx-action="toggle" aria-expanded="false" aria-controls="<?php echo $hid; ?>">Por que essa hipótese está aqui?</button>
                            <div class="dx-evidence" id="<?php echo $hid; ?>" hidden>
                                <?php if ($h['supports'] !== []): ?>
                                    <div class="dx-ev-label">Sustenta:</div>
                                    <ul><?php foreach ($h['supports'] as $t): ?><li><?php echo e($t); ?></li><?php endforeach; ?></ul>
                                <?php endif; ?>
                                <?php if ($h['weakens'] !== []): ?>
                                    <div class="dx-ev-label">Enfraquece:</div>
                                    <ul><?php foreach ($h['weakens'] as $t): ?><li><?php echo e($t); ?></li><?php endforeach; ?></ul>
                                <?php endif; ?>
                                <?php if ($h['sources'] !== []): ?>
                                    <div class="dx-ev-label">Fontes relacionadas:</div>
                                    <ul><?php foreach ($h['sources'] as $s): ?><li><span class="chat-badge <?php echo e($s['css']); ?>"><?php echo e($s['badge']); ?></span> <?php echo e($s['title']); ?></li><?php endforeach; ?></ul>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($p['answered'] !== []): ?>
            <h3 class="dx-subtitle">Verificações</h3>
            <ul class="dx-checks">
                <?php foreach ($p['answered'] as $q): ?>
                    <li>
                        <div class="dx-check-q"><?php echo e($q['question']); ?></div>
                        <div class="dx-check-a"><span class="dx-answered">✓ Respondida</span> <?php echo e($q['answer']); ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($p['actions']['resolve'] || $p['actions']['abandon'] || $p['actions']['create_procedure']): ?>
            <div class="dx-actions">
                <?php if ($p['actions']['resolve']): ?>
                    <button type="button" class="btn btn-sm btn-outline-success" data-dx-action="resolve-ask" aria-controls="<?php echo $uid; ?>-resolve" aria-expanded="false">✓ Problema resolvido</button>
                <?php endif; ?>
                <?php if ($p['actions']['abandon']): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-dx-action="abandon-ask" aria-controls="<?php echo $uid; ?>-abandon" aria-expanded="false">Encerrar diagnóstico</button>
                <?php endif; ?>
                <?php if ($p['actions']['create_procedure']): ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo e($p['urls']['create_procedure']); ?>" data-dx-procedure>🛠️ Criar procedimento interno</a>
                <?php endif; ?>
            </div>

            <?php if ($p['actions']['resolve']): ?>
                <div class="dx-confirm" id="<?php echo $uid; ?>-resolve" role="group" aria-labelledby="<?php echo $uid; ?>-resolve-q" hidden>
                    <p id="<?php echo $uid; ?>-resolve-q">Confirma que o problema foi resolvido com a orientação aplicada?</p>
                    <button type="button" class="btn btn-sm btn-success" data-dx-action="resolve-confirm">Confirmar resolução</button>
                    <button type="button" class="btn btn-sm btn-link" data-dx-action="cancel">Voltar</button>
                    <div class="dx-error small text-danger" role="alert" hidden></div>
                </div>
            <?php endif; ?>

            <?php if ($p['actions']['abandon']): ?>
                <fieldset class="dx-confirm" id="<?php echo $uid; ?>-abandon" hidden>
                    <legend class="dx-legend">Por que encerrar o diagnóstico?</legend>
                    <?php foreach ($p['close_reasons'] as $value => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="<?php echo $uid; ?>-reason" id="<?php echo $uid; ?>-r-<?php echo e($value); ?>" value="<?php echo e($value); ?>"<?php echo $value === 'nao_resolvido' ? ' checked' : ''; ?>>
                            <label class="form-check-label" for="<?php echo $uid; ?>-r-<?php echo e($value); ?>"><?php echo e($label); ?></label>
                        </div>
                    <?php endforeach; ?>
                    <button type="button" class="btn btn-sm btn-secondary mt-2" data-dx-action="abandon-confirm">Encerrar</button>
                    <button type="button" class="btn btn-sm btn-link mt-2" data-dx-action="cancel">Voltar</button>
                    <div class="dx-error small text-danger" role="alert" hidden></div>
                </fieldset>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
