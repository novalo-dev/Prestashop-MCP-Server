/**
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
 */

/*
 * The UI sanity run: a real browser through the module's back office.
 *
 * The integration workflow calls every tool, but no browser ever opened the
 * pages a merchant works in, so a script error or a pane left empty went
 * unnoticed. This signs in, opens the seven settings pages and the chat, uses
 * the search, a tool switch and a group switch of the Tools and prompts tab,
 * connects an assistant through the authorization page, and fails on the
 * first uncaught script error, page error or PHP message on screen.
 *
 * Usage: node sanity.mjs <admin url> <email> <password> <browser> <shot dir> [results file]
 *   browser is chromium or firefox. A screenshot of the page in front is left
 *   in <shot dir> when a check fails. The results file gets one PASS or FAIL
 *   line per check: the release report lists them.
 */

import { chromium, firefox } from 'playwright';
import { appendFileSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const [adminUrl, email, password, browserName = 'chromium', shots = 'ui-shots', results = ''] = process.argv.slice(2);
if (!adminUrl || !email || !password) {
    console.error('usage: node sanity.mjs <admin url> <email> <password> <browser> <shot dir>');
    process.exit(2);
}

// The settings pages, as somcp.php names them in settingsPages().
const PAGES = {
    config: 'AdminSomcpConfig',
    tools: 'AdminSomcpTools',
    access: 'AdminSomcpAccess',
    assistants: 'AdminSomcpConnected',
    assistant: 'AdminSomcpBuiltin',
    log: 'AdminSomcpLog',
    help: 'AdminSomcpHelp',
};

// What a page shows when PHP printed an error into it.
const PHP_MESSAGES = /(Fatal error|Parse error|Warning: |Notice: |Deprecated: |Stack trace:)/;

mkdirSync(shots, { recursive: true });
const browser = await (browserName === 'firefox' ? firefox : chromium).launch();
const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
const errors = [];
const passed = [];
let step = 'start';

// A script error fails the run when it comes from the module: its scripts,
// or the inline ones its templates print, whose frame is the page itself. One
// thrown inside a script file of PrestaShop's own is the core's, as the shop
// test treats the core's notices: it is shown as a warning, with its file.
// An error that cannot be placed still counts.
page.on('pageerror', (error) => {
    // The frame the error was thrown in is the first line naming an address.
    const frame = String(error.stack || '').split('\n').find((line) => /https?:\/\//.test(line)) || '';
    const where = (frame.match(/https?:\/\/[^\s)@]+?\.js\b[^\s)]*/) || [''])[0];
    if (where !== '' && !where.includes('/modules/somcp/')) {
        console.log(`::warning title=PrestaShop script error::${step}: ${error.message} (${where})`);
        return;
    }
    errors.push(`${step}: ${error.message}${where !== '' ? ` (${where})` : ''}`);
});

const check = async (name, run) => {
    step = name;
    const before = errors.length;
    try {
        await run();
    } catch (error) {
        errors.push(`${name}: ${error.message.split('\n')[0]}`);
    }
    const failed = errors.length > before;
    if (results !== '') {
        appendFileSync(results, `${failed ? 'FAIL' : 'PASS'} ${name}\n`);
    }
    if (failed) {
        await page.screenshot({ path: `${shots}/${name.replace(/[^a-z0-9]+/gi, '-')}.png`, fullPage: true }).catch(() => {});
        console.log(`[FAIL] ${name}`);
        return false;
    }
    passed.push(name);
    console.log(`[PASS] ${name}`);
    return true;
};

const noPhpMessage = async () => {
    const text = await page.locator('body').innerText();
    const found = text.match(PHP_MESSAGES);
    if (found) {
        throw new Error(`PHP message on the page: "${found[0]}"`);
    }
};

// A page of the module is reached through the links PrestaShop draws, which
// carry the employee's token: a hand-built address would stop at the
// invalid token screen.
const openPane = async (controller) => {
    const link = page.locator(`a[href*="controller=${controller}"]`).first();
    const href = await link.getAttribute('href', { timeout: 15000 });
    const response = await page.goto(new URL(href, page.url()).toString());
    if (!response || response.status() >= 400) {
        throw new Error(`answered ${response ? response.status() : 'nothing'}`);
    }
    await noPhpMessage();
};

const signedIn = await check('sign in to the back office', async () => {
    await page.goto(adminUrl);
    // PrestaShop 8 draws its own login form and PrestaShop 9 a Symfony one,
    // with different ids: the fields are found by their type.
    await page.locator('input[type="email"]').first().fill(email);
    await page.locator('input[type="password"]').first().fill(password);
    await Promise.all([
        page.waitForLoadState('load'),
        page.locator('form:has(input[type="password"]) button[type="submit"]').first().click(),
    ]);
    await page.locator(`a[href*="controller=${PAGES.config}"]`).first().waitFor({ state: 'attached', timeout: 30000 });
});

if (signedIn) {
    await check('open Configuration', () => openPane(PAGES.config));

    for (const [pane, controller] of Object.entries(PAGES)) {
        await check(`page ${pane} shows its pane`, async () => {
            await openPane(controller);
            await page.locator(`#somcp-pane-${pane}`).waitFor({ state: 'visible', timeout: 15000 });
        });
    }

    await check('search the tools', async () => {
        await openPane(PAGES.tools);
        const panel = page.locator('#somcp-pane-tools .panel').first();
        const search = panel.locator('[data-somcp-search]');
        // The tools are folded by group: the group rows show first, and a
        // search opens the groups it finds a tool in.
        const groups = panel.locator('tbody.somcp-group:not([hidden])');
        const rows = panel.locator('tr[data-somcp-row]:not([hidden])');
        if ((await groups.count()) === 0) {
            throw new Error('no group of tools shown before searching');
        }
        await search.fill('somcp_ping');
        if ((await rows.count()) !== 1) {
            throw new Error(`searching somcp_ping kept ${await rows.count()} rows`);
        }
        await search.fill('no-tool-has-this-name');
        if ((await rows.count()) !== 0) {
            throw new Error('a search matching nothing still shows rows');
        }
        await search.fill('');
    });

    // The switch of the built-in assistant column, saved and read back, then
    // put back as it was so the shop ends the run as it started.
    await check('switch a tool for the built-in assistant and save', async () => {
        const name = 'somcp_tool_chat[somcp_ping]';
        const toggle = () => page.locator(`input[name="${name}"]`);
        const save = () => page.locator('#somcp-pane-tools button[name="submitSomcpSettings"]').first();
        await openPane(PAGES.tools);
        await page.locator('#somcp-pane-tools [data-somcp-search]').first().fill('somcp_ping');
        const was = await toggle().isChecked();
        await toggle().setChecked(!was);
        await Promise.all([page.waitForLoadState('load'), save().click()]);
        await noPhpMessage();
        if ((await toggle().isChecked()) === was) {
            throw new Error('the switch did not keep its new position after saving');
        }
        await page.locator('#somcp-pane-tools [data-somcp-search]').first().fill('somcp_ping');
        await toggle().setChecked(was);
        await Promise.all([page.waitForLoadState('load'), save().click()]);
        if ((await toggle().isChecked()) !== was) {
            throw new Error('the switch could not be put back');
        }
    });

    // A whole group, by its scope, for the built-in assistant: saved, read
    // back and put back as it was.
    await check('switch a group of tools for the built-in assistant and save', async () => {
        const name = 'somcp_scope_chat[mcp.orders.read]';
        const toggle = () => page.locator(`input[name="${name}"]`);
        const save = () => page.locator('#somcp-pane-tools button[name="submitSomcpSettings"]').first();
        await openPane(PAGES.tools);
        const was = await toggle().isChecked();
        await toggle().setChecked(!was);
        await Promise.all([page.waitForLoadState('load'), save().click()]);
        await noPhpMessage();
        if ((await toggle().isChecked()) === was) {
            throw new Error('the group switch did not keep its new position after saving');
        }
        await toggle().setChecked(was);
        await Promise.all([page.waitForLoadState('load'), save().click()]);
        if ((await toggle().isChecked()) !== was) {
            throw new Error('the group switch could not be put back');
        }
    });

    await check('open the built-in assistant', async () => {
        await page.locator('#somcp-chat-button').first().click();
        await page.locator('#somcp-chat').waitFor({ state: 'visible', timeout: 15000 });
        await noPhpMessage();
    });
}

// The page an assistant sends the merchant to: sign in with the back office
// account, solve the anti-robot check, approve, and the code goes back to the
// assistant. Nothing on the way names the administration folder.
await check('connect an assistant through the sign-in page', async () => {
    // What the page's scripts say goes to the log, so a widget that fails
    // quietly can still be told apart from one that was never loaded.
    page.on('console', (message) => {
        if (message.type() === 'error' || message.type() === 'warning') {
            console.log(`  console ${message.type()}: ${message.text()}`);
        }
    });
    const shop = new URL(adminUrl).origin;
    const adminFolder = new URL(adminUrl).pathname.split('/').filter(Boolean)[0] || '';
    const front = (controller, query) => `${shop}/index.php?fc=module&module=somcp&controller=${controller}&${query}`;
    const callback = 'http://127.0.0.1:9/callback';

    const registered = await page.request.post(front('oauth', 'a=register'), {
        data: { client_name: 'UI sanity', redirect_uris: [callback] },
    });
    if (registered.status() !== 201) {
        throw new Error(`registering a client answered ${registered.status()}`);
    }
    const clientId = (await registered.json()).client_id;
    const { createHash, randomBytes } = await import('node:crypto');
    const verifier = randomBytes(32).toString('base64url');
    const challenge = createHash('sha256').update(verifier).digest('base64url');

    await page.goto(front('authorize', new URLSearchParams({
        response_type: 'code', client_id: clientId, redirect_uri: callback,
        code_challenge: challenge, code_challenge_method: 'S256', state: 'ui',
    }).toString()));
    await page.locator('#somcp-authorize[data-somcp-state="signin"]').waitFor({ timeout: 15000 });
    await noPhpMessage();
    const shown = async (what) => {
        if (adminFolder !== '' && (await page.content()).includes(adminFolder)) {
            throw new Error(`the ${what} names the administration folder`);
        }
    };
    await shown('sign-in page');

    await page.locator('#somcp_email').fill(email);
    await page.locator('#somcp_password').fill(password);
    // The widget draws its own box over a hidden checkbox, so the checkbox is
    // clicked the way the widget's own drawing does it, not by mouse position.
    await page.locator('altcha-widget input[type="checkbox"]').first().waitFor({ state: 'attached', timeout: 15000 })
        .catch(async () => {
            throw new Error(`the anti-robot widget was not drawn: ${(await page.locator('#somcp-authorize .captcha').innerHTML()).slice(0, 300)}`);
        });
    await page.locator('altcha-widget input[type="checkbox"]').first().evaluate((box) => box.click());
    // Polled from the test rather than with waitForFunction, which runs its
    // check through eval() - and the page's Content-Security-Policy refuses
    // eval, as it should.
    const payload = page.locator('input[name="somcp_altcha"]').first();
    for (let waited = 0; ; waited += 500) {
        if ((await payload.count()) > 0 && (await payload.inputValue()) !== '') {
            break;
        }
        if (waited >= 30000) {
            throw new Error('the anti-robot check was not solved within 30 seconds');
        }
        await page.waitForTimeout(500);
    }
    await Promise.all([page.waitForLoadState('load'), page.locator('form button[type="submit"]').first().click()]);

    // The password leads to the code e-mailed to the employee. The test shop
    // sends no e-mail, so a known code is written in its place, then typed
    // in the six boxes as a person would: the page moves from box to box and
    // posts the form once the last one is filled.
    await page.locator('#somcp-authorize[data-somcp-state="otp"]').waitFor({ timeout: 15000 });
    await shown('code page');
    execFileSync('docker', ['exec', '-u', 'www-data', 'shop', 'php', '/tmp/somcp-dev/ui-tests/consent.php', '/var/www/html', '482913']);
    if (!(await page.locator('#somcp-resend').isDisabled())) {
        throw new Error('a new code can be asked for at once, before the wait');
    }
    await page.locator('input.digit').first().click();
    await Promise.all([page.waitForLoadState('load'), page.keyboard.type('482913', { delay: 40 })]);
    await page.locator('#somcp-authorize[data-somcp-state="consent"]').waitFor({ timeout: 15000 });
    await shown('consent page');
    if (!(await page.locator('#somcp-return-host').innerText()).includes('127.0.0.1')) {
        throw new Error('the consent page does not show where the answer goes');
    }

    // The answer to the consent form is the redirect to the assistant, read
    // from the response itself: the assistant's own address is not listening
    // here, and Firefox does not report the request it never manages to send.
    const [answer] = await Promise.all([
        page.waitForResponse((response) => response.request().method() === 'POST'
            && /authorize/.test(response.url()), { timeout: 15000 }),
        page.locator('button[name="decision"][value="allow"]').click({ noWaitAfter: true }),
    ]);
    const location = answer.headers().location || '';
    if (answer.status() !== 302 || !location.startsWith(callback) || !new URL(location).searchParams.get('code')) {
        throw new Error(`the assistant got no code: ${answer.status()} ${location}`);
    }
});

await browser.close();

console.log(`\n${passed.length} passed, ${errors.length} failed`);
for (const error of errors) {
    console.log(`::error title=UI sanity::${error}`);
}
process.exit(errors.length === 0 && signedIn ? 0 : 1);
