<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = APP_PATH . DIRECTORY_SEPARATOR . 'app'
        . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative)
        . '.php';

    if (is_file($path)) {
        require $path;
    }
});