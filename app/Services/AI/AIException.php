<?php

declare(strict_types=1);

namespace App\Services\AI;

use RuntimeException;

/**
 * Erro de dominio da camada de IA. Carrega um codigo estavel (ex.: http_401,
 * timeout), mensagem amigavel em pt-BR, status HTTP original e identificador
 * do request no provedor, quando disponivel.
 */
final class AIException extends RuntimeException
{
    private string $errorCode;

    private ?int $httpStatus;

    private ?string $requestId;

    private array $context;

    public function __construct(
        string $message,
        string $errorCode,
        ?int $httpStatus = null,
        ?string $requestId = null,
        array $context = []
    ) {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->requestId = $requestId;
        $this->context = $context;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Contexto seguro para registro em logs (nunca contem a credencial).
     */
    public function safeContext(): array
    {
        return $this->context;
    }

    public function isTransient(): bool
    {
        return in_array($this->errorCode, [
            'connection_error',
            'dns_error',
            'timeout',
            'ssl_error',
            'http_408',
            'http_429',
            'http_5xx',
            'rate_limited',
            'upstream_overloaded',
        ], true);
    }

    /**
     * Categoria normalizada, comum a todos os providers. Os providers novos já
     * emitem estes códigos; os códigos históricos do OpenRouter (http_401,
     * http_429…) são traduzidos aqui, sem mudar o que ele devolve.
     */
    public function category(): string
    {
        return self::categoryFor($this->errorCode);
    }

    public static function categoryFor(string $code): string
    {
        return match ($code) {
            'authentication_error', 'http_401', 'http_403', 'missing_api_key' => 'authentication_error',
            'rate_limited', 'http_429' => 'rate_limited',
            'model_unavailable', 'http_404', 'model_not_configured' => 'model_unavailable',
            'upstream_overloaded', 'http_5xx' => 'upstream_overloaded',
            'timeout', 'http_408' => 'timeout',
            'connection_error', 'dns_error', 'ssl_error' => 'connection_error',
            'invalid_request', 'http_400', 'invalid_base_url' => 'invalid_request',
            default => 'provider_error',
        };
    }
}