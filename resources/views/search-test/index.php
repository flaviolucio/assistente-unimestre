<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h4 mb-0">Testar recuperação</h1>
</div>

<?php if (!empty($errors['query'])): ?>
    <div class="alert alert-danger app-flash" role="alert"><?php echo e($errors['query']); ?></div>
<?php endif; ?>

<div class="card app-card mb-4">
    <div class="card-body">
        <form method="post" action="<?php echo app_url('admin/search-test'); ?>">
            <?php echo csrf_field(); ?>
            <label for="query" class="form-label small mb-1">Pergunta</label>
            <textarea class="form-control" id="query" name="query" rows="2"
                      placeholder="Ex.: Como alterar a situação de matrícula de um aluno?"><?php echo e($query ?? ''); ?></textarea>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary btn-sm">Pesquisar</button>
            </div>
        </form>
        <p class="form-text mb-0 mt-2">
            Ferramenta administrativa de depuração do mecanismo de recuperação (RAG local).
            Nenhuma IA é utilizada nesta tela.
        </p>
    </div>
</div>

<?php if ($result !== null): ?>
    <div class="card app-card mb-4">
        <div class="card-body py-3">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-muted small text-uppercase">Pergunta normalizada</div>
                    <div class="small"><?php echo e($result['normalized'] !== '' ? $result['normalized'] : '—'); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small text-uppercase">Termos</div>
                    <div class="small"><?php echo $result['terms'] === [] ? '—' : e(implode(', ', $result['terms'])); ?></div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small text-uppercase">Candidatos</div>
                    <div class="small"><?php echo (int) $result['total_candidates']; ?></div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small text-uppercase">Duração</div>
                    <div class="small"><?php echo e((string) ($result['metrics']['duration_ms'] ?? 0)); ?> ms</div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small text-uppercase">Estratégias</div>
                    <div class="small"><?php echo ($result['metrics']['strategies'] ?? []) === [] ? '—' : e(implode(', ', $result['metrics']['strategies'])); ?></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($result['results'] === []): ?>
        <div class="alert alert-warning app-flash" role="alert">
            <?php echo e($result['message'] ?? 'Nenhum conteúdo suficientemente relevante encontrado.'); ?>
        </div>
    <?php else: ?>
        <?php foreach ($result['results'] as $index => $item): ?>
            <?php
            $isOfficial = ($item['source_type'] ?? '') === 'official';
            $breakdown = $item['breakdown'] ?? [];
            $canView = $item['document_id'] !== null;
            ?>
            <div class="card app-card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <h2 class="h5 mb-0">Resultado <?php echo (int) $index + 1; ?></h2>
                        <span class="badge text-bg-primary">Score: <?php echo e((string) $item['score']); ?></span>
                    </div>
                    <div class="mb-2">
                        <div class="text-muted small text-uppercase">Fonte</div>
                        <?php if ($isOfficial): ?>
                            <span class="badge app-badge-official">OFICIAL UNIMESTRE</span>
                        <?php else: ?>
                            <span class="badge app-badge-internal">PROCEDIMENTO INTERNO</span>
                        <?php endif; ?>
                    </div>
                    <div class="mb-1">
                        <span class="text-muted small text-uppercase">Documento:</span>
                        <?php if ($canView): ?>
                            <a class="app-link" href="<?php echo app_url('knowledge/' . (int) $item['document_id']); ?>"><?php echo e($item['title']); ?></a>
                        <?php else: ?>
                            <strong><?php echo e($item['title']); ?></strong>
                        <?php endif; ?>
                    </div>
                    <div class="mb-1">
                        <span class="text-muted small text-uppercase">Categoria:</span>
                        <?php echo e($item['category'] ?? '—'); ?>
                        <?php if (!empty($item['subcategory'])): ?>
                            <span class="text-muted">/ <?php echo e($item['subcategory']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="mb-2">
                        <span class="text-muted small text-uppercase">Trecho encontrado:</span>
                        <div class="app-prose bg-light border rounded p-2 mt-1 small"><?php echo e($item['content']); ?></div>
                    </div>
                    <div class="mb-2">
                        <span class="text-muted small text-uppercase">Termos encontrados:</span>
                        <span class="small"><?php echo ($item['matched_terms'] ?? []) === [] ? '—' : e(implode(', ', $item['matched_terms'])); ?></span>
                    </div>
                    <div class="small">
                        <span class="text-muted text-uppercase">Composição do score:</span>
                        <table class="table table-sm table-borderless mb-0 mt-1 w-auto">
                            <?php foreach ($breakdown as $label => $value): ?>
                                <tr>
                                    <td class="pe-3 text-muted text-capitalize"><?php echo e((string) $label); ?></td>
                                    <td class="text-end"><?php echo e((string) $value); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="fw-semibold">
                                <td class="pe-3 text-uppercase">Score final</td>
                                <td class="text-end"><?php echo e((string) $item['score']); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>