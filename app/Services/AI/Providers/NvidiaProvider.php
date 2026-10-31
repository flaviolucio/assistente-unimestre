<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

/**
 * NVIDIA API / NIM — chamada DIRETA (não passa pelo OpenRouter).
 * POST https://integrate.api.nvidia.com/v1/chat/completions
 * Authorization: Bearer <chave>. Formato OpenAI; alguns modelos devolvem
 * message.reasoning_content, tratado genericamente pela classe base.
 */
final class NvidiaProvider extends OpenAICompatibleProvider
{
    public const PROVIDER = 'nvidia';

    public function name(): string
    {
        return self::PROVIDER;
    }

    public function label(): string
    {
        return 'NVIDIA';
    }

    protected function defaultBaseUrl(): string
    {
        return (string) config('ai.base_urls.nvidia', 'https://integrate.api.nvidia.com/v1');
    }
}
