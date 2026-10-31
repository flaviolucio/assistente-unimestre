<?php

declare(strict_types=1);

namespace App\Services\Security;

final class PasswordPolicy
{
    public static function minLength(): int
    {
        return (int) config('auth.password_min_length', 8);
    }

    public static function validate(string $password): ?string
    {
        if (strlen($password) < self::minLength()) {
            return 'A senha deve ter pelo menos ' . self::minLength() . ' caracteres.';
        }

        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return 'A senha deve conter letras e números.';
        }

        return null;
    }
}