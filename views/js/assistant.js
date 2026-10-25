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

    var config = window.somcpAssistant;
    if (!config || !config.endpoint || document.getElementById('somcp-chat')) {
        return;
    }

    var text = config.text || {};
    var MAX_STEPS = 30;
    var state = { view: null, threads: [], assistants: [], busy: false, steps: 0 };
    var panel;
    var body;
    var input;
    var sendButton;
    var picker;
    var threadList;
    var foot;

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
        Object.keys(data || {}).forEach(function (key) {
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
                if (action === 'send' && state.view && state.view.error && data.text && input.value === '') {
                    input.value = data.text;
                }
                if (action !== 'step') {
                    state.steps = 0;
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
        node.innerHTML = markdown(message.text);
        return node;
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

        threadList.innerHTML = '';
        var fresh = el('button', 'somcp-chat-newthread', '+ ' + text.newThread);
        fresh.type = 'button';
        fresh.addEventListener('click', function () {
            threadList.hidden = true;
            run('new', { id_assistant: picker.value });
        });
        threadList.appendChild(fresh);
        state.threads.forEach(function (thread) {
            var item = el('button', view && view.thread_id === thread.thread_id ? 'somcp-current' : '', thread.title || thread.date);
            item.type = 'button';
            item.addEventListener('click', function () {
                threadList.hidden = true;
                run('load', { thread_id: thread.thread_id });
            });
            threadList.appendChild(item);
        });
        if (view) {
            var remove = el('button', '', '🗑 ' + text.deleteThread);
            remove.type = 'button';
            remove.addEventListener('click', function () {
                threadList.hidden = true;
                run('delete', { thread_id: view.thread_id });
            });
            threadList.appendChild(remove);
        }

        var spentNode = foot.firstChild;
        if (view) {
            spentNode.textContent = view.budget > 0
                ? text.spentOf.replace('%spent%', money(view.spent)).replace('%budget%', money(view.budget))
                : text.spent.replace('%spent%', money(view.spent));
        }
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
            threadList.hidden = !threadList.hidden;
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
        var close = el('button', '', '×');
        close.type = 'button';
        close.title = text.close;
        close.addEventListener('click', toggle);
        head.appendChild(menu);
        head.appendChild(who);
        head.appendChild(picker);
        head.appendChild(close);

        threadList = el('div', 'somcp-chat-threads');
        threadList.hidden = true;

        body = el('div', 'somcp-chat-body');

        var form = el('form', 'somcp-chat-input');
        input = el('textarea');
        input.rows = 1;
        input.placeholder = text.placeholder;
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                submit();
            }
        });
        sendButton = el('button', '', '➤');
        sendButton.type = 'submit';
        sendButton.title = text.send;
        form.appendChild(input);
        form.appendChild(sendButton);
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submit();
        });

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
        panel.appendChild(threadList);
        panel.appendChild(body);
        panel.appendChild(form);
        panel.appendChild(foot);
        document.body.appendChild(panel);
    }

    function submit() {
        var message = input.value.trim();
        if (!message || state.busy) {
            return;
        }
        input.value = '';
        state.view = state.view || { thread_id: '', id_assistant: Number(picker.value), messages: [], status: 'idle', actions: [], budget: 0, spent: 0 };
        state.view.messages.push({ role: 'user', text: message });
        var data = { text: message, id_assistant: picker.value };
        if (state.view.thread_id) {
            data.thread_id = state.view.thread_id;
        }
        run('send', data);
    }

    var loaded = false;

    function toggle() {
        if (!panel) {
            build();
        }
        panel.hidden = !panel.hidden;
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
            input.focus();
        }
    }

    // The button goes beside the other icons of the back office header; the
    // header differs between versions and between legacy and Symfony pages,
    // so it falls back to a floating button rather than to nothing.
    function placeButton() {
        var button = el('button', '', '✦');
        button.id = 'somcp-chat-button';
        button.type = 'button';
        button.title = text.open;
        button.setAttribute('aria-label', text.open);
        button.addEventListener('click', toggle);

        var anchors = ['#header-notifications-container', '#notification', '.notification-center', '#employee_infos', '#header-employee-container'];
        for (var i = 0; i < anchors.length; i += 1) {
            var anchor = document.querySelector(anchors[i]);
            if (anchor && anchor.parentNode) {
                var holder = el('div', 'component');
                holder.appendChild(button);
                anchor.parentNode.insertBefore(holder, anchor);
                return;
            }
        }

        button.className = 'somcp-floating';
        document.body.appendChild(button);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', placeButton);
    } else {
        placeButton();
    }
}());
