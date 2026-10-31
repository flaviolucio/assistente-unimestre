<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;
use App\Services\Security\Audit;
use App\Services\Security\Permissions;

final class UserService
{
    private const ALLOWED_ROLES = ['admin', 'tecnico', 'usuario'];
    private const ALLOWED_STATUSES = ['active', 'inactive'];

    private UserRepository $repository;

    public function __construct(?UserRepository $repository = null)
    {
        $this->repository = $repository ?? new UserRepository();
    }

    public function list(array $filters = []): array
    {
        return $this->repository->all($filters);
    }

    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    public function validateCreate(array $data): array
    {
        $errors = [];

        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = 'Informe o nome.';
        }

        $email = trim((string) ($data['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Informe um e-mail válido.';
        } elseif ($this->repository->emailExists($email)) {
            $errors['email'] = 'Este e-mail já está em uso.';
        }

        if (!in_array($data['role'] ?? null, self::ALLOWED_ROLES, true)) {
            $errors['role'] = 'Perfil inválido.';
        }

        if (!in_array($data['status'] ?? null, self::ALLOWED_STATUSES, true)) {
            $errors['status'] = 'Situação inválida.';
        }

        $passwordErrors = $this->validatePassword((string) ($data['password'] ?? ''), (string) ($data['password_confirmation'] ?? ''));

        return array_merge($errors, $passwordErrors);
    }

    public function validateUpdate(array $data): array
    {
        $errors = [];

        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = 'Informe o nome.';
        }

        $email = trim((string) ($data['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Informe um e-mail válido.';
        }

        if (!in_array($data['role'] ?? null, self::ALLOWED_ROLES, true)) {
            $errors['role'] = 'Perfil inválido.';
        }

        if (!in_array($data['status'] ?? null, self::ALLOWED_STATUSES, true)) {
            $errors['status'] = 'Situação inválida.';
        }

        return $errors;
    }

    public function create(array $data, ?int $byUserId): array
    {
        $email = mb_strtolower(trim((string) $data['email']));
        $passwordHash = password_hash((string) $data['password'], PASSWORD_DEFAULT);

        $id = $this->repository->create([
            'name' => trim((string) $data['name']),
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $data['role'],
            'status' => $data['status'],
            'must_change_password' => 1,
        ]);

        Audit::record('user.create', 'user', $id, [
            'name' => trim((string) $data['name']),
            'email' => $email,
            'role' => $data['role'],
            'by_user_id' => $byUserId,
        ]);

        return ['id' => $id];
    }

    public function update(int $id, array $data, ?int $byUserId): array
    {
        $target = $this->repository->find($id);

        if ($target === null) {
            return ['error' => 'Usuário não encontrado.'];
        }

        $guard = $this->guardManagement($target, $byUserId, [
            'role' => $data['role'] ?? $target['role'],
            'status' => $data['status'] ?? $target['status'],
        ]);

        if ($guard !== null) {
            return ['error' => $guard];
        }

        $this->repository->update($id, [
            'name' => trim((string) $data['name']),
            'email' => mb_strtolower(trim((string) $data['email'])),
            'role' => $data['role'],
            'status' => $data['status'],
        ]);

        Audit::record('user.update', 'user', $id, [
            'role' => $data['role'],
            'status' => $data['status'],
            'by_user_id' => $byUserId,
        ]);

        return [];
    }

    public function resetPassword(int $id, string $password, string $confirmation, ?int $byUserId): array
    {
        $target = $this->repository->find($id);

        if ($target === null) {
            return ['error' => 'Usuário não encontrado.'];
        }

        $errors = $this->validatePassword($password, $confirmation);

        if ($errors !== []) {
            return ['error' => implode(' ', $errors)];
        }

        $this->repository->updatePassword($id, password_hash($password, PASSWORD_DEFAULT), 1);

        Audit::record('user.reset_password', 'user', $id, ['by_user_id' => $byUserId]);

        return [];
    }

    public function toggleStatus(int $id, ?int $byUserId): array
    {
        $target = $this->repository->find($id);

        if ($target === null) {
            return ['error' => 'Usuário não encontrado.'];
        }

        $newStatus = $target['status'] === 'active' ? 'inactive' : 'active';
        $guard = $this->guardManagement($target, $byUserId, [
            'role' => $target['role'],
            'status' => $newStatus,
        ]);

        if ($guard !== null) {
            return ['error' => $guard];
        }

        $this->repository->update($id, ['status' => $newStatus]);

        if ($newStatus === 'inactive') {
            $this->repository->unlockUser($id);
        }

        Audit::record('user.toggle_status', 'user', $id, [
            'status' => $newStatus,
            'by_user_id' => $byUserId,
        ]);

        return [];
    }

    public function destroy(int $id, ?int $byUserId): array
    {
        $target = $this->repository->find($id);

        if ($target === null) {
            return ['error' => 'Usuário não encontrado.'];
        }

        $guard = $this->guardManagement($target, $byUserId, ['role' => $target['role'], 'status' => 'inactive']);

        if ($guard !== null) {
            return ['error' => $guard];
        }

        $this->repository->delete($id);

        Audit::record('user.delete', 'user', $id, [
            'name' => $target['name'],
            'email' => $target['email'],
            'by_user_id' => $byUserId,
        ]);

        return [];
    }

    public function lastActiveAdminMessage(): string
    {
        return 'Não é possível remover, desativar ou rebaixar o último administrador ativo.';
    }

    private function validatePassword(string $password, string $confirmation): array
    {
        $errors = [];

        $policyError = \App\Services\Security\PasswordPolicy::validate($password);

        if ($policyError !== null) {
            $errors['password'] = $policyError;
        }

        if ($password !== $confirmation) {
            $errors['password_confirmation'] = 'A confirmação da senha não confere.';
        }

        return $errors;
    }

    private function guardManagement(array $target, ?int $byUserId, array $proposed): ?string
    {
        if ($byUserId !== null && (int) $target['id'] === $byUserId) {
            if (
                ($proposed['role'] ?? $target['role']) !== $target['role']
                && $proposed['role'] !== 'admin'
            ) {
                return 'Você não pode rebaixar o próprio perfil.';
            }

            if (($proposed['status'] ?? $target['status']) !== 'active') {
                return 'Você não pode desativar a própria conta.';
            }
        }

        $isBecomingNotAdmin = ($proposed['role'] ?? $target['role']) !== 'admin';
        $isBecomingInactive = ($proposed['status'] ?? $target['status']) !== 'active';

        if (
            $target['role'] === 'admin'
            && $target['status'] === 'active'
            && ($isBecomingNotAdmin || $isBecomingInactive)
            && $this->repository->isLastActiveAdmin((int) $target['id'])
        ) {
            return $this->lastActiveAdminMessage();
        }

        return null;
    }
}