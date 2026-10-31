<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Services\AI\AIException;
use App\Services\AI\AIResponse;

/**
 * Google Gemini API — REST generateContent.
 *   POST {base}/models/{model}:generateContent
 *   Cabeçalho x-goog-api-key (a chave NUNCA vai na URL/query string).
 *
 * Adaptador de mensagens (o resto do sistema não conhece o formato Google):
 *   system            → systemInstruction.parts[].text (todas concatenadas)
 *   user / assistant  → contents[] com role "user" / "model"
 *   papéis repetidos consecutivos são unidos (a API espera alternância)
 * Resposta:
 *   candidates[0].content.parts[] com text; partes com "thought": true são
 *   raciocínio (não entram no conteúdo). finishReason é normalizado:
 *   STOP→stop, MAX_TOKENS→length, SAFETY/RECITATION/BLOCKLIST/…→content_filter.
 */
final class GeminiProvider extends AbstractHttpProvider
{
    public const PROVIDER = 'gemini';

    private const FILTER_REASONS = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY'];

    private const STATUS_CODES = [
        'UNAUTHENTICATED' => 'authentication_error',
        'PERMISSION_DENIED' => 'authentication_error',
        'RESOURCE_EXHAUSTED' => 'rate_limited',
        'NOT_FOUND' => 'model_unavailable',
        'UNAVAILABLE' => 'upstream_overloaded',
        'DEADLINE_EXCEEDED' => 'timeout',
        'INVALID_ARGUMENT' => 'invalid_request',
        'FAILED_PRECONDITION' => 'invalid_request',
        'INTERNAL' => 'provider_error',
    ];

    public function name(): string
    {
        return self::PROVIDER;
    }

    public function label(): string
    {
        return 'Google Gemini';
    }

    protected function defaultBaseUrl(): string
    {
        return (string) config('ai.base_urls.gemini', 'https://generativelanguage.googleapis.com/v1beta');
    }

    protected function buildRequest(array $messages, ?int $maxTokens): array
    {
        $model = preg_replace('#^models/#', '', (string) $this->model) ?? '';

        if (preg_match('/^[A-Za-z0-9._-]+$/', $model) !== 1) {
            throw new AIException(self::MSG_INVALID_REQUEST, 'invalid_request', null, null, [
                'reason' => 'Identificador de modelo Gemini inválido.',
            ]);
        }

        $payload = $this->toGeminiFormat($messages);
        $generation = ['temperature' => $this->temperature];

        if ($maxTokens !== null) {
            $generation['maxOutputTokens'] = $maxTokens;
        }

        $payload['generationConfig'] = $generation;

        return [
            'url' => $this->baseUrl . '/models/' . rawurlencode($model) . ':generateContent',
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'payload' => $payload,
        ];
    }

    /**
     * Converte mensagens internas (role/content) para systemInstruction + contents.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<string, mixed>
     */
    public function toGeminiFormat(array $messages): array
    {
        $system = [];
        $contents = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $system[] = $message['content'];
                continue;
            }

            $role = $message['role'] === 'assistant' ? 'model' : 'user';
            $last = count($contents) - 1;

            if ($last >= 0 && $contents[$last]['role'] === $role) {
                $contents[$last]['parts'][0]['text'] .= "\n\n" . $message['content'];
            } else {
                $contents[] = ['role' => $role, 'parts' => [['text' => $message['content']]]];
            }
        }

        // A conversa precisa começar pelo usuário.
        if ($contents !== [] && $contents[0]['role'] === 'model') {
            array_unshift($contents, ['role' => 'user', 'parts' => [['text' => '(continuação da conversa)']]]);
        }

        $payload = ['contents' => $contents];

        if ($system !== []) {
            $payload['systemInstruction'] = ['parts' => [['text' => implode("\n\n", $system)]]];
        }

        return $payload;
    }

    protected function mapHttpError(int $status, array $decoded): array
    {
        [$code, $message, $providerMessage] = parent::mapHttpError($status, $decoded);
        $grpcStatus = is_array($decoded['error'] ?? null) ? (string) ($decoded['error']['status'] ?? '') : '';

        if (isset(self::STATUS_CODES[$grpcStatus])) {
            $code = self::STATUS_CODES[$grpcStatus];
        }

        // Chave inválida chega como 400 INVALID_ARGUMENT "API key not valid".
        if ($providerMessage !== null && preg_match('/api[\s_-]?key/i', $providerMessage) === 1) {
            $code = 'authentication_error';
        }

        return [$code, $this->messageFor($code), $providerMessage];
    }

    protected function parseSuccess(array $decoded, int $status, float $latency, ?string $requestId, array $headers): AIResponse
    {
        $requestId ??= isset($decoded['responseId']) && is_string($decoded['responseId']) ? mb_substr($decoded['responseId'], 0, 100) : null;
        $modelUsed = isset($decoded['modelVersion']) && is_string($decoded['modelVersion']) ? $decoded['modelVersion'] : $this->model;
        $candidate = is_array($decoded['candidates'][0] ?? null) ? $decoded['candidates'][0] : null;
        $blockReason = $decoded['promptFeedback']['blockReason'] ?? null;

        if ($candidate === null) {
            $finish = $blockReason !== null ? 'content_filter' : null;
            $this->assertUsableContent('', false, $finish, $status, $requestId, [
                'model_used' => $modelUsed,
                'candidates' => 0,
                'block_reason' => is_string($blockReason) ? $blockReason : null,
                'duration_ms' => $latency,
            ]);
        }

        $content = '';
        $hasReasoning = false;

        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
            if (!is_array($part) || !isset($part['text']) || !is_string($part['text'])) {
                continue;
            }

            if (!empty($part['thought'])) {
                $hasReasoning = true;
                continue;
            }

            $content .= $part['text'];
        }

        $usage = is_array($decoded['usageMetadata'] ?? null) ? $decoded['usageMetadata'] : [];
        $hasReasoning = $hasReasoning || (int) ($usage['thoughtsTokenCount'] ?? 0) > 0;
        $rawFinish = isset($candidate['finishReason']) ? (string) $candidate['finishReason'] : null;
        $finish = match (true) {
            $rawFinish === 'STOP' => 'stop',
            $rawFinish === 'MAX_TOKENS' => 'length',
            in_array($rawFinish, self::FILTER_REASONS, true) => 'content_filter',
            $rawFinish === null => null,
            default => strtolower($rawFinish),
        };

        $this->assertUsableContent(trim($content), $hasReasoning, $finish, $status, $requestId, [
            'model_used' => $modelUsed,
            'candidates' => count((array) ($decoded['candidates'] ?? [])),
            'finish_reason' => $rawFinish,
            'content_length' => mb_strlen($content),
            'has_reasoning' => $hasReasoning,
            'duration_ms' => $latency,
        ]);

        $response = AIResponse::success([
            'content' => $content,
            'provider' => self::PROVIDER,
            'model' => $modelUsed,
            'prompt_tokens' => $usage['promptTokenCount'] ?? null,
            'completion_tokens' => $usage['candidatesTokenCount'] ?? null,
            'total_tokens' => $usage['totalTokenCount'] ?? null,
            'latency_ms' => $latency,
            'finish_reason' => $finish,
            'truncated' => $finish === 'length',
            'request_id' => $requestId,
            'has_reasoning' => $hasReasoning,
            'metadata' => [
                'finish_reason_raw' => $rawFinish,
                'thoughts_tokens' => isset($usage['thoughtsTokenCount']) ? (int) $usage['thoughtsTokenCount'] : null,
            ],
        ]);

        $this->logSuccess($response, $status);

        return $response;
    }
}
