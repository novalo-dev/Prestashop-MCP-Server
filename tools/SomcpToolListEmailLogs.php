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
 * somcp_list_email_logs - the list of sent e-mails of Advanced Parameters >
 * E-mail.
 *
 * Read the way the core's EmailLogsQueryBuilder reads it: the table mail left
 * joined to lang, the texts filtered by LIKE, the day range from 0:00:00 to
 * 23:59:59. The table has no shop column, so the list is the same whatever
 * shop the token is for, as on the page.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolGetEmailSettings.php';
require_once __DIR__ . '/SomcpToolListStockMovements.php';

class SomcpToolListEmailLogs
{
    /** @var int Lines returned when the caller asks for no particular number. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Most a single call will return, whatever it asks for. */
    private const MAX_LIMIT = 100;

    /** @var array<int, string> The arguments the tool takes; any other is refused. */
    private const FIELDS = ['recipient', 'template', 'subject', 'language', 'date_from', 'date_to', 'limit', 'offset'];

    /** @var array<int, string> The text filters, each a column of the table matched by LIKE. */
    private const TEXTS = ['recipient', 'template', 'subject'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_list_email_logs',
            Somcp::t('List the sent e-mails'),
            'Lists the e-mails the shop sent, newest first, as the list of Advanced Parameters > E-mail: '
                . 'recipient, template, language, subject, when. Use it to check that order and account '
                . 'e-mails really go out. Only kept while Log Emails is on (log_emails). For the e-mail '
                . 'settings use somcp_get_email_settings. '
                . 'total_count counts every match: read the next page with offset.',
            [
                'type' => 'object',
                'properties' => [
                    'recipient' => [
                        'type' => 'string',
                        'description' => 'Lines whose Recipient contains this text.',
                    ],
                    'template' => [
                        'type' => 'string',
                        'description' => 'Lines whose Template contains this text, for example order_conf.',
                    ],
                    'subject' => [
                        'type' => 'string',
                        'description' => 'Lines whose Subject contains this text.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO code of one of the shop\'s active languages, for example fr: the '
                            . 'e-mails sent in it only. A code the shop does not have is refused.',
                    ],
                    'date_from' => [
                        'type' => 'string',
                        'description' => 'First day the e-mail was sent, YYYY-MM-DD, included.',
                    ],
                    'date_to' => [
                        'type' => 'string',
                        'description' => 'Last day the e-mail was sent, YYYY-MM-DD, included up to 23:59:59.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'How many lines to return, 20 by default and 100 at most.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'How many to skip, to read the next page.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            // Each line holds the address of a customer, so the scope is the
            // customers' one rather than the system's of the e-mail settings.
            [SomcpScope::CUSTOMERS_READ],
            [self::class, 'handle'],
            // The list sits on the E-mail page, so whoever may view that page
            // may read it.
            SomcpTool::acts(SomcpPermission::VIEW, 'AdminEmails')
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
        $idShop = (int) Somcp::currentShop()->id;

        // Every mistake is reported in one answer, so the call is corrected
        // in one go, through the shared envelope and wordings of #169.
        $problems = SomcpTool::unknownFields($arguments, self::FIELDS);

        $texts = [];
        foreach (self::TEXTS as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }
            if (!is_string($arguments[$field])) {
                $problems[] = $field . ': a text';
                continue;
            }
            $text = trim($arguments[$field]);
            if ($text !== '') {
                $texts[$field] = $text;
            }
        }

        // Left out means every language, as the filter of the list: the shop
        // default would hide the e-mails sent to customers of another one.
        $idLang = 0;
        if (array_key_exists('language', $arguments) && $arguments['language'] !== null && $arguments['language'] !== '') {
            $language = SomcpTool::readLanguage($arguments, $idShop);
            if ($language === null) {
                $problems[] = SomcpTool::languageProblem($idShop, true);
            } else {
                $idLang = $language[0];
            }
        }

        $dateFrom = SomcpToolListStockMovements::date($arguments, 'date_from', $problems);
        $dateTo = SomcpToolListStockMovements::date($arguments, 'date_to', $problems);
        if ($dateFrom !== null && $dateTo !== null && $dateTo < $dateFrom) {
            $problems[] = 'date_to: a date not before date_from';
        }

        $limit = self::DEFAULT_LIMIT;
        if (array_key_exists('limit', $arguments) && $arguments['limit'] !== null) {
            if (!is_int($arguments['limit']) || $arguments['limit'] < 1 || $arguments['limit'] > self::MAX_LIMIT) {
                $problems[] = 'limit: a whole number from 1 to ' . self::MAX_LIMIT;
            } else {
                $limit = (int) $arguments['limit'];
            }
        }
        $offset = 0;
        if (array_key_exists('offset', $arguments) && $arguments['offset'] !== null) {
            if (!is_int($arguments['offset']) || $arguments['offset'] < 0) {
                $problems[] = 'offset: a whole number, 0 at least';
            } else {
                $offset = (int) $arguments['offset'];
            }
        }

        if ($problems !== []) {
            return SomcpTool::refused('read', $problems);
        }

        $count = self::query($texts, $idLang, $dateFrom, $dateTo);
        $count->select('COUNT(*)');
        $total = (int) Db::getInstance()->getValue($count);

        $page = self::query($texts, $idLang, $dateFrom, $dateTo);
        $page->select('m.`id_mail`, m.`recipient`, m.`template`, m.`subject`, m.`date_add`, l.`iso_code`');
        // Newest first, as the list opens.
        $page->orderBy('m.`id_mail` DESC');
        $page->limit($limit, $offset);

        $emails = [];
        foreach (Db::getInstance()->executeS($page) ?: [] as $row) {
            $emails[] = [
                'id_mail' => (int) $row['id_mail'],
                'recipient' => (string) $row['recipient'],
                'template' => (string) $row['template'],
                // A language deleted since leaves no row to join: null rather
                // than a code the shop no longer has.
                'language' => isset($row['iso_code']) && $row['iso_code'] !== '' ? (string) $row['iso_code'] : null,
                'subject' => (string) $row['subject'],
                'sent' => SomcpTool::isoDate($row['date_add'] ?? null),
            ];
        }

        $logEmails = (bool) SomcpToolGetEmailSettings::settings($idShop)['log_emails'];

        $result = [
            'emails' => $emails,
            'returned' => count($emails),
            // So the assistant knows there is more instead of concluding from
            // one page that it has seen everything.
            'total_count' => $total,
            'offset' => $offset,
            'log_emails' => $logEmails,
        ];

        $warnings = [];
        // The page hides the list when the log is off; the tool still lists
        // what was kept, so it says why new e-mails will not appear.
        if (!$logEmails) {
            $warnings[] = 'Log Emails is off: e-mails sent from now on are not added to this list. '
                . 'Turn it on with somcp_update_email_settings (log_emails).';
        }
        if (Shop::isFeatureActive() && count(Shop::getShops(false, null, true)) > 1) {
            $warnings[] = 'This list holds the e-mails of every shop: PrestaShop keeps one log for all of them.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }

    /**
     * The query both the count and the page are built on: the join and the
     * filters, without what is selected.
     *
     * @param array<string, string> $texts the text filters given, by column
     * @param int $idLang 0 for every language
     * @param string|null $dateFrom YYYY-MM-DD
     * @param string|null $dateTo YYYY-MM-DD
     *
     * @return DbQuery
     */
    private static function query(array $texts, int $idLang, ?string $dateFrom, ?string $dateTo): DbQuery
    {
        $query = new DbQuery();
        $query->from('mail', 'm');
        $query->leftJoin('lang', 'l', 'l.`id_lang` = m.`id_lang`');

        foreach ($texts as $field => $text) {
            // The column comes from TEXTS, never from the caller.
            $query->where('m.`' . bqSQL($field) . '` LIKE \'%' . pSQL($text) . '%\'');
        }
        if ($idLang > 0) {
            $query->where('m.`id_lang` = ' . (int) $idLang);
        }
        if ($dateFrom !== null) {
            $query->where('m.`date_add` >= \'' . pSQL($dateFrom) . ' 00:00:00\'');
        }
        if ($dateTo !== null) {
            $query->where('m.`date_add` <= \'' . pSQL($dateTo) . ' 23:59:59\'');
        }

        return $query;
    }
}
