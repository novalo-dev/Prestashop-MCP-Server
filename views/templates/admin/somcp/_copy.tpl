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
            // merchant last had open: they pressed the button to read it. A link
            // naming a tab - the AI agent page sends to its own - opens that one.
            var asked = /[?&]somcp_tab=([a-z]+)/.exec(window.location.search);
            var start = /[?&]somcp_diag=/.test(window.location.search) ? 'config' : (asked ? asked[1] : remembered);
            if (!start || !show(start)) {
                show(links[0].getAttribute('data-somcp-tab'));
            }
        }

        // The server switch saves as soon as it moves, like every on/off switch
        // of the back office: a merchant who turns it off in an emergency must
        // not also have to find a Save button in another tab.
        document.querySelectorAll('[data-somcp-autosave]').forEach(function (field) {
            field.addEventListener('change', function () {
                var form = field.form;
                if (!form) {
                    return;
                }
                var marker = document.createElement('input');
                marker.type = 'hidden';
                marker.name = 'submitSomcpSettings';
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
