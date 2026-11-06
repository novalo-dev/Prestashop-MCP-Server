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
{* A bare page, not one of the theme: no script but the module's own, so
   nothing else on the shop can read what is typed in the password field. *}
<!doctype html>
<html lang="{$somcp_lang|escape:'html':'UTF-8'}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$somcp_text.title|escape:'html':'UTF-8'} - {$somcp_shop_name|escape:'html':'UTF-8'}</title>
<style>
    {* The colours of the module logo: its ink, its cream line and its orange dot. *}
    :root { color-scheme: light dark; --ink: #0f1419; --cream: #fbf9f4; --accent: #d55d3e; --accent-soft: #e8876d; --accent-bg: #fbeee9;
        --fg: #0f1419; --text: #4a4e52; --muted: #6b6f73; --bg: #fbf9f4; --field: #fff; --line: #d8d2c4; --rule: #ece7dc; --bar: #fff;
        --side-muted: #a7a9a6; --side-faint: #6f7476; }
    @media (prefers-color-scheme: dark) { :root { --fg: #f1efe9; --text: #c9c7c1; --muted: #9a9d9f; --bg: #161b21; --field: #1d242c; --line: #343c46; --rule: #262e37; --bar: #1b2128; --accent-bg: #3a221b; } }
    * { box-sizing: border-box; }
    html, body { margin: 0; height: 100%; }
    body { background: var(--bg); color: var(--fg); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; -webkit-font-smoothing: antialiased; }
    #somcp-authorize { display: grid; grid-template-columns: minmax(360px, 40%) 1fr; min-height: 100%; }
    .side { background: var(--ink); color: var(--cream); padding: 48px 56px; display: flex; flex-direction: column; gap: 40px; }
    .brand { display: flex; align-items: center; gap: 12px; font-size: 14px; font-weight: 600; }
    .brand img { width: 36px; height: 36px; border-radius: 7px; display: block; }
    .side h1 { margin: 0; font-size: 34px; line-height: 1.2; font-weight: 700; letter-spacing: -.02em; }
    .side .lead { margin: 14px 0 0; color: var(--side-muted); font-size: 16px; }
    .side .lead strong { color: var(--cream); }
    .side .shop { margin-top: auto; font-size: 13px; color: var(--side-faint); }
    .host { border-left: 4px solid var(--accent); padding: 4px 0 4px 20px; color: var(--side-muted); font-size: 13px; }
    .host strong { display: block; margin: 6px 0 10px; color: var(--cream); font: 700 40px/1.1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: -.02em; word-break: break-all; }
    .host span { display: block; color: var(--accent-soft); font-size: 13.5px; }
    .details { display: grid; grid-template-columns: auto 1fr; gap: 10px 24px; margin: 0; font-size: 14.5px; }
    .details dt { color: var(--side-muted); }
    .details dd { margin: 0; font-weight: 600; word-break: break-all; }
    .details small { display: block; font-weight: 400; color: var(--side-muted); }
    code { font: 13px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-weight: 400; }
    .main { display: flex; align-items: center; padding: 48px 96px; }
    .main > div { width: 100%; max-width: 440px; }
    .main h2 { margin: 0; font-size: 26px; font-weight: 700; }
    .main p { color: var(--text); }
    .refused { padding: 12px 16px; border-radius: 6px; background: var(--accent-bg); border-left: 4px solid var(--accent); color: var(--fg); }
    form.signin { margin-top: 28px; display: flex; flex-direction: column; gap: 18px; }
    label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; }
    input[type=email], input[type=password] { width: 100%; height: 48px; padding: 0 14px; border: 1px solid var(--line); border-radius: 6px; background: var(--field); color: var(--fg); font: inherit; font-size: 16px; }
    input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(213, 93, 62, .18); }
    {* The widget's own look, set through the variables it reads. *}
    altcha-widget { --altcha-max-width: 100%; --altcha-border-radius: 6px; --altcha-border-color: var(--line); --altcha-color-base: var(--field);
        --altcha-color-base-content: var(--fg); --altcha-color-primary: var(--accent); --altcha-checkbox-border-color: var(--line); }
    button { font: inherit; font-size: 15.5px; font-weight: 650; height: 50px; padding: 0 20px; border-radius: 6px; cursor: pointer; border: 1.5px solid var(--fg); background: transparent; color: var(--fg); }
    button.primary { border-color: var(--accent); background: var(--accent); color: #fff; }
    .signin button { width: 100%; }
    .consent { display: flex; flex-direction: column; height: 100vh; }
    .consent header { padding: 48px 72px 16px; border-bottom: 1px solid var(--rule); }
    .consent .list { flex: 1; min-height: 0; overflow-y: auto; padding: 4px 72px 24px; }
    .consent ul { list-style: none; margin: 0; padding: 0; }
    .consent .list p { margin: 18px 0 0; font-size: 13.5px; max-width: 640px; }
    .consent li { display: flex; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--rule); }
    .consent li svg { flex: none; width: 34px; height: 34px; padding: 8px; border-radius: 8px; background: var(--rule); color: var(--muted); }
    .consent li.write svg { background: var(--accent-bg); color: var(--accent); }
    .consent li strong { display: block; font-size: 14.5px; }
    .consent li span { color: var(--text); font-size: 14px; }
    .consent footer { flex: none; padding: 18px 72px 24px; border-top: 1px solid var(--rule); background: var(--bar); }
    .consent form { display: flex; gap: 14px; max-width: 520px; }
    .consent form button { flex: 1; }
    .message { font-size: 17px; }
    @media (max-width: 900px) {
        #somcp-authorize { display: block; }
        .side { padding: 22px 22px 28px; gap: 24px; }
        .side h1 { font-size: 25px; }
        .side .shop { display: none; }
        .host strong { font-size: 28px; }
        .main { display: block; padding: 28px 22px 40px; }
        .main > div { max-width: none; }
        .main h2 { font-size: 21px; }
        .consent { height: auto; }
        .consent header { padding: 22px 22px 8px; border: 0; }
        .consent .list { overflow: visible; padding: 0 22px 16px; }
        .consent form button { padding: 0 8px; font-size: 14px; white-space: nowrap; }
        {* The answer buttons stay in reach while the list is scrolled. *}
        .consent footer { position: sticky; bottom: 0; padding: 14px 22px 20px; box-shadow: 0 -6px 16px rgba(15, 20, 25, .08); }
    }
</style>
</head>
<body>
<div id="somcp-authorize" data-somcp-state="{$somcp_state|escape:'html':'UTF-8'}">
    <aside class="side">
        <div class="brand"><img src="{$somcp_logo|escape:'html':'UTF-8'}" alt="">{$somcp_brand|escape:'html':'UTF-8'}</div>
        <div>
            <h1>{$somcp_text.title|escape:'html':'UTF-8'}</h1>
            {if $somcp_state != 'message'}
                <p class="lead">{$somcp_text.intro|escape:'html':'UTF-8'}</p>
            {/if}
            {if $somcp_state == 'signin'}
                <p class="lead">{$somcp_text.application|escape:'html':'UTF-8'} <strong>{$somcp.client_name|escape:'html':'UTF-8'}</strong></p>
            {/if}
        </div>

        {if $somcp_state == 'consent'}
            <dl class="details">
                <dt>{$somcp_text.application|escape:'html':'UTF-8'}</dt>
                <dd>{$somcp.client_name|escape:'html':'UTF-8'}{if $somcp.client_uri}<small>{$somcp.client_uri|escape:'html':'UTF-8'}</small>{/if}<small>{$somcp.client_origin|escape:'html':'UTF-8'}</small></dd>
                <dt>{$somcp_text.identifier|escape:'html':'UTF-8'}</dt>
                <dd><code>{$somcp.client_id|escape:'html':'UTF-8'}</code></dd>
                <dt>{$somcp_text.signed_in_as|escape:'html':'UTF-8'}</dt>
                <dd>{$somcp.employee|escape:'html':'UTF-8'}</dd>
                <dt>{$somcp_text.shop|escape:'html':'UTF-8'}</dt>
                <dd>{$somcp.shop|escape:'html':'UTF-8'}</dd>
                <dt>{$somcp_text.sent_back|escape:'html':'UTF-8'}</dt>
                <dd><code>{$somcp.redirect_uri|escape:'html':'UTF-8'}</code></dd>
            </dl>
        {/if}

        {if $somcp_state != 'message'}
            <div class="host" id="somcp-return-host">
                {$somcp_text.return_to|escape:'html':'UTF-8'}
                <strong>{$somcp.return_host|escape:'html':'UTF-8'}</strong>
                <span>{$somcp_text.return_check|escape:'html':'UTF-8'}</span>
            </div>
        {/if}

        <div class="shop">{$somcp_shop_name|escape:'html':'UTF-8'}</div>
    </aside>

    {if $somcp_state == 'message'}
        <main class="main">
            <div><p class="message">{$somcp.message|escape:'html':'UTF-8'}</p></div>
        </main>
    {elseif $somcp_state == 'signin'}
        <main class="main">
            <div>
                <h2>{$somcp_text.signin|escape:'html':'UTF-8'}</h2>
                <p>{$somcp_text.signin_intro|escape:'html':'UTF-8'}</p>
                {if $somcp.refused}
                    <p class="refused" role="alert">{$somcp_text.refused|escape:'html':'UTF-8'}</p>
                {/if}
                <form class="signin" method="post" action="{$somcp_action|escape:'html':'UTF-8'}" autocomplete="on">
                    <input type="hidden" name="somcp_step" value="signin">
                    <input type="hidden" name="somcp_csrf" value="{$somcp.csrf|escape:'html':'UTF-8'}">
                    <div>
                        <label for="somcp_email">{$somcp_text.email|escape:'html':'UTF-8'}</label>
                        <input type="email" id="somcp_email" name="somcp_email" autocomplete="username" required>
                    </div>
                    <div>
                        <label for="somcp_password">{$somcp_text.password|escape:'html':'UTF-8'}</label>
                        <input type="password" id="somcp_password" name="somcp_password" autocomplete="current-password" required>
                    </div>
                    {* The anti-robot check, served from the module itself: the
                       challenge is written here rather than fetched, so the page
                       makes no request of its own. Its footer stays visible so
                       the merchant can see what checks the form. *}
                    <div class="captcha">
                        <altcha-widget name="somcp_altcha"
                                       challenge="{$somcp.challenge|escape:'html':'UTF-8'}"
                                       language="{$somcp_lang|escape:'html':'UTF-8'}"></altcha-widget>
                    </div>
                    <button type="submit" class="primary">{$somcp_text.signin|escape:'html':'UTF-8'}</button>
                </form>
            </div>
        </main>
    {else}
        <main class="consent">
            <header><h2>{$somcp_text.abilities|escape:'html':'UTF-8'}</h2></header>
            <div class="list">
                <ul>
                    {foreach from=$somcp.scopes item=scope}
                        <li{if $scope.write} class="write"{/if}>
                            {if $scope.write}
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M14 6l4 4"/></svg>
                            {else}
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            {/if}
                            <div>
                                <strong>{$scope.title|escape:'html':'UTF-8'}</strong>
                                <span>{$scope.label|escape:'html':'UTF-8'}</span>
                            </div>
                        </li>
                    {/foreach}
                </ul>
                <p>{$somcp_text.rights|escape:'html':'UTF-8'}</p>
            </div>
            <footer>
                <form method="post" action="{$somcp_action|escape:'html':'UTF-8'}">
                    <input type="hidden" name="somcp_step" value="consent">
                    <input type="hidden" name="somcp_csrf" value="{$somcp.csrf|escape:'html':'UTF-8'}">
                    <button type="submit" name="decision" value="deny">{$somcp_text.refuse|escape:'html':'UTF-8'}</button>
                    <button type="submit" name="decision" value="allow" class="primary">{$somcp_text.allow|escape:'html':'UTF-8'}</button>
                </form>
            </footer>
        </main>
    {/if}
</div>
{if $somcp_state == 'signin'}
<script type="module" src="{$somcp.altcha_js|escape:'html':'UTF-8'}"></script>
{/if}
</body>
</html>
