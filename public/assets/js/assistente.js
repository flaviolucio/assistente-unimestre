/*
 * Componentes visuais do chat do Assistente UNIMESTRE (somente apresentação).
 *
 * Segurança:
 *  - O Markdown da IA é convertido em DOM usando apenas createElement /
 *    textContent. Nunca há innerHTML com conteúdo externo: HTML devolvido
 *    pela IA aparece como texto, jamais é interpretado.
 *  - Links só ficam clicáveis quando a URL é uma das fontes validadas pelo
 *    backend para aquela resposta (lista "trusted"). URLs escritas pela IA
 *    fora dessa lista são exibidas como texto (proteção contra URL inventada).
 */
(function (global) {
    'use strict';

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    function normalizeUrl(url) {
        return String(url || '').trim().replace(/[).,;:!?]+$/, '');
    }

    function isHttpUrl(url) {
        return /^https?:\/\/[^\s<>"']+$/i.test(url);
    }

    function isSafeSourceUrl(url, title) {
        if (!url) {
            return false;
        }
        var trimmed = String(url).trim();
        if (!/^https?:\/\//i.test(trimmed)) {
            return false;
        }
        if (/^\s*(F4|F5|F6)[\s-]/i.test(String(title || ''))) {
            return false;
        }
        try {
            var host = new URL(trimmed).hostname.toLowerCase();
            var reserved = ['.example', 'example.com', 'example.org', 'example.net'];
            for (var i = 0; i < reserved.length; i++) {
                var r = reserved[i];
                if (host === r.replace(/^\./, '') || host.endsWith(r)) {
                    return false;
                }
            }
        } catch (e) {
            return false;
        }
        return true;
    }

    /*
     * Remove a seção final "Fonte(s): [Fonte 1] …" que a IA costuma escrever:
     * ela duplica o painel "Fontes consultadas", que é gerado pelo backend.
     * Só remove quando TODAS as linhas da seção citam [Fonte n] ou são URLs.
     */
    function stripTrailingSourcesSection(text) {
        var lines = text.split('\n');
        var header = /^\s*(?:#{1,6}\s*)?(?:\*\*|__)?\s*fontes?(?:\s+(?:consultadas|utilizadas|citadas))?\s*:?\s*(?:\*\*|__)?\s*:?\s*(.*)$/i;

        for (var i = lines.length - 1; i >= 0; i--) {
            var m = lines[i].match(header);
            if (!m) {
                continue;
            }
            var rest = [m[1]].concat(lines.slice(i + 1)).filter(function (l) {
                return l.trim() !== '';
            });
            if (rest.length === 0) {
                return text;
            }
            var allRefs = rest.every(function (l) {
                return /\[\s*fonte\s*\d+\s*\]/i.test(l) || /^\s*[-*•]?\s*https?:\/\/\S+\s*$/i.test(l);
            });
            return allRefs ? lines.slice(0, i).join('\n').replace(/\s+$/, '') : text;
        }
        return text;
    }

    var INLINE = /(\*\*([^*]+?)\*\*|__([^_]+?)__|`([^`]+)`|\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)|\[\s*[Ff]onte\s*(\d+)\s*\]|(https?:\/\/[^\s<>"')\]]+)|(^|[\s(])\*([^*\s][^*\n]*?[^*\s]|[^*\s])\*(?=[\s).,;:!?]|$))/g;

    function appendInline(parent, text, ctx) {
        var last = 0;
        var match;
        // Instância própria por chamada: a recursão (negrito/itálico) não pode
        // compartilhar o lastIndex do regex global com o laço externo.
        var re = new RegExp(INLINE.source, 'g');

        while ((match = re.exec(text)) !== null) {
            if (match[0] === '') {
                re.lastIndex++;
                continue;
            }
            var start = match.index;
            var lead = '';

            if (match[10] !== undefined) { // itálico *x*: preserva o caractere anterior
                lead = match[9] || '';
            }

            if (start + lead.length > last) {
                parent.appendChild(document.createTextNode(text.slice(last, start) + lead));
            } else if (lead) {
                parent.appendChild(document.createTextNode(lead));
            }

            if (match[2] !== undefined || match[3] !== undefined) {
                var strong = el('strong');
                appendInline(strong, match[2] !== undefined ? match[2] : match[3], ctx);
                parent.appendChild(strong);
            } else if (match[4] !== undefined) {
                parent.appendChild(el('code', 'chat-md-code', match[4]));
            } else if (match[5] !== undefined) {
                appendLink(parent, match[5], match[6], ctx);
            } else if (match[7] !== undefined) {
                appendCitation(parent, parseInt(match[7], 10), match[0], ctx);
            } else if (match[8] !== undefined) {
                var raw = match[8];
                var url = normalizeUrl(raw);
                appendLink(parent, url, url, ctx);
                if (raw.length > url.length) {
                    parent.appendChild(document.createTextNode(raw.slice(url.length)));
                }
            } else if (match[10] !== undefined) {
                var em = el('em');
                appendInline(em, match[10], ctx);
                parent.appendChild(em);
            }

            last = re.lastIndex;
        }

        if (last < text.length) {
            parent.appendChild(document.createTextNode(text.slice(last)));
        }
    }

    function appendLink(parent, label, url, ctx) {
        var clean = normalizeUrl(url);
        if (isHttpUrl(clean) && ctx.trusted.indexOf(clean) !== -1) {
            var a = el('a', 'app-link', label);
            a.href = clean;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            parent.appendChild(a);
            return;
        }
        // URL não validada pelo backend: exibida como texto, nunca como link.
        var span = el('span', 'chat-md-untrusted', label === url ? label : label + ' (' + url + ')');
        span.title = 'Link não verificado: não corresponde a uma fonte consultada.';
        parent.appendChild(span);
    }

    function appendCitation(parent, number, rawText, ctx) {
        if (ctx.sourceCount < number || number < 1 || typeof ctx.onCite !== 'function') {
            parent.appendChild(document.createTextNode(rawText));
            return;
        }
        var btn = el('button', 'chat-cite', String(number));
        btn.type = 'button';
        btn.title = 'Ver fonte ' + number;
        btn.setAttribute('aria-label', 'Ver fonte ' + number);
        btn.addEventListener('click', function () {
            ctx.onCite(number);
        });
        parent.appendChild(btn);
    }

    /*
     * Renderiza o subconjunto seguro de Markdown: parágrafos, quebras de linha,
     * títulos (como parágrafo em destaque), listas numeradas/com marcadores
     * (um nível de aninhamento), negrito, itálico, código inline, links
     * confiáveis e referências [Fonte n].
     */
    function renderMarkdown(container, text, options) {
        var ctx = {
            trusted: (options && options.trusted ? options.trusted : []).map(normalizeUrl),
            sourceCount: options && options.sourceCount ? options.sourceCount : 0,
            onCite: options && options.onCite ? options.onCite : null
        };

        var source = String(text || '').replace(/\r\n?/g, '\n');
        if (ctx.sourceCount > 0) {
            source = stripTrailingSourcesSection(source);
        }

        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }

        var lines = source.split('\n');
        var paragraph = null;
        var list = null;
        var listType = null;
        var lastItem = null;
        var subList = null;

        function closeParagraph() {
            paragraph = null;
        }

        function closeList() {
            list = null;
            listType = null;
            lastItem = null;
            subList = null;
        }

        lines.forEach(function (line) {
            if (line.trim() === '') {
                closeParagraph();
                if (list) {
                    subList = null;
                }
                return;
            }

            var indent = line.match(/^\s*/)[0].replace(/\t/g, '    ').length;
            var heading = line.match(/^\s*#{1,6}\s+(.*)$/);
            var ordered = line.match(/^\s*(\d{1,3})[.)]\s+(.*)$/);
            var bullet = line.match(/^\s*[-*•+]\s+(.*)$/);
            var rule = /^\s*([-*_])(\s*\1){2,}\s*$/.test(line);

            if (rule) {
                closeParagraph();
                closeList();
                container.appendChild(el('hr', 'chat-md-rule'));
                return;
            }

            if (heading) {
                closeParagraph();
                closeList();
                var h = el('p', 'chat-md-heading');
                appendInline(h, heading[1], ctx);
                container.appendChild(h);
                return;
            }

            if ((ordered || bullet) && list && indent >= 2 && lastItem) {
                // Item aninhado sob o último item da lista
                if (!subList) {
                    subList = el(ordered ? 'ol' : 'ul', 'chat-md-list');
                    lastItem.appendChild(subList);
                }
                var sub = el('li');
                appendInline(sub, ordered ? ordered[2] : bullet[1], ctx);
                subList.appendChild(sub);
                return;
            }

            if (ordered || bullet) {
                closeParagraph();
                var type = ordered ? 'ol' : 'ul';
                if (!list || listType !== type) {
                    list = el(type, 'chat-md-list');
                    listType = type;
                    if (ordered && parseInt(ordered[1], 10) > 1) {
                        list.start = parseInt(ordered[1], 10);
                    }
                    container.appendChild(list);
                }
                subList = null;
                lastItem = el('li');
                appendInline(lastItem, ordered ? ordered[2] : bullet[1], ctx);
                list.appendChild(lastItem);
                return;
            }

            if (list && lastItem && indent >= 2) {
                // Continuação do item (texto recuado)
                lastItem.appendChild(el('br'));
                appendInline(lastItem, line.trim(), ctx);
                return;
            }

            closeList();

            if (!paragraph) {
                paragraph = el('p');
                container.appendChild(paragraph);
            } else {
                paragraph.appendChild(el('br'));
            }
            appendInline(paragraph, line.trim(), ctx);
        });

        if (!container.firstChild) {
            container.appendChild(el('p', null, String(text || '')));
        }
    }

    /* Painel compacto de fontes (começa recolhido). */
    function buildSources(sources) {
        var details = el('details', 'chat-sources');
        var summary = el('summary', 'chat-sources-summary');
        summary.appendChild(el('span', 'chat-sources-icon', '📚'));
        summary.appendChild(document.createTextNode(' Fontes consultadas (' + sources.length + ')'));
        details.appendChild(summary);

        var list = el('div', 'chat-sources-list');

        sources.forEach(function (source, index) {
            var number = source.number || index + 1;
            var item = el('div', 'chat-source');
            item.setAttribute('data-source-number', String(number));

            var head = el('div', 'chat-source-head');
            head.appendChild(el('span', 'chat-source-num', String(number)));
            var isInternal = source.source_type !== 'official';
            head.appendChild(el('span', 'chat-badge ' + (source.badge_class || (isInternal ? 'chat-badge-internal' : 'chat-badge-official')),
                source.source_badge || source.source_type_label || (isInternal ? 'PROCEDIMENTO INTERNO' : 'OFICIAL UNIMESTRE')));
            item.appendChild(head);

            item.appendChild(el('div', 'chat-source-title', source.title || ('Fonte ' + number)));

            var meta = [];
            if (source.category) {
                meta.push(source.category);
            }
            if (typeof source.relevance_score === 'number') {
                meta.push('Relevância ' + source.relevance_score.toFixed(2));
            }
            if (meta.length) {
                item.appendChild(el('div', 'chat-source-meta', meta.join(' · ')));
            }

            if (source.snippet) {
                item.appendChild(el('div', 'chat-source-snippet', source.snippet));
            }

            if (isSafeSourceUrl(source.url, source.title)) {
                var open = el('a', 'btn btn-sm btn-outline-primary chat-source-open',
                    isInternal ? 'Abrir fonte' : 'Abrir fonte oficial');
                open.href = source.url;
                open.target = '_blank';
                open.rel = 'noopener noreferrer';
                item.appendChild(open);
            }

            list.appendChild(item);
        });

        details.appendChild(list);
        return details;
    }

    /* Abre o painel de fontes e destaca a fonte n. */
    function focusSource(details, number) {
        if (!details) {
            return;
        }
        details.open = true;
        var item = details.querySelector('[data-source-number="' + number + '"]');
        if (!item) {
            return;
        }
        item.classList.add('chat-source-highlight');
        item.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        window.setTimeout(function () {
            item.classList.remove('chat-source-highlight');
        }, 1600);
    }

    function trustedUrlsFrom(sources) {
        return (sources || []).filter(function (s) {
            return isSafeSourceUrl(s.url, s.title);
        }).map(function (s) {
            return normalizeUrl(s.url);
        });
    }

    /* Textarea cresce com o conteúdo até um limite (depois rola internamente). */
    function autoGrow(textarea, maxPx) {
        var resize = function () {
            if (textarea.value === '') {
                // Vazio: altura natural (rows=1). Evita medir com layout ainda sem largura.
                textarea.style.height = '';
                textarea.style.overflowY = 'hidden';
                return;
            }
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, maxPx) + 'px';
            textarea.style.overflowY = textarea.scrollHeight > maxPx ? 'auto' : 'hidden';
        };
        textarea.addEventListener('input', resize);
        window.addEventListener('resize', resize);
        resize();
        return resize;
    }

    function scrollPageToBottom() {
        window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'smooth' });
    }

    /*
     * Painel do diagnóstico (Etapa 7.4). O HTML vem PRONTO do backend
     * (resources/views/chat/_diagnostic_panel.php, texto já escapado). Este
     * código só alterna visibilidade, foca o composer e chama os endpoints
     * de fechamento — nenhuma regra de diagnóstico vive no JavaScript.
     *
     * options: { apiUrl, csrf, conversationId, input, loginUrl }
     */
    function bindDiagnosticPanel(host, options) {
        if (!host || host.getAttribute('data-dx-bound') === '1') {
            return;
        }
        host.setAttribute('data-dx-bound', '1');

        function setOpen(button, open) {
            var target = document.getElementById(button.getAttribute('aria-controls'));
            if (!target) {
                return;
            }
            target.hidden = !open;
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function closeBoxes(panel) {
            panel.querySelectorAll('[data-dx-action="resolve-ask"], [data-dx-action="abandon-ask"]').forEach(function (b) {
                setOpen(b, false);
            });
        }

        function post(panel, action, body, button) {
            var box = button.closest('.dx-confirm, .esc-confirm');
            var error = box ? box.querySelector('.dx-error, .esc-error') : null;
            button.disabled = true;
            fetch(options.apiUrl + '/' + options.conversationId() + '/diagnostico/' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': options.csrf, 'Accept': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(body || {})
            }).then(function (resp) {
                if (resp.status === 401 && options.loginUrl) {
                    window.location.href = options.loginUrl;
                    return null;
                }
                return resp.json();
            }).then(function (res) {
                if (res && res.ok && res.diagnostic_html) {
                    if (dialog && dialog.open) {
                        dialog.close();
                    }
                    setPanelHtml(host, res.diagnostic_html);
                    var toggle = host.querySelector('.dx-toggle');
                    if (toggle) {
                        toggle.focus();
                    }
                    return;
                }
                if (res) {
                    button.disabled = false;
                    if (error) {
                        error.textContent = res.message || 'Não foi possível concluir a ação.';
                        error.hidden = false;
                    }
                }
            }).catch(function () {
                button.disabled = false;
                if (error) {
                    error.textContent = 'Falha de conexão. Tente novamente.';
                    error.hidden = false;
                }
            });
        }

        /* Modal de escalonamento (7.5): HTML do backend; nada é enviado para fora. */
        var dialog = null;
        var dialogOpener = null;

        function ensureDialog() {
            if (dialog) {
                return dialog;
            }
            dialog = document.createElement('dialog');
            dialog.className = 'dx-dialog';
            document.body.appendChild(dialog);
            dialog.addEventListener('close', function () {
                if (dialogOpener && document.body.contains(dialogOpener)) {
                    dialogOpener.focus();
                }
            });
            dialog.addEventListener('click', function (ev) {
                var b = ev.target.closest('[data-esc-action]');
                if (!b) {
                    return;
                }
                var act = b.getAttribute('data-esc-action');
                var root = dialog.querySelector('.esc');
                if (act === 'close') {
                    dialog.close();
                } else if (act === 'copy') {
                    var src = root.querySelector('[data-esc-text="' + b.getAttribute('data-esc-target') + '"]');
                    copyText(src ? src.value : '', root.querySelector('.esc-copied'));
                } else if (act === 'reopen') {
                    post(null, 'retomar', {}, b);
                } else if (act === 'confirm-ask' || act === 'confirm-cancel') {
                    var opener = root.querySelector('[data-esc-action="confirm-ask"]');
                    var boxEl = document.getElementById(opener.getAttribute('aria-controls'));
                    var show = act === 'confirm-ask' && boxEl.hidden;
                    boxEl.hidden = !show;
                    opener.setAttribute('aria-expanded', show ? 'true' : 'false');
                    (show ? boxEl.querySelector('select') : opener).focus();
                } else if (act === 'confirm') {
                    var reason = root.querySelector('[data-esc-field="reason"]');
                    var err = root.querySelector('.esc-error');
                    if (!reason.value) {
                        err.textContent = 'Selecione o motivo do escalonamento.';
                        err.hidden = false;
                        reason.focus();
                        return;
                    }
                    post(null, 'escalar', {
                        reason: reason.value,
                        external_reference: root.querySelector('[data-esc-field="reference"]').value,
                        confirm: '1'
                    }, b);
                }
            });
            return dialog;
        }

        function copyText(text, status) {
            var done = function () {
                if (status) {
                    status.textContent = 'Copiado para a área de transferência.';
                }
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text); done(); });
            } else {
                fallbackCopy(text);
                done();
            }
        }

        function fallbackCopy(text) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            var previous = document.activeElement;
            (dialog && dialog.open ? dialog : document.body).appendChild(ta);
            ta.focus();
            ta.select();
            try { document.execCommand('copy'); } catch (e) { /* sem suporte */ }
            ta.parentNode.removeChild(ta);
            if (previous && previous.focus) {
                previous.focus();
            }
        }

        function openEscalation(button) {
            button.disabled = true;
            fetch(options.apiUrl + '/' + options.conversationId() + '/diagnostico/escalonamento', {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            }).then(function (resp) { return resp.json(); }).then(function (res) {
                button.disabled = false;
                if (!res || !res.ok) {
                    return;
                }
                var d = ensureDialog();
                d.innerHTML = res.html;
                var title = d.querySelector('.esc-title');
                if (title) {
                    d.setAttribute('aria-labelledby', title.id);
                }
                dialogOpener = button;
                d.showModal();
                d.scrollTop = 0;
            }).catch(function () { button.disabled = false; });
        }

        host.addEventListener('click', function (ev) {
            var button = ev.target.closest('[data-dx-action]');
            if (!button || !host.contains(button)) {
                return;
            }
            var panel = button.closest('.dx-panel');
            var action = button.getAttribute('data-dx-action');

            if (action === 'toggle') {
                setOpen(button, button.getAttribute('aria-expanded') !== 'true');
            } else if (action === 'answer') {
                if (options.input) {
                    options.input.focus();
                    options.input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            } else if (action === 'resolve-ask' || action === 'abandon-ask') {
                var wasOpen = button.getAttribute('aria-expanded') === 'true';
                closeBoxes(panel);
                setOpen(button, !wasOpen);
                if (!wasOpen) {
                    var first = document.getElementById(button.getAttribute('aria-controls')).querySelector('input:checked, button');
                    if (first) {
                        first.focus();
                    }
                }
            } else if (action === 'cancel') {
                var boxId = button.closest('.dx-confirm').id;
                var opener = panel.querySelector('[aria-controls="' + boxId + '"]');
                closeBoxes(panel);
                if (opener) {
                    opener.focus();
                }
            } else if (action === 'escalation-open') {
                openEscalation(button);
            } else if (action === 'reopen') {
                post(panel, 'retomar', {}, button);
            } else if (action === 'resolve-confirm') {
                post(panel, 'resolver', {}, button);
            } else if (action === 'abandon-confirm') {
                var checked = button.closest('.dx-confirm').querySelector('input[type="radio"]:checked');
                post(panel, 'encerrar', { reason: checked ? checked.value : 'outro' }, button);
            }
        });
    }

    /* Substitui o painel pelo HTML do backend (fonte da verdade). */
    function setPanelHtml(host, html) {
        host.innerHTML = html || '';
        host.hidden = !html;
    }

    global.ChatUI = {
        bindDiagnosticPanel: bindDiagnosticPanel,
        setPanelHtml: setPanelHtml,
        el: el,
        renderMarkdown: renderMarkdown,
        buildSources: buildSources,
        focusSource: focusSource,
        trustedUrlsFrom: trustedUrlsFrom,
        isSafeSourceUrl: isSafeSourceUrl,
        autoGrow: autoGrow,
        scrollPageToBottom: scrollPageToBottom
    };
})(window);
