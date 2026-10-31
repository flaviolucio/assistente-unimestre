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
 * Base dos providers HTTP novos (Gemini, Groq, NVIDIA).
 *
 * Centraliza o que é comum e sensível:
 *  - política de nova tentativa (429/sobrecarga só com Retry-After curto;
 *    timeout NÃO é repetido; falha de conexão tem 1 nova tentativa curta);
 *  - mapeamento de erros de transporte/HTTP para códigos NORMALIZADOS:
 *    authentication_error, rate_limited, model_unavailable, upstream_overloaded,
 *    timeout, connection_error, invalid_request, provider_error;
 *  - erro dentro de HTTP 200;
 *  - allowlist EXATA de host (URL base vem só da configuração);
 *  - logs e mensagens sem API Key (redação defensiva).
 *
 * O OpenRouterProvider segue com implementação própria nesta fase (não foi
 * refatorado para não arriscar o que já está validado).
 */
abstract class AbstractHttpProvider implements AIProviderInterface
{
    public const MSG_MISSING_KEY = 'Configure a API Key deste provider antes de utilizar a IA.';
    public const MSG_NO_MODEL = 'O modelo de IA não está configurado para o provider.';
    public const MSG_AUTH = 'A autenticação com o provedor de IA foi recusada. Verifique a API Key.';
    public const MSG_RATE_LIMITED = 'Limite de uso do provedor de IA atingido. Tente novamente em instantes.';
    public const MSG_MODEL_UNAVAILABLE = 'O modelo selecionado está indisponível ou não existe neste provedor.';
    public const MSG_OVERLOADED = 'O provedor de IA está sobrecarregado no momento. Tente novamente mais tarde.';
    public const MSG_TIMEOUT = 'Tempo esgotado ao aguardar o provedor de IA.';
    public const MSG_CONNECTION = 'Falha de conexão com o provedor de IA. Verifique rede e DNS.';
    public const MSG_INVALID_REQUEST = 'O provedor de IA recusou a requisição (parâmetros ou modelo inválidos).';
    public const MSG_PROVIDER_ERROR = 'O provedor de IA retornou um erro inesperado.';
    public const MSG_INVALID_JSON = 'Resposta inválida recebida do provedor de IA.';
    public const MSG_EMPTY_RESPONSE = 'O provedor de IA respondeu sem conteúdo.';
    public const MSG_REASONING_ONLY = 'O provedor de IA respondeu apenas com raciocínio e sem texto final. Aumente o Máx. tokens ou use um modelo com resposta direta.';
    public const MSG_TRUNCATED = 'O limite de tokens foi atingido antes da resposta final. Aumente o Máx. tokens.';
    public const MSG_CONTENT_FILTERED = 'O provedor de IA bloqueou o conteúdo da resposta.';

    /** 429/sobrecarga: nova tentativa só com Retry-After até este valor. */
    public const MAX_RETRY_AFTER_SECONDS = 2;

    /** Limite do teste de conexão (mínimo, mas suficiente para modelos com raciocínio curto). */
    public const TEST_MAX_TOKENS = 256;

    private const RETRY_SLEEP_MICROSECONDS = 250000;
    private const TIMEOUT_ERRNO = [28];
    private const OVERLOAD_PATTERN = '/overload|temporarily|capacity|unavailable|try again later/i';

    protected string $apiKey;
    protected ?string $model;
    protected float $temperature;
    protected ?int $maxTokens;
    protected int $timeoutSeconds;
    protected int $connectTimeout;
    protected string $baseUrl;
    protected bool $retryTransient;
    protected int $retryCount;
    protected HttpTransportInterface $transport;
    protected int $currentAttempt = 1;

    public function __construct(array $config, ?HttpTransportInterface $transport = null)
    {
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->model = trim((string) ($config['model'] ?? '')) !== '' ? trim((string) $config['model']) : null;
        $this->temperature = (float) ($config['temperature'] ?? 0.2);
        $this->maxTokens = isset($config['max_tokens']) && $config['max_tokens'] !== null ? max(1, (int) $config['max_tokens']) : null;
        $this->timeoutSeconds = max(5, (int) ($config['timeout_seconds'] ?? (int) config('ai.timeout', 30)));
        $this->connectTimeout = max(1, (int) ($config['connect_timeout'] ?? (int) config('ai.connect_timeout', 10)));
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? $this->defaultBaseUrl()), '/');
        $this->retryTransient = (bool) ($config['retry_transient'] ?? config('ai.retry_transient', true));
        $this->retryCount = max(0, (int) ($config['retry_count'] ?? (int) config('ai.retry_count', 1)));
        $this->transport = $transport ?? new CurlTransport();
    }

    /** Identificador estável (openrouter|gemini|groq|nvidia). */
    abstract public function name(): string;

    /** Nome legível usado em logs. */
    abstract public function label(): string;

    abstract protected function defaultBaseUrl(): string;

    /**
     * Monta a requisição no formato nativo do provider.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{url: string, headers: array<string, string>, payload: array<string, mixed>}
     */
    abstract protected function buildRequest(array $messages, ?int $maxTokens): array;

    /** Converte a resposta nativa (HTTP 2xx, sem campo de erro) em AIResponse. */
    abstract protected function parseSuccess(array $decoded, int $status, float $latency, ?string $requestId, array $headers): AIResponse;

    public function model(): ?string
    {
        return $this->model;
    }

    public function chat(array $messages): AIResponse
    {
        return $this->requestWithRetry($messages, null);
    }

    public function testConnection(): AIResponse
    {
        $limit = $this->maxTokens !== null ? min(self::TEST_MAX_TOKENS, $this->maxTokens) : self::TEST_MAX_TOKENS;

        return $this->requestWithRetry([
            ['role' => 'user', 'content' => 'Responda apenas com a palavra OK.'],
        ], $limit);
    }

    /**
     * Normaliza a lista de mensagens internas: papéis user/assistant/system e
     * conteúdo string não vazio.
     *
     * @return array<int, array{role: string, content: string}>
     */
    protected function normalizeMessages(array $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $role = in_array($role, ['system', 'user', 'assistant'], true) ? $role : 'user';
            $content = trim((string) ($message['content'] ?? ''));

            if ($content !== '') {
                $out[] = ['role' => $role, 'content' => $content];
            }
        }

        return $out;
    }

    protected function requestWithRetry(array $messages, ?int $maxTokensOverride): AIResponse
    {
        if ($this->apiKey === '') {
            throw new AIException(self::MSG_MISSING_KEY, 'missing_api_key');
        }

        if ($this->model === null) {
            throw new AIException(self::MSG_NO_MODEL, 'model_not_configured');
        }

        $this->assertAllowedHost();

        $attempts = $this->retryTransient ? $this->retryCount + 1 : 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->currentAttempt = $attempt;

            try {
                return $this->executeOnce($messages, $maxTokensOverride);
            } catch (AIException $exception) {
                $sleep = $this->retryDelay($exception);

                if ($sleep === null || $attempt >= $attempts) {
                    throw new AIException(
                        $exception->getMessage(),
                        $exception->errorCode(),
                        $exception->httpStatus(),
                        $exception->requestId(),
                        array_merge($exception->safeContext(), ['attempts' => $attempt])
                    );
                }

                Logger::warning('ai', 'Falha transitória detectada, programando nova tentativa', [
                    'provider' => $this->name(),
                    'model' => $this->model,
                    'error_code' => $exception->errorCode(),
                    'attempt' => $attempt,
                    'retry_in_ms' => (int) round($sleep / 1000),
                ]);

                usleep($sleep);
            }
        }

        throw new AIException(self::MSG_PROVIDER_ERROR, 'provider_error');
    }

    /** Espera (µs) antes da nova tentativa, ou null para não repetir. */
    protected function retryDelay(AIException $exception): ?int
    {
        $code = $exception->errorCode();

        if (in_array($code, ['rate_limited', 'upstream_overloaded'], true)) {
            $retryAfter = $exception->safeContext()['retry_after_seconds'] ?? null;

            return is_int($retryAfter) && $retryAfter <= self::MAX_RETRY_AFTER_SECONDS ? max(0, $retryAfter) * 1000000 : null;
        }

        // Timeout não é repetido: dobraria a espera de uma requisição web.
        if ($code === 'connection_error') {
            return self::RETRY_SLEEP_MICROSECONDS;
        }

        return null;
    }

    protected function executeOnce(array $messages, ?int $maxTokensOverride): AIResponse
    {
        $request = $this->buildRequest($this->normalizeMessages($messages), $maxTokensOverride ?? $this->maxTokens);
        $headers = array_merge(['User-Agent' => 'assistente-unimestre/1.0'], $request['headers']);

        $result = $this->transport->postJson(
            $request['url'],
            $headers,
            $request['payload'],
            $this->connectTimeout,
            $this->timeoutSeconds
        );

        return $this->parseResult($result);
    }

    protected function parseResult(array $result): AIResponse
    {
        $status = (int) ($result['status'] ?? 0);
        $latency = (float) ($result['latency_ms'] ?? 0.0);
        $headers = is_array($result['headers'] ?? null) ? $result['headers'] : [];
        $requestId = $this->requestIdFromHeaders($headers);
        $body = (string) ($result['body'] ?? '');

        if ($status === 0) {
            $errno = (int) ($result['error_no'] ?? 0);
            $code = in_array($errno, self::TIMEOUT_ERRNO, true) ? 'timeout' : 'connection_error';
            $this->logFailure('Falha de transporte', $code, null, $latency, $requestId, null, null);

            throw new AIException($code === 'timeout' ? self::MSG_TIMEOUT : self::MSG_CONNECTION, $code, null, $requestId, [
                'transport_errno' => $errno,
            ]);
        }

        $decoded = json_decode($body, true);

        if ($status >= 400) {
            [$code, $message, $providerMessage] = $this->mapHttpError($status, is_array($decoded) ? $decoded : []);
            $retryAfter = $this->retryAfterSeconds($headers);
            $this->logFailure('Erro HTTP', $code, $status, $latency, $requestId, $providerMessage, $retryAfter);

            throw new AIException($message, $code, $status, $requestId, [
                'http_status' => $status,
                'provider_message' => $providerMessage,
                'retry_after_seconds' => $retryAfter,
            ]);
        }

        if (!is_array($decoded)) {
            $this->logFailure('JSON inválido', 'invalid_json', $status, $latency, $requestId, null, null);

            throw new AIException(self::MSG_INVALID_JSON, 'invalid_json', $status, $requestId);
        }

        $bodyError = $this->errorInBody($decoded);

        if ($bodyError !== null) {
            // HTTP 2xx com objeto de erro (ex.: upstream sobrecarregado).
            $providerMessage = $this->sanitize($bodyError);
            $code = preg_match(self::OVERLOAD_PATTERN, $providerMessage) === 1 ? 'upstream_overloaded' : 'provider_error';
            $retryAfter = $this->retryAfterSeconds($headers);
            $this->logFailure('Resposta 2xx com campo de erro', $code, $status, $latency, $requestId, $providerMessage, $retryAfter);

            throw new AIException($code === 'upstream_overloaded' ? self::MSG_OVERLOADED : self::MSG_PROVIDER_ERROR, $code, $status, $requestId, [
                'http_status' => $status,
                'provider_message' => $providerMessage,
                'retry_after_seconds' => $retryAfter,
            ]);
        }

        return $this->parseSuccess($decoded, $status, $latency, $requestId, $headers);
    }

    /** Mensagem de erro no corpo de uma resposta 2xx, se houver. */
    protected function errorInBody(array $decoded): ?string
    {
        if (isset($decoded['error']) && $decoded['error'] !== [] && $decoded['error'] !== null) {
            $error = $decoded['error'];

            return is_array($error)
                ? (string) ($error['message'] ?? json_encode($error, JSON_UNESCAPED_UNICODE))
                : (string) $error;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string, 2: ?string} [código, mensagem ao usuário, mensagem do provedor sanitizada]
     */
    protected function mapHttpError(int $status, array $decoded): array
    {
        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $raw = (string) ($error['message'] ?? (is_string($decoded['error'] ?? null) ? $decoded['error'] : ($decoded['message'] ?? '')));
        $providerMessage = $raw !== '' ? $this->sanitize($raw) : null;
        $mentionsModel = $providerMessage !== null && preg_match('/model/i', $providerMessage) === 1;
        $mentionsKey = $providerMessage !== null && preg_match('/api[\s_-]?key|unauthori[sz]ed|authenticat/i', $providerMessage) === 1;

        $code = match (true) {
            $status === 401, $status === 403 => 'authentication_error',
            $status === 400 && $mentionsKey => 'authentication_error',
            $status === 404 => 'model_unavailable',
            $status === 400 && $mentionsModel => 'model_unavailable',
            $status === 408, $status === 504 => 'timeout',
            $status === 429 => 'rate_limited',
            in_array($status, [502, 503, 529], true) => 'upstream_overloaded',
            $status === 400, $status === 413, $status === 422 => 'invalid_request',
            default => 'provider_error',
        };

        return [$code, $this->messageFor($code), $providerMessage];
    }

    protected function messageFor(string $code): string
    {
        return match ($code) {
            'authentication_error' => self::MSG_AUTH,
            'rate_limited' => self::MSG_RATE_LIMITED,
            'model_unavailable' => self::MSG_MODEL_UNAVAILABLE,
            'upstream_overloaded' => self::MSG_OVERLOADED,
            'timeout' => self::MSG_TIMEOUT,
            'connection_error' => self::MSG_CONNECTION,
            'invalid_request' => self::MSG_INVALID_REQUEST,
            default => self::MSG_PROVIDER_ERROR,
        };
    }

    /**
     * Garante que o conteúdo foi entregue; senão lança erro normalizado.
     * $finishReason já normalizado para stop|length|content_filter|outro.
     */
    protected function assertUsableContent(string $content, bool $hasReasoning, ?string $finishReason, int $status, ?string $requestId, array $summary): void
    {
        if ($content !== '') {
            return;
        }

        [$code, $message] = match (true) {
            $finishReason === 'content_filter' => ['content_filtered', self::MSG_CONTENT_FILTERED],
            $finishReason === 'length' && $hasReasoning => ['reasoning_only', self::MSG_REASONING_ONLY],
            $finishReason === 'length' => ['truncated_response', self::MSG_TRUNCATED],
            $hasReasoning => ['reasoning_only', self::MSG_REASONING_ONLY],
            default => ['empty_response', self::MSG_EMPTY_RESPONSE],
        };

        $this->logFailure('Resposta sem texto final utilizável', $code, $status, (float) ($summary['duration_ms'] ?? 0.0), $requestId, null, null, $summary);

        throw new AIException($message, $code, $status, $requestId, ['summary' => $summary]);
    }

    protected function logSuccess(AIResponse $response, int $status): void
    {
        Logger::info('ai', 'Resposta obtida do provider ' . $this->label(), [
            'provider' => $this->name(),
            'model' => $this->model,
            'model_used' => $response->model(),
            'http_status' => $status,
            'duration_ms' => $response->latencyMs(),
            'request_id' => $response->requestId(),
            'finish_reason' => $response->finishReason(),
            'truncated' => $response->truncated(),
            'has_reasoning' => $response->hasReasoning(),
            'content_length' => mb_strlen((string) $response->content()),
            'usage_total' => $response->totalTokens(),
            'attempt' => $this->currentAttempt,
        ]);
    }

    protected function logFailure(
        string $what,
        string $code,
        ?int $status,
        float $latency,
        ?string $requestId,
        ?string $providerMessage,
        ?int $retryAfter,
        array $extra = []
    ): void {
        Logger::error('ai', $what . ' no provider ' . $this->label(), array_merge([
            'provider' => $this->name(),
            'model' => $this->model,
            'http_status' => $status,
            'error_code' => $code,
            'error_category' => AIException::categoryFor($code),
            'provider_message' => $providerMessage,
            'retry_after_seconds' => $retryAfter,
            'attempt' => $this->currentAttempt,
            'duration_ms' => $latency,
            'request_id' => $requestId,
        ], $extra));
    }

    /** Retry-After sanitizado (0..86400 s) a partir de segundos ou data HTTP. */
    protected function retryAfterSeconds(array $headers): ?int
    {
        $raw = $headers['retry-after'] ?? null;

        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        $raw = trim((string) $raw);

        if (preg_match('/^\d{1,6}$/', $raw) === 1) {
            return min(86400, (int) $raw);
        }

        if (preg_match('/^[A-Za-z]{3},/', $raw) === 1 && ($ts = strtotime($raw)) !== false) {
            return max(0, min(86400, $ts - time()));
        }

        return null;
    }

    protected function requestIdFromHeaders(array $headers): ?string
    {
        foreach (['x-request-id', 'x-goog-request-id', 'request-id', 'nvcf-reqid'] as $key) {
            if (isset($headers[$key]) && is_string($headers[$key]) && $headers[$key] !== '') {
                return mb_substr($headers[$key], 0, 100);
            }
        }

        return null;
    }

    /**
     * Mensagem do provedor segura para log/diagnóstico: limitada e sem nada
     * que pareça credencial (inclusive a própria chave configurada).
     */
    protected function sanitize(string $message): string
    {
        if ($this->apiKey !== '') {
            $message = str_replace($this->apiKey, '[REDACTED]', $message);
        }

        $message = preg_replace('/\b(sk-[A-Za-z0-9_\-]{6,}|gsk_[A-Za-z0-9]{6,}|nvapi-[A-Za-z0-9_\-]{6,}|AIza[0-9A-Za-z_\-]{10,})/', '[REDACTED]', $message) ?? $message;
        $message = preg_replace('/(bearer\s+)[^\s"\']+/i', '$1[REDACTED]', $message) ?? $message;
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return mb_strlen($message) > 200 ? mb_substr($message, 0, 197) . '...' : $message;
    }

    /** Aceita apenas https + host exato configurado para este provider. */
    protected function assertAllowedHost(): void
    {
        $parts = parse_url($this->baseUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_map('strtolower', (array) config('ai.allowed_hosts.' . $this->name(), []));

        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['port'])
            || !in_array($host, $allowed, true)) {
            throw new AIException('Host da base URL não permitido para o provider ' . $this->label() . '.', 'invalid_base_url');
        }
    }
}
