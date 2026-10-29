/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to obtain
 * it through the world-wide-web, please send an email to
 * contact@novalo.fr so we can send you a copy immediately.
 *
 * @author    Novalo <contact@novalo.fr>
 * @copyright 2026 Novalo
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License v3.0 (AFL-3.0)
 */

/*
 * The chat of the built-in assistant, on every back office page.
 *
 * The server makes at most one model call per request, so this page drives an
 * answer step by step: while the conversation says it is working, the next
 * step is asked for, until the answer is there or a change waits for the
 * employee's approval. Everything the model wrote is escaped before it is
 * drawn: it is text from a third party, not HTML of this module.
 */
(function () {
    'use strict';

    // The settings come escaped in an attribute of this script's own tag.
    var tag = document.getElementById('somcp-assistant-js');
    var config = null;
    try {
        config = JSON.parse((tag && tag.getAttribute('data-config')) || 'null');
    } catch (e) {
        config = null;
    }
    if (!config || !config.endpoint || document.getElementById('somcp-chat')) {
        return;
    }

    var text = config.text || {};
    var MAX_STEPS = 30;
    // A move still running on the server - from before a reload, or in another
    // tab - is waited for by asking again every BUSY_DELAY, up to MAX_WAITS
    // times: the server frees a conversation whose move died after 5 minutes.
    var BUSY_DELAY = 2000;
    var MAX_WAITS = 160;
    var state = { view: null, threads: [], assistants: [], busy: false, steps: 0, waits: 0 };
    var panel;
    var body;
    var input;
    var sendButton;
    var picker;
    var threadList;
    var foot;
    var fileInput;
    var fileList;
    var pending = [];

    function el(tag, className, content) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (content !== undefined) {
            node.textContent = content;
        }
        return node;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // A small part of Markdown, on text already escaped: what assistants use
    // in a short answer. Links are kept only when they are http or https.
    function markdown(value) {
        var lines = escapeHtml(value).split(/\r?\n/);
        var html = '';
        var list = false;

        lines.forEach(function (line) {
            var item = /^\s*(?:[-*]|\d+\.)\s+(.*)$/.exec(line);
            if (item) {
                if (!list) {
                    html += '<ul>';
                    list = true;
                }
                html += '<li>' + inline(item[1]) + '</li>';
                return;
            }
            if (list) {
                html += '</ul>';
                list = false;
            }
            if (line.trim() !== '') {
                html += '<p>' + inline(line) + '</p>';
            }
        });
        if (list) {
            html += '</ul>';
        }

        return html;
    }

    function inline(value) {
        return value
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    }

    function remember(threadId) {
        try {
            window.localStorage.setItem('somcp.assistant.thread', threadId || '');
        } catch (e) {
            // Not remembered: the chat opens on the latest conversation instead.
        }
    }

    function remembered() {
        try {
            return window.localStorage.getItem('somcp.assistant.thread') || '';
        } catch (e) {
            return '';
        }
    }

    function request(action, data) {
        var form = new FormData();
        form.append('somcp_action', action);
        form.append('page', config.page || '');
        Object.keys(data || {}).forEach(function (key) {
            if (key === 'files') {
                data.files.forEach(function (file) {
                    form.append('files[]', file, file.name);
                });
                return;
            }
            form.append(key, data[key]);
        });

        return fetch(config.endpoint, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok) {
                    throw new Error(json && json.error ? json.error : 'http_' + response.status);
                }
                return json;
            });
        });
    }

    function money(value) {
        return '$' + Number(value || 0).toFixed(2);
    }

    function absorb(json) {
        state.view = json.view;
        state.threads = json.threads || [];
        state.assistants = json.assistants || [];
        remember(state.view ? state.view.thread_id : '');
        render();
    }

    function run(action, data) {
        if (state.busy) {
            return Promise.resolve();
        }
        state.busy = true;
        render();

        return request(action, data)
            .then(function (json) {
                state.busy = false;
                absorb(json);
                // A message the assistant could not answer is not kept by the
                // server: it goes back into the field rather than being lost.
                if (action === 'send' && state.view && state.view.error) {
                    if (data.text && input.value === '') {
                        input.value = data.text;
                    }
                    if (data.files && data.files.length && !pending.length) {
                        pending = data.files;
                        drawPending();
                    }
                }
                if (action !== 'step') {
                    state.steps = 0;
                    state.waits = 0;
                }
                if (state.view && state.view.status === 'working' && state.view.busy && state.waits < MAX_WAITS) {
                    // Not a step of ours: the one running is waited for, and
                    // its answer comes with the next reply.
                    state.waits += 1;
                    var threadId = state.view.thread_id;
                    return new Promise(function (resolve) {
                        window.setTimeout(resolve, BUSY_DELAY);
                    }).then(function () {
                        return run('step', { thread_id: threadId });
                    });
                }
                if (state.view && state.view.status === 'working' && state.steps < MAX_STEPS) {
                    state.steps += 1;
                    return run('step', { thread_id: state.view.thread_id });
                }
                return null;
            })
            .catch(function () {
                state.busy = false;
                if (action === 'send' && data.text && input.value === '') {
                    input.value = data.text;
                }
                if (action === 'send' && data.files && data.files.length && !pending.length) {
                    pending = data.files;
                }
                render(text.failed);
            });
    }

    function threadData(extra) {
        var data = extra || {};
        if (state.view) {
            data.thread_id = state.view.thread_id;
        }
        return data;
    }

    function drawMessage(message) {
        // The oldest messages, summarized to keep the cost down: a quiet note
        // that unfolds to the summary, not a message of the employee.
        if (message.role === 'summary') {
            var note = el('details', 'somcp-summary');
            note.appendChild(el('summary', '', text.summarized));
            var gist = el('div', 'somcp-summary-text');
            gist.innerHTML = markdown(message.text || '');
            note.appendChild(gist);
            return note;
        }
        if (message.role === 'tools') {
            var wrap = document.createDocumentFragment();
            if (message.text) {
                var said = el('div', 'somcp-msg somcp-msg-assistant');
                said.innerHTML = markdown(message.text);
                wrap.appendChild(said);
            }
            (message.tools || []).forEach(function (tool) {
                var label = tool.title;
                if (tool.approval === 'approved') {
                    label += ' · ' + text.approved;
                } else if (tool.approval === 'rejected') {
                    label += ' · ' + text.refused;
                }
                wrap.appendChild(el('div', 'somcp-tool', '⚙ ' + label));
            });
            return wrap;
        }

        var node = el('div', 'somcp-msg ' + (message.role === 'user' ? 'somcp-msg-user' : 'somcp-msg-assistant'));
        node.innerHTML = markdown(message.text || '');
        (message.images || []).forEach(function (image) {
            node.appendChild(drawImage(image));
        });
        (message.files || []).forEach(function (name) {
            node.appendChild(el('div', 'somcp-msg-file', '📎 ' + name));
        });
        return node;
    }

    // An image a provider generated: inline bytes in the message, or a signed
    // link of the shop. Anything else is not drawn: the address is set as an
    // attribute, never written into HTML.
    function drawImage(image) {
        var src = String(image.src || '');
        var inline = /^data:image\/(png|jpeg|webp|gif);base64,[A-Za-z0-9+\/=]+$/.test(src);
        if (!inline && !/^https?:\/\//.test(src)) {
            return document.createDocumentFragment();
        }
        var picture = el('img', 'somcp-msg-image');
        picture.src = src;
        picture.alt = image.name || '';
        picture.loading = 'lazy';
        if (inline) {
            return picture;
        }
        var link = el('a');
        link.href = src;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.appendChild(picture);
        return link;
    }

    function drawApproval(actions) {
        var card = el('div', 'somcp-approval');
        card.appendChild(el('b', '', '⚠ ' + text.approvalTitle));
        var list = el('ul');
        actions.forEach(function (action) {
            var item = el('li', '', action.title);
            var inputs = action.inputs || {};
            if (Object.keys(inputs).length) {
                item.appendChild(el('pre', '', JSON.stringify(inputs, null, 2)));
            }
            list.appendChild(item);
        });
        card.appendChild(list);

        var decide = function (verdict) {
            var decisions = {};
            actions.forEach(function (action) {
                decisions[action.id] = verdict;
            });
            run('decide', threadData({ decisions: JSON.stringify(decisions) }));
        };
        var approve = el('button', 'somcp-approve', text.approve);
        approve.type = 'button';
        approve.disabled = state.busy;
        approve.addEventListener('click', function () {
            decide('approve');
        });
        var refuse = el('button', 'somcp-refuse', text.refuse);
        refuse.type = 'button';
        refuse.disabled = state.busy;
        refuse.addEventListener('click', function () {
            decide('reject');
        });
        card.appendChild(approve);
        card.appendChild(refuse);

        return card;
    }

    function render(error) {
        if (!panel) {
            return;
        }
        var view = state.view;

        body.innerHTML = '';
        if (!view || !view.messages.length) {
            body.appendChild(drawMessage({ role: 'assistant', text: text.hello }));
        }
        if (view) {
            view.messages.forEach(function (message) {
                body.appendChild(drawMessage(message));
            });
            if (view.status === 'approval' && view.actions.length) {
                body.appendChild(drawApproval(view.actions));
            }
        }
        if (state.busy || (view && view.status === 'working')) {
            body.appendChild(el('div', 'somcp-typing', text.working));
        }
        var problem = error || (view && view.error);
        if (problem) {
            body.appendChild(el('div', 'somcp-error', problem));
        }
        body.scrollTop = body.scrollHeight;

        var waiting = view && view.status === 'approval';
        input.disabled = state.busy || waiting;
        sendButton.disabled = state.busy || waiting;
        drawPending();

        picker.innerHTML = '';
        var automatic = el('option', '', text.defaultAssistant);
        automatic.value = '0';
        picker.appendChild(automatic);
        state.assistants.forEach(function (assistant) {
            var option = el('option', '', assistant.label);
            option.value = String(assistant.id);
            picker.appendChild(option);
        });
        picker.value = view ? String(view.id_assistant) : '0';
        if (picker.value === '') {
            picker.value = '0';
        }

        drawThreads();

        var spentNode = foot.firstChild;
        if (view) {
            spentNode.textContent = view.budget > 0
                ? text.spentOf.replace('%spent%', money(view.spent)).replace('%budget%', money(view.budget))
                : text.spent.replace('%spent%', money(view.spent));
        }
    }

    // The conversations, in a bar sliding in from the left of the chat: the
    // new one on top, then each conversation with its own bin.
    function drawThreads() {
        var view = state.view;
        threadList.innerHTML = '';
        var fresh = el('button', 'somcp-chat-newthread', '+ ' + text.newThread);
        fresh.type = 'button';
        fresh.addEventListener('click', function () {
            closeThreads();
            run('new', { id_assistant: picker.value });
        });
        threadList.appendChild(fresh);
        var title = el('div', 'somcp-chat-threads-title', text.threads);
        threadList.appendChild(title);
        state.threads.forEach(function (thread) {
            var row = el('div', 'somcp-thread' + (view && view.thread_id === thread.thread_id ? ' somcp-current' : ''));
            var open = el('button', 'somcp-thread-open', thread.title || thread.date);
            open.type = 'button';
            open.title = thread.title || thread.date;
            open.addEventListener('click', function () {
                closeThreads();
                run('load', { thread_id: thread.thread_id });
            });
            var remove = el('button', 'somcp-thread-delete');
            remove.type = 'button';
            remove.title = text.deleteThread;
            remove.setAttribute('aria-label', text.deleteThread);
            remove.appendChild(el('i', 'material-icons', 'delete'));
            remove.addEventListener('click', function () {
                if (window.confirm(text.deleteThread + ' ?')) {
                    run('delete', { thread_id: thread.thread_id });
                }
            });
            row.appendChild(open);
            row.appendChild(remove);
            threadList.appendChild(row);
        });
    }

    function openThreads() {
        panel.classList.add('somcp-threads-open');
    }

    function closeThreads() {
        panel.classList.remove('somcp-threads-open');
    }

    function build() {
        panel = el('div');
        panel.id = 'somcp-chat';
        panel.hidden = true;
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', text.title);

        var head = el('div', 'somcp-chat-head');
        var menu = el('button', '', '☰');
        menu.type = 'button';
        menu.title = text.threads;
        menu.addEventListener('click', function () {
            if (panel.classList.contains('somcp-threads-open')) {
                closeThreads();
            } else {
                openThreads();
            }
        });
        var who = el('div', 'somcp-chat-who');
        who.appendChild(el('b', '', text.title));
        who.appendChild(el('small', '', text.subtitle));
        picker = el('select');
        picker.addEventListener('change', function () {
            if (state.view) {
                run('assistant', threadData({ id_assistant: picker.value }));
            }
        });
        var widen = el('button', 'somcp-widen', '⤢');
        widen.type = 'button';
        widen.title = text.enlarge;
        widen.setAttribute('aria-label', text.enlarge);
        widen.addEventListener('click', function () {
            var wide = !panel.classList.contains('somcp-chat-wide');
            panel.classList.toggle('somcp-chat-wide', wide);
            widen.textContent = wide ? '⤡' : '⤢';
            widen.title = wide ? text.reduce : text.enlarge;
            try {
                window.localStorage.setItem('somcp.assistant.wide', wide ? '1' : '');
            } catch (e) {
                // Not remembered; the next page opens it at its usual size.
            }
        });
        var settings = el('button', '', '⚙');
        settings.type = 'button';
        settings.title = text.settings;
        settings.setAttribute('aria-label', text.settings);
        settings.addEventListener('click', function () {
            if (config.settingsUrl) {
                window.open(config.settingsUrl, '_blank', 'noopener');
            }
        });
        var close = el('button', '', '×');
        close.type = 'button';
        close.title = text.close;
        close.addEventListener('click', toggle);
        head.appendChild(menu);
        head.appendChild(who);
        head.appendChild(picker);
        head.appendChild(settings);
        head.appendChild(widen);
        head.appendChild(close);
        try {
            if (window.localStorage.getItem('somcp.assistant.wide') === '1') {
                panel.classList.add('somcp-chat-wide');
                widen.textContent = '⤡';
                widen.title = text.reduce;
            }
        } catch (e) {
            // Storage refused: the usual size.
        }

        threadList = el('div', 'somcp-chat-threads');
        var shade = el('div', 'somcp-chat-shade');
        shade.addEventListener('click', closeThreads);

        body = el('div', 'somcp-chat-body');

        fileList = el('div', 'somcp-chat-files');
        fileInput = el('input');
        fileInput.type = 'file';
        fileInput.multiple = true;
        fileInput.accept = 'image/jpeg,image/png,image/gif,image/webp,application/pdf,.txt,.csv,.json,.xml';
        fileInput.hidden = true;
        fileInput.addEventListener('change', function () {
            Array.prototype.forEach.call(fileInput.files, function (file) {
                if (pending.length < config.maxFiles) {
                    pending.push(file);
                }
            });
            fileInput.value = '';
            drawPending();
        });

        // One rounded box holding the field, the files waiting and a row of
        // actions under them, the way the chat apps people know lay it out.
        var form = el('form', 'somcp-composer');
        input = el('textarea');
        input.rows = 1;
        input.placeholder = text.placeholder;
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                submit();
            }
        });
        input.addEventListener('input', grow);
        var actions = el('div', 'somcp-composer-actions');
        var attach = el('button', 'somcp-attach', '+');
        attach.type = 'button';
        attach.title = text.attach;
        attach.setAttribute('aria-label', text.attach);
        attach.addEventListener('click', function () {
            fileInput.click();
        });
        sendButton = el('button', 'somcp-send', '↑');
        sendButton.type = 'submit';
        sendButton.title = text.send;
        sendButton.setAttribute('aria-label', text.send);
        actions.appendChild(attach);
        actions.appendChild(fileInput);
        actions.appendChild(sendButton);
        form.appendChild(fileList);
        form.appendChild(input);
        form.appendChild(actions);
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submit();
        });
        var warning = el('div', 'somcp-chat-warning', text.mistakes);

        foot = el('div', 'somcp-chat-foot');
        foot.appendChild(el('span', '', ''));
        var credit = el('span', '', text.poweredBy + ' ');
        var link = el('a', '', 'Neuron AI');
        link.href = config.neuron;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        credit.appendChild(link);
        foot.appendChild(credit);

        panel.appendChild(head);
        panel.appendChild(shade);
        panel.appendChild(threadList);
        panel.appendChild(body);
        panel.appendChild(form);
        panel.appendChild(warning);
        panel.appendChild(foot);
        document.body.appendChild(panel);
    }

    // The field grows with what is typed, up to a few lines, then scrolls.
    function grow() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 200) + 'px';
    }

    // The files chosen for the next message, each with a cross to take it
    // back before sending.
    function drawPending() {
        if (!fileList) {
            return;
        }
        fileList.innerHTML = '';
        pending.forEach(function (file, index) {
            var chip = el('span', 'somcp-file-chip', '📎 ' + file.name + ' ');
            var remove = el('button', '', '×');
            remove.type = 'button';
            remove.title = text.removeFile;
            remove.addEventListener('click', function () {
                pending.splice(index, 1);
                drawPending();
            });
            chip.appendChild(remove);
            fileList.appendChild(chip);
        });
        fileList.hidden = pending.length === 0;
    }

    function submit() {
        var message = input.value.trim();
        if ((!message && !pending.length) || state.busy) {
            return;
        }
        var tooBig = pending.filter(function (file) {
            return file.size > config.maxFileBytes;
        });
        if (tooBig.length) {
            render(text.fileTooBig + ' ' + tooBig[0].name);
            return;
        }
        var files = pending;
        pending = [];
        input.value = '';
        grow();
        state.view = state.view || { thread_id: '', id_assistant: Number(picker.value), messages: [], status: 'idle', actions: [], budget: 0, spent: 0 };
        state.view.messages.push({ role: 'user', text: message, files: files.map(function (file) { return file.name; }) });
        var data = { text: message, id_assistant: picker.value, files: files };
        if (state.view.thread_id) {
            data.thread_id = state.view.thread_id;
        }
        run('send', data);
    }

    var loaded = false;

    // Whether the chat was open, kept per browser tab: going to another page
    // of the back office opens it again there, and a new tab starts closed.
    function rememberOpen(open) {
        try {
            window.sessionStorage.setItem('somcp.assistant.open', open ? '1' : '');
        } catch (e) {
            // Not remembered: the chat starts closed on the next page.
        }
    }

    function wasOpen() {
        try {
            return window.sessionStorage.getItem('somcp.assistant.open') === '1';
        } catch (e) {
            return false;
        }
    }

    function toggle(event, restoring) {
        if (!panel) {
            build();
        }
        panel.hidden = !panel.hidden;
        rememberOpen(!panel.hidden);
        if (!panel.hidden) {
            if (!loaded) {
                loaded = true;
                var last = remembered();
                run('load', last ? { thread_id: last } : {}).then(function () {
                    // A remembered conversation that was deleted elsewhere: start
                    // from the latest one instead of an error.
                    if (!state.view && last) {
                        remember('');
                        run('load', {});
                    }
                });
            }
            // Reopened by itself on a new page: the employee may be about to
            // type in that page, so the focus is not taken from it.
            if (!restoring) {
                input.focus();
            }
        }
    }

    // The button is printed by the server through the displayBackOfficeTop
    // hook, at the end of the header bar. It is moved right after "View my
    // shop", or after the shop list that replaces it on a multistore shop.
    // When a page shows neither, it goes just before the notification bell,
    // which is where they would have been; with no bell either, it stays
    // where the hook put it. A page that prints no header gets a floating
    // button.
    function placeButton() {
        var button = document.getElementById('somcp-chat-button');
        if (button) {
            var holder = document.getElementById('somcp-chat-component');
            var shop = document.getElementById('header_shopname');
            // The legacy pages hold the link or the list in #header-list, the
            // Symfony pages in .shop-list - wrapped in #header-shop-list-container
            // on some of them, directly in the header on others.
            var after = document.querySelector('#header-shop-list-container, #header-list');
            if (!after) {
                var list = document.querySelector('#header_shopname, #shop-list');
                after = list ? (list.closest('.shop-list') || list) : null;
            }
            var before = after ? null : document.querySelector('#header-notifications-container, #notification');
            if (before && before.id === 'notification') {
                before = before.closest('.header-list') || before;
            }
            if (holder && after && after.parentNode && !after.contains(holder)) {
                after.parentNode.insertBefore(holder, after.nextSibling);
            } else if (holder && before && before.parentNode) {
                before.parentNode.insertBefore(holder, before);
            }
            // The header colours differ between PrestaShop versions and pages,
            // so the button takes the colour of the link it sits beside - or of
            // the bell when it sits before it - rather than one written here.
            var model = shop || document.querySelector('#shop-list > .btn, #header_shop, #header-notifications-container .notification, #notification .notification');
            if (holder && model && window.getComputedStyle) {
                holder.style.setProperty('--somcp-header-color', window.getComputedStyle(model).color);
            }
            button.addEventListener('click', function (event) {
                event.preventDefault();
                toggle();
            });
            if (wasOpen()) {
                toggle(null, true);
            }
            return;
        }

        button = el('button', 'somcp-floating');
        button.id = 'somcp-chat-button';
        button.type = 'button';
        button.title = text.open;
        button.setAttribute('aria-label', text.open);
        button.appendChild(el('i', 'material-icons', 'forum'));
        button.addEventListener('click', toggle);
        document.body.appendChild(button);
        if (wasOpen()) {
            toggle(null, true);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', placeButton);
    } else {
        placeButton();
    }
}());
