<?php

declare(strict_types=1);

use App\Core\Database;

$systemPrompt = <<<'PROMPT'
Você é um assistente técnico especializado no sistema UNIMESTRE.

Sua função é auxiliar técnicos e usuários utilizando prioritariamente a documentação fornecida no CONTEXTO.

Não invente procedimentos.
Não invente nomes de menus, parâmetros ou configurações.

Quando houver documentação suficiente, explique a solução passo a passo de maneira clara.
Quando não houver informação suficiente, informe explicitamente que a base disponível não permite responder com segurança.

Diferencie documentação oficial de procedimentos internos.
Nunca apresente procedimento interno como orientação oficial do UNIMESTRE.

Sempre indique as fontes utilizadas.
PROMPT;

$run = static function (PDO $db) use ($systemPrompt): void {
    $adminEmail = (string) env('APP_ADMIN_EMAIL', 'admin@assistente.local');
    $adminPassword = (string) env('APP_ADMIN_PASSWORD', 'admin123');

    $selectUser = $db->prepare('SELECT id FROM users WHERE email = ?');
    $selectUser->execute([$adminEmail]);

    if ($selectUser->fetchColumn() === false) {
        $insert = $db->prepare(
            'INSERT INTO users (name, email, password_hash, role, status, must_change_password) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            'Administrador',
            $adminEmail,
            password_hash($adminPassword, PASSWORD_DEFAULT),
            'admin',
            'active',
            1,
        ]);

        echo 'Seed: usuário admin criado (' . $adminEmail . ')' . PHP_EOL;
    }

    $sources = [
        ['unimestre_oficial', 'Documentação Oficial UNIMESTRE', 'official'],
        ['base_interna', 'Base Interna do TI', 'internal'],
    ];

    $selectSource = $db->prepare('SELECT id FROM knowledge_sources WHERE name = ?');
    $insertSource = $db->prepare(
        'INSERT INTO knowledge_sources (name, type, status) VALUES (?, ?, ?)'
    );

    foreach ($sources as [$slug, $name, $type]) {
        $selectSource->execute([$name]);

        if ($selectSource->fetchColumn() === false) {
            $insertSource->execute([$name, $type, 'active']);
            echo 'Seed: fonte de conhecimento ' . $slug . ' criada' . PHP_EOL;
        }
    }

    $providers = [
        [
            'provider' => 'openrouter',
            'name' => 'OpenRouter',
            'base_url' => (string) config('ai.openrouter_base_url'),
        ],
        [
            'provider' => 'nvidia',
            'name' => 'NVIDIA NIM',
            'base_url' => (string) config('ai.nvidia_base_url'),
        ],
    ];

    $selectProvider = $db->prepare('SELECT id FROM ai_providers WHERE provider = ?');
    $insertProvider = $db->prepare(
        'INSERT INTO ai_providers (provider, name, base_url, status, is_default, timeout_seconds) VALUES (?, ?, ?, ?, ?, ?)'
    );

    foreach ($providers as $provider) {
        $selectProvider->execute([$provider['provider']]);

        if ($selectProvider->fetchColumn() === false) {
            $insertProvider->execute([
                $provider['provider'],
                $provider['name'],
                $provider['base_url'],
                'inactive',
                0,
                (int) config('ai.timeout'),
            ]);
            echo 'Seed: provider ' . $provider['provider'] . ' criado (inativo)' . PHP_EOL;
        }
    }

    $settings = [
        ['app_name', (string) config('app.name'), 'general'],
        ['system_prompt', $systemPrompt, 'ai'],
        ['rag.top_k', (string) config('knowledge.retrieval.top_k'), 'rag'],
        ['rag.max_context_chars', (string) config('rag.max_context_chars'), 'rag'],
        ['rag.chunk_size_chars', (string) config('rag.chunk_size_chars'), 'rag'],
        ['rag.chunk_overlap_chars', (string) config('rag.chunk_overlap_chars'), 'rag'],
        ['rag.min_score', (string) config('knowledge.retrieval.minimum_relevance_score'), 'rag'],
        ['rag.max_context_tokens', (string) config('rag.max_context_tokens'), 'rag'],
        ['rag.history_messages', (string) config('rag.history_messages'), 'rag'],
        ['chat.rate_limit_per_minute', (string) config('chat.rate_limit_per_minute'), 'chat'],
        ['chat.max_question_length', (string) config('chat.max_question_length'), 'chat'],
    ];

    $selectSetting = $db->prepare('SELECT setting_key FROM system_settings WHERE setting_key = ?');
    $insertSetting = $db->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, category) VALUES (?, ?, ?)'
    );

    foreach ($settings as [$key, $value, $category]) {
        $selectSetting->execute([$key]);

        if ($selectSetting->fetchColumn() === false) {
            $insertSetting->execute([$key, $value, $category]);
        }
    }

    $db->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, details) VALUES (NULL, ?, ?, ?)'
    )->execute([
        'system.seed',
        'system',
        'Seed de base aplicado (usuário admin, fontes, providers, configurações)',
    ]);

    echo 'Seed: configurações padrão e auditoria registrados' . PHP_EOL;
};

return [
    'version' => '0001',
    'name' => 'seed_defaults',
    'run' => $run,
];