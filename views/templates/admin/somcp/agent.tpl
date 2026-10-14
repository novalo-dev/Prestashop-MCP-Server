{*
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
 *}

{* Styles and script inline, for the reason given in _copy.tpl: one file that
   works the same on PrestaShop 8 and 9. *}
<style type="text/css">
.somcp-agent .somcp-agent-list {
    max-height: calc(100vh - 300px);
    overflow-y: auto;
    margin-bottom: 0;
}

.somcp-agent .somcp-agent-list .list-group-item {
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}

.somcp-agent .somcp-agent-list .somcp-agent-title {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.somcp-agent .somcp-agent-list .somcp-agent-drop {
    border: 0;
    background: none;
    color: #98a3b0;
    padding: 0 2px;
}

.somcp-agent .somcp-agent-list .somcp-agent-drop:hover {
    color: #c0392b;
}

.somcp-agent .somcp-agent-thread {
    height: calc(100vh - 380px);
    min-height: 260px;
    overflow-y: auto;
    padding: 4px 2px 12px;
}

.somcp-agent .somcp-agent-empty {
    color: #6c868e;
    text-align: center;
    margin-top: 60px;
}

.somcp-agent .somcp-agent-msg {
    margin: 10px 0;
    padding: 10px 14px;
    border-radius: 6px;
    max-width: 85%;
    line-height: 1.5;
    word-wrap: break-word;
}

.somcp-agent .somcp-agent-msg-user {
    background: #e8f6f9;
    margin-left: auto;
    white-space: pre-wrap;
}

.somcp-agent .somcp-agent-msg-assistant {
    background: #f8f9fa;
    border: 1px solid #eef1f4;
}

.somcp-agent .somcp-agent-who {
    display: block;
    font-size: 11px;
    color: #6c868e;
    margin-bottom: 4px;
}

.somcp-agent .somcp-agent-msg pre {
    white-space: pre-wrap;
    font-size: 12px;
}

.somcp-agent .somcp-agent-tool {
    margin: 6px 0;
    font-size: 12px;
    color: #6c868e;
}

.somcp-agent .somcp-agent-tool pre {
    margin: 6px 0 0;
    font-size: 11px;
    max-height: 240px;
    overflow: auto;
}

.somcp-agent .somcp-agent-pending {
    padding: 10px 14px;
    border: 1px solid #fbc02d;
    background: #fffbea;
    border-radius: 6px;
    color: #363a41;
}

.somcp-agent .somcp-agent-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: 8px;
}

.somcp-agent .somcp-agent-compose {
    display: flex;
    gap: 8px;
    align-items: flex-end;
    border-top: 1px solid #eef1f4;
    padding-top: 12px;
}

.somcp-agent .somcp-agent-compose textarea {
    flex: 1;
    resize: vertical;
    min-height: 60px;
}

.somcp-agent .somcp-agent-bar {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 10px;
}

.somcp-agent .somcp-agent-bar select {
    max-width: 360px;
}

.somcp-agent .somcp-agent-role {
    font-size: 12px;
    color: #6c868e;
}

.somcp-agent .somcp-agent-working {
    color: #6c868e;
    font-style: italic;
    margin: 8px 0;
}

.somcp-agent .somcp-agent-working[hidden],
.somcp-agent [hidden] {
    display: none;
}

.somcp-agent .btn:focus:not(:focus-visible),
.somcp-agent .list-group-item:focus:not(:focus-visible) {
    outline: 0;
}
</style>

<div class="somcp-agent">
{if !$somcp_agents}
    <div class="alert alert-info">
        {l s='No assistant is enabled yet. Add an API key for Claude, ChatGPT or Gemini in the module settings.' d='Modules.Somcp.Admin'}
        {if $somcp_settings_url}
            <a href="{$somcp_settings_url|escape:'html':'UTF-8'}">{l s='Open the AI agent settings' d='Modules.Somcp.Admin'}</a>
        {else}
            {l s='Ask an administrator of the shop.' d='Modules.Somcp.Admin'}
        {/if}
    </div>
{else}
    <div class="row">
        <div class="col-lg-3">
            <div class="panel">
                <div class="panel-heading"><i class="icon-comments"></i> {l s='Conversations' d='Modules.Somcp.Admin'}</div>
                <button type="button" class="btn btn-primary btn-block" data-somcp-new>
                    <i class="icon-plus"></i> {l s='New conversation' d='Modules.Somcp.Admin'}
                </button>
                <div class="list-group somcp-agent-list" data-somcp-list></div>
            </div>
        </div>

        <div class="col-lg-9">
            <div class="panel">
                <div class="panel-heading"><i class="icon-comment"></i> <span data-somcp-title>{l s='New conversation' d='Modules.Somcp.Admin'}</span></div>

                <div class="somcp-agent-bar">
                    <label for="somcp-agent-pick" class="control-label">{l s='Assistant' d='Modules.Somcp.Admin'}</label>
                    <select id="somcp-agent-pick" class="form-control" data-somcp-pick>
                        {foreach from=$somcp_agents item=agent}
                            <option value="{$agent.value|escape:'html':'UTF-8'}" data-role="{$agent.role|escape:'html':'UTF-8'}">{$agent.label|escape:'html':'UTF-8'}</option>
                        {/foreach}
                    </select>
                    <span class="somcp-agent-role" data-somcp-role></span>
                </div>

                <div class="somcp-agent-thread" data-somcp-thread>
                    <p class="somcp-agent-empty">{l s='Ask anything about your shop: the assistant reads it with its tools and asks before changing anything.' d='Modules.Somcp.Admin'}</p>
                </div>
                <p class="somcp-agent-working" data-somcp-working hidden>{l s='The assistant is working...' d='Modules.Somcp.Admin'}</p>
                <div class="alert alert-danger" data-somcp-error hidden></div>

                <form class="somcp-agent-compose" data-somcp-compose>
                    <textarea class="form-control" rows="2" maxlength="20000" data-somcp-text placeholder="{l s='Write a message. Enter sends, Shift+Enter goes to the next line.' d='Modules.Somcp.Admin'}"></textarea>
                    <button type="submit" class="btn btn-primary" data-somcp-send>
                        <i class="icon-send"></i> {l s='Send' d='Modules.Somcp.Admin'}
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        (function () {
            var url = '{$somcp_ajax_url|escape:'javascript':'UTF-8'}';
            var initial = {$somcp_conversations|json_encode nofilter};
            var text = {
                you: '{l s='You' d='Modules.Somcp.Admin' js=1}',
                fresh: '{l s='New conversation' d='Modules.Somcp.Admin' js=1}',
                read: '{l s='Read' d='Modules.Somcp.Admin' js=1}',
                done: '{l s='Done' d='Modules.Somcp.Admin' js=1}',
                failed: '{l s='Failed' d='Modules.Somcp.Admin' js=1}',
                refused: '{l s='Refused' d='Modules.Somcp.Admin' js=1}',
                pending: '{l s='The assistant wants to make this change:' d='Modules.Somcp.Admin' js=1}',
                approve: '{l s='Approve' d='Modules.Somcp.Admin' js=1}',
                refuse: '{l s='Refuse' d='Modules.Somcp.Admin' js=1}',
                confirmDrop: '{l s='Delete this conversation?' d='Modules.Somcp.Admin' js=1}',
                drop: '{l s='Delete' d='Modules.Somcp.Admin' js=1}',
                errors: {
                    unavailable: '{l s='This model is not available any more. Pick another one, or check the AI agent settings.' d='Modules.Somcp.Admin' js=1}',
                    too_many_steps: '{l s='The assistant stopped after too many steps in a row. Send a message to let it carry on.' d='Modules.Somcp.Admin' js=1}',
                    provider: '{l s='The assistant could not answer:' d='Modules.Somcp.Admin' js=1}',
                    storage: '{l s='The conversation could not be saved.' d='Modules.Somcp.Admin' js=1}',
                    not_found: '{l s='This conversation no longer exists.' d='Modules.Somcp.Admin' js=1}',
                    network: '{l s='The shop did not answer. Check your connection and try again.' d='Modules.Somcp.Admin' js=1}'
                }
            };
{* Everything below is plain JavaScript: kept away from Smarty, which would
   read a regular expression quantifier or an empty object as one of its tags. *}
{literal}
            var root = document.querySelector('.somcp-agent');
            var list = root.querySelector('[data-somcp-list]');
            var thread = root.querySelector('[data-somcp-thread]');
            var empty = thread.innerHTML;
            var title = root.querySelector('[data-somcp-title]');
            var pick = root.querySelector('[data-somcp-pick]');
            var role = root.querySelector('[data-somcp-role]');
            var working = root.querySelector('[data-somcp-working]');
            var failure = root.querySelector('[data-somcp-error]');
            var compose = root.querySelector('[data-somcp-compose]');
            var field = root.querySelector('[data-somcp-text]');
            var send = root.querySelector('[data-somcp-send]');

            var current = 0;
            var busy = false;
            var remembered = null;

            var escape = function (value) {
                return String(value).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            };

            // A small part of Markdown, enough for what models write: code,
            // bold, links, titles and lists. The text is escaped first and only
            // these few tags are put back, so nothing a model writes - or a
            // product description it quotes - can become live markup.
            var markdown = function (source) {
                var blocks = [];
                var html = escape(source).replace(/```[a-z0-9]*\n?([\s\S]*?)```/gi, function (all, code) {
                    blocks.push('<pre>' + code + '</pre>');
                    return '\u0000' + (blocks.length - 1) + '\u0000';
                });
                html = html
                    .replace(/`([^`\n]+)`/g, '<code>$1</code>')
                    .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
                    .replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
                    .replace(/^#{1,4} (.+)$/gm, '<strong>$1</strong>')
                    .replace(/^\s*[-*] (.+)$/gm, '&bull; $1')
                    .replace(/\n/g, '<br>');
                return html.replace(/\u0000(\d+)\u0000/g, function (all, index) {
                    return blocks[parseInt(index, 10)];
                });
            };

            var showRole = function () {
                var option = pick.options[pick.selectedIndex];
                role.textContent = option ? option.getAttribute('data-role') : '';
            };

            var drawList = function (conversations) {
                list.innerHTML = '';
                conversations.forEach(function (conversation) {
                    var item = document.createElement('a');
                    item.className = 'list-group-item' + (conversation.id_conversation === current ? ' active' : '');
                    item.setAttribute('role', 'button');
                    item.setAttribute('tabindex', '0');
                    item.innerHTML = '<span class="somcp-agent-title"></span><button type="button" class="somcp-agent-drop" title="' + escape(text.drop) + '">&times;</button>';
                    item.querySelector('.somcp-agent-title').textContent = conversation.title;
                    item.addEventListener('click', function (event) {
                        if (event.target.classList.contains('somcp-agent-drop')) {
                            event.stopPropagation();
                            if (window.confirm(text.confirmDrop)) {
                                drop(conversation.id_conversation);
                            }
                            return;
                        }
                        open(conversation.id_conversation);
                    });
                    list.appendChild(item);
                    if (conversation.id_conversation === current) {
                        title.textContent = conversation.title;
                    }
                });
            };

            var drawThread = function (items, waiting) {
                if (!items.length) {
                    thread.innerHTML = empty;
                    return;
                }
                thread.innerHTML = '';
                items.forEach(function (item) {
                    var node = document.createElement('div');
                    if (item.kind === 'user') {
                        node.className = 'somcp-agent-msg somcp-agent-msg-user';
                        node.textContent = item.text;
                    } else if (item.kind === 'assistant') {
                        node.className = 'somcp-agent-msg somcp-agent-msg-assistant';
                        node.innerHTML = '<span class="somcp-agent-who">' + escape(item.agent) + '</span>' + markdown(item.text);
                    } else if (item.status === 'pending') {
                        node.className = 'somcp-agent-pending';
                        node.innerHTML = '<strong>' + escape(text.pending) + '</strong> <code>' + escape(item.name) + '</code>'
                            + '<pre>' + escape(item.arguments) + '</pre>';
                    } else {
                        var label = { done: text.done, error: text.failed, refused: text.refused }[item.status] || text.read;
                        node.className = 'somcp-agent-tool';
                        node.innerHTML = '<details><summary><i class="icon-wrench"></i> <code>' + escape(item.name) + '</code> &middot; '
                            + escape(label) + '</summary><pre>' + escape(item.arguments) + '</pre></details>';
                    }
                    thread.appendChild(node);
                });
                if (waiting) {
                    var actions = document.createElement('div');
                    actions.className = 'somcp-agent-actions';
                    actions.innerHTML = '<button type="button" class="btn btn-default" data-decide="0">' + escape(text.refuse) + '</button>'
                        + '<button type="button" class="btn btn-primary" data-decide="1">' + escape(text.approve) + '</button>';
                    actions.querySelectorAll('[data-decide]').forEach(function (button) {
                        button.addEventListener('click', function () {
                            call('decide', { approve: button.getAttribute('data-decide') });
                        });
                    });
                    thread.appendChild(actions);
                }
                thread.scrollTop = thread.scrollHeight;
            };

            var setBusy = function (on) {
                busy = on;
                working.hidden = !on;
                send.disabled = on;
                pick.disabled = on;
            };

            var fail = function (code, detail) {
                var message = text.errors[code] || text.errors.network;
                failure.textContent = detail ? message + ' ' + detail : message;
                failure.hidden = false;
            };

            var call = function (action, data) {
                var body = new FormData();
                body.append('ajax', '1');
                body.append('action', action);
                body.append('id_conversation', String(current));
                body.append('agent', pick.value);
                Object.keys(data || {}).forEach(function (key) {
                    body.append(key, data[key]);
                });

                failure.hidden = true;
                setBusy(true);

                return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (answer) {
                        handle(answer);
                    })
                    .catch(function () {
                        setBusy(false);
                        fail('network', '');
                    });
            };

            var handle = function (answer) {
                if (answer.id_conversation) {
                    current = answer.id_conversation;
                    remember();
                }
                if (answer.agent && pick.querySelector('option[value="' + answer.agent.replace(/"/g, '') + '"]')) {
                    pick.value = answer.agent;
                    showRole();
                }
                if (answer.conversations) {
                    drawList(answer.conversations);
                }
                if (answer.items) {
                    drawThread(answer.items, answer.state === 'confirm');
                }
                if (answer.error) {
                    fail(answer.error, answer.detail || '');
                    if (answer.error === 'not_found') {
                        reset();
                    }
                }

                // Tools ran: the page asks for the next turn itself, so no
                // single request has to last as long as the whole answer.
                if (answer.state === 'continue' && !answer.error) {
                    call('continue', {});
                    return;
                }
                setBusy(false);
                if (answer.state !== 'confirm') {
                    field.focus();
                }
            };

            var remember = function () {
                try {
                    window.sessionStorage.setItem('somcp.agent.conversation', String(current));
                } catch (e) {
                    // Not remembered: the page opens on a new conversation.
                }
            };

            var reset = function () {
                current = 0;
                remember();
                title.textContent = text.fresh;
                thread.innerHTML = empty;
                list.querySelectorAll('.active').forEach(function (item) {
                    item.classList.remove('active');
                });
            };

            var open = function (id) {
                if (busy) {
                    return;
                }
                current = id;
                call('open', {});
            };

            var drop = function (id) {
                var previous = current;
                current = id;
                call('remove', {}).then(function () {
                    if (previous === id) {
                        reset();
                    } else {
                        current = previous;
                        remember();
                    }
                });
            };

            compose.addEventListener('submit', function (event) {
                event.preventDefault();
                var message = field.value.trim();
                if (!message || busy) {
                    return;
                }
                field.value = '';
                // Shown at once, before the round trip, so the employee sees
                // their message leave; the answer redraws the whole thread.
                if (!current) {
                    thread.innerHTML = '';
                }
                var mine = document.createElement('div');
                mine.className = 'somcp-agent-msg somcp-agent-msg-user';
                mine.textContent = message;
                thread.appendChild(mine);
                thread.scrollTop = thread.scrollHeight;
                call('send', { text: message });
            });

            field.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                    event.preventDefault();
                    compose.requestSubmit ? compose.requestSubmit() : compose.dispatchEvent(new Event('submit', { cancelable: true }));
                }
            });

            root.querySelector('[data-somcp-new]').addEventListener('click', function () {
                if (!busy) {
                    reset();
                    field.focus();
                }
            });

            pick.addEventListener('change', showRole);

            showRole();
            drawList(initial);

            try {
                remembered = parseInt(window.sessionStorage.getItem('somcp.agent.conversation') || '0', 10);
            } catch (e) {
                remembered = 0;
            }
            if (remembered && initial.some(function (conversation) { return conversation.id_conversation === remembered; })) {
                open(remembered);
            }
{/literal}
        })();
    </script>
{/if}
</div>
