<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Classificação centralizada das falhas de provider para o fallback.
 *
 *  RECOVERABLE      falha temporária/do próprio provider: tenta o próximo.
 *  CONFIGURATION    problema de configuração DAQUELE provider (401/403, chave
 *                   ausente, modelo não configurado): tenta o próximo para
 *                   manter o serviço, mas registra alerta para o admin. Nunca
 *                   desativa o provider nem altera a chave.
 *  NON_RECOVERABLE  a própria requisição é o problema (payload inválido,
 *                   bloqueio de conteúdo/segurança, erro interno): outro
 *                   provider falharia igual ou não deveria receber o pedido.
 *                   Interrompe o fallback.
 */
final class ProviderErrorClassifier
{
    public const RECOVERABLE = 'recoverable';
    public const CONFIGURATION = 'configuration';
    public const NON_RECOVERABLE = 'non_recoverable';

    private const RECOVERABLE_CODES = [
        'timeout', 'http_408',
        'connection_error', 'dns_error', 'ssl_error',
        'rate_limited', 'http_429',
        'upstream_overloaded', 'http_5xx',
        'provider_unavailable', 'model_unavailable', 'http_404',
        'provider_error',
        // o modelo daquele provider não produziu resposta utilizável
        'empty_response', 'reasoning_only', 'invalid_json',
    ];

    private const CONFIGURATION_CODES = [
        'authentication_error', 'http_401', 'http_403',
        'missing_api_key', 'model_not_configured', 'invalid_base_url', 'provider_inactive', 'not_found',
    ];

    public static function classify(?string $errorCode, ?int $httpStatus = null): string
    {
        $code = (string) $errorCode;

        if (in_array($code, self::CONFIGURATION_CODES, true) || in_array($httpStatus, [401, 403], true)) {
            return self::CONFIGURATION;
        }

        if (in_array($code, self::RECOVERABLE_CODES, true)
            || in_array($httpStatus, [408, 429, 500, 502, 503, 504, 529], true)
        ) {
            return self::RECOVERABLE;
        }

        // invalid_request / http_400 / content_filter(ed) / internal_error / desconhecido
        return self::NON_RECOVERABLE;
    }

    public static function allowsFallback(string $class): bool
    {
        return $class !== self::NON_RECOVERABLE;
    }
}
