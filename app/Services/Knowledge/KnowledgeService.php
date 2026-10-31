<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\KnowledgeChunkRepository;
use App\Repositories\KnowledgeDocumentRepository;
use App\Repositories\KnowledgeSourceRepository;
use App\Services\RAG\ChunkingService;
use App\Services\Security\Audit;
use Throwable;

final class KnowledgeService
{
    public const STATUS_CREATED = 'created';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_VERSION_CONFLICT = 'version_conflict';
    public const STATUS_CONTENT_DUPLICATE = 'content_duplicate';
    public const STATUS_ERROR = 'error';

    private KnowledgeDocumentRepository $documents;
    private KnowledgeSourceRepository $sources;
    private KnowledgeChunkRepository $chunks;
    private ChunkingService $chunker;

    public function __construct(
        ?KnowledgeDocumentRepository $documents = null,
        ?KnowledgeSourceRepository $sources = null,
        ?KnowledgeChunkRepository $chunks = null,
        ?ChunkingService $chunker = null
    ) {
        $this->documents = $documents ?? new KnowledgeDocumentRepository();
        $this->sources = $sources ?? new KnowledgeSourceRepository();
        $this->chunks = $chunks ?? new KnowledgeChunkRepository();
        $this->chunker = $chunker ?? new ChunkingService();
    }

    public function categories(): array
    {
        return (array) config('knowledge.categories', []);
    }

    public function origins(): array
    {
        return (array) config('knowledge.origins', []);
    }

    public function sourceForType(string $type): ?array
    {
        return $this->sources->findByType($type);
    }

    public function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    public function contentHash(string $normalizedContent): string
    {
        return hash('sha256', $normalizedContent);
    }

    public function makeSlug(string $title): string
    {
        $slug = mb_strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? 'doc';
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'documento';
        }

        $slug .= '-' . substr((string) password_hash((string) random_int(1, PHP_INT_MAX), PASSWORD_BCRYPT), -8);

        return substr($slug, 0, 255);
    }

    public function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        $parsed = parse_url($url);
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    public function safeExternalUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    public function validateManualImport(array $data): array
    {
        $errors = [];

        if (trim((string) ($data['title'] ?? '')) === '') {
            $errors['title'] = 'Informe o título do documento.';
        }

        if (trim((string) ($data['content'] ?? '')) === '') {
            $errors['content'] = 'Informe o conteúdo do documento (ou envie um arquivo .txt/.md).';
        }

        $origin = $data['origin'] ?? '';

        if (!in_array($origin, array_keys($this->origins()), true)) {
            $errors['origin'] = 'Selecione a origem do documento.';
        }

        if (!in_array($data['category'] ?? null, $this->categories(), true)) {
            $errors['category'] = 'Selecione uma categoria válida.';
        }

        if (!in_array($data['status'] ?? null, ['active', 'inactive'], true)) {
            $errors['status'] = 'Status inválido.';
        }

        $url = trim((string) ($data['url'] ?? ''));

        if ($url !== '' && !$this->safeExternalUrl($this->normalizeUrl($url))) {
            $errors['url'] = 'URL inválida. Utilize http:// ou https://.';
        }

        return $errors;
    }

    public function validateUploadedFile(array $file): array
    {
        $errors = [];
        $maxSize = (int) config('knowledge.upload.max_size', 2 * 1024 * 1024);

        if (!isset($file['error']) || !is_int($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return $errors;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors['file'] = 'Falha no upload do arquivo (código ' . $file['error'] . ').';

            return $errors;
        }

        if ($file['size'] > $maxSize) {
            $errors['file'] = 'O arquivo excede o tamanho máximo de ' . (int) ($maxSize / 1024 / 1024) . ' MB.';

            return $errors;
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $allowed = (array) config('knowledge.upload.allowed_extensions', []);

        if (!in_array($extension, $allowed, true)) {
            $errors['file'] = 'Extensão não permitida. Envie arquivos .txt ou .md.';

            return $errors;
        }

        $tmpPath = $file['tmp_name'];

        if (!is_uploaded_file($tmpPath) && !is_readable($tmpPath)) {
            $errors['file'] = 'Arquivo temporário inválido.';

            return $errors;
        }

        if (function_exists('finfo_open')) {
            $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpPath);
            $allowedMimes = (array) config('knowledge.upload.allowed_mimes', []);

            if ($mime !== false && !in_array($mime, $allowedMimes, true)) {
                $errors['file'] = 'Tipo de arquivo não reconhecido como texto.';

                return $errors;
            }
        }

        return $errors;
    }

    public function readUploadedText(array $file): ?string
    {
        $content = @file_get_contents((string) $file['tmp_name']);

        if ($content === false || $content === '') {
            return null;
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            return null;
        }

        return $content;
    }

    public function importManual(array $data, ?int $userId): array
    {
        $errors = $this->validateManualImport($data);

        if ($errors !== []) {
            return ['status' => 'invalid', 'errors' => $errors];
        }

        $origin = $data['origin'];
        $source = $this->sources->findByType($origin);

        if ($source === null) {
            return ['status' => 'invalid', 'errors' => ['origin' => 'Origem não configurada no banco.']];
        }

        $content = $this->normalizeText((string) $data['content']);
        $hash = $this->contentHash($content);
        $url = $this->normalizeUrl((string) ($data['url'] ?? ''));

        $existingByUrl = $url !== '' ? $this->documents->findByUrl($url) : null;

        if ($existingByUrl !== null) {
            if (hash_equals($existingByUrl['content_hash'], $hash)) {
                Audit::record('knowledge.document.duplicate_attempted', 'knowledge_document', (int) $existingByUrl['id'], [
                    'document_id' => (int) $existingByUrl['id'],
                    'source' => $source['name'],
                    'category' => $data['category'],
                    'reason' => 'same_url_same_hash',
                ]);

                return [
                    'status' => self::STATUS_DUPLICATE,
                    'document_id' => (int) $existingByUrl['id'],
                    'message' => 'Este documento já existe na base de conhecimento.',
                ];
            }

            Audit::record('knowledge.document.duplicate_attempted', 'knowledge_document', (int) $existingByUrl['id'], [
                'document_id' => (int) $existingByUrl['id'],
                'source' => $source['name'],
                'category' => $data['category'],
                'reason' => 'same_url_different_hash',
            ]);

            return [
                'status' => self::STATUS_VERSION_CONFLICT,
                'document_id' => (int) $existingByUrl['id'],
                'message' => 'Já existe um documento com esta URL, porém com conteúdo diferente. Revise a versão existente antes de atualizar.',
            ];
        }

        $existingByHash = $this->documents->findByHash((string) $source['id'], $hash);

        if ($existingByHash !== null) {
            Audit::record('knowledge.document.duplicate_attempted', 'knowledge_document', (int) $existingByHash['id'], [
                'document_id' => (int) $existingByHash['id'],
                'source' => $source['name'],
                'category' => $data['category'],
                'reason' => $existingByHash['url'] !== $url ? 'different_url_same_content' : 'same_content_without_url',
            ]);

            return [
                'status' => self::STATUS_CONTENT_DUPLICATE,
                'document_id' => (int) $existingByHash['id'],
                'message' => 'Já existe um documento com este mesmo conteúdo (URL diferente ou sem URL). Possível duplicidade: revise o documento existente.',
            ];
        }

        $slug = $this->makeSlug((string) $data['title']);

        $db = Database::connection();
        $db->beginTransaction();

        try {
            $id = $this->documents->create([
                'source_id' => (int) $source['id'],
                'title' => trim((string) $data['title']),
                'slug' => $slug,
                'url' => $url,
                'category' => (string) ($data['category'] ?? ''),
                'subcategory' => trim((string) ($data['subcategory'] ?? '')),
                'content' => $content,
                'content_hash' => $hash,
                'origin' => $source['name'],
                'status' => $data['status'],
            ]);

            $this->reindex($id, $content);

            Audit::record('knowledge.document.created', 'knowledge_document', $id, [
                'document_id' => $id,
                'source' => $source['name'],
                'category' => $data['category'],
                'url' => $url !== '' ? $url : null,
            ]);

            $db->commit();

            return ['status' => self::STATUS_CREATED, 'document_id' => $id];
        } catch (Throwable $exception) {
            $db->rollBack();
            Logger::error('knowledge', 'Falha ao importar e indexar documento', [
                'error' => $exception->getMessage(),
                'source' => $source['name'],
            ]);

            return ['status' => self::STATUS_ERROR, 'message' => 'Falha ao importar e indexar o documento. Nenhum dado foi gravado.'];
        }
    }

    public function importFromFile(array $data, array $file, ?int $userId): array
    {
        $errors = $this->validateUploadedFile($file);

        if ($errors !== []) {
            return ['status' => 'invalid', 'errors' => $errors];
        }

        if (trim((string) ($data['title'] ?? '')) === '') {
            $data['title'] = pathinfo((string) $file['name'], PATHINFO_FILENAME);
        }

        $content = $this->readUploadedText($file);

        if ($content === null) {
            return ['status' => 'invalid', 'errors' => ['file' => 'Conteúdo do arquivo vazio ou com codificação inválida (UTF-8 exigido).']];
        }

        $data['content'] = $content;

        return $this->importManual($data, $userId);
    }

    public function update(int $id, array $data, ?int $userId): array
    {
        $existing = $this->documents->find($id);

        if ($existing === null) {
            return ['status' => 'invalid', 'errors' => ['id' => 'Documento não encontrado.']];
        }

        $data['title'] = trim((string) ($data['title'] ?? ''));
        $data['content'] = $this->normalizeText((string) ($data['content'] ?? ''));
        $data['url'] = $this->normalizeUrl((string) ($data['url'] ?? ''));
        $data['category'] = (string) ($data['category'] ?? '');
        $data['subcategory'] = trim((string) ($data['subcategory'] ?? ''));
        $data['status'] = (string) ($data['status'] ?? $existing['status']);
        $data['origin'] = (string) ($data['origin'] ?? $existing['source_type']);

        $errors = [];

        if ($data['title'] === '') {
            $errors['title'] = 'Informe o título do documento.';
        }

        if ($data['content'] === '') {
            $errors['content'] = 'O conteúdo não pode ficar vazio.';
        }

        if (!in_array($data['category'], $this->categories(), true)) {
            $errors['category'] = 'Selecione uma categoria válida.';
        }

        if (!in_array($data['status'], ['active', 'inactive'], true)) {
            $errors['status'] = 'Status inválido.';
        }

        if (!array_key_exists($data['origin'], $this->origins())) {
            $errors['origin'] = 'Origem inválida.';
        }

        if ($errors !== []) {
            return ['status' => 'invalid', 'errors' => $errors];
        }

        $newSource = $this->sources->findByType($data['origin']);

        if ($newSource === null) {
            return ['status' => 'invalid', 'errors' => ['origin' => 'Origem não configurada.']];
        }

        $hash = $this->contentHash($data['content']);
        $originChanged = $existing['source_type'] !== $data['origin'];
        $statusChanged = $existing['status'] !== $data['status'];
        $needsReindex = $hash !== $existing['content_hash'] || $this->chunks->countForDocument($id) === 0;

        $db = Database::connection();
        $db->beginTransaction();

        try {
            $this->documents->update($id, [
                'source_id' => (int) $newSource['id'],
                'title' => $data['title'],
                'url' => $data['url'],
                'category' => $data['category'],
                'subcategory' => $data['subcategory'],
                'content' => $data['content'],
                'content_hash' => $hash,
                'origin' => $newSource['name'],
                'status' => $data['status'],
            ]);

            if ($needsReindex) {
                $this->reindex($id, $data['content']);
            }

            Audit::record('knowledge.document.updated', 'knowledge_document', $id, [
                'document_id' => $id,
                'source' => $newSource['name'],
                'category' => $data['category'],
            ]);

            if ($originChanged) {
                Audit::record('knowledge.document.origin_changed', 'knowledge_document', $id, [
                    'document_id' => $id,
                    'from' => $existing['source_type'],
                    'to' => $data['origin'],
                ]);
            }

            if ($statusChanged) {
                $this->recordStatusAudit($id, $data['status']);
            }

            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            Logger::error('knowledge', 'Falha ao atualizar e reindexar documento', [
                'error' => $exception->getMessage(),
                'document_id' => $id,
            ]);

            return ['status' => self::STATUS_ERROR, 'message' => 'Falha ao atualizar o documento. Nenhum dado foi gravado.'];
        }

        return ['status' => 'updated', 'document_id' => $id, 'origin_changed' => $originChanged, 'reindexed' => $needsReindex];
    }

    public function toggleStatus(int $id, ?int $userId): array
    {
        $existing = $this->documents->find($id);

        if ($existing === null) {
            return ['error' => 'Documento não encontrado.'];
        }

        $newStatus = $existing['status'] === 'active' ? 'inactive' : 'active';

        $this->documents->updateStatus($id, $newStatus);
        $this->recordStatusAudit($id, $newStatus);

        return ['status' => $newStatus];
    }

    public function document(): KnowledgeDocumentRepository
    {
        return $this->documents;
    }

    public function chunks(): KnowledgeChunkRepository
    {
        return $this->chunks;
    }

    private function reindex(int $documentId, string $content): void
    {
        $chunks = $this->chunker->chunkText($content);
        $this->chunks->replaceForDocument($documentId, $chunks);
    }

    private function recordStatusAudit(int $id, string $status): void
    {
        Audit::record(
            $status === 'active' ? 'knowledge.document.activated' : 'knowledge.document.deactivated',
            'knowledge_document',
            $id,
            ['document_id' => $id]
        );
    }
}