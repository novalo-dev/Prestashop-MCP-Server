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
 * somcp_create_carrier - Add new carrier of Shipping > Carriers, as Save
 * (9.0.0) or Finish (8.0.1) creates it: the Carrier name and the Transit
 * time, and in the same call any field somcp_update_carrier takes.
 *
 * The arguments are checked by the rules of somcp_update_carrier before
 * anything is written, so every mistake comes back in one answer. The carrier
 * is then added with its name, transit time, speed grade and status, as
 * Carrier::add() makes it on both versions, and the rest is written by the
 * write of somcp_update_carrier on the new identifier: no order uses it, so
 * no new version is made. A failure after add() deletes the carrier again, so
 * a call either creates the whole carrier or nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The languages and texts of #149, shared with every create tool.
require_once __DIR__ . '/SomcpToolCreateProduct.php';
// The checks and the write of a carrier, shared with the update.
require_once __DIR__ . '/SomcpToolUpdateCarrier.php';
require_once __DIR__ . '/SomcpToolGetCarrier.php';

class SomcpToolCreateCarrier extends SomcpToolCreateProduct
{
    /** @var array<int, string> The arguments of somcp_update_carrier a creation has no use for. */
    private const NOT_HERE = ['id_carrier', 'remove_logo'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $properties = SomcpToolUpdateCarrier::properties();
        foreach (self::NOT_HERE as $key) {
            unset($properties[$key]);
        }
        // The defaults of a new carrier, as CarrierFormDataProvider::getDefaultData() of 9.0.0 opens the form.
        $properties['name']['description'] = 'Carrier name, 64 characters at most.';
        $properties['delay']['description'] = 'Transit time, keyed by ISO code, for example {"en": "2 to 3 days"}; '
            . '512 characters at most. A language left out of languages takes the text of the default language.';
        $properties['languages']['description'] = 'ISO codes of the languages delay is given in; every active '
            . 'language when left out.';
        $properties['grade']['description'] = 'Speed grade, from 0 (slow) to 9 (fast); 0 when left out.';
        $properties['active']['description'] = 'Whether customers can choose the carrier; false when left out, '
            . 'so it is offered once its zones and prices are set.';
        $properties['group_access']['description'] = 'Every customer group allowed to use the carrier, as id_group '
            . 'integers from somcp_list_customer_groups; every group of the shop when left out.';
        $properties['zones']['description'] = 'Every zone the carrier delivers to, as id_zone from somcp_list_zones.';
        $properties['ranges']['description'] = 'The ranges of the billing: {from, to, prices: [{id_zone, '
            . 'price_tax_excl}]}, from included, to excluded, each id_zone among zones; null for no price.';
        $properties['logo'] = SomcpUpload::schema('Merchant. The logo of the carrier.', 'the logo');

        return new SomcpTool(
            'somcp_create_carrier',
            Somcp::t('Create a carrier'),
            'Creates a carrier of Shipping > Carriers: Carrier name and Transit time, and in the same call Speed '
                . 'grade, Logo, Tracking URL, Active, Group access, Zones (from somcp_list_zones), ranges and prices '
                . 'per zone, Free shipping, handling costs, billing, Tax, Out-of-range behavior, size and weight '
                . 'limits. Off until active is given. To change a carrier, somcp_update_carrier. Not replayable: a '
                . 'second call creates a second carrier, so read somcp_list_carriers first when an answer is lost.',
            [
                'type' => 'object',
                'properties' => $properties,
                'required' => ['name', 'delay'],
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // The right Add new carrier asks for in Team, Permissions.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminCarriers'),
            files: ['logo' => SomcpUpload::images()]
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

        // The fields of the read are refused as any other unknown field: a
        // creation has no carrier to compare them with.
        $errors = SomcpTool::unknownFields(
            $arguments,
            array_values(array_diff(SomcpToolUpdateCarrier::ARGUMENTS, self::NOT_HERE))
        );
        if (!array_key_exists('name', $arguments)) {
            $errors[] = 'name: required';
        }

        $languages = self::languages($arguments, $idShop, $errors);
        if ($languages === null) {
            return SomcpTool::refused('created', $errors);
        }
        if (!array_key_exists('delay', $arguments)) {
            // Answered in the wording of every translated text that is required.
            self::texts($arguments, 'delay', 'delay', $languages, $errors, true);
        }

        $checked = $arguments;
        // The new carrier is added after the others, so the last place counts
        // one more carrier than the rules of a change know of: there it stays.
        $last = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'carrier` WHERE `deleted` = 0');
        if (array_key_exists('position', $checked) && self::integer($checked['position']) === $last) {
            unset($checked['position']);
        }
        $first = SomcpToolUpdateCarrier::plan(
            array_diff_key($checked, array_flip(self::NOT_HERE)),
            null,
            0,
            $idShop,
            $languages,
            $errors
        );
        if ($errors !== []) {
            return SomcpTool::refused('created', $errors);
        }

        $logoFile = '';
        try {
            if ($first['logo'] !== null) {
                $refusal = SomcpToolUpdateCarrier::fetchLogo($first['logo'], $logoFile);
                if ($refusal !== '') {
                    return SomcpTool::refused('created', ['logo: ' . $refusal]);
                }
            }

            return self::asEmployee($idEmployee, static function () use ($arguments, $languages, $idShop, $idEmployee, $first, $logoFile): array {
                return self::addCarrier($arguments, $languages, $idShop, $idEmployee, $first['logo'], $logoFile);
            });
        } finally {
            if ($logoFile !== '') {
                @unlink($logoFile);
            }
        }
    }

    /**
     * Adds the carrier, writes the rest of the call on it, and answers with what was stored.
     *
     * @param array<string, mixed> $arguments
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     * @param int $idShop
     * @param int $idEmployee
     * @param array{url: string, upload_ref: string}|null $logo the logo admitted by the first check
     * @param string $logoFile the logo fetched, '' without one
     *
     * @return array<string, mixed>
     */
    private static function addCarrier(array $arguments, array $languages, int $idShop, int $idEmployee, ?array $logo, string $logoFile): array
    {
        $ignored = [];
        $delay = self::complete(self::texts($arguments, 'delay', 'delay', $languages, $ignored, true) ?? [], $languages);

        $carrier = new Carrier();
        $carrier->name = (string) $arguments['name'];
        // Through hydrate(): 8.0 declares the property one string while a
        // translated field takes a text per language, as 9.0 declares it. The
        // id_lang it resets is already null on a new carrier.
        $carrier->hydrate(['delay' => $delay]);
        // What the form opens with; the update below sets what was given.
        $carrier->grade = 0;
        $carrier->active = false;
        $carrier->id_shop_list = [$idShop];

        $idCarrier = 0;
        try {
            if (!$carrier->add()) {
                return self::creationFailed('PrestaShop refused to save it', $idCarrier, $idEmployee);
            }
            $idCarrier = (int) $carrier->id;

            // Every group of the shop, as getDefaultData() preselects them;
            // a group_access given replaces them in the update below.
            $groups = [];
            foreach (Db::getInstance()->executeS(
                'SELECT `id_group` FROM `' . _DB_PREFIX_ . 'group_shop` WHERE `id_shop` = ' . $idShop
            ) ?: [] as $row) {
                $groups[] = (int) $row['id_group'];
            }
            if ($groups !== [] && !$carrier->setGroups($groups)) {
                return self::creationFailed('PrestaShop refused its customer groups', $idCarrier, $idEmployee);
            }

            // Checked again against the carrier just added: what it already
            // holds is no change, and what the first check could not see
            // without a carrier (a price in a zone the carrier does not
            // serve) is told now. The logo was admitted once, and is not
            // taken a second time from its source.
            $errors = [];
            $read = SomcpToolGetCarrier::carrier($idCarrier, $idShop, (int) $languages['fallback']);
            $plan = $read === null ? null : SomcpToolUpdateCarrier::plan(
                array_diff_key($arguments, array_flip(array_merge(self::NOT_HERE, ['logo']))),
                $read,
                $idCarrier,
                $idShop,
                $languages,
                $errors
            );
            if ($plan === null || $errors !== []) {
                self::dropCarrier($idCarrier);

                return SomcpTool::refused('created', $errors === [] ? ['the carrier could not be read back'] : $errors);
            }
            if ($logo !== null) {
                $plan['logo'] = $logo;
                $plan['changes'][] = ['field' => 'logo', 'old' => null, 'new' => SomcpUpload::origin($logo)];
            }

            $written = null;
            if ($plan['changes'] !== []) {
                $written = SomcpToolUpdateCarrier::write($idCarrier, $idShop, $plan, (int) $languages['fallback'], $idEmployee, $logoFile, null);
                if (!empty($written['isError'])) {
                    return self::creationFailed('PrestaShop refused the rest of the carrier', $idCarrier, $idEmployee);
                }
            }
        } catch (Throwable $e) {
            return self::creationFailed($e->getMessage(), $idCarrier, $idEmployee);
        }

        $given = array_values(array_intersect(array_keys($arguments), SomcpToolUpdateCarrier::ARGUMENTS));
        SomcpLogger::log(
            'carrier_created',
            SomcpLogger::OUTCOME_SUCCESS,
            'carrier ' . $idCarrier . ', fields: ' . implode(', ', $given),
            '',
            $idEmployee
        );

        $stored = SomcpToolGetCarrier::carrier($idCarrier, $idShop, (int) $languages['fallback']);
        $warnings = [];
        if (!array_key_exists('zones', $arguments)) {
            $warnings[] = 'The carrier serves no zone yet: no customer can choose it until it has zones, with somcp_update_carrier.';
        } elseif ($written !== null) {
            // The sentence of somcp_update_carrier for a zone added without a price, as it said it.
            $answer = json_decode((string) ($written['content'][0]['text'] ?? ''), true);
            $said = is_array($answer) ? (string) ($answer['warning'] ?? '') : '';
            if (preg_match('/The zone\(s\) [^:]+: the shop does not offer this carrier there until its ranges get one\./', $said, $match)) {
                $warnings[] = $match[0];
            }
        }
        if (!($stored['active'] ?? false)) {
            $warnings[] = 'The carrier is off: customers can choose it once it is switched on with somcp_update_carrier.';
        }
        if (SomcpToolCreateTax::otherShops() > 0) {
            $warnings[] = 'The carrier was created for this shop only.';
        }

        $result = [
            'created' => true,
            'id_carrier' => $idCarrier,
            'carrier' => $stored,
        ];
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }

    /**
     * Deletes the carrier this call added, so a failure keeps nothing.
     *
     * Carrier::delete() rather than the deleted flag: no order can use a
     * carrier added a moment ago, and its rows go with it.
     *
     * @param int $idCarrier
     *
     * @return void
     */
    private static function dropCarrier(int $idCarrier): void
    {
        if ($idCarrier === 0) {
            return;
        }
        $carrier = new Carrier($idCarrier);
        if (Validate::isLoadedObject($carrier)) {
            $carrier->delete();
        }
        @unlink(_PS_SHIP_IMG_DIR_ . $idCarrier . '.jpg');
    }

    /**
     * The answer to a creation that failed once something was written.
     *
     * @param string $failure
     * @param int $idCarrier the carrier added, 0 before add()
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function creationFailed(string $failure, int $idCarrier, int $idEmployee): array
    {
        self::dropCarrier($idCarrier);
        SomcpLogger::log(
            'carrier_create_failed',
            SomcpLogger::OUTCOME_FAILURE,
            Tools::substr($failure, 0, 500),
            '',
            $idEmployee
        );

        return SomcpTool::text('The carrier could not be created: ' . $failure . '. Nothing was kept.', true);
    }
}
