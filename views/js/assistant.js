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
    var SVG = 'http://www.w3.org/2000/svg';
    // The pictures a prompt may name in its prompt.json, as stroke paths on a
    // 24 px grid. CI reads the keys of this object: a prompt naming anything
    // else fails the build, so keep one entry per line and the key as written.
    var PROMPT_ICONS = {
        spark: ['M12 3l1.8 4.6 4.7 1.9-4.7 1.8L12 16l-1.8-4.7-4.7-1.8 4.7-1.9z', 'M19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z'],
        chart: ['M3 3v18h18', 'M7 15l4-4 3 3 5-6'],
        search: ['M11 4a7 7 0 1 0 0 14 7 7 0 1 0 0-14z', 'M20 20l-3.5-3.5'],
        truck: ['M3 6h11v10H3z', 'M14 10h4l3 3v3h-7', 'M7 19a2 2 0 1 0 0-4 2 2 0 1 0 0 4z', 'M17 19a2 2 0 1 0 0-4 2 2 0 1 0 0 4z'],
        tag: ['M3 12V4h8l9 9-8 8z', 'M7.5 7.5h.01'],
        box: ['M21 8l-9-5-9 5 9 5z', 'M3 8v8l9 5 9-5V8', 'M12 13v8'],
        person: ['M12 12a4 4 0 1 0 0-8 4 4 0 1 0 0 8z', 'M4 21a8 8 0 0 1 16 0'],
        page: ['M6 2h9l5 5v15H6z', 'M14 2v6h6', 'M9 13h8', 'M9 17h6'],
        rank: ['M4 20h16', 'M7 16v-6', 'M12 16V6', 'M17 16v-3'],
        plug: ['M9 2v6', 'M15 2v6', 'M6 8h12v3a6 6 0 0 1-12 0z', 'M12 17v5'],
        puzzle: ['M4 7h4a2 2 0 1 1 4 0h4v4a2 2 0 1 1 0 4v4H4z'],
        menu: ['M4 6h16', 'M4 12h16', 'M4 18h16'],
        refresh: ['M21 12a9 9 0 1 1-3-6.7L21 8', 'M21 3v5h-5'],
        bulb: ['M9 18h6', 'M10 22h4', 'M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2z'],
        cart: ['M3 4h2l2.4 11h11.2L21 8H6.2', 'M9 20a1 1 0 1 0 0-2 1 1 0 1 0 0 2z', 'M18 20a1 1 0 1 0 0-2 1 1 0 1 0 0 2z']
    };
    // The pictures of the chat itself: drawn as lines in the text colour, so
    // they follow the theme and read the same on every system, unlike emoji.
    var UI_ICONS = {
        list: ['M4 6h16', 'M4 12h10', 'M4 18h7'],
        gear: ['M12 15a3 3 0 1 0 0-6 3 3 0 1 0 0 6z', 'M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z'],
        expand: ['M15 3h6v6', 'M9 21H3v-6', 'M21 3l-7 7', 'M3 21l7-7'],
        shrink: ['M4 14h6v6', 'M20 10h-6V4', 'M14 10l7-7', 'M3 21l7-7'],
        close: ['M6 6l12 12', 'M18 6L6 18'],
        plus: ['M12 5v14', 'M5 12h14'],
        up: ['M12 19V5', 'M5 12l7-7 7 7'],
        clip: ['M21 12.5l-8.5 8.5a5 5 0 0 1-7-7L14 5.5a3.3 3.3 0 0 1 4.7 4.7L10.2 18.7a1.7 1.7 0 0 1-2.4-2.4L15.5 8.6'],
        check: ['M20 6L9 17l-5-5'],
        warn: ['M12 9v4', 'M12 17h.01', 'M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z'],
        trash: ['M3 6h18', 'M8 6V4h8v2', 'M6 6l1 14h10l1-14'],
        copy: ['M9 9h12v12H9z', 'M5 15V5a2 2 0 0 1 2-2h10'],
        chevron: ['M6 9l6 6 6-6'],
        shop: ['M3 9l1.5-5h15L21 9', 'M3 9h18v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0z', 'M5 13v7h14v-7'],
        clock: ['M12 21a9 9 0 1 0 0-18 9 9 0 1 0 0 18z', 'M12 7v5l3 2'],
        globe: ['M12 21a9 9 0 1 0 0-18 9 9 0 1 0 0 18z', 'M3 12h18', 'M12 3a14 14 0 0 1 0 18', 'M12 3a14 14 0 0 0 0 18'],
        brain: ['M12 4a3 3 0 0 0-3 3 3 3 0 0 0-3 3c0 1 .4 1.8 1 2.4A3 3 0 0 0 9 18a3 3 0 0 0 3 2', 'M12 4a3 3 0 0 1 3 3 3 3 0 0 1 3 3c0 1-.4 1.8-1 2.4A3 3 0 0 1 15 18a3 3 0 0 1-3 2', 'M12 4v16']
    };
    // The switches of the + menu, in their order. A pill under the field
    // shows each one set away from how a conversation starts: thinking and
    // search start off, the memory on.
    var SWITCHES = [
        { key: 'think', icon: 'bulb', start: false },
        { key: 'web', icon: 'globe', start: false },
        { key: 'memory', icon: 'brain', start: true }
    ];
    var MAX_SUGGESTIONS = 4;
    var MAX_STEPS = 30;
    // A move still running on the server - from before a reload, or in another
    // tab - is waited for by asking again every BUSY_DELAY, up to MAX_WAITS
    // times: the server frees a conversation whose move died after 5 minutes.
    var BUSY_DELAY = 2000;
    var MAX_WAITS = 160;
    // A conversation left while it was being answered is watched every
    // WATCH_DELAY, up to MAX_WATCHES times, to mark it once the answer lands.
    var WATCH_DELAY = 5000;
    var MAX_WATCHES = 60;
    // busy: an action of the employee is on its way (send, approve, open...).
    // shown: the conversation on screen, the only one whose replies may
    // replace what is drawn. ticket and round: the latest action and the
    // latest background check; a reply to an older one is stale.
    var state = {
        view: null, threads: [], assistants: [], prompts: [], shops: [], pickShop: false, verb: 0, query: '', busy: false, steps: 0, waits: 0,
        shown: '', ticket: 0, round: 0, left: {}, unread: {}, watches: 0, drawn: '', openings: {},
        memory: false, switches: startSwitches(), switchesOf: ''
    };
    var panel;
    var body;
    var input;
    var sendButton;
    var picker;
    var threadList;
    var foot;
    var fileInput;
    var fileList;
    var addMenu;
    var addButton;
    var promptList;
    var threadRows;
    var shopBadge;
    var spendBar;
    var spendText;
    var pending = [];
    var switchRows = {};
    var switchList;

    function startSwitches() {
        var set = {};
        SWITCHES.forEach(function (one) {
            set[one.key] = one.start;
        });
        return set;
    }

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

    function icon(name, size) {
        var paths = PROMPT_ICONS[name] || UI_ICONS[name] || PROMPT_ICONS.spark;
        var svg = document.createElementNS(SVG, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('width', String(size || 18));
        svg.setAttribute('height', String(size || 18));
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.8');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('class', 'somcp-icon');
        paths.forEach(function (d) {
            var path = document.createElementNS(SVG, 'path');
            path.setAttribute('d', d);
            svg.appendChild(path);
        });
        return svg;
    }

    // A button that shows only a picture: its name goes to screen readers and
    // to the tooltip.
    function iconButton(name, label, className) {
        var button = el('button', className || '');
        button.type = 'button';
        button.title = label;
        button.setAttribute('aria-label', label);
        button.appendChild(icon(name));
        return button;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // The Markdown assistants write in an answer, on text already escaped:
    // titles, lists, tables, quotes, rules and code. Nothing in it becomes
    // HTML of its own - a <p> the model wrote shows as text - and links are
    // kept only when they are http or https.
    function markdown(value) {
        var lines = escapeHtml(value).split(/\r?\n/);
        var html = '';
        var list = '';
        var i = 0;
        var closeList = function () {
            if (list) {
                html += '</' + list + '>';
                list = '';
            }
        };

        while (i < lines.length) {
            var line = lines[i];
            if (/^\s*```/.test(line)) {
                closeList();
                var code = [];
                i += 1;
                while (i < lines.length && !/^\s*```/.test(lines[i])) {
                    code.push(lines[i]);
                    i += 1;
                }
                html += '<pre><code>' + code.join('\n') + '</code></pre>';
                i += 1;
                continue;
            }
            if (isRow(line) && i + 1 < lines.length && isSeparator(lines[i + 1])) {
                closeList();
                html += table(lines, i);
                i += 2;
                while (i < lines.length && isRow(lines[i])) {
                    i += 1;
                }
                continue;
            }
            var heading = /^\s*(#{1,6})\s+(.*?)\s*#*\s*$/.exec(line);
            if (heading) {
                closeList();
                var level = heading[1].length <= 2 ? 'h3' : 'h4';
                html += '<' + level + '>' + inline(heading[2]) + '</' + level + '>';
                i += 1;
                continue;
            }
            if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(line)) {
                closeList();
                html += '<hr>';
                i += 1;
                continue;
            }
            var quote = /^\s*&gt;\s?(.*)$/.exec(line);
            if (quote) {
                closeList();
                html += '<blockquote>' + inline(quote[1]) + '</blockquote>';
                i += 1;
                continue;
            }
            var bullet = /^\s*[-*+]\s+(.*)$/.exec(line);
            var number = /^\s*\d+[.)]\s+(.*)$/.exec(line);
            if (bullet || number) {
                var kind = bullet ? 'ul' : 'ol';
                if (list !== kind) {
                    closeList();
                    html += '<' + kind + '>';
                    list = kind;
                }
                html += '<li>' + inline((bullet || number)[1]) + '</li>';
                i += 1;
                continue;
            }
            closeList();
            if (line.trim() !== '') {
                html += '<p>' + inline(line) + '</p>';
            }
            i += 1;
        }
        closeList();

        return html;
    }

    function isRow(line) {
        return /^\s*\|.*\|\s*$/.test(line);
    }

    function isSeparator(line) {
        return isRow(line) && cells(line).every(function (cell) {
            return /^:?-+:?$/.test(cell);
        });
    }

    function cells(line) {
        return line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(function (cell) {
            return cell.trim();
        });
    }

    // A table from its header row at start, its separator, and every row
    // after them. Wrapped in a box that scrolls sideways, so a wide table
    // never widens the chat.
    function table(lines, start) {
        var aligns = cells(lines[start + 1]).map(function (cell) {
            if (/^:-+:$/.test(cell)) {
                return 'center';
            }
            return /-:$/.test(cell) ? 'right' : '';
        });
        var row = function (line, tag) {
            return '<tr>' + cells(line).map(function (cell, index) {
                var align = aligns[index] ? ' style="text-align:' + aligns[index] + '"' : '';
                return '<' + tag + align + '>' + inline(cell) + '</' + tag + '>';
            }).join('') + '</tr>';
        };
        var out = '<div class="somcp-table"><table><thead>' + row(lines[start], 'th') + '</thead><tbody>';
        for (var i = start + 2; i < lines.length && isRow(lines[i]); i += 1) {
            out += row(lines[i], 'td');
        }
        return out + '</tbody></table></div>';
    }

    function inline(value) {
        return value
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[\s(])\*([^*\s][^*]*?)\*(?=[\s).,;:!?]|$)/g, '$1<em>$2</em>')
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

    // 1 200 000 reads 1.2M, in the employee's language where the browser
    // knows how to say it.
    function count(value) {
        try {
            return new Intl.NumberFormat(document.documentElement.lang || undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value || 0));
        } catch (e) {
            return String(Number(value || 0));
        }
    }

    function money(value) {
        return '$' + Number(value || 0).toFixed(2);
    }

    function absorb(json) {
        state.view = json.view;
        state.shown = state.view ? state.view.thread_id : '';
        // Taken from the server when another conversation comes on screen
        // only: a switch flipped while an answer is coming is not undone by
        // that answer, and goes with the next message.
        var opened = state.view && state.view.thread_id ? state.view.thread_id : '';
        if (opened !== state.switchesOf) {
            state.switchesOf = opened;
            state.switches = startSwitches();
            SWITCHES.forEach(function (one) {
                if (opened && state.view.switches && one.key in state.view.switches) {
                    state.switches[one.key] = !!state.view.switches[one.key];
                }
            });
        }
        delete state.unread[state.shown];
        delete state.left[state.shown];
        list(json);
        remember(state.shown);
        render();
    }

    // The conversation list and assistants of a reply, without touching the
    // conversation on screen.
    function list(json) {
        state.threads = json.threads || [];
        state.assistants = json.assistants || [];
        state.prompts = json.prompts || state.prompts;
        state.shops = json.shops || [];
        state.pickShop = !!json.pick_shop;
        state.memory = !!json.memory;
    }

    // What the assistant of the conversation on screen can honour: the
    // server says for an open conversation, and the list of assistants
    // tells for one not started yet.
    function capable() {
        var view = state.view;
        if (view && view.thread_id && view.can) {
            return view.can;
        }
        var picked = Number(picker ? picker.value : 0);
        var assistant = null;
        state.assistants.forEach(function (one) {
            if ((picked && one.id === picked) || (!picked && !assistant && one.default)) {
                assistant = one;
            }
        });
        assistant = assistant || (picked ? null : state.assistants[0]) || {};
        return { think: !!assistant.think, web: !!assistant.web, memory: state.memory };
    }

    function switchData(data) {
        SWITCHES.forEach(function (one) {
            data[one.key] = state.switches[one.key] ? '1' : '0';
        });
        return data;
    }

    // Flips a switch, and keeps it on the conversation at once when there is
    // one and nothing is on its way; otherwise it goes with the next message.
    function flip(key) {
        state.switches[key] = !state.switches[key];
        drawSwitches();
        if (state.view && state.view.thread_id && !state.busy) {
            run('switches', switchData(threadData()));
        }
    }

    // The rows of the + menu for what this assistant can do, and a pill under
    // the field for each switch set away from how a conversation starts.
    function drawSwitches() {
        if (!switchList) {
            return;
        }
        var can = capable();
        var any = false;
        switchList.innerHTML = '';
        SWITCHES.forEach(function (one) {
            var row = switchRows[one.key];
            var on = !!state.switches[one.key];
            row.hidden = !can[one.key];
            row.setAttribute('aria-checked', on ? 'true' : 'false');
            any = any || !row.hidden;
            if (!can[one.key] || on === one.start) {
                return;
            }
            var pill = el('span', 'somcp-switch-pill');
            pill.appendChild(icon(one.icon, 14));
            pill.appendChild(el('span', '', text[one.key + 'Pill']));
            var undo = iconButton('close', text.removeSwitch.replace('%name%', text[one.key + 'Pill']));
            undo.addEventListener('click', function () {
                flip(one.key);
            });
            pill.appendChild(undo);
            switchList.appendChild(pill);
        });
        switchRows.think.previousSibling.hidden = !any;
        switchList.hidden = !switchList.childNodes.length;
    }

    function shopOf(idShop) {
        for (var i = 0; i < state.shops.length; i += 1) {
            if (state.shops[i].id === Number(idShop)) {
                return state.shops[i];
            }
        }
        return null;
    }

    // The shop beside each conversation and in the header, only where there
    // is more than one to tell apart.
    function manyShops() {
        return state.shops.length > 1;
    }

    // The conversation on screen is not tied to a shop yet, and the back
    // office is on several: nothing may be sent before one is picked.
    function needsShop(view) {
        return state.pickShop && !(view && view.thread_id);
    }

    // Before another conversation is opened: the checks on the one on screen
    // stop, and if it was still being answered it is watched for its answer
    // rather than pulled back on screen when the answer lands.
    function leave() {
        state.round += 1;
        var view = state.view;
        if (view && view.thread_id && view.status === 'working') {
            state.left[view.thread_id] = true;
            state.watches = 0;
            watch();
        }
    }

    // Reads each conversation left mid-answer, without showing it, and marks
    // it in the list once it is no longer being answered.
    function watch() {
        if (state.watching || !Object.keys(state.left).length || state.watches >= MAX_WATCHES) {
            return;
        }
        state.watching = true;
        window.setTimeout(function () {
            state.watches += 1;
            Promise.all(Object.keys(state.left).map(function (threadId) {
                return request('load', { thread_id: threadId }).then(function (json) {
                    if (json.view && json.view.thread_id === threadId && json.view.status !== 'working'
                        && state.left[threadId] && threadId !== state.shown) {
                        delete state.left[threadId];
                        state.unread[threadId] = true;
                    }
                }, function () {
                    // Gone or unreachable: no longer watched.
                    delete state.left[threadId];
                });
            })).then(function () {
                state.watching = false;
                drawThreads();
                watch();
            });
        }, WATCH_DELAY);
    }

    // What follows a reply on the conversation on screen. A move running on
    // the server is waited for, a step left to take is taken - both in the
    // background: the field stays free and the page does not move.
    function next() {
        var view = state.view;
        if (!view || view.status !== 'working') {
            return;
        }
        if (view.busy) {
            if (state.waits < MAX_WAITS) {
                state.waits += 1;
                follow(view.thread_id, BUSY_DELAY);
            }
            return;
        }
        if (state.steps < MAX_STEPS) {
            state.steps += 1;
            follow(view.thread_id, 0);
        }
    }

    function follow(threadId, delay) {
        state.round += 1;
        var round = state.round;
        var current = function () {
            return round === state.round && threadId === state.shown && !state.busy;
        };
        window.setTimeout(function () {
            if (!current()) {
                return;
            }
            request('step', { thread_id: threadId })
                .then(function (json) {
                    if (!current()) {
                        list(json);
                        drawThreads();
                        return;
                    }
                    absorb(json);
                    next();
                })
                .catch(function () {
                    if (current()) {
                        render(text.failed);
                    }
                });
        }, delay);
    }

    function run(action, data) {
        // Opening, starting or deleting a conversation is never held up by a
        // reply still on its way: that reply goes on on the server, and when
        // it comes back it no longer replaces the conversation on screen.
        var navigating = action === 'load' || action === 'new' || action === 'delete';
        if (state.busy && !navigating) {
            return Promise.resolve();
        }
        if (navigating) {
            leave();
            if (action === 'load') {
                state.shown = data.thread_id || '';
            }
        }
        state.ticket += 1;
        var ticket = state.ticket;
        state.busy = true;
        render();

        return request(action, data)
            .then(function (json) {
                if (ticket !== state.ticket) {
                    list(json);
                    drawThreads();
                    return null;
                }
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
                state.steps = 0;
                state.waits = 0;
                next();
                return null;
            })
            .catch(function () {
                if (ticket !== state.ticket) {
                    return;
                }
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
        // The pages a web search of the chat read, under its step.
        if (message.role === 'sources') {
            return drawSources(message.sources || []);
        }
        if (message.role === 'tools') {
            var wrap = document.createDocumentFragment();
            if (message.reasoning) {
                wrap.appendChild(drawReasoning(message.reasoning));
            }
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
                // A change still waiting for the employee is not done yet: it
                // shows a clock, not the check of a finished step.
                var step = tool.approval === 'rejected' ? 'refused' : (tool.approval === 'pending' ? 'pending' : 'done');
                var chip = el('div', 'somcp-tool somcp-tool-' + step);
                chip.appendChild(icon({ refused: 'close', pending: 'clock', done: 'check' }[step], 14));
                chip.appendChild(el('span', '', label));
                wrap.appendChild(chip);
            });
            return wrap;
        }

        var node = el('div', 'somcp-msg ' + (message.role === 'user' ? 'somcp-msg-user' : 'somcp-msg-assistant'));
        node.innerHTML = markdown(message.text || '');
        if (message.sources && message.sources.length) {
            node.appendChild(drawSources(message.sources));
        }
        (message.images || []).forEach(function (image) {
            node.appendChild(drawImage(image));
        });
        (message.files || []).forEach(function (name) {
            var file = el('div', 'somcp-msg-file');
            file.appendChild(icon('clip', 14));
            file.appendChild(el('span', '', name));
            node.appendChild(file);
        });
        if (message.reasoning) {
            var both = document.createDocumentFragment();
            both.appendChild(drawReasoning(message.reasoning));
            both.appendChild(node);
            addCopy(node, message);
            return both;
        }
        addCopy(node, message);
        return node;
    }

    // What the model thought before answering, folded: it is there to be
    // checked, not read every time.
    function drawReasoning(reasoning) {
        var fold = el('details', 'somcp-reasoning');
        var head = el('summary');
        head.appendChild(icon('bulb', 16));
        head.appendChild(el('span', '', text.thought));
        fold.appendChild(head);
        var said = el('div', 'somcp-reasoning-text');
        said.innerHTML = markdown(reasoning);
        fold.appendChild(said);
        return fold;
    }

    // The web pages an answer rests on, as links built from attributes, never
    // from HTML: their titles come from whoever runs those sites.
    function drawSources(sources) {
        var row = el('div', 'somcp-sources');
        row.appendChild(el('span', 'somcp-sources-label', text.sources));
        sources.forEach(function (source) {
            if (!/^https?:\/\//i.test(source.url)) {
                return;
            }
            var link = el('a', 'somcp-source');
            link.href = source.url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer nofollow';
            link.title = source.url;
            link.appendChild(icon('globe', 12));
            link.appendChild(el('span', '', source.title || source.url));
            row.appendChild(link);
        });
        return row;
    }

    function addCopy(node, message) {
        if (message.role === 'assistant' && message.text && navigator.clipboard) {
            var copy = iconButton('copy', text.copy, 'somcp-copy');
            copy.addEventListener('click', function () {
                navigator.clipboard.writeText(message.text).then(function () {
                    copy.title = text.copied;
                    copy.setAttribute('aria-label', text.copied);
                    copy.classList.add('somcp-copied');
                }, function () {
                    // Refused by the browser: nothing copied, nothing claimed.
                });
            });
            node.appendChild(copy);
        }
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

    // A field name of a tool, as a label: id_order_state reads "Order state (ID)".
    function fieldLabel(key) {
        var label = String(key).replace(/^id_(.+)$/, '$1 (ID)').replace(/_/g, ' ');
        return label.charAt(0).toUpperCase() + label.slice(1);
    }

    // A value given to a tool, in words: yes or no, a list, one line per
    // language for a translated text, and the rest as compact data.
    function fieldValue(value) {
        var out;
        if (value === null || value === undefined) {
            out = '';
        } else if (typeof value === 'boolean') {
            out = value ? text.yes : text.no;
        } else if (typeof value !== 'object') {
            out = String(value);
        } else if (Array.isArray(value) && value.every(function (item) { return item === null || typeof item !== 'object'; })) {
            out = value.join(', ');
        } else if (!Array.isArray(value) && Object.keys(value).length && Object.keys(value).every(function (key) {
            return /^[a-z]{2}(-[A-Za-z]{2})?$/.test(key) && (value[key] === null || typeof value[key] !== 'object');
        })) {
            out = Object.keys(value).map(function (key) {
                return key + ' : ' + value[key];
            }).join('\n');
        } else {
            out = JSON.stringify(value);
        }
        return out.length > 400 ? out.slice(0, 400) + '...' : out;
    }

    function drawApproval(actions) {
        var card = el('section', 'somcp-approval');
        var head = el('div', 'somcp-approval-head');
        head.appendChild(icon('warn'));
        head.appendChild(el('b', '', text.approvalTitle));
        head.appendChild(el('span', '', actions.length === 1 ? text.oneChange : text.changes.replace('%count%', actions.length)));
        card.appendChild(head);

        var content = el('div', 'somcp-approval-body');
        var raw = {};
        actions.forEach(function (action) {
            var block = el('div', 'somcp-approval-action');
            block.appendChild(el('div', 'somcp-approval-title', action.title));
            var inputs = action.inputs || {};
            raw[action.title] = inputs;
            var keys = Object.keys(inputs);
            if (keys.length) {
                var fields = el('dl');
                keys.forEach(function (key) {
                    fields.appendChild(el('dt', '', fieldLabel(key)));
                    fields.appendChild(el('dd', '', fieldValue(inputs[key])));
                });
                block.appendChild(fields);
            }
            content.appendChild(block);
        });
        // The exact call stays one click away, for whoever wants to check
        // what the labels above stand for.
        var details = el('details', 'somcp-approval-raw');
        details.appendChild(el('summary', '', text.technical));
        details.appendChild(el('pre', '', JSON.stringify(raw, null, 2)));
        content.appendChild(details);
        card.appendChild(content);

        var decide = function (verdict) {
            var decisions = {};
            actions.forEach(function (action) {
                decisions[action.id] = verdict;
            });
            run('decide', threadData({ decisions: JSON.stringify(decisions) }));
        };
        var foot = el('div', 'somcp-approval-foot');
        var refuse = el('button', 'somcp-refuse', text.refuse);
        refuse.type = 'button';
        refuse.disabled = state.busy;
        refuse.addEventListener('click', function () {
            decide('reject');
        });
        var approve = el('button', 'somcp-approve', text.approve);
        approve.type = 'button';
        approve.disabled = state.busy;
        approve.addEventListener('click', function () {
            decide('approve');
        });
        foot.appendChild(refuse);
        foot.appendChild(approve);
        card.appendChild(foot);

        return card;
    }

    function render(error) {
        if (!panel) {
            return;
        }
        var view = state.view;
        var working = state.busy || (view && view.status === 'working');
        var problem = error || (view && view.error);

        // Drawn again only when something changed: the background checks
        // bring the same conversation back every two seconds, and redrawing it
        // each time made the page jump. Someone reading higher up stays there.
        var drawn = JSON.stringify([view, working, problem, state.busy, state.prompts, state.shops, state.pickShop]);
        if (drawn !== state.drawn) {
            state.drawn = drawn;
            drawBody(view, working, problem);
        }

        var waiting = view && view.status === 'approval';
        // The field stays free while an answer is coming, so the next message
        // can be written meanwhile; only sending it waits.
        var unpicked = needsShop(view);
        input.disabled = waiting || unpicked;
        input.placeholder = waiting ? text.waiting : (unpicked ? text.pickShop : text.placeholder);
        sendButton.disabled = working || waiting || unpicked;
        addButton.disabled = waiting || unpicked;
        drawShopBadge(view);
        drawPending();
        drawPrompts();
        drawSwitches();

        // The picker and the list are rebuilt only when they changed too: a
        // menu rebuilt under the pointer closes, and a click on a row being
        // replaced is lost.
        var choices = JSON.stringify([state.assistants, view ? view.id_assistant : 0]);
        if (choices === state.choices) {
            drawThreads();
            drawSpent(view);
            return;
        }
        state.choices = choices;
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
        drawSpent(view);
    }

    function drawSpent(view) {
        if (view) {
            // Tokens are exact and carry the cap; the cost is an estimate from
            // public prices, given beside them in the list of conversations.
            var used = view.token_cap > 0
                ? text.tokensOf.replace('%tokens%', count(view.tokens)).replace('%cap%', count(view.token_cap))
                : text.tokens.replace('%tokens%', count(view.tokens));
            foot.lastChild.textContent = used;
            spendText.textContent = used + ' (' + text.estimate.replace('%cost%', money(view.spent)) + ')';
            spendBar.parentNode.hidden = !(view.token_cap > 0);
            spendBar.style.width = view.token_cap > 0 ? Math.min(100, Math.round(view.tokens / view.token_cap * 100)) + '%' : '0';
        }
    }

    // The prompts offered first: those of the page the employee has open,
    // then those of another page, then those tied to none, each group in the
    // order the server listed them.
    function suggestions() {
        return state.prompts.map(function (prompt, index) {
            var rank = prompt.controller === config.page ? 0 : (prompt.controller ? 1 : 2);
            return { prompt: prompt, rank: rank, index: index };
        }).sort(function (a, b) {
            return a.rank - b.rank || a.index - b.index;
        }).slice(0, MAX_SUGGESTIONS).map(function (entry) {
            return entry.prompt;
        });
    }

    // The word shown while the assistant works. It changes every few seconds,
    // so a long step reads as work going on rather than a frozen line.
    function verb() {
        var verbs = text.verbs && text.verbs.length ? text.verbs : [text.working];
        return verbs[state.verb % verbs.length];
    }

    function initials(name) {
        return String(name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(function (word) {
            return word.charAt(0).toUpperCase();
        }).join('') || '#';
    }

    // A new conversation on a back office set to several shops starts by
    // picking its shop: it then acts on that shop and no other.
    function drawShopPicker() {
        var wrap = el('div', 'somcp-welcome');
        wrap.appendChild(el('h2', '', text.chooseShop));
        wrap.appendChild(el('p', '', text.chooseShopHint));
        var grid = el('div', 'somcp-shops');
        state.shops.forEach(function (shop) {
            var card = el('button', 'somcp-shop');
            card.type = 'button';
            card.disabled = state.busy;
            var mark = el('span', 'somcp-shop-mark');
            if (shop.logo) {
                var logo = el('img');
                logo.src = shop.logo;
                logo.alt = '';
                mark.appendChild(logo);
            } else {
                mark.textContent = initials(shop.name);
                mark.classList.add('somcp-shop-initials');
            }
            card.appendChild(mark);
            card.appendChild(el('b', '', shop.name));
            card.appendChild(el('small', '', '#' + shop.id + (shop.domain ? ' · ' + shop.domain : '')));
            card.addEventListener('click', function () {
                run('new', { id_assistant: picker.value, id_shop: shop.id });
            });
            grid.appendChild(card);
        });
        wrap.appendChild(grid);
        return wrap;
    }

    function drawShopBadge(view) {
        var shop = manyShops() && view && view.thread_id ? shopOf(view.id_shop) : null;
        shopBadge.hidden = !shop;
        if (shop) {
            shopBadge.lastChild.textContent = shop.name;
            shopBadge.title = text.conversationFor.replace('%shop%', shop.name);
        }
    }

    function drawWelcome(threadId) {
        var welcome = el('div', 'somcp-welcome');
        var spark = el('div', 'somcp-welcome-mark');
        spark.appendChild(icon('spark', 32));
        welcome.appendChild(spark);
        welcome.appendChild(el('h2', '', opening(threadId)));
        welcome.appendChild(el('p', '', text.subtitle));
        var picks = suggestions();
        if (picks.length) {
            welcome.appendChild(el('div', 'somcp-welcome-label', text.toStart));
            picks.forEach(function (prompt) {
                var pick = el('button', 'somcp-suggestion');
                pick.type = 'button';
                pick.disabled = state.busy;
                pick.appendChild(icon(prompt.icon));
                var words = el('span', 'somcp-suggestion-text');
                words.appendChild(el('b', '', prompt.title));
                words.appendChild(el('small', '', prompt.description));
                pick.appendChild(words);
                pick.addEventListener('click', function () {
                    submit(prompt);
                });
                welcome.appendChild(pick);
            });
        }
        return welcome;
    }

    // The opening line of an empty conversation, drawn once per conversation
    // so it does not change each time the chat redraws it.
    function opening(threadId) {
        // The single line of earlier versions, if the page comes from one: an
        // empty bubble is worse than the old wording.
        var lines = text.hellos || (text.hello ? [text.hello] : []);
        if (!(threadId in state.openings)) {
            state.openings[threadId] = lines.length ? lines[Math.floor(Math.random() * lines.length)] : '';
        }
        return state.openings[threadId];
    }

    function drawBody(view, working, problem) {
        var bottom = body.scrollHeight - body.scrollTop - body.clientHeight < 40;
        var top = body.scrollTop;
        body.innerHTML = '';
        if (needsShop(view)) {
            body.appendChild(drawShopPicker());
        } else if (!view || !view.messages.length) {
            body.appendChild(drawWelcome(view ? view.thread_id : ''));
        }
        if (view) {
            view.messages.forEach(function (message) {
                body.appendChild(drawMessage(message));
            });
            if (view.status === 'approval' && view.actions.length) {
                body.appendChild(drawApproval(view.actions));
            }
        }
        if (working) {
            var typing = el('div', 'somcp-typing');
            typing.appendChild(el('span', 'somcp-dots'));
            typing.appendChild(el('span', 'somcp-verb', verb()));
            var shop = manyShops() && view ? shopOf(view.id_shop) : null;
            if (shop) {
                typing.appendChild(el('span', '', '· ' + shop.name));
            }
            body.appendChild(typing);
        }
        if (problem) {
            body.appendChild(el('div', 'somcp-error', problem));
        }
        body.scrollTop = bottom ? body.scrollHeight : top;
    }

    // Today, yesterday or earlier, from the date the server keeps: its own
    // clock and the browser's agree on the day for everyone but those working
    // across midnight from another time zone.
    function dayOf(date) {
        var day = String(date || '').slice(0, 10);
        var now = new Date();
        var iso = function (moment) {
            return moment.getFullYear() + '-' + ('0' + (moment.getMonth() + 1)).slice(-2) + '-' + ('0' + moment.getDate()).slice(-2);
        };
        if (day === iso(now)) {
            return text.today;
        }
        now.setDate(now.getDate() - 1);
        return day === iso(now) ? text.yesterday : text.earlier;
    }

    // The conversations, in a bar sliding in from the left of the chat: the
    // new one and the search on top, then each conversation under its day,
    // with its own bin.
    function drawThreads() {
        var view = state.view;
        var drawn = JSON.stringify([state.threads, view ? view.thread_id : '', state.unread, state.query, state.shops]);
        if (drawn === state.listed) {
            return;
        }
        state.listed = drawn;
        // The list is folded most of the time: its button carries the mark too.
        panel.classList.toggle('somcp-has-unread', Object.keys(state.unread).length > 0);
        threadRows.innerHTML = '';
        var query = state.query.trim().toLowerCase();
        var shown = state.threads.filter(function (thread) {
            return !query || String(thread.title || '').toLowerCase().indexOf(query) !== -1;
        });
        if (!shown.length && query) {
            threadRows.appendChild(el('div', 'somcp-thread-none', text.noThread));
        }
        var group = '';
        shown.forEach(function (thread) {
            var day = dayOf(thread.date);
            if (day !== group) {
                group = day;
                threadRows.appendChild(el('div', 'somcp-chat-threads-title', day));
            }
            var row = el('div', 'somcp-thread'
                + (view && view.thread_id === thread.thread_id ? ' somcp-current' : '')
                + (state.unread[thread.thread_id] ? ' somcp-unread' : ''));
            var open = el('button', 'somcp-thread-open', thread.title || thread.date);
            open.type = 'button';
            open.title = thread.title || thread.date;
            open.addEventListener('click', function () {
                closeThreads();
                run('load', { thread_id: thread.thread_id });
            });
            var shop = manyShops() ? shopOf(thread.id_shop) : null;
            var badge = null;
            if (shop) {
                badge = el('span', 'somcp-thread-shop', '#' + shop.id);
                badge.title = text.conversationFor.replace('%shop%', shop.name);
                badge.setAttribute('aria-label', badge.title);
            }
            var remove = iconButton('trash', text.deleteThread, 'somcp-thread-delete');
            remove.addEventListener('click', function () {
                if (window.confirm(text.deleteThread + ' ?')) {
                    run('delete', { thread_id: thread.thread_id });
                }
            });
            row.appendChild(open);
            if (badge) {
                row.appendChild(badge);
            }
            row.appendChild(remove);
            threadRows.appendChild(row);
        });
    }

    // Every prompt the employee may start, in the menu of the + button.
    function drawPrompts() {
        var drawn = JSON.stringify(state.prompts);
        if (drawn === state.prompted) {
            return;
        }
        state.prompted = drawn;
        promptList.innerHTML = '';
        state.prompts.forEach(function (prompt) {
            var item = el('button', 'somcp-addmenu-prompt');
            item.type = 'button';
            item.setAttribute('role', 'menuitem');
            item.title = prompt.description;
            item.appendChild(icon(prompt.icon, 16));
            item.appendChild(el('span', '', prompt.title));
            item.addEventListener('click', function () {
                closeMenu();
                submit(prompt);
            });
            promptList.appendChild(item);
        });
        promptList.previousSibling.hidden = !state.prompts.length;
    }

    function openMenu() {
        addMenu.hidden = false;
        addButton.setAttribute('aria-expanded', 'true');
    }

    function closeMenu() {
        if (addMenu && !addMenu.hidden) {
            addMenu.hidden = true;
            addButton.setAttribute('aria-expanded', 'false');
        }
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
        var menu = iconButton('list', text.threads, 'somcp-chat-menu');
        menu.addEventListener('click', function () {
            if (panel.classList.contains('somcp-threads-open')) {
                closeThreads();
            } else {
                openThreads();
            }
        });
        var who = el('div', 'somcp-chat-who');
        var mark = el('span', 'somcp-chat-mark');
        mark.appendChild(icon('spark'));
        who.appendChild(mark);
        who.appendChild(el('b', '', text.title));
        shopBadge = el('span', 'somcp-chat-shop');
        shopBadge.hidden = true;
        shopBadge.appendChild(icon('shop', 14));
        shopBadge.appendChild(el('span', '', ''));
        who.appendChild(shopBadge);
        var widen = iconButton('expand', text.enlarge, 'somcp-widen');
        var setWide = function (wide) {
            panel.classList.toggle('somcp-chat-wide', wide);
            widen.innerHTML = '';
            widen.appendChild(icon(wide ? 'shrink' : 'expand'));
            widen.title = wide ? text.reduce : text.enlarge;
            widen.setAttribute('aria-label', widen.title);
        };
        widen.addEventListener('click', function () {
            var wide = !panel.classList.contains('somcp-chat-wide');
            setWide(wide);
            try {
                window.localStorage.setItem('somcp.assistant.wide', wide ? '1' : '');
            } catch (e) {
                // Not remembered; the next page opens it at its usual size.
            }
        });
        var settings = iconButton('gear', text.settings);
        settings.addEventListener('click', function () {
            if (config.settingsUrl) {
                window.open(config.settingsUrl, '_blank', 'noopener');
            }
        });
        var close = iconButton('close', text.close);
        close.addEventListener('click', toggle);
        head.appendChild(menu);
        head.appendChild(who);
        head.appendChild(settings);
        head.appendChild(widen);
        head.appendChild(close);
        try {
            if (window.localStorage.getItem('somcp.assistant.wide') === '1') {
                setWide(true);
            }
        } catch (e) {
            // Storage refused: the usual size.
        }

        // The conversations: the new one and the search are built once, so
        // the search keeps its focus while the rows under it are redrawn.
        threadList = el('div', 'somcp-chat-threads');
        var fresh = el('button', 'somcp-chat-newthread');
        fresh.type = 'button';
        fresh.appendChild(icon('plus', 16));
        fresh.appendChild(el('span', '', text.newThread));
        fresh.addEventListener('click', function () {
            closeThreads();
            // On several shops, the conversation is created once its shop is
            // picked on the cards; until then nothing is asked of the server.
            if (state.pickShop) {
                leave();
                state.view = null;
                state.shown = '';
                state.switchesOf = '';
                state.switches = startSwitches();
                remember('');
                render();
                return;
            }
            run('new', { id_assistant: picker.value });
        });
        var search = el('input', 'somcp-thread-search');
        search.type = 'search';
        search.placeholder = text.search;
        search.setAttribute('aria-label', text.search);
        search.addEventListener('input', function () {
            state.query = search.value;
            drawThreads();
        });
        threadRows = el('div', 'somcp-thread-rows');
        var spend = el('div', 'somcp-spend');
        spendText = el('div', 'somcp-spend-text');
        var track = el('div', 'somcp-spend-track');
        spendBar = el('div', 'somcp-spend-bar');
        track.appendChild(spendBar);
        var credit = el('div', 'somcp-credit', text.poweredBy + ' ');
        var link = el('a', '', 'Neuron AI');
        link.href = config.neuron;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        credit.appendChild(link);
        spend.appendChild(spendText);
        spend.appendChild(track);
        spend.appendChild(credit);
        threadList.appendChild(fresh);
        threadList.appendChild(search);
        threadList.appendChild(threadRows);
        threadList.appendChild(spend);
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
        input.setAttribute('aria-label', text.placeholder);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                submit();
            }
        });
        input.addEventListener('input', grow);

        // The + opens a menu: files from the computer, or a prompt started
        // from its title.
        addMenu = el('div', 'somcp-addmenu');
        addMenu.hidden = true;
        addMenu.setAttribute('role', 'menu');
        var files = el('button', 'somcp-addmenu-item');
        files.type = 'button';
        files.setAttribute('role', 'menuitem');
        files.title = text.attach;
        files.appendChild(icon('clip'));
        files.appendChild(el('span', '', text.addFiles));
        files.appendChild(el('small', '', text.filesHint));
        files.addEventListener('click', function () {
            closeMenu();
            fileInput.click();
        });
        var promptsLabel = el('div', 'somcp-addmenu-label', text.prompts);
        promptList = el('div', 'somcp-addmenu-prompts');
        addMenu.appendChild(files);
        // The switches come before the prompts: the prompt list scrolls, and
        // a switch below it would be out of sight.
        addMenu.appendChild(el('div', 'somcp-addmenu-rule'));
        SWITCHES.forEach(function (one) {
            var row = el('button', 'somcp-addmenu-item somcp-addmenu-switch');
            row.type = 'button';
            row.setAttribute('role', 'menuitemcheckbox');
            row.appendChild(icon(one.icon));
            var words = el('span', 'somcp-addmenu-words');
            words.appendChild(el('span', '', text[one.key]));
            words.appendChild(el('small', '', text[one.key + 'Hint']));
            row.appendChild(words);
            row.appendChild(el('span', 'somcp-toggle'));
            row.addEventListener('click', function () {
                flip(one.key);
            });
            switchRows[one.key] = row;
            addMenu.appendChild(row);
        });
        addMenu.appendChild(promptsLabel);
        addMenu.appendChild(promptList);

        var actions = el('div', 'somcp-composer-actions');
        addButton = iconButton('plus', text.add, 'somcp-attach');
        addButton.setAttribute('aria-haspopup', 'menu');
        addButton.setAttribute('aria-expanded', 'false');
        addButton.addEventListener('click', function () {
            if (addMenu.hidden) {
                openMenu();
            } else {
                closeMenu();
            }
        });
        picker = el('select', 'somcp-picker');
        picker.setAttribute('aria-label', text.chooseAssistant);
        picker.title = text.chooseAssistant;
        picker.addEventListener('change', function () {
            if (state.view && state.view.thread_id) {
                run('assistant', threadData({ id_assistant: picker.value }));
            } else {
                // Not started yet: the menu follows the assistant picked.
                drawSwitches();
            }
        });
        sendButton = el('button', 'somcp-send');
        sendButton.type = 'submit';
        sendButton.title = text.send;
        sendButton.setAttribute('aria-label', text.send);
        sendButton.appendChild(icon('up'));
        actions.appendChild(addButton);
        actions.appendChild(picker);
        actions.appendChild(el('span', 'somcp-composer-gap'));
        actions.appendChild(fileInput);
        actions.appendChild(sendButton);
        switchList = el('div', 'somcp-chat-switches');
        switchList.hidden = true;
        form.appendChild(addMenu);
        form.appendChild(fileList);
        form.appendChild(input);
        form.appendChild(switchList);
        form.appendChild(actions);
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submit();
        });

        foot = el('div', 'somcp-chat-foot');
        foot.appendChild(el('span', '', text.mistakes));
        foot.appendChild(el('span', '', ''));

        // A click anywhere else in the chat, or Escape, folds the menu.
        panel.addEventListener('click', function (event) {
            if (!addMenu.hidden && !addMenu.contains(event.target) && !addButton.contains(event.target)) {
                closeMenu();
            }
        });
        panel.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !addMenu.hidden) {
                closeMenu();
                addButton.focus();
            }
        });

        window.setInterval(function () {
            var shown = body.querySelector('.somcp-verb');
            if (shown) {
                state.verb += 1;
                shown.textContent = verb();
            }
        }, 2500);

        panel.appendChild(head);
        panel.appendChild(shade);
        panel.appendChild(threadList);
        panel.appendChild(body);
        panel.appendChild(form);
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
            var chip = el('span', 'somcp-file-chip');
            chip.appendChild(icon('clip', 14));
            chip.appendChild(el('span', '', file.name));
            var remove = iconButton('close', text.removeFile);
            remove.addEventListener('click', function () {
                pending.splice(index, 1);
                drawPending();
            });
            chip.appendChild(remove);
            fileList.appendChild(chip);
        });
        fileList.hidden = pending.length === 0;
    }

    // Sends what is in the field, with the files waiting. Given a prompt, its
    // guide goes with the message, and its title stands for an empty field.
    function submit(prompt) {
        var message = input.value.trim();
        if (prompt) {
            message = message || prompt.title;
        }
        if ((!message && !pending.length) || sendButton.disabled) {
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
        state.view = state.view || { thread_id: '', id_assistant: Number(picker.value), messages: [], status: 'idle', actions: [], tokens: 0, token_cap: 0, spent: 0 };
        state.view.messages.push({ role: 'user', text: message, files: files.map(function (file) { return file.name; }) });
        var data = switchData({ text: message, id_assistant: picker.value, files: files });
        if (prompt) {
            data.prompt = prompt.name;
        }
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
