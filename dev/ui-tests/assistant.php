<?php
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
declare(strict_types=1);

/*
 * Gives the UI test shop a built-in assistant before the browser runs.
 *
 * The back office shows the Assistant button only to a shop with an active
 * assistant, and a freshly installed shop has none, so the browser would find
 * no button to open. The key is fake and the model named, so saving it calls
 * no provider; opening the chat does not either.
 *
 * Usage: php dev/ui-tests/assistant.php <prestashop root>
 */

$psRoot = realpath($argv[1] ?? '');
if ($psRoot === false || !is_file($psRoot . '/config/config.inc.php')) {
    fwrite(STDERR, "Usage: php dev/ui-tests/assistant.php <prestashop root>\n");
    exit(1);
}

require __DIR__ . '/../shop-tests/boot.php';

$context = Context::getContext();
$context->employee = new Employee(1);
$context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
Shop::setContext(Shop::CONTEXT_SHOP, (int) $context->shop->id);

// Loading the instance loads the module's class, which boots the others.
$module = Module::getInstanceByName('somcp');
if (!$module instanceof Somcp || !Module::isInstalled('somcp')) {
    fwrite(STDERR, "The somcp module is not installed in this shop.\n");
    exit(1);
}
Somcp::bootClasses();

$idAssistant = SomcpAssistant::save(0, [
    'provider' => 'anthropic', 'api_key' => 'ui-test-key', 'level' => 'custom',
    'model' => 'claude-ui-test', 'role' => '', 'active' => true,
]);
if ($idAssistant <= 0 || SomcpAssistant::leaderFor((int) $context->shop->id) === null) {
    fwrite(STDERR, "The test assistant could not be saved.\n");
    exit(1);
}
echo "Test assistant #$idAssistant saved.\n";
