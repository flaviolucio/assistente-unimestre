<?php
$chatUrl = app_url('assistente');
$conversations = isset($conversations) ? (array) $conversations : [];

$formatDate = static function (string $value): string {
    $ts = strtotime($value);

    if ($ts === false) {
        return $value;
    }

    return date('d/m/Y H:i', $ts);
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Histórico de conversas</h1>
    <a href="<?php echo e($chatUrl); ?>" class="btn btn-primary btn-sm">Nova conversa</a>
</div>

<?php if (empty($conversations)): ?>
    <div class="alert alert-info">
        Nenhuma conversa ainda. <a class="app-link" href="<?php echo e($chatUrl); ?>">Pergunte ao Assistente UNIMESTRE</a>.
    </div>
<?php else: ?>
    <div class="app-table">
        <table class="table table-striped mb-0">
            <thead>
            <tr>
                <th>Assunto</th>
                <th class="text-end">Perguntas</th>
                <th class="text-end">Última atividade</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($conversations as $conversation): ?>
                <tr>
                    <td>
                        <a class="app-link fw-semibold" href="<?php echo e(app_url('assistente/' . (int) $conversation['id'])); ?>">
                            <?php echo e((string) $conversation['title']); ?>
                        </a>
                        <div class="small text-muted"><?php echo e($formatDate((string) $conversation['created_at'])); ?></div>
                    </td>
                    <td class="text-end"><?php echo (int) $conversation['question_count']; ?></td>
                    <td class="text-end"><?php echo e($formatDate((string) $conversation['last_message_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>