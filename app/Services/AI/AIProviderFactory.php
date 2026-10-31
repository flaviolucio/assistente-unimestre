<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Http\HttpTransportInterface;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\GroqProvider;
use App\Services\AI\Providers\NvidiaProvider;
use App\Services\AI\Providers\OpenRouterProvider;
use RuntimeException;

/**
 * Único ponto que conhece o mapeamento tipo → classe concreta:
 *   openrouter → OpenRouterProvider
 *   gemini     → GeminiProvider
 *   groq       → GroqProvider
 *   nvidia     → NvidiaProvider
 *
 * A URL base vem SEMPRE da configuração (config/app.php › ai.base_urls),
 * nunca do banco/formulário (anti-SSRF).
 */
final class AIProviderFactory
{
    public const PROVIDERS = [
        OpenRouterProvider::PROVIDER => 'OpenRouter',
        GeminiProvider::PROVIDER => 'Google Gemini',
        GroqProvider::PROVIDER => 'Groq',
        NvidiaProvider::PROVIDER => 'NVIDIA',
    ];

    /** Exemplos exibidos no painel (orientação, não validação). */
    public const MODEL_HINTS = [
        'openrouter' => 'ex.: google/gemma-4-31b-it:free',
        'gemini' => 'ex.: gemini-2.5-flash',
        'groq' => 'ex.: llama-3.3-70b-versatile',
        'nvidia' => 'ex.: meta/llama-3.3-70b-instruct',
    ];

    public static function supports(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]);
    }

    public static function label(string $provider): string
    {
        return self::PROVIDERS[$provider] ?? $provider;
    }

    /**
     * @param array<string, mixed> $config configuração já com a chave descriptografada
     */
    public static function create(string $provider, array $config, HttpTransportInterface $transport): AIProviderInterface
    {
        if (!self::supports($provider)) {
            throw new RuntimeException('Provider de IA não suportado: ' . $provider);
        }

        $config['base_url'] = (string) config('ai.base_urls.' . $provider, '');
        $config['retry_transient'] = (bool) config('ai.retry_transient', true);
        $config['retry_count'] = (int) config('ai.retry_count', 1);

        return match ($provider) {
            OpenRouterProvider::PROVIDER => new OpenRouterProvider(array_merge($config, [
                'base_url' => (string) config('ai.openrouter_base_url', 'https://openrouter.ai/api/v1'),
                'referer' => (string) config('ai.app_referer', ''),
                'app_title' => (string) config('ai.app_title', 'assistente-unimestre'),
            ]), $transport),
            GeminiProvider::PROVIDER => new GeminiProvider($config, $transport),
            GroqProvider::PROVIDER => new GroqProvider($config, $transport),
            NvidiaProvider::PROVIDER => new NvidiaProvider($config, $transport),
        };
    }
}
