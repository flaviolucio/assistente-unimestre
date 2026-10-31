<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Services\Knowledge\KnowledgeService;
use App\Services\Security\Auth;

final class KnowledgeController extends Controller
{
    private KnowledgeService $service;

    public function __construct()
    {
        $this->service = new KnowledgeService();
    }

    public function index(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $filters = array_filter([
            'search' => trim((string) ($_GET['search'] ?? '')),
            'category' => trim((string) ($_GET['category'] ?? '')),
            'subcategory' => trim((string) ($_GET['subcategory'] ?? '')),
            'origin' => trim((string) ($_GET['origin'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
        ], static fn (string $value): bool => $value !== '');

        $perPage = (int) config('knowledge.pagination.per_page', 20);
        $result = $this->service->document()->paginate($filters, $page, $perPage);

        $this->render('knowledge/index', [
            'appTitle' => 'Base de Conhecimento',
            'documents' => $result['items'],
            'pagination' => $result,
            'filters' => $filters,
            'categories' => $this->service->categories(),
            'origins' => $this->service->origins(),
            'stats' => $this->service->document()->counts(),
            'canManage' => Auth::instance()->can('documents.manage'),
        ]);
    }

    public function show(int $id): void
    {
        $document = $this->service->document()->find($id);

        if ($document === null) {
            Flash::set('error', 'Documento não encontrado.');

            $this->redirect('/knowledge');
        }

        $this->render('knowledge/view', [
            'appTitle' => $document['title'],
            'document' => $document,
            'canManage' => Auth::instance()->can('documents.manage'),
        ]);
    }

    public function importForm(): void
    {
        $old = [];

        if (Flash::old('duplicate_document_id') !== '') {
            $old['duplicate_document_id'] = Flash::old('duplicate_document_id');
        }

        $this->render('knowledge/import', [
            'appTitle' => 'Importar documento',
            'old' => $old,
            'errors' => [],
            'categories' => $this->service->categories(),
            'origins' => $this->service->origins(),
        ]);
    }

    public function import(): void
    {
        $data = $this->inputForImport();
        $file = $_FILES['file'] ?? UPLOAD_ERR_NO_FILE;
        $hasFile = isset($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE;

        $result = $hasFile
            ? $this->service->importFromFile($data, $file, Auth::instance()->id())
            : $this->service->importManual($data, Auth::instance()->id());

        if ($result['status'] === 'invalid') {
            $this->render('knowledge/import', [
                'appTitle' => 'Importar documento',
                'old' => $data,
                'errors' => $result['errors'],
                'categories' => $this->service->categories(),
                'origins' => $this->service->origins(),
            ]);

            return;
        }

        if ($result['status'] === KnowledgeService::STATUS_ERROR) {
            Flash::set('error', $result['message'] ?? 'Falha ao importar o documento.');

            $this->redirect('/admin/knowledge/import');
        }

        $id = (int) ($result['document_id'] ?? 0);

        if ($result['status'] === KnowledgeService::STATUS_CREATED) {
            Flash::set('success', 'Documento importado com sucesso.');

            $this->redirect('/knowledge/' . $id);
        }

        Flash::set('warning', $result['message'] ?? 'Duplicidade detectada.');

        if ($id > 0) {
            Flash::input('duplicate_document_id', (string) $id);
        }

        $this->redirect('/admin/knowledge/import');
    }

    public function editForm(int $id): void
    {
        $document = $this->service->document()->find($id);

        if ($document === null) {
            Flash::set('error', 'Documento não encontrado.');

            $this->redirect('/knowledge');
        }

        $this->render('knowledge/edit', [
            'appTitle' => 'Editar documento',
            'document' => $document,
            'old' => $document,
            'errors' => [],
            'categories' => $this->service->categories(),
            'origins' => $this->service->origins(),
        ]);
    }

    public function update(int $id): void
    {
        $data = [
            'title' => trim((string) ($_POST['title'] ?? '')),
            'url' => trim((string) ($_POST['url'] ?? '')),
            'category' => (string) ($_POST['category'] ?? ''),
            'subcategory' => trim((string) ($_POST['subcategory'] ?? '')),
            'content' => (string) ($_POST['content'] ?? ''),
            'origin' => (string) ($_POST['origin'] ?? ''),
            'status' => (string) ($_POST['status'] ?? 'active'),
        ];

        $result = $this->service->update($id, $data, Auth::instance()->id());

        if ($result['status'] === 'invalid') {
            $document = $this->service->document()->find($id);

            $this->render('knowledge/edit', [
                'appTitle' => 'Editar documento',
                'document' => $document,
                'old' => $data,
                'errors' => $result['errors'],
                'categories' => $this->service->categories(),
                'origins' => $this->service->origins(),
            ]);

            return;
        }

        if ($result['status'] === KnowledgeService::STATUS_ERROR) {
            Flash::set('error', $result['message'] ?? 'Falha ao atualizar o documento.');

            $this->redirect('/admin/knowledge/' . $id . '/edit');
        }

        if (!empty($result['origin_changed'])) {
            Flash::set('warning', 'A origem do documento foi alterada e registrada em auditoria.');
        }

        Flash::set('success', 'Documento atualizado.');

        $this->redirect('/knowledge/' . $id);
    }

    public function toggleStatus(int $id): void
    {
        $result = $this->service->toggleStatus($id, Auth::instance()->id());

        if (isset($result['error'])) {
            Flash::set('error', $result['error']);
        } else {
            Flash::set('success', $result['status'] === 'active' ? 'Documento ativado.' : 'Documento desativado.');
        }

        $this->redirect('/knowledge');
    }

    private function inputForImport(): array
    {
        return [
            'title' => trim((string) ($_POST['title'] ?? '')),
            'url' => trim((string) ($_POST['url'] ?? '')),
            'category' => (string) ($_POST['category'] ?? ''),
            'subcategory' => trim((string) ($_POST['subcategory'] ?? '')),
            'content' => (string) ($_POST['content'] ?? ''),
            'origin' => (string) ($_POST['origin'] ?? ''),
            'status' => (string) ($_POST['status'] ?? 'active'),
        ];
    }
}