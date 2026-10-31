<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Services\AI\AIService;
use App\Services\AI\Providers\OpenRouterProvider;
use App\Services\Security\Auth;

final class AiController extends Controller
{
    private AIService $service;

    public function __construct(?AIService $service = null)
    {
        $this->service = $service ?? new AIService();
    }

    public function index(): void
    {
        $this->render('ai/index', [
            'appTitle' => 'Integração com IA',
            'providers' => $this->orderedProviders(),
            'encryptionConfigured' => $this->service->ecConfigured(),
            'activeProvider' => $this->service->provider($this->activeProviderId()),
            'testResult' => $this->flashTestResult(),
            'canManage' => Auth::instance()->can('ai.manage'),
            'canTest' => Auth::instance()->can('ai.test'),
            'fallbackEnabled' => $this->service->fallbackEnabled(),
            'fallbackBudget' => $this->service->fallbackBudgetSeconds(),
            'priorityList' => $this->service->priorityList(),
            'attemptOrder' => $this->service->attemptOrder(null, true),
            'defaultProvider' => ($defaultId = $this->service->defaultProviderId()) !== null ? $this->service->provider($defaultId) : null,
        ]);
    }

    /** POST /admin/ai/fallback */
    public function saveFallback(): void
    {
        $enabled = isset($_POST['fallback_enabled']) && $_POST['fallback_enabled'] === '1';
        $this->service->setFallbackEnabled($enabled, Auth::instance()->id());
        Flash::set('success', $enabled ? 'Fallback automático ativado.' : 'Fallback automático desativado.');

        $this->redirect('/admin/ai#fallback');
    }

    /** POST /admin/ai/{id}/prioridade (direction=up|down) */
    public function movePriority(int $id): void
    {
        $direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';

        if (!$this->service->movePriority($id, $direction, Auth::instance()->id())) {
            Flash::set('error', 'Não foi possível alterar a prioridade.');
        }

        $this->redirect('/admin/ai#fallback');
    }

    public function save(int $id): void
    {
        $provider = $this->service->provider($id);

        if ($provider === null) {
            Flash::set('error', 'Provider de IA não encontrado.');

            $this->redirect('/admin/ai');
        }

        $data = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'model' => trim((string) ($_POST['model'] ?? '')),
            'temperature' => (string) ($_POST['temperature'] ?? '0.2'),
            'max_tokens' => (string) ($_POST['max_tokens'] ?? ''),
            'timeout_seconds' => (string) ($_POST['timeout_seconds'] ?? '30'),
            'status' => (isset($_POST['status']) && $_POST['status'] === 'active') ? 'active' : 'inactive',
            'is_default' => isset($_POST['is_default']),
            'api_key' => (string) ($_POST['api_key'] ?? ''),
        ];

        $result = $this->service->save($id, $data, Auth::instance()->id());

        if ($result['status'] === 'invalid') {
            $first = $result['errors'] !== [] ? (string) reset($result['errors']) : 'Revise os dados do provider.';
            Flash::set('error', $first);

            $this->redirect('/admin/ai');
        }

        if ($result['status'] === 'error') {
            Flash::set('error', $result['message'] ?? 'Falha ao salvar a configuração do provider.');

            $this->redirect('/admin/ai');
        }

        Flash::set('success', $result['message'] ?? 'Configuração salva.');

        if (!empty($data['api_key'])) {
            Flash::set('warning', 'Nova API Key criptografada e armazenada com segurança. Ela não será exibida novamente.');
        }

        $this->redirect('/admin/ai');
    }

    public function toggleStatus(int $id): void
    {
        $result = $this->service->toggleStatus($id, Auth::instance()->id());

        if (isset($result['error'])) {
            Flash::set('error', $result['error']);
        } else {
            Flash::set('success', $result['status'] === 'active' ? 'Provider ativado.' : 'Provider desativado.');
        }

        $this->redirect('/admin/ai');
    }

    /** Remoção EXPLÍCITA da API Key (salvar com campo vazio nunca apaga a chave). */
    public function removeKey(int $id): void
    {
        $result = $this->service->removeKey($id, Auth::instance()->id());

        Flash::set($result['status'] === 'ok' ? 'success' : 'error', (string) $result['message']);

        $this->redirect('/admin/ai');
    }

    public function test(int $id): void
    {
        $provider = $this->service->provider($id);

        if ($provider === null) {
            Flash::set('error', 'Provider de IA não encontrado.');

            $this->redirect('/admin/ai');
        }

        $result = $this->service->test($id, Auth::instance()->id());

        Flash::input('ai_test_result_' . $id, (string) json_encode($result, JSON_UNESCAPED_UNICODE));

        if (!empty($result['success'])) {
            Flash::set('success', 'Teste de conexão: ' . ($result['message'] ?? 'sucesso'));
        } else {
            Flash::set('error', 'Teste de conexão falhou: ' . ($result['message'] ?? 'erro desconhecido'));
        }

        $this->redirect('/admin/ai');
    }

    public function playground(): void
    {
        $this->renderAiPlayground(null, '', []);
    }

    public function playgroundSend(): void
    {
        $question = trim((string) ($_POST['question'] ?? ''));

        if ($question === '') {
            $this->renderAiPlayground(null, '', ['question' => 'Informe a pergunta antes de enviar.']);

            return;
        }

        if (mb_strlen($question) > (int) config('ai.playground.max_question_chars', 2000)) {
            $this->renderAiPlayground(
                null,
                $question,
                ['question' => 'A pergunta excede o limite de ' . (int) config('ai.playground.max_question_chars', 2000) . ' caracteres.']
            );

            return;
        }

        $mode = ($_POST['mode'] ?? 'single') === 'auto' ? 'auto' : 'single';
        $selectedId = isset($_POST['provider_id']) && ctype_digit((string) $_POST['provider_id']) ? (int) $_POST['provider_id'] : 0;
        $activeId = $selectedId > 0 ? $selectedId : $this->activeProviderId();
        $provider = $this->service->provider($activeId);

        if ($mode === 'single' && ($provider === null || !$provider['has_key'])) {
            $this->renderAiPlayground(null, $question, [
                'provider' => 'Configure a API Key do ' . ($provider['label'] ?? 'provider') . ' antes de utilizar a IA.',
            ]);

            return;
        }

        $systemPrompt = $this->service->systemPrompt();
        $messages = [];

        if ($systemPrompt !== null && trim($systemPrompt) !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        $messages[] = ['role' => 'user', 'content' => $question];

        // A) somente o provider selecionado; B) estratégia automática (força o
        // fallback só neste teste, sem mudar a configuração salva).
        $result = $mode === 'auto'
            ? $this->service->chatWithFallback($messages, Auth::instance()->id(), null, true)
            : $this->service->chat($activeId, $messages, Auth::instance()->id());
        $result['mode'] = $mode;

        $this->renderAiPlayground($result, $question, []);
    }

    private function renderAiPlayground(?array $result, string $question, array $errors): void
    {
        $providers = $this->service->listProviders();
        $active = [];

        foreach ($providers as $provider) {
            if ($provider['status'] === 'active') {
                $active[] = $provider;
            }
        }

        $this->render('ai/playground', [
            'appTitle' => 'Testar IA — Playground',
            'providers' => $active,
            'systemPrompt' => $this->service->systemPrompt(),
            'question' => $question,
            'result' => $result,
            'errors' => $errors,
            'selectedProviderId' => isset($_POST['provider_id']) ? (int) $_POST['provider_id'] : $this->activeProviderId(),
            'mode' => ($_POST['mode'] ?? 'single') === 'auto' ? 'auto' : 'single',
            'labels' => array_column($providers, 'name', 'provider'),
        ]);
    }

    /** Cards na ordem fixa OpenRouter → Gemini → Groq → NVIDIA (demais ao final). */
    private function orderedProviders(): array
    {
        $order = array_flip(array_keys(\App\Services\AI\AIProviderFactory::PROVIDERS));
        $providers = $this->service->listProviders();

        usort($providers, static fn (array $a, array $b): int => ($order[$a['provider']] ?? 99) <=> ($order[$b['provider']] ?? 99));

        return $providers;
    }

    private function activeProviderId(): int
    {
        $providers = $this->service->listProviders();

        // Mesma regra do chat: somente o provider PADRÃO e ativo (sem fallback
        // para outro ativo). Sem padrão, usa a linha do OpenRouter apenas para
        // exibir a orientação de configuração.
        $defaultId = $this->service->defaultProviderId();

        if ($defaultId !== null) {
            return $defaultId;
        }

        foreach ($providers as $provider) {
            if ($provider['provider'] === 'openrouter') {
                return (int) $provider['id'];
            }
        }

        return $providers !== [] ? (int) $providers[0]['id'] : 0;
    }

    private function flashTestResult(): array
    {
        $result = [];

        foreach ($this->service->listProviders() as $provider) {
            $raw = Flash::old('ai_test_result_' . (int) $provider['id']);

            if ($raw === '') {
                continue;
            }

            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $result[(int) $provider['id']] = $decoded;
            }
        }

        return $result;
    }
}