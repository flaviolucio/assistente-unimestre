<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Logger;
use App\Repositories\ConversationRepository;
use App\Services\Chat\ChatRateLimiter;
use App\Services\Chat\ChatService;
use App\Services\Copilot\CopilotDiagnosticService;
use App\Services\Copilot\DiagnosticPanelPresenter;
use App\Services\Copilot\EscalationSummaryService;
use App\Support\SensitiveDataMasker;
use App\Services\Copilot\EvidenceType;
use App\Services\Knowledge\InternalSolutionService;
use App\Services\RAG\RAGService;
use App\Support\SourceUrl;
use App\Services\RAG\RAGResult;
use App\Services\Security\Auth;
use App\Services\Security\Audit;

final class ChatController extends Controller
{
    private ConversationRepository $conversations;

    private ChatService $chat;

    public function __construct(?ConversationRepository $conversations = null, ?ChatService $chat = null)
    {
        $this->conversations = $conversations ?? new ConversationRepository();
        $this->chat = $chat ?? new ChatService();
    }

    public function index(): void
    {
        if (!Auth::instance()->check()) {
            $this->requireLogin();

            return;
        }

        $this->render('chat/index', [
            'appTitle' => 'Assistente UNIMESTRE',
            'chatCsrf' => Csrf::token(),
            'chat_max_length' => $this->maxQuestionLength(),
            'isTechSupport' => in_array((string) Auth::instance()->role(), ['admin', 'tecnico'], true),
        ]);
    }

    public function history(): void
    {
        if (!Auth::instance()->check()) {
            $this->requireLogin();

            return;
        }

        $this->render('chat/history', [
            'appTitle' => 'Histórico de conversas',
            'conversations' => $this->conversations->listForUser((int) Auth::instance()->id()),
        ]);
    }

    public function show(int $id): void
    {
        if (!Auth::instance()->check()) {
            $this->requireLogin();

            return;
        }

        $userId = (int) Auth::instance()->id();
        $conversation = $this->conversations->findByUser($id, $userId);

        if ($conversation === null) {
            $this->renderNotFound();

            return;
        }

        $messages = $this->conversations->messagesWithSources($id);
        $feedback = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'assistant') {
                $entry = $this->conversations->feedbackForMessage((int) $message['id'], $userId);

                if ($entry !== null) {
                    $feedback[(int) $message['id']] = $entry;
                }
            }
        }

        $session = (new CopilotDiagnosticService())->repository()->latestForConversation($id);

        $this->render('chat/thread', [
            'diagnosticHtml' => $session !== null
                ? (new DiagnosticPanelPresenter())->render($session, Auth::instance()->can('solutions.manage'))
                : null,
            'appTitle' => 'Conversa — ' . (string) $conversation['title'],
            'conversation' => $conversation,
            'messages' => $messages,
            'feedback' => $feedback,
            'chatCsrf' => Csrf::token(),
            'canManageSolutions' => Auth::instance()->can('solutions.manage'),
            'isTechSupport' => in_array((string) Auth::instance()->role(), ['admin', 'tecnico'], true),
        ]);
    }

    public function api(): void
    {
        if (!Auth::instance()->check()) {
            $this->json([
                'ok' => false,
                'error' => 'unauthenticated',
                'message' => 'Faça login para continuar.',
            ], 401);

            return;
        }

        $userId = (int) Auth::instance()->id();
        $input = $this->inputBody();

        $question = trim((string) ($input['question'] ?? ($input['message'] ?? '')));
        $conversationId = isset($input['conversation_id']) && is_numeric($input['conversation_id'])
            ? (int) $input['conversation_id']
            : null;
        $clientRequestId = trim((string) ($input['client_request_id'] ?? ''));

        $maxLength = $this->maxQuestionLength();

        if ($question === '') {
            $this->json([
                'ok' => false,
                'error' => 'question_required',
                'message' => 'Pergunta não pode ser vazia.',
            ], 422);

            return;
        }

        if (mb_strlen($question) > $maxLength) {
            $this->json([
                'ok' => false,
                'error' => 'question_too_long',
                'message' => 'A pergunta excede o limite de ' . $maxLength . ' caracteres.',
            ], 422);

            return;
        }

        if (!(new ChatRateLimiter())->allow($userId)) {
            $this->json([
                'ok' => false,
                'error' => 'rate_limited',
                'message' => 'Limite de perguntas excedido. Aguarde um pouco antes de perguntar novamente.',
            ], 429);

            return;
        }

        try {
            $response = $this->chat->ask($userId, $question, $conversationId, $clientRequestId);
        } catch (\Throwable $exception) {
            Logger::error('chat', 'Falha interna ao processar a pergunta', [
                'user_id' => $userId,
                'conversation_id' => $conversationId,
                'error_class' => $exception::class,
            ]);

            $this->json([
                'ok' => false,
                'error' => 'internal_error',
                'message' => 'Não foi possível processar a pergunta neste momento.',
            ], 500);

            return;
        }

        if ($response['status'] === 'duplicate') {
            $this->json([
                'ok' => true,
                'duplicate' => true,
                'message' => ChatService::MSG_DUPLICATE,
            ]);

            return;
        }

        if ($response['status'] === 'not_found') {
            $this->json([
                'ok' => false,
                'error' => 'conversation_not_found',
                'message' => 'Conversa não encontrada.',
            ], 404);

            return;
        }

        /** @var RAGResult $result */
        $result = $response['result'];

        $gatewayState = $this->gatewayState($result);
        $displayAnswer = $result->isSuccess()
            ? (string) $result->answer()
            : RAGService::failureMessage($gatewayState, $result);

        $this->json([
            'ok' => true,
            'duplicate' => false,
            'conversation_id' => (int) $response['conversation_id'],
            'user_message_id' => (int) $response['user_message_id'],
            'assistant_message_id' => $response['assistant_message_id'] !== null ? (int) $response['assistant_message_id'] : null,
            'answer' => $displayAnswer,
            'gateway_state' => $gatewayState,
            'error_code' => $result->isSuccess() ? null : $result->errorCode(),
            'truncated' => $result->truncated() && $result->isSuccess(),
            'truncated_notice' => ($result->truncated() && $result->isSuccess()) ? RAGService::MSG_TRUNCATED_NOTICE : null,
            'sources' => $this->presentSources($result->sources()),
            'structured' => $result->isSuccess() ? $this->presentStructured($result->structured()) : null,
            'diagnostic' => $response['diagnostic'] ?? null,
            'diagnostic_html' => $this->diagnosticHtml($response['diagnostic']['session_id'] ?? null),
            'retrieval_count' => $result->retrievalCount(),
            'top_score' => round($result->topScore(), 2),
            'ai_called' => $result->aiCalled(),
            'model' => $result->model(),
            'total_tokens' => $result->totalTokens(),
            'latency_ms' => $result->latencyMs() !== null ? round($result->latencyMs(), 2) : null,
            'request_id' => $result->requestId(),
        ]);
    }

    /** POST /api/chat/{id}/diagnostico/resolver — confirmação HUMANA de que resolveu. */
    public function diagnosticResolve(int $conversationId): void
    {
        $this->diagnosticClose($conversationId, 'resolve');
    }

    /** POST /api/chat/{id}/diagnostico/encerrar — abandona a investigação ativa. */
    public function diagnosticAbandon(int $conversationId): void
    {
        $this->diagnosticClose($conversationId, 'abandon');
    }

    private function diagnosticClose(int $conversationId, string $action): void
    {
        if (!Auth::instance()->check()) {
            $this->json(['ok' => false, 'error' => 'unauthenticated', 'message' => 'Faça login para continuar.'], 401);

            return;
        }

        $userId = (int) Auth::instance()->id();
        $service = new CopilotDiagnosticService();

        if ($this->conversations->findByUser($conversationId, $userId) === null) {
            $this->json(['ok' => false, 'error' => 'conversation_not_found', 'message' => 'Conversa não encontrada.'], 404);

            return;
        }

        $session = $service->activeSession($conversationId);

        if ($session === null) {
            $this->json(['ok' => false, 'error' => 'no_active_diagnostic', 'message' => 'Não há diagnóstico em andamento nesta conversa.'], 409);

            return;
        }

        if ($action === 'resolve') {
            $service->markResolved($session, $userId);
        } else {
            $reason = (string) ($this->inputBody()['reason'] ?? '');
            $service->abandon($session, $userId, DiagnosticPanelPresenter::CLOSE_REASONS[$reason] ?? null);
        }

        $this->json([
            'ok' => true,
            'diagnostic' => $service->present((array) $service->repository()->find((int) $session['id'])),
            'diagnostic_html' => $this->diagnosticHtml((int) $session['id']),
        ]);
    }

    /**
     * GET /api/chat/{id}/diagnostico/escalonamento — gera (sem gravar) o resumo
     * de escalonamento; em sessão escalada devolve o resumo congelado.
     */
    public function diagnosticEscalation(int $conversationId): void
    {
        $session = $this->ownedSessionOrFail($conversationId, false);
        $allowed = in_array($session['status'], ['escalation_needed', 'investigating', 'open', 'escalated'], true);

        if (!$allowed) {
            $this->json(['ok' => false, 'error' => 'not_escalatable', 'message' => 'Este diagnóstico não pode ser escalado no estado atual.'], 409);

            return;
        }

        if ($session['status'] !== 'escalated') {
            Audit::record('copilot.escalonamento_preparado', 'diagnostic_session', (int) $session['id'], [
                'diagnostic_session_id' => (int) $session['id'],
                'conversation_id' => $conversationId,
                'user_id' => (int) Auth::instance()->id(),
            ]);
        }

        $this->json([
            'ok' => true,
            'html' => (new EscalationSummaryService())->renderView($session, (string) (Auth::instance()->user()['name'] ?? '')),
        ]);
    }

    /** POST /api/chat/{id}/diagnostico/escalar — AÇÃO HUMANA; nada é enviado para fora. */
    public function diagnosticEscalate(int $conversationId): void
    {
        $session = $this->ownedSessionOrFail($conversationId, true);
        $input = $this->inputBody();
        $reasonCode = (string) ($input['reason'] ?? '');

        if (($input['confirm'] ?? '') !== '1' || !isset(CopilotDiagnosticService::ESCALATION_REASONS[$reasonCode])) {
            $this->json(['ok' => false, 'error' => 'invalid_escalation', 'message' => 'Selecione o motivo e confirme o escalonamento.'], 422);

            return;
        }

        if ($session['status'] === 'solution_proposed') {
            $this->json(['ok' => false, 'error' => 'not_escalatable', 'message' => 'Há uma solução proposta aguardando confirmação.'], 409);

            return;
        }

        $reference = trim((string) ($input['external_reference'] ?? ''));
        $reference = $reference !== '' ? mb_substr((new SensitiveDataMasker())->mask($reference), 0, 120) : null;
        $reason = CopilotDiagnosticService::ESCALATION_REASONS[$reasonCode];
        $user = Auth::instance()->user();

        // O resumo é regerado no servidor (nunca aceito do cliente) e congelado.
        $summary = (new EscalationSummaryService())->build($session, (string) ($user['name'] ?? ''), $reason);
        $service = new CopilotDiagnosticService();
        $service->markEscalated($session, (int) Auth::instance()->id(), $reason, $summary, $reference);

        $this->json([
            'ok' => true,
            'diagnostic' => $service->present((array) $service->repository()->find((int) $session['id'])),
            'diagnostic_html' => $this->diagnosticHtml((int) $session['id']),
        ]);
    }

    /** POST /api/chat/{id}/diagnostico/retomar — escalation_needed → investigating. */
    public function diagnosticReopen(int $conversationId): void
    {
        $session = $this->ownedSessionOrFail($conversationId, true);
        $service = new CopilotDiagnosticService();

        if (!$service->reopen($session, (int) Auth::instance()->id())) {
            $this->json(['ok' => false, 'error' => 'not_reopenable', 'message' => 'O diagnóstico não está aguardando escalonamento.'], 409);

            return;
        }

        $this->json(['ok' => true, 'diagnostic_html' => $this->diagnosticHtml((int) $session['id'])]);
    }

    /**
     * Sessão mais recente da conversa, somente se a conversa for do usuário.
     *
     * @return array<string, mixed>
     */
    private function ownedSessionOrFail(int $conversationId, bool $activeOnly): array
    {
        if (!Auth::instance()->check()) {
            $this->json(['ok' => false, 'error' => 'unauthenticated', 'message' => 'Faça login para continuar.'], 401);
        }

        if ($this->conversations->findByUser($conversationId, (int) Auth::instance()->id()) === null) {
            $this->json(['ok' => false, 'error' => 'conversation_not_found', 'message' => 'Conversa não encontrada.'], 404);
        }

        $repository = (new CopilotDiagnosticService())->repository();
        $session = $activeOnly ? $repository->activeForConversation($conversationId) : $repository->latestForConversation($conversationId);

        if ($session === null) {
            $this->json(['ok' => false, 'error' => 'no_active_diagnostic', 'message' => 'Não há diagnóstico nesta conversa.'], 409);
        }

        return $session;
    }

    /** HTML do painel (mesmo partial da página da conversa). */
    private function diagnosticHtml(?int $sessionId): ?string
    {
        if ($sessionId === null) {
            return null;
        }

        $session = (new CopilotDiagnosticService())->repository()->find($sessionId);

        return $session !== null
            ? (new DiagnosticPanelPresenter())->render($session, Auth::instance()->can('solutions.manage'))
            : null;
    }

    public function feedback(int $messageId): void
    {
        if (!Auth::instance()->check()) {
            $this->json([
                'ok' => false,
                'error' => 'unauthenticated',
                'message' => 'Faça login para continuar.',
            ], 401);

            return;
        }

        $userId = (int) Auth::instance()->id();
        $input = $this->inputBody();
        $rating = (string) ($input['rating'] ?? '');

        if (!in_array($rating, ['util', 'nao_util'], true)) {
            $this->json([
                'ok' => false,
                'error' => 'invalid_rating',
                'message' => 'Avaliação inválida.',
            ], 422);

            return;
        }

        $comment = trim((string) ($input['comment'] ?? ''));

        if ($comment !== '' && mb_strlen($comment) > 1000) {
            $this->json([
                'ok' => false,
                'error' => 'comment_too_long',
                'message' => 'Comentário deve ter no máximo 1000 caracteres.',
            ], 422);

            return;
        }

        if (!$this->conversations->messageBelongsToUser($messageId, $userId)) {
            $this->json([
                'ok' => false,
                'error' => 'message_not_found',
                'message' => 'Mensagem não encontrada.',
            ], 404);

            return;
        }

        $this->conversations->upsertFeedback(
            $messageId,
            $userId,
            $rating,
            $comment !== '' ? $comment : null
        );

        Audit::record('chat.feedback', 'message', $messageId, [
            'message_id' => $messageId,
            'user_id' => $userId,
            'rating' => $rating,
        ]);

        $this->json([
            'ok' => true,
            'rating' => $rating,
        ]);
    }

    public function saveSolutionForm(int $messageId): void
    {
        if (!Auth::instance()->check()) {
            $this->requireLogin();

            return;
        }

        $userId = (int) Auth::instance()->id();

        if (!$this->conversations->messageBelongsToUser($messageId, $userId)) {
            $this->renderNotFound();

            return;
        }

        $message = $this->conversations->findMessage($messageId);

        if ($message === null) {
            $this->renderNotFound();

            return;
        }

        $question = $this->resolveQuestionMessage((int) $message['id']);

        if ($question === null) {
            Flash::set('error', 'Não foi possível localizar a pergunta original da conversa.');

            $this->redirect('/assistente/' . (int) $message['conversation_id']);
        }

        $category = $this->deriveCategory((int) $message['conversation_id']);

        $service = new InternalSolutionService();

        $this->render('solutions/form', [
            'appTitle' => 'Salvar como solução interna',
            'solution' => null,
            'old' => [
                'title' => mb_substr(preg_replace('/\s+/u', ' ', trim((string) $question['content'])) ?? '', 0, 120),
                'problem' => (string) $question['content'],
                'solution' => (string) $message['content'],
                'category' => $category,
                'tags' => '',
                'status' => 'active',
            ],
            'categories' => $service->categories(),
            'errors' => [],
        ]);
    }

    private function resolveQuestionMessage(int $messageId): ?array
    {
        $message = $this->conversations->findMessage($messageId);

        if ($message === null) {
            return null;
        }

        if ($message['role'] === 'user') {
            return $message;
        }

        return $this->conversations->findPreviousUserMessage(
            (int) $message['conversation_id'],
            (string) $message['created_at'],
            $messageId
        );
    }

    private function deriveCategory(int $conversationId): string
    {
        $messages = $this->conversations->messagesWithSources($conversationId);

        foreach (array_reverse($messages) as $message) {
            foreach (($message['sources'] ?? []) as $source) {
                $category = trim((string) ($source['category'] ?? ''));

                if ($category !== '') {
                    return $category;
                }
            }
        }

        return 'Outros';
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    private function presentSources(array $sources): array
    {
        $presented = [];

        foreach ($sources as $index => $source) {
            $evidence = EvidenceType::fromSource($source);
            $presented[] = [
                'number' => $index + 1,
                'title' => (string) $source['title'],
                'source_type' => $evidence,
                'source_type_label' => EvidenceType::label($evidence),
                'source_badge' => EvidenceType::badge($evidence),
                'badge_class' => EvidenceType::cssClass($evidence),
                'category' => $source['category'] !== null ? (string) $source['category'] : null,
                'snippet' => $this->sourceSnippet($source),
                'url' => SourceUrl::isTrusted($source) ? (string) $source['url'] : null,
                'document_id' => $source['document_id'] !== null ? (int) $source['document_id'] : null,
                'solution_id' => $source['solution_id'] !== null ? (int) $source['solution_id'] : null,
                'relevance_score' => isset($source['relevance_score']) ? round((float) $source['relevance_score'], 2) : null,
            ];
        }

        return $presented;
    }

    /**
     * Resumo estruturado seguro para a UI: sem warnings internos.
     *
     * @param array<string, mixed>|null $structured
     * @return array<string, mixed>|null
     */
    private function presentStructured(?array $structured): ?array
    {
        if ($structured === null) {
            return null;
        }

        return [
            'mode' => $structured['mode'],
            'hypotheses' => array_map(static fn (array $h): array => [
                'title' => $h['title'],
                'status' => $h['status'],
                'status_label' => $h['status_label'],
                'reason' => $h['reason'],
                'source_ids' => $h['source_ids'],
                'badge' => EvidenceType::badge(EvidenceType::AI_HYPOTHESIS),
            ], (array) $structured['hypotheses']),
            'next_question' => $structured['next_question'],
            'recommended_action' => $structured['recommended_action'],
            'resolved' => (bool) $structured['resolved'],
            'confirmed_cause' => $structured['confirmed_cause'],
            'source_ids' => $structured['source_ids'],
        ];
    }

    /**
     * Trecho relevante exibido no card da fonte, sem expor o documento inteiro.
     *
     * @param array<string, mixed> $source
     */
    private function sourceSnippet(array $source): ?string
    {
        $raw = (string) ($source['content'] ?? '');

        if (trim($raw) === '') {
            return null;
        }

        $raw = preg_replace('/\s+/u', ' ', $raw) ?? $raw;
        $raw = trim($raw);

        if (mb_strlen($raw) <= 260) {
            return $raw;
        }

        $cut = mb_substr($raw, 0, 260);
        $space = mb_strrpos($cut, ' ');

        if ($space !== false && $space > 120) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t\n\r\0\x0B") . '…';
    }

    private function gatewayState(RAGResult $result): string
    {
        if (!$result->isSuccess()) {
            $state = $result->errorCode();

            if ($state === 'provider_unavailable') {
                return 'provider_unavailable';
            }

            if ($state === 'timeout') {
                return 'timeout';
            }

            if (in_array($state, ['rate_limited', 'rate_limit', 'http_429'], true)) {
                return 'rate_limited';
            }

            return 'provider_failure';
        }

        return $result->noContext() ? 'no_context' : 'ok';
    }

    private function maxQuestionLength(): int
    {
        $fromSetting = (new \App\Repositories\SystemSettingRepository())->get('chat.max_question_length');

        if ($fromSetting !== null && is_numeric($fromSetting)) {
            return max(1, (int) $fromSetting);
        }

        return max(1, (int) config('chat.max_question_length', 4000));
    }

    /**
     * @return array<string, mixed>
     */
    private function inputBody(): array
    {
        $body = file_get_contents('php://input');

        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $_POST;
    }

    private function requireLogin(): void
    {
        Flash::set('error', 'Faça login para continuar.');
        $this->redirect('/login');
    }

    private function renderNotFound(): void
    {
        http_response_code(404);
        require config('paths.views') . '/errors/404.php';
        exit;
    }

    private function json(array $data, int $status = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}