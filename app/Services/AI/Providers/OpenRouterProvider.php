<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Core\Logger;
use App\Services\AI\AIException;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\AIResponse;
use App\Services\AI\Http\CurlTransport;
use App\Services\AI\Http\HttpTransportInterface;

/**
 * Provider OpenRouter (https://openrouter.ai/api/v1).
 *
 * Implementa o endpoint /chat/completions no dialeto OpenAI. A credencial é
 * enviada apenas no cabeçalho Authorization do transporte e jamais é
 * persistida em logs ou nas respostas. A base URL é validada para evitar
 * SSRF (somente hosts da lista permitida em config/app.php).
 *
 * Endpoint/documentação verificados em https://openrouter.ai/docs/api-reference
 * na data da implementação (POST https://openrouter.ai/api/v1/chat/completions).
 */
final class OpenRouterProvider implements AIProviderInterface
{
    public const PROVIDER = 'openrouter';

    public const MSG_MISSING_KEY = 'Configure a API Key do OpenRouter antes de utilizar a IA.';
    public const MSG_UNAUTHORIZED = 'A autenticação com o provedor de IA foi recusada. Verifique a API Key.';
    public const MSG_RATE_LIMITED =
        'O provedor de IA atingiu um limite temporário de requisições ou créditos. Tente novamente mais tarde ou verifique sua conta.';
    public const MSG_CONNECTION = 'Falha de conexão com o provedor de IA. Verifique rede e DNS.';
    public const MSG_TIMEOUT = 'Tempo esgotado ao aguardar o provedor de IA.';
    public const MSG_SSL = 'Falha de segurança na conexão TLS com o provedor de IA.';
    public const MSG_BAD_REQUEST = 'Requisição inválida para o provedor de IA.';
    public const MSG_FORBIDDEN = 'Acesso negado pelo provedor de IA. Verifique as permissões da chave.';
    public const MSG_NOT_FOUND = 'Endpoint ou modelo não encontrado no provedor de IA.';
    public const MSG_MODEL_UNAVAILABLE = 'O modelo selecionado está indisponível no provedor de IA.';
    public const MSG_HTTP_408 = 'O provedor de IA demorou a responder.';
    public const MSG_SERVER = 'O provedor de IA está indisponível no momento. Tente novamente mais tarde.';
    public const MSG_HTTP_GENERIC = 'O provedor de IA retornou um erro inesperado.';
    public const MSG_INVALID_JSON = 'Resposta inválida recebida do provedor de IA.';
    public const MSG_EMPTY_RESPONSE = 'O provedor de IA respondeu sem conteúdo.';
    public const MSG_REASONING_ONLY = 'O provedor de IA respondeu apenas com raciocínio e sem texto final. Tente novamente ou use um modelo com resposta final.';
    public const MSG_TRUNCATED_RESPONSE = 'O limite de tokens configurado foi atingido antes da resposta final completa. Aumente o Máx. tokens ou use um modelo maior.';
    public const MSG_PROVIDER_ERROR = 'O provedor de IA retornou um erro na resposta. Verifique o modelo e a disponibilidade do serviço.';
    public const MSG_NO_MODEL = 'O modelo de IA não está configurado para o provider.';

    private const RETRY_SLEEP_MICROSECONDS = 250000;

    /**
     * 429 (rate limit): só há nova tentativa se o provedor informar
     * Retry-After de até este valor. Acima disso, ou sem Retry-After, falha
     * imediatamente: uma requisição web não pode ficar dezenas de segundos
     * esperando, e repetir no mesmo segundo só consome mais cota.
     */
    public const MAX_RETRY_AFTER_SECONDS = 2;

    private int $currentAttempt = 1;

    private const SSL_ERROR_CODES = [35, 51, 53, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];
    private const DNS_ERROR_CODES = [3, 5, 6];
    private const TIMEOUT_ERROR_CODES = [28];

    private string $apiKey;

    private ?string $model;

    private float $temperature;

    private ?int $maxTokens;

    private int $timeoutSeconds;

    private int $connectTimeout;

    private string $baseUrl;

    private string $referer;

    private string $appTitle;

    private bool $retryTransient;

    private int $retryCount;

    private HttpTransportInterface $transport;

    public function __construct(array $config, ?HttpTransportInterface $transport = null)
    {
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->model = ($config['model'] ?? '') !== '' ? (string) $config['model'] : null;
        $this->temperature = (float) ($config['temperature'] ?? 0.2);
        $this->maxTokens = isset($config['max_tokens']) && $config['max_tokens'] !== null
            ? max(1, (int) $config['max_tokens'])
            : null;
        $this->timeoutSeconds = max(5, (int) ($config['timeout_seconds'] ?? (int) config('ai.timeout', 30)));
        $this->connectTimeout = max(1, (int) ($config['connect_timeout'] ?? (int) config('ai.connect_timeout', 10)));
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? config('ai.openrouter_base_url', 'https://openrouter.ai/api/v1')), '/');
        $this->referer = (string) ($config['referer'] ?? config('ai.app_referer', ''));
        $this->appTitle = (string) ($config['app_title'] ?? config('ai.app_title', 'assistente-unimestre'));
        $this->retryTransient = (bool) ($config['retry_transient'] ?? config('ai.retry_transient', true));
        $this->retryCount = max(0, (int) ($config['retry_count'] ?? (int) config('ai.retry_count', 1)));
        $this->transport = $transport ?? new CurlTransport();

        $this->assertValidBaseUrl();
    }

    public function name(): string
    {
        return self::PROVIDER;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function chat(array $messages): AIResponse
    {
        return $this->requestWithRetry($messages);
    }

    public function testConnection(): AIResponse
    {
        $limit = $this->maxTokens !== null ? min(64, $this->maxTokens) : 64;

        $response = $this->requestWithRetry([
            ['role' => 'user', 'content' => 'Responda apenas com a palavra OK.'],
        ], $limit);

        return $response;
    }

    private function requestWithRetry(array $messages, ?int $maxTokensOverride = null): AIResponse
    {
        if ($this->apiKey === '' || $this->apiKey === null) {
            throw new AIException(self::MSG_MISSING_KEY, 'missing_api_key');
        }

        if ($this->model === null) {
            throw new AIException(self::MSG_NO_MODEL, 'model_not_configured');
        }

        $attempts = $this->retryTransient ? $this->retryCount + 1 : 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->currentAttempt = $attempt;

            try {
                return $this->executeOnce($messages, $maxTokensOverride);
            } catch (AIException $exception) {
                $sleepMicroseconds = $this->retryDelay($exception);

                if ($sleepMicroseconds === null || $attempt >= $attempts) {
                    throw $this->withAttempts($exception, $attempt);
                }

                Logger::warning('ai', 'Falha transitória detectada, programando nova tentativa', [
                    'provider' => self::PROVIDER,
                    'model' => $this->model,
                    'error_code' => $exception->errorCode(),
                    'attempt' => $attempt,
                    'retry_in_ms' => (int) round($sleepMicroseconds / 1000),
                ]);

                usleep($sleepMicroseconds);
            }
        }

        throw new AIException('Falha inesperada na chamada ao provedor de IA.', 'unexpected');
    }

    /**
     * Espera antes da nova tentativa (µs), ou null quando não se deve repetir.
     *  - erro não transitório → null
     *  - 429 → só com Retry-After ≤ MAX_RETRY_AFTER_SECONDS (espera exatamente esse tempo)
     *  - demais transitórios (timeout, conexão, 5xx…) → pausa curta padrão
     */
    private function retryDelay(AIException $exception): ?int
    {
        if (!$exception->isTransient()) {
            return null;
        }

        if ($exception->errorCode() !== 'http_429') {
            return self::RETRY_SLEEP_MICROSECONDS;
        }

        $retryAfter = $exception->safeContext()['retry_after_seconds'] ?? null;

        if (!is_int($retryAfter) || $retryAfter > self::MAX_RETRY_AFTER_SECONDS) {
            Logger::warning('ai', 'HTTP 429 sem nova tentativa (Retry-After ausente ou alto)', [
                'provider' => self::PROVIDER,
                'model' => $this->model,
                'retry_after_seconds' => $retryAfter,
                'max_retry_after_seconds' => self::MAX_RETRY_AFTER_SECONDS,
            ]);

            return null;
        }

        return max(0, $retryAfter) * 1000000;
    }

    private function withAttempts(AIException $exception, int $attempts): AIException
    {
        return new AIException(
            $exception->getMessage(),
            $exception->errorCode(),
            $exception->httpStatus(),
            $exception->requestId(),
            array_merge($exception->safeContext(), ['attempts' => $attempts])
        );
    }

    /**
     * Retry-After sanitizado em segundos (0..86400) a partir de segundos ou
     * data HTTP. Qualquer outro formato → null. Nenhum outro cabeçalho é lido.
     *
     * @param array<string, mixed> $headers cabeçalhos da resposta (chaves minúsculas)
     */
    private function retryAfterSeconds(array $headers): ?int
    {
        $raw = $headers['retry-after'] ?? null;

        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        $raw = trim((string) $raw);

        if (preg_match('/^\d{1,6}$/', $raw) === 1) {
            return min(86400, (int) $raw);
        }

        $timestamp = strtotime($raw);

        if ($timestamp === false || preg_match('/^[A-Za-z]{3},/', $raw) !== 1) {
            return null;
        }

        return max(0, min(86400, $timestamp - time()));
    }

    private function executeOnce(array $messages, ?int $maxTokensOverride = null): AIResponse
    {
        $payload = [
            'model' => $this->model,
            'temperature' => $this->temperature,
            'stream' => false,
            'messages' => $messages,
        ];

        if ($maxTokensOverride !== null) {
            $payload['max_tokens'] = $maxTokensOverride;
        } elseif ($this->maxTokens !== null) {
            $payload['max_tokens'] = $this->maxTokens;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'HTTP-Referer' => $this->referer !== '' ? $this->referer : 'http://localhost/assistente-unimestre',
            'X-OpenRouter-Title' => $this->appTitle !== '' ? $this->appTitle : 'assistente-unimestre',
            'User-Agent' => 'assistente-unimestre/1.0',
        ];

        $result = $this->transport->postJson(
            $this->baseUrl . '/chat/completions',
            $headers,
            $payload,
            $this->connectTimeout,
            $this->timeoutSeconds
        );

        return $this->parseResult($result);
    }

    private function parseResult(array $result): AIResponse
    {
        $status = (int) $result['status'];
        $latency = (float) $result['latency_ms'];
        $requestId = $this->extractRequestId($result);

        if ($status === 0) {
            $transportError = $this->mapTransportError($result);

            Logger::error('ai', 'Falha de transporte na chamada ao provider OpenRouter', [
                'provider' => self::PROVIDER,
                'model' => $this->model,
                'error_code' => $transportError['code'],
                'transport_error' => (string) $result['error'],
                'duration_ms' => $latency,
            ]);

            throw new AIException($transportError['message'], $transportError['code'], null, $requestId);
        }

        if ($status >= 400) {
            $error = $this->mapHttpError($status, (string) $result['body']);
            $retryAfter = $this->retryAfterSeconds(is_array($result['headers'] ?? null) ? $result['headers'] : []);

            Logger::error('ai', 'Erro HTTP na chamada ao provider OpenRouter', [
                'provider' => self::PROVIDER,
                'model' => $this->model,
                'http_status' => $status,
                'error_code' => $error['code'],
                'provider_message' => $error['provider_message'],
                'retry_after_seconds' => $retryAfter,
                'attempt' => $this->currentAttempt,
                'duration_ms' => $latency,
                'request_id' => $requestId,
            ]);

            throw new AIException($error['message'], $error['code'], $status, $requestId, [
                'http_status' => $status,
                'provider_message' => $error['provider_message'],
                'retry_after_seconds' => $retryAfter,
            ]);
        }

        $decoded = json_decode((string) $result['body'], true);

        if (!is_array($decoded)) {
            Logger::error('ai', 'JSON inválido retornado pelo provider OpenRouter', [
                'provider' => self::PROVIDER,
                'model' => $this->model,
                'http_status' => $status,
                'duration_ms' => $latency,
                'request_id' => $requestId,
            ]);

            throw new AIException(self::MSG_INVALID_JSON, 'invalid_json', $status, $requestId);
        }

        if (isset($decoded['error']) && is_array($decoded['error']) && $decoded['error'] !== []) {
            $providerMessage = (string) ($decoded['error']['message'] ?? '');
            $providerMessage = $providerMessage !== ''
                ? $providerMessage
                : (string) json_encode($decoded['error'], JSON_UNESCAPED_UNICODE);
            $providerMessage = mb_strlen($providerMessage) > 200
                ? mb_substr($providerMessage, 0, 197) . '...'
                : $providerMessage;

            Logger::error('ai', 'Provider OpenRouter retornou 200 com campo error', [
                'provider' => self::PROVIDER,
                'model' => $decoded['model'] ?? $this->model,
                'http_status' => $status,
                'duration_ms' => $latency,
                'request_id' => $requestId,
                'provider_message' => $providerMessage,
            ]);

            throw new AIException(self::MSG_PROVIDER_ERROR, 'provider_error', $status, $requestId, [
                'summary' => [
                    'model_used' => $decoded['model'] ?? $this->model,
                    'http_status' => $status,
                    'provider_error' => $providerMessage,
                ],
            ]);
        }

        $choices = $decoded['choices'] ?? null;

        if (!is_array($choices) || $choices === [] || !isset($choices[0]['message'])) {
            Logger::error('ai', 'Resposta sem choices/content do provider OpenRouter', [
                'provider' => self::PROVIDER,
                'model' => $this->model,
                'http_status' => $status,
                'duration_ms' => $latency,
                'request_id' => $requestId,
            ]);

            throw new AIException(self::MSG_EMPTY_RESPONSE, 'empty_response', $status, $requestId, [
                'summary' => [
                    'model_used' => $decoded['model'] ?? $this->model,
                    'choices' => 0,
                    'content_length' => 0,
                    'has_reasoning' => false,
                ],
            ]);
        }

        $first = $choices[0];
        $content = $first['message']['content'] ?? null;
        $reasoning = $first['message']['reasoning'] ?? null;
        $finishReason = $first['finish_reason'] ?? null;

        if ($content === null && $finishReason === 'content_filter') {
            throw new AIException('O provedor de IA bloqueou o conteúdo da resposta.', 'content_filtered', $status, $requestId);
        }

        $length = is_array($content) ? count($content) : strlen((string) $content);
        $hasContent = $content !== null && $length > 0;
        $hasReasoning = is_string($reasoning) && $reasoning !== '';

        $summary = [
            'model_used' => $decoded['model'] ?? $this->model,
            'choices' => count($choices),
            'finish_reason' => $finishReason,
            'content_length' => $length,
            'has_reasoning' => $hasReasoning,
            'content_is_array' => is_array($content),
            'http_status' => $status,
        ];

        if (!$hasContent && $finishReason === 'length') {
            Logger::error('ai', 'Provider OpenRouter atingiu o limite de tokens sem entregar texto final', $summary);

            throw new AIException(self::MSG_TRUNCATED_RESPONSE, 'truncated_response', $status, $requestId, ['summary' => $summary]);
        }

        if (!$hasContent && $hasReasoning) {
            Logger::error('ai', 'Provider OpenRouter respondeu apenas com reasoning (sem texto final)', $summary);

            throw new AIException(self::MSG_REASONING_ONLY, 'reasoning_only', $status, $requestId, ['summary' => $summary]);
        }

        if (!$hasContent) {
            Logger::error('ai', 'Provider OpenRouter respondeu sem conteúdo', $summary);

            throw new AIException(self::MSG_EMPTY_RESPONSE, 'empty_response', $status, $requestId, ['summary' => $summary]);
        }

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];

        Logger::info('ai', 'Resposta obtida do provider OpenRouter', [
            'provider' => self::PROVIDER,
            'model' => $decoded['model'] ?? $this->model,
            'http_status' => $status,
            'duration_ms' => $latency,
            'request_id' => $requestId,
            'prompt_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            'completion_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            'total_tokens' => isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
            'finish_reason' => $finishReason,
            'content_length' => $length,
            'has_reasoning' => $hasReasoning,
        ]);

        return AIResponse::success([
            'content' => is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE),
            'provider' => self::PROVIDER,
            'model' => $decoded['model'] ?? $this->model,
            'prompt_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            'completion_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            'total_tokens' => isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
            'latency_ms' => $latency,
            'finish_reason' => $finishReason,
            'truncated' => $finishReason === 'length',
            'request_id' => $requestId,
        ]);
    }

    /**
     * @return array{message: string, code: string}
     */
    private function mapTransportError(array $result): array
    {
        $errorNo = (int) $result['error_no'];
        $error = (string) $result['error'];

        if (in_array($errorNo, self::TIMEOUT_ERROR_CODES, true)) {
            return ['message' => self::MSG_TIMEOUT, 'code' => 'timeout'];
        }

        if (in_array($errorNo, self::SSL_ERROR_CODES, true)) {
            return ['message' => self::MSG_SSL, 'code' => 'ssl_error'];
        }

        if (in_array($errorNo, self::DNS_ERROR_CODES, true) || str_contains($error, 'Could not resolve host')) {
            return ['message' => self::MSG_CONNECTION, 'code' => 'dns_error'];
        }

        return ['message' => self::MSG_CONNECTION, 'code' => 'connection_error'];
    }

    /**
     * @return array{message: string, code: string, provider_message: ?string}
     */
    private function mapHttpError(int $status, string $body): array
    {
        $decoded = json_decode($body, true);
        $providerMessage = is_array($decoded)
            ? (string) ($decoded['error']['message'] ?? '')
            : '';

        if ($providerMessage === '') {
            $providerMessage = null;
        }

        $containsModel = $providerMessage !== null && stripos($providerMessage, 'model') !== false;

        switch (true) {
            case $status === 400:
                return ['message' => self::MSG_BAD_REQUEST, 'code' => 'http_400', 'provider_message' => $providerMessage];
            case $status === 401:
                return ['message' => self::MSG_UNAUTHORIZED, 'code' => 'http_401', 'provider_message' => $providerMessage];
            case $status === 403:
                return ['message' => self::MSG_FORBIDDEN, 'code' => 'http_403', 'provider_message' => $providerMessage];
            case $status === 404:
                $code = $containsModel ? 'model_unavailable' : 'http_404';
                $message = $containsModel ? self::MSG_MODEL_UNAVAILABLE : self::MSG_NOT_FOUND;

                return ['message' => $message, 'code' => $code, 'provider_message' => $providerMessage];
            case $status === 408:
                return ['message' => self::MSG_HTTP_408, 'code' => 'http_408', 'provider_message' => $providerMessage];
            case $status === 429:
                return ['message' => self::MSG_RATE_LIMITED, 'code' => 'http_429', 'provider_message' => $providerMessage];
            case $status >= 500:
                return ['message' => self::MSG_SERVER, 'code' => 'http_5xx', 'provider_message' => $providerMessage];
            default:
                return ['message' => self::MSG_HTTP_GENERIC, 'code' => 'http_error', 'provider_message' => $providerMessage];
        }
    }

    private function extractRequestId(array $result): ?string
    {
        $headers = is_array($result['headers'] ?? null) ? $result['headers'] : [];

        foreach (['x-request-id', 'x-or-request-id', 'x-openrouter-request-id'] as $key) {
            if (isset($headers[$key]) && $headers[$key] !== '') {
                return (string) $headers[$key];
            }
        }

        return null;
    }

    private function assertValidBaseUrl(): void
    {
        $parts = parse_url($this->baseUrl);

        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            throw new AIException('Base URL inválida para o provider OpenRouter.', 'invalid_base_url');
        }

        $host = strtolower((string) $parts['host']);
        $allowed = (array) config('ai.allowed_base_urls', []);

        foreach ($allowed as $allowedUrl) {
            $allowedHost = strtolower((string) parse_url((string) $allowedUrl, PHP_URL_HOST));

            if ($allowedHost !== '' && ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost))) {
                return;
            }
        }

        throw new AIException('Host da base URL não permitido para o provider OpenRouter.', 'invalid_base_url');
    }
}