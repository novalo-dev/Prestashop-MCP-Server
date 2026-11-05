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

{* Kept inline rather than in a media file: on PrestaShop 9 the settings page is
   rendered by Symfony, where the legacy controller that addJS() would need is a
   proxy without that method. One small script that runs everywhere beats a
   media file that loads on one version out of two. *}
<script type="text/javascript">
    (function () {
        var copied = '{l s='Copied' d='Modules.Somcp.Admin' js=1}';

        // The server switch saves as soon as it moves, like every on/off switch
        // of the back office: a merchant who turns it off in an emergency must
        // not also have to find a Save button in another tab. A switch that
        // belongs to another form names the action it submits.
        // The menu of a row in the assistants list posts its action through
        // the one hidden form, asking first when the action removes.
        document.querySelectorAll('[data-somcp-post]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                var question = link.getAttribute('data-somcp-question');
                if (question && !window.confirm(question)) {
                    return;
                }
                var form = document.getElementById('somcp-assistant-action');
                if (!form) {
                    return;
                }
                form.querySelector('[name="id_assistant"]').value = link.getAttribute('data-somcp-id');
                var marker = document.createElement('input');
                marker.type = 'hidden';
                marker.name = link.getAttribute('data-somcp-post');
                marker.value = '1';
                form.appendChild(marker);
                form.submit();
            });
        });

        // The images use is offered only for the providers that make
        // images: it follows the provider picked in the form.
        var providerField = document.querySelector('[data-somcp-provider]');
        var imageLevel = document.querySelector('[data-somcp-image-level]');
        if (providerField && imageLevel) {
            var followProvider = function () {
                var offered = imageLevel.getAttribute('data-somcp-image-level').split(' ').indexOf(providerField.value) !== -1;
                // A style, not the hidden attribute: Bootstrap's .radio sets
                // display itself and would win over it.
                imageLevel.style.display = offered ? '' : 'none';
                var radio = imageLevel.querySelector('input');
                if (!offered && radio.checked) {
                    radio.checked = false;
                    var first = document.querySelector('input[name="somcp_assistant_level"]');
                    if (first) {
                        first.checked = true;
                    }
                }
            };
            providerField.addEventListener('change', followProvider);
            followProvider();
        }

        // The default-model row belongs to a chat assistant only: an image
        // one resolves its own model, so the row hides when Images is picked.
        var customRow = document.querySelector('[data-somcp-custom-model]');
        var levelRadios = document.querySelectorAll('input[name="somcp_assistant_level"]');
        if (customRow && levelRadios.length) {
            var followLevel = function () {
                var custom = document.querySelector('input[name="somcp_assistant_level"]:checked');
                customRow.style.display = custom && custom.value === 'custom' ? '' : 'none';
            };
            levelRadios.forEach(function (radio) {
                radio.addEventListener('change', followLevel);
            });
            followLevel();
        }

        // The memory block follows its switch, and the key field appears only
        // for a provider marked special (Voyage), which brings its own key.
        var memSwitch = document.querySelectorAll('[data-somcp-memory-switch] input');
        var memBlock = document.querySelector('[data-somcp-memory-block]');
        var memProvider = document.querySelector('[data-somcp-memory-provider]');
        var memKey = document.querySelector('[data-somcp-memory-key]');
        if (memSwitch.length && memBlock) {
            var followMemory = function () {
                var on = document.getElementById('somcp_emb_on');
                var isOn = on && on.checked;
                memBlock.style.display = isOn ? '' : 'none';
                if (memKey) {
                    var opt = memProvider ? memProvider.options[memProvider.selectedIndex] : null;
                    var special = opt && opt.hasAttribute('data-somcp-special');
                    memKey.style.display = isOn && special ? '' : 'none';
                }
            };
            memSwitch.forEach(function (input) {
                input.addEventListener('change', followMemory);
            });
            if (memProvider) {
                memProvider.addEventListener('change', followMemory);
            }
            followMemory();
        }

        // A removal asks first, in the merchant's language.
        document.querySelectorAll('[data-somcp-confirm]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                if (!window.confirm(button.getAttribute('data-somcp-confirm'))) {
                    event.preventDefault();
                }
            });
        });

        document.querySelectorAll('[data-somcp-autosave]').forEach(function (field) {
            field.addEventListener('change', function () {
                var form = field.form;
                if (!form) {
                    return;
                }
                var marker = document.createElement('input');
                marker.type = 'hidden';
                marker.name = field.getAttribute('data-somcp-autosave') || 'submitSomcpSettings';
                marker.value = '1';
                form.appendChild(marker);
                form.submit();
            });
        });

        // The lists of tools and prompts, a page at a time. Every row stays in
        // the form - hidden, not removed - because the switches of a page
        // nobody looked at must still be submitted. Without a browser the whole
        // list shows, which is the right thing to fall back to. Each list has a
        // panel of its own, which is what scopes the rows to their pager and to
        // the search above them. The contributor cards marked data-somcp-paged
        // go through the same pager, without a search.
        document.querySelectorAll('[data-somcp-pager]').forEach(function (nav) {
            var group = nav.closest('.panel');
            // The grouped tools and prompts lists page by group, below.
            if (group && group.querySelector('[data-somcp-grouped]')) {
                return;
            }
            var rows = group ? Array.prototype.slice.call(group.querySelectorAll('.somcp-tools tbody tr, [data-somcp-paged]')) : [];
            var size = parseInt(nav.getAttribute('data-somcp-pager'), 10) || 5;
            var search = group ? group.querySelector('[data-somcp-search]') : null;

            if (rows.length <= size && !search) {
                return;
            }

            var count = nav.querySelector('.somcp-tools-count');
            var pattern = count ? count.getAttribute('data-somcp-range') : '';
            var filtered = count ? count.getAttribute('data-somcp-filtered') || '' : '';
            var none = search ? search.getAttribute('data-somcp-none') || '' : '';
            var page = 0;

            var draw = function () {
                // Matched on the text the merchant reads - title, name,
                // publisher, permission - so a search finds what the row says.
                var query = search ? search.value.trim().toLowerCase() : '';
                var shown = rows.filter(function (row) {
                    return query === '' || row.textContent.toLowerCase().indexOf(query) !== -1;
                });
                var pages = Math.max(1, Math.ceil(shown.length / size));
                page = Math.min(page, pages - 1);

                rows.forEach(function (row) {
                    row.hidden = true;
                });
                shown.forEach(function (row, i) {
                    row.hidden = i < page * size || i >= (page + 1) * size;
                });

                if (count) {
                    if (shown.length === 0) {
                        count.textContent = none;
                    } else {
                        count.textContent = pattern
                            .replace('%from%', page * size + 1)
                            .replace('%to%', Math.min((page + 1) * size, shown.length))
                            .replace('%total%', shown.length)
                            + (query !== '' && filtered !== '' ? ' ' + filtered.replace('%all%', rows.length) : '');
                    }
                }

                nav.querySelector('[data-somcp-page="-1"]').disabled = page === 0;
                nav.querySelector('[data-somcp-page="1"]').disabled = page >= pages - 1;
                // A list that fits on one page needs no buttons, unless a search
                // is under way: then the line says how many rows it kept.
                nav.hidden = rows.length <= size && query === '';
            };

            nav.querySelectorAll('[data-somcp-page]').forEach(function (button) {
                button.addEventListener('click', function () {
                    page = Math.max(0, page + parseInt(button.getAttribute('data-somcp-page'), 10));
                    draw();
                });
            });

            if (search) {
                search.addEventListener('input', function () {
                    page = 0;
                    draw();
                });
                // Enter in the search would submit the settings form.
                search.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                    }
                });
            }

            draw();
        });

        // A list of host names shown as tags, the way a product's tags are. The
        // original field stays in the form and keeps receiving the list joined
        // by spaces, so the save code and a page without script both see the
        // same value. Built before the inheritance switch below runs, so that
        // switch finds the typing field and disables it along with the rest.
        document.querySelectorAll('input[data-somcp-tags]').forEach(function (source) {
            var box = document.createElement('div');
            var typing = document.createElement('input');
            var suggest = document.querySelector('[data-somcp-tags-suggest="' + source.name + '"]');
            var removeLabel = source.getAttribute('data-somcp-tags-remove') || '';
            var tags = [];

            // The same characters the module keeps when it saves the setting.
            var clean = function (text) {
                return text.toLowerCase().replace(/[^a-z0-9.\-]/g, '');
            };

            var sync = function () {
                source.value = tags.join(' ');
                box.querySelectorAll('.somcp-tag').forEach(function (tag) {
                    tag.remove();
                });
                tags.forEach(function (host, index) {
                    var tag = document.createElement('span');
                    var remove = document.createElement('button');
                    tag.className = 'somcp-tag';
                    tag.textContent = host;
                    remove.type = 'button';
                    remove.className = 'somcp-tag-remove';
                    remove.setAttribute('aria-label', removeLabel + ' ' + host);
                    remove.innerHTML = '&times;';
                    remove.addEventListener('click', function () {
                        if (!source.disabled) {
                            tags.splice(index, 1);
                            sync();
                        }
                    });
                    tag.appendChild(remove);
                    box.insertBefore(tag, typing);
                });
                if (suggest) {
                    suggest.querySelectorAll('[data-somcp-host]').forEach(function (button) {
                        button.hidden = tags.indexOf(button.getAttribute('data-somcp-host')) !== -1;
                    });
                }
            };

            var add = function (text) {
                text.split(/[\s,;]+/).forEach(function (part) {
                    var host = clean(part);
                    if (host !== '' && tags.indexOf(host) === -1) {
                        tags.push(host);
                    }
                });
                sync();
            };

            // Inheritance disables the original field: mirror that on the tags,
            // which are not form fields and would otherwise stay clickable.
            var follow = function () {
                box.classList.toggle('is-disabled', source.disabled);
                typing.disabled = source.disabled;
                if (suggest) {
                    suggest.querySelectorAll('button').forEach(function (button) {
                        button.disabled = source.disabled;
                    });
                }
            };

            box.className = 'somcp-tags form-control';
            typing.type = 'text';
            typing.className = 'somcp-tags-input';
            typing.placeholder = source.getAttribute('data-somcp-tags-placeholder') || '';
            box.appendChild(typing);
            source.type = 'hidden';
            source.parentNode.insertBefore(box, source);

            typing.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ',' || event.key === ' ' || (event.key === 'Tab' && typing.value !== '')) {
                    // Enter would otherwise submit the whole settings form.
                    event.preventDefault();
                    add(typing.value);
                    typing.value = '';
                } else if (event.key === 'Backspace' && typing.value === '' && tags.length) {
                    tags.pop();
                    sync();
                }
            });
            // A host left half typed when the merchant clicks Save is still kept.
            typing.addEventListener('blur', function () {
                add(typing.value);
                typing.value = '';
            });
            typing.addEventListener('paste', function (event) {
                event.preventDefault();
                add((event.clipboardData || window.clipboardData).getData('text'));
            });
            box.addEventListener('click', function (event) {
                if (event.target === box) {
                    typing.focus();
                }
            });

            if (suggest) {
                suggest.querySelectorAll('[data-somcp-host]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        add(button.getAttribute('data-somcp-host'));
                    });
                });
                suggest.hidden = false;
            }

            new MutationObserver(follow).observe(source, { attributes: true, attributeFilter: ['disabled'] });
            add(source.value);
            follow();
        });

        // The sponsor window. The Support us button of the page header is
        // drawn by PrestaShop, which rewrites its id and class and offers no
        // hook of its own, so a click is caught by the address it leads to -
        // the one the window's own button carries, which is left alone. A
        // browser without dialogs follows the link. Escape and a click on the
        // backdrop close it, as anyone expects of a window that only says
        // thank you.
        document.querySelectorAll('[data-somcp-sponsor]').forEach(function (dialog) {
            var own = dialog.querySelector('a[href]');
            var url = own ? own.getAttribute('href') : '';
            if (url && typeof dialog.showModal === 'function') {
                document.addEventListener('click', function (event) {
                    var link = event.target.closest ? event.target.closest('a[href]') : null;
                    if (!link || dialog.contains(link) || link.getAttribute('href') !== url) {
                        return;
                    }
                    event.preventDefault();
                    event.stopPropagation();
                    if (!dialog.open) {
                        dialog.showModal();
                    }
                }, true);
            }

            dialog.querySelectorAll('[data-somcp-sponsor-close]').forEach(function (button) {
                button.addEventListener('click', function () {
                    dialog.close();
                });
            });
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) {
                    dialog.close();
                }
            });
        });

        // The tools and prompts lists, grouped by scope. Each group folds under
        // its heading row, which carries the group's two switches and how many
        // of its rows are on. Every row stays in the form, hidden rather than
        // removed, so a folded group still posts its switches; a search unfolds
        // the groups it finds something in. Without a script the whole list
        // shows, unfolded, which is the right thing to fall back to.
        document.querySelectorAll('[data-somcp-grouped]').forEach(function (table) {
            var panel = table.closest('.panel');
            var search = panel ? panel.querySelector('[data-somcp-search]') : null;
            var nothing = panel ? panel.querySelector('[data-somcp-nothing]') : null;
            var pattern = table.getAttribute('data-somcp-count') || '';
            var offText = table.getAttribute('data-somcp-off') || '';
            var groups = Array.prototype.slice.call(table.querySelectorAll('tbody.somcp-group'));
            var open = {};
            // A few groups a page, counted in rows on the line between the
            // buttons, so the line says the same as on the other lists.
            var nav = panel ? panel.querySelector('[data-somcp-pager]') : null;
            var size = nav ? parseInt(nav.getAttribute('data-somcp-pager'), 10) || 5 : groups.length;
            var counter = nav ? nav.querySelector('.somcp-tools-count') : null;
            var range = counter ? counter.getAttribute('data-somcp-range') || '' : '';
            var filtered = counter ? counter.getAttribute('data-somcp-filtered') || '' : '';
            var allRows = table.querySelectorAll('[data-somcp-row]').length;
            var page = 0;

            var boxesOf = function (group, column) {
                return Array.prototype.slice.call(group.querySelectorAll('[data-somcp-row] [data-somcp-column="' + column + '"] input'));
            };

            var count = function (group) {
                ['on', 'chat'].forEach(function (column) {
                    var boxes = boxesOf(group, column);
                    var on = boxes.filter(function (box) {
                        return box.checked;
                    }).length;
                    var scope = group.querySelector('[data-somcp-scope="' + column + '"]');
                    var label = group.querySelector('[data-somcp-counted="' + column + '"]');
                    if (label) {
                        // A group switched off offers none of its rows, whatever
                        // their own switches say: the line says so instead of
                        // counting switches that have no effect there.
                        label.textContent = scope && !scope.checked
                            ? offText
                            : pattern.replace('%on%', on).replace('%total%', boxes.length);
                    }
                    // The prompts' group switch only reflects its rows: on when
                    // all are, half when some are.
                    var all = group.querySelector('[data-somcp-all="' + column + '"]');
                    if (all) {
                        all.checked = boxes.length > 0 && on === boxes.length;
                        all.indeterminate = on > 0 && on < boxes.length;
                    }
                    // The tools' group switch is a setting of its own: the rows
                    // keep their switch, faded while the group is off.
                    if (scope) {
                        group.classList.toggle('somcp-off-' + column, !scope.checked);
                    }
                });
            };

            var draw = function () {
                // Matched on the text the merchant reads - group, title, name,
                // publisher - so a search finds what the row says.
                var query = search ? search.value.trim().toLowerCase() : '';
                var kept = [];
                var matched = 0;
                groups.forEach(function (group) {
                    var head = group.querySelector('.somcp-group-row');
                    var rows = Array.prototype.slice.call(group.querySelectorAll('[data-somcp-row]'));
                    var whole = query !== '' && head.textContent.toLowerCase().indexOf(query) !== -1;
                    var hits = rows.filter(function (row) {
                        return query === '' || whole || row.textContent.toLowerCase().indexOf(query) !== -1;
                    });
                    if (query === '' || hits.length > 0) {
                        kept.push({ group: group, rows: rows, hits: hits });
                        matched += hits.length;
                    } else {
                        group.hidden = true;
                        rows.forEach(function (row) {
                            row.hidden = true;
                        });
                    }
                });

                var pages = Math.max(1, Math.ceil(kept.length / size));
                page = Math.min(page, pages - 1);
                var first = 0;
                var last = 0;
                var before = 0;
                kept.forEach(function (entry, i) {
                    var onPage = i >= page * size && i < (page + 1) * size;
                    var unfolded = query !== '' || open[entry.group.getAttribute('data-somcp-group')] === true;
                    entry.group.hidden = !onPage;
                    entry.rows.forEach(function (row) {
                        row.hidden = !onPage || !unfolded || entry.hits.indexOf(row) === -1;
                    });
                    var caret = entry.group.querySelector('[data-somcp-expand]');
                    if (caret) {
                        caret.setAttribute('aria-expanded', unfolded ? 'true' : 'false');
                    }
                    if (onPage) {
                        if (first === 0 && entry.hits.length > 0) {
                            first = before + 1;
                        }
                        last = before + entry.hits.length;
                    }
                    before += entry.hits.length;
                });

                if (nothing) {
                    nothing.textContent = search ? search.getAttribute('data-somcp-none') || '' : '';
                    nothing.hidden = kept.length > 0;
                }
                if (nav) {
                    if (counter) {
                        counter.textContent = matched === 0 ? '' : range
                            .replace('%from%', first)
                            .replace('%to%', last)
                            .replace('%total%', matched)
                            + (query !== '' && filtered !== '' ? ' ' + filtered.replace('%all%', allRows) : '');
                    }
                    nav.querySelector('[data-somcp-page="-1"]').disabled = page === 0;
                    nav.querySelector('[data-somcp-page="1"]').disabled = page >= pages - 1;
                    nav.hidden = kept.length <= size;
                }
            };

            if (nav) {
                nav.querySelectorAll('[data-somcp-page]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        page = Math.max(0, page + parseInt(button.getAttribute('data-somcp-page'), 10));
                        draw();
                    });
                });
            }

            groups.forEach(function (group) {
                var key = group.getAttribute('data-somcp-group');
                var caret = group.querySelector('[data-somcp-expand]');
                if (caret) {
                    caret.addEventListener('click', function () {
                        open[key] = caret.getAttribute('aria-expanded') !== 'true';
                        // A click during a search leaves the search for the
                        // whole list, on the page this group sits on.
                        if (search && search.value !== '') {
                            search.value = '';
                            page = Math.floor(groups.indexOf(group) / size);
                        }
                        draw();
                    });
                }
                group.querySelectorAll('[data-somcp-all]').forEach(function (all) {
                    all.addEventListener('change', function () {
                        boxesOf(group, all.getAttribute('data-somcp-all')).forEach(function (box) {
                            if (!box.disabled) {
                                box.checked = all.checked;
                            }
                        });
                        count(group);
                    });
                });
                group.addEventListener('change', function (event) {
                    if (!event.target.hasAttribute('data-somcp-all')) {
                        count(group);
                    }
                });
                count(group);
            });

            if (search) {
                search.addEventListener('input', function () {
                    page = 0;
                    draw();
                });
                // Enter in the search would submit the settings form.
                search.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                    }
                });
            }
            draw();
        });

        // A prompt needs the groups of the tools it guides: switching one of
        // them off in the tools list greys the prompt in that column at once,
        // as the server does once the page is saved. A scope with no switch
        // on the page keeps what the server said.
        (function () {
            var switches = document.querySelectorAll('[data-somcp-scope]');
            var rows = document.querySelectorAll('[data-somcp-needs]');
            if (switches.length === 0 || rows.length === 0) {
                return;
            }
            var refresh = function () {
                rows.forEach(function (row) {
                    var needs = row.getAttribute('data-somcp-needs').split(' ').filter(function (scope) {
                        return scope !== '';
                    });
                    ['on', 'chat'].forEach(function (column) {
                        var cell = row.querySelector('[data-somcp-column="' + column + '"]');
                        if (!cell) {
                            return;
                        }
                        var known = false;
                        var held = needs.some(function (scope) {
                            var box = document.querySelector('[data-somcp-group="' + scope + '"] [data-somcp-scope="' + column + '"]');
                            known = known || box !== null;
                            return box !== null && !box.checked;
                        });
                        if (!known) {
                            return;
                        }
                        cell.classList.toggle('somcp-is-held', held);
                        var label = cell.querySelector('.somcp-held');
                        if (label) {
                            label.hidden = !held;
                        }
                    });
                });
            };
            switches.forEach(function (box) {
                box.addEventListener('change', refresh);
            });
            refresh();
        }());

        // A setting that inherits from the level above is shown but not editable:
        // its value does not belong to this level, and it is not submitted either
        // - the module removes the row instead of writing what was on screen.
        // A setting is its form group, a block marked data-somcp-setting when
        // it spans several groups, or the whole panel for the tool and prompt
        // lists, which have one panel each and no form group.
        document.querySelectorAll('[data-somcp-own]').forEach(function (box) {
            var group = box.closest('[data-somcp-setting], .form-group, .panel');
            if (!group) {
                return;
            }

            var apply = function () {
                group.querySelectorAll('input, select, textarea').forEach(function (field) {
                    // The search of a list only filters the rows on screen:
                    // it stays usable on an inherited list.
                    if (field !== box && !field.hasAttribute('data-somcp-search')) {
                        // A field the server greys for a reason of its own
                        // stays greyed whatever the box says.
                        field.disabled = !box.checked || field.hasAttribute('data-somcp-locked');
                    }
                });
            };

            box.addEventListener('change', apply);
            apply();
        });

        // The assistants of a level: checking the box copies those inherited
        // here at once, unchecking it removes this level's after a question,
        // since their keys go with them.
        document.querySelectorAll('[data-somcp-assistants-own]').forEach(function (box) {
            box.addEventListener('change', function () {
                if (!box.checked && !window.confirm(box.getAttribute('data-somcp-question'))) {
                    box.checked = true;
                    return;
                }
                box.form.submit();
            });
        });

        // The pairing of a local assistant checks itself: every few seconds
        // it asks where the relay stands, and once that moved on - linked,
        // then models found - the page is drawn again at the new step. The
        // wait for models gives up after a few minutes, with Retry to start
        // it again; an expired code has its own Retry, a new code.
        var pairing = document.querySelector('[data-somcp-relay-status]');
        if (pairing) {
            var stage = pairing.getAttribute('data-somcp-relay-stage');
            var statusUrl = pairing.getAttribute('data-somcp-relay-status');
            var aiStep = pairing.querySelector('[data-somcp-relay-ai]');
            var retry = pairing.querySelector('[data-somcp-relay-retry]');
            var gaveUp = pairing.querySelector('[data-somcp-relay-gaveup]');
            var waiting = pairing.querySelector('[data-somcp-relay-waiting]');
            var timer = null;
            var stopAt = 0;
            var check = function () {
                if (stopAt && Date.now() > stopAt) {
                    clearInterval(timer);
                    timer = null;
                    if (aiStep) { aiStep.className = 'is-failed'; }
                    if (waiting) { waiting.hidden = true; }
                    if (gaveUp) { gaveUp.hidden = false; }
                    if (retry) { retry.hidden = false; }
                    return;
                }
                fetch(statusUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function (response) { return response.json(); })
                    .then(function (answer) {
                        if (answer && answer.stage && answer.stage !== stage) {
                            window.location.reload();
                        }
                    })
                    .catch(function () {});
            };
            var start = function () {
                // The code waits until it expires, which the server reports;
                // the models are waited for five minutes.
                stopAt = stage === 'wait' ? Date.now() + 5 * 60 * 1000 : 0;
                if (aiStep && stage === 'wait') { aiStep.className = 'is-checking'; }
                if (waiting) { waiting.hidden = false; }
                if (gaveUp) { gaveUp.hidden = true; }
                if (retry) { retry.hidden = true; }
                if (!timer) { timer = setInterval(check, 3000); }
                check();
            };
            if (stage === 'code' || stage === 'wait') {
                start();
            }
            if (retry) {
                retry.addEventListener('click', start);
            }
        }

        document.querySelectorAll('[data-somcp-copy]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();

                var field = document.getElementById(button.getAttribute('data-somcp-copy'));
                if (!field) {
                    return;
                }

                var label = button.innerHTML;
                var done = function () {
                    button.innerHTML = copied;
                    setTimeout(function () { button.innerHTML = label; }, 1500);
                };

                // execCommand is the fallback: the clipboard API needs a secure
                // context, and a shop opened over plain HTTP has none.
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(field.value).then(done);
                } else {
                    field.select();
                    document.execCommand('copy');
                    done();
                }
            });
        });
    })();
</script>
