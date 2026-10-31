<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Resposta normalizada de uma chamada ao provedor de IA. Usada tanto para o
 * fluxo de chat quanto para o teste de conexao. Nunca contem a credencial.
 */
final class AIResponse
{
    private bool $success;

    private ?string $content;

    private ?string $provider;

    private ?string $model;

    private ?int $promptTokens;

    private ?int $completionTokens;

    private ?int $totalTokens;

    private ?float $latencyMs;

    private ?string $finishReason;

    private bool $truncated;

    private ?string $requestId;

    private ?string $errorCode;

    private ?string $errorMessage;

    /** O provider devolveu raciocínio (reasoning/thought) junto da resposta. */
    private bool $hasReasoning;

    /** Metadados sanitizados e específicos do provider (ex.: finish_reason bruto). */
    private array $metadata;

    public function __construct(array $data = [])
    {
        $this->hasReasoning = (bool) ($data['has_reasoning'] ?? false);
        $this->metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $this->success = (bool) ($data['success'] ?? false);
        $this->content = isset($data['content']) ? (string) $data['content'] : null;
        $this->provider = isset($data['provider']) ? (string) $data['provider'] : null;
        $this->model = isset($data['model']) ? (string) $data['model'] : null;
        $this->promptTokens = isset($data['prompt_tokens']) ? (int) $data['prompt_tokens'] : null;
        $this->completionTokens = isset($data['completion_tokens']) ? (int) $data['completion_tokens'] : null;
        $this->totalTokens = isset($data['total_tokens']) ? (int) $data['total_tokens'] : null;
        $this->latencyMs = isset($data['latency_ms']) ? (float) $data['latency_ms'] : null;
        $this->finishReason = isset($data['finish_reason']) ? (string) $data['finish_reason'] : null;
        $this->truncated = (bool) ($data['truncated'] ?? false);
        $this->requestId = isset($data['request_id']) ? (string) $data['request_id'] : null;
        $this->errorCode = isset($data['error_code']) ? (string) $data['error_code'] : null;
        $this->errorMessage = isset($data['error_message']) ? (string) $data['error_message'] : null;
    }

    public static function success(array $data): self
    {
        $data['success'] = true;

        return new self($data);
    }

    public static function error(string $message, string $errorCode): self
    {
        return new self([
            'success' => false,
            'error_message' => $message,
            'error_code' => $errorCode,
        ]);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function content(): ?string
    {
        return $this->content;
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

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function hasReasoning(): bool
    {
        return $this->hasReasoning;
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'content' => $this->content,
            'provider' => $this->provider,
            'model' => $this->model,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
            'latency_ms' => $this->latencyMs !== null ? round($this->latencyMs, 2) : null,
            'finish_reason' => $this->finishReason,
            'truncated' => $this->truncated,
            'request_id' => $this->requestId,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'has_reasoning' => $this->hasReasoning,
            'metadata' => $this->metadata,
        ];
    }
}