<?php

declare(strict_types=1);

use App\Core\Env;

// Fail-safe: em produção o debug é SEMPRE desligado, mesmo com APP_DEBUG=true.
// Em desenvolvimento só liga quando APP_DEBUG=true explícito.
$appEnv = strtolower(trim((string) Env::get('APP_ENV', 'production')));
$isProduction = in_array($appEnv, ['production', 'prod'], true);

return [
    'app' => [
        'name' => Env::get('APP_NAME', 'Central Inteligente de Suporte UNIMESTRE'),
        'env' => $appEnv,
        'debug' => !$isProduction && strtolower(trim((string) Env::get('APP_DEBUG', 'false'))) === 'true',
        'debug_requested' => strtolower(trim((string) Env::get('APP_DEBUG', 'false'))) === 'true',
        'url' => rtrim((string) Env::get('APP_URL', 'http://localhost/assistente-unimestre'), '/'),
        'key' => (string) Env::get('APP_KEY', ''),
        'timezone' => Env::get('APP_TIMEZONE', 'America/Sao_Paulo'),
        'locale' => Env::get('APP_LOCALE', 'pt_BR'),
    ],
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_NAME', 'assistente_unimestre'),
        'user' => Env::get('DB_USER', 'root'),
        'pass' => (string) Env::get('DB_PASS', ''),
        'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
        'collation' => Env::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'prefix' => (string) Env::get('DB_PREFIX', ''),
    ],
    'paths' => [
        'storage' => APP_PATH . '/storage',
        'logs' => APP_PATH . '/storage/logs',
        'cache' => APP_PATH . '/storage/cache',
        'views' => APP_PATH . '/resources/views',
        'public' => APP_PATH . '/public',
    ],
    'ai' => [
        'default_provider' => Env::get('AI_DEFAULT_PROVIDER', 'openrouter'),
        'timeout' => (int) Env::get('AI_TIMEOUT_SECONDS', '30'),
        'openrouter_base_url' => Env::get('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'nvidia_base_url' => Env::get('NVIDIA_BASE_URL', 'https://integrate.api.nvidia.com/v1'),
        // URL base de cada provider: sempre da configuração, nunca do formulário (anti-SSRF).
        'base_urls' => [
            'openrouter' => Env::get('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'gemini' => Env::get('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'groq' => Env::get('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'nvidia' => Env::get('NVIDIA_BASE_URL', 'https://integrate.api.nvidia.com/v1'),
        ],
        // Hosts exatos aceitos pelos providers novos (Gemini, Groq, NVIDIA).
        'allowed_hosts' => [
            'gemini' => ['generativelanguage.googleapis.com'],
            'groq' => ['api.groq.com'],
            'nvidia' => ['integrate.api.nvidia.com'],
        ],
        'encryption_key' => (string) Env::get('APP_ENCRYPTION_KEY', ''),
        'cipher' => 'aes-256-gcm',
        'connect_timeout' => (int) Env::get('AI_CONNECT_TIMEOUT_SECONDS', '10'),
        'retry_transient' => Env::get('AI_RETRY_TRANSIENT', 'true') === 'true',
        'retry_count' => 1,
        // Fallback entre providers (a configuração salva em system_settings prevalece).
        'fallback' => [
            'enabled' => filter_var(Env::get('AI_PROVIDER_FALLBACK_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
            'max_total_seconds' => (int) Env::get('AI_FALLBACK_MAX_TOTAL_SECONDS', '90'),
        ],
        'allowed_base_urls' => [
            Env::get('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            Env::get('NVIDIA_BASE_URL', 'https://integrate.api.nvidia.com/v1'),
        ],
        'app_referer' => (string) Env::get('APP_URL', 'http://localhost/assistente-unimestre'),
        'app_title' => Env::get('APP_NAME', 'assistente-unimestre'),
        'playground' => [
            'max_question_chars' => (int) Env::get('AI_PLAYGROUND_MAX_QUESTION_CHARS', '2000'),
        ],
    ],
    'rag' => [
        'max_context_chars' => (int) Env::get('RAG_MAX_CONTEXT_CHARS', '12000'),
        'chunk_size_chars' => (int) Env::get('RAG_CHUNK_SIZE_CHARS', '1800'),
        'chunk_overlap_chars' => (int) Env::get('RAG_CHUNK_OVERLAP_CHARS', '200'),
        'max_context_tokens' => (int) Env::get('RAG_MAX_CONTEXT_TOKENS', '4000'),
        'history_messages' => (int) Env::get('RAG_HISTORY_MESSAGES', '6'),
    ],
    'chat' => [
        'rate_limit_per_minute' => (int) Env::get('CHAT_RATE_LIMIT_PER_MINUTE', '10'),
        'max_question_length' => (int) Env::get('CHAT_MAX_QUESTION_LENGTH', '4000'),
    ],
    'copilot' => [
        'structured_output' => filter_var(Env::get('COPILOT_STRUCTURED_OUTPUT', 'true'), FILTER_VALIDATE_BOOLEAN),
    ],
    'log' => [
        'dir' => APP_PATH . '/storage/logs',
        'level' => Env::get('LOG_LEVEL', 'debug'),
        'days_to_keep' => (int) Env::get('LOG_DAYS_TO_KEEP', '30'),
    ],
    'session' => [
        'name' => Env::get('SESSION_NAME', 'assistente_unimestre'),
        'lifetime' => (int) Env::get('SESSION_LIFETIME', '7200'),
    ],
    'auth' => [
        'max_attempts' => (int) Env::get('AUTH_MAX_ATTEMPTS', '5'),
        'lockout_minutes' => (int) Env::get('AUTH_LOCKOUT_MINUTES', '15'),
        'attempt_window_minutes' => (int) Env::get('AUTH_ATTEMPT_WINDOW_MINUTES', '15'),
        'password_min_length' => (int) Env::get('AUTH_PASSWORD_MIN_LENGTH', '8'),
    ],
];