<?php

declare(strict_types=1);

use App\Core\Database;

$run = static function (PDO $db): void {
    $adminEmail = (string) env('APP_ADMIN_EMAIL', 'admin@assistente.local');
    $defaultPassword = (string) env('APP_ADMIN_PASSWORD', 'admin123');

    $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$adminEmail]);
    $admin = $stmt->fetch();

    if ($admin === false) {
        echo 'Seed: usuário admin não encontrado; nada a fazer' . PHP_EOL;

        return;
    }

    $stillDefault = password_verify($defaultPassword, $admin['password_hash']);
    $forceChange = $stillDefault ? 1 : 0;

    $update = $db->prepare(
        'UPDATE users SET must_change_password = ? WHERE id = ?'
    );
    $update->execute([$forceChange, $admin['id']]);

    echo 'Seed: política de troca de senha admin = '
        . ($forceChange === 1 ? 'obrigatória (senha padrão ainda ativa)' : 'dispensada (senha já alterada)')
        . PHP_EOL;
};

return [
    'version' => '0002',
    'name' => 'force_admin_password_change_policy',
    'run' => $run,
];