<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Repositories\InternalSolutionRepository;
use App\Services\Security\Audit;

final class InternalSolutionService
{
    private InternalSolutionRepository $repository;

    public function __construct(?InternalSolutionRepository $repository = null)
    {
        $this->repository = $repository ?? new InternalSolutionRepository();
    }

    public function categories(): array
    {
        $categories = (array) config('knowledge.categories', []);

        return in_array('Outros', $categories, true) ? $categories : array_merge($categories, ['Outros']);
    }

    public function normalizeTags(string $tags): string
    {
        $parts = preg_split('/[,;]+/', $tags) ?? [];

        $normalized = [];

        foreach ($parts as $part) {
            $tag = mb_strtolower(trim($part));
            $tag = preg_replace('/\s+/u', ' ', $tag) ?? $tag;

            if ($tag !== '') {
                $normalized[mb_strtolower($tag)] = $tag;
            }
        }

        ksort($normalized);

        return implode(', ', array_values($normalized));
    }

    public function validate(array $data): array
    {
        $errors = [];

        if (trim((string) ($data['title'] ?? '')) === '') {
            $errors['title'] = 'Informe o título da solução.';
        }

        if (trim((string) ($data['problem'] ?? '')) === '') {
            $errors['problem'] = 'Descreva o problema.';
        }

        if (trim((string) ($data['solution'] ?? '')) === '') {
            $errors['solution'] = 'Descreva a solução.';
        }

        if (trim((string) ($data['category'] ?? '')) === '') {
            $errors['category'] = 'Selecione a categoria.';
        }

        if (!in_array($data['status'] ?? null, ['active', 'inactive'], true)) {
            $errors['status'] = 'Status inválido.';
        }

        return $errors;
    }

    public function create(array $data, ?int $userId): array
    {
        $errors = $this->validate($data);

        if ($errors !== []) {
            return ['status' => 'invalid', 'errors' => $errors];
        }

        $tags = $this->normalizeTags((string) ($data['tags'] ?? ''));

        $id = $this->repository->create([
            'title' => trim((string) $data['title']),
            'problem' => trim((string) $data['problem']),
            'solution' => trim((string) $data['solution']),
            'category' => (string) $data['category'],
            'tags' => $tags,
            'created_by' => $userId,
            'status' => $data['status'],
        ]);

        Audit::record('solution.created', 'internal_solution', $id, [
            'solution_id' => $id,
            'category' => $data['category'],
        ]);

        return ['status' => 'created', 'solution_id' => $id];
    }

    public function update(int $id, array $data, ?int $userId): array
    {
        $existing = $this->repository->find($id);

        if ($existing === null) {
            return ['status' => 'invalid', 'errors' => ['id' => 'Solução não encontrada.']];
        }

        $errors = $this->validate($data);

        if ($errors !== []) {
            return ['status' => 'invalid', 'errors' => $errors];
        }

        $tags = $this->normalizeTags((string) ($data['tags'] ?? ''));

        $this->repository->update($id, [
            'title' => trim((string) $data['title']),
            'problem' => trim((string) $data['problem']),
            'solution' => trim((string) $data['solution']),
            'category' => (string) $data['category'],
            'tags' => $tags,
            'status' => $data['status'],
        ]);

        Audit::record('solution.updated', 'internal_solution', $id, [
            'solution_id' => $id,
            'category' => $data['category'],
        ]);

        if ($existing['status'] !== $data['status']) {
            Audit::record(
                $data['status'] === 'active' ? 'solution.activated' : 'solution.deactivated',
                'internal_solution',
                $id,
                ['solution_id' => $id]
            );
        }

        return ['status' => 'updated', 'solution_id' => $id];
    }

    public function toggleStatus(int $id, ?int $userId): array
    {
        $existing = $this->repository->find($id);

        if ($existing === null) {
            return ['error' => 'Solução não encontrada.'];
        }

        $newStatus = $existing['status'] === 'active' ? 'inactive' : 'active';

        $this->repository->updateStatus($id, $newStatus);

        Audit::record(
            $newStatus === 'active' ? 'solution.activated' : 'solution.deactivated',
            'internal_solution',
            $id,
            ['solution_id' => $id]
        );

        return ['status' => $newStatus];
    }

    public function repository(): InternalSolutionRepository
    {
        return $this->repository;
    }
}