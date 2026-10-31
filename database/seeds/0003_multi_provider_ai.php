<?php

declare(strict_types=1);

/**
 * Multi-provider de IA: cadastra as linhas de configuração de Google Gemini e
 * Groq em ai_providers (a de NVIDIA já existe desde a 0001).
 *
 * Idempotente e conservadora: só INSERE o que não existe, sempre INATIVO,
 * SEM API Key e SEM ser padrão. Nunca altera linhas existentes.
 */
$run = static function (PDO $db): void {
    $providers = [
        ['provider' => 'gemini', 'name' => 'Google Gemini', 'base_url' => (string) config('ai.base_urls.gemini')],
        ['provider' => 'groq', 'name' => 'Groq', 'base_url' => (string) config('ai.base_urls.groq')],
    ];

    $select = $db->prepare('SELECT id FROM ai_providers WHERE provider = ?');
    $insert = $db->prepare(
        'INSERT INTO ai_providers (provider, name, base_url, status, is_default, timeout_seconds, temperature, max_tokens)
         VALUES (?, ?, ?, ?, 0, ?, 0.20, 1500)'
    );

    foreach ($providers as $provider) {
        $select->execute([$provider['provider']]);

        if ($select->fetchColumn() !== false) {
            echo 'Seed: provider ' . $provider['provider'] . ' já existe; mantido' . PHP_EOL;
            continue;
        }

        $insert->execute([$provider['provider'], $provider['name'], $provider['base_url'], 'inactive', (int) config('ai.timeout', 30)]);
        echo 'Seed: provider ' . $provider['provider'] . ' criado (inativo, sem chave)' . PHP_EOL;
    }
};

return [
    'version' => '0003',
    'name' => 'multi_provider_ai',
    'run' => $run,
];
