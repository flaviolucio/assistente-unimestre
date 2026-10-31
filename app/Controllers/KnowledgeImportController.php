<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Services\Knowledge\Import\OfficialImportService;
use App\Services\Security\Auth;

/**
 * Importação em massa da documentação oficial a partir de arquivos locais.
 * Somente admin (permission knowledge.bulk_import). CSRF é validado
 * globalmente para todo POST (inclusive as chamadas AJAX).
 */
final class KnowledgeImportController extends Controller
{
    private OfficialImportService $service;

    public function __construct(?OfficialImportService $service = null)
    {
        $this->service = $service ?? new OfficialImportService();
    }

    public function index(): void
    {
        // Lote explícito (vindo do redirect da análise) tem prioridade sobre "o último".
        $batch = (string) ($_GET['lote'] ?? '');
        $state = ($batch !== '' ? $this->service->state($batch) : null) ?? $this->service->latestState();
        // Upload atual: o da URL (após o envio) > o do lote exibido > o mais recente. Sempre validado.
        $uploads = $this->service->uploads();
        $upload = (string) ($_GET['upload'] ?? ($state['upload_id'] ?? ($uploads[0] ?? '')));
        $upload = in_array($upload, $uploads, true) ? $upload : ($uploads[0] ?? '');

        $this->render('knowledge/official_import', [
            'appTitle' => 'Importação oficial UNIMESTRE',
            'state' => $state,
            'currentUpload' => $upload,
            'uploads' => $uploads,
            'importDir' => $this->service->importDir(),
            'actions' => OfficialImportService::ACTIONS,
            'formatLabels' => OfficialImportService::FORMAT_LABELS,
            'batchSize' => (int) config('knowledge.bulk_import.batch_size', 25),
            'allowedHosts' => (array) config('knowledge.bulk_import.allowed_hosts', []),
        ]);
    }

    public function upload(): void
    {
        $file = $_FILES['package'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            Flash::set('error', 'Envie um arquivo .zip válido.');
            $this->redirect('/admin/knowledge/importacao-oficial');
        }

        if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'zip') {
            Flash::set('error', 'Somente arquivos .zip são aceitos.');
            $this->redirect('/admin/knowledge/importacao-oficial');
        }

        $result = $this->service->uploadZip((string) $file['tmp_name'], (string) $file['name']);

        Flash::set(
            $result['status'] === 'ok' ? 'success' : 'error',
            $result['status'] === 'ok'
                ? 'ZIP recebido: ' . $result['extracted'] . ' arquivo(s) extraído(s), ' . $result['skipped'] . ' ignorado(s). Agora clique em "Analisar base".'
                : (string) $result['message']
        );

        $this->redirect('/admin/knowledge/importacao-oficial' . ($result['status'] === 'ok' ? '?upload=' . rawurlencode((string) $result['upload_id']) : ''));
    }

    /** DRY RUN: nenhum documento é gravado. */
    public function analyze(): void
    {
        $mode = (string) ($_POST['dokuwiki'] ?? 'auto');
        $upload = (string) ($_POST['upload_id'] ?? '');

        // Nunca diretório arbitrário: só um upload_id válido que exista dentro da pasta de importação.
        if ($upload !== '' && $this->service->uploadPath($upload) === null) {
            Flash::set('error', 'Upload inválido.');
            $this->redirect('/admin/knowledge/importacao-oficial');
        }

        $state = $this->service->analyze(Auth::instance()->id(), ['dokuwiki' => $mode, 'upload_id' => $upload !== '' ? $upload : null]);
        $s = $state['summary'];

        Flash::set('success', 'Análise concluída (nada foi importado) do upload ' . ($state['upload_id'] ?? 'pasta local') . ': ' . $s['files'] . ' arquivo(s), '
            . $s['new'] . ' novo(s), ' . $s['exists'] . ' já existente(s), ' . $s['update'] . ' com atualização disponível.');

        $this->redirect('/admin/knowledge/importacao-oficial?lote=' . rawurlencode((string) $state['batch_id']));
    }

    /** Prévia da extração: o texto exato que iria ao RAG. Não grava nada. */
    public function preview(string $batch, int $item): void
    {
        $preview = $this->service->preview($batch, $item);

        if ($preview === null) {
            Flash::set('error', 'Item não encontrado neste lote.');
            $this->redirect('/admin/knowledge/importacao-oficial');
        }

        $this->render('knowledge/official_import_preview', [
            'appTitle' => 'Prévia da extração',
            'batchId' => $batch,
            'preview' => $preview,
            'actions' => OfficialImportService::ACTIONS,
            'formatLabels' => OfficialImportService::FORMAT_LABELS,
        ]);
    }

    public function queue(string $batch): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : $_POST;
        $ids = ($input['mode'] ?? '') === 'selected' ? array_map('intval', (array) ($input['ids'] ?? [])) : null;

        if ($ids === []) {
            $this->json(['ok' => false, 'message' => 'Nenhum documento selecionado.'], 422);
        }

        $this->respond(fn () => $this->service->queue($batch, $ids, Auth::instance()->id()));
    }

    public function batch(string $batch): void
    {
        $this->respond(fn () => $this->service->importNext($batch, null, Auth::instance()->id()));
    }

    private function respond(callable $fn): void
    {
        try {
            $state = $fn();
        } catch (\RuntimeException $exception) {
            $this->json(['ok' => false, 'message' => $exception->getMessage()], 409);
        }

        $items = array_map(static fn (array $i): array => [
            'id' => $i['id'],
            'import_status' => $i['import_status'],
            'document_id' => $i['document_id'],
            'chunks' => $i['chunks'],
            'error' => $i['error'],
        ], array_values(array_filter($state['items'], static fn (array $i): bool => $i['import_status'] !== 'none')));

        $this->json(['ok' => true, 'summary' => $state['summary'], 'items' => $items]);
    }

    private function json(array $data, int $status = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
