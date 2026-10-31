<?php

use App\Services\Security\Auth;

$currentUser = Auth::instance()->user();
$pageTitle = htmlspecialchars((string) ($appTitle ?? ''), ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo ($pageTitle !== '' ? $pageTitle . ' — ' : '') . htmlspecialchars((string) ($appName ?? ''), ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo e((string) ($basePublic ?? '') . 'assets/css/app.css'); ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark app-navbar">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-semibold" href="<?php echo e($basePublic ?? './'); ?>">
            <span class="app-dot me-2"></span><?php echo e($appName ?? 'Assistente UNIMESTRE'); ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarApp" aria-controls="navbarApp" aria-expanded="false" aria-label="Alternar navegação">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarApp">
            <?php if ($currentUser !== null): ?>
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo app_url('dashboard'); ?>">Painel</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo app_url('assistente'); ?>">Assistente</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo app_url('history'); ?>">Histórico</a>
                    </li>
                    <?php if ($currentUser['role'] === 'admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo app_url('usuarios'); ?>">Usuários</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo app_url('admin/search-test'); ?>">Testar recuperação</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo app_url('admin/ai'); ?>">IA</a>
                        </li>
                    <?php endif; ?>
                    <?php if (Auth::instance()->can('knowledge.view')): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo app_url('knowledge'); ?>">Base de Conhecimento</a>
                        </li>
                    <?php endif; ?>
                    <?php if (Auth::instance()->can('solutions.view')): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo app_url('solutions'); ?>">Soluções Internas</a>
                        </li>
                    <?php endif; ?>
                </ul>
                <div class="d-flex align-items-center gap-3">
                    <span class="navbar-text text-white-50 small text-end">
                        <?php echo e($currentUser['name']); ?>
                        <span class="badge app-role-badge"><?php echo e($currentUser['role']); ?></span>
                    </span>
                    <form method="post" action="<?php echo app_url('logout'); ?>">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="btn btn-sm btn-outline-light">Sair</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="ms-auto d-flex align-items-center gap-3">
                    <span class="navbar-text text-white-50 small"><?php echo e((string) ($appEnv ?? '')); ?></span>
                    <a class="btn btn-sm btn-outline-light" href="<?php echo app_url('login'); ?>">Entrar</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>
<main class="container-fluid px-4 py-4">
    <?php if (!empty($flashMessages)): ?>
        <?php foreach ($flashMessages as $type => $messages): ?>
            <?php foreach ($messages as $message): ?>
                <div class="alert alert-<?php echo e($type === 'error' ? 'danger' : ($type === 'warning' ? 'warning' : 'success')); ?> app-flash alert-dismissible fade show" role="alert">
                    <?php echo e($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php echo $content ?? ''; ?>
</main>
<footer class="text-center text-muted small py-3">
    <?php echo e((string) ($appName ?? '')); ?>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo e((string) ($basePublic ?? '') . 'assets/js/app.js'); ?>"></script>
</body>
</html>