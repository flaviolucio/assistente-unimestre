<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Services\Knowledge\InternalSolutionService;
use App\Services\Security\Auth;

final class SolutionController extends Controller
{
    private InternalSolutionService $service;

    public function __construct()
    {
        $this->service = new InternalSolutionService();
    }

    public function index(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $filters = array_filter([
            'search' => trim((string) ($_GET['search'] ?? '')),
            'category' => trim((string) ($_GET['category'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
        ], static fn (string $value): bool => $value !== '');

        $perPage = (int) config('knowledge.pagination.per_page', 20);
        $result = $this->service->repository()->paginate($filters, $page, $perPage);

        $this->render('solutions/index', [
            'appTitle' => 'Soluções Internas',
            'solutions' => $result['items'],
            'pagination' => $result,
            'filters' => $filters,
            'categories' => $this->service->categories(),
            'canManage' => Auth::instance()->can('solutions.manage'),
        ]);
    }

    public function createForm(): void
    {
        $this->render('solutions/form', [
            'appTitle' => 'Nova solução interna',
            'solution' => null,
            'old' => [],
            'errors' => [],
            'categories' => $this->service->categories(),
        ]);
    }

    public function store(): void
    {
        $data = $this->input();
        $result = $this->service->create($data, Auth::instance()->id());

        if ($result['status'] === 'invalid') {
            $this->render('solutions/form', [
                'appTitle' => 'Nova solução interna',
                'solution' => null,
                'old' => $data,
                'errors' => $result['errors'],
                'categories' => $this->service->categories(),
            ]);

            return;
        }

        Flash::set('success', 'Solução interna cadastrada.');

        $this->redirect('/solutions');
    }

    public function editForm(int $id): void
    {
        $solution = $this->service->repository()->find($id);

        if ($solution === null) {
            Flash::set('error', 'Solução não encontrada.');

            $this->redirect('/solutions');
        }

        $this->render('solutions/form', [
            'appTitle' => 'Editar solução interna',
            'solution' => $solution,
            'old' => $solution,
            'errors' => [],
            'categories' => $this->service->categories(),
        ]);
    }

    public function update(int $id): void
    {
        $data = $this->input();
        $result = $this->service->update($id, $data, Auth::instance()->id());

        if ($result['status'] === 'invalid') {
            $solution = $this->service->repository()->find($id);

            $this->render('solutions/form', [
                'appTitle' => 'Editar solução interna',
                'solution' => $solution,
                'old' => $data,
                'errors' => $result['errors'],
                'categories' => $this->service->categories(),
            ]);

            return;
        }

        Flash::set('success', 'Solução interna atualizada.');

        $this->redirect('/solutions');
    }

    public function toggleStatus(int $id): void
    {
        $result = $this->service->toggleStatus($id, Auth::instance()->id());

        if (isset($result['error'])) {
            Flash::set('error', $result['error']);
        } else {
            Flash::set('success', $result['status'] === 'active' ? 'Solução ativada.' : 'Solução desativada.');
        }

        $this->redirect('/solutions');
    }

    private function input(): array
    {
        return [
            'title' => trim((string) ($_POST['title'] ?? '')),
            'problem' => trim((string) ($_POST['problem'] ?? '')),
            'solution' => trim((string) ($_POST['solution'] ?? '')),
            'category' => (string) ($_POST['category'] ?? ''),
            'tags' => (string) ($_POST['tags'] ?? ''),
            'status' => (string) ($_POST['status'] ?? 'active'),
        ];
    }
}