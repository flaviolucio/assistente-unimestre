<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Security\Auth;

final class HomeController extends Controller
{
    public function index(): void
    {
        // Página pública: o estado técnico fica restrito a /diagnostico.
        $this->render('home', [
            'appName' => config('app.name'),
            'appEnv' => '',
            'isLoggedIn' => Auth::instance()->check(),
        ]);
    }
}
