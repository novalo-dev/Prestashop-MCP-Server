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

{* The shop addresses with their Copy buttons, shared by the settings page and
   the page shown to an employee who may connect an assistant but not change
   the settings, so both show the same thing the same way. *}
<div class="somcp-endpoints">
    {foreach from=$somcp_endpoints item=endpoint}
        <div class="somcp-endpoint">
            {if $somcp_multistore}
                <div class="somcp-endpoint-shop">
                    {$endpoint.name|escape:'html':'UTF-8'}
                    {if $endpoint.current}
                        <span class="badge nv-badge">{l s='current shop' d='Modules.Somcp.Admin'}</span>
                    {/if}
                </div>
            {/if}
            <div class="input-group">
                <input type="text" id="somcp-endpoint-{$endpoint.id|intval}" class="form-control" readonly
                       value="{$endpoint.url|escape:'html':'UTF-8'}">
                <span class="input-group-btn">
                    <button type="button" class="btn btn-default"
                            data-somcp-copy="somcp-endpoint-{$endpoint.id|intval}">
                        <i class="icon-copy"></i> {l s='Copy' d='Modules.Somcp.Admin'}
                    </button>
                </span>
            </div>
        </div>
    {/foreach}
</div>
