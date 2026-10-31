<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\InternalSolutionRepository;
use App\Repositories\KnowledgeDocumentRepository;
use App\Repositories\UserRepository;
use App\Services\Security\Auth;
use App\Services\Security\Permissions;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $user = Auth::instance()->user();

        if ($user === null) {
            $this->redirect('/login');
        }

        $repository = new UserRepository();

        $this->render('dashboard', [
            'appTitle' => 'Painel',
            'user' => $user,
            'permissions' => Permissions::permissionsFor($user['role']),
            'totalUsers' => $repository->total(),
            'activeUsers' => $repository->countByStatus('active'),
            'adminCount' => $repository->countByRole('admin'),
            'tecnicoCount' => $repository->countByRole('tecnico'),
            'knowledgeDocuments' => (new KnowledgeDocumentRepository())->counts(),
            'internalSolutions' => (new InternalSolutionRepository())->countActive(),
        ]);
    }
}