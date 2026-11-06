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
    :root { color-scheme: light dark; --fg: #1f2933; --muted: #5f6b7a; --bg: #f4f5f7; --card: #fff; --line: #d9dde3; --accent: #25b9d7; --warn-bg: #fff4e5; --warn-fg: #7a4b00; --bad-bg: #fdecea; --bad-fg: #8a1c14; }
    @media (prefers-color-scheme: dark) { :root { --fg: #e6e9ed; --muted: #a3acb8; --bg: #15181c; --card: #1f2329; --line: #343a42; --warn-bg: #3a2c12; --warn-fg: #f5cf8e; --bad-bg: #3d1a17; --bad-fg: #f3b3ad; } }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--fg); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
    main { max-width: 480px; margin: 40px auto; padding: 0 16px; }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 24px; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    .shop { color: var(--muted); margin: 0 0 20px; }
    .host { margin: 16px 0; padding: 14px; border-radius: 8px; background: var(--warn-bg); color: var(--warn-fg); }
    .host strong { display: block; font-size: 24px; line-height: 1.2; word-break: break-all; margin: 4px 0 6px; }
    .refused { padding: 10px 12px; border-radius: 8px; background: var(--bad-bg); color: var(--bad-fg); margin: 0 0 16px; }
    label { display: block; font-weight: 600; margin: 12px 0 4px; }
    input[type=email], input[type=password] { width: 100%; padding: 10px; border: 1px solid var(--line); border-radius: 6px; background: var(--card); color: var(--fg); font: inherit; }
    button { font: inherit; padding: 10px 16px; border-radius: 6px; border: 1px solid var(--line); background: var(--card); color: var(--fg); cursor: pointer; }
    button.primary { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
    .actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px; flex-wrap: wrap; }
    table { width: 100%; border-collapse: collapse; margin: 12px 0; }
    th, td { text-align: left; vertical-align: top; padding: 6px 0; border-bottom: 1px solid var(--line); }
    th { width: 40%; color: var(--muted); font-weight: 500; padding-right: 8px; }
    code { word-break: break-all; font-size: 13px; }
    .muted { color: var(--muted); }
    ul { padding-left: 20px; }
    h2.abilities { font-size: 16px; margin: 16px 0 4px; }
</style>
</head>
<body>
<main>
    <div class="card" id="somcp-authorize" data-somcp-state="{$somcp_state|escape:'html':'UTF-8'}">
        <h1>{$somcp_text.title|escape:'html':'UTF-8'}</h1>
        <p class="shop">{$somcp_shop_name|escape:'html':'UTF-8'}</p>

        {if $somcp_state == 'message'}
            <p>{$somcp.message|escape:'html':'UTF-8'}</p>
        {else}
            <p>{$somcp_text.intro|escape:'html':'UTF-8'}</p>
            <p><span class="muted">{$somcp_text.application|escape:'html':'UTF-8'}</span> <strong>{$somcp.client_name|escape:'html':'UTF-8'}</strong></p>

            <div class="host" id="somcp-return-host">
                {$somcp_text.return_to|escape:'html':'UTF-8'}
                <strong>{$somcp.return_host|escape:'html':'UTF-8'}</strong>
                {$somcp_text.return_check|escape:'html':'UTF-8'}
            </div>

            {if $somcp_state == 'signin'}
                <p class="muted">{$somcp_text.signin_intro|escape:'html':'UTF-8'}</p>
                {if $somcp.refused}
                    <p class="refused" role="alert">{$somcp_text.refused|escape:'html':'UTF-8'}</p>
                {/if}
                <form method="post" action="{$somcp_action|escape:'html':'UTF-8'}" autocomplete="on">
                    <input type="hidden" name="somcp_step" value="signin">
                    <input type="hidden" name="somcp_csrf" value="{$somcp.csrf|escape:'html':'UTF-8'}">
                    <label for="somcp_email">{$somcp_text.email|escape:'html':'UTF-8'}</label>
                    <input type="email" id="somcp_email" name="somcp_email" autocomplete="username" required>
                    <label for="somcp_password">{$somcp_text.password|escape:'html':'UTF-8'}</label>
                    <input type="password" id="somcp_password" name="somcp_password" autocomplete="current-password" required>
                    <div class="actions">
                        <button type="submit" class="primary">{$somcp_text.signin|escape:'html':'UTF-8'}</button>
                    </div>
                </form>
            {else}
                <table>
                    <tbody>
                        <tr>
                            <th>{$somcp_text.application|escape:'html':'UTF-8'}</th>
                            <td>
                                <strong>{$somcp.client_name|escape:'html':'UTF-8'}</strong>
                                {if $somcp.client_uri}<br><span class="muted">{$somcp.client_uri|escape:'html':'UTF-8'}</span>{/if}
                                <br><span class="muted">{$somcp.client_origin|escape:'html':'UTF-8'}</span>
                            </td>
                        </tr>
                        <tr><th>{$somcp_text.identifier|escape:'html':'UTF-8'}</th><td><code>{$somcp.client_id|escape:'html':'UTF-8'}</code></td></tr>
                        <tr><th>{$somcp_text.signed_in_as|escape:'html':'UTF-8'}</th><td>{$somcp.employee|escape:'html':'UTF-8'}</td></tr>
                        <tr><th>{$somcp_text.shop|escape:'html':'UTF-8'}</th><td>{$somcp.shop|escape:'html':'UTF-8'}</td></tr>
                        <tr><th>{$somcp_text.sent_back|escape:'html':'UTF-8'}</th><td><code>{$somcp.redirect_uri|escape:'html':'UTF-8'}</code></td></tr>
                    </tbody>
                </table>

                <h2 class="abilities">{$somcp_text.abilities|escape:'html':'UTF-8'}</h2>
                <ul>
                    {foreach from=$somcp.scopes item=scope}
                        <li>{$scope.label|escape:'html':'UTF-8'} <span class="muted">({$scope.name|escape:'html':'UTF-8'})</span></li>
                    {/foreach}
                </ul>
                <p class="muted">{$somcp_text.rights|escape:'html':'UTF-8'}</p>

                <form method="post" action="{$somcp_action|escape:'html':'UTF-8'}">
                    <input type="hidden" name="somcp_step" value="consent">
                    <input type="hidden" name="somcp_csrf" value="{$somcp.csrf|escape:'html':'UTF-8'}">
                    <div class="actions">
                        <button type="submit" name="decision" value="deny">{$somcp_text.refuse|escape:'html':'UTF-8'}</button>
                        <button type="submit" name="decision" value="allow" class="primary">{$somcp_text.allow|escape:'html':'UTF-8'}</button>
                    </div>
                </form>
            {/if}
        {/if}
    </div>
</main>
</body>
</html>
