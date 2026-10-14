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

{* The same layout as the customer service screen of the other Novalo module -
   conversations on the left, the thread on the right, the reply bar at the
   foot - drawn with PrestaShop's own panels, lists, buttons and fields. The
   rules below only place things: colours, borders and fonts come from the
   back office theme, so the page follows whatever theme the shop runs.
   Inline for the reason given in _copy.tpl. *}
<style type="text/css">
.somcp-agent-layout {
    display: flex;
    gap: 16px;
    height: calc(100vh - 260px);
    min-height: 500px;
}

#content .somcp-agent-layout > .panel {
    display: flex;
    flex-direction: column;
    min-height: 0;
    margin-bottom: 0;
    padding: 0;
}

/* The panel loses its padding so the list and the thread reach its edges;
   the heading and the reply bar keep the theme's own padding but drop the
   negative margins that padding used to offset. */
#content .somcp-agent-layout > .panel > .panel-heading,
#content .somcp-agent-layout > .panel > .panel-footer {
    margin: 0;
    flex-shrink: 0;
}

.somcp-agent-side {
    flex: 0 0 340px;
}

.somcp-agent-main {
    flex: 1;
    min-width: 0;
}

.somcp-agent-tools {
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex-shrink: 0;
}

.somcp-agent-list {
    flex: 1;
    overflow-y: auto;
    margin: 0;
}

.somcp-agent-list .list-group-item {
    border-left: 0;
    border-right: 0;
    border-radius: 0;
    cursor: pointer;
}

/* The open conversation, marked the way the customer service screen marks it:
   a light background and a bar on the left, rather than the theme's solid
   active fill, which turned the whole row black or blue. The bar takes the
   colour of the theme's primary button, read by the script below. */
.somcp-agent-list .list-group-item.somcp-agent-current {
    background: rgba(0, 0, 0, .04);
    box-shadow: inset 3px 0 0 var(--somcp-accent, #25b9d7);
}

.somcp-agent-row {
    display: flex;
    justify-content: space-between;
    gap: 8px;
}

.somcp-agent-row strong {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.somcp-agent-head {
    display: flex;
    align-items: center;
    gap: 10px;
}

.somcp-agent-head [data-somcp-title] {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.somcp-agent-head .btn {
    text-transform: none;
}

.somcp-agent-thread {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    min-height: 0;
}

.somcp-agent-empty {
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.somcp-agent-empty .material-icons {
    font-size: 64px;
    opacity: .3;
    margin-bottom: 12px;
}

/* Bubbles: the employee on the right, the Agent on the left, each with the
   theme's own well and panel boxes. Only the width and side are set here. */
.somcp-agent-msg {
    max-width: 75%;
    margin-bottom: 14px;
}

.somcp-agent-msg-user {
    margin-left: auto;
}

/* Under #content: the theme sets the panel margin with #content.bootstrap,
   which outweighs any selector made of classes alone. */
#content .somcp-agent .somcp-agent-msg > .alert {
    margin: 0;
}

/* The alert keeps its own padding: PrestaShop 9 draws its icon inside it. */
#content .somcp-agent .somcp-agent-msg > .well,
#content .somcp-agent .somcp-agent-msg > .panel {
    margin: 0;
    padding: 10px 14px;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.somcp-agent-msg-user > .well {
    white-space: pre-wrap;
}

.somcp-agent-msg pre {
    white-space: pre-wrap;
    margin: 6px 0 0;
}

.somcp-agent-meta {
    margin-top: 4px;
    padding: 0 6px;
}

.somcp-agent-msg-user .somcp-agent-meta {
    text-align: right;
}

.somcp-agent-tool {
    margin: 0 0 10px;
}

.somcp-agent-tool summary {
    cursor: pointer;
}

.somcp-agent-tool pre {
    max-height: 240px;
    overflow: auto;
}

.somcp-agent-decide {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin: -4px 0 14px;
}

#content .somcp-agent-reply {
    padding: 12px 16px;
    height: auto;
}

.somcp-agent-reply textarea {
    resize: vertical;
    min-height: 70px;
}

/* Two groups, as on the customer service screen: what shapes the message on
   the left, what sends it on the right. The row breaks between them on a
   narrow screen rather than squeezing either. */
.somcp-agent-foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px 16px;
    margin-top: 8px;
}

.somcp-agent-foot > div {
    display: flex;
    align-items: center;
    gap: 10px;
}

.somcp-agent-foot .control-label,
.somcp-agent-foot .help-block {
    margin: 0;
    padding: 0;
}

.somcp-agent-foot select {
    width: auto;
    max-width: 280px;
}

.somcp-agent-count {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.somcp-agent [hidden] {
    display: none !important;
}

.somcp-agent .btn:focus:not(:focus-visible),
.somcp-agent .list-group-item:focus:not(:focus-visible) {
    outline: 0;
}

@media (max-width: 991px) {
    .somcp-agent-layout {
        flex-direction: column;
        height: auto;
    }

    .somcp-agent-side {
        flex-basis: auto;
        max-height: 260px;
    }

    .somcp-agent-main {
        height: 70vh;
    }
}
</style>

<div class="somcp-agent">
{if !$somcp_agents}
    <div class="alert alert-info">
        {l s='No assistant is enabled yet. Add an API key for Claude, ChatGPT or Gemini in the module settings.' d='Modules.Somcp.Admin'}
        {if $somcp_settings_url}
            <a href="{$somcp_settings_url|escape:'html':'UTF-8'}">{l s='Open the AI Agent settings' d='Modules.Somcp.Admin'}</a>
        {else}
            {l s='Ask an administrator of the shop.' d='Modules.Somcp.Admin'}
        {/if}
    </div>
{else}
    <div class="somcp-agent-layout">

        {* ===== Left: the conversations ===== *}
        <div class="panel somcp-agent-side">
            <div class="panel-heading"><i class="icon-comments"></i> {l s='Conversations' d='Modules.Somcp.Admin'}</div>
            <div class="somcp-agent-tools">
                <button type="button" class="btn btn-primary btn-block" data-somcp-new>
                    <i class="icon-plus"></i> {l s='New conversation' d='Modules.Somcp.Admin'}
                </button>
                <input type="search" class="form-control" data-somcp-search placeholder="{l s='Search a conversation' d='Modules.Somcp.Admin'}">
            </div>
            <div class="list-group somcp-agent-list" data-somcp-list></div>
        </div>

        {* ===== Right: the thread and the reply bar ===== *}
        <div class="panel somcp-agent-main">
            <div class="panel-heading somcp-agent-head">
                <i class="icon-comment"></i>
                <span data-somcp-title>{l s='New conversation' d='Modules.Somcp.Admin'}</span>
                <button type="button" class="btn btn-default btn-sm" data-somcp-drop hidden>
                    <i class="icon-trash"></i> {l s='Delete' d='Modules.Somcp.Admin'}
                </button>
            </div>

            <div class="somcp-agent-thread" data-somcp-thread></div>

            <form class="somcp-agent-reply panel-footer" data-somcp-compose>
                <textarea class="form-control" rows="3" maxlength="20000" data-somcp-text placeholder="{l s='Write a message. Enter sends, Shift+Enter goes to the next line.' d='Modules.Somcp.Admin'}"></textarea>
                <div class="somcp-agent-foot">
                    <div>
                        <label for="somcp-agent-pick" class="control-label">{l s='Agent' d='Modules.Somcp.Admin'}</label>
                        <select id="somcp-agent-pick" class="form-control" data-somcp-pick>
                            {foreach from=$somcp_agents item=agent}
                                <option value="{$agent.value|escape:'html':'UTF-8'}" data-role="{$agent.role|escape:'html':'UTF-8'}">{$agent.label|escape:'html':'UTF-8'}</option>
                            {/foreach}
                        </select>
                        <span class="help-block" data-somcp-role></span>
                    </div>
                    <div>
                        <span class="help-block somcp-agent-count" data-somcp-count>0 / 20000</span>
                        <button type="submit" class="btn btn-primary" data-somcp-send>
                            <i class="icon-send"></i> {l s='Send' d='Modules.Somcp.Admin'}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {* The empty thread, cloned whenever no conversation is open. *}
    <template data-somcp-empty>
        <div class="somcp-agent-empty text-muted">
            <i class="material-icons">forum</i>
            <p>{l s='Pick a conversation on the left, or write to start a new one.' d='Modules.Somcp.Admin'}</p>
            <p>{l s='Ask anything about your shop: the Agent reads it with its tools and asks before changing anything.' d='Modules.Somcp.Admin'}</p>
        </div>
    </template>

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
                pending: '{l s='The Agent wants to make this change:' d='Modules.Somcp.Admin' js=1}',
                approve: '{l s='Approve' d='Modules.Somcp.Admin' js=1}',
                refuse: '{l s='Refuse' d='Modules.Somcp.Admin' js=1}',
                working: '{l s='Thinking...' d='Modules.Somcp.Admin' js=1}',
                nothing: '{l s='No conversation found.' d='Modules.Somcp.Admin' js=1}',
                confirmDrop: '{l s='Delete this conversation?' d='Modules.Somcp.Admin' js=1}',
                // Written as the Agent's own reply, so a failure reads like the
                // rest of the conversation rather than as a page error.
                errors: {
                    unavailable: '{l s='I am not available with this model any more. Pick another one in the list below, or check the AI Agent settings.' d='Modules.Somcp.Admin' js=1}',
                    too_many_steps: '{l s='I stopped after too many steps in a row, to be safe. Send me a message and I will carry on where I left off.' d='Modules.Somcp.Admin' js=1}',
                    provider: '{l s='I could not answer this time. Here is what my provider replied:' d='Modules.Somcp.Admin' js=1}',
                    storage: '{l s='I could not save our conversation. Try again in a moment.' d='Modules.Somcp.Admin' js=1}',
                    not_found: '{l s='This conversation no longer exists. Write to me here and we will start a new one.' d='Modules.Somcp.Admin' js=1}',
                    network: '{l s='Your message did not reach me: the shop did not answer. Check your connection and send it again.' d='Modules.Somcp.Admin' js=1}'
                }
            };
{* Everything below is plain JavaScript: kept away from Smarty, which would
   read a regular expression quantifier or an empty object as one of its tags. *}
{literal}
            var root = document.querySelector('.somcp-agent');
            var list = root.querySelector('[data-somcp-list]');
            var search = root.querySelector('[data-somcp-search]');
            var thread = root.querySelector('[data-somcp-thread]');
            var empty = root.querySelector('[data-somcp-empty]');
            var title = root.querySelector('[data-somcp-title]');
            var dropButton = root.querySelector('[data-somcp-drop]');
            var pick = root.querySelector('[data-somcp-pick]');
            var role = root.querySelector('[data-somcp-role]');
            var compose = root.querySelector('[data-somcp-compose]');
            var field = root.querySelector('[data-somcp-text]');
            var count = root.querySelector('[data-somcp-count]');
            var send = root.querySelector('[data-somcp-send]');

            var current = 0;
            var busy = false;
            var conversations = initial;
            var items = [];
            var waiting = false;

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

            // Today shows the time only, an older message its date as well.
            var when = function (stamp) {
                if (!stamp) {
                    return '';
                }
                var date = new Date(String(stamp).replace(' ', 'T'));
                if (isNaN(date.getTime())) {
                    return String(stamp);
                }
                var time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                return date.toDateString() === new Date().toDateString()
                    ? time
                    : date.toLocaleDateString() + ' ' + time;
            };

            var agentLabel = function (value) {
                var option = pick.querySelector('option[value="' + String(value).replace(/["\\]/g, '') + '"]');
                return option ? option.textContent : '';
            };

            var showRole = function () {
                var option = pick.options[pick.selectedIndex];
                role.textContent = option ? option.getAttribute('data-role') : '';
            };

            var drawList = function () {
                var filter = search.value.trim().toLowerCase();
                list.innerHTML = '';
                var shown = 0;
                conversations.forEach(function (conversation) {
                    if (filter && conversation.title.toLowerCase().indexOf(filter) === -1) {
                        return;
                    }
                    shown++;
                    var item = document.createElement('a');
                    item.className = 'list-group-item' + (conversation.id_conversation === current ? ' somcp-agent-current' : '');
                    item.setAttribute('aria-current', conversation.id_conversation === current ? 'true' : 'false');
                    item.setAttribute('role', 'button');
                    item.setAttribute('tabindex', '0');
                    item.innerHTML = '<div class="somcp-agent-row"><strong></strong><small></small></div><small class="text-muted"></small>';
                    item.querySelector('strong').textContent = conversation.title;
                    item.querySelector('.somcp-agent-row small').textContent = when(conversation.date_upd);
                    item.querySelector('.text-muted').textContent = agentLabel(conversation.provider + ':' + conversation.model) || conversation.model;
                    item.addEventListener('click', function () {
                        open(conversation.id_conversation);
                    });
                    item.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter') {
                            open(conversation.id_conversation);
                        }
                    });
                    list.appendChild(item);
                    if (conversation.id_conversation === current) {
                        title.textContent = conversation.title;
                    }
                });
                if (!shown) {
                    list.innerHTML = '<p class="list-group-item text-muted text-center">' + escape(text.nothing) + '</p>';
                }
            };

            var bubble = function (side, who, stamp, body, box) {
                var node = document.createElement('div');
                node.className = 'somcp-agent-msg somcp-agent-msg-' + side;
                node.innerHTML = '<div class="' + box + '">' + body + '</div>'
                    + '<div class="somcp-agent-meta help-block">' + escape(who) + (stamp ? ' &middot; ' + escape(when(stamp)) : '') + '</div>';
                return node;
            };

            // Draws the thread. A failure, when there is one, is drawn as the
            // Agent's last reply: it is not saved, and the next answer replaces it.
            var drawThread = function (failure, thinking) {
                thread.innerHTML = '';
                if (!items.length && !failure && !thinking) {
                    thread.appendChild(empty.content.cloneNode(true));
                    return;
                }
                items.forEach(function (item) {
                    if (item.kind === 'user') {
                        thread.appendChild(bubble('user', text.you, item.date, escape(item.text), 'well'));
                        return;
                    }
                    if (item.kind === 'assistant') {
                        thread.appendChild(bubble('agent', item.agent, item.date, markdown(item.text), 'panel'));
                        return;
                    }
                    var node = document.createElement('div');
                    if (item.status === 'pending') {
                        node.className = 'somcp-agent-msg somcp-agent-msg-agent';
                        node.innerHTML = '<div class="alert alert-warning"><strong>' + escape(text.pending) + '</strong> <code>'
                            + escape(item.name) + '</code><pre>' + escape(item.arguments) + '</pre></div>';
                    } else {
                        var label = { done: text.done, error: text.failed, refused: text.refused }[item.status] || text.read;
                        node.className = 'somcp-agent-tool help-block';
                        node.innerHTML = '<details><summary><i class="icon-wrench"></i> <code>' + escape(item.name) + '</code> &middot; '
                            + escape(label) + '</summary><pre>' + escape(item.arguments) + '</pre></details>';
                    }
                    thread.appendChild(node);
                });
                if (waiting) {
                    var actions = document.createElement('div');
                    actions.className = 'somcp-agent-decide';
                    actions.innerHTML = '<button type="button" class="btn btn-default" data-decide="0">' + escape(text.refuse) + '</button>'
                        + '<button type="button" class="btn btn-primary" data-decide="1">' + escape(text.approve) + '</button>';
                    actions.querySelectorAll('[data-decide]').forEach(function (button) {
                        button.addEventListener('click', function () {
                            call('decide', { approve: button.getAttribute('data-decide') });
                        });
                    });
                    thread.appendChild(actions);
                }
                if (thinking) {
                    thread.appendChild(bubble('agent', agentLabel(pick.value), '', '<span class="text-muted">' + escape(text.working) + '</span>', 'panel'));
                }
                if (failure) {
                    thread.appendChild(bubble('agent', agentLabel(pick.value), new Date().toISOString(), failure, 'panel'));
                }
                thread.scrollTop = thread.scrollHeight;
            };

            var failureText = function (code, detail) {
                var message = escape(text.errors[code] || text.errors.network);
                return detail ? message + '<pre>' + escape(detail) + '</pre>' : message;
            };

            var setBusy = function (on) {
                busy = on;
                send.disabled = on;
                pick.disabled = on;
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

                setBusy(true);
                waiting = false;
                drawThread('', action !== 'open' && action !== 'remove');

                return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (answer) {
                        handle(answer);
                    })
                    .catch(function () {
                        setBusy(false);
                        drawThread(failureText('network', ''), false);
                    });
            };

            var handle = function (answer) {
                if (answer.id_conversation) {
                    current = answer.id_conversation;
                    remember();
                }
                if (answer.agent && agentLabel(answer.agent)) {
                    pick.value = answer.agent;
                    showRole();
                }
                if (answer.conversations) {
                    conversations = answer.conversations;
                }
                if (answer.items) {
                    items = answer.items;
                }
                if (answer.error === 'not_found') {
                    current = 0;
                    items = [];
                    remember();
                }
                waiting = answer.state === 'confirm';
                dropButton.hidden = !current;
                if (!current) {
                    title.textContent = text.fresh;
                }
                drawList();

                // Tools ran: the page asks for the next turn itself, so no
                // single request has to last as long as the whole answer.
                if (answer.state === 'continue' && !answer.error) {
                    call('continue', {});
                    return;
                }
                setBusy(false);
                drawThread(answer.error ? failureText(answer.error, answer.detail || '') : '', false);
                if (!waiting) {
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
                items = [];
                waiting = false;
                remember();
                title.textContent = text.fresh;
                dropButton.hidden = true;
                drawList();
                drawThread('', false);
            };

            var open = function (id) {
                if (busy) {
                    return;
                }
                current = id;
                call('open', {});
            };

            compose.addEventListener('submit', function (event) {
                event.preventDefault();
                var message = field.value.trim();
                if (!message || busy) {
                    return;
                }
                field.value = '';
                count.textContent = '0 / 20000';
                // Shown at once, before the round trip, so the employee sees
                // their message leave; the answer redraws the whole thread.
                items = items.concat([{ kind: 'user', text: message, date: new Date().toISOString() }]);
                call('send', { text: message });
            });

            field.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                    event.preventDefault();
                    if (compose.requestSubmit) {
                        compose.requestSubmit();
                    } else {
                        compose.dispatchEvent(new Event('submit', { cancelable: true }));
                    }
                }
            });

            field.addEventListener('input', function () {
                count.textContent = field.value.length + ' / 20000';
            });

            dropButton.addEventListener('click', function () {
                if (busy || !current || !window.confirm(text.confirmDrop)) {
                    return;
                }
                call('remove', {}).then(reset);
            });

            root.querySelector('[data-somcp-new]').addEventListener('click', function () {
                if (!busy) {
                    reset();
                    field.focus();
                }
            });

            search.addEventListener('input', drawList);
            pick.addEventListener('change', showRole);

            // The accent of the open conversation is the theme's own primary
            // colour, whatever theme the shop runs: read from the button that
            // already wears it rather than guessed.
            var accent = window.getComputedStyle(root.querySelector('[data-somcp-new]')).backgroundColor;
            if (accent && accent !== 'transparent' && accent !== 'rgba(0, 0, 0, 0)') {
                root.style.setProperty('--somcp-accent', accent);
            }

            showRole();
            drawList();
            drawThread('', false);

            var remembered = 0;
            try {
                remembered = parseInt(window.sessionStorage.getItem('somcp.agent.conversation') || '0', 10);
            } catch (e) {
                remembered = 0;
            }
            if (remembered && conversations.some(function (conversation) { return conversation.id_conversation === remembered; })) {
                open(remembered);
            }
{/literal}
        })();
    </script>
{/if}
</div>
