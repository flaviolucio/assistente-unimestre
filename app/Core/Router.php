<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Router
{
    private array $routesByMethod = [];
    private array $middleware = [];

    public function add(string $method, string $path, string|array|callable $handler, array $middleware = []): void
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');

        $entry = $this->compile($path);
        $entry['handler'] = $handler;
        $entry['middleware'] = $middleware;

        $this->routesByMethod[$method][] = $entry;
    }

    public function get(string $path, string|array|callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, string|array|callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function middleware(string $name, callable $handler): void
    {
        $this->middleware[$name] = $handler;
    }

    public function match(string $method, string $path): string|array|callable|null
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');

        foreach ($this->routesByMethod[$method] ?? [] as $entry) {
            if ($entry['exact']) {
                if ($entry['path'] === $path) {
                    return $entry['handler'];
                }

                continue;
            }

            if (preg_match($entry['regex'], $path) === 1) {
                return $entry['handler'];
            }
        }

        return null;
    }

    public function locate(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');

        foreach ($this->routesByMethod[$method] ?? [] as $entry) {
            if ($entry['exact']) {
                if ($entry['path'] === $path) {
                    return ['handler' => $entry['handler'], 'middleware' => $entry['middleware'], 'params' => []];
                }

                continue;
            }

            if (preg_match($entry['regex'], $path, $matches) === 1) {
                $params = [];

foreach ($entry['params'] as $name) {
            $params[$name] = is_numeric($matches[$name] ?? '') ? (int) $matches[$name] : ($matches[$name] ?? null);
        }

                return ['handler' => $entry['handler'], 'middleware' => $entry['middleware'], 'params' => $params];
            }
        }

        return null;
    }

    public function dispatch(string $method, string $path): void
    {
        $route = $this->locate($method, $path);

        if ($route === null) {
            http_response_code(404);
            require config('paths.views') . '/errors/404.php';

            return;
        }

        foreach ($route['middleware'] as $spec) {
            $parts = explode(':', (string) $spec, 2);
            $name = $parts[0];
            $param = $parts[1] ?? null;

            if ($name === '') {
                continue;
            }

            if (!isset($this->middleware[$name])) {
                throw new RuntimeException('Middleware não registrado: ' . $name);
            }

            if (($this->middleware[$name])($param) === false) {
                return;
            }
        }

        $handler = $route['handler'];
        $params = $route['params'];

        if (is_string($handler) && str_contains($handler, '@')) {
            [$controller, $action] = explode('@', $handler, 2);
            $class = 'App\\Controllers\\' . $controller;

            if (!class_exists($class)) {
                throw new RuntimeException('Controller não encontrado: ' . $class);
            }

            $instance = new $class();

            if ($params === []) {
                $instance->{$action}();
            } else {
                $instance->{$action}(...array_values($params));
            }

            return;
        }

        if (is_array($handler) && is_callable($handler)) {
            $handler(...array_values($params));

            return;
        }

        if (is_callable($handler)) {
            $handler(...array_values($params));

            return;
        }

        throw new RuntimeException('Rota inválida registrada para: ' . $method . ' ' . $path);
    }

    public function pathFromUri(string $uri, string $basePath): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        if ($basePath !== '' && $basePath !== '/') {
            if (str_starts_with($path, $basePath)) {
                $path = substr($path, strlen($basePath));
            }
        }

        return '/' . trim($path, '/');
    }

    private function compile(string $path): array
    {
        $paramNames = [];
        $pattern = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $matches) use (&$paramNames): string {
                $paramNames[] = $matches[1];

                return '(?P<' . $matches[1] . '>[^/]+)';
            },
            $path
        );

        return [
            'path' => $path,
            'exact' => $paramNames === [],
            'regex' => $paramNames === [] ? null : '~^' . $pattern . '$~',
            'params' => $paramNames,
        ];
    }
}