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
 * and fails on the first uncaught script error, page error or PHP message on
 * screen.
 *
 * Usage: node sanity.mjs <admin url> <email> <password> <browser> <shot dir> [results file]
 *   browser is chromium or firefox. A screenshot of the page in front is left
 *   in <shot dir> when a check fails. The results file gets one PASS or FAIL
 *   line per check: the release report lists them.
 */

import { chromium, firefox } from 'playwright';
import { appendFileSync, mkdirSync } from 'node:fs';

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

await browser.close();

console.log(`\n${passed.length} passed, ${errors.length} failed`);
for (const error of errors) {
    console.log(`::error title=UI sanity::${error}`);
}
process.exit(errors.length === 0 && signedIn ? 0 : 1);
