<?php

declare(strict_types=1);

namespace App\Services\RAG;

/**
 * Resultado normalizado de uma execução RAG (recuperação + resposta da IA).
 *
 * Carrega apenas dados seguros para logs, auditoria e interface: nada de
 * reasoning/chain-of-thought, API Key, prompt bruto ou conteúdo do contexto.
 */
final class RAGResult
{
    private bool $success;

    private ?string $answer;

    /** @var array<int, array<string, mixed>> */
    private array $sources;

    private ?string $provider;

    private ?string $model;

    private ?int $promptTokens;

    private ?int $completionTokens;

    private ?int $totalTokens;

    private ?float $latencyMs;

    private ?string $finishReason;

    private bool $truncated;

    private int $retrievalCount;

    private float $topScore;

    private bool $aiCalled;

    private bool $noContext;

    private ?string $errorCode;

    private ?string $errorMessage;

    private string $requestId;

    /** @var array<string, mixed>|null resposta estruturada validada (Etapa 7.1) */
    private ?array $structured;

    public function __construct(array $data = [])
    {
        $this->success = (bool) ($data['success'] ?? false);
        $this->answer = isset($data['answer']) ? (string) $data['answer'] : null;
        $this->sources = (array) ($data['sources'] ?? []);
        $this->provider = isset($data['provider']) ? (string) $data['provider'] : null;
        $this->model = isset($data['model']) ? (string) $data['model'] : null;
        $this->promptTokens = isset($data['prompt_tokens']) ? (int) $data['prompt_tokens'] : null;
        $this->completionTokens = isset($data['completion_tokens']) ? (int) $data['completion_tokens'] : null;
        $this->totalTokens = isset($data['total_tokens']) ? (int) $data['total_tokens'] : null;
        $this->latencyMs = isset($data['latency_ms']) ? (float) $data['latency_ms'] : null;
        $this->finishReason = isset($data['finish_reason']) ? (string) $data['finish_reason'] : null;
        $this->truncated = (bool) ($data['truncated'] ?? false);
        $this->retrievalCount = (int) ($data['retrieval_count'] ?? 0);
        $this->topScore = (float) ($data['top_score'] ?? 0.0);
        $this->aiCalled = (bool) ($data['ai_called'] ?? false);
        $this->noContext = (bool) ($data['no_context'] ?? false);
        $this->errorCode = isset($data['error_code']) ? (string) $data['error_code'] : null;
        $this->errorMessage = isset($data['error_message']) ? (string) $data['error_message'] : null;
        $this->requestId = (string) ($data['request_id'] ?? '');
        $this->structured = isset($data['structured']) && is_array($data['structured']) ? $data['structured'] : null;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function answer(): ?string
    {
        return $this->answer;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function promptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function completionTokens(): ?int
    {
        return $this->completionTokens;
    }

    public function totalTokens(): ?int
    {
        return $this->totalTokens;
    }

    public function latencyMs(): ?float
    {
        return $this->latencyMs;
    }

    public function finishReason(): ?string
    {
        return $this->finishReason;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    public function retrievalCount(): int
    {
        return $this->retrievalCount;
    }

    public function topScore(): float
    {
        return $this->topScore;
    }

    public function aiCalled(): bool
    {
        return $this->aiCalled;
    }

    public function noContext(): bool
    {
        return $this->noContext;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    /**
     * Cópia com a estrutura ajustada pelo estado do diagnóstico (7.2).
     *
     * @param array<string, mixed> $structured
     */
    public function withStructured(array $structured, string $answer): self
    {
        $clone = clone $this;
        $clone->structured = $structured;
        $clone->answer = $answer;

        return $clone;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function structured(): ?array
    {
        return $this->structured;
    }

    /**
     * @return array{success: bool, answer: ?string, sources: array<int, mixed>, provider: ?string, model: ?string, prompt_tokens: ?int, completion_tokens: ?int, total_tokens: ?int, latency_ms: ?float, finish_reason: ?string, truncated: bool, retrieval_count: int, top_score: float, ai_called: bool, no_context: bool, error_code: ?string, error_message: ?string, request_id: string}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'answer' => $this->answer,
            'sources' => $this->sources,
            'provider' => $this->provider,
            'model' => $this->model,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
            'latency_ms' => $this->latencyMs !== null ? round($this->latencyMs, 2) : null,
            'finish_reason' => $this->finishReason,
            'truncated' => $this->truncated,
            'retrieval_count' => $this->retrievalCount,
            'top_score' => round($this->topScore, 2),
            'ai_called' => $this->aiCalled,
            'no_context' => $this->noContext,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'request_id' => $this->requestId,
            'structured' => $this->structured,
        ];
    }
}