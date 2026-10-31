<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Logger;
use App\Repositories\AiProviderRepository;
use App\Repositories\SystemSettingRepository;
use App\Services\AI\Http\CurlTransport;
use App\Services\AI\Http\HttpTransportInterface;
use App\Services\AI\Providers\OpenRouterProvider;
use App\Services\Security\Audit;
use App\Services\Security\EncryptionService;
use RuntimeException;

/**
 * Camada de orquestracao da IA. Concentra a leitura/gravacao de providers,
 * criptografia da API Key, ativacao/default, teste de conexao e chat.
 *
 * Regras de seguranca:
 *  - A API Key nunca sai daqui; apenas indicador "has_key" e mascara.
 *  - Sem APP_ENCRYPTION_KEY, salvar/descriptografar credenciais e bloqueado.
 *  - Nenhum segredo vai para logs, auditoria ou respostas.
 */
final class AIService
{
    private AiProviderRepository $repository;

    private SystemSettingRepository $settings;

    private EncryptionService $encryption;

    /** @var callable|null */
    private $transportFactory;

    public function __construct(
        ?AiProviderRepository $repository = null,
        ?EncryptionService $encryption = null,
        ?callable $transportFactory = null
    ) {
        $this->repository = $repository ?? new AiProviderRepository();
        $this->settings = new SystemSettingRepository();
        $this->encryption = $encryption ?? new EncryptionService();
        $this->transportFactory = $transportFactory;
    }

    public function encryptionService(): EncryptionService
    {
        return $this->encryption;
    }

    public function ecConfigured(): bool
    {
        return $this->encryption->isConfigured();
    }

    public function listProviders(): array
    {
        $providers = [];

        foreach ($this->repository->all() as $row) {
            $providers[] = $this->decorate($row);
        }

        return $providers;
    }

    public function provider(int $id): ?array
    {
        $row = $this->repository->find($id);

        return $row !== null ? $this->decorate($row) : null;
    }

    public function hasKey(int $id): bool
    {
        $row = $this->repository->find($id);

        return $row !== null && $this->keyIsPresent($row);
    }

    public function systemPrompt(): ?string
    {
        return $this->settings->get('system_prompt');
    }

    public function providerConfig(int $id): array
    {
        $row = $this->repository->find($id);

        if ($row === null) {
            throw new RuntimeException('Provider de IA não encontrado.');
        }

        $apiKey = $this->keyIsPresent($row) ? $this->decryptKey($row) : '';

        $config = [
            'api_key' => $apiKey,
            'provider' => $row['provider'],
            'name' => $row['name'],
            'model' => $row['model'],
            'temperature' => (float) $row['temperature'],
            'max_tokens' => $row['max_tokens'] !== null ? (int) $row['max_tokens'] : null,
            'timeout_seconds' => (int) $row['timeout_seconds'],
            'base_url' => $row['base_url'] !== null && $row['base_url'] !== ''
                ? (string) $row['base_url']
                : config('ai.openrouter_base_url', 'https://openrouter.ai/api/v1'),
        ];

        return $config;
    }

    /**
     * Cria o provider concreto via AIProviderFactory (OpenRouter, Gemini,
     * Groq ou NVIDIA). A base URL vem de config/app.php (nunca do
     * formulário), evitando SSRF.
     */
    public function createProvider(int $id, array $overrides = []): AIProviderInterface
    {
        $row = $this->repository->find($id);

        if ($row === null) {
            throw new RuntimeException('Provider de IA não encontrado.');
        }

        if (!AIProviderFactory::supports((string) $row['provider'])) {
            throw new RuntimeException('Provider de IA não suportado.');
        }

        $transport = null;

        if (is_callable($this->transportFactory)) {
            $transport = call_user_func($this->transportFactory);
        }

        if (!$transport instanceof HttpTransportInterface) {
            $transport = new CurlTransport();
        }

        return AIProviderFactory::create((string) $row['provider'], array_merge($this->providerConfig($id), $overrides), $transport);
    }

    /**
     * Provider usado pelo chat/RAG: SOMENTE o padrão e ativo. Sem padrão ativo
     * → null (IA indisponível). Não há fallback para outro provider.
     */
    public function defaultProviderId(): ?int
    {
        $row = $this->repository->defaultActiveProvider();

        return $row !== null ? (int) $row['id'] : null;
    }

    /**
     * Remove explicitamente a API Key (ação separada do "salvar"). O provider
     * é desativado e deixa de ser padrão, pois não pode operar sem chave.
     */
    public function removeKey(int $id, ?int $userId = null): array
    {
        $row = $this->repository->find($id);

        if ($row === null) {
            return ['status' => 'error', 'message' => 'Provider de IA não encontrado.'];
        }

        if (!$this->keyIsPresent($row)) {
            return ['status' => 'error', 'message' => 'Este provider não possui API Key configurada.'];
        }

        $this->repository->update($id, ['api_key_encrypted' => null, 'status' => 'inactive', 'is_default' => 0]);

        if ((int) $row['is_default'] === 1) {
            $this->repository->clearDefault($id);
        }

        Audit::record('ai.key_removed', 'ai_provider', $id, ['provider' => $row['provider']]);
        Logger::info('ai', 'API Key removida do provider', ['provider' => $row['provider']]);

        return ['status' => 'ok', 'message' => 'API Key removida. O provider foi desativado.'];
    }

    private function missingKeyMessage(array $row, string $action): string
    {
        return 'Configure a API Key do ' . AIProviderFactory::label((string) $row['provider']) . ' antes de ' . $action . '.';
    }

    /**
     * Persiste a configuração de um provider. Retorna array:
     * ['status' => 'ok'|'error'|'invalid', 'errors' => [...], 'message' => ?string].
     */
    public function save(int $id, array $data, ?int $userId = null): array
    {
        $row = $this->repository->find($id);

        if ($row === null) {
            return ['status' => 'error', 'errors' => [], 'message' => 'Provider de IA não encontrado.'];
        }

        $errors = $this->validate($data);

        if ($errors !== []) {
            return ['status' => 'invalid', 'errors' => $errors, 'message' => null];
        }

        $status = $data['status'] === 'active' ? 'active' : 'inactive';
        $wantedKey = trim((string) ($data['api_key'] ?? ''));
        $keyChanged = false;
        $encrypted = $row['api_key_encrypted'];

        if ($wantedKey !== '') {
            if (!$this->encryption->isConfigured()) {
                return [
                    'status' => 'error',
                    'errors' => [],
                    'message' => 'APP_ENCRYPTION_KEY não configurada. Defina a chave no .env antes de salvar credenciais.',
                ];
            }

            try {
                $encrypted = $this->encryption->encrypt($wantedKey);
                $keyChanged = true;
            } catch (RuntimeException $exception) {
                return ['status' => 'error', 'errors' => [], 'message' => $exception->getMessage()];
            }
        }

        $statusChanged = $status !== $row['status'];

        if ($status === 'active' && !$this->keyIsPresent(['api_key_encrypted' => $encrypted])) {
            return [
                'status' => 'error',
                'errors' => [],
                'message' => $this->missingKeyMessage($row, 'ativar o provider'),
            ];
        }

        $fields = [
            'name' => trim((string) $data['name']),
            'model' => trim((string) $data['model']),
            'temperature' => (float) $data['temperature'],
            'max_tokens' => $data['max_tokens'] !== null ? (int) $data['max_tokens'] : null,
            'timeout_seconds' => (int) $data['timeout_seconds'],
            'status' => $status,
        ];

        if ($keyChanged) {
            $fields['api_key_encrypted'] = $encrypted;
        }

        $makeDefault = $status === 'active' && !empty($data['is_default']);

        if ($makeDefault) {
            $fields['is_default'] = 1;
        } elseif ($status === 'inactive' && (int) $row['is_default'] === 1) {
            $fields['is_default'] = 0;
        }

        $this->repository->update($id, $fields);

        if ($makeDefault) {
            $this->repository->setDefault($id);
        } elseif ((int) $row['is_default'] === 1 && $status === 'inactive') {
            $this->repository->clearDefault($id);
        }

        Audit::record('ai.provider_updated', 'ai_provider', $id, [
            'provider' => $row['provider'],
            'model' => $fields['model'],
            'status' => $status,
            'is_default' => $makeDefault ? 1 : 0,
            'key_changed' => $keyChanged,
            'status_changed' => $statusChanged,
        ]);

        Logger::info('ai', 'Configuração do provider atualizada', [
            'provider' => $row['provider'],
            'model' => $fields['model'],
            'status' => $status,
            'key_changed' => $keyChanged,
            'status_changed' => $statusChanged,
        ]);

        return [
            'status' => 'ok',
            'errors' => [],
            'message' => $statusChanged
                ? 'Configuração salva. Status do provider alterado.'
                : 'Configuração salva.',
        ];
    }

    public function toggleStatus(int $id, ?int $userId = null): array
    {
        $row = $this->repository->find($id);

        if ($row === null) {
            return ['error' => 'Provider de IA não encontrado.'];
        }

        $newStatus = $row['status'] === 'active' ? 'inactive' : 'active';

        if ($newStatus === 'active' && !$this->keyIsPresent($row)) {
            return ['error' => $this->missingKeyMessage($row, 'ativar o provider')];
        }

        $fields = ['status' => $newStatus];

        if ($newStatus === 'inactive' && (int) $row['is_default'] === 1) {
            $fields['is_default'] = 0;
        }

        $this->repository->update($id, $fields);

        if ($newStatus === 'inactive' && (int) $row['is_default'] === 1) {
            $this->repository->clearDefault($id);
        }

        Audit::record(
            $newStatus === 'active' ? 'ai.provider_activated' : 'ai.provider_deactivated',
            'ai_provider',
            $id,
            ['provider' => $row['provider']]
        );

        Logger::info('ai', 'Status do provider alterado', [
            'provider' => $row['provider'],
            'status' => $newStatus,
        ]);

        return ['status' => $newStatus];
    }

    /**
     * Executa o teste de conexão contra o provedor configurado.
     */
    public function test(int $id, ?int $userId = null): array
    {
        $row = $this->repository->find($id);

        if ($row === null) {
            return ['success' => false, 'message' => 'Provider de IA não encontrado.', 'error_code' => 'not_found'];
        }

        if ($row['status'] !== 'active') {
            return [
                'success' => false,
                'message' => 'Ative o provider antes de testar a conexão.',
                'error_code' => 'provider_inactive',
            ];
        }

        if (!$this->keyIsPresent($row)) {
            return [
                'success' => false,
                'message' => $this->missingKeyMessage($row, 'utilizar a IA'),
                'error_code' => 'missing_api_key',
            ];
        }

        try {
            $response = $this->createProvider($id)->testConnection();
        } catch (AIException $exception) {
            $this->auditTest($id, false, null, $exception->errorCode());

            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode(),
                'request_id' => $exception->requestId(),
                'detail' => $exception->safeContext() !== [] ? $exception->safeContext() : null,
            ];
        } catch (RuntimeException $exception) {
            $this->auditTest($id, false, null, 'internal_error');

            return [
                'success' => false,
                'message' => 'Não foi possível executar o teste de conexão.',
                'error_code' => 'internal_error',
            ];
        }

        $this->auditTest($id, $response->isSuccess(), $response->model(), $response->errorCode());

        if (!$response->isSuccess()) {
            return [
                'success' => false,
                'message' => $response->errorMessage() ?? 'Teste de conexão falhou.',
                'error_code' => $response->errorCode(),
                'request_id' => $response->requestId(),
            ];
        }

        // O provider só devolve sucesso com conteúdo utilizável (vazio/só
        // raciocínio já vira erro). Aqui ficam os AVISOS de uso real.
        $warnings = [];

        if ($response->finishReason() === 'length' || $response->truncated()) {
            $warnings[] = 'A resposta de teste atingiu o limite de tokens (finish_reason=length). Em respostas reais, avalie aumentar o Máx. tokens.';
        }

        if ($response->hasReasoning()) {
            $warnings[] = 'O modelo gastou tokens com raciocínio interno. Respostas longas podem ficar truncadas ou só com raciocínio; considere mais Máx. tokens ou um modelo sem raciocínio.';
        }

        if ($warnings !== []) {
            Logger::warning('ai', 'Teste de conexão aprovado com avisos', [
                'provider' => $row['provider'],
                'model' => $row['model'],
                'finish_reason' => $response->finishReason(),
                'has_reasoning' => $response->hasReasoning(),
            ]);
        }

        return [
            'success' => true,
            'content' => $response->content(),
            'model' => $response->model(),
            'request_id' => $response->requestId(),
            'latency_ms' => $response->latencyMs(),
            'prompt_tokens' => $response->promptTokens(),
            'completion_tokens' => $response->completionTokens(),
            'total_tokens' => $response->totalTokens(),
            'finish_reason' => $response->finishReason(),
            'truncated' => $response->truncated(),
            'has_reasoning' => $response->hasReasoning(),
            'warnings' => $warnings,
            'message' => $warnings === []
                ? 'Conexão com o provedor de IA estabelecida com sucesso.'
                : 'Conexão estabelecida, com avisos (ver detalhes).',
            'error_code' => null,
        ];
    }

    /**
     * Envia uma conversa (sem RAG nesta fase) ao provider ativo/default.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function chat(int $id, array $messages, ?int $userId = null, ?int $timeoutOverride = null): array
    {
        $row = $this->repository->find($id);

        if ($row === null) {
            return ['success' => false, 'message' => 'Provider de IA não encontrado.', 'error_code' => 'not_found'];
        }

        if ($row['status'] !== 'active') {
            return [
                'success' => false,
                'message' => 'Ative o provider antes de enviar mensagens.',
                'error_code' => 'provider_inactive',
            ];
        }

        if (!$this->keyIsPresent($row)) {
            return ['success' => false, 'message' => $this->missingKeyMessage($row, 'utilizar a IA'), 'error_code' => 'missing_api_key'];
        }

        try {
            $response = $this->createProvider($id, $timeoutOverride !== null ? ['timeout_seconds' => $timeoutOverride] : [])->chat($messages);
        } catch (AIException $exception) {
            $context = $exception->safeContext();

            Logger::error('ai', 'Falha na chamada de chat', [
                'provider' => $row['provider'],
                'model' => $row['model'],
                'http_status' => $exception->httpStatus(),
                'error_code' => $exception->errorCode(),
                'error_category' => $exception->category(),
                'retry_after_seconds' => $context['retry_after_seconds'] ?? null,
                'attempts' => $context['attempts'] ?? null,
                'request_id' => $exception->requestId(),
            ]);

            return [
                'success' => false,
                'provider' => $row['provider'],
                'model_requested' => $row['model'],
                'error_category' => $exception->category(),
                'http_status' => $exception->httpStatus(),
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode(),
                'request_id' => $exception->requestId(),
                'detail' => $exception->safeContext() !== [] ? $exception->safeContext() : null,
            ];
        }

        Audit::record('ai.chat_requested', 'ai_provider', $id, [
            'provider' => $row['provider'],
            'model' => $row['model'],
            'success' => $response->isSuccess(),
        ]);

        return [
            'success' => $response->isSuccess(),
            'provider' => $row['provider'],
            'model_requested' => $row['model'],
            'content' => $response->content(),
            'model' => $response->model(),
            'request_id' => $response->requestId(),
            'latency_ms' => $response->latencyMs(),
            'prompt_tokens' => $response->promptTokens(),
            'completion_tokens' => $response->completionTokens(),
            'total_tokens' => $response->totalTokens(),
            'finish_reason' => $response->finishReason(),
            'truncated' => $response->truncated(),
            'message' => $response->errorMessage(),
            'error_code' => $response->errorCode(),
        ];
    }

    public const MSG_ALL_FAILED = 'Não foi possível consultar o serviço de IA neste momento.';

    /** Menor timeout aceitável para uma tentativa de fallback (s). */
    public const MIN_ATTEMPT_SECONDS = 5;

    public function fallbackEnabled(): bool
    {
        $setting = $this->settings->get('ai.fallback_enabled');

        return $setting !== null ? $setting === '1' : (bool) config('ai.fallback.enabled', false);
    }

    /** Orçamento total aproximado de uma requisição com fallback (s). */
    public function fallbackBudgetSeconds(): int
    {
        $setting = $this->settings->get('ai.fallback_max_total_seconds');

        return max(10, $setting !== null && is_numeric($setting) ? (int) $setting : (int) config('ai.fallback.max_total_seconds', 90));
    }

    public function setFallbackEnabled(bool $enabled, ?int $userId = null): void
    {
        $this->settings->set('ai.fallback_enabled', $enabled ? '1' : '0', 'ai');
        Audit::record('ai.fallback_updated', 'system_setting', null, ['enabled' => $enabled, 'user_id' => $userId]);
        Logger::info('ai', 'Fallback automático entre providers alterado', ['enabled' => $enabled]);
    }

    /** Provider elegível: ativo, suportado, com chave e modelo configurados. */
    public function isEligible(array $row): bool
    {
        return ($row['status'] ?? '') === 'active'
            && AIProviderFactory::supports((string) $row['provider'])
            && $this->keyIsPresent($row)
            && trim((string) ($row['model'] ?? '')) !== '';
    }

    /**
     * Ordem de tentativa: o PADRÃO (ou o preferido informado) primeiro; depois
     * os demais elegíveis por prioridade (e id). Sem fallback: só o primeiro.
     * Nunca repete provider. Não altera o provider padrão salvo.
     *
     * @return array<int, array<string, mixed>> providers decorados (sem chave)
     */
    public function attemptOrder(?int $preferredId = null, ?bool $fallback = null): array
    {
        $rows = $this->sortedByPriority($this->repository->all());
        $eligible = array_values(array_filter($rows, fn (array $r): bool => $this->isEligible($r)));
        $first = null;

        foreach ([$preferredId, $this->defaultProviderId()] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            foreach ($eligible as $row) {
                if ((int) $row['id'] === $candidate) {
                    $first = $row;
                    break 2;
                }
            }
        }

        $fallback ??= $this->fallbackEnabled();

        if ($first === null) {
            // Sem fallback mantém a regra antiga: sem padrão ativo, sem IA.
            if (!$fallback || $eligible === []) {
                return [];
            }

            $first = $eligible[0];
        }

        $order = [$this->decorate($first)];

        if ($fallback) {
            foreach ($eligible as $row) {
                if ((int) $row['id'] !== (int) $first['id']) {
                    $order[] = $this->decorate($row);
                }
            }
        }

        return $order;
    }

    /**
     * Todos os providers na ordem de prioridade, com elegibilidade (sem chave).
     *
     * @return array<int, array<string, mixed>>
     */
    public function priorityList(): array
    {
        return array_map(function (array $row): array {
            $decorated = $this->decorate($row);
            $decorated['eligible'] = $this->isEligible($row);

            return $decorated;
        }, $this->sortedByPriority($this->repository->all()));
    }

    /** Move o provider uma posição (up/down) e renumera as prioridades 1..n. */
    public function movePriority(int $id, string $direction, ?int $userId = null): bool
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->priorityList());
        $index = array_search($id, $ids, true);

        if ($index === false) {
            return false;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($target < 0 || $target >= count($ids)) {
            return false;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        foreach ($ids as $position => $providerId) {
            $this->repository->update($providerId, ['priority' => $position + 1]);
        }

        Audit::record('ai.priority_updated', 'ai_provider', $id, ['direction' => $direction, 'order' => $ids, 'user_id' => $userId]);

        return true;
    }

    /**
     * Chat com fallback automático: as MESMAS mensagens (system prompt,
     * contexto RAG, memória do diagnóstico, contrato estruturado) vão para cada
     * provider, cada um com o SEU modelo/configuração. Cada provider é tentado
     * no máximo uma vez: PRIMÁRIO → FALLBACK 1 → 2 → 3. Sem paralelismo e sem
     * circuit breaker: a próxima requisição recomeça pelo padrão.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param bool|null $force true/false força ligar/desligar (Playground); null = configuração salva
     * @return array<string, mixed> mesmo formato de chat() + attempts/fallback_used/final_provider
     */
    public function chatWithFallback(array $messages, ?int $userId = null, ?int $preferredId = null, ?bool $force = null): array
    {
        $order = $this->attemptOrder($preferredId, $force);

        if ($order === []) {
            return [
                'success' => false,
                'error_code' => 'provider_unavailable',
                'message' => 'Nenhum provider ativo está configurado.',
                'attempts' => [],
                'fallback_used' => false,
                'final_provider' => null,
            ];
        }

        $started = microtime(true);
        $deadline = $started + $this->fallbackBudgetSeconds();
        $attempts = [];
        $tried = [];
        $result = null;

        foreach ($order as $position => $provider) {
            $id = (int) $provider['id'];

            if (isset($tried[$id])) {
                continue; // sem loop: nunca o mesmo provider duas vezes
            }

            $tried[$id] = true;
            $timeout = null;

            if ($position > 0) {
                $remaining = (int) floor($deadline - microtime(true));

                if ($remaining < self::MIN_ATTEMPT_SECONDS) {
                    $attempts[] = [
                        'order' => $position + 1, 'provider' => (string) $provider['provider'], 'model' => (string) $provider['model'],
                        'duration_ms' => 0, 'result' => 'skipped_budget', 'class' => null, 'category' => null, 'http_status' => null,
                    ];
                    break;
                }

                $timeout = min((int) $provider['timeout_seconds'], $remaining);
            }

            $t0 = microtime(true);
            $result = $this->chat($id, $messages, $userId, $timeout);
            $ok = !empty($result['success']);
            $httpStatus = isset($result['http_status']) ? (int) $result['http_status'] : null;
            $class = $ok ? null : ProviderErrorClassifier::classify($result['error_code'] ?? null, $httpStatus);

            $attempts[] = [
                'order' => $position + 1,
                'provider' => (string) $provider['provider'],
                'model' => (string) $provider['model'],
                'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
                'result' => $ok ? 'success' : (string) ($result['error_code'] ?? 'error'),
                'class' => $class,
                'category' => $ok ? null : AIException::categoryFor((string) ($result['error_code'] ?? '')),
                'http_status' => $httpStatus,
            ];

            if ($ok) {
                break;
            }

            if ($class === ProviderErrorClassifier::CONFIGURATION) {
                // Visível ao admin; a chave/status NUNCA são alterados aqui.
                Logger::error('ai', 'Provider com erro de configuração durante o fallback', [
                    'provider' => $provider['provider'],
                    'error_code' => $result['error_code'] ?? null,
                    'http_status' => $httpStatus,
                ]);
                Audit::record('ai.provider_config_error', 'ai_provider', $id, [
                    'provider' => $provider['provider'],
                    'error_code' => $result['error_code'] ?? null,
                    'http_status' => $httpStatus,
                ]);
            }

            if (!ProviderErrorClassifier::allowsFallback((string) $class)) {
                break;
            }
        }

        $success = $result !== null && !empty($result['success']);
        $final = $success ? $attempts[count($attempts) - 1]['provider'] : null;
        $real = array_values(array_filter($attempts, static fn (array $a): bool => $a['result'] !== 'skipped_budget'));
        $fallbackUsed = count($real) > 1;

        if ($fallbackUsed || !$success) {
            Logger::warning('ai', $success ? 'Resposta obtida via fallback de provider' : 'Nenhum provider respondeu', [
                'attempts' => $attempts,
                'final_provider' => $final,
                'total_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        }

        if ($fallbackUsed) {
            Audit::record('ai_provider_fallback', 'ai_provider', null, [
                'from_provider' => $attempts[0]['provider'],
                'to_provider' => $final,
                'reason' => $attempts[0]['category'] ?? $attempts[0]['result'],
                'success' => $success,
                'attempts' => array_map(static fn (array $a): string => $a['provider'] . ':' . $a['result'], $attempts),
            ]);
        }

        $result = (array) $result;
        $result['attempts'] = $attempts;
        $result['fallback_used'] = $fallbackUsed;
        $result['final_provider'] = $final;

        if (!$success && $fallbackUsed) {
            $result['message'] = self::MSG_ALL_FAILED;
            $result['all_failed'] = true;
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sortedByPriority(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [(int) ($a['priority'] ?? 100), (int) $a['id']] <=> [(int) ($b['priority'] ?? 100), (int) $b['id']]);

        return $rows;
    }

    private function validate(array $data): array
    {
        $errors = [];

        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = 'Informe o nome do provider.';
        } elseif (mb_strlen(trim((string) $data['name'])) > 150) {
            $errors['name'] = 'Nome do provider deve ter no máximo 150 caracteres.';
        }

        if (trim((string) ($data['model'] ?? '')) === '') {
            $errors['model'] = 'Informe o modelo de IA (ex.: openrouter/free).';
        } elseif (mb_strlen(trim((string) $data['model'])) > 150) {
            $errors['model'] = 'Modelo de IA deve ter no máximo 150 caracteres.';
        } elseif (preg_match('/^[a-zA-Z0-9.:_\/-]+$/', trim((string) $data['model'])) !== 1) {
            $errors['model'] = 'Modelo de IA contém caracteres inválidos.';
        }

        $temperature = (float) ($data['temperature'] ?? 0.2);

        if (!is_numeric((string) ($data['temperature'] ?? '')) || $temperature < 0 || $temperature > 2) {
            $errors['temperature'] = 'Temperatura deve ser um número entre 0 e 2.';
        }

        $maxTokens = ($data['max_tokens'] ?? '') === '' ? null : (int) $data['max_tokens'];

        if ($maxTokens !== null && ($maxTokens < 1 || $maxTokens > 100000)) {
            $errors['max_tokens'] = 'Máximo de tokens deve estar entre 1 e 100000.';
        }

        $timeout = (int) ($data['timeout_seconds'] ?? 30);

        if ($timeout < 5 || $timeout > 120) {
            $errors['timeout_seconds'] = 'Timeout deve estar entre 5 e 120 segundos.';
        }

        return $errors;
    }

    private function keyIsPresent(array $row): bool
    {
        return isset($row['api_key_encrypted'])
            && is_string($row['api_key_encrypted'])
            && trim($row['api_key_encrypted']) !== '';
    }

    private function decryptKey(array $row): string
    {
        try {
            return $this->encryption->decrypt((string) $row['api_key_encrypted']);
        } catch (RuntimeException $exception) {
            throw $exception;
        }
    }

    private function decorate(array $row): array
    {
        $hasKey = $this->keyIsPresent($row);

        unset($row['api_key_encrypted']);

        $row['has_key'] = $hasKey;
        $row['api_key_masked'] = $hasKey ? '••••••••••' : '';
        $row['label'] = AIProviderFactory::label((string) $row['provider']);
        $row['supported'] = AIProviderFactory::supports((string) $row['provider']);
        $row['model_hint'] = AIProviderFactory::MODEL_HINTS[(string) $row['provider']] ?? '';

        return $row;
    }

    private function auditTest(int $id, bool $success, ?string $model, ?string $errorCode): void
    {
        $row = $this->repository->find($id);

        Audit::record('ai.connection_tested', 'ai_provider', $id, [
            'provider' => $row['provider'] ?? null,
            'model' => $model,
            'success' => $success,
            'error_code' => $success ? null : $errorCode,
        ]);
    }
}