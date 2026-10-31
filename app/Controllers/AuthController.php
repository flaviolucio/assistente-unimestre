<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Services\Security\Audit;
use App\Services\Security\Auth;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::instance()->check()) {
            $this->redirect('/dashboard');
        }

        $this->render('auth/login', [
            'appTitle' => 'Entrar',
        ]);
    }

    public function login(): void
    {
        if (Auth::instance()->check()) {
            $this->redirect('/dashboard');
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $result = Auth::instance()->attempt($email, $password);

        if ($result['status'] !== 'ok' || $result['user'] === null) {
            Flash::set('error', $this->messageForStatus($result['status']));
            Flash::input('email', $email);

            $this->redirect('/login');
        }

        $user = $result['user'];

        Auth::instance()->loginUser($user);

        Audit::record('auth.login', 'user', (int) $user['id'], ['ip' => client_ip()]);

        if ((int) $user['must_change_password'] === 1) {
            Flash::set('warning', 'Você deve alterar sua senha antes de continuar.');

            $this->redirect('/alterar-senha');
        }

        Flash::set('success', 'Login realizado com sucesso.');

        $this->redirect('/dashboard');
    }

    public function logout(): void
    {
        $userId = Auth::instance()->id();

        Auth::instance()->logout();

        Audit::record('auth.logout', 'user', $userId, ['ip' => client_ip()]);
        Flash::set('success', 'Você saiu da aplicação.');

        $this->redirect('/login');
    }

    public const MSG_INVALID_CREDENTIALS = 'E-mail ou senha inválidos.';

    /**
     * Conta inexistente, inativa, bloqueada ou senha errada recebem a MESMA
     * mensagem pública (anti-enumeração). O motivo real vai só para o log.
     * O throttle por e-mail/IP conta tentativas independentemente de a conta
     * existir, portanto sua mensagem não revela existência.
     */
    private function messageForStatus(string $status): string
    {
        return match ($status) {
            'rate_limited' => 'Muitas tentativas de login. Aguarde alguns minutos antes de tentar novamente.',
            default => self::MSG_INVALID_CREDENTIALS,
        };
    }
}