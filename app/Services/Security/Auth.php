<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Flash;
use App\Core\Logger;
use App\Repositories\UserRepository;
use RuntimeException;

final class Auth
{
    private const KEY_USER_ID = '_auth_user_id';
    private const KEY_LAST_ACTIVITY = '_auth_last_activity';

    private static ?Auth $instance = null;

    private ?array $cachedUser = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function id(): ?int
    {
        $id = $_SESSION[self::KEY_USER_ID] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public function user(): ?array
    {
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        if ($this->cachedUser === null) {
            $this->cachedUser = (new UserRepository())->findActive($id);
        }

        return $this->cachedUser;
    }

    public function check(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $this->refreshIdleTimeout();
    }

    public function role(): ?string
    {
        return $this->user()['role'] ?? null;
    }

    public function can(string $permission): bool
    {
        $role = $this->role();

        if ($role === null) {
            return false;
        }

        return Permissions::userCan($role, $permission);
    }

    public function mustChangePassword(): bool
    {
        $user = $this->user();

        return $user !== null && (int) $user['must_change_password'] === 1;
    }

    public function attempt(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));

        if ($email === '' || $password === '') {
            return ['status' => 'invalid', 'user' => null];
        }

        $user = (new UserRepository())->findByEmail($email);

        // Custo constante: sempre executa um password_verify, mesmo quando a
        // conta não existe/está inativa/bloqueada, para não vazar existência
        // pelo tempo de resposta.
        $passwordOk = password_verify($password, $user['password_hash'] ?? self::dummyHash());

        if ($user !== null && $user['status'] !== 'active') {
            $this->logDeniedReason($email, 'inactive', $user);

            return ['status' => 'inactive', 'user' => $user];
        }

        if ($user !== null && (new UserRepository())->isLocked((int) $user['id'])) {
            $this->logDeniedReason($email, 'locked', $user);

            return ['status' => 'locked', 'user' => $user];
        }

        if ($this->isRateLimited($email)) {
            $this->logDeniedReason($email, 'rate_limited', $user);

            return ['status' => 'rate_limited', 'user' => $user];
        }

        if ($user !== null && $passwordOk) {
            return ['status' => 'ok', 'user' => $user];
        }

        $this->logDeniedReason($email, $user === null ? 'user_not_found' : 'invalid_password', $user);
        $this->registerFailure($email, $user);

        return ['status' => 'invalid', 'user' => $user];
    }

    public function loginUser(array $user): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        session_regenerate_id(true);

        $_SESSION[self::KEY_USER_ID] = (int) $user['id'];
        $_SESSION[self::KEY_LAST_ACTIVITY] = time();

        $this->cachedUser = $user;

        (new UserRepository())->recordSuccessfulLogin((int) $user['id']);
    }

    public function logout(): void
    {
        foreach (array_keys($_SESSION) as $key) {
            unset($_SESSION[$key]);
        }

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        $this->cachedUser = null;
    }

    public function isLastActiveAdmin(int $userId): bool
    {
        return (new UserRepository())->isLastActiveAdmin($userId);
    }

    public function requireAdmin(): void
    {
        if (!$this->can('users.manage')) {
            throw new RuntimeException('Permissão insuficiente.');
        }
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }

    /** Motivo real da recusa: somente no log interno, nunca na resposta. */
    private function logDeniedReason(string $email, string $reason, ?array $user): void
    {
        Logger::info('security', 'Login recusado', [
            'reason' => $reason,
            'email' => $email,
            'user_id' => $user['id'] ?? null,
            'ip' => client_ip(),
        ]);
    }

    private function isRateLimited(string $email): bool
    {
        $repository = new UserRepository();
        $max = (int) config('auth.max_attempts', 5);
        $windowMinutes = (int) config('auth.attempt_window_minutes', 15);

        $emailCount = $repository->countRecentFailures($email, $windowMinutes);
        $ipCount = $repository->countRecentFailuresByIp(client_ip(), $windowMinutes);

        return $emailCount >= $max || $ipCount >= $max;
    }

    private function registerFailure(string $email, ?array $user): void
    {
        $repository = new UserRepository();
        $max = (int) config('auth.max_attempts', 5);
        $lockoutMinutes = (int) config('auth.lockout_minutes', 15);

        $repository->recordFailedAttempt($email, client_ip());

        Logger::warning('security', 'Falha de autenticação', [
            'email' => $email,
            'ip' => client_ip(),
            'user_id' => $user['id'] ?? null,
        ]);

        Audit::record('auth.login.failed', 'user', $user['id'] ?? null, [
            'email' => $email,
            'ip' => client_ip(),
        ]);

        if ($user !== null) {
            $failures = (int) $user['failed_login_attempts'] + 1;
            $repository->incrementFailedAttempts((int) $user['id']);

            if ($failures >= $max) {
                $repository->lockUser((int) $user['id'], $lockoutMinutes);
                Logger::warning('security', 'Conta bloqueada temporariamente', [
                    'user_id' => $user['id'],
                    'minutes' => $lockoutMinutes,
                ]);
                Audit::record('auth.locked', 'user', (int) $user['id'], [
                    'minutes' => $lockoutMinutes,
                    'ip' => client_ip(),
                ]);
            }
        }
    }

    private function refreshIdleTimeout(): bool
    {
        $lifetime = (int) config('session.lifetime', 7200);
        $last = $_SESSION[self::KEY_LAST_ACTIVITY] ?? null;

        if (!is_numeric($last)) {
            $_SESSION[self::KEY_LAST_ACTIVITY] = time();

            return true;
        }

        if (time() - (int) $last > $lifetime) {
            $this->logout();
            Flash::set('error', 'Sessão expirada. Faça login novamente.');

            return false;
        }

        $_SESSION[self::KEY_LAST_ACTIVITY] = time();

        return true;
    }
}