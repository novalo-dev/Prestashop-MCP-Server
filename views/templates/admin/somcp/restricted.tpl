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

<div class="somcp nv">
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-lock"></i> {$somcp_restricted_title|escape:'html':'UTF-8'}
        </div>
        <p>{$somcp_restricted_message|escape:'html':'UTF-8'}</p>

        {if $somcp_may_grant}
            <hr>
            <p>{l s='Your account may connect an assistant. Paste this address into it, then approve the connection when the shop asks you to sign in.' d='Modules.Somcp.Admin'}</p>
            {include file='./_endpoints.tpl'}

            {if $somcp_multistore}
                <p class="nv-note">
                    {l s='Each shop has its own address and its own connections. An assistant connected to one shop reaches that shop only.' d='Modules.Somcp.Admin'}
                </p>
            {/if}
        {/if}
    </div>

    {include file='./_copy.tpl'}
</div>
