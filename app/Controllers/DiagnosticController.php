<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DiagnosticService;

final class DiagnosticController extends Controller
{
    public function index(): void
    {
        $diagnostic = (new DiagnosticService())->run();

        $this->render('diagnostic', [
            'appName' => config('app.name'),
            'diagnostic' => $diagnostic,
        ]);
    }

    public function api(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode((new DiagnosticService())->run(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}