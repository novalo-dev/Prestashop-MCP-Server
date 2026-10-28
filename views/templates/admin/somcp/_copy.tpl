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

        // Tabs. Every pane stays in the page - hidden, not removed - because the
        // settings form spans two of them and must post all its fields from
        // whichever one holds the Save button. The tab survives the reload that
        // follows every save, so a merchant lands back where they were.
        var tabs = document.querySelector('.somcp-tabs');
        if (tabs) {
            var links = tabs.querySelectorAll('[data-somcp-tab]');
            var panes = document.querySelectorAll('[data-somcp-pane]');
            var remembered = null;

            try {
                remembered = window.sessionStorage.getItem('somcp.tab');
            } catch (e) {
                // Storage refused (private window, blocked site data): start on
                // the first tab, which is what a new visit does anyway.
            }

            var show = function (name) {
                var found = false;
                // Both the link and its item carry the state: PrestaShop's
                // Bootstrap 3 back office styles li.active, Bootstrap 4 styles
                // a.nav-link.active.
                links.forEach(function (link) {
                    var on = link.getAttribute('data-somcp-tab') === name;
                    link.classList.toggle('active', on);
                    link.parentNode.classList.toggle('active', on);
                    link.setAttribute('aria-selected', on ? 'true' : 'false');
                    found = found || on;
                });
                if (!found) {
                    return false;
                }
                panes.forEach(function (pane) {
                    pane.hidden = pane.getAttribute('data-somcp-pane') !== name;
                });
                try {
                    window.sessionStorage.setItem('somcp.tab', name);
                } catch (e) {
                    // Not remembered; nothing else depends on it.
                }
                return true;
            };

            links.forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    show(link.getAttribute('data-somcp-tab'));
                });
            });

            // A diagnostic just run is shown on the first tab, whatever tab the
            // merchant last had open: they pressed the button to read it.
            // The assistant form opens on its own tab: the merchant clicked
            // Add or Edit there, whatever tab was remembered before.
            var start = /[?&]somcp_diag=/.test(window.location.search) ? 'config'
                : (/[?&]somcp_assistant_(edit|add)=/.test(window.location.search) ? 'assistant' : remembered);
            if (!start || !show(start)) {
                show(links[0].getAttribute('data-somcp-tab'));
            }
        }

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

        // The images level is offered only for the providers that make
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

        // The exact-model row belongs to the Custom level only: it follows the
        // level radios, shown when Custom is picked and hidden otherwise.
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

        // The list of tools, a page at a time. Every row stays in the form -
        // hidden, not removed - because the checkboxes of a page nobody looked
        // at must still be submitted. Without a browser the whole list shows,
        // which is the right thing to fall back to.
        document.querySelectorAll('[data-somcp-pager]').forEach(function (nav) {
            var group = nav.closest('.form-group');
            var rows = group ? group.querySelectorAll('.somcp-tools tbody tr') : [];
            var size = parseInt(nav.getAttribute('data-somcp-pager'), 10) || 5;
            var total = rows.length;

            if (total <= size) {
                return;
            }

            var count = nav.querySelector('.somcp-tools-count');
            var pattern = count ? count.getAttribute('data-somcp-range') : '';
            var pages = Math.ceil(total / size);
            var page = 0;

            var draw = function () {
                for (var i = 0; i < total; i++) {
                    rows[i].hidden = i < page * size || i >= (page + 1) * size;
                }

                var from = page * size + 1;
                var to = Math.min((page + 1) * size, total);

                if (count) {
                    count.textContent = pattern
                        .replace('%from%', from)
                        .replace('%to%', to)
                        .replace('%total%', total);
                }

                nav.querySelector('[data-somcp-page="-1"]').disabled = page === 0;
                nav.querySelector('[data-somcp-page="1"]').disabled = page >= pages - 1;
            };

            nav.querySelectorAll('[data-somcp-page]').forEach(function (button) {
                button.addEventListener('click', function () {
                    page = Math.min(pages - 1, Math.max(0, page + parseInt(button.getAttribute('data-somcp-page'), 10)));
                    draw();
                });
            });

            nav.hidden = false;
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

        // The sponsor window. Escape and a click on the backdrop close it too,
        // as anyone expects of a window that only says thank you.
        document.querySelectorAll('[data-somcp-sponsor]').forEach(function (dialog) {
            var open = document.querySelector('[data-somcp-sponsor-open]');
            // A browser without dialog support would show it inline for good:
            // the button goes rather than leaving a window that cannot close.
            if (!open || typeof dialog.showModal !== 'function') {
                if (open) {
                    open.hidden = true;
                }
                return;
            }
            open.addEventListener('click', function () {
                dialog.showModal();
            });
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

        // A setting that inherits from the level above is shown but not editable:
        // its value does not belong to this level, and it is not submitted either
        // - the module removes the row instead of writing what was on screen.
        document.querySelectorAll('[data-somcp-own]').forEach(function (box) {
            var group = box.closest('.form-group');
            if (!group) {
                return;
            }

            var apply = function () {
                group.querySelectorAll('input, select, textarea').forEach(function (field) {
                    if (field !== box) {
                        field.disabled = !box.checked;
                    }
                });
            };

            box.addEventListener('change', apply);
            apply();
        });

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
