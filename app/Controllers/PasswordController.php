<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Repositories\UserRepository;
use App\Services\Security\Audit;
use App\Services\Security\Auth;
use App\Services\Security\PasswordPolicy;

final class PasswordController extends Controller
{
    public function showChange(): void
    {
        $user = Auth::instance()->user();

        if ($user === null) {
            $this->redirect('/login');
        }

        $this->render('auth/change_password', [
            'appTitle' => 'Alterar senha',
            'user' => $user,
        ]);
    }

    public function change(): void
    {
        $user = Auth::instance()->user();

        if ($user === null) {
            $this->redirect('/login');
        }

        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirmation = (string) ($_POST['new_password_confirmation'] ?? '');

        if (!password_verify($current, $user['password_hash'])) {
            Flash::set('error', 'A senha atual está incorreta.');
            $this->redirect('/alterar-senha');
        }

        $policyError = PasswordPolicy::validate($new);

        if ($policyError !== null) {
            Flash::set('error', $policyError);
            $this->redirect('/alterar-senha');
        }

        if ($new !== $confirmation) {
            Flash::set('error', 'A confirmação da nova senha não confere.');
            $this->redirect('/alterar-senha');
        }

        if (password_verify($new, $user['password_hash'])) {
            Flash::set('error', 'A nova senha deve ser diferente da atual.');
            $this->redirect('/alterar-senha');
        }

        (new UserRepository())->updatePassword((int) $user['id'], password_hash($new, PASSWORD_DEFAULT), 0);

        Audit::record('auth.password.change', 'user', (int) $user['id'], [
            'forced' => (int) $user['must_change_password'] === 1,
            'ip' => client_ip(),
        ]);

        Flash::set('success', 'Senha alterada com sucesso.');

        $this->redirect('/dashboard');
    }
}