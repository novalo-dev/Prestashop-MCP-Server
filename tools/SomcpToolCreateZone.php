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
 * somcp_create_zone - one zone of International > Locations > Zones, as Add
 * new zone then Save: a name, and whether shipping to it is allowed.
 *
 * A carrier is offered at checkout only in the zones it serves, and its prices
 * are set per zone: without this tool an assistant setting up the delivery of
 * a shop could only use the zones the install left, never the one a merchant
 * needs (the neighbouring countries, the mainland apart from the islands).
 * The answer is the zone read back through the row function of
 * somcp_list_zones, so the creation and the list give the same fields.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// answer is the zone as somcp_list_zones returns it.
require_once __DIR__ . '/SomcpToolListZones.php';

class SomcpToolCreateZone
{
    /** @var int The size of the name column (Zone::$definition), used only when the column cannot be read. */
    public const NAME_MAX = 64;

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_create_zone',
            Somcp::t('Create a zone'),
            'Creates one zone of International > Locations > Zones, as Add new zone then Save: Name and Active. A '
                . 'zone groups countries for the carriers and their prices per zone. Read somcp_list_zones first: '
                . 'the same call twice creates two zones. The name is the merchant\'s - ask, never invent. A new '
                . 'zone holds no country. To give zones to a carrier use somcp_update_carrier; to rename or '
                . 'disable a zone, somcp_update_zone.',
            [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Merchant. Required. Name, as the merchant sees it in the carrier form (e.g. '
                            . 'Neighboring Countries), 64 characters at most, without < > = { }.',
                    ],
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Merchant. Active: true allows shipping to this zone. true by default, as '
                            . 'in the form.',
                    ],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            [SomcpScope::ORDERS_WRITE],
            [self::class, 'handle'],
            // Adding a zone is what the zones page asks for in Team,
            // Permissions; editing one is a different right.
            SomcpTool::acts(SomcpPermission::ADD, 'AdminZones')
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

        // id_zone and countries are what a row of somcp_list_zones holds: named,
        // so an assistant giving a row back learns why they are not taken.
        $errors = SomcpTool::unknownFields($arguments, ['name', 'active'], ['id_zone', 'countries']);
        $name = self::name($arguments, true, $errors);
        $active = true;
        if (array_key_exists('active', $arguments)) {
            // A boolean only: "false" cast to bool is true, and would allow
            // shipping to a zone the merchant wanted closed.
            if (!is_bool($arguments['active'])) {
                $errors[] = 'active: true or false';
            } else {
                $active = $arguments['active'];
            }
        }
        if ($errors !== [] || $name === null) {
            return SomcpTool::refused('created', $errors);
        }

        $zone = new Zone();
        $zone->name = $name;
        $zone->active = $active;
        // Tied to the shop of the token only, as the form does with Shop
        // association on that shop: the list of a shop reads zone_shop.
        $zone->id_shop_list = [$idShop];

        try {
            $done = $zone->add();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('zone_create_failed', SomcpLogger::OUTCOME_FAILURE, Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'The zone could not be created: ' . $failure . '. Read somcp_list_zones before calling again.',
                true
            );
        }

        $idZone = (int) $zone->id;
        SomcpLogger::log('zone_created', SomcpLogger::OUTCOME_SUCCESS, 'zone ' . $idZone, '', $idEmployee);

        $warnings = ['The zone has no country yet: a carrier given this zone is offered to no address until '
            . 'countries are moved into it.'];
        $same = self::sameName($idShop, $name, $idZone);
        if ($same !== null) {
            $warnings[] = $same;
        }

        return SomcpTool::json([
            'created' => true,
            'id_zone' => $idZone,
            'warning' => implode(' ', $warnings),
            // Read back rather than echoed from the arguments: what the
            // assistant shows the merchant is what the shop stored.
            'zone' => SomcpToolListZones::zone($idShop, $idZone),
        ]);
    }

    /**
     * The name given in the call, checked with the running version's rules, or null with the reason.
     *
     * Public so somcp_update_zone takes a name the same way.
     *
     * @param array<string, mixed> $arguments
     * @param bool $required
     * @param array<int, string> $errors
     *
     * @return string|null
     */
    public static function name(array $arguments, bool $required, array &$errors): ?string
    {
        if (!array_key_exists('name', $arguments) || $arguments['name'] === null) {
            if ($required) {
                $errors[] = 'name: required';
            }

            return null;
        }
        if (!is_string($arguments['name'])) {
            $errors[] = 'name: a text';

            return null;
        }
        $name = trim($arguments['name']);
        if ($name === '') {
            // Required when given: an empty name leaves a zone nobody can
            // pick in the carrier form.
            $errors[] = 'name: required';

            return null;
        }
        $probe = new Zone();
        $max = SomcpTool::columnSize($probe, 'name', self::NAME_MAX);
        if (Tools::strlen($name) > $max) {
            $errors[] = 'name: ' . $max . ' characters at most, ' . Tools::strlen($name) . ' given';

            return null;
        }
        // Zone::$name is checked with isGenericName.
        $check = $probe->validateField('name', $name);
        if ($check !== true) {
            $errors[] = 'name: a character PrestaShop refuses in a zone name (< > = { }) - '
                . strip_tags(is_string($check) ? $check : 'invalid');

            return null;
        }

        return $name;
    }

    /**
     * The warning for a name another zone of the shop already has, or null.
     *
     * The form takes it, so it is not refused, but the merchant would see two
     * zones of that name in the carrier form and could not tell them apart.
     *
     * @param int $idShop
     * @param string $name
     * @param int $idZone the zone being written, left out of the comparison
     *
     * @return string|null
     */
    public static function sameName(int $idShop, string $name, int $idZone): ?string
    {
        $other = (int) Db::getInstance()->getValue(
            'SELECT z.`id_zone` FROM `' . _DB_PREFIX_ . 'zone` z'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'zone_shop` zs ON zs.`id_zone` = z.`id_zone` AND zs.`id_shop` = ' . (int) $idShop
            . " WHERE LOWER(z.`name`) = '" . pSQL(Tools::strtolower($name)) . "'"
            . ' AND z.`id_zone` <> ' . (int) $idZone
            . ' ORDER BY z.`id_zone` ASC'
        );
        if ($other === 0) {
            return null;
        }

        return 'Zone ' . $other . ' already has this name: the merchant sees two zones named ' . $name
            . ' in the carrier form.';
    }
}
