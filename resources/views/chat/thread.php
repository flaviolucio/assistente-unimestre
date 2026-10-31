<?php
$conversationId = (int) $conversation['id'];
$historyUrl = app_url('history');
$chatUrl = app_url('assistente');
$feedback = isset($feedback) ? (array) $feedback : [];
$canSave = (bool) ($canManageSolutions ?? false);

$formatDate = static function (string $value): string {
    $ts = strtotime($value);

    if ($ts === false) {
        return $value;
    }

    return date('d/m/Y H:i', $ts);
};
?>
<div class="chat-page">
    <div class="chat-page-header">
        <div class="min-w-0">
            <div class="mb-2">
                <a href="<?php echo e($historyUrl); ?>" class="btn btn-outline-secondary btn-sm">&larr; Histórico</a>
                <a href="<?php echo e($chatUrl); ?>" class="btn btn-outline-secondary btn-sm">Nova conversa</a>
            </div>
            <h1 class="h4 mb-0 text-break"><?php echo e((string) $conversation['title']); ?></h1>
            <span class="text-muted small"><?php echo e($formatDate((string) $conversation['created_at'])); ?></span>
        </div>
    </div>

    <div id="chat-messages" class="chat-thread">
        <?php foreach ($messages as $message): ?>
            <?php $isAssistant = $message['role'] === 'assistant'; ?>
            <?php if (!$isAssistant): ?>
                <div class="chat-row chat-row-user flex-column align-items-end">
                    <div class="chat-user"><?php echo e((string) $message['content']); ?></div>
                    <?php if ($canSave): ?>
                        <div class="chat-user-actions">
                            <a class="chat-action-btn" href="<?php echo e(app_url('assistente/' . (int) $message['id'] . '/salvar-solucao')); ?>"
                               title="Criar solução interna a partir da pergunta">＋ Criar solução interna</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php
                $msgId = (int) $message['id'];
                $sources = (array) ($message['sources'] ?? []);
                $trusted = [];
                foreach ($sources as $source) {
                    if (\App\Support\SourceUrl::isTrusted($source)) {
                        $trusted[] = (string) $source['url'];
                    }
                }
                ?>
                <div class="chat-row chat-row-assistant">
                    <div class="chat-assistant">
                        <div class="chat-avatar" aria-hidden="true">AU</div>
                        <div class="chat-assistant-body">
                            <div class="chat-assistant-name">Assistente UNIMESTRE</div>
                            <div class="chat-bubble chat-md"
                                 data-md="<?php echo e((string) $message['content']); ?>"
                                 data-trusted="<?php echo e((string) json_encode($trusted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>"
                                 data-source-count="<?php echo count($sources); ?>"
                                 data-sources-id="sources-<?php echo $msgId; ?>"><?php echo nl2br(e((string) $message['content'])); ?></div>

                            <?php if ((int) $message['truncated'] === 1): ?>
                                <div class="chat-bubble chat-bubble-notice mt-2"><?php echo e(\App\Services\RAG\RAGService::MSG_TRUNCATED_NOTICE); ?></div>
                            <?php endif; ?>

                            <?php if ($sources !== []): ?>
                                <details class="chat-sources" id="sources-<?php echo $msgId; ?>">
                                    <summary class="chat-sources-summary"><span class="chat-sources-icon">📚</span> Fontes consultadas (<?php echo count($sources); ?>)</summary>
                                    <div class="chat-sources-list">
                                        <?php foreach ($sources as $index => $source): ?>
                                            <?php
                                            $number = (int) $index + 1;
                                            $evidenceType = \App\Services\Copilot\EvidenceType::fromSource($source);
                                            $isInternal = $evidenceType !== 'official';
                                            $sourceTitle = (string) ($source['title'] ?? ('Fonte ' . $number));
                                            $sourceUrl = \App\Support\SourceUrl::isTrusted($source) ? (string) $source['url'] : null;
                                            $meta = [];
                                            if (!empty($source['category'])) {
                                                $meta[] = (string) $source['category'];
                                            }
                                            if (!empty($source['relevance_score'])) {
                                                $meta[] = 'Relevância ' . number_format((float) $source['relevance_score'], 2, '.', '');
                                            }
                                            ?>
                                            <div class="chat-source" data-source-number="<?php echo $number; ?>">
                                                <div class="chat-source-head">
                                                    <span class="chat-source-num"><?php echo $number; ?></span>
                                                    <span class="chat-badge <?php echo e(\App\Services\Copilot\EvidenceType::cssClass($evidenceType)); ?>"><?php echo e(\App\Services\Copilot\EvidenceType::badge($evidenceType)); ?></span>
                                                </div>
                                                <div class="chat-source-title"><?php echo e($sourceTitle); ?></div>
                                                <?php if ($meta !== []): ?>
                                                    <div class="chat-source-meta"><?php echo e(implode(' · ', $meta)); ?></div>
                                                <?php endif; ?>
                                                <?php if ($sourceUrl !== null): ?>
                                                    <a class="btn btn-sm btn-outline-primary chat-source-open" href="<?php echo e($sourceUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo $isInternal ? 'Abrir fonte' : 'Abrir fonte oficial'; ?></a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endif; ?>

                            <div class="chat-actions">
                                <?php if (isset($feedback[$msgId])): ?>
                                    <span>
                                        Avaliação: <?php echo $feedback[$msgId]['rating'] === 'util' ? 'útil' : 'não útil'; ?>
                                        <?php if (!empty($feedback[$msgId]['comment'])): ?>
                                            — <?php echo e((string) $feedback[$msgId]['comment']); ?>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="chat-feedback" data-feedback-row="<?php echo $msgId; ?>">
                                        <span data-feedback-label>Esta resposta foi útil?</span>
                                        <button type="button" class="chat-action-btn" data-feedback="util" data-message-id="<?php echo $msgId; ?>">👍 Sim</button>
                                        <button type="button" class="chat-action-btn" data-feedback="nao_util" data-message-id="<?php echo $msgId; ?>">👎 Não</button>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <div id="chat-end"></div>
    </div>

    <div id="chat-diagnostic-panel" class="dx-host" aria-live="polite"<?php echo empty($diagnosticHtml) ? ' hidden' : ''; ?>><?php echo $diagnosticHtml ?? ''; ?></div>

    <form id="chat-form" class="chat-composer" autocomplete="off">
        <div class="chat-composer-box">
            <label for="chat-question" class="visually-hidden">Continuar conversa</label>
            <textarea id="chat-question" rows="1"
                      placeholder="Continue perguntando sobre esta conversa..."></textarea>
            <button id="chat-send" type="submit" class="btn btn-primary chat-send">Enviar</button>
        </div>
        <div class="chat-composer-hint">Enter envia · Shift+Enter quebra linha</div>
    </form>
</div>

<div id="chat-config"
     data-csrf="<?php echo e((string) ($chatCsrf ?? '')); ?>"
     data-url="<?php echo e(app_url('api/chat')); ?>"
     data-conversation="<?php echo $conversationId; ?>"
     data-support="<?php echo !empty($isTechSupport) ? '1' : '0'; ?>"></div>

<script src="<?php echo e(app_url('assets/js/assistente.js')); ?>"></script>
<script>
(function () {
    'use strict';

    var ui = window.ChatUI;
    var el = ui.el;
    var SCROLL_KEY = 'chat-scroll-end-<?php echo $conversationId; ?>';

    var config = document.getElementById('chat-config');
    var csrf = config.getAttribute('data-csrf');
    var apiUrl = config.getAttribute('data-url');
    var conversationId = config.getAttribute('data-conversation');
    var isSupport = config.getAttribute('data-support') === '1';
    var loginUrl = '<?php echo e(app_url('login')); ?>';

    var messagesEl = document.getElementById('chat-messages');
    var endEl = document.getElementById('chat-end');
    var form = document.getElementById('chat-form');
    var input = document.getElementById('chat-question');
    var sendBtn = document.getElementById('chat-send');
    var resizeInput = ui.autoGrow(input, 200);
    var busy = false;

    // Painel do diagnóstico (7.4): HTML do backend; o JS só liga as ações.
    ui.bindDiagnosticPanel(document.getElementById('chat-diagnostic-panel'), {
        apiUrl: apiUrl,
        csrf: csrf,
        conversationId: function () { return conversationId; },
        input: input,
        loginUrl: loginUrl
    });

    // Markdown seguro nas respostas já persistidas (o fallback do servidor é texto escapado).
    document.querySelectorAll('.chat-md[data-md]').forEach(function (bubble) {
        var trusted = [];
        try {
            trusted = JSON.parse(bubble.getAttribute('data-trusted') || '[]');
        } catch (e) {
            trusted = [];
        }
        var sourcesEl = document.getElementById(bubble.getAttribute('data-sources-id'));
        ui.renderMarkdown(bubble, bubble.getAttribute('data-md'), {
            trusted: Array.isArray(trusted) ? trusted : [],
            sourceCount: parseInt(bubble.getAttribute('data-source-count') || '0', 10),
            onCite: function (n) { ui.focusSource(sourcesEl, n); }
        });
    });

    // Após enviar, a página recarrega e volta para o fim da conversa.
    try {
        if (window.sessionStorage.getItem(SCROLL_KEY) === '1') {
            window.sessionStorage.removeItem(SCROLL_KEY);
            endEl.scrollIntoView({ block: 'end' });
        }
    } catch (e) { /* armazenamento indisponível: mantém o topo */ }

    function uid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    document.querySelectorAll('[data-feedback]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var messageId = btn.getAttribute('data-message-id');
            var rating = btn.getAttribute('data-feedback');

            fetch(apiUrl + '/' + messageId + '/feedback', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ rating: rating })
            }).then(function (resp) {
                if (resp.status === 401) {
                    window.location.href = loginUrl;
                    return;
                }
                return resp.json();
            }).then(function (data) {
                if (data && data.ok) {
                    var row = document.querySelector('[data-feedback-row="' + messageId + '"]');
                    if (row) {
                        row.textContent = rating === 'util' ? 'Obrigado pelo feedback!' : 'Feedback registrado.';
                    }
                }
            }).catch(function () {});
        });
    });

    function addPending(question) {
        var userRow = el('div', 'chat-row chat-row-user');
        userRow.appendChild(el('div', 'chat-user', question));
        messagesEl.insertBefore(userRow, endEl);

        var row = el('div', 'chat-row chat-row-assistant');
        var wrap = el('div', 'chat-assistant');
        var avatar = el('div', 'chat-avatar', 'AU');
        avatar.setAttribute('aria-hidden', 'true');
        wrap.appendChild(avatar);
        var body = el('div', 'chat-assistant-body');
        body.appendChild(el('div', 'chat-assistant-name', 'Assistente UNIMESTRE'));
        var bubble = el('div', 'chat-bubble');
        var typing = el('span', 'chat-typing');
        var dots = el('span', 'chat-typing-dots');
        dots.appendChild(el('span'));
        dots.appendChild(el('span'));
        dots.appendChild(el('span'));
        typing.appendChild(dots);
        typing.appendChild(el('span', null, 'Consultando a base de conhecimento...'));
        bubble.appendChild(typing);
        body.appendChild(bubble);
        wrap.appendChild(body);
        row.appendChild(wrap);
        messagesEl.insertBefore(row, endEl);
        ui.scrollPageToBottom();
        return bubble;
    }

    function send() {
        if (busy) {
            return;
        }

        var question = input.value.trim();

        if (question === '') {
            return;
        }

        busy = true;
        sendBtn.disabled = true;
        input.disabled = true;
        input.value = '';
        resizeInput();
        var pending = addPending(question);

        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                question: question,
                conversation_id: conversationId,
                client_request_id: uid()
            })
        }).then(function (resp) {
            if (resp.status === 401) {
                window.location.href = loginUrl;
                throw new Error('unauthenticated');
            }
            return resp.json().then(function (data) {
                return { status: resp.status, data: data || {} };
            });
        }).then(function (result) {
            var data = result.data;

            // Resposta do assistente gravada: recarrega para exibir a conversa persistida.
            if (result.status < 400 && data.ok && data.assistant_message_id) {
                try {
                    window.sessionStorage.setItem(SCROLL_KEY, '1');
                } catch (e) { /* sem armazenamento: recarrega no topo */ }
                window.location.reload();
                return;
            }

            // Falha (provider_failure, 429, validação, duplicada…): nada foi gravado
            // como resposta. NÃO recarrega; a pergunta e o aviso ficam visíveis.
            showFailure(pending, question, data.message || data.answer
                || 'Não foi possível consultar o assistente neste momento. Tente novamente.', data);
        }).catch(function (err) {
            if (err && err.message === 'unauthenticated') {
                return;
            }
            showFailure(pending, question, 'Não foi possível consultar o assistente neste momento. Tente novamente.', {});
        });
    }

    /* Mostra o aviso seguro no lugar do "digitando" e libera nova tentativa MANUAL. */
    function showFailure(bubble, question, message, data) {
        bubble.className = 'chat-bubble chat-bubble-notice';
        bubble.textContent = message;
        bubble.setAttribute('data-chat-failure', '1');

        var body = bubble.parentNode;

        if (isSupport && data && data.error_code && data.gateway_state) {
            body.appendChild(el('div', 'chat-bubble chat-bubble-muted mt-2',
                'Diagnóstico técnico (admin/técnico) — Código: ' + data.error_code + ' · Estado: ' + data.gateway_state));
        }

        var actions = el('div', 'chat-actions');
        var retry = el('button', 'chat-action-btn', '↻ Reenviar pergunta');
        retry.type = 'button';
        retry.title = 'Coloca a pergunta de volta no campo para você enviar novamente';
        retry.addEventListener('click', function () {
            input.value = question;
            resizeInput();
            input.focus();
        });
        actions.appendChild(retry);
        body.appendChild(actions);

        busy = false;
        sendBtn.disabled = false;
        input.disabled = false;
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        send();
    });

    input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {
            ev.preventDefault();
            send();
        }
    });
})();
</script>
