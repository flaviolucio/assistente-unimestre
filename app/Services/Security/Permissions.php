<?php

declare(strict_types=1);

namespace App\Services\Security;

final class Permissions
{
    private static ?array $matrix = null;

    public static function matrix(): array
    {
        if (self::$matrix === null) {
            $loaded = require APP_PATH . '/config/permissions.php';
            self::$matrix = is_array($loaded) ? $loaded : [];
        }

        return self::$matrix;
    }

    public static function roles(): array
    {
        return array_keys(self::matrix());
    }

    public static function userCan(string $role, string $permission): bool
    {
        $permissions = self::matrix()[$role] ?? [];

        if (in_array('*', $permissions, true)) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }

    public static function permissionsFor(string $role): array
    {
        $permissions = self::matrix()[$role] ?? [];

        if (in_array('*', $permissions, true)) {
            return array_values(array_filter(
                array_merge(...array_values(self::matrix())),
                static fn (string $p): bool => $p !== '*'
            ));
        }

        return $permissions;
    }
}