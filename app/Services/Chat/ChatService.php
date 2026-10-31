<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Core\Database;
use App\Repositories\ConversationRepository;
use App\Services\Copilot\CopilotDiagnosticService;
use App\Services\Copilot\CopilotResponseValidator;
use App\Services\RAG\RAGService;
use App\Services\RAG\RAGResult;
use App\Services\Security\Audit;

/**
 * Orquestra o ciclo completo de uma pergunta no chat, com persistência
 * TRANSACIONAL: conversa (ou continuação) + pergunta + resposta + fontes são
 * gravados na mesma transação. Se qualquer etapa falhar com exceção, nada é
 * gravado (nenhuma resposta fantasma).
 *
 * Decisões documentadas:
 *  - A pergunta do usuário e a resposta local (no_context ou sucesso) são
 *    persistidas. Quando o provider de IA falha, apenas a pergunta é gravada
 *    — nenhuma resposta inventada entra no histórico.
 *  - as fontes citadas vêm sempre da recuperação local (RAGService/backend).
 *  - Auditoria registra ids, contagens e códigos de erro — nunca pergunta,
 *    resposta, contexto ou segredos.
 */
final class ChatService
{
    public const MSG_DUPLICATE = 'Requisição duplicada ignorada.';

    public const TITLE_MAX_CHARS = 60;

    private ConversationRepository $conversations;

    private RAGService $rag;

    private CopilotDiagnosticService $diagnostics;

    public function __construct(
        ?ConversationRepository $conversations = null,
        ?RAGService $rag = null,
        ?CopilotDiagnosticService $diagnostics = null
    ) {
        $this->conversations = $conversations ?? new ConversationRepository();
        $this->rag = $rag ?? new RAGService();
        $this->diagnostics = $diagnostics ?? new CopilotDiagnosticService();
    }

    /**
     * @return array<string, mixed>
     */
    public function ask(
        int $userId,
        string $question,
        ?int $conversationId = null,
        ?string $clientRequestId = null
    ): array {
        if ($conversationId !== null) {
            $existing = $this->conversations->findByUser($conversationId, $userId);

            if ($existing === null) {
                return ['status' => 'not_found'];
            }
        }

        if ($clientRequestId !== null && $clientRequestId !== '') {
            if (!$this->conversations->claimRequest($userId, $clientRequestId)) {
                return ['status' => 'duplicate', 'message' => self::MSG_DUPLICATE];
            }
        }

        $title = $this->buildTitle($question);
        $db = Database::connection();

        // Transação 1 (curta): conversa + pergunta. O histórico é lido ANTES
        // de gravar a pergunta atual, para não enviá-la duas vezes à IA.
        $db->beginTransaction();

        try {
            if ($conversationId === null) {
                $conversationId = $this->conversations->createForUser($userId, $title);
            }

            // Só turnos completos vão para o contexto da IA: perguntas sem
            // resposta (falha do provider) não contaminam a próxima chamada.
            $history = $this->conversations->recentCompletedTurns($conversationId, $this->rag->historyLimit());
            $problemStatement = $this->conversations->firstUserMessage($conversationId);

            $userMessageId = $this->conversations->addMessage($conversationId, 'user', $question, ['status' => 'ok']);

            // 7.2: diagnóstico ativo da conversa — a mensagem responde à
            // pergunta pendente e o estado vira memória para a IA.
            $session = $this->diagnostics->activeSession($conversationId);
            $answeredQuestion = false;

            if ($session !== null) {
                $answeredQuestion = $this->diagnostics->recordUserAnswer($session, $question, $userMessageId);
                $session = $this->diagnostics->activeSession($conversationId);
                $problemStatement = (string) $session['original_problem'];
            }

            $diagnosticMemory = $session !== null ? $this->diagnostics->memoryBlock($session) : null;

            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }

        // Retrieval + IA SEM transação aberta (B4): a chamada ao provider pode
        // levar até o timeout configurado e não pode segurar locks do banco.
        // Se ela lançar exceção, fica apenas a pergunta — o mesmo estado já
        // adotado quando o provider falha — e nenhuma resposta/fonte órfã.
        $result = $this->rag->answer(
            $question,
            $userId,
            null,
            $history,
            $conversationId,
            $problemStatement,
            $diagnosticMemory,
            $answeredQuestion
        );

        $assistantMessageId = null;
        $diagnostic = null;

        // Transação 2 (curta): resposta + fontes + atualização da conversa.
        $db->beginTransaction();

        try {
            if ($result->isSuccess()) {
                $assistantMessageId = $this->conversations->addMessage($conversationId, 'assistant', (string) $result->answer(), [
                    'provider' => $result->provider(),
                    'model' => $result->model(),
                    'tokens_input' => $result->promptTokens(),
                    'tokens_output' => $result->completionTokens(),
                    'tokens_total' => $result->totalTokens(),
                    'latency_ms' => $result->latencyMs(),
                    'request_id' => $result->requestId(),
                    'status' => 'ok',
                    'finish_reason' => $result->finishReason(),
                    'truncated' => $result->truncated(),
                    'structured' => $result->structured(),
                ]);

                $this->conversations->addMessageSources($assistantMessageId, $result->sources());

                if ($result->structured() !== null) {
                    $applied = $this->diagnostics->applyTurn(
                        $conversationId,
                        $session,
                        $result->structured(),
                        $result->sources(),
                        $question,
                        $assistantMessageId,
                        $answeredQuestion ? $question : null
                    );

                    if ($applied !== null) {
                        $session = $applied['session'];

                        if ($applied['structured'] !== $result->structured()) {
                            // Ex.: pergunta repetida removida pelo backend.
                            $result = $result->withStructured(
                                $applied['structured'],
                                (new CopilotResponseValidator())->render($applied['structured'])
                            );
                            $this->conversations->updateAssistantOutput($assistantMessageId, (string) $result->answer(), $applied['structured']);
                        }
                    }
                }
            }

            if ($session !== null) {
                $diagnostic = $this->diagnostics->present($session);
            }

            $this->conversations->touch($conversationId);

            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }

        $result = $result ?? new RAGResult([]);

        $this->audit($userId, $conversationId, $userMessageId, $assistantMessageId, $result);

        return [
            'status' => 'ok',
            'conversation_id' => $conversationId,
            'user_message_id' => $userMessageId,
            'assistant_message_id' => $assistantMessageId,
            'result' => $result,
            'diagnostic' => $diagnostic,
        ];
    }

    private function buildTitle(string $question): string
    {
        $title = preg_replace('/\s+/u', ' ', trim($question)) ?? trim($question);

        return mb_substr($title, 0, self::TITLE_MAX_CHARS);
    }

    private function audit(
        int $userId,
        int $conversationId,
        int $userMessageId,
        ?int $assistantMessageId,
        RAGResult $result
    ): void {
        $requestId = $result->requestId();

        Audit::record('chat.pergunta', 'conversation', $conversationId, [
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'user_message_id' => $userMessageId,
            'request_id' => $requestId,
        ]);

        if (!$result->isSuccess()) {
            Audit::record('chat.provider_failure', 'conversation', $conversationId, [
                'conversation_id' => $conversationId,
                'request_id' => $requestId,
                'error_code' => $result->errorCode(),
            ]);

            return;
        }

        if ($result->noContext()) {
            Audit::record('chat.no_context', 'conversation', $conversationId, [
                'conversation_id' => $conversationId,
                'request_id' => $requestId,
                'retrieval_count' => $result->retrievalCount(),
            ]);

            return;
        }

        if ($assistantMessageId !== null) {
            Audit::record('chat.resposta', 'message', $assistantMessageId, [
                'conversation_id' => $conversationId,
                'assistant_message_id' => $assistantMessageId,
                'request_id' => $requestId,
                'model' => $result->model(),
                'truncated' => $result->truncated(),
                'retrieval_count' => $result->retrievalCount(),
                'total_tokens' => $result->totalTokens(),
            ]);
        }
    }
}