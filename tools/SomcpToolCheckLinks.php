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
 * somcp_check_links - the internal links of the shop's public pages,
 * followed from a starting page as a visitor without an account.
 *
 * A broken link or a chain of redirects is invisible from the database: the
 * theme, the menus and the modules decide which addresses a page prints. Only
 * walking the pages finds them.
 *
 * Every request goes through the fetching code of somcp_audit_page, so the
 * same rule applies: only the shop's own domains are requested, redirects
 * included, and external links are counted, never fetched.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolCheckLinks
{
    /** Pages fetched when max_pages is left out. */
    private const DEFAULT_PAGES = 50;

    /** Pages fetched at most, so one call stays a bounded load on the shop. */
    private const MAX_PAGES = 200;

    /** Seconds for the whole call, every page included. */
    private const TIME_LIMIT = 60;

    /** Pages named in linked_from at most, per broken link. */
    private const MAX_LINKED_FROM = 10;

    /** The arguments the schema declares: anything else is a mistake to report, not to ignore. */
    private const FIELDS = ['url', 'max_pages'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_check_links',
            Somcp::t('Check the links of the shop\'s public pages'),
            'Follows the internal links of this shop\'s public pages from a starting page, breadth first, as a '
                . 'visitor without an account, and reports broken links (with the pages linking to them), '
                . 'redirect chains, pages slower than 2 seconds and the number of external links, which are '
                . 'never fetched. url takes the same forms as somcp_audit_page (a full address on one of the '
                . 'shop\'s domains or a path such as /en/3-clothes; / by default). At most max_pages pages and '
                . '60 seconds per call. For one page in detail, use somcp_audit_page; for the SEO texts stored '
                . 'in the database, the audit tools of this section.',
            [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'The starting page: an address of this shop, for example '
                            . 'https://shop.example/en/3-clothes, or a path from the root of its domain such as '
                            . '/en/3-clothes. / is the home page, used when left out.',
                    ],
                    'max_pages' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => self::MAX_PAGES,
                        'description' => 'Pages fetched at most, ' . self::DEFAULT_PAGES . ' by default, 1 to '
                            . self::MAX_PAGES . '.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            [SomcpScope::SEO_READ],
            [self::class, 'handle'],
            // The same right as somcp_audit_page: what search engines find on
            // the storefront is managed from Shop Parameters > Traffic & SEO.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminMeta')
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
        $idShop = Somcp::currentShopId();
        $hosts = SomcpToolAuditPage::hosts($idShop);
        if ($hosts === []) {
            return SomcpTool::text('Nothing was fetched: this shop has no active domain.', true);
        }

        // Every mistake that can be seen before a request is reported in one
        // answer, so the call can be corrected in one go.
        $problems = [];
        foreach (array_keys($arguments) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                $problems[] = '- ' . $field . ': not a field of this tool';
            }
        }

        $maxPages = $arguments['max_pages'] ?? self::DEFAULT_PAGES;
        if (!is_int($maxPages) || $maxPages < 1 || $maxPages > self::MAX_PAGES) {
            $problems[] = '- max_pages: a whole number from 1 to ' . self::MAX_PAGES;
            $maxPages = self::DEFAULT_PAGES;
        }

        $requested = $arguments['url'] ?? '/';
        $url = is_string($requested) ? SomcpToolAuditPage::absolute(trim($requested), $idShop) : null;
        if ($url === null) {
            $problems[] = '- url: a full http or https address of this shop, or a path starting with a single /, '
                . 'such as /en/3-clothes';
        } elseif (!in_array(SomcpHttp::authority($url), $hosts, true)) {
            $problems[] = '- url: not an address of this shop. Its domains are: ' . implode(', ', $hosts);
        }

        if ($problems !== [] || $url === null) {
            return SomcpTool::text(
                "Nothing was fetched. Correct these and call again:\n" . implode("\n", array_unique($problems)),
                true
            );
        }

        return SomcpTool::json(self::crawl($url, $hosts, $maxPages));
    }

    /**
     * The pages reached from $start, breadth first, each address once.
     *
     * @param string $start
     * @param array<int, string> $hosts
     * @param int $maxPages
     *
     * @return array<string, mixed>
     */
    private static function crawl(string $start, array $hosts, int $maxPages): array
    {
        $started = microtime(true);
        $queue = [$start];
        $seen = [$start => true];
        $linkedFrom = [];
        $external = [];
        $broken = [];
        $chains = [];
        $slow = [];
        $checked = 0;
        $stopped = null;

        while ($queue !== []) {
            if ($checked >= $maxPages) {
                $stopped = 'max_pages';
                break;
            }
            // One budget for the whole call: each request gets what is left
            // of it, never more than one page of somcp_audit_page is allowed.
            $left = (int) floor(self::TIME_LIMIT - (microtime(true) - $started));
            if ($left < 1) {
                $stopped = 'time_limit';
                break;
            }

            $url = (string) array_shift($queue);
            $fetched = SomcpToolAuditPage::follow($url, $hosts, min(SomcpToolAuditPage::TIMEOUT, $left));
            ++$checked;
            $response = $fetched['response'];
            $final = $fetched['final_url'];
            // The address a redirect lands on is a page of its own: it is not
            // fetched a second time when another page links to it directly.
            $seen[$final] = true;

            if ($fetched['outside'] !== null || count($fetched['redirects']) > 1) {
                $chain = [
                    'url' => $url,
                    'redirects' => $fetched['redirects'],
                    'final_url' => $fetched['outside'] ?? $final,
                ];
                if ($fetched['outside'] !== null) {
                    $chain['outside_shop'] = true;
                }
                $chains[] = $chain;
            }
            if ($fetched['outside'] !== null) {
                // The visitor leaves the shop there, which is reported above;
                // the address outside is never requested.
                continue;
            }

            if ($response === null || $response['status'] === 0) {
                $entry = ['url' => $url, 'status' => 0];
                $entry['error'] = $response === null ? 'timeout' : $response['error'];
                $broken[$url] = $entry;
                continue;
            }
            if ($response['status'] < 200 || $response['status'] >= 300) {
                $broken[$url] = ['url' => $url, 'status' => $response['status']];
                continue;
            }
            if ($fetched['time_ms'] > SomcpToolAuditPage::SLOW_MS) {
                $slow[] = ['url' => $url, 'time_ms' => $fetched['time_ms']];
            }

            // Only web pages hold further links: an image or a PDF answers
            // 200 and stops there.
            $type = strtolower((string) ($response['headers']['content-type'] ?? ''));
            if ($type !== '' && strpos($type, 'html') === false) {
                continue;
            }
            $dom = SomcpToolAuditPage::load($response['body']);
            if ($dom === null) {
                continue;
            }

            foreach (SomcpToolAuditPage::links(new DOMXPath($dom), $final) as $target) {
                if (!in_array(SomcpHttp::authority($target), $hosts, true)) {
                    $external[$target] = true;
                    continue;
                }
                if (!isset($linkedFrom[$target])) {
                    $linkedFrom[$target] = [];
                }
                if (count($linkedFrom[$target]) < self::MAX_LINKED_FROM && !in_array($final, $linkedFrom[$target], true)) {
                    $linkedFrom[$target][] = $final;
                }
                if (!isset($seen[$target])) {
                    $seen[$target] = true;
                    $queue[] = $target;
                }
            }
        }

        $brokenList = [];
        foreach ($broken as $url => $entry) {
            $entry['linked_from'] = $linkedFrom[$url] ?? [];
            $brokenList[] = $entry;
        }

        return [
            'pages_checked' => $checked,
            'broken' => $brokenList,
            'redirect_chains' => $chains,
            'slow_pages' => $slow,
            'external_links' => count($external),
            'stopped_early' => $stopped,
        ];
    }
}
