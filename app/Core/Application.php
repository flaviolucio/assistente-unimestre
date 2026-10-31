<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\Security\Audit;
use App\Services\Security\Auth;
use App\Support\Redirect;

final class Application
{
    /** Únicas rotas autenticadas liberadas enquanto must_change_password = 1. */
    public const PASSWORD_CHANGE_ALLOWED_PATHS = ['/alterar-senha', '/logout'];

    private Router $router;
    private string $currentPath = '';

    public function __construct()
    {
        date_default_timezone_set((string) config('app.timezone'));

        $this->router = new Router();
        $router = $this->router;

        require APP_PATH . '/routes/web.php';

        $this->registerMiddleware();
        $this->startSession();
    }

    public function run(): void
    {
        $basePath = rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        $this->currentPath = $this->router->pathFromUri($uri, $basePath);

        if ($method === 'POST' && !Csrf::validate()) {
            Logger::warning('security', 'Token CSRF inválido', [
                'path' => $this->currentPath,
                'ip' => client_ip(),
            ]);

            header('HTTP/1.1 419 Page Expired');
            require config('paths.views') . '/errors/419.php';

            return;
        }

        $this->router->dispatch($method, $this->currentPath);
    }

    private function registerMiddleware(): void
    {
        $self = $this;

        $this->router->middleware('auth', static function () use ($self): bool {
            return $self->handleAuthMiddleware();
        });

        $this->router->middleware('permission', static function (?string $permission = null) use ($self): bool {
            return $self->handlePermissionMiddleware((string) $permission);
        });
    }

    private function handleAuthMiddleware(): bool
    {
        if (!Auth::instance()->check()) {
            if ($this->isApiPath()) {
                $this->denyJson(401, 'unauthenticated', 'Faça login para continuar.');

                return false;
            }

            Flash::set('error', 'Faça login para continuar.');
            $this->redirect('/login');

            return false;
        }

        if (
            Auth::instance()->mustChangePassword()
            && !in_array($this->currentPath, self::PASSWORD_CHANGE_ALLOWED_PATHS, true)
        ) {
            Logger::warning('security', 'Acesso bloqueado até troca obrigatória de senha', [
                'user_id' => Auth::instance()->id(),
                'path' => $this->currentPath,
            ]);

            if ($this->isApiPath()) {
                $this->denyJson(403, 'password_change_required', 'Você deve alterar sua senha antes de continuar.');

                return false;
            }

            Flash::set('warning', 'Você deve alterar sua senha antes de continuar.');
            $this->redirect('/alterar-senha');

            return false;
        }

        return true;
    }

    private function handlePermissionMiddleware(string $permission): bool
    {
        if (Auth::instance()->can($permission)) {
            return true;
        }

        Logger::warning('security', 'Acesso negado', [
            'permission' => $permission,
            'user_id' => Auth::instance()->id(),
            'path' => $this->currentPath,
            'ip' => client_ip(),
        ]);

        Audit::record('access.denied', 'route', null, [
            'permission' => $permission,
            'path' => $this->currentPath,
        ]);

        if ($this->isApiPath()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['error' => true, 'message' => 'Acesso negado.']);

            return false;
        }

        http_response_code(403);
        require config('paths.views') . '/errors/403.php';

        return false;
    }

    private function isApiPath(): bool
    {
        return str_starts_with($this->currentPath, '/api/');
    }

    private function denyJson(int $status, string $error, string $message): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode(['ok' => false, 'error' => $error, 'message' => $message], JSON_UNESCAPED_UNICODE);
    }

    private function redirect(string $path): void
    {
        header('Location: ' . Redirect::url($path));
        exit;
    }

    private function startSession(): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = str_starts_with((string) config('app.url'), 'https://');

        session_name((string) config('session.name'));
        session_set_cookie_params([
            'lifetime' => (int) config('session.lifetime'),
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}