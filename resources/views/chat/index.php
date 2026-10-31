<?php
use App\Services\Security\Auth;

$chatUrl = app_url('api/chat');
$historyUrl = app_url('history');
$threadUrl = app_url('');
$canSave = Auth::instance()->can('solutions.manage');
?>
<div class="chat-page">
    <div class="chat-page-header">
        <h1 class="h4 mb-0">Assistente UNIMESTRE</h1>
        <a href="<?php echo e($historyUrl); ?>" class="btn btn-outline-secondary btn-sm">Histórico de conversas</a>
    </div>

    <div id="chat-messages" class="chat-thread" aria-live="polite">
        <div class="chat-row chat-row-assistant">
            <div class="chat-assistant">
                <div class="chat-avatar" aria-hidden="true">AU</div>
                <div class="chat-assistant-body">
                    <div class="chat-assistant-name">Assistente UNIMESTRE</div>
                    <div class="chat-bubble">
                        Olá! Sou o assistente do UNIMESTRE. Pergunte sobre sistemas, acesso, senhas, procedimentos e serviços.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="chat-diagnostic-panel" class="dx-host" aria-live="polite" hidden></div>

    <form id="chat-form" class="chat-composer" autocomplete="off">
        <div class="chat-composer-box">
            <label for="chat-question" class="visually-hidden">Sua dúvida</label>
            <textarea id="chat-question" rows="1" maxlength="<?php echo (int) ($chatMaxLength ?? 4000); ?>"
                      placeholder="Digite sua dúvida (ex.: esqueci minha senha do portal)"></textarea>
            <button id="chat-send" type="submit" class="btn btn-primary chat-send">Enviar</button>
        </div>
        <div class="chat-composer-hint">
            Enter envia · Shift+Enter quebra linha · Respostas baseadas na base de conhecimento do UNIMESTRE.
        </div>
    </form>
</div>

<div id="chat-config"
     data-csrf="<?php echo e((string) ($chatCsrf ?? '')); ?>"
     data-url="<?php echo e($chatUrl); ?>"
     data-history="<?php echo e($historyUrl); ?>"
     data-can-save="<?php echo $canSave ? '1' : '0'; ?>"
     data-support="<?php echo !empty($isTechSupport) ? '1' : '0'; ?>"></div>

<script src="<?php echo e(app_url('assets/js/assistente.js')); ?>"></script>
<script>
(function () {
    'use strict';

    var ui = window.ChatUI;
    var el = ui.el;

    var config = document.getElementById('chat-config');
    var csrf = config.getAttribute('data-csrf');
    var apiUrl = config.getAttribute('data-url');
    var threadBase = '<?php echo e(rtrim($threadUrl, '/') . '/assistente'); ?>';
    var loginUrl = '<?php echo e(app_url('login')); ?>';
    var canSave = config.getAttribute('data-can-save') === '1';
    var isSupport = config.getAttribute('data-support') === '1';

    var messagesEl = document.getElementById('chat-messages');
    var form = document.getElementById('chat-form');
    var input = document.getElementById('chat-question');
    var sendBtn = document.getElementById('chat-send');
    var resizeInput = ui.autoGrow(input, 200);

    var conversationId = null;
    var panelHost = document.getElementById('chat-diagnostic-panel');
    ui.bindDiagnosticPanel(panelHost, {
        apiUrl: apiUrl,
        csrf: csrf,
        conversationId: function () { return conversationId; },
        input: input,
        loginUrl: loginUrl
    });
    var busy = false;

    function uid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    function addUser(text) {
        var row = el('div', 'chat-row chat-row-user');
        row.appendChild(el('div', 'chat-user', text));
        messagesEl.appendChild(row);
        return row;
    }

    /* Linha do assistente: avatar + nome + corpo; devolve {row, body}. */
    function addAssistantRow() {
        var row = el('div', 'chat-row chat-row-assistant');
        var wrap = el('div', 'chat-assistant');
        var avatar = el('div', 'chat-avatar', 'AU');
        avatar.setAttribute('aria-hidden', 'true');
        wrap.appendChild(avatar);
        var body = el('div', 'chat-assistant-body');
        body.appendChild(el('div', 'chat-assistant-name', 'Assistente UNIMESTRE'));
        wrap.appendChild(body);
        row.appendChild(wrap);
        messagesEl.appendChild(row);
        return { row: row, body: body };
    }

    function addAssistantText(text, extraClass) {
        var parts = addAssistantRow();
        parts.body.appendChild(el('div', 'chat-bubble' + (extraClass ? ' ' + extraClass : ''), text));
        return parts.row;
    }

    function addTyping() {
        var parts = addAssistantRow();
        var bubble = el('div', 'chat-bubble');
        var typing = el('span', 'chat-typing');
        var dots = el('span', 'chat-typing-dots');
        dots.appendChild(el('span'));
        dots.appendChild(el('span'));
        dots.appendChild(el('span'));
        typing.appendChild(dots);
        typing.appendChild(el('span', null, 'Consultando a base de conhecimento...'));
        bubble.appendChild(typing);
        parts.body.appendChild(bubble);
        return parts.row;
    }

    function buildFeedback(messageId) {
        var box = el('span', 'chat-feedback');
        var label = el('span', null, 'Esta resposta foi útil?');
        var util = el('button', 'chat-action-btn', '👍 Sim');
        util.type = 'button';
        var naoUtil = el('button', 'chat-action-btn', '👎 Não');
        naoUtil.type = 'button';
        box.appendChild(label);
        box.appendChild(util);
        box.appendChild(naoUtil);

        var done = function (btn) {
            util.disabled = true;
            naoUtil.disabled = true;
            label.textContent = btn === util ? 'Obrigado pelo feedback!' : 'Feedback registrado.';
        };

        var send = function (rating) {
            return function () {
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
                        done(rating === 'util' ? util : naoUtil);
                    }
                }).catch(function () {});
            };
        };

        util.addEventListener('click', send('util'));
        naoUtil.addEventListener('click', send('nao_util'));
        return box;
    }

    function diagnosticText(data) {
        if (!isSupport || !data.error_code) {
            return null;
        }
        var states = ['provider_unavailable', 'provider_failure', 'timeout', 'rate_limited'];
        if (states.indexOf(data.gateway_state) === -1) {
            return null;
        }
        return 'Diagnóstico técnico (admin/técnico) — Código: ' + data.error_code + ' · Estado: ' + data.gateway_state;
    }

    function renderAnswer(data) {
        var sources = data.sources || [];
        var parts = addAssistantRow();
        var bubble = el('div', 'chat-bubble chat-md');
        var sourcesEl = sources.length > 0 ? ui.buildSources(sources) : null;

        ui.renderMarkdown(bubble, data.answer || '', {
            trusted: ui.trustedUrlsFrom(sources),
            sourceCount: sources.length,
            onCite: function (n) { ui.focusSource(sourcesEl, n); }
        });
        parts.body.appendChild(bubble);

        if (data.truncated && data.truncated_notice) {
            parts.body.appendChild(el('div', 'chat-bubble chat-bubble-notice mt-2', data.truncated_notice));
        }

        if (sourcesEl) {
            parts.body.appendChild(sourcesEl);
        }

        var diag = diagnosticText(data);
        if (diag) {
            parts.body.appendChild(el('div', 'chat-bubble chat-bubble-muted mt-2', diag));
        }

        if (!data.duplicate && data.assistant_message_id) {
            var actions = el('div', 'chat-actions');
            actions.appendChild(buildFeedback(data.assistant_message_id));

            if (canSave && data.user_message_id) {
                var save = el('a', 'chat-action-btn', '＋ Criar procedimento interno');
                save.href = '<?php echo e(app_url('procedimentos/novo')); ?>?from_message=' + data.user_message_id;
                save.title = 'Criar procedimento interno (revisão humana obrigatória)';
                actions.appendChild(save);
            }

            parts.body.appendChild(actions);
        }

        return parts.row;
    }

    function revealAnswer(row) {
        // Mostra o início da resposta; respostas longas continuam na rolagem da página.
        row.scrollIntoView({ behavior: 'smooth', block: 'start' });
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

        addUser(question);
        input.value = '';
        resizeInput();
        var typingRow = addTyping();
        ui.scrollPageToBottom();

        var finishTyping = function () {
            if (typingRow && typingRow.parentNode) {
                typingRow.parentNode.removeChild(typingRow);
            }
            typingRow = null;
        };

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
                return { status: resp.status, data: data };
            });
        }).then(function (result) {
            var data = result.data || {};
            finishTyping();

            if (data.duplicate) {
                revealAnswer(addAssistantText(data.message || 'Requisição duplicada ignorada.', 'chat-bubble-muted'));
                return;
            }

            if (result.status >= 400) {
                revealAnswer(addAssistantText(data.message || 'Não foi possível processar a pergunta.', 'chat-bubble-notice'));
                return;
            }

            conversationId = data.conversation_id;
            revealAnswer(renderAnswer(data));
            // Estado do diagnóstico: HTML pronto do backend (fonte da verdade).
            if (Object.prototype.hasOwnProperty.call(data, 'diagnostic_html')) {
                ui.setPanelHtml(panelHost, data.diagnostic_html);
            }
        }).catch(function (err) {
            finishTyping();
            if (err && err.message === 'unauthenticated') {
                return;
            }
            revealAnswer(addAssistantText('Não foi possível consultar o assistente neste momento. Tente novamente.', 'chat-bubble-notice'));
        }).finally(function () {
            busy = false;
            sendBtn.disabled = false;
            input.disabled = false;
            input.focus({ preventScroll: true });
        });
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
