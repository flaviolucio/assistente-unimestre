<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\DiagnosticSessionRepository;
use App\Repositories\KnowledgeChunkRepository;
use App\Repositories\KnowledgeDocumentRepository;
use App\Repositories\KnowledgeSourceRepository;
use App\Services\Copilot\EvidenceType;
use App\Services\RAG\ChunkingService;
use App\Services\Security\Audit;
use Throwable;

/**
 * Conhecimento interno validado por humanos (Etapa 7.3):
 *
 *  - 🧩 CASO RESOLVIDO: registro factual de um problema real resolvido,
 *    originado de uma diagnostic_session com status "resolved";
 *  - 🛠️ PROCEDIMENTO INTERNO: orientação da equipe para execução recorrente.
 *
 * Ambos viram knowledge_documents (fontes resolved_case / internal_procedure)
 * e são indexados pelo MESMO pipeline (ChunkingService + knowledge_chunks) da
 * base oficial — sem mecanismo paralelo. Nunca têm URL: não viram link
 * "oficial". Proveniência completa em knowledge_documents.metadata.
 *
 * Nada é salvo sem confirmação humana explícita (confirm_review = 1).
 */
final class InternalKnowledgeService
{
    public const KIND_CASE = EvidenceType::RESOLVED_CASE;
    public const KIND_PROCEDURE = EvidenceType::INTERNAL_PROCEDURE;

    public const TITLE_SIMILARITY = 90.0;

    private KnowledgeDocumentRepository $documents;
    private KnowledgeChunkRepository $chunks;
    private KnowledgeSourceRepository $sources;
    private ChunkingService $chunker;
    private DiagnosticSessionRepository $sessions;

    public function __construct(
        ?KnowledgeDocumentRepository $documents = null,
        ?KnowledgeChunkRepository $chunks = null,
        ?KnowledgeSourceRepository $sources = null,
        ?ChunkingService $chunker = null,
        ?DiagnosticSessionRepository $sessions = null
    ) {
        $this->documents = $documents ?? new KnowledgeDocumentRepository();
        $this->chunks = $chunks ?? new KnowledgeChunkRepository();
        $this->sources = $sources ?? new KnowledgeSourceRepository();
        $this->chunker = $chunker ?? new ChunkingService();
        $this->sessions = $sessions ?? new DiagnosticSessionRepository();
    }

    public function canRegisterCase(?array $session): bool
    {
        return $session !== null && $session['status'] === 'resolved';
    }

    /** Documento de caso já criado a partir da sessão (deduplicação por sessão). */
    public function caseForSession(int $sessionId): ?array
    {
        return $this->documents->findByMetadata('diagnostic_session_id', (string) $sessionId);
    }

    /**
     * Pré-preenchimento do formulário a partir do diagnóstico (editável).
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function casePrefill(array $session): array
    {
        $hypotheses = $this->sessions->hypotheses((int) $session['id']);
        $confirmed = array_values(array_filter($hypotheses, static fn (array $h): bool => $h['status'] === 'confirmed'));

        $symptoms = [];

        foreach ((array) $session['questions'] as $q) {
            if (($q['answer'] ?? null) !== null) {
                $symptoms[] = '- ' . $q['question'] . ' → ' . $q['answer'];
            }
        }

        $evidence = [];

        foreach ((array) $session['sources'] as $s) {
            $evidence[] = '- ' . EvidenceType::badge((string) $s['type']) . ': ' . $s['title'];
        }

        foreach ($confirmed as $h) {
            $evidence[] = '- Hipótese confirmada no diagnóstico: ' . $h['title'];
        }

        // A causa só é pré-preenchida quando o diagnóstico tem hipótese confirmada.
        $cause = $confirmed !== [] ? (string) ($session['confirmed_cause'] ?? $confirmed[0]['title']) : '';

        return [
            'title' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $session['original_problem'])), 0, 120),
            'problem' => (string) $session['original_problem'],
            'module' => (string) ($session['identified_module'] ?? ''),
            'context' => (string) ($session['identified_context'] ?? ''),
            'symptoms' => implode("\n", $symptoms),
            'confirmed_cause' => $cause,
            'solution' => (string) ($session['recommended_action'] ?? ''),
            'evidence' => implode("\n", $evidence),
            'observations' => '',
            'cause_needs_review' => $confirmed === [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: string, errors?: array<string, string>, document_id?: int, message?: string}
     */
    public function saveResolvedCase(int $sessionId, array $data, int $userId): array
    {
        $session = $this->sessions->find($sessionId);

        if (!$this->canRegisterCase($session)) {
            return ['status' => 'not_allowed', 'message' => 'Só é possível registrar caso resolvido de um diagnóstico com status "resolvido".'];
        }

        $existing = $this->caseForSession($sessionId);

        if ($existing !== null) {
            return ['status' => 'duplicate', 'document_id' => (int) $existing['id'], 'message' => 'Este diagnóstico já foi registrado como caso resolvido. Edite o registro existente.'];
        }

        $fields = $this->caseFields($data);
        $errors = $this->validateCase($fields, $data, $session);

        if ($errors !== []) {
            return ['status' => 'invalid', 'errors' => $errors];
        }

        $hypotheses = $this->sessions->hypotheses($sessionId);

        $provenance = [
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'diagnostic_session_id' => $sessionId,
            'conversation_id' => (int) $session['conversation_id'],
            'official_sources' => array_values(array_filter((array) $session['sources'], static fn (array $s): bool => ($s['type'] ?? '') === EvidenceType::OFFICIAL)),
            'sources_used' => (array) $session['sources'],
            'confirmed_hypotheses' => array_values(array_map(
                static fn (array $h): array => ['id' => (int) $h['id'], 'title' => (string) $h['title']],
                array_filter($hypotheses, static fn (array $h): bool => $h['status'] === 'confirmed')
            )),
            'cause_reviewed_explicitly' => ($data['cause_reviewed'] ?? '') === '1',
            'human_review' => ['reviewed_by' => $userId, 'reviewed_at' => date('Y-m-d H:i:s'), 'confirmed' => true],
        ];

        return $this->persist(self::KIND_CASE, $fields, [
            'kind' => self::KIND_CASE,
            'diagnostic_session_id' => (string) $sessionId,
            'fields' => $fields,
            'provenance' => $provenance,
        ], $userId, null);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: string, errors?: array<string, string>, document_id?: int, message?: string}
     */
    public function createProcedure(array $data, int $userId): array
    {
        $fields = $this->procedureFields($data);
        $errors = $this->validateProcedure($fields, $data);

        if ($errors !== []) {
            return ['status' => 'invalid', 'errors' => $errors];
        }

        return $this->persist(self::KIND_PROCEDURE, $fields, [
            'kind' => self::KIND_PROCEDURE,
            'fields' => $fields,
            'provenance' => [
                'created_by' => $userId,
                'created_at' => date('Y-m-d H:i:s'),
                'from_message_id' => isset($data['from_message_id']) && ctype_digit((string) $data['from_message_id']) ? (int) $data['from_message_id'] : null,
                'human_review' => ['reviewed_by' => $userId, 'reviewed_at' => date('Y-m-d H:i:s'), 'confirmed' => true],
            ],
        ], $userId, null);
    }

    /**
     * Documento interno (caso/procedimento) com metadados decodificados.
     *
     * @return array<string, mixed>|null
     */
    public function findInternal(int $documentId): ?array
    {
        $document = $this->documents->find($documentId);

        if ($document === null || !in_array($document['source_type'], [self::KIND_CASE, self::KIND_PROCEDURE], true)) {
            return null;
        }

        $document['meta'] = $this->documents->metadataOf($documentId);

        return $document;
    }

    /**
     * Admin edita tudo; técnico só os próprios registros.
     *
     * @param array<string, mixed> $document
     */
    public function canEdit(array $document, int $userId, bool $isAdmin): bool
    {
        return $isAdmin || (int) ($document['meta']['provenance']['created_by'] ?? 0) === $userId;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: string, errors?: array<string, string>, document_id?: int, message?: string}
     */
    public function update(int $documentId, array $data, int $userId): array
    {
        $document = $this->findInternal($documentId);

        if ($document === null) {
            return ['status' => 'not_found'];
        }

        $kind = (string) $document['source_type'];
        $meta = (array) $document['meta'];

        if ($kind === self::KIND_CASE) {
            $fields = $this->caseFields($data);
            $session = $this->sessions->find((int) ($meta['provenance']['diagnostic_session_id'] ?? 0));
            // Causa já revisada na criação; edição exige nova confirmação humana.
            $errors = $this->validateCase($fields, $data + ['cause_reviewed' => '1'], $session, true);
        } else {
            $fields = $this->procedureFields($data);
            $errors = $this->validateProcedure($fields, $data);
        }

        if ($errors !== []) {
            return ['status' => 'invalid', 'errors' => $errors];
        }

        $meta['fields'] = $fields;
        $meta['provenance']['updates'][] = ['updated_by' => $userId, 'updated_at' => date('Y-m-d H:i:s')];
        $meta['provenance']['human_review'] = ['reviewed_by' => $userId, 'reviewed_at' => date('Y-m-d H:i:s'), 'confirmed' => true];

        return $this->persist($kind, $fields, $meta, $userId, $documentId);
    }

    /**
     * Grava documento + chunks (mesmo pipeline da base) numa transação.
     *
     * @param array<string, string> $fields
     * @param array<string, mixed> $metadata
     * @return array{status: string, errors?: array<string, string>, document_id?: int, message?: string}
     */
    private function persist(string $kind, array $fields, array $metadata, int $userId, ?int $documentId): array
    {
        $source = $this->sources->findByType($kind);

        if ($source === null) {
            return ['status' => 'error', 'message' => 'Fonte interna não configurada (rode as migrations).'];
        }

        $content = $kind === self::KIND_CASE ? $this->caseContent($fields) : $this->procedureContent($fields);
        $content = (new KnowledgeService())->normalizeText($content);
        $hash = hash('sha256', $content);

        $sameHash = $this->documents->findByHash((string) $source['id'], $hash);

        if ($sameHash !== null && (int) $sameHash['id'] !== $documentId) {
            return ['status' => 'duplicate', 'document_id' => (int) $sameHash['id'], 'message' => 'Já existe um registro com exatamente este conteúdo.'];
        }

        $similar = $this->similarTitle((int) $source['id'], $fields['title'], $documentId);

        if ($similar !== null) {
            return ['status' => 'duplicate', 'document_id' => $similar, 'message' => 'Já existe um registro com título igual ou muito semelhante. Revise-o antes de criar outro.'];
        }

        $db = Database::connection();
        $db->beginTransaction();

        try {
            if ($documentId === null) {
                $documentId = $this->documents->create([
                    'source_id' => (int) $source['id'],
                    'title' => $fields['title'],
                    'slug' => (new KnowledgeService())->makeSlug($fields['title']),
                    'url' => '',
                    'category' => $fields['module'],
                    'subcategory' => $kind === self::KIND_CASE ? $fields['context'] : $fields['usage_context'],
                    'content' => $content,
                    'content_hash' => $hash,
                    'origin' => (string) $source['name'],
                    'status' => 'active',
                ]);
                $action = $kind === self::KIND_CASE ? 'resolved_case_created' : 'internal_procedure_created';
            } else {
                $this->documents->update($documentId, [
                    'title' => $fields['title'],
                    'category' => $fields['module'] !== '' ? $fields['module'] : null,
                    'subcategory' => ($kind === self::KIND_CASE ? $fields['context'] : $fields['usage_context']) ?: null,
                    'content' => $content,
                    'content_hash' => $hash,
                ]);
                $action = $kind === self::KIND_CASE ? 'resolved_case_updated' : 'internal_procedure_updated';
            }

            $this->documents->setImportMetadata($documentId, $metadata, null);
            $this->chunks->replaceForDocument($documentId, $this->chunker->chunkText($content));

            Audit::record($action, 'knowledge_document', $documentId, [
                'user_id' => $userId,
                'document_id' => $documentId,
                'diagnostic_session_id' => isset($metadata['provenance']['diagnostic_session_id']) ? (int) $metadata['provenance']['diagnostic_session_id'] : null,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

            $db->commit();
        } catch (Throwable $exception) {
            $db->rollBack();
            Logger::error('knowledge', 'Falha ao salvar conhecimento interno', ['kind' => $kind, 'error_class' => $exception::class]);

            return ['status' => 'error', 'message' => 'Falha ao salvar e indexar o registro. Nada foi gravado.'];
        }

        return ['status' => str_ends_with($action, '_created') ? 'created' : 'updated', 'document_id' => $documentId];
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $session
     * @return array<string, string>
     */
    private function validateCase(array $fields, array $data, ?array $session, bool $editing = false): array
    {
        $errors = [];

        if (mb_strlen($fields['title']) < 5) {
            $errors['title'] = 'Informe um título (mínimo 5 caracteres).';
        }

        if (mb_strlen($fields['problem']) < 10) {
            $errors['problem'] = 'Descreva o problema.';
        }

        if (mb_strlen($fields['confirmed_cause']) < 5) {
            $errors['confirmed_cause'] = 'Informe a causa confirmada.';
        }

        if (mb_strlen($fields['solution']) < 10) {
            $errors['solution'] = 'Descreva a solução aplicada.';
        }

        if (($data['confirm_review'] ?? '') !== '1') {
            $errors['confirm_review'] = 'Confirme que revisou o conteúdo e que o problema foi resolvido com esta solução.';
        }

        if (!$editing && $session !== null && !isset($errors['confirmed_cause']) && ($data['cause_reviewed'] ?? '') !== '1') {
            $confirmed = array_filter(
                $this->sessions->hypotheses((int) $session['id']),
                static fn (array $h): bool => $h['status'] === 'confirmed'
            );

            if ($confirmed === []) {
                // Diagnóstico sem hipótese confirmada: causa exige revisão explícita.
                $errors['cause_reviewed'] = 'O diagnóstico não tem hipótese confirmada. Revise a causa e marque "Revisei a causa" para confirmá-la.';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validateProcedure(array $fields, array $data): array
    {
        $errors = [];

        if (mb_strlen($fields['title']) < 5) {
            $errors['title'] = 'Informe um título (mínimo 5 caracteres).';
        }

        if (mb_strlen($fields['steps']) < 10) {
            $errors['steps'] = 'Descreva o passo a passo.';
        }

        if (($data['confirm_review'] ?? '') !== '1') {
            $errors['confirm_review'] = 'Confirme que revisou o procedimento.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function caseFields(array $data): array
    {
        return [
            'title' => $this->text($data['title'] ?? '', 255),
            'problem' => $this->text($data['problem'] ?? '', 4000),
            'module' => $this->text($data['module'] ?? '', 150),
            'context' => $this->text($data['context'] ?? '', 150),
            'symptoms' => $this->text($data['symptoms'] ?? '', 4000),
            'confirmed_cause' => $this->text($data['confirmed_cause'] ?? '', 2000),
            'solution' => $this->text($data['solution'] ?? '', 6000),
            'evidence' => $this->text($data['evidence'] ?? '', 4000),
            'observations' => $this->text($data['observations'] ?? '', 4000),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function procedureFields(array $data): array
    {
        return [
            'title' => $this->text($data['title'] ?? '', 255),
            'module' => $this->text($data['module'] ?? '', 150),
            'usage_context' => $this->text($data['usage_context'] ?? '', 150),
            'steps' => $this->text($data['steps'] ?? '', 8000),
            'prerequisites' => $this->text($data['prerequisites'] ?? '', 4000),
            'risks' => $this->text($data['risks'] ?? '', 4000),
            'observations' => $this->text($data['observations'] ?? '', 4000),
        ];
    }

    /**
     * Texto indexado. Evidências ficam só nos metadados (não viram conteúdo
     * pesquisável, para o caso não "herdar" termos de documentos oficiais).
     *
     * @param array<string, string> $f
     */
    private function caseContent(array $f): string
    {
        return $this->sections('CASO RESOLVIDO (experiência validada pela equipe; não é documentação oficial UNIMESTRE): ' . $f['title'], [
            'Problema' => $f['problem'],
            'Módulo' => $f['module'],
            'Contexto' => $f['context'],
            'Sintomas observados' => $f['symptoms'],
            'Causa confirmada' => $f['confirmed_cause'],
            'Solução aplicada' => $f['solution'],
            'Observações' => $f['observations'],
        ]);
    }

    /**
     * @param array<string, string> $f
     */
    private function procedureContent(array $f): string
    {
        return $this->sections('PROCEDIMENTO INTERNO (orientação da equipe; não é documentação oficial UNIMESTRE): ' . $f['title'], [
            'Módulo' => $f['module'],
            'Contexto de uso' => $f['usage_context'],
            'Pré-requisitos' => $f['prerequisites'],
            'Passo a passo' => $f['steps'],
            'Riscos e cuidados' => $f['risks'],
            'Observações' => $f['observations'],
        ]);
    }

    /**
     * @param array<string, string> $sections
     */
    private function sections(string $heading, array $sections): string
    {
        $parts = [$heading];

        foreach ($sections as $label => $value) {
            if (trim($value) !== '') {
                $parts[] = $label . ":\n" . $value;
            }
        }

        return implode("\n\n", $parts);
    }

    private function similarTitle(int $sourceId, string $title, ?int $ignoreId): ?int
    {
        $key = $this->titleKey($title);

        foreach ($this->documents->titlesInSource($sourceId) as $row) {
            if ((int) $row['id'] === $ignoreId) {
                continue;
            }

            $other = $this->titleKey((string) $row['title']);
            similar_text($key, $other, $percent);

            if ($other === $key || $percent >= self::TITLE_SIMILARITY) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    private function titleKey(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = is_string($ascii) && $ascii !== '' ? $ascii : $text;

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /**
     * @param mixed $value
     */
    private function text($value, int $max): string
    {
        $value = is_string($value) ? str_replace(["\r\n", "\r"], "\n", trim($value)) : '';

        return mb_substr($value, 0, $max);
    }
}
