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
    html, body { margin: 0; height: 100%; overflow: hidden; }
    body { background: var(--bg); color: var(--fg); font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; -webkit-font-smoothing: antialiased; }
    {* The page never scrolls as a whole: it fills the window, and only the
       list of permissions scrolls, so the answer buttons are always in sight. *}
    #somcp-authorize { display: grid; grid-template-columns: minmax(320px, 34%) 1fr; height: 100vh; height: 100dvh; }
    .side { background: var(--ink); color: var(--cream); padding: 24px 32px; display: flex; flex-direction: column; gap: 20px; overflow-y: auto; }
    .brand { display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 600; }
    .brand img { width: 28px; height: 28px; border-radius: 6px; display: block; }
    .side h1 { margin: 0; font-size: 22px; line-height: 1.25; font-weight: 700; }
    .side .lead { margin: 6px 0 0; color: var(--side-muted); }
    .side .lead strong { color: var(--cream); }
    .side .shop { margin-top: auto; font-size: 12px; color: var(--side-faint); }
    .host { border-left: 3px solid var(--accent); padding: 2px 0 2px 14px; color: var(--side-muted); font-size: 12.5px; }
    .host strong { display: block; margin: 2px 0 4px; color: var(--cream); font: 700 26px/1.15 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; overflow-wrap: anywhere; }
    .host span { display: block; color: var(--accent-soft); }
    .details { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; margin: 0; font-size: 13px; }
    .details dt { color: var(--side-muted); }
    .details dd { margin: 0; font-weight: 600; overflow-wrap: break-word; min-width: 0; }
    .details small { display: block; font-weight: 400; color: var(--side-muted); }
    {* Only addresses and identifiers may break anywhere: they have no words. *}
    code { font: 12px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; word-break: break-all; }
    .main { display: flex; align-items: center; padding: 24px 48px; overflow-y: auto; }
    .main > div { width: 100%; max-width: 400px; }
    .main h2 { margin: 0; font-size: 20px; font-weight: 700; }
    .main p { margin: 6px 0 0; color: var(--text); }
    .refused { padding: 8px 12px; border-radius: 6px; background: var(--accent-bg); border-left: 3px solid var(--accent); color: var(--fg); }
    form.signin { margin-top: 18px; display: flex; flex-direction: column; gap: 12px; }
    label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
    input[type=email], input[type=password] { width: 100%; height: 40px; padding: 0 12px; border: 1px solid var(--line); border-radius: 6px; background: var(--field); color: var(--fg); font: inherit; font-size: 15px; }
    input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(213, 93, 62, .18); }
    {* The widget's own look, set through the variables it reads. *}
    altcha-widget { --altcha-max-width: 100%; --altcha-border-radius: 6px; --altcha-border-color: var(--line); --altcha-color-base: var(--field);
        --altcha-color-base-content: var(--fg); --altcha-color-primary: var(--accent); --altcha-checkbox-border-color: var(--line); }
    button { font: inherit; font-size: 14.5px; font-weight: 650; height: 42px; padding: 0 16px; border-radius: 6px; cursor: pointer; border: 1.5px solid var(--fg); background: transparent; color: var(--fg); white-space: nowrap; }
    button.primary { border-color: var(--accent); background: var(--accent); color: #fff; }
    .signin button { width: 100%; margin-top: 4px; }
    .consent { display: flex; flex-direction: column; min-height: 0; }
    .consent header { padding: 20px 40px 10px; border-bottom: 1px solid var(--rule); }
    .consent h2 { margin: 0; font-size: 18px; }
    .consent .list { flex: 1; min-height: 0; overflow-y: auto; padding: 0 40px 12px; }
    .consent ul { list-style: none; margin: 0; padding: 0; }
    .consent .list p { margin: 12px 0 0; font-size: 12.5px; color: var(--text); }
    .consent li { display: flex; gap: 12px; padding: 9px 0; border-bottom: 1px solid var(--rule); }
    .consent li svg { flex: none; width: 26px; height: 26px; padding: 5px; border-radius: 6px; background: var(--rule); color: var(--muted); }
    .consent li.write svg { background: var(--accent-bg); color: var(--accent); }
    .consent li strong { display: block; font-size: 13.5px; }
    .consent li span { color: var(--text); font-size: 13px; }
    .consent footer { flex: none; padding: 12px 40px; border-top: 1px solid var(--rule); background: var(--bar); }
    .consent form { display: flex; gap: 10px; max-width: 460px; }
    .consent form button { flex: 1; }
    .message { font-size: 15px; }
    .code { text-align: center; }
    .code .icon { width: 48px; height: 48px; margin: 0 auto 14px; border-radius: 50%; background: var(--accent-bg); color: var(--accent); display: flex; align-items: center; justify-content: center; }
    .code .icon svg { width: 22px; height: 22px; }
    .code strong { color: var(--fg); }
    .code .notice { margin-top: 14px; text-align: left; }
    .code fieldset { border: 0; margin: 20px 0 0; padding: 0; display: flex; justify-content: center; gap: 8px; }
    .code legend { position: absolute; left: -9999px; }
    input.digit { width: 52px; height: 60px; padding: 0; border: 1.5px solid var(--line); border-radius: 8px; background: var(--field); color: var(--fg); text-align: center; font: 600 26px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .code form.verify button { width: 100%; margin-top: 20px; }
    .code .rule { font-size: 12.5px; color: var(--muted); margin-top: 14px; }
    .code form.resend button { height: auto; padding: 4px; border: 0; background: none; color: var(--accent-soft); font-size: 13px; text-decoration: underline; }
    .code form.resend button:disabled { color: var(--muted); text-decoration: none; cursor: default; }
    @media (max-width: 900px) {
        #somcp-authorize { display: flex; flex-direction: column; }
        .side { flex: none; padding: 14px 16px; gap: 10px; }
        .side .lead, .side .shop { display: none; }
        .side h1 { font-size: 17px; }
        .host strong { font-size: 20px; }
        {* On a phone the summary keeps at most a third of the screen, so the
           list below it always has room. *}
        #somcp-authorize[data-somcp-state=consent] .side { max-height: 36dvh; }
        .main { flex: 1; min-height: 0; display: block; padding: 16px; }
        .main > div { max-width: none; }
        .main h2 { font-size: 17px; }
        .consent { flex: 1; }
        .consent header { padding: 12px 16px 6px; }
        .consent h2 { font-size: 16px; }
        .consent .list { padding: 0 16px 10px; }
        .consent footer { padding: 10px 16px 14px; }
        .consent form button { padding: 0 8px; font-size: 13.5px; }
        .code fieldset { gap: 6px; }
        input.digit { width: 44px; height: 54px; font-size: 22px; }
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
            {if $somcp_state == 'signin' || $somcp_state == 'otp'}
                <p class="lead">{$somcp_text.application|escape:'html':'UTF-8'} <strong>{$somcp.client_name|escape:'html':'UTF-8'}</strong></p>
            {/if}
        </div>

        {if $somcp_state != 'message'}
            <div class="host" id="somcp-return-host">
                {$somcp_text.return_to|escape:'html':'UTF-8'}
                <strong>{$somcp.return_host|escape:'html':'UTF-8'}</strong>
                <span>{$somcp_text.return_check|escape:'html':'UTF-8'}</span>
            </div>
        {/if}

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
    {elseif $somcp_state == 'otp'}
        <main class="main">
            <div class="code">
                <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></div>
                <h2>{$somcp_text.otp_title|escape:'html':'UTF-8'}</h2>
                <p>{$somcp_text.otp_intro|escape:'html':'UTF-8'}<br><strong>{$somcp.email|escape:'html':'UTF-8'}</strong></p>
                {if $somcp.notice == 'wrong'}
                    <p class="refused notice" role="alert">{$somcp_text.otp_wrong|escape:'html':'UTF-8'}</p>
                {elseif $somcp.notice == 'spent'}
                    <p class="refused notice" role="alert">{$somcp_text.otp_spent|escape:'html':'UTF-8'}</p>
                {/if}
                <form class="verify" id="somcp-otp" method="post" action="{$somcp_action|escape:'html':'UTF-8'}" autocomplete="off">
                    <input type="hidden" name="somcp_step" value="otp">
                    <input type="hidden" name="somcp_csrf" value="{$somcp.csrf|escape:'html':'UTF-8'}">
                    <fieldset>
                        <legend>{$somcp_text.otp_code|escape:'html':'UTF-8'}</legend>
                        {for $digit=1 to 6}
                            <input class="digit" type="text" name="somcp_otp[]" inputmode="numeric" pattern="[0-9]" maxlength="1" required
                                   {if $digit == 1}autocomplete="one-time-code" {/if}aria-label="{$somcp_text.otp_digit|escape:'html':'UTF-8'} {$digit|intval}">
                        {/for}
                    </fieldset>
                    <button type="submit" class="primary">{$somcp_text.verify|escape:'html':'UTF-8'}</button>
                </form>
                <p class="rule">{$somcp_text.otp_rule|escape:'html':'UTF-8'}</p>
                {if $somcp.last}
                    <p class="rule">{$somcp_text.otp_last|escape:'html':'UTF-8'}</p>
                {else}
                    <form class="resend" method="post" action="{$somcp_action|escape:'html':'UTF-8'}">
                        <input type="hidden" name="somcp_step" value="resend">
                        <input type="hidden" name="somcp_csrf" value="{$somcp.csrf|escape:'html':'UTF-8'}">
                        <button type="submit" id="somcp-resend" data-wait="{$somcp.wait|intval}"
                                data-label="{$somcp_text.resend|escape:'html':'UTF-8'}"
                                data-label-wait="{$somcp_text.resend_in|escape:'html':'UTF-8'}"{if $somcp.wait > 0} disabled{/if}>{$somcp_text.resend|escape:'html':'UTF-8'}</button>
                    </form>
                {/if}
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
{elseif $somcp_state == 'otp'}
<script src="{$somcp.otp_js|escape:'html':'UTF-8'}"></script>
{/if}
</body>
</html>
