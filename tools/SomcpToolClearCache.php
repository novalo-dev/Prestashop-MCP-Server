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
 * somcp_clear_cache - what the Clear cache button of Advanced Parameters >
 * Performance clears: Symfony, Smarty, XML, the theme's CCC files and the
 * class index.
 *
 * The button runs the core's cache clearer chain. The MCP endpoint is a front
 * controller where no Symfony kernel is booted, so that chain is usually out
 * of reach; its steps are then called through their legacy calls, in the same
 * order. Removing var/cache/<env>/ that way also takes the Doctrine cache that
 * PrestaShop 9 adds to the chain.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolClearCache
{
    /**
     * The service the Clear cache button calls, named as a string: its class
     * differs between versions and is never typed here.
     */
    private const CHAIN = 'prestashop.core.cache.clearer.cache_clearer_chain';

    /**
     * The caches cleared, in the order of the core's chain.
     */
    private const CLEARED = ['symfony', 'smarty', 'xml', 'media', 'class_index'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_clear_cache',
            Somcp::t('Clear the cache'),
            'Clears the caches, as the Clear cache button of Advanced Parameters > Performance: Symfony, '
                . 'Smarty, XML, the theme\'s combined CSS and JavaScript and the class index. They are shared by '
                . 'every shop of the installation, so all shops are cleared. Memcache or APC is not flushed and no '
                . 'setting changes. Pages are slower until the caches rebuild: do not clear on a busy shop unless '
                . 'the merchant asks. Calling twice is harmless, so a lost answer can be retried. For the settings '
                . 'use somcp_get_cache_status.',
            [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            [SomcpScope::SYSTEM_WRITE],
            [self::class, 'handle'],
            // The core guards the Clear cache route with the delete right on
            // Performance, not edit: an employee who may change the settings
            // but not delete there cannot press the button either.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminPerformance')
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function handle(array $arguments, array $context): array
    {
        // A misspelt argument would otherwise be ignored, and the caller
        // would believe it had asked for something narrower than a full clear.
        if ($arguments !== []) {
            $lines = [];
            foreach (array_keys($arguments) as $key) {
                $lines[] = '- ' . $key . ': not a field of this tool';
            }

            return SomcpTool::text("Nothing was cleared. Correct these and call again:\n" . implode("\n", $lines), true);
        }

        try {
            self::clear();
        } catch (Throwable $e) {
            return SomcpTool::text('The caches could not be cleared on the server. Try again, or use Clear cache in Advanced Parameters > Performance.', true);
        }

        return SomcpTool::json([
            'cleared' => self::CLEARED,
            // The core removes the Symfony folder once the answer is sent,
            // as the button does: the files are still there during this call.
            'symfony_cleared_at' => 'end_of_request',
            'message' => 'All caches cleared successfully',
        ]);
    }

    /**
     * Runs the button's chain when a container offers it, its steps otherwise.
     */
    private static function clear(): void
    {
        $chain = SomcpBackOffice::coreService(self::CHAIN);
        if ($chain !== null && method_exists($chain, 'clear')) {
            $chain->clear();

            return;
        }

        // The steps of the chain, in its order. Without a kernel, PrestaShop
        // 9's own Symfony clearer would do nothing, while clearSf2Cache()
        // removes var/cache/<env>/ at the end of the request on both versions.
        Tools::clearSf2Cache();
        Tools::clearSmartyCache();
        Tools::clearXMLCache();
        Media::clearCache();
        // PrestaShop 8.1 moved the class index to the prestashop/autoload
        // package and dropped the global class 8.0 loads from classes/: on
        // 8.1 and later the old name does not exist at all, and a call to it
        // stopped the whole clearing. Each name is checked before it is used.
        if (class_exists('PrestaShop\Autoload\PrestashopAutoload')) {
            PrestaShop\Autoload\PrestashopAutoload::getInstance()->generateIndex();
        } elseif (class_exists('PrestaShopAutoload')) {
            PrestaShopAutoload::getInstance()->generateIndex();
        }
    }
}
