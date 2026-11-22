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
 * somcp_update_carrier - the General settings of one carrier of Shipping >
 * Carriers, as its edit page saves them: Carrier name, Transit time, Speed
 * grade, Tracking URL, Enabled / Active and Group access, and its place in
 * the list. Only what is given changes; a call that would change nothing
 * writes nothing.
 *
 * The two versions of PrestaShop save a carrier differently: the 8.0.1 wizard
 * makes a new carrier at every save, 9.0.0 changes it in place while no order
 * uses it. The tool follows 9.0.0 on both: the new version only exists so
 * that past orders keep the carrier they were placed with, and a new
 * identifier at every call would break every id_carrier just read.
 *
 * It takes the answer of somcp_get_carrier back as it is: the fields other
 * tools write are compared with the shop and named only when they differ.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpToolUpdateCarrier
{
    /** @var array<int, string> The arguments this tool writes, under the names somcp_get_carrier returns. */
    private const ARGUMENTS = ['id_carrier', 'languages', 'name', 'delay', 'grade', 'tracking_url', 'active', 'group_access', 'position'];

    /**
     * @var array<int, string> The other keys of the answer of somcp_get_carrier,
     *                         passed when they hold what the shop holds, so a read given back changes nothing
     */
    private const READ_BACK = [
        'id_reference', 'is_free', 'logo', 'external_module', 'zones', 'id_tax_rules_group',
        'tax_rule', 'handling_costs', 'handling_cost_tax_excl', 'billing', 'out_of_range', 'max_width',
        'max_height', 'max_depth', 'max_weight', 'ranges', 'range_unit', 'dimension_unit', 'weight_unit',
    ];

    /** @var array<int, string> Keys of the read that describe the answer rather than the carrier. */
    private const READ_ONLY = ['language', 'warning'];

    /** @var int The size of carrier.url, which the definition does not declare. */
    private const URL_SIZE = 255;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_carrier',
            Somcp::t('Change a carrier'),
            'Changes the General settings of one carrier of Shipping > Carriers: Carrier name, Transit time, '
                . 'Speed grade, Tracking URL, Active, Group access and the place in the list. Only the fields given change; the answer '
                . 'of somcp_get_carrier can be given back as it is. A carrier an order uses gets a new '
                . 'id_carrier, which the answer gives. Not for the carrier of one order '
                . '(somcp_set_order_carrier). Replayable: the same call twice changes nothing the second time.',
            [
                'type' => 'object',
                'properties' => [
                    'id_carrier' => [
                        'type' => 'integer',
                        'description' => 'The carrier to change, from somcp_list_carriers or somcp_get_carrier.',
                    ],
                    'languages' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'ISO codes of the languages delay is given in; every active language '
                            . 'when left out. A language left out keeps its text.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Carrier name, 64 characters at most.',
                    ],
                    'delay' => [
                        'type' => 'object',
                        'description' => 'Transit time, keyed by ISO code, for example {"en": "2 to 3 days"}; '
                            . '512 characters at most.',
                    ],
                    'grade' => [
                        'type' => 'integer',
                        'description' => 'Speed grade, from 0 (slow) to 9 (fast).',
                    ],
                    'tracking_url' => [
                        'type' => 'string',
                        'description' => 'Tracking URL, @ where the tracking number goes; an empty text '
                            . 'removes it.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Whether customers can choose the carrier.',
                    ],
                    'group_access' => [
                        'type' => 'array',
                        'description' => 'Every customer group allowed to use the carrier after the change, '
                            . 'as id_group integers from somcp_list_customer_groups or the objects '
                            . 'somcp_get_carrier answers.',
                    ],
                    'position' => [
                        'type' => 'integer',
                        'description' => 'Merchant. The place among the carriers, from 0 as somcp_list_carriers '
                            . 'gives it (the back office list shows it plus 1); the others shift.',
                    ],
                ],
                'required' => ['id_carrier'],
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The right the carrier edit page asks for in Team, Permissions.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminCarriers')
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = SomcpTool::unknownFields(
            $arguments,
            array_merge(self::ARGUMENTS, self::READ_BACK, self::READ_ONLY)
        );

        $idCarrier = SomcpTool::id($arguments, 'id_carrier');
        $read = null;
        if ($idCarrier === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_carrier');
        } else {
            $read = SomcpToolGetCarrier::carrier($idCarrier, $idShop, (int) Configuration::get('PS_LANG_DEFAULT'));
            if ($read === null) {
                $errors[] = SomcpToolGetCarrier::notFound($idCarrier, $idShop);
            }
        }

        $languages = SomcpToolCreateProduct::languages($arguments, $idShop, $errors);
        $plan = $languages === null ? null : self::plan($arguments, $read, $idCarrier, $idShop, $languages, $errors);
        if ($errors !== [] || $plan === null || $read === null) {
            return SomcpTool::refused('changed', $errors);
        }

        if ($plan['changes'] === []) {
            return SomcpTool::json([
                'changed' => false,
                'id_carrier' => $idCarrier,
                'reason' => 'The carrier already holds every value given. Nothing was written.',
                'carrier' => SomcpToolGetCarrier::carrier($idCarrier, $idShop, (int) $languages['fallback']),
            ]);
        }

        return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($idCarrier, $idShop, $plan, $languages, $idEmployee): array {
            return self::apply($idCarrier, $idShop, $plan, (int) $languages['fallback'], $idEmployee);
        });
    }

    /**
     * Turns the arguments into what will change, or says everything that is wrong with them.
     *
     * Nothing is written here. A field equal to what the shop holds is left
     * out of the changes, so what remains is exactly what will change. Run
     * even when the carrier is unknown, so every problem is told at once.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $read the carrier as somcp_get_carrier answers it
     * @param int $idCarrier
     * @param int $idShop
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param array<int, string> $errors
     *
     * @return array<string, mixed>
     */
    private static function plan(array $arguments, ?array $read, int $idCarrier, int $idShop, array $languages, array &$errors): array
    {
        $probe = new Carrier();
        $stored = $read === null ? null : self::stored($idCarrier, $idShop);
        $plan = ['changes' => [], 'set' => [], 'groups' => null, 'position' => null];

        if ($read !== null) {
            foreach (self::READ_BACK as $key) {
                if (!array_key_exists($key, $arguments)) {
                    continue;
                }
                // A download_ref carries its own expiry, so the one given back
                // is not the one a new read would answer: it is recognised by
                // the file it names, as the other updates recognise theirs.
                $same = $key === 'logo'
                    ? SomcpUpload::same($arguments[$key], 'carrier_logo', (string) $idCarrier)
                    : self::same($arguments[$key], $read[$key] ?? null);
                if (!$same) {
                    $errors[] = $key . ': changed by another tool, not this one - leave it out or give it as '
                        . 'somcp_get_carrier answers it';
                }
            }
        }

        if (array_key_exists('name', $arguments)) {
            $name = $arguments['name'];
            $size = SomcpTool::columnSize($probe, 'name', 64);
            if (!is_string($name) || trim($name) === '') {
                $errors[] = 'name: a text, ' . $size . ' characters at most';
            } elseif (Tools::strlen($name) > $size) {
                $errors[] = 'name: ' . $size . ' characters at most';
            } elseif (!Validate::isCarrierName($name)) {
                $errors[] = 'name: letters, digits and spaces, without < > ; = # { }';
            } elseif ($stored !== null && $name !== SomcpTool::carrierName($stored['name']) && $name !== $stored['name']) {
                $plan['changes'][] = ['field' => 'name', 'old' => SomcpTool::carrierName($stored['name']), 'new' => $name];
                $plan['set']['name'] = $name;
            }
        }

        if (array_key_exists('delay', $arguments)) {
            self::planDelay($arguments, $stored, $languages, $probe, $errors, $plan);
        }

        if (array_key_exists('grade', $arguments)) {
            $grade = $arguments['grade'];
            if (!is_int($grade) || $grade < 0 || $grade > 9) {
                $errors[] = 'grade: an integer from 0 to 9';
            } elseif ($stored !== null && $grade !== $stored['grade']) {
                $plan['changes'][] = ['field' => 'grade', 'old' => $stored['grade'], 'new' => $grade];
                $plan['set']['grade'] = $grade;
            }
        }

        if (array_key_exists('tracking_url', $arguments)) {
            $url = $arguments['tracking_url'];
            if (!is_string($url) || Tools::strlen($url) > self::URL_SIZE
                || ($url !== '' && !Validate::isAbsoluteUrl($url))) {
                $errors[] = 'tracking_url: an absolute URL of ' . self::URL_SIZE . ' characters at most, for '
                    . 'example https://carrier.example/track?n=@, or "" for none';
            } elseif ($stored !== null && $url !== $stored['url']) {
                $plan['changes'][] = ['field' => 'tracking_url', 'old' => $stored['url'], 'new' => $url];
                $plan['set']['url'] = $url;
            }
        }

        if (array_key_exists('active', $arguments)) {
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } elseif ($stored !== null && $arguments['active'] !== $stored['active']) {
                $plan['changes'][] = ['field' => 'active', 'old' => $stored['active'], 'new' => $arguments['active']];
                $plan['set']['active'] = $arguments['active'];
            }
        }

        if (array_key_exists('group_access', $arguments)) {
            $groups = self::groups($arguments['group_access'], $idShop, $errors);
            if ($groups !== null && $stored !== null && $groups !== $stored['groups']) {
                $plan['changes'][] = ['field' => 'group_access', 'old' => $stored['groups'], 'new' => $groups];
                $plan['groups'] = $groups;
            }
        }

        if (array_key_exists('position', $arguments)) {
            // Carriers have one order for the whole installation, counted
            // among those not deleted, as Carrier::updatePosition() counts them.
            $last = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'carrier` WHERE `deleted` = 0'
            ) - 1;
            $position = SomcpToolCreateProduct::integer($arguments['position']);
            if ($position === null || $position < 0 || $position > $last) {
                $errors[] = 'position: a whole number from 0 to ' . $last . ', the place among the carriers';
            } elseif ($stored !== null && $position !== $stored['position']) {
                $plan['changes'][] = ['field' => 'position', 'old' => $stored['position'], 'new' => $position];
                $plan['position'] = $position;
            }
        }

        return $plan;
    }

    /**
     * Records the Transit time change per language, and the full set of texts to write.
     *
     * somcp_get_carrier answers the transit time as one text, in the language
     * it was read in. On a shop with several languages such a text names no
     * language: it is passed when it is the stored text of one of them, the
     * read given back as it is, as somcp_update_order_status does with its
     * name, and refused as ambiguous otherwise by the shared parser.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed>|null $stored from stored()
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param Carrier $probe
     * @param array<int, string> $errors
     * @param array<string, mixed> $plan
     *
     * @return void
     */
    private static function planDelay(array $arguments, ?array $stored, array $languages, Carrier $probe, array &$errors, array &$plan): void
    {
        if ($stored !== null && is_string($arguments['delay']) && count($languages['filled']) > 1
            && in_array($arguments['delay'], $stored['delay'], true)) {
            return;
        }
        $texts = SomcpToolCreateProduct::texts($arguments, 'delay', 'delay', $languages, $errors, true);
        if ($texts === null) {
            return;
        }
        $size = SomcpTool::columnSize($probe, 'delay', 512);
        $merged = $stored === null ? [] : $stored['delay'];
        $changed = false;
        foreach ($texts as $idLang => $text) {
            $iso = $languages['filled'][$idLang];
            if (trim($text) === '') {
                // Already said by texts(), which requires a text in every language filled.
                continue;
            }
            if (Tools::strlen($text) > $size) {
                $errors[] = 'delay (' . $iso . '): ' . $size . ' characters at most';
                continue;
            }
            if (!Validate::isGenericName($text)) {
                $errors[] = 'delay (' . $iso . '): a text without < > = { }';
                continue;
            }
            $current = (string) ($merged[$idLang] ?? '');
            if ($stored === null || $current === $text) {
                continue;
            }
            $merged[$idLang] = $text;
            $changed = true;
            $plan['changes'][] = ['field' => 'delay (' . $iso . ')', 'old' => $current, 'new' => $text];
        }
        if ($changed) {
            $plan['set']['delay'] = $merged;
        }
    }

    /**
     * The customer groups given, as sorted identifiers, or null when one is wrong.
     *
     * The full list after the change: either the objects somcp_get_carrier
     * answers, whose name is ignored, or bare identifiers.
     *
     * @param mixed $value
     * @param int $idShop
     * @param array<int, string> $errors
     *
     * @return array<int, int>|null
     */
    private static function groups($value, int $idShop, array &$errors): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $errors[] = 'group_access: a list of id_group from somcp_list_customer_groups';

            return null;
        }
        if ($value === []) {
            $errors[] = 'group_access: at least one customer group';

            return null;
        }
        $known = [];
        foreach (Db::getInstance()->executeS(
            'SELECT `id_group` FROM `' . _DB_PREFIX_ . 'group_shop` WHERE `id_shop` = ' . $idShop
        ) ?: [] as $row) {
            $known[(int) $row['id_group']] = true;
        }

        $ids = [];
        $wrong = false;
        foreach ($value as $index => $group) {
            $id = SomcpTool::id(is_array($group) ? $group : ['id_group' => $group], 'id_group');
            if ($id === 0) {
                $errors[] = SomcpTool::notAnIdentifier('group_access[' . $index . ']');
                $wrong = true;
                continue;
            }
            if (!isset($known[$id])) {
                $errors[] = SomcpTool::notFound('group_access', 'customer group', $id, 'somcp_list_customer_groups');
                $wrong = true;
                continue;
            }
            $ids[$id] = $id;
        }
        if ($wrong) {
            return null;
        }
        $ids = array_values($ids);
        sort($ids);

        return $ids;
    }

    /**
     * What the shop stores for the fields this tool writes.
     *
     * @param int $idCarrier
     * @param int $idShop
     *
     * @return array{name: string, grade: int, url: string, active: bool, position: int, delay: array<int, string>, groups: array<int, int>, orders: int}
     */
    private static function stored(int $idCarrier, int $idShop): array
    {
        $db = Db::getInstance();
        $row = $db->getRow(
            'SELECT `name`, `grade`, `url`, `active`, `position` FROM `' . _DB_PREFIX_ . 'carrier`'
            . ' WHERE `id_carrier` = ' . $idCarrier
        ) ?: [];

        // Every language of this shop, so a language not filled keeps its text when the rest is written.
        $delay = [];
        foreach ($db->executeS(
            'SELECT `id_lang`, `delay` FROM `' . _DB_PREFIX_ . 'carrier_lang`'
            . ' WHERE `id_carrier` = ' . $idCarrier . ' AND `id_shop` = ' . $idShop
        ) ?: [] as $line) {
            $delay[(int) $line['id_lang']] = (string) $line['delay'];
        }

        $groups = [];
        foreach ($db->executeS(
            'SELECT `id_group` FROM `' . _DB_PREFIX_ . 'carrier_group` WHERE `id_carrier` = ' . $idCarrier
            . ' ORDER BY `id_group` ASC'
        ) ?: [] as $line) {
            $groups[] = (int) $line['id_group'];
        }

        return [
            'name' => (string) ($row['name'] ?? ''),
            'grade' => (int) ($row['grade'] ?? 0),
            'url' => (string) ($row['url'] ?? ''),
            'active' => (bool) ($row['active'] ?? false),
            'position' => (int) ($row['position'] ?? 0),
            'delay' => $delay,
            'groups' => $groups,
            'orders' => self::orders($idCarrier),
        ];
    }

    /**
     * The orders placed with the carrier, counted as the 9.0.0 core counts
     * them (CarrierRepository::getOrdersCount()) to decide on a new version.
     *
     * @param int $idCarrier
     *
     * @return int
     */
    private static function orders(int $idCarrier): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_carrier` WHERE `id_carrier` = ' . $idCarrier
        );
    }

    /**
     * Whether a value given back is the one the read answers.
     *
     * JSON does not keep 0 and 0.0 apart, so numbers are compared as numbers.
     *
     * @param mixed $given
     * @param mixed $read
     *
     * @return bool
     */
    private static function same($given, $read): bool
    {
        if ((is_int($given) || is_float($given)) && (is_int($read) || is_float($read))) {
            return (float) $given === (float) $read;
        }
        if (is_array($given) && is_array($read)) {
            if (count($given) !== count($read) || array_keys($given) !== array_keys($read)) {
                return false;
            }
            foreach ($read as $key => $value) {
                if (!self::same($given[$key], $value)) {
                    return false;
                }
            }

            return true;
        }

        return $given === $read;
    }

    /**
     * Writes the change, in place or on a new version, and answers with what was stored.
     *
     * @param int $idCarrier
     * @param int $idShop
     * @param array<string, mixed> $plan
     * @param int $idLang for the carrier read back
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function apply(int $idCarrier, int $idShop, array $plan, int $idLang, int $idEmployee): array
    {
        $carrier = new Carrier($idCarrier);
        $oldGroups = array_map('intval', array_column($carrier->getGroups() ?: [], 'id_group'));
        $newId = $idCarrier;
        $replaced = false;

        // A move alone is not a change of the carrier: neither version of the
        // core makes a new version for it, so the orders keep it as it is.
        $edits = $plan['set'] !== [] || $plan['groups'] !== null;

        try {
            if ($edits && self::orders($idCarrier) > 0) {
                // As 9.0.0 does in CarrierRepository::getEditableOrNewVersion():
                // the orders keep the old row, hidden, and the change goes on a
                // copy that takes over its ranges, zones, logo, tax and reference.
                $copy = $carrier->duplicateObject();
                if (!$copy instanceof Carrier) {
                    return self::failed($idCarrier, null, 'PrestaShop could not copy the carrier', $idEmployee);
                }
                $carrier->deleted = true;
                $carrier->id_shop_list = [$idShop];
                if (!$carrier->update()) {
                    $copy->delete();

                    return self::failed($idCarrier, null, 'PrestaShop refused to save it', $idEmployee);
                }
                $copy->copyCarrierData($idCarrier);
                $copy->setGroups($oldGroups);
                $newId = (int) $copy->id;
                $replaced = true;
                $carrier = new Carrier($newId);
            }

            if (array_key_exists('name', $plan['set'])) {
                $carrier->name = $plan['set']['name'];
            }
            if (array_key_exists('grade', $plan['set'])) {
                $carrier->grade = (int) $plan['set']['grade'];
            }
            if (array_key_exists('url', $plan['set'])) {
                $carrier->url = $plan['set']['url'];
            }
            if (array_key_exists('active', $plan['set'])) {
                $carrier->active = (bool) $plan['set']['active'];
            }
            if (array_key_exists('delay', $plan['set'])) {
                $carrier->delay = $plan['set']['delay'];
            }
            // The transit time is stored per shop: the shop of the token only,
            // the other shops keep their own text. update() also writes back
            // the shipping_method the constructor resolved from
            // PS_SHIPPING_METHOD when 0 was stored, as the form saves it: the
            // billing the merchant sees does not change.
            $carrier->id_shop_list = [$idShop];
            $done = !$edits || $carrier->update();
            if ($done && $plan['groups'] !== null) {
                $done = (bool) $carrier->setGroups($plan['groups']);
            }
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }

        if (!$done) {
            return self::failed($idCarrier, $replaced ? $newId : null, $failure, $idEmployee);
        }

        if ($plan['position'] !== null) {
            // Written last, on the carrier now in use: a new version stands
            // where the old one stood, then moves. Carrier::updatePosition()
            // finds the carrier from its own id, so no request value is needed.
            $failure = SomcpCatalog::movePosition($carrier, (int) $carrier->position, (int) $plan['position']);
            if ($failure !== '') {
                SomcpLogger::log(
                    'carrier_update_failed',
                    SomcpLogger::OUTCOME_FAILURE,
                    'carrier ' . $newId . ' position: ' . Tools::substr($failure, 0, 500),
                    '',
                    $idEmployee
                );

                return SomcpTool::text(
                    'The carrier could not be moved to position ' . (int) $plan['position'] . ': ' . $failure
                        . '. The other changes of this call may be saved. Read the carrier again with '
                        . 'somcp_get_carrier, tell the merchant what it holds now, and do not call again before.',
                    true
                );
            }
        }

        if ($replaced) {
            // What the 8.0.1 wizard fires on every save: shipping modules
            // follow their carrier across versions with it. Nothing of the
            // 9.0.0 core listens to it.
            Hook::exec('actionCarrierUpdate', ['id_carrier' => $idCarrier, 'carrier' => $carrier]);
        }
        SomcpLogger::log('carrier_updated', SomcpLogger::OUTCOME_SUCCESS, 'carrier ' . $newId, '', $idEmployee);

        $result = [
            'changed' => true,
            'id_carrier' => $newId,
        ];
        $warnings = [];
        if ($replaced) {
            $result['replaced_id_carrier'] = $idCarrier;
            $warnings[] = 'An order uses this carrier, so the shop keeps it for that order and gives the carrier '
                . 'a new identifier, ' . $newId . '. Use ' . $newId . ' from now on.';
        }
        $result['changes'] = $plan['changes'];
        // Said under the key the tax tools use for the same fact (#314).
        $otherShops = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'carrier_shop` WHERE `id_carrier` = ' . $newId
            . ' AND `id_shop` <> ' . $idShop
        );
        if ($otherShops > 0) {
            $result['also_in_other_shops'] = $otherShops;
            $warnings[] = 'This carrier is also in ' . $otherShops . ' other shop(s): its name, Speed grade, '
                . 'Tracking URL, status and groups change there too, as they do from the back office; the '
                . 'Transit time was changed for this shop only.';
        }
        if ($plan['position'] !== null && SomcpToolCreateTax::otherShops() > 0) {
            $warnings[] = 'The place in the list of carriers is the same in every shop of this installation, so '
                . 'the new order shows in the other shops too.';
        }
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }
        // Read back rather than echoed: what the assistant shows is what the
        // shop stored, under the new identifier for a carrier orders use.
        $result['carrier'] = SomcpToolGetCarrier::carrier($newId, $idShop, $idLang);

        return SomcpTool::json($result);
    }

    /**
     * The answer to a save that failed, naming both identifiers once a new version exists.
     *
     * @param int $idCarrier
     * @param int|null $newId
     * @param string $failure
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function failed(int $idCarrier, ?int $newId, string $failure, int $idEmployee): array
    {
        SomcpLogger::log(
            'carrier_update_failed',
            SomcpLogger::OUTCOME_FAILURE,
            'carrier ' . $idCarrier . ($newId === null ? '' : ' copied to carrier ' . $newId),
            '',
            $idEmployee
        );
        if ($newId !== null) {
            return SomcpTool::text(
                'The carrier was copied to a new identifier, ' . $newId . ', and the old one, ' . $idCarrier
                    . ', is now hidden, but PrestaShop reported a failure: ' . $failure
                    . '. Read the carrier ' . $newId . ' with somcp_get_carrier before calling again.',
                true
            );
        }

        return SomcpTool::text(
            'The carrier could not be changed: ' . $failure . '. Read it with somcp_get_carrier before calling again.',
            true
        );
    }
}
