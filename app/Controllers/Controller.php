<?php

declare(strict_types=1);

namespace App\Controllers;

abstract class Controller
{
    protected function render(string $view, array $data = []): void
    {
        $appUrlPath = rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');

        $data = array_merge([
            'appName' => config('app.name'),
            'appEnv' => config('app.env'),
            'appUrl' => config('app.url'),
            'basePublic' => $appUrlPath . '/',
            'flashMessages' => flash(),
        ], $data);

        extract($data, EXTR_SKIP);

        ob_start();
        require config('paths.views') . '/' . $view . '.php';
        $content = ob_get_clean();

        require config('paths.views') . '/layouts/base.php';
    }

    protected function redirect(string $path): void
    {
        header('Location: ' . \App\Support\Redirect::url($path));
        exit;
    }
}