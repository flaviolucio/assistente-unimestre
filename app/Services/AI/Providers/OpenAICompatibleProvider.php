<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Services\AI\AIResponse;

/**
 * Providers com endpoint no estilo OpenAI (POST {base}/chat/completions,
 * Authorization: Bearer). Groq e NVIDIA estendem esta classe e ajustam apenas
 * nome, URL base e o campo de limite de tokens.
 *
 * Nada aqui é específico de um modelo: raciocínio é lido de
 * message.reasoning OU message.reasoning_content, quando presente.
 */
abstract class OpenAICompatibleProvider extends AbstractHttpProvider
{
    /** Nome do campo de limite de saída aceito pelo provider. */
    protected function maxTokensField(): string
    {
        return 'max_tokens';
    }

    protected function buildRequest(array $messages, ?int $maxTokens): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $this->temperature,
            'stream' => false,
        ];

        if ($maxTokens !== null) {
            $payload[$this->maxTokensField()] = $maxTokens;
        }

        return [
            'url' => $this->baseUrl . '/chat/completions',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'payload' => $payload,
        ];
    }

    protected function parseSuccess(array $decoded, int $status, float $latency, ?string $requestId, array $headers): AIResponse
    {
        $requestId ??= isset($decoded['id']) && is_string($decoded['id']) ? mb_substr($decoded['id'], 0, 100) : null;
        $choice = is_array($decoded['choices'][0] ?? null) ? $decoded['choices'][0] : [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];

        $content = $message['content'] ?? '';
        $content = is_string($content) ? $content : $this->flattenContent($content);
        $reasoning = $message['reasoning'] ?? $message['reasoning_content'] ?? null;
        $hasReasoning = is_string($reasoning) && trim($reasoning) !== '';
        $rawFinish = isset($choice['finish_reason']) ? (string) $choice['finish_reason'] : null;
        $finish = $rawFinish === 'content_filter' ? 'content_filter' : $rawFinish;
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $modelUsed = isset($decoded['model']) && is_string($decoded['model']) ? $decoded['model'] : $this->model;

        $this->assertUsableContent(trim($content), $hasReasoning, $finish, $status, $requestId, [
            'model_used' => $modelUsed,
            'choices' => is_array($decoded['choices'] ?? null) ? count($decoded['choices']) : 0,
            'finish_reason' => $rawFinish,
            'content_length' => mb_strlen($content),
            'has_reasoning' => $hasReasoning,
            'duration_ms' => $latency,
        ]);

        $response = AIResponse::success([
            'content' => $content,
            'provider' => $this->name(),
            'model' => $modelUsed,
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'latency_ms' => $latency,
            'finish_reason' => $finish,
            'truncated' => $finish === 'length',
            'request_id' => $requestId,
            'has_reasoning' => $hasReasoning,
            'metadata' => ['finish_reason_raw' => $rawFinish],
        ]);

        $this->logSuccess($response, $status);

        return $response;
    }

    /** Conteúdo em partes (array) → texto. */
    private function flattenContent(mixed $content): string
    {
        if (!is_array($content)) {
            return '';
        }

        $text = '';

        foreach ($content as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        return $text;
    }
}
