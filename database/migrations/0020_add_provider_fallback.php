<?php

declare(strict_types=1);

use App\Core\Database;

/**
 * Fallback automático entre providers de IA.
 *
 * 1) ai_providers.priority: ordem de contingência (1 = primeiro). O provider
 *    PADRÃO continua sendo sempre a primeira tentativa; a prioridade só ordena
 *    os demais. Valores iniciais: Groq 1, OpenRouter 2, Gemini 3, NVIDIA 4.
 * 2) system_settings: ai.fallback_enabled = 0 (desligado por padrão — mantém
 *    o comportamento anterior até o admin ativar) e
 *    ai.fallback_max_total_seconds = 90 (orçamento total da requisição).
 */
$up = static function (): void {
    $db = Database::connection();

    try {
        $db->exec('ALTER TABLE ai_providers ADD COLUMN priority INT UNSIGNED NOT NULL DEFAULT 100 AFTER is_default');
    } catch (\PDOException $exception) {
        if (!str_contains((string) $exception->getMessage(), 'Duplicate column name')) {
            throw $exception;
        }
    }

    $set = $db->prepare('UPDATE ai_providers SET priority = ? WHERE provider = ? AND priority = 100');

    foreach (['groq' => 1, 'openrouter' => 2, 'gemini' => 3, 'nvidia' => 4] as $provider => $priority) {
        $set->execute([$priority, $provider]);
    }

    $select = $db->prepare('SELECT setting_key FROM system_settings WHERE setting_key = ?');
    $insert = $db->prepare('INSERT INTO system_settings (setting_key, setting_value, category) VALUES (?, ?, ?)');

    foreach ([['ai.fallback_enabled', '0'], ['ai.fallback_max_total_seconds', '90']] as [$key, $value]) {
        $select->execute([$key]);

        if ($select->fetchColumn() === false) {
            $insert->execute([$key, $value, 'ai']);
        }
    }
};

$down = static function (): void {
    $db = Database::connection();
    $db->exec("DELETE FROM system_settings WHERE setting_key IN ('ai.fallback_enabled', 'ai.fallback_max_total_seconds')");
    $db->exec('ALTER TABLE ai_providers DROP COLUMN priority');
};

return [
    'version' => '0020',
    'name' => 'add_provider_fallback',
    'up' => $up,
    'down' => $down,
];
