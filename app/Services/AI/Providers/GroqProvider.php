<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

/**
 * Groq — POST https://api.groq.com/openai/v1/chat/completions
 * Authorization: Bearer <chave>. Usa max_completion_tokens (max_tokens está
 * marcado como obsoleto na referência oficial da Groq).
 */
final class GroqProvider extends OpenAICompatibleProvider
{
    public const PROVIDER = 'groq';

    public function name(): string
    {
        return self::PROVIDER;
    }

    public function label(): string
    {
        return 'Groq';
    }

    protected function defaultBaseUrl(): string
    {
        return (string) config('ai.base_urls.groq', 'https://api.groq.com/openai/v1');
    }

    protected function maxTokensField(): string
    {
        return 'max_completion_tokens';
    }
}
