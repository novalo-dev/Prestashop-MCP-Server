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

{include file='./_style.tpl'}

<div class="somcp-consent nv">
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-upload"></i> {$somcp_title|escape:'html':'UTF-8'}
        </div>

        {if $somcp_state !== 'ask'}
            <div class="alert {if $somcp_state === 'done'}alert-success{else}alert-warning{/if}">
                {$somcp_message|escape:'html':'UTF-8'}
            </div>
        {else}
            <p class="somcp-lead">
                {l s='Your assistant is asking for a file. It is sent straight to your shop - the assistant never sees it.' d='Modules.Somcp.Admin'}
            </p>

            {if $somcp_error}
                <div class="alert alert-danger">{$somcp_error|escape:'html':'UTF-8'}</div>
            {/if}

            <table class="table somcp-facts">
                <tbody>
                    <tr>
                        <th>{l s='What for' d='Modules.Somcp.Admin'}</th>
                        <td><strong>{$somcp_purpose|escape:'html':'UTF-8'}</strong></td>
                    </tr>
                    <tr>
                        <th>{l s='Asked by' d='Modules.Somcp.Admin'}</th>
                        <td>{$somcp_asked_by|escape:'html':'UTF-8'}</td>
                    </tr>
                    <tr>
                        <th>{l s='Signed in as' d='Modules.Somcp.Admin'}</th>
                        <td>{$somcp_employee|escape:'html':'UTF-8'}</td>
                    </tr>
                    <tr>
                        <th>{l s='Shop' d='Modules.Somcp.Admin'}</th>
                        <td>{$somcp_shop|escape:'html':'UTF-8'}</td>
                    </tr>
                    <tr>
                        <th>{l s='Accepted' d='Modules.Somcp.Admin'}</th>
                        <td>
                            {$somcp_extensions|escape:'html':'UTF-8'}
                            <span class="text-muted">
                                &mdash;
                                {if $somcp_max_files > 1}
                                    {l s='up to %count% files' sprintf=['%count%' => $somcp_max_files] d='Modules.Somcp.Admin'},
                                {/if}
                                {l s='%size% each' sprintf=['%size%' => $somcp_max_size] d='Modules.Somcp.Admin'}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>

            {if $somcp_files}
                <h4>{l s='Already sent' d='Modules.Somcp.Admin'}</h4>
                <table class="table somcp-facts">
                    <tbody>
                        {foreach from=$somcp_files item=file}
                            <tr>
                                <td>{$file.name|escape:'html':'UTF-8'}</td>
                                <td class="text-muted">{$file.size|escape:'html':'UTF-8'}</td>
                                <td class="text-right">
                                    <form method="post" action="{$somcp_form_action|escape:'html':'UTF-8'}">
                                        <input type="hidden" name="submitSomcpUpload" value="1">
                                        <input type="hidden" name="ref" value="{$somcp_reference|escape:'html':'UTF-8'}">
                                        <input type="hidden" name="file" value="{$file.id|intval}">
                                        <button type="submit" name="decision" value="remove" class="btn btn-default btn-sm">
                                            {l s='Remove' d='Modules.Somcp.Admin'}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            {/if}

            <form method="post" enctype="multipart/form-data" action="{$somcp_form_action|escape:'html':'UTF-8'}" id="somcp-upload-form">
                <input type="hidden" name="submitSomcpUpload" value="1">
                <input type="hidden" name="ref" value="{$somcp_reference|escape:'html':'UTF-8'}">

                {if $somcp_room_left}
                    <label class="somcp-drop" id="somcp-drop"
                           data-extensions="{$somcp_extension_list|escape:'html':'UTF-8'}"
                           data-max-bytes="{$somcp_max_bytes|intval}"
                           data-max-total="{$somcp_max_total|intval}"
                           data-room="{$somcp_room_for|intval}"
                           data-msg-type="{l s='That file is not one of the accepted kinds.' d='Modules.Somcp.Admin'|escape:'html':'UTF-8'}"
                           data-msg-size="{l s='That file is too large.' d='Modules.Somcp.Admin'|escape:'html':'UTF-8'}"
                           data-msg-room="{l s='You have already sent as many files as were asked for.' d='Modules.Somcp.Admin'|escape:'html':'UTF-8'}"
                           data-msg-total="{l s='Everything together was too large to send at once. Send fewer files at a time.' d='Modules.Somcp.Admin'|escape:'html':'UTF-8'}">
                        <input type="file" name="somcp_file[]" id="somcp-input"
                               accept="{$somcp_accept|escape:'html':'UTF-8'}"{if $somcp_room_for > 1} multiple{/if}>
                        <span class="somcp-drop-title">
                            {if $somcp_room_for > 1}
                                {l s='Drop your files here, or click to choose them' d='Modules.Somcp.Admin'}
                            {else}
                                {l s='Drop your file here, or click to choose it' d='Modules.Somcp.Admin'}
                            {/if}
                        </span>
                        <span class="somcp-drop-hint">
                            {$somcp_extensions|escape:'html':'UTF-8'}
                            &mdash; {l s='%size% each' sprintf=['%size%' => $somcp_max_size] d='Modules.Somcp.Admin'}
                            {if $somcp_room_for > 1}
                                &mdash; {l s='%count% more can still be sent' sprintf=['%count%' => $somcp_room_for] d='Modules.Somcp.Admin'}
                            {/if}
                        </span>
                        <ul class="somcp-chosen" id="somcp-chosen"></ul>
                    </label>
                {else}
                    <div class="alert alert-info">
                        {l s='You have sent everything that was asked for.' d='Modules.Somcp.Admin'}
                    </div>
                {/if}

                <p class="text-muted">
                    {l s='The request is dropped after fifteen minutes, and each file can only be collected once.' d='Modules.Somcp.Admin'}
                </p>

                {* Walking away belongs at the other end of the row. Put next to
                   "send", it is one slip away from the button that does the
                   opposite, and on this page the slip costs the merchant the
                   files they just chose. *}
                <div class="somcp-actions nv-bar-split">
                    <button type="submit" name="decision" value="cancel" class="btn btn-default" formnovalidate>
                        {l s='Send nothing' d='Modules.Somcp.Admin'}
                    </button>
                    {if $somcp_room_left}
                        <button type="submit" name="decision" value="deposit" class="btn btn-primary" id="somcp-send">
                            {l s='Send' d='Modules.Somcp.Admin'}
                        </button>
                    {/if}
                </div>
            </form>

            {* Drag and drop, and the checks the merchant should not have to
               wait for a round trip to be told about. Written out rather than
               pulled from a library: it is a hundred lines, and every rule it
               applies is applied again in PHP - this only spares the upload,
               it never decides anything on its own. *}
            <script type="text/javascript">
            (function () {
                var drop = document.getElementById('somcp-drop');
                var input = document.getElementById('somcp-input');
                var chosen = document.getElementById('somcp-chosen');
                var send = document.getElementById('somcp-send');
                if (!drop || !input || !chosen) {
                    return;
                }

                var allowed = (drop.dataset.extensions || '').split(',').filter(Boolean);
                var maxBytes = parseInt(drop.dataset.maxBytes, 10) || 0;
                var maxTotal = parseInt(drop.dataset.maxTotal, 10) || 0;
                var room = parseInt(drop.dataset.room, 10) || 0;

                function readable(bytes) {
                    return bytes >= 1048576
                        ? (Math.round(bytes / 104857.6) / 10) + ' MB'
                        : Math.max(1, Math.round(bytes / 1024)) + ' kB';
                }

                function why(file, kept, total) {
                    var dot = file.name.lastIndexOf('.');
                    var extension = dot < 0 ? '' : file.name.slice(dot + 1).toLowerCase();

                    if (allowed.indexOf(extension) === -1) {
                        return drop.dataset.msgType;
                    }
                    if (maxBytes && file.size > maxBytes) {
                        return drop.dataset.msgSize;
                    }
                    if (room && kept >= room) {
                        return drop.dataset.msgRoom;
                    }
                    if (maxTotal && total + file.size > maxTotal) {
                        return drop.dataset.msgTotal;
                    }

                    return '';
                }

                // What the merchant has gathered so far. The input is only
                // the transport: they pick one file, then another, and expect
                // both to go - so each pick is added to this rather than
                // replacing it.
                var pending = [];

                function seen(file) {
                    return pending.some(function (kept) {
                        return kept.name === file.name && kept.size === file.size;
                    });
                }

                function render() {
                    var keep = new DataTransfer();
                    var total = 0;

                    chosen.innerHTML = '';

                    pending.forEach(function (file, index) {
                        // A rejected file is left out of what is sent rather
                        // than blocking the others: a merchant who picked four
                        // things and got one wrong should still send the three.
                        var refusal = why(file, keep.items.length, total);
                        var line = document.createElement('li');

                        if (refusal === '') {
                            keep.items.add(file);
                            total += file.size;
                        } else {
                            line.className = 'somcp-rejected';
                        }

                        var label = document.createElement('span');
                        label.textContent = refusal === ''
                            ? file.name + ' - ' + readable(file.size)
                            : file.name + ' - ' + refusal;
                        line.appendChild(label);

                        var remove = document.createElement('button');
                        remove.type = 'button';
                        remove.className = 'somcp-drop-one';
                        remove.textContent = '×';
                        remove.setAttribute('aria-label', file.name);
                        remove.addEventListener('click', function (event) {
                            event.preventDefault();
                            event.stopPropagation();
                            pending.splice(index, 1);
                            render();
                        });
                        line.appendChild(remove);

                        chosen.appendChild(line);
                    });

                    input.files = keep.files;
                    if (send) {
                        send.disabled = keep.items.length === 0;
                    }
                }

                function gather(files) {
                    for (var i = 0; i < files.length; i++) {
                        if (!seen(files[i])) {
                            pending.push(files[i]);
                        }
                    }
                    render();
                }

                input.addEventListener('change', function () {
                    gather(input.files);
                });

                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function (name) {
                    drop.addEventListener(name, function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                    });
                });

                ['dragenter', 'dragover'].forEach(function (name) {
                    drop.addEventListener(name, function () {
                        drop.classList.add('is-over');
                    });
                });

                ['dragleave', 'drop'].forEach(function (name) {
                    drop.addEventListener(name, function () {
                        drop.classList.remove('is-over');
                    });
                });

                drop.addEventListener('drop', function (event) {
                    if (event.dataTransfer && event.dataTransfer.files.length) {
                        gather(event.dataTransfer.files);
                    }
                });

                if (send) {
                    send.disabled = true;
                }
            })();
            </script>
        {/if}
    </div>
</div>
