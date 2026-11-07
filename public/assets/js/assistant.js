// Floating AI chat (ADR-028). The server makes one Claude call per request; while the answer is
// "working" (Claude is reading data with tools) this script asks for the next step.
// Answers are rendered by assistant-format.js (escaped Markdown, tables, charts); each answer can be
// copied or downloaded as Markdown, tables as CSV and charts as SVG.

const root = document.querySelector('[data-assistant]');

if (root) {
    const { renderMarkdown, escapeHtml, plainCell, toCsv, toTsv, chartRows } = await import('./assistant-format.js' + new URL(import.meta.url).search);
    const panel = root.querySelector('.assistant-panel');
    const log = root.querySelector('[data-assistant-log]');
    const empty = root.querySelector('[data-assistant-empty]');
    const library = root.querySelector('[data-assistant-library]');
    const libraryToggle = root.querySelector('[data-assistant-library-toggle]');
    const form = root.querySelector('[data-assistant-form]');
    const input = root.querySelector('[data-assistant-input]');
    const confirmBox = root.querySelector('[data-assistant-confirm]');
    const actionText = root.querySelector('[data-assistant-action]');
    const launcher = root.querySelector('.assistant-launcher');
    const expand = root.querySelector('[data-assistant-expand]');
    const exportButton = root.querySelector('[data-assistant-export]');
    const storageKey = 'scc-assistant-open-' + root.dataset.workspace;
    const wideKey = 'scc-assistant-wide';
    const MAX_STEPS = 8;

    let conversation = null;
    let busy = false;
    let loaded = false;
    let transcript = [];

    const stored = (key) => {
        try {
            return localStorage.getItem(key);
        } catch {
            return null;
        }
    };
    const store = (key, value) => {
        try {
            localStorage.setItem(key, value);
        } catch {
            // Storage disabled: the preference only lasts for this page.
        }
    };

    const request = async (method, path, data = {}) => {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 75000);
        const options = { method, headers: { 'X-CSRF-Token': root.dataset.csrf, Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal };
        if (method === 'POST') {
            options.body = new URLSearchParams({ ...data, page: location.pathname });
        }
        try {
            const response = await fetch(path, options);
            const reply = await response.json();
            if (!reply || typeof reply !== 'object' || Array.isArray(reply) || !['done', 'working', 'confirm', 'error', 'incomplete', 'paused'].includes(reply.status)
                || ['error', 'conversation', 'action'].some((field) => reply[field] != null && typeof reply[field] !== 'string')
                || (reply.messages !== undefined && (!Array.isArray(reply.messages) || !reply.messages.every((message) => message && typeof message === 'object' && ['user', 'assistant', 'tool', 'file'].includes(message.role) && typeof message.text === 'string' && (message.role !== 'file' || typeof message.url === 'string'))))) {
                throw new Error('Unexpected assistant response');
            }
            if (response.status === 429) {
                reply.retry_after = Math.max(Number(reply.retry_after) || 0, Number(response.headers.get('Retry-After')) || 60);
            }
            return reply;
        } catch {
            return { status: 'error', error: 'The response was delayed or the connection failed. Refresh the conversation before continuing; the request may already have completed.', refresh: true };
        } finally {
            clearTimeout(timer);
        }
    };

    // ---- Copy and download helpers -------------------------------------------------------------

    const stamp = () => {
        const d = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + '-' + pad(d.getHours()) + pad(d.getMinutes());
    };
    const download = (name, type, content) => {
        const url = URL.createObjectURL(new Blob([content], { type }));
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        link.hidden = true;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 2000);
    };
    const copy = async (text) => {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            const area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.className = 'visually-hidden';
            document.body.append(area);
            area.select();
            const ok = document.execCommand('copy');
            area.remove();
            return ok;
        }
    };
    const acknowledge = (button, ok, done = 'Copied') => {
        const label = button.dataset.label || button.textContent;
        button.dataset.label = label;
        button.textContent = ok ? done : 'Copy failed';
        setTimeout(() => {
            button.textContent = label;
        }, 1600);
    };
    const tableRowsOf = (block) => [...block.querySelectorAll('tr')].map((tr) => [...tr.children].map((cell) => plainCell(cell.textContent)));

    // SVG download: computed colours and fonts are written into a copy, so the file looks the same outside the app.
    const standaloneSvg = (svg) => {
        const copyNode = svg.cloneNode(true);
        const source = [svg, ...svg.querySelectorAll('*')];
        const target = [copyNode, ...copyNode.querySelectorAll('*')];
        source.forEach((node, index) => {
            const style = getComputedStyle(node);
            const into = target[index];
            for (const property of ['fill', 'stroke', 'stroke-width', 'stroke-dasharray', 'font-family', 'font-size', 'font-weight', 'opacity']) {
                const value = style.getPropertyValue(property);
                if (value && value !== 'normal') {
                    into.setAttribute(property, value);
                }
            }
        });
        copyNode.removeAttribute('class');
        const background = getComputedStyle(panel).backgroundColor;
        const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        rect.setAttribute('width', '100%');
        rect.setAttribute('height', '100%');
        rect.setAttribute('fill', background);
        copyNode.insertBefore(rect, copyNode.firstChild.nextSibling);
        return '<?xml version="1.0" encoding="UTF-8"?>\n' + new XMLSerializer().serializeToString(copyNode);
    };

    const slug = (text) => text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40) || 'answer';

    log.addEventListener('click', async (event) => {
        const button = event.target.closest('button');
        if (!button || !log.contains(button)) {
            return;
        }
        const message = button.closest('.assistant-assistant');
        const table = button.closest('[data-assistant-table]');
        const chart = button.closest('[data-assistant-chart]');
        if (button.matches('[data-assistant-copy-answer]') && message) {
            acknowledge(button, await copy(message.dataset.markdown || ''));
        } else if (button.matches('[data-assistant-download-answer]') && message) {
            const first = (message.dataset.markdown || '').split('\n').find((line) => line.trim() !== '') || '';
            download('seocc-' + slug(first.replace(/^#+\s*/, '')) + '-' + stamp() + '.md', 'text/markdown;charset=utf-8', message.dataset.markdown || '');
        } else if (button.matches('[data-assistant-copy-table]') && table) {
            acknowledge(button, await copy(toTsv(tableRowsOf(table))));
        } else if (button.matches('[data-assistant-csv]') && table) {
            download('seocc-table-' + stamp() + '.csv', 'text/csv;charset=utf-8', '﻿' + toCsv(tableRowsOf(table)));
        } else if (button.matches('[data-assistant-chart-copy]') && chart) {
            acknowledge(button, await copy(toTsv(chartRows(JSON.parse(chart.dataset.assistantChart)))));
        } else if (button.matches('[data-assistant-chart-svg]') && chart) {
            const spec = JSON.parse(chart.dataset.assistantChart);
            download('seocc-' + slug(spec.title || 'chart') + '-' + stamp() + '.svg', 'image/svg+xml;charset=utf-8', standaloneSvg(chart.querySelector('svg')));
        }
    });

    // ---- Rendering -----------------------------------------------------------------------------

    const bubble = (role, html, extraClass = '') => {
        const node = document.createElement('div');
        node.className = 'assistant-message assistant-' + role + (extraClass ? ' ' + extraClass : '');
        node.innerHTML = html;
        log.append(node);
        return node;
    };

    const answer = (text) => {
        const node = bubble('assistant', '<div class="assistant-answer">' + renderMarkdown(text) + '</div>'
            + '<div class="assistant-answer-tools"><button type="button" data-assistant-copy-answer>Copy</button><button type="button" data-assistant-download-answer>Download .md</button></div>');
        node.dataset.markdown = text;
        return node;
    };

    const render = (reply) => {
        if (reply.conversation) {
            conversation = reply.conversation;
        }
        // Without a transcript (network or server failure) the messages on screen stay as they are.
        for (const node of log.querySelectorAll(reply.messages ? '.assistant-message' : '.assistant-error')) {
            node.remove();
        }
        const messages = reply.messages || [];
        if (reply.messages) {
            transcript = messages;
            empty.hidden = messages.length > 0;
            exportButton.disabled = !messages.some((message) => message.role === 'assistant');
        }
        let tools = null;
        for (const message of messages) {
            if (message.role === 'tool') {
                if (!tools) {
                    tools = bubble('tool', '');
                    tools.dataset.labels = '';
                }
                const labels = new Set(tools.dataset.labels ? tools.dataset.labels.split('|') : []);
                labels.add(message.text);
                tools.dataset.labels = [...labels].join('|');
                tools.textContent = 'Read: ' + [...labels].join(', ');
                continue;
            }
            tools = null;
            if (message.role === 'file' && typeof message.url === 'string' && message.url.startsWith('/assistant/plans/')) {
                // A fix plan saved by the assistant: a download link (same origin only).
                const node = bubble('file', '');
                const link = document.createElement('a');
                link.href = message.url;
                link.className = 'assistant-download';
                link.textContent = 'Download fix plan: ' + message.text;
                node.append(link);
                continue;
            }
            if (message.role === 'assistant') {
                answer(message.text);
            } else {
                bubble(message.role, '<p>' + escapeHtml(message.text).replace(/\n/g, '<br>') + '</p>');
            }
        }
        confirmBox.hidden = reply.status !== 'confirm' || !reply.action;
        if (!confirmBox.hidden) {
            actionText.textContent = reply.action + '?';
        }
        if ((reply.status === 'error' || reply.status === 'paused') && reply.error) {
            const node = bubble('error', '<p>' + escapeHtml(reply.error) + '</p>');
            if (reply.refresh) {
                const refresh = document.createElement('button');
                refresh.type = 'button';
                refresh.className = 'button button-small button-ghost';
                refresh.textContent = 'Refresh conversation';
                refresh.addEventListener('click', () => run(() => request('GET', '/assistant')));
                node.append(refresh);
            } else if (conversation && reply.retryable === true) {
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'button button-small button-ghost';
                retry.textContent = 'Try again';
                const readyAt = Date.now() + Math.max(0, Number(reply.retry_after) || 0) * 1000;
                if (readyAt > Date.now()) {
                    retry.disabled = true;
                    const tick = () => {
                        if (!retry.isConnected) return;
                        const seconds = Math.max(0, Math.ceil((readyAt - Date.now()) / 1000));
                        retry.disabled = seconds > 0;
                        retry.textContent = seconds ? 'Try again in ' + seconds + 's' : 'Try again';
                        if (seconds) setTimeout(tick, 1000);
                    };
                    setTimeout(tick, 0);
                }
                retry.addEventListener('click', () => run(() => request('POST', '/assistant/continue', { conversation })));
                node.append(retry);
            }
        }
        // Keep the start of a new answer in view rather than jumping to the end of a long table.
        const last = log.querySelector('.assistant-message:last-of-type');
        if (last && last.classList.contains('assistant-assistant') && last.offsetHeight > log.clientHeight) {
            log.scrollTop = last.offsetTop - log.offsetTop - 8;
        } else {
            log.scrollTop = log.scrollHeight;
        }
    };

    const thinking = (on) => {
        log.querySelector('.assistant-thinking')?.remove();
        if (on) {
            bubble('thinking', '<span></span><span></span><span></span>', 'assistant-thinking').setAttribute('aria-label', 'The assistant is working');
            log.scrollTop = log.scrollHeight;
        }
    };

    const setBusy = (on) => {
        busy = on;
        input.disabled = on;
        form.querySelector('button').disabled = on;
        root.querySelector('[data-assistant-new]').disabled = on;
        thinking(on);
    };

    // Runs one request and follows "working" answers until Claude is done or needs a confirmation.
    const run = async (first) => {
        if (busy) {
            return;
        }
        setBusy(true);
        let reply;
        try {
            reply = await first();
            for (let step = 0; reply.status === 'working' && step < MAX_STEPS; step++) {
                render(reply);
                thinking(true);
                reply = await request('POST', '/assistant/continue', { conversation });
            }
        } finally {
            setBusy(false);
        }
        render(reply);
        if (reply.status !== 'confirm') {
            input.focus();
        }
    };

    // ---- Panel ---------------------------------------------------------------------------------

    const open = async (focus = true) => {
        panel.hidden = false;
        launcher.setAttribute('aria-expanded', 'true');
        root.classList.add('is-open');
        store(storageKey, '1');
        if (!loaded) {
            loaded = true;
            setBusy(true);
            const reply = await request('GET', '/assistant');
            setBusy(false);
            render(reply);
        }
        if (focus) {
            input.focus();
        }
    };
    const close = () => {
        panel.hidden = true;
        launcher.setAttribute('aria-expanded', 'false');
        root.classList.remove('is-open');
        store(storageKey, '0');
        launcher.focus();
    };

    for (const toggle of root.querySelectorAll('[data-assistant-toggle]')) {
        toggle.addEventListener('click', () => (panel.hidden ? open() : close()));
    }

    const setWide = (wide) => {
        root.classList.toggle('is-wide', wide);
        expand.setAttribute('aria-pressed', wide ? 'true' : 'false');
        expand.setAttribute('aria-label', wide ? 'Make the assistant narrower' : 'Make the assistant wider');
        expand.title = wide ? 'Narrower panel' : 'Wider panel for tables and charts';
        store(wideKey, wide ? '1' : '0');
    };
    setWide(stored(wideKey) === '1');
    expand.addEventListener('click', () => setWide(!root.classList.contains('is-wide')));

    exportButton.disabled = true;
    exportButton.addEventListener('click', () => {
        const parts = ['# AI assistant conversation', '', 'Exported from SEO Control Center on ' + new Date().toLocaleString() + '. Answers are based on stored data; check important numbers.', ''];
        for (const message of transcript) {
            if (message.role === 'user') {
                parts.push('## Question', '', message.text, '');
            } else if (message.role === 'assistant') {
                parts.push('## Answer', '', message.text, '');
            } else if (message.role === 'file') {
                parts.push('Fix plan: ' + message.text + ' (download in the app)', '');
            }
        }
        download('seocc-conversation-' + stamp() + '.md', 'text/markdown;charset=utf-8', parts.join('\n'));
    });

    // ---- Prompt library ------------------------------------------------------------------------

    const tabs = [...library.querySelectorAll('[role="tab"]')];
    const selectTab = (tab, focus = false) => {
        for (const other of tabs) {
            const selected = other === tab;
            other.setAttribute('aria-selected', selected ? 'true' : 'false');
            other.tabIndex = selected ? 0 : -1;
            library.querySelector('#' + other.getAttribute('aria-controls')).hidden = !selected;
        }
        if (focus) {
            tab.focus();
        }
    };
    for (const tab of tabs) {
        tab.addEventListener('click', () => selectTab(tab));
        tab.addEventListener('keydown', (event) => {
            const index = tabs.indexOf(tab);
            const next = { ArrowRight: index + 1, ArrowDown: index + 1, ArrowLeft: index - 1, ArrowUp: index - 1, Home: 0, End: tabs.length - 1 }[event.key];
            if (next !== undefined) {
                event.preventDefault();
                selectTab(tabs[(next + tabs.length) % tabs.length], true);
            }
        });
    }
    const showLibrary = (show) => {
        library.classList.toggle('is-floating', show && !empty.contains(library));
        if (!empty.contains(library)) {
            library.hidden = !show;
        }
        libraryToggle.setAttribute('aria-expanded', show ? 'true' : 'false');
    };
    libraryToggle.addEventListener('click', () => {
        if (!empty.hidden) {
            library.querySelector('[aria-selected="true"]')?.focus();
            return;
        }
        // With a conversation on screen, the library opens above the input.
        if (empty.contains(library)) {
            library.hidden = true;
            confirmBox.before(library);
        }
        showLibrary(library.hidden);
        if (!library.hidden) {
            library.querySelector('[aria-selected="true"]')?.focus();
        }
    });
    // Buttons elsewhere on the page (data-ask-ai) open Ask AI with a prepared question; the user sends it.
    document.addEventListener('click', async (event) => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-ask-ai]') : null;
        if (!trigger) {
            return;
        }
        await open(false);
        input.value = trigger.getAttribute('data-ask-ai') || '';
        input.dispatchEvent(new Event('input'));
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
    });
    for (const prompt of library.querySelectorAll('[data-assistant-prompt]')) {
        prompt.addEventListener('click', () => {
            input.value = prompt.dataset.assistantPrompt || '';
            input.dispatchEvent(new Event('input'));
            if (!empty.contains(library)) {
                showLibrary(false);
            }
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || panel.hidden) {
            return;
        }
        if (!library.hidden && library.classList.contains('is-floating')) {
            showLibrary(false);
            libraryToggle.focus();
            return;
        }
        close();
    });

    const send = (text) => {
        const message = text.trim();
        if (message === '' || busy) {
            return;
        }
        input.value = '';
        input.style.height = '';
        empty.hidden = true;
        if (!empty.contains(library)) {
            showLibrary(false);
        }
        bubble('user', '<p>' + escapeHtml(message).replace(/\n/g, '<br>') + '</p>');
        confirmBox.hidden = true;
        run(() => request('POST', '/assistant/messages', { message }));
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        send(input.value);
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            send(input.value);
        }
    });
    // Collapse first so scrollHeight reflects the text, not the current box; CSS max-height caps growth.
    const fitInput = () => {
        input.style.height = 'auto';
        input.style.height = input.scrollHeight + 2 + 'px';
    };
    input.addEventListener('input', fitInput);
    root.querySelector('[data-assistant-new]').addEventListener('click', () => {
        // A new chat shows the library again in the empty state.
        if (!empty.contains(library)) {
            empty.append(library);
            library.hidden = false;
            library.classList.remove('is-floating');
            libraryToggle.setAttribute('aria-expanded', 'false');
        }
        run(() => request('POST', '/assistant/new'));
    });
    root.querySelector('[data-assistant-approve]').addEventListener('click', () => {
        confirmBox.hidden = true;
        run(() => request('POST', '/assistant/confirm', { conversation, approve: '1' }));
    });
    root.querySelector('[data-assistant-decline]').addEventListener('click', () => {
        confirmBox.hidden = true;
        run(() => request('POST', '/assistant/confirm', { conversation, approve: '0' }));
    });

    // Actions offered only while no assistant is available (e.g. "Create content brief" on opportunities).
    for (const fallback of document.querySelectorAll('[data-assistant-fallback]')) {
        fallback.hidden = true;
    }

    // "Ask AI" buttons elsewhere on the page (e.g. on each opportunity) open the chat with a prepared question.
    for (const ask of document.querySelectorAll('[data-assistant-ask]')) {
        ask.hidden = false;
        ask.addEventListener('click', async () => {
            await open(false);
            send(ask.dataset.assistantAsk || '');
        });
    }

    if (stored(storageKey) === '1') {
        open(false);
    }
}
