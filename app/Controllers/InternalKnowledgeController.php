<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Repositories\ConversationRepository;
use App\Repositories\DiagnosticSessionRepository;
use App\Services\Knowledge\InternalKnowledgeService;
use App\Services\Security\Auth;

/**
 * Etapa 7.3 — registro de 🧩 CASO RESOLVIDO (a partir de diagnóstico
 * resolvido) e de 🛠️ PROCEDIMENTO INTERNO. Rotas protegidas por
 * solutions.manage (admin e técnico); técnico edita só os próprios registros.
 */
final class InternalKnowledgeController extends Controller
{
    private InternalKnowledgeService $service;

    private ConversationRepository $conversations;

    private DiagnosticSessionRepository $sessions;

    public function __construct()
    {
        $this->service = new InternalKnowledgeService();
        $this->conversations = new ConversationRepository();
        $this->sessions = new DiagnosticSessionRepository();
    }

    /** GET /chat/{id}/caso-resolvido */
    public function caseForm(int $conversationId): void
    {
        $session = $this->resolvedSessionOrRedirect($conversationId);

        $this->render('internal_knowledge/form', [
            'appTitle' => 'Registrar caso resolvido',
            'kind' => InternalKnowledgeService::KIND_CASE,
            'action' => app_url('assistente/' . $conversationId . '/caso-resolvido'),
            'backUrl' => app_url('assistente/' . $conversationId),
            'old' => $this->service->casePrefill($session),
            'causeNeedsReview' => $this->service->casePrefill($session)['cause_needs_review'],
            'errors' => [],
            'editing' => false,
        ]);
    }

    /** POST /chat/{id}/caso-resolvido */
    public function caseStore(int $conversationId): void
    {
        $session = $this->resolvedSessionOrRedirect($conversationId);
        $result = $this->service->saveResolvedCase((int) $session['id'], $_POST, (int) Auth::instance()->id());

        if ($result['status'] === 'invalid') {
            $this->render('internal_knowledge/form', [
                'appTitle' => 'Registrar caso resolvido',
                'kind' => InternalKnowledgeService::KIND_CASE,
                'action' => app_url('assistente/' . $conversationId . '/caso-resolvido'),
                'backUrl' => app_url('assistente/' . $conversationId),
                'old' => $_POST,
                'causeNeedsReview' => $this->service->casePrefill($session)['cause_needs_review'],
                'errors' => $result['errors'] ?? [],
                'editing' => false,
            ]);

            return;
        }

        $this->finish($result, 'assistente/' . $conversationId, 'Caso resolvido registrado. Ele passa a ser consultado como 🧩 CASO RESOLVIDO.');
    }

    /** GET /procedimentos/novo[?from_message=ID] */
    public function procedureForm(): void
    {
        $old = ['title' => '', 'module' => '', 'usage_context' => '', 'steps' => '', 'prerequisites' => '', 'risks' => '', 'observations' => ''];
        $fromMessage = isset($_GET['from_message']) && ctype_digit((string) $_GET['from_message']) ? (int) $_GET['from_message'] : null;

        if ($fromMessage !== null && $this->conversations->messageBelongsToUser($fromMessage, (int) Auth::instance()->id())) {
            $message = $this->conversations->findMessage($fromMessage);

            if ($message !== null) {
                $old['title'] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $message['content'])), 0, 120);
                $old['from_message_id'] = (string) $fromMessage;
            }
        }

        $fromSession = isset($_GET['from_session']) && ctype_digit((string) $_GET['from_session']) ? (int) $_GET['from_session'] : null;
        $session = $fromSession !== null ? $this->sessions->findForUser($fromSession, (int) Auth::instance()->id()) : null;

        if ($session !== null) {
            // Só contexto registrado no diagnóstico; nada é salvo sem revisão.
            $old['title'] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $session['original_problem'])), 0, 120);
            $old['module'] = (string) ($session['identified_module'] ?? '');
            $old['usage_context'] = (string) ($session['identified_context'] ?? '');
            $old['steps'] = (string) ($session['recommended_action'] ?? '');
            $old['observations'] = 'Criado a partir do diagnóstico #' . (int) $session['id'] . '.';
        }

        $this->render('internal_knowledge/form', [
            'appTitle' => 'Novo procedimento interno',
            'kind' => InternalKnowledgeService::KIND_PROCEDURE,
            'action' => app_url('procedimentos'),
            'backUrl' => $session !== null ? app_url('assistente/' . (int) $session['conversation_id']) : app_url('assistente'),
            'old' => $old,
            'causeNeedsReview' => false,
            'errors' => [],
            'editing' => false,
        ]);
    }

    /** POST /procedimentos */
    public function procedureStore(): void
    {
        $result = $this->service->createProcedure($_POST, (int) Auth::instance()->id());

        if ($result['status'] === 'invalid') {
            $this->render('internal_knowledge/form', [
                'appTitle' => 'Novo procedimento interno',
                'kind' => InternalKnowledgeService::KIND_PROCEDURE,
                'action' => app_url('procedimentos'),
                'backUrl' => app_url('assistente'),
                'old' => $_POST,
                'causeNeedsReview' => false,
                'errors' => $result['errors'] ?? [],
                'editing' => false,
            ]);

            return;
        }

        $this->finish($result, 'assistente', 'Procedimento interno salvo. Ele passa a ser consultado como 🛠️ PROCEDIMENTO INTERNO.');
    }

    /** GET /conhecimento-interno/{id}/editar */
    public function editForm(int $id): void
    {
        $document = $this->editableOrFail($id);

        $this->render('internal_knowledge/form', [
            'appTitle' => 'Editar registro interno',
            'kind' => (string) $document['source_type'],
            'action' => app_url('conhecimento-interno/' . $id . '/editar'),
            'backUrl' => app_url('knowledge/' . $id),
            'old' => (array) ($document['meta']['fields'] ?? []),
            'causeNeedsReview' => false,
            'errors' => [],
            'editing' => true,
        ]);
    }

    /** POST /conhecimento-interno/{id}/editar */
    public function update(int $id): void
    {
        $document = $this->editableOrFail($id);
        $result = $this->service->update($id, $_POST, (int) Auth::instance()->id());

        if ($result['status'] === 'invalid') {
            $this->render('internal_knowledge/form', [
                'appTitle' => 'Editar registro interno',
                'kind' => (string) $document['source_type'],
                'action' => app_url('conhecimento-interno/' . $id . '/editar'),
                'backUrl' => app_url('knowledge/' . $id),
                'old' => $_POST,
                'causeNeedsReview' => false,
                'errors' => $result['errors'] ?? [],
                'editing' => true,
            ]);

            return;
        }

        $this->finish($result, 'conhecimento-interno/' . $id . '/editar', 'Registro atualizado e reindexado.');
    }

    /**
     * @param array<string, mixed> $result
     */
    private function finish(array $result, string $back, string $success): void
    {
        if (in_array($result['status'], ['created', 'updated'], true)) {
            Flash::set('success', $success);
            $this->redirect($back);
        }

        Flash::set('error', (string) ($result['message'] ?? 'Não foi possível salvar.'));

        if ($result['status'] === 'duplicate' && isset($result['document_id'])) {
            $this->redirect('conhecimento-interno/' . (int) $result['document_id'] . '/editar');
        }

        $this->redirect($back);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedSessionOrRedirect(int $conversationId): array
    {
        if ($this->conversations->findByUser($conversationId, (int) Auth::instance()->id()) === null) {
            $this->notFound();
        }

        $session = $this->sessions->latestForConversation($conversationId);

        if (!$this->service->canRegisterCase($session)) {
            Flash::set('error', 'Só é possível registrar caso resolvido depois que o problema for confirmado como resolvido.');
            $this->redirect('assistente/' . $conversationId);
        }

        $existing = $this->service->caseForSession((int) $session['id']);

        if ($existing !== null) {
            Flash::set('error', 'Este diagnóstico já foi registrado como caso resolvido.');
            $this->redirect('conhecimento-interno/' . (int) $existing['id'] . '/editar');
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function editableOrFail(int $id): array
    {
        $document = $this->service->findInternal($id);
        $auth = Auth::instance();

        if ($document === null || !$this->service->canEdit($document, (int) $auth->id(), $auth->role() === 'admin')) {
            $this->notFound();
        }

        return $document;
    }

    private function notFound(): void
    {
        http_response_code(404);
        require config('paths.views') . '/errors/404.php';
        exit;
    }
}
