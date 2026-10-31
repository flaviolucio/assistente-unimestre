<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h4 mb-0">Diagnóstico do ambiente</h1>
            <button type="button" class="btn btn-sm btn-outline-primary" id="btn-recarregar-diagnostico">Recarregar</button>
        </div>

        <div class="alert app-env-alert">
            <span class="badge <?php echo $diagnostic['all_ok'] ? 'text-bg-success' : 'text-bg-danger'; ?>">
                <?php echo $diagnostic['all_ok'] ? 'AMBIENTE OK' : 'PROBLEMA DETECTADO'; ?>
            </span>
            <span class="ms-2 text-muted small">Verificado em <?php echo htmlspecialchars($diagnostic['timestamp'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>

        <div class="card app-card mb-3">
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-3">Aplicação</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($diagnostic['app']['name'], ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt class="col-sm-3">Ambiente</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($diagnostic['app']['env'], ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt class="col-sm-3">URL</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($diagnostic['app']['url'], ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt class="col-sm-3">Bridge API</dt>
                    <dd class="col-sm-9"><a href="./api/diagnostico" target="_blank">./api/diagnostico</a></dd>
                </dl>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table app-table align-middle">
                <thead>
                <tr>
                    <th>Item</th>
                    <th>Status</th>
                    <th>Detalhe</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($diagnostic['checks'] as $check): ?>
                    <tr>
                        <td class="fw-semibold"><?php echo htmlspecialchars($check['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if ($check['ok']): ?>
                                <span class="badge text-bg-success">OK</span>
                            <?php else: ?>
                                <span class="badge text-bg-danger">FALHOU</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?php echo htmlspecialchars($check['detail'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>