<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Repositories\UserRepository;
use App\Services\Security\Auth;
use App\Services\Security\Permissions;
use App\Services\UserService;

final class UserController extends Controller
{
    private UserService $service;

    public function __construct()
    {
        $this->service = new UserService();
    }

    public function index(): void
    {
        $filters = array_filter([
            'search' => trim((string) ($_GET['search'] ?? '')),
            'role' => trim((string) ($_GET['role'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
        ], static fn (string $value): bool => $value !== '');

        $this->render('users/index', [
            'appTitle' => 'Usuários',
            'users' => $this->service->list($filters),
            'filters' => $filters,
            'roles' => Permissions::roles(),
        ]);
    }

    public function createForm(): void
    {
        $this->render('users/form', [
            'appTitle' => 'Novo usuário',
            'user' => null,
            'old' => [],
            'errors' => [],
            'roles' => Permissions::roles(),
        ]);
    }

    public function store(): void
    {
        $data = $this->dataForCreate();

        $errors = $this->service->validateCreate($data);

        if ($errors !== []) {
            $this->render('users/form', [
                'appTitle' => 'Novo usuário',
                'user' => null,
                'old' => $data,
                'errors' => $errors,
                'roles' => Permissions::roles(),
            ]);

            return;
        }

        $this->service->create($data, Auth::instance()->id());

        Flash::set('success', 'Usuário criado. O primeiro acesso exigirá troca de senha.');

        $this->redirect('/usuarios');
    }

    public function editForm(int $id): void
    {
        $user = $this->service->find($id);

        if ($user === null) {
            Flash::set('error', 'Usuário não encontrado.');

            $this->redirect('/usuarios');
        }

        $this->render('users/form', [
            'appTitle' => 'Editar usuário',
            'user' => $user,
            'old' => $user,
            'errors' => [],
            'roles' => Permissions::roles(),
        ]);
    }

    public function update(int $id): void
    {
        $user = $this->service->find($id);

        if ($user === null) {
            Flash::set('error', 'Usuário não encontrado.');

            $this->redirect('/usuarios');
        }

        $data = $this->dataForUpdate();

        $errors = $this->service->validateUpdate($data);

        if ($errors !== []) {
            $this->render('users/form', [
                'appTitle' => 'Editar usuário',
                'user' => $user,
                'old' => $data,
                'errors' => $errors,
                'roles' => Permissions::roles(),
            ]);

            return;
        }

        $result = $this->service->update($id, $data, Auth::instance()->id());

        if (isset($result['error'])) {
            Flash::set('error', $result['error']);

            $this->redirect('/usuarios/' . $id . '/editar');
        }

        Flash::set('success', 'Usuário atualizado.');

        $this->redirect('/usuarios');
    }

    public function resetPassword(int $id): void
    {
        $result = $this->service->resetPassword(
            $id,
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_confirmation'] ?? ''),
            Auth::instance()->id()
        );

        if (isset($result['error'])) {
            Flash::set('error', $result['error']);
        } else {
            Flash::set('success', 'Senha redefinida. O usuário precisará trocá-la no próximo login.');
        }

        $this->redirect('/usuarios');
    }

    public function toggleStatus(int $id): void
    {
        $result = $this->service->toggleStatus($id, Auth::instance()->id());

        if (isset($result['error'])) {
            Flash::set('error', $result['error']);
        } else {
            Flash::set('success', 'Situação do usuário atualizada.');
        }

        $this->redirect('/usuarios');
    }

    public function destroy(int $id): void
    {
        $result = $this->service->destroy($id, Auth::instance()->id());

        if (isset($result['error'])) {
            Flash::set('error', $result['error']);
        } else {
            Flash::set('success', 'Usuário excluído.');
        }

        $this->redirect('/usuarios');
    }

    private function dataForCreate(): array
    {
        return [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'role' => (string) ($_POST['role'] ?? 'usuario'),
            'status' => (string) ($_POST['status'] ?? 'active'),
            'password' => (string) ($_POST['password'] ?? ''),
            'password_confirmation' => (string) ($_POST['password_confirmation'] ?? ''),
        ];
    }

    private function dataForUpdate(): array
    {
        return [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'role' => (string) ($_POST['role'] ?? 'usuario'),
            'status' => (string) ($_POST['status'] ?? 'active'),
        ];
    }
}