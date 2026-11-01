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

<div class="somcp">

    {foreach from=$somcp_confirmations item=confirmation}
        <div class="alert alert-success">{$confirmation|escape:'html':'UTF-8'}</div>
    {/foreach}
    {foreach from=$somcp_errors item=error}
        <div class="alert alert-danger">{$error|escape:'html':'UTF-8'}</div>
    {/foreach}

    {* One page of the module per pane, each its own entry under the module in
       the back office menu: PrestaShop draws the tabs under the page title,
       as on Contacts and Stores. Every pane is still written into every page,
       hidden but not removed, because the settings form spans two of them and
       must post all its fields from whichever page holds the Save button. *}
    <div class="somcp-pane" id="somcp-pane-config" data-somcp-pane="config" role="tabpanel"{if $somcp_pane !== 'config'} hidden{/if}>

        {* The switch leads the first tab, where a merchant looks first. Its fields
           belong to the settings form through the form attribute: that form sits
           in two other tabs, and forms cannot nest. *}
        <div class="panel">
            <div class="panel-heading"><i class="icon-power-off"></i> {l s='MCP server' d='Modules.Somcp.Admin'}</div>
            <div class="form-horizontal">
                <div class="form-group">
                    <label class="control-label col-lg-4">{l s='Enable the MCP server' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-8">
                        {include file='./_inherit.tpl' field='SOMCP_ENABLED' form='somcp-settings'}
                        <span class="switch prestashop-switch fixed-width-lg">
                            <input type="radio" form="somcp-settings" data-somcp-autosave name="SOMCP_ENABLED" id="SOMCP_ENABLED_on" value="1"{if $somcp_settings.SOMCP_ENABLED === '1'} checked{/if}>
                            <label for="SOMCP_ENABLED_on">{l s='Yes' d='Modules.Somcp.Admin'}</label>
                            <input type="radio" form="somcp-settings" data-somcp-autosave name="SOMCP_ENABLED" id="SOMCP_ENABLED_off" value="0"{if $somcp_settings.SOMCP_ENABLED !== '1'} checked{/if}>
                            <label for="SOMCP_ENABLED_off">{l s='No' d='Modules.Somcp.Admin'}</label>
                            <a class="slide-button btn"></a>
                        </span>
                        <p class="help-block">{l s='Turning this off closes the endpoint immediately. Tokens already issued stop working.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-heading"><i class="icon-link"></i> {l s='Address to give to your assistant' d='Modules.Somcp.Admin'}</div>
            <p>{l s='Paste this address into Claude, ChatGPT, Le Chat or any other MCP-compatible assistant. It will ask you to sign in to this shop and to approve the connection.' d='Modules.Somcp.Admin'}</p>
        {include file='./_endpoints.tpl'}

        <p class="somcp-note">
            {if $somcp_multistore}
                {l s='Each shop has its own address and its own connections. An assistant connected to one shop reaches that shop only.' d='Modules.Somcp.Admin'}
            {else}
                {l s='Authorization server:' d='Modules.Somcp.Admin'} <code>{$somcp_issuer|escape:'html':'UTF-8'}</code>
            {/if}
        </p>
            <p class="somcp-note">{l s='OAuth 2.1: every connection is approved by an employee, and an assistant never gets more rights than that employee.' d='Modules.Somcp.Admin'}</p>
        </div>

        <div class="panel">
            <div class="panel-heading"><i class="icon-stethoscope"></i> {l s='Diagnostic' d='Modules.Somcp.Admin'}</div>
        <p>{l s='Checks one shop the way an assistant would: it calls that shop own addresses from the outside and reports what comes back.' d='Modules.Somcp.Admin'}</p>

        {if $somcp_diagnostic}
            <table class="table somcp-diagnostic">
                <tbody>
                {foreach from=$somcp_diagnostic item=check}
                    <tr>
                        <td class="somcp-status somcp-status-{$check.status|escape:'html':'UTF-8'}">
                            {if $check.status === 'ok'}<i class="icon-check"></i>
                            {elseif $check.status === 'warning'}<i class="icon-warning"></i>
                            {elseif $check.status === 'skipped'}<i class="icon-minus"></i>
                            {else}<i class="icon-remove"></i>{/if}
                        </td>
                        <th>{$check.label|escape:'html':'UTF-8'}</th>
                        <td>
                            {$check.detail|escape:'html':'UTF-8'}
                            {if $check.url}<br><code>{$check.url|escape:'html':'UTF-8'}</code>{/if}
                            {if $check.fix}<br><a href="{$check.fix|escape:'html':'UTF-8'}" target="_blank" rel="noopener">{l s='Go to the setting' d='Modules.Somcp.Admin'} <i class="icon-external-link"></i></a>{/if}
                        </td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        {/if}

        <form method="post" action="{$somcp_form_action|escape:'html':'UTF-8'}" class="somcp-diag-run">
            {if $somcp_diag_pick}
                <select name="somcp_diag_shop" class="form-control fixed-width-xl">
                    {foreach from=$somcp_diag_shops item=candidate}
                        <option value="{$candidate.id|intval}"{if $candidate.id == $somcp_diag_shop} selected{/if}>
                            {$candidate.name|escape:'html':'UTF-8'}
                        </option>
                    {/foreach}
                </select>
            {/if}
            <button type="submit" name="submitSomcpDiagnostic" value="1" class="btn btn-default">
                {l s='Run the diagnostic' d='Modules.Somcp.Admin'}
            </button>
        </form>
        </div>
    </div>

    <form method="post" action="{$somcp_form_action|escape:'html':'UTF-8'}" class="defaultForm form-horizontal" id="somcp-settings">

        {* Each list in a panel of its own, the full width of the page like the
           Connected assistants table: in a form column beside a label the
           tables were squeezed into half the page. The three panels stay in the
           one settings form, so the single Save button at the bottom posts the
           switches of both lists with the rest. *}
        <div class="somcp-pane" id="somcp-pane-tools" data-somcp-pane="tools" role="tabpanel"{if $somcp_pane !== 'tools'} hidden{/if}>
            <div class="panel">
                <div class="panel-heading"><i class="icon-wrench"></i> {l s='Available tools' d='Modules.Somcp.Admin'} <span class="badge">{$somcp_tools_count|intval}</span></div>
                {include file='./_inherit.tpl' field='SOMCP_TOOLS_OFF'}
                <p class="help-block">{l s='What an assistant may ask this shop to do. A tool switched off here is offered to nobody, whatever permissions their account holds. Tools published by another module carry that module name in front of theirs.' d='Modules.Somcp.Admin'}</p>
                {if $somcp_tools}
                    <div class="somcp-tools-scroll">
                    <table class="table somcp-tools">
                        <thead>
                        <tr>
                            <th class="somcp-tool-on">{l s='On' d='Modules.Somcp.Admin'}</th>
                            <th>{l s='Tool' d='Modules.Somcp.Admin'}</th>
                            <th>{l s='Published by' d='Modules.Somcp.Admin'}</th>
                            <th>{l s='Requires' d='Modules.Somcp.Admin'}</th>
                        </tr>
                        </thead>
                        <tbody>
                        {foreach from=$somcp_tools item=tool}
                            <tr>
                                <td class="somcp-tool-on">
                                    <input type="checkbox" name="somcp_tool_on[{$tool.name|escape:'html':'UTF-8'}]"
                                           value="1"{if $tool.on} checked{/if}>
                                </td>
                                <td>
                                    <strong>{$tool.title|escape:'html':'UTF-8'}</strong>
                                    {if $tool.writes}
                                        <span class="badge somcp-writes">{l s='changes data' d='Modules.Somcp.Admin'}</span>
                                    {/if}
                                    <br><code>{$tool.name|escape:'html':'UTF-8'}</code>
                                </td>
                                <td>
                                    {if $tool.module}<code>{$tool.module|escape:'html':'UTF-8'}</code>
                                    {else}{l s='This module' d='Modules.Somcp.Admin'}{/if}
                                </td>
                                <td>
                                    {if $tool.permission}{$tool.permission|escape:'html':'UTF-8'}
                                    {else}<span class="text-muted">{l s='no particular permission' d='Modules.Somcp.Admin'}</span>{/if}
                                </td>
                            </tr>
                        {/foreach}
                        </tbody>
                    </table>
                    </div>
                    {* Paged in the browser, with every row left in the form.
                       Paging on the server would post only the boxes on
                       screen, and saving would switch off every tool on the
                       other pages. *}
                    <div class="somcp-tools-nav" data-somcp-pager="5" hidden>
                        <button type="button" class="btn btn-default btn-sm" data-somcp-page="-1">
                            &laquo; {l s='Previous' d='Modules.Somcp.Admin'}
                        </button>
                        <span class="somcp-tools-count"
                              data-somcp-range="{l s='Tools %from% to %to% of %total%' d='Modules.Somcp.Admin' js=1}"></span>
                        <button type="button" class="btn btn-default btn-sm" data-somcp-page="1">
                            {l s='Next' d='Modules.Somcp.Admin'} &raquo;
                        </button>
                    </div>
                {else}
                    <p class="text-muted">{l s='No tool is available on this shop.' d='Modules.Somcp.Admin'}</p>
                {/if}
            </div>

            <div class="panel">
                <div class="panel-heading"><i class="icon-comments"></i> {l s='Available prompts' d='Modules.Somcp.Admin'} <span class="badge">{$somcp_prompts_count|intval}</span></div>
                {include file='./_inherit.tpl' field='SOMCP_PROMPTS_OFF'}
                <p class="help-block">{l s='A prompt is a guided task you start yourself from your assistant menu. It costs nothing until you pick it, then hands the assistant step-by-step instructions for using the tools. A prompt published by another module puts that module words into your assistant, which is why you can switch each one off here.' d='Modules.Somcp.Admin'}</p>
                {if $somcp_prompts}
                    <div class="somcp-tools-scroll">
                    <table class="table somcp-tools">
                        <thead>
                        <tr>
                            <th class="somcp-tool-on">{l s='On' d='Modules.Somcp.Admin'}</th>
                            <th>{l s='Prompt' d='Modules.Somcp.Admin'}</th>
                            <th>{l s='Published by' d='Modules.Somcp.Admin'}</th>
                            <th>{l s='Asks for' d='Modules.Somcp.Admin'}</th>
                            <th>{l s='Requires' d='Modules.Somcp.Admin'}</th>
                        </tr>
                        </thead>
                        <tbody>
                        {foreach from=$somcp_prompts item=prompt}
                            <tr>
                                <td class="somcp-tool-on">
                                    <input type="checkbox" name="somcp_prompt_on[{$prompt.name|escape:'html':'UTF-8'}]"
                                           value="1"{if $prompt.on} checked{/if}>
                                </td>
                                <td>
                                    <strong>{$prompt.title|escape:'html':'UTF-8'}</strong>
                                    <br><code>{$prompt.name|escape:'html':'UTF-8'}</code>
                                    <br><span class="text-muted">{$prompt.description|escape:'html':'UTF-8'}</span>
                                </td>
                                <td>
                                    {if $prompt.module}<code>{$prompt.module|escape:'html':'UTF-8'}</code>
                                    {else}{l s='This module' d='Modules.Somcp.Admin'}{/if}
                                </td>
                                <td>
                                    {if $prompt.asks}<code>{$prompt.asks|escape:'html':'UTF-8'}</code>
                                    {else}<span class="text-muted">{l s='nothing' d='Modules.Somcp.Admin'}</span>{/if}
                                </td>
                                <td>
                                    {if $prompt.permission}{$prompt.permission|escape:'html':'UTF-8'}
                                    {else}<span class="text-muted">{l s='no particular permission' d='Modules.Somcp.Admin'}</span>{/if}
                                </td>
                            </tr>
                        {/foreach}
                        </tbody>
                    </table>
                    </div>
                    <div class="somcp-tools-nav" data-somcp-pager="5" hidden>
                        <button type="button" class="btn btn-default btn-sm" data-somcp-page="-1">
                            &laquo; {l s='Previous' d='Modules.Somcp.Admin'}
                        </button>
                        <span class="somcp-tools-count"
                              data-somcp-range="{l s='Prompts %from% to %to% of %total%' d='Modules.Somcp.Admin' js=1}"></span>
                        <button type="button" class="btn btn-default btn-sm" data-somcp-page="1">
                            {l s='Next' d='Modules.Somcp.Admin'} &raquo;
                        </button>
                    </div>
                {else}
                    <p class="text-muted">{l s='No prompt is available on this shop.' d='Modules.Somcp.Admin'}</p>
                {/if}
            </div>

            <div class="panel">
                <div class="panel-heading"><i class="icon-cogs"></i> {l s='Settings' d='Modules.Somcp.Admin'}</div>
                <div class="form-group">
                    <label class="control-label col-lg-4">{l s='How long an assistant keeps the list of tools' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-8">
                        {include file='./_inherit.tpl' field='SOMCP_TOOLS_TTL'}
                        <input type="number" min="10" max="3600" name="SOMCP_TOOLS_TTL" class="form-control fixed-width-xl"
                               value="{$somcp_settings.SOMCP_TOOLS_TTL|escape:'html':'UTF-8'}">
                        <p class="help-block">{l s='In seconds, between 10 and 3600. This shop cannot tell an assistant that the list changed - there is no connection open to it - so the assistant re-reads only when this has run out. A tool switched on or off appears or disappears after that delay, not before. Lower it while you are adding tools, raise it back afterwards. 300 by default.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>

                <div class="panel-footer">
                    <button type="submit" name="submitSomcpSettings" value="1" class="btn btn-default pull-right">
                        <i class="process-icon-save"></i> {l s='Save' d='Modules.Somcp.Admin'}
                    </button>
                </div>
            </div>
        </div>

        <div class="somcp-pane" id="somcp-pane-access" data-somcp-pane="access" role="tabpanel"{if $somcp_pane !== 'access'} hidden{/if}>
            <div class="panel">
                <div class="panel-heading"><i class="icon-lock"></i> {l s='Access and security' d='Modules.Somcp.Admin'}</div>
                <div class="form-group">
                    <label class="control-label col-lg-4">{l s='Employees allowed to connect an assistant' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-8">
                        {include file='./_inherit.tpl' field='SOMCP_REQUIRED_PROFILE'}
                        <select name="SOMCP_REQUIRED_PROFILE" class="form-control fixed-width-xl">
                            <option value="0"{if $somcp_settings.SOMCP_REQUIRED_PROFILE === '0'} selected{/if}>
                                {l s='Any employee' d='Modules.Somcp.Admin'}
                            </option>
                            {foreach from=$somcp_profiles item=profile}
                                <option value="{$profile.id_profile|intval}"{if $somcp_settings.SOMCP_REQUIRED_PROFILE == $profile.id_profile} selected{/if}>
                                    {$profile.name|escape:'html':'UTF-8'}
                                </option>
                            {/foreach}
                        </select>
                        <p class="help-block">{l s='An assistant never gets more rights than the employee who connected it.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>

                <hr>
                <h4>{l s='OAuth 2.1' d='Modules.Somcp.Admin'}</h4>
                <div class="form-group">
                    <label class="control-label col-lg-4">{l s='Accept new connections' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-8">
                        {include file='./_inherit.tpl' field='SOMCP_OAUTH_ENABLED'}
                        <span class="switch prestashop-switch fixed-width-lg">
                            <input type="radio" name="SOMCP_OAUTH_ENABLED" id="SOMCP_OAUTH_ENABLED_on" value="1"{if $somcp_settings.SOMCP_OAUTH_ENABLED === '1'} checked{/if}>
                            <label for="SOMCP_OAUTH_ENABLED_on">{l s='Yes' d='Modules.Somcp.Admin'}</label>
                            <input type="radio" name="SOMCP_OAUTH_ENABLED" id="SOMCP_OAUTH_ENABLED_off" value="0"{if $somcp_settings.SOMCP_OAUTH_ENABLED !== '1'} checked{/if}>
                            <label for="SOMCP_OAUTH_ENABLED_off">{l s='No' d='Modules.Somcp.Admin'}</label>
                            <a class="slide-button btn"></a>
                        </span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-4">{l s='Access token lifetime (seconds)' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-8">
                        {include file='./_inherit.tpl' field='SOMCP_ACCESS_TTL'}
                        <input type="number" min="60" name="SOMCP_ACCESS_TTL" class="form-control fixed-width-xl" value="{$somcp_settings.SOMCP_ACCESS_TTL|escape:'html':'UTF-8'}">
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-4">{l s='Refresh token lifetime (seconds)' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-8">
                        {include file='./_inherit.tpl' field='SOMCP_REFRESH_TTL'}
                        <input type="number" min="600" name="SOMCP_REFRESH_TTL" class="form-control fixed-width-xl" value="{$somcp_settings.SOMCP_REFRESH_TTL|escape:'html':'UTF-8'}">
                        <p class="help-block">{l s='How long an assistant can keep reconnecting on its own before the merchant has to approve it again.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-4">{l s='Replace the refresh token on every use' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-8">
                        {include file='./_inherit.tpl' field='SOMCP_REFRESH_ROTATION'}
                        <span class="switch prestashop-switch fixed-width-lg">
                            <input type="radio" name="SOMCP_REFRESH_ROTATION" id="SOMCP_REFRESH_ROTATION_on" value="1"{if $somcp_settings.SOMCP_REFRESH_ROTATION === '1'} checked{/if}>
                            <label for="SOMCP_REFRESH_ROTATION_on">{l s='Yes' d='Modules.Somcp.Admin'}</label>
                            <input type="radio" name="SOMCP_REFRESH_ROTATION" id="SOMCP_REFRESH_ROTATION_off" value="0"{if $somcp_settings.SOMCP_REFRESH_ROTATION !== '1'} checked{/if}>
                            <label for="SOMCP_REFRESH_ROTATION_off">{l s='No' d='Modules.Somcp.Admin'}</label>
                            <a class="slide-button btn"></a>
                        </span>
                        <p class="help-block">{l s='Recommended. A token that is used twice then reveals a theft, and the shop cuts the whole connection.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>

                <hr>
                <h4>{l s='Security' d='Modules.Somcp.Admin'}</h4>
                <div class="form-group">
                    <label class="control-label col-lg-4">{l s='Let applications identify themselves by address' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-8">
                        {include file='./_inherit.tpl' field='SOMCP_CIMD_ENABLED'}
                        <span class="switch prestashop-switch fixed-width-lg">
                            <input type="radio" name="SOMCP_CIMD_ENABLED" id="SOMCP_CIMD_ENABLED_on" value="1"{if $somcp_settings.SOMCP_CIMD_ENABLED === '1'} checked{/if}>
                            <label for="SOMCP_CIMD_ENABLED_on">{l s='Yes' d='Modules.Somcp.Admin'}</label>
                            <input type="radio" name="SOMCP_CIMD_ENABLED" id="SOMCP_CIMD_ENABLED_off" value="0"{if $somcp_settings.SOMCP_CIMD_ENABLED !== '1'} checked{/if}>
                            <label for="SOMCP_CIMD_ENABLED_off">{l s='No' d='Modules.Somcp.Admin'}</label>
                            <a class="slide-button btn"></a>
                        </span>
                        <p class="help-block">{l s='This is how the Claude assistants connect. Turning it off will stop them from working.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-4">{l s='Let applications register themselves' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-8">
                        {include file='./_inherit.tpl' field='SOMCP_DCR_ENABLED'}
                        <span class="switch prestashop-switch fixed-width-lg">
                            <input type="radio" name="SOMCP_DCR_ENABLED" id="SOMCP_DCR_ENABLED_on" value="1"{if $somcp_settings.SOMCP_DCR_ENABLED === '1'} checked{/if}>
                            <label for="SOMCP_DCR_ENABLED_on">{l s='Yes' d='Modules.Somcp.Admin'}</label>
                            <input type="radio" name="SOMCP_DCR_ENABLED" id="SOMCP_DCR_ENABLED_off" value="0"{if $somcp_settings.SOMCP_DCR_ENABLED !== '1'} checked{/if}>
                            <label for="SOMCP_DCR_ENABLED_off">{l s='No' d='Modules.Somcp.Admin'}</label>
                            <a class="slide-button btn"></a>
                        </span>
                        <p class="help-block">{l s='Older way of connecting, kept for assistants that know nothing else. Registering costs nothing on its own: an application still has to be approved by an employee before it can do anything.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-4">{l s='Only allow these return addresses' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-8">
                        {include file='./_inherit.tpl' field='SOMCP_REDIRECT_HOSTS'}
                        {* A plain field the script turns into tags, like the tags of a
                           product. Without the script it still works as a list of
                           host names separated by spaces, which is what is saved. *}
                        <input type="text" name="SOMCP_REDIRECT_HOSTS" class="form-control" value="{$somcp_settings.SOMCP_REDIRECT_HOSTS|escape:'html':'UTF-8'}"
                               data-somcp-tags data-somcp-tags-remove="{l s='Remove' d='Modules.Somcp.Admin'}"
                               data-somcp-tags-placeholder="{l s='Add a host name' d='Modules.Somcp.Admin'}">
                        <p class="somcp-tags-suggest" data-somcp-tags-suggest="SOMCP_REDIRECT_HOSTS" hidden>
                            <span class="text-muted">{l s='Clients the module works with:' d='Modules.Somcp.Admin'}</span>
                            {foreach $somcp_redirect_suggestions as $somcp_host => $somcp_client}
                                <button type="button" class="somcp-tag-suggest" data-somcp-host="{$somcp_host|escape:'html':'UTF-8'}">+ {$somcp_host|escape:'html':'UTF-8'} <span class="text-muted">{$somcp_client|escape:'html':'UTF-8'}</span></button>
                            {/foreach}
                        </p>
                        <p class="help-block">{l s='Type a host name, for example claude.ai, and press Enter. Leave empty to accept any address an application registers. Desktop assistants and coding agents use your own computer and are always allowed; a self-hosted n8n needs its own host name added.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-4">{l s='Keep a security log' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-8">
                        {include file='./_inherit.tpl' field='SOMCP_LOG_ENABLED'}
                        <span class="switch prestashop-switch fixed-width-lg">
                            <input type="radio" name="SOMCP_LOG_ENABLED" id="SOMCP_LOG_ENABLED_on" value="1"{if $somcp_settings.SOMCP_LOG_ENABLED === '1'} checked{/if}>
                            <label for="SOMCP_LOG_ENABLED_on">{l s='Yes' d='Modules.Somcp.Admin'}</label>
                            <input type="radio" name="SOMCP_LOG_ENABLED" id="SOMCP_LOG_ENABLED_off" value="0"{if $somcp_settings.SOMCP_LOG_ENABLED !== '1'} checked{/if}>
                            <label for="SOMCP_LOG_ENABLED_off">{l s='No' d='Modules.Somcp.Admin'}</label>
                            <a class="slide-button btn"></a>
                        </span>
                        <p class="help-block">{l s='Connections, approvals and refusals, kept for thirty days. Never contains a token.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>

                <div class="panel-footer">
                    <button type="submit" name="submitSomcpSettings" value="1" class="btn btn-default pull-right">
                        <i class="process-icon-save"></i> {l s='Save' d='Modules.Somcp.Admin'}
                    </button>
                </div>
            </div>
        </div>
    </form>

    <div class="somcp-pane" id="somcp-pane-assistants" data-somcp-pane="assistants" role="tabpanel"{if $somcp_pane !== 'assistants'} hidden{/if}>
        <div class="panel">
            <div class="panel-heading"><i class="icon-plug"></i> {l s='Connected assistants' d='Modules.Somcp.Admin'}</div>
        {if $somcp_consents}
            <table class="table">
                <thead>
                    <tr>
                        <th>{l s='Application' d='Modules.Somcp.Admin'}</th>
                        <th>{l s='Approved by' d='Modules.Somcp.Admin'}</th>
                        <th>{l s='Profile' d='Modules.Somcp.Admin'}</th>
                        {if $somcp_multistore}<th>{l s='Shop' d='Modules.Somcp.Admin'}</th>{/if}
                        <th>{l s='Permissions' d='Modules.Somcp.Admin'}</th>
                        <th>{l s='Since' d='Modules.Somcp.Admin'}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                {foreach from=$somcp_consents item=consent}
                    <tr>
                        <td>
                            {if $consent.client_name}{$consent.client_name|escape:'html':'UTF-8'}{else}{$consent.client_id|escape:'html':'UTF-8'}{/if}
                        </td>
                        <td>
                            {if $consent.firstname || $consent.lastname}
                                {$consent.firstname|escape:'html':'UTF-8'} {$consent.lastname|escape:'html':'UTF-8'}<br>
                            {/if}
                            <span class="text-muted">{$consent.email|escape:'html':'UTF-8'}</span>
                            {if isset($consent.employee_active) && !$consent.employee_active}
                                <br><span class="text-muted">{l s='account disabled' d='Modules.Somcp.Admin'}</span>
                            {/if}
                        </td>
                        <td>
                            {if $consent.profile_name}{$consent.profile_name|escape:'html':'UTF-8'}
                            {else}<span class="text-muted">{l s='unknown' d='Modules.Somcp.Admin'}</span>{/if}
                        </td>
                        {if $somcp_multistore}<td>{$consent.shop_name|escape:'html':'UTF-8'}</td>{/if}
                        <td><code>{$consent.scopes|escape:'html':'UTF-8'}</code></td>
                        <td>{$consent.date_add|escape:'html':'UTF-8'}</td>
                        <td class="text-right">
                            <form method="post" action="{$somcp_form_action|escape:'html':'UTF-8'}">
                                <input type="hidden" name="id_consent" value="{$consent.id_consent|intval}">
                                <button type="submit" name="submitSomcpWithdraw" value="1" class="btn btn-default btn-sm">
                                    {l s='Disconnect' d='Modules.Somcp.Admin'}
                                </button>
                            </form>
                        </td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        {else}
            <p class="text-muted">{l s='No assistant is connected to this shop yet.' d='Modules.Somcp.Admin'}</p>
        {/if}
        </div>
    </div>

    {* The chat built into the back office. Same panels and table as the
       Connected assistants tab, and the Edit button of PrestaShop's own lists,
       so the two tabs read as one family. *}
    <div class="somcp-pane" id="somcp-pane-assistant" data-somcp-pane="assistant" role="tabpanel"{if $somcp_pane !== 'assistant'} hidden{/if}>
    {* Shown on every visit to the tab: the assistant ships before it is finished,
       and a merchant must know that before relying on it. *}
    <div class="alert alert-warning">{l s='In development: the built-in assistant is unstable and may change or stop working without notice, and its consumption can be somewhat high.' d='Modules.Somcp.Admin'}</div>
    {if !$somcp_assistant_ready}
        <div class="alert alert-warning">{l s='The files of the assistant are missing from this copy of the module. Install the module from its release zip.' d='Modules.Somcp.Admin'}</div>
    {/if}

    {if $somcp_assistant_edit}
        <form method="post" action="{$somcp_form_action|escape:'html':'UTF-8'}" class="defaultForm form-horizontal">
            <input type="hidden" name="id_assistant" value="{$somcp_assistant_edit.id_assistant|intval}">
            <div class="panel">
                <div class="panel-heading">
                    {if $somcp_assistant_edit.id_assistant}{l s='Edit the assistant' d='Modules.Somcp.Admin'}{else}{l s='Add an assistant' d='Modules.Somcp.Admin'}{/if}
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3 required">{l s='Assistant' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-6">
                        <select name="somcp_assistant_provider" class="form-control" data-somcp-provider>
                        {foreach from=$somcp_assistant_providers key=providerId item=provider}
                            <option value="{$providerId|escape:'html':'UTF-8'}"{if $somcp_assistant_edit.provider === $providerId} selected{/if}>{$provider.label|escape:'html':'UTF-8'}</option>
                        {/foreach}
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3{if !$somcp_assistant_edit.has_key} required{/if}">{l s='API key' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-6">
                        <input type="password" name="somcp_assistant_key" class="form-control" autocomplete="off" value=""{if !$somcp_assistant_edit.has_key} required{/if}>
                        <p class="help-block">
                            {if $somcp_assistant_edit.has_key}{l s='Leave empty to keep the key already saved.' d='Modules.Somcp.Admin'}{/if}
                            {l s='The key is stored encrypted on your shop and only sent to the provider you picked.' d='Modules.Somcp.Admin'}
                        </p>
                        <p class="help-block">
                        {foreach from=$somcp_assistant_providers key=providerId item=provider name=keys}
                            <a href="{$provider.key_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{$provider.label|escape:'html':'UTF-8'}</a>{if !$smarty.foreach.keys.last} &middot; {/if}
                        {/foreach}
                        </p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Level' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-6">
                        <div class="radio"><label><input type="radio" name="somcp_assistant_level" value="economy"{if $somcp_assistant_edit.level === 'economy'} checked{/if}> <strong>{l s='Economy' d='Modules.Somcp.Admin'}</strong> - {l s='fast and inexpensive, enough for most tasks' d='Modules.Somcp.Admin'}</label></div>
                        <div class="radio"><label><input type="radio" name="somcp_assistant_level" value="balanced"{if $somcp_assistant_edit.level === 'balanced'} checked{/if}> <strong>{l s='Balanced' d='Modules.Somcp.Admin'}</strong> - {l s='finer for texts and SEO' d='Modules.Somcp.Admin'}</label></div>
                        <div class="radio"><label><input type="radio" name="somcp_assistant_level" value="power"{if $somcp_assistant_edit.level === 'power'} checked{/if}> <strong>{l s='Powerful' d='Modules.Somcp.Admin'}</strong> - {l s='the most capable, clearly more expensive' d='Modules.Somcp.Admin'}</label></div>
                        {* Only for the providers that make images. Hidden, not
                           removed, when another provider is picked: the server
                           refuses the level for them anyway. *}
                        <div class="radio" data-somcp-image-level="{$somcp_assistant_image_providers|escape:'html':'UTF-8'}"><label><input type="radio" name="somcp_assistant_level" value="image"{if $somcp_assistant_edit.level === 'image'} checked{/if}> <strong>{l s='Images' d='Modules.Somcp.Admin'}</strong> - {l s='creates pictures; it never leads the chat, the default assistant asks it for them' d='Modules.Somcp.Admin'}</label></div>
                        <div class="radio"><label><input type="radio" name="somcp_assistant_level" value="custom" data-somcp-custom-level{if $somcp_assistant_edit.level === 'custom'} checked{/if}> <strong>{l s='Custom' d='Modules.Somcp.Admin'}</strong> - {l s='choose the exact model yourself from the list below' d='Modules.Somcp.Admin'}</label></div>
                        <p class="help-block">{l s='The module always takes the newest model of the chosen level, without an update.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>
                {* The exact model shows only for the Custom level, right under it.
                   The list is the one the provider returned for the saved key; a
                   stored model no longer listed stays selectable so editing
                   another field never loses it. No key yet, no list - the hint
                   says to save a working key first. *}
                <div class="form-group" data-somcp-custom-model{if $somcp_assistant_edit.level !== 'custom'} style="display:none"{/if}>
                    <label class="control-label col-lg-3">{l s='Exact model' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-6">
                        {if $somcp_assistant_models || $somcp_assistant_edit.model}
                            <select name="somcp_assistant_model" class="form-control">
                                {if $somcp_assistant_edit.model && !in_array($somcp_assistant_edit.model, $somcp_assistant_models)}
                                    <option value="{$somcp_assistant_edit.model|escape:'html':'UTF-8'}" selected>{$somcp_assistant_edit.model|escape:'html':'UTF-8'}</option>
                                {/if}
                                {foreach from=$somcp_assistant_models item=model}
                                    <option value="{$model|escape:'html':'UTF-8'}"{if $model === $somcp_assistant_edit.model} selected{/if}>{$model|escape:'html':'UTF-8'}</option>
                                {/foreach}
                            </select>
                            <p class="help-block">{l s='Pick the model this assistant runs. The list comes from your provider.' d='Modules.Somcp.Admin'}</p>
                        {else}
                            <input type="hidden" name="somcp_assistant_model" value="">
                            <p class="help-block">{l s='Save this assistant with a working key first, then edit it to choose a model here.' d='Modules.Somcp.Admin'}</p>
                        {/if}
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Role' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-6">
                        <input type="text" name="somcp_assistant_role" class="form-control" maxlength="255" value="{$somcp_assistant_edit.role|escape:'html':'UTF-8'}">
                        <p class="help-block">{l s='For example: SEO and product pages. Optional: with one assistant switched on, it does everything. With several, the default one hands each the work of its role.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Monthly cap for this assistant' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-6">
                        <div class="input-group fixed-width-xl">
                            <input type="number" min="0" step="1000" name="somcp_assistant_token_cap" class="form-control" value="{$somcp_assistant_edit.token_cap|intval}">
                            <span class="input-group-addon">{l s='tokens' d='Modules.Somcp.Admin'}</span>
                        </div>
                        <p class="help-block">{l s='Tokens it may use in a month, counted on each shop. A powerful model deserves a lower cap than an economy one. 0 for no cap of its own.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Active' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-6">
                        <span class="switch prestashop-switch fixed-width-lg">
                            <input type="radio" name="somcp_assistant_active" id="somcp_assistant_active_on" value="1"{if $somcp_assistant_edit.active} checked{/if}>
                            <label for="somcp_assistant_active_on">{l s='Yes' d='Modules.Somcp.Admin'}</label>
                            <input type="radio" name="somcp_assistant_active" id="somcp_assistant_active_off" value="0"{if !$somcp_assistant_edit.active} checked{/if}>
                            <label for="somcp_assistant_active_off">{l s='No' d='Modules.Somcp.Admin'}</label>
                            <a class="slide-button btn"></a>
                        </span>
                    </div>
                </div>
                <div class="panel-footer">
                    <a class="btn btn-default" href="{$somcp_form_action|escape:'html':'UTF-8'}"><i class="process-icon-cancel"></i> {l s='Cancel' d='Modules.Somcp.Admin'}</a>
                    <button type="submit" name="submitSomcpAssistant" value="1" class="btn btn-default pull-right"><i class="process-icon-save"></i> {l s='Save' d='Modules.Somcp.Admin'}</button>
                </div>
            </div>
        </form>
    {/if}

        <div class="panel">
            <div class="panel-heading"><i class="icon-comments"></i> {l s='Your assistants' d='Modules.Somcp.Admin'} <span class="somcp-foot text-muted">- {l s='Powered by' d='Modules.Somcp.Admin'} <a href="https://github.com/neuron-core/neuron-ai" target="_blank" rel="noopener noreferrer">Neuron AI</a></span></div>
            <p class="help-block">{l s='The chat opens with the button at the top right of every back office page. It acts with the rights of the employee signed in and asks for approval before any change. One assistant switched on does everything; with several, each takes its role.' d='Modules.Somcp.Admin'}</p>
        {* A group or a shop uses the assistants of the level above until it is
           given its own: checking the box copies them here, keys included;
           unchecking it removes this level's and goes back to those above. *}
        {if $somcp_inheritable}
            <form method="post" action="{$somcp_form_action|escape:'html':'UTF-8'}" class="somcp-inherit" data-somcp-assistants-level>
                <label>
                    <input type="checkbox" value="1" data-somcp-assistants-own data-somcp-question="{l s='Use the assistants inherited from above again? The assistants of this level are removed.' d='Modules.Somcp.Admin'}"{if $somcp_assistants_own} checked{/if}{if !$somcp_assistants_own && !$somcp_assistants} disabled{/if}>
                    {l s='Value of its own here' d='Modules.Somcp.Admin'}
                </label>
                {if !$somcp_assistants_own}
                    <span class="text-muted">
                        {if $somcp_assistants_source === 'group'}{l s='inherited from the group' d='Modules.Somcp.Admin'}
                        {elseif $somcp_assistants_source === 'global'}{l s='inherited from all shops' d='Modules.Somcp.Admin'}
                        {else}{l s='module default' d='Modules.Somcp.Admin'}{/if}
                    </span>
                {/if}
                <input type="hidden" name="{if $somcp_assistants_own}submitSomcpAssistantInherit{else}submitSomcpAssistantOwn{/if}" value="1">
            </form>
        {/if}
        {if $somcp_assistants}
            <table class="table">
                <thead>
                    <tr>
                        <th>{l s='Assistant' d='Modules.Somcp.Admin'}</th>
                        <th>{l s='Role' d='Modules.Somcp.Admin'}</th>
                        <th>{l s='Level' d='Modules.Somcp.Admin'}</th>
                        <th>{l s='Model' d='Modules.Somcp.Admin'}</th>
                        <th class="text-right">{l s='This month' d='Modules.Somcp.Admin'}</th>
                        <th class="text-center">{l s='Active' d='Modules.Somcp.Admin'}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                {foreach from=$somcp_assistants item=assistant}
                    <tr>
                        <td>
                            {$assistant.label|escape:'html':'UTF-8'}
                            {if $assistant.is_default}<span class="badge badge-info">{l s='Default' d='Modules.Somcp.Admin'}</span>{/if}
                        </td>
                        <td>{if $assistant.role}{$assistant.role|escape:'html':'UTF-8'}{else}<span class="text-muted">{l s='Everything' d='Modules.Somcp.Admin'}</span>{/if}</td>
                        <td>
                            {if $assistant.level === 'image'}{l s='Images' d='Modules.Somcp.Admin'}
                            {elseif $assistant.level === 'custom'}{l s='Custom' d='Modules.Somcp.Admin'}
                            {elseif $assistant.level === 'power'}{l s='Powerful' d='Modules.Somcp.Admin'}
                            {elseif $assistant.level === 'balanced'}{l s='Balanced' d='Modules.Somcp.Admin'}
                            {else}{l s='Economy' d='Modules.Somcp.Admin'}{/if}
                        </td>
                        <td>
                            {if $assistant.model}<code>{$assistant.model|escape:'html':'UTF-8'}</code>
                            {elseif $assistant.resolved_model}<code>{$assistant.resolved_model|escape:'html':'UTF-8'}</code>
                            {else}<span class="text-muted">{l s='not checked yet' d='Modules.Somcp.Admin'}</span>{/if}
                        </td>
                        <td class="text-right">
                            {if isset($somcp_assistant_tokens[$assistant.id_assistant])}{$somcp_assistant_tokens[$assistant.id_assistant]|escape:'html':'UTF-8'}{else}0{/if}{if $assistant.token_cap} / {$assistant.token_cap_label|escape:'html':'UTF-8'}{/if} {l s='tokens' d='Modules.Somcp.Admin'}
                            <br><small class="text-muted">{l s='about' d='Modules.Somcp.Admin'} ${if isset($somcp_assistant_costs[$assistant.id_assistant])}{$somcp_assistant_costs[$assistant.id_assistant]|floatval|string_format:'%.4f'}{else}0.0000{/if}</small>
                        </td>
                        <td class="text-center">
                            {if $assistant.active}<i class="icon-check text-success"></i>{else}<i class="icon-remove text-danger"></i>{/if}
                        </td>
                        <td class="text-right">
                        {if $somcp_assistants_own}
                            <div class="btn-group-action">
                                <div class="btn-group pull-right">
                                    <a class="edit btn btn-default" href="{$somcp_form_action|escape:'html':'UTF-8'}&amp;somcp_assistant_edit={$assistant.id_assistant|intval}" title="{l s='Edit' d='Modules.Somcp.Admin'}">
                                        <i class="icon-pencil"></i> {l s='Edit' d='Modules.Somcp.Admin'}
                                    </a>
                                    <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown">
                                        <i class="icon-caret-down"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <a href="#" data-somcp-post="submitSomcpAssistantTest" data-somcp-id="{$assistant.id_assistant|intval}">
                                                <i class="icon-refresh"></i> {l s='Test the key' d='Modules.Somcp.Admin'}
                                            </a>
                                        </li>
                                    {if !$assistant.is_default && $assistant.level !== 'image'}
                                        <li>
                                            <a href="#" data-somcp-post="submitSomcpAssistantDefault" data-somcp-id="{$assistant.id_assistant|intval}">
                                                <i class="icon-star"></i> {l s='Make default' d='Modules.Somcp.Admin'}
                                            </a>
                                        </li>
                                    {/if}
                                        <li class="divider"></li>
                                        <li>
                                            <a href="#" class="delete" data-somcp-post="submitSomcpAssistantDelete" data-somcp-id="{$assistant.id_assistant|intval}" data-somcp-question="{l s='Remove this assistant?' d='Modules.Somcp.Admin'}">
                                                <i class="icon-trash"></i> {l s='Remove' d='Modules.Somcp.Admin'}
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        {/if}
                        </td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        {else}
            <p class="text-muted">{l s='No assistant yet. Add one with your own API key to get the chat.' d='Modules.Somcp.Admin'}</p>
        {/if}
            {* One form for every row action of the list, filled by the link
               clicked: PrestaShop's lists put links in their menus, not
               buttons, and a link cannot post on its own. *}
            <form method="post" action="{$somcp_form_action|escape:'html':'UTF-8'}" id="somcp-assistant-action" hidden>
                <input type="hidden" name="id_assistant" value="">
            </form>
            {* Under the list, not in a panel footer: a footer makes PrestaShop 9
               move the panel padding onto each child, with wider side margins
               than the tools and connected assistants panels have. *}
            {if $somcp_assistants_own}
            <div class="somcp-panel-actions">
                <a class="btn btn-default" href="{$somcp_form_action|escape:'html':'UTF-8'}&amp;somcp_assistant_add=1"><i class="icon-plus"></i> {l s='Add an assistant' d='Modules.Somcp.Admin'}</a>
            </div>
            {/if}
        </div>

        <form method="post" action="{$somcp_form_action|escape:'html':'UTF-8'}" class="defaultForm form-horizontal">
            <div class="panel">
                <div class="panel-heading"><i class="icon-cogs"></i> {l s='Settings' d='Modules.Somcp.Admin'}</div>
                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Employees allowed to use the built-in assistant' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-6">
                        {include file='./_inherit.tpl' field='SOMCP_ASSISTANT_PROFILE'}
                        <select name="SOMCP_ASSISTANT_PROFILE" class="form-control fixed-width-xl">
                            <option value="0"{if !$somcp_assistant_profile} selected{/if}>{l s='Any employee' d='Modules.Somcp.Admin'}</option>
                            {foreach from=$somcp_profiles item=profile}
                                <option value="{$profile.id_profile|intval}"{if $somcp_assistant_profile == $profile.id_profile} selected{/if}>{$profile.name|escape:'html':'UTF-8'}</option>
                            {/foreach}
                        </select>
                        <p class="help-block">{l s='The chat acts with the rights of the employee using it, never more. Administrators always have it.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Monthly cap, all assistants' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-6">
                        {include file='./_inherit.tpl' field='SOMCP_ASSISTANT_TOKENS'}
                        <div class="input-group fixed-width-xl">
                            <input type="number" min="0" step="1000" name="SOMCP_ASSISTANT_TOKENS" class="form-control" value="{$somcp_assistant_token_cap|intval}">
                            <span class="input-group-addon">{l s='tokens' d='Modules.Somcp.Admin'}</span>
                        </div>
                        <p class="help-block">
                            {l s='This month: %tokens% tokens, counted exactly as the providers report them. The chat stops when the cap is reached, on each shop. 0 for no cap.' sprintf=['%tokens%' => $somcp_assistant_used] d='Modules.Somcp.Admin'}
                            {l s='About $%spent%, estimated from the public prices of each provider: a token does not cost the same with every model.' sprintf=['%spent%' => ($somcp_assistant_spent|string_format:'%.4f')] d='Modules.Somcp.Admin'}
                        </p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Instructions for the shop' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-6">
                        {include file='./_inherit.tpl' field='SOMCP_ASSISTANT_INSTRUCTIONS'}
                        <textarea name="SOMCP_ASSISTANT_INSTRUCTIONS" class="form-control" rows="4" maxlength="4000">{$somcp_assistant_instructions|escape:'html':'UTF-8'}</textarea>
                        <p class="help-block">{l s='Added to every conversation, for every assistant. For example: how to address customers, delivery times, house style.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>
                {* Web search for the assistants that cannot run one beside the
                   shop's tools: a Claude or Gemini assistant lends its key.
                   Greyed while there is nobody to lend or nobody to lend to;
                   the PHP side ignores it then too. *}
                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Web search' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-6">
                        {include file='./_inherit.tpl' field='SOMCP_ASSISTANT_SEARCH'}
                        <select name="SOMCP_ASSISTANT_SEARCH" class="form-control"{if !$somcp_search_useful} disabled data-somcp-locked{/if}>
                            <option value="">{l s='Off' d='Modules.Somcp.Admin'}</option>
                            {foreach from=$somcp_search_lenders key=id item=label}
                                <option value="{$id|escape:'html':'UTF-8'}"{if $id === $somcp_search_provider} selected{/if}>{$label|escape:'html':'UTF-8'}</option>
                            {/foreach}
                        </select>
                        <p class="help-block">{l s='Claude and Gemini search the web on their own. Pick one of them to search for the other assistants too, with its key: its searches count in its tokens.' d='Modules.Somcp.Admin'}</p>
                        {if !$somcp_search_useful}
                            <p class="help-block">{l s='Available once a Claude or Gemini assistant and an assistant of another provider are both set up.' d='Modules.Somcp.Admin'}</p>
                        {/if}
                    </div>
                </div>
                {* Long-term memory: a switch, then a provider. Providers already
                   set up for the chat lend their key, so nothing is asked twice;
                   Voyage does embeddings only and shows a key field of its own
                   when picked. The whole block hides when the switch is off. *}
                {* The switch, the provider and the key are one setting across
                   levels, so one box governs the three groups. *}
                <div data-somcp-setting>
                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Memory' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-6">
                        {include file='./_inherit.tpl' field='SOMCP_EMB_ON'}
                        <span class="switch prestashop-switch fixed-width-lg" data-somcp-memory-switch>
                            <input type="radio" name="SOMCP_EMB_ON" id="somcp_emb_on" value="1"{if $somcp_memory_on} checked{/if}>
                            <label for="somcp_emb_on">{l s='Yes' d='Modules.Somcp.Admin'}</label>
                            <input type="radio" name="SOMCP_EMB_ON" id="somcp_emb_off" value="0"{if !$somcp_memory_on} checked{/if}>
                            <label for="somcp_emb_off">{l s='No' d='Modules.Somcp.Admin'}</label>
                            <a class="slide-button btn"></a>
                        </span>
                        <p class="help-block">{l s='Lets the assistant remember past conversations and recall them later, each one kept to the employee who had it. It embeds them with a provider - a chat provider you already set up, or Voyage - and is billed for that. Off by default.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>
                <div class="form-group" data-somcp-memory-block{if !$somcp_memory_on} style="display:none"{/if}>
                    <label class="control-label col-lg-3">{l s='Embeddings provider' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-6">
                        {if $somcp_memory_selectable}
                            <select name="SOMCP_EMB_PROVIDER" class="form-control" data-somcp-memory-provider>
                                {foreach from=$somcp_memory_selectable key=id item=provider}
                                    <option value="{$id|escape:'html':'UTF-8'}" data-key-url="{$provider.key_url|escape:'html':'UTF-8'}"{if $provider.special} data-somcp-special{/if}{if $id === $somcp_memory_provider} selected{/if}>{$provider.label|escape:'html':'UTF-8'}{if $provider.special} ({l s='special provider for the memory' d='Modules.Somcp.Admin'}){/if}</option>
                                {/foreach}
                            </select>
                            <p class="help-block">{l s='The chat providers already set up lend their key. Voyage is embeddings only, so it asks for its own key below.' d='Modules.Somcp.Admin'}</p>
                        {else}
                            <p class="help-block">{l s='Set up a chat assistant first (OpenAI, Gemini, Mistral or Cohere), or pick Voyage, to give the memory a key.' d='Modules.Somcp.Admin'}</p>
                        {/if}
                    </div>
                </div>
                <div class="form-group" data-somcp-memory-key style="display:none">
                    <label class="control-label col-lg-3">{l s='Voyage key' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-6">
                        <input type="password" name="SOMCP_EMB_KEY" class="form-control" autocomplete="new-password" value=""{if $somcp_memory_has_own_key} placeholder="{l s='A key is saved. Leave empty to keep it.' d='Modules.Somcp.Admin' js=1}"{/if}>
                        <p class="help-block">{l s='Get a key at' d='Modules.Somcp.Admin'} <a href="https://dashboard.voyageai.com" target="_blank" rel="noopener">dashboard.voyageai.com</a>. {l s='Stored encrypted. Leave empty to keep the saved one.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>
                </div>
                <div class="panel-footer">
                    <button type="submit" name="submitSomcpAssistantSettings" value="1" class="btn btn-default pull-right"><i class="process-icon-save"></i> {l s='Save' d='Modules.Somcp.Admin'}</button>
                </div>
            </div>
        </form>
    </div>

    <div class="somcp-pane" id="somcp-pane-log" data-somcp-pane="log" role="tabpanel"{if $somcp_pane !== 'log'} hidden{/if}>
        <div class="panel">
            <div class="panel-heading"><i class="icon-list"></i> {l s='Security log' d='Modules.Somcp.Admin'}</div>
        {if $somcp_logs}
            <table class="table somcp-log">
                <thead>
                    <tr>
                        <th>{l s='Date' d='Modules.Somcp.Admin'}</th>
                        <th>{l s='Event' d='Modules.Somcp.Admin'}</th>
                        {if $somcp_multistore}<th>{l s='Shop' d='Modules.Somcp.Admin'}</th>{/if}
                        <th>{l s='Application' d='Modules.Somcp.Admin'}</th>
                        <th>{l s='Detail' d='Modules.Somcp.Admin'}</th>
                    </tr>
                </thead>
                <tbody>
                {foreach from=$somcp_logs item=line}
                    <tr class="somcp-log-{$line.outcome|escape:'html':'UTF-8'}">
                        <td>{$line.date_add|escape:'html':'UTF-8'}</td>
                        <td><code>{$line.event|escape:'html':'UTF-8'}</code></td>
                        {if $somcp_multistore}<td>{$line.shop_name|escape:'html':'UTF-8'}</td>{/if}
                        <td>{$line.client_id|escape:'html':'UTF-8'}</td>
                        <td>{$line.message|escape:'html':'UTF-8'}</td>
                    </tr>
                {/foreach}
                </tbody>
            </table>

            <form method="post" action="{$somcp_form_action|escape:'html':'UTF-8'}">
                <button type="submit" name="submitSomcpClearLog" value="1" class="btn btn-default">
                    {l s='Empty the log' d='Modules.Somcp.Admin'}
                </button>
            </form>
        {else}
            <p class="text-muted">{l s='Nothing logged yet.' d='Modules.Somcp.Admin'}</p>
        {/if}
        </div>
    </div>

    {* Updates are announced, never applied: the module does not download or
       replace its own code. The merchant uploads the archive in the Module
       Manager, which upgrades the module in place and keeps its settings. *}
    <div class="somcp-pane" id="somcp-pane-help" data-somcp-pane="help" role="tabpanel"{if $somcp_pane !== 'help'} hidden{/if}>
        <div class="panel">
            <div class="panel-heading"><i class="icon-refresh"></i> {l s='Updates' d='Modules.Somcp.Admin'}</div>
            <form method="post" action="{$somcp_form_action|escape:'html':'UTF-8'}" class="form-horizontal">
                <div class="form-group">
                    <label class="control-label col-lg-4">{l s='Installed version' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-8"><p class="form-control-static">{$somcp_update.installed|escape:'html':'UTF-8'}</p></div>
                </div>
            {if $somcp_update.enabled}
                <div class="form-group">
                    <label class="control-label col-lg-4">{l s='Latest version' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-8">
                        <p class="form-control-static">
                        {if $somcp_update.failed}
                            <span class="text-muted">{l s='Unknown: GitHub could not be reached.' d='Modules.Somcp.Admin'}</span>
                        {elseif $somcp_update.latest}
                            {$somcp_update.latest|escape:'html':'UTF-8'}
                        {/if}
                        {if $somcp_update_checked}
                            <br><small class="text-muted">{l s='Checked on %date%' sprintf=['%date%' => $somcp_update_checked] d='Modules.Somcp.Admin'}</small>
                        {/if}
                        </p>
                    </div>
                </div>
            {/if}
                <div class="form-group">
                    <label class="control-label col-lg-4">{l s='Check for new versions' d='Modules.Somcp.Admin'}</label>
                    <div class="col-lg-8">
                        <span class="switch prestashop-switch fixed-width-lg">
                            <input type="radio" data-somcp-autosave="submitSomcpUpdateSwitch" name="SOMCP_UPDATE_CHECK" id="SOMCP_UPDATE_CHECK_on" value="1"{if $somcp_update.enabled} checked{/if}>
                            <label for="SOMCP_UPDATE_CHECK_on">{l s='Yes' d='Modules.Somcp.Admin'}</label>
                            <input type="radio" data-somcp-autosave="submitSomcpUpdateSwitch" name="SOMCP_UPDATE_CHECK" id="SOMCP_UPDATE_CHECK_off" value="0"{if !$somcp_update.enabled} checked{/if}>
                            <label for="SOMCP_UPDATE_CHECK_off">{l s='No' d='Modules.Somcp.Admin'}</label>
                            <a class="slide-button btn"></a>
                        </span>
                        <p class="help-block">{l s='Once a day, while you have this page open, the module asks GitHub for the latest published version. Nothing is downloaded or installed without you.' d='Modules.Somcp.Admin'}</p>
                    </div>
                </div>
            {if $somcp_update.enabled}
                <div class="form-group">
                    <div class="col-lg-8 col-lg-offset-4">
                        <button type="submit" name="submitSomcpUpdateCheck" value="1" class="btn btn-default">
                            <i class="icon-refresh"></i> {l s='Check now' d='Modules.Somcp.Admin'}
                        </button>
                    </div>
                </div>
            {/if}
            </form>

        {if $somcp_update.available}
            <div class="alert alert-info">
                <p><strong>{l s='Version %version% is available.' sprintf=['%version%' => $somcp_update.latest] d='Modules.Somcp.Admin'}</strong></p>
            {if $somcp_update.titles}
                <p>{l s='What is new:' d='Modules.Somcp.Admin'}</p>
                <ul>
                {foreach from=$somcp_update.titles item=title}
                    <li>{$title|escape:'html':'UTF-8'}</li>
                {/foreach}
                {if $somcp_update.more > 0}
                    <li>{l s='and %count% more' sprintf=['%count%' => $somcp_update.more|intval] d='Modules.Somcp.Admin'}</li>
                {/if}
                </ul>
            {/if}
                <p>
                    <a class="btn btn-primary" href="{$somcp_update.download|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">
                        <i class="icon-download"></i> {l s='Download the zip' d='Modules.Somcp.Admin'}
                    </a>
                    <a class="btn btn-default" href="{$somcp_update.notes|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">
                        <i class="icon-external-link"></i> {l s='See what is new' d='Modules.Somcp.Admin'}
                    </a>
                </p>
                <p class="help-block">{l s='To update, open Modules > Module Manager, click Upload a module and drop the zip. PrestaShop replaces the module in place and keeps your settings and connected assistants.' d='Modules.Somcp.Admin'}</p>
            </div>
            {* An assistant keeps the permissions it was granted when it connected,
               so a release that brings new ones stays out of its reach until it
               goes through the approval screen again. *}
            <div class="alert alert-info">{l s='After the update, reconnect your assistants so that their access includes the new permissions.' d='Modules.Somcp.Admin'}</div>
        {elseif $somcp_update.enabled && !$somcp_update.failed && $somcp_update.latest}
            <div class="alert alert-success">{l s='You have the latest version.' d='Modules.Somcp.Admin'}</div>
        {/if}
        </div>

    {if $somcp_contributors}
        {* The first card goes to whoever has contributed the most, read from
           GitHub each time: the place follows the work, not a title. The
           others are paged six at a time so the row never wraps, and past
           seven people the last slot links to GitHub's full list. *}
        {assign var=somcp_people value=$somcp_contributors|@count}
        <div class="panel">
            <div class="panel-heading"><i class="icon-group"></i> {l s='Meet our contributors' d='Modules.Somcp.Admin'}</div>
            <p class="help-block">{l s='Everyone who helped build this module, as GitHub counts the commits of the published code.' d='Modules.Somcp.Admin'}</p>
            <div class="somcp-contributors">
            {foreach from=$somcp_contributors item=person name=people}
                <div class="somcp-contributor{if $smarty.foreach.people.first} somcp-contributor-top{/if}"{if !$smarty.foreach.people.first} data-somcp-paged{/if}>
                    <a href="{$person.profile|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer" title="{$person.login|escape:'html':'UTF-8'}">
                    {if $person.avatar}
                        <img src="{$person.avatar|escape:'html':'UTF-8'}" alt="" width="48" height="48" loading="lazy" referrerpolicy="no-referrer">
                    {/if}
                        <span class="somcp-contributor-name">{$person.login|escape:'html':'UTF-8'}</span>
                    </a>
                    <span class="somcp-contributor-share">
                    {if $person.contributions == 1}
                        {l s='1 contribution' d='Modules.Somcp.Admin'}
                    {else}
                        {l s='%count% contributions' sprintf=['%count%' => $person.contributions|intval] d='Modules.Somcp.Admin'}
                    {/if}
                        <br>{l s='%percent%% of the commits' sprintf=['%percent%' => $person.percent_shown] d='Modules.Somcp.Admin'}
                    </span>
                {if $smarty.foreach.people.first}
                    <span class="badge somcp-contributor-badge">{l s='Top contributor' d='Modules.Somcp.Admin'}</span>
                {/if}
                </div>
                {if $smarty.foreach.people.first && $somcp_people > 1}<div class="somcp-contributors-sep"></div>{/if}
            {/foreach}
            {if $somcp_people > 7}
                <a class="somcp-contributor somcp-contributor-all" href="{$somcp_repository|escape:'html':'UTF-8'}/graphs/contributors" target="_blank" rel="noopener noreferrer">
                    <span class="somcp-contributor-total">{$somcp_people|intval}</span>
                    {l s='See all contributors' d='Modules.Somcp.Admin'} <i class="icon-external-link"></i>
                </a>
            {/if}
            </div>
            <div class="somcp-contributors-bar">
                <a class="btn btn-default" href="{$somcp_repository|escape:'html':'UTF-8'}/blob/main/CONTRIBUTING.md" target="_blank" rel="noopener noreferrer">
                    <i class="icon-code-fork"></i> {l s='Become a contributor' d='Modules.Somcp.Admin'}
                </a>
                <div class="somcp-tools-nav" data-somcp-pager="6" hidden>
                    <button type="button" class="btn btn-default btn-sm" data-somcp-page="-1">
                        &laquo; {l s='Previous' d='Modules.Somcp.Admin'}
                    </button>
                    <span class="somcp-tools-count"
                          data-somcp-range="{l s='Contributors %from% to %to% of %total%' d='Modules.Somcp.Admin' js=1}"></span>
                    <button type="button" class="btn btn-default btn-sm" data-somcp-page="1">
                        {l s='Next' d='Modules.Somcp.Admin'} &raquo;
                    </button>
                </div>
            </div>
        </div>
    {/if}

        <div class="panel">
            <div class="panel-heading"><i class="icon-comments"></i> {l s='Share your feedback' d='Modules.Somcp.Admin'}</div>
            <p>{l s='A bug, an idea, a tool you are missing? The module is built in the open on GitHub: tell us there.' d='Modules.Somcp.Admin'}</p>
            <a class="btn btn-default" href="{$somcp_repository|escape:'html':'UTF-8'}/issues/new/choose" target="_blank" rel="noopener noreferrer">
                <i class="icon-comment"></i> {l s='Give feedback' d='Modules.Somcp.Admin'}
            </a>
            <a class="btn btn-default" href="{$somcp_repository|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">
                <i class="icon-github"></i> {l s='Source code' d='Modules.Somcp.Admin'}
            </a>
        </div>
    </div>

    <p class="somcp-foot text-center text-muted">
        {$somcp_name|escape:'html':'UTF-8'} {$somcp_version|escape:'html':'UTF-8'}
        &middot; &copy; 2026 Novalo
        &middot; {l s='Open source under the AFL-3.0 licence' d='Modules.Somcp.Admin'}
    </p>

    {include file='./_sponsor.tpl'}
    {include file='./_copy.tpl'}

</div>
