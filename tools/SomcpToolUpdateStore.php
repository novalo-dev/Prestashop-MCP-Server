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
 * somcp_update_store - Edit then Save of a store of Shop Parameters >
 * Contact, tab Stores, and its Enabled switch.
 *
 * Save posts every field of the form, so the screen checks them all; this
 * tool checks only the fields that differ from the store as read, through
 * SomcpToolCreateStore::planStore(), the very rules of the create. A value
 * saved before a rule changed is then never refused when given back, and an
 * item of somcp_list_stores given back whole changes nothing.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/SomcpToolCreateStore.php';

class SomcpToolUpdateStore extends SomcpToolCreateStore
{
    /** @var array<int, string> Answered by somcp_list_stores, taken only as read. */
    private const READ_ONLY = ['id_country', 'id_state', 'country_name', 'state_name', 'date_add', 'date_upd', 'image'];

    /** @var array<int, string> The plain texts, compared as given. */
    private const PLAIN_TEXTS = ['city', 'phone', 'fax', 'email', 'postcode'];

    /** @var array<int, string> The translated texts. */
    private const TRANSLATED_TEXTS = ['name', 'address1', 'address2', 'note'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        $schema = self::storeSchema(['id_store']);
        $schema['properties'] = [
            'id_store' => [
                'type' => 'integer',
                'description' => 'The store to change, from somcp_list_stores.',
            ],
        ] + $schema['properties'];
        $schema['properties']['active']['description'] = 'Active: whether the storefront page of the stores shows '
            . 'it, as the Enabled switch of the list.';
        // Declared so a client that checks arguments against the schema
        // accepts an item of the list given back whole.
        foreach (self::READ_ONLY as $field) {
            $schema['properties'][$field] = [
                'description' => 'Read by somcp_list_stores: taken only as the list gives it, so an item can be given back whole.',
            ];
        }

        return new SomcpTool(
            'somcp_update_store',
            Somcp::t('Change a store'),
            'Changes a store of Shop Parameters > Contact > Stores, as Edit then Save, or switches it on or off as '
                . 'the Enabled switch: name, address, country and state by ISO code, coordinates, phone, e-mail, '
                . 'note, opening hours. Only the fields given change; an item of somcp_list_stores given back whole '
                . 'changes nothing. To add a store use somcp_create_store; for the address of the shop itself '
                . 'somcp_update_contact_details.',
            $schema,
            [SomcpScope::CUSTOMERS_WRITE],
            [self::class, 'handle'],
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminStores')
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
        $idEmployee = (int) ($context['id_employee'] ?? 0);

        $errors = SomcpTool::unknownFields($arguments, array_merge(['id_store'], self::STORE_FIELDS, self::READ_ONLY));

        $store = null;
        $idStore = SomcpTool::id($arguments, 'id_store');
        if (!array_key_exists('id_store', $arguments)) {
            $errors[] = 'id_store: required - find it with somcp_list_stores';
        } elseif ($idStore === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_store');
        } else {
            $loaded = new Store($idStore);
            // A store of another shop is answered as unknown, as the list of
            // this shop does not show it.
            if (Validate::isLoadedObject($loaded) && $loaded->isAssociatedToShop($idShop)) {
                $store = $loaded;
            } else {
                $errors[] = SomcpTool::notFound('id_store', 'store', $idStore, 'somcp_list_stores');
            }
        }

        $probe = [];
        $languages = self::languages($arguments, $idShop, $probe);
        if ($store === null || $languages === null) {
            // The rest is checked against the store: without it, only the
            // shape of the call can be told, by the rules of the create.
            if ($languages !== null) {
                self::planStore(array_intersect_key($arguments, array_flip(self::STORE_FIELDS)), $idShop, $errors, false);
            } else {
                $errors = array_merge($errors, $probe);
            }

            return SomcpTool::refused('changed', $errors);
        }

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
        $read = SomcpToolListStores::read($idStore, $idLang, $idShop) ?? [];
        self::readOnly($arguments, $read, $idStore, $errors);

        $given = self::withoutUnchanged($arguments, $store, $read, $languages);
        $plan = self::planStore($given, $idShop, $errors, false);
        if ($errors !== [] || $plan === null) {
            return SomcpTool::refused('changed', $errors);
        }

        [$changes, $set] = self::changes($plan, $store, $read);
        if ($changes === []) {
            return SomcpTool::json([
                'changed' => false,
                'changes' => [],
                'reason' => 'The store already holds every value given. Nothing was written.',
                'store' => $read,
                'language' => (string) Language::getIsoById($idLang),
            ]);
        }

        self::fill($store, $set);
        // The shop of the token, which the store already belongs to: its
        // other shops stay as they are.
        $store->id_shop_list = [$idShop];

        try {
            $done = (bool) $store->update();
            $failure = $done ? '' : 'PrestaShop refused to save it';
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        }
        if (!$done) {
            SomcpLogger::log('store_update_failed', SomcpLogger::OUTCOME_FAILURE, 'store ' . $idStore . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text('The store could not be changed: ' . $failure . '.', true);
        }
        // The names of the fields only: the values are an address.
        SomcpLogger::log('store_updated', SomcpLogger::OUTCOME_SUCCESS, 'store ' . $idStore . ': '
            . implode(', ', array_unique(array_map(static function (array $change): string {
                return (string) preg_replace('/ \(.*\)$/', '', $change['field']);
            }, $changes))), '', $idEmployee);

        $result = [
            'changed' => true,
            'changes' => $changes,
            // Read back rather than echoed: what the assistant shows is what
            // the shop stored.
            'store' => SomcpToolListStores::read($idStore, $idLang, $idShop),
            'language' => (string) Language::getIsoById($idLang),
        ];
        $shops = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'store_shop` WHERE `id_store` = ' . (int) $idStore
        );
        if ($shops > 1) {
            $result['warning'] = 'This store is shown on ' . $shops . ' shops: the change applies to all of them.';
        }

        return SomcpTool::json($result);
    }

    /**
     * The fields the list answers and this tool does not set: equal to the
     * read, they are an item given back; otherwise they are named.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $read
     * @param int $idStore
     * @param array<int, string> $errors
     *
     * @return void
     */
    private static function readOnly(array $arguments, array $read, int $idStore, array &$errors): void
    {
        foreach (self::READ_ONLY as $field) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }
            $value = $arguments[$field];
            if ($field === 'image') {
                if (!SomcpUpload::same($value, 'store_image', (string) $idStore)) {
                    $errors[] = 'image: read only - the picture is not changed by this tool';
                }
                continue;
            }
            if ($value === ($read[$field] ?? null)) {
                continue;
            }
            if ($field === 'country_name' || $field === 'state_name') {
                $errors[] = $field . ': read only - change ' . ($field === 'country_name' ? 'country' : 'state')
                    . ', its ISO code';
            } else {
                $errors[] = $field . ': read only';
            }
        }
    }

    /**
     * The arguments without the fields equal to the store as read.
     *
     * Equal, a field is no change and is never checked, as #436 decided for
     * the cart rules. A changed country takes the state and the postcode
     * along, and a changed state or postcode the country, as the screen
     * sends them together and checks them against each other.
     *
     * @param array<string, mixed> $arguments
     * @param Store $store
     * @param array<string, mixed> $read
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     *
     * @return array<string, mixed>
     */
    private static function withoutUnchanged(array $arguments, Store $store, array $read, array $languages): array
    {
        $given = array_intersect_key($arguments, array_flip(self::STORE_FIELDS));

        foreach (self::TRANSLATED_TEXTS as $field) {
            if (array_key_exists($field, $given) && self::sameTexts($given[$field], self::stored($store->{$field}), $languages)) {
                unset($given[$field]);
            }
        }
        foreach (['city', 'phone', 'fax', 'email'] as $field) {
            if (array_key_exists($field, $given) && is_string($given[$field])
                && trim($given[$field]) === (string) $store->{$field}) {
                unset($given[$field]);
            }
        }
        foreach (['latitude', 'longitude'] as $field) {
            if (array_key_exists($field, $given) && (is_int($given[$field]) || is_float($given[$field]))
                && round((float) $given[$field], 8) === round((float) $store->{$field}, 8)) {
                unset($given[$field]);
            }
        }
        if (array_key_exists('active', $given) && $given['active'] === (bool) $store->active) {
            unset($given['active']);
        }
        if (array_key_exists('hours', $given) && self::sameHours($given['hours'], self::stored($store->hours))) {
            unset($given['hours']);
        }
        // The list says null for what the row holds in another shape: given
        // back, it is the read, not a value to check.
        foreach (['latitude', 'longitude', 'hours'] as $field) {
            if (array_key_exists($field, $given) && $given[$field] === null && ($read[$field] ?? null) === null) {
                unset($given[$field]);
            }
        }

        $country = $read['country'] ?? null;
        $state = $read['state'] ?? null;
        $changed = static function (string $field, $current) use ($given): bool {
            if (!array_key_exists($field, $given)) {
                return false;
            }
            $value = $given[$field];
            if (($value === null || $value === '') && ($current === null || $current === '')) {
                return false;
            }

            return !is_string($value) || $current === null
                || Tools::strtoupper(trim($value)) !== Tools::strtoupper((string) $current);
        };
        $countryChanged = $changed('country', $country);
        $stateChanged = $changed('state', $state);
        $postcodeChanged = array_key_exists('postcode', $given)
            && (!is_string($given['postcode']) || trim($given['postcode']) !== (string) $store->postcode);

        if ($countryChanged) {
            if (!array_key_exists('state', $given) && $state !== null) {
                $given['state'] = $state;
            }
            if (!array_key_exists('postcode', $given)) {
                $given['postcode'] = (string) $store->postcode;
            }
        } elseif ($stateChanged || $postcodeChanged) {
            if ($country !== null) {
                $given['country'] = $country;
            }
            if (!$stateChanged) {
                if ($state !== null) {
                    $given['state'] = $state;
                } else {
                    unset($given['state']);
                }
            }
            if (!$postcodeChanged) {
                unset($given['postcode']);
            }
        } else {
            unset($given['country'], $given['state'], $given['postcode']);
        }

        return $given;
    }

    /**
     * What changes, as the answer names it, and the properties to set.
     *
     * @param array<string, mixed> $plan
     * @param Store $store
     * @param array<string, mixed> $read
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private static function changes(array $plan, Store $store, array $read): array
    {
        $changes = [];
        $set = [];
        $isos = $plan['languages']['filled'];

        foreach ($plan['texts'] as $field => $texts) {
            $stored = self::stored($store->{$field});
            $merged = $stored;
            foreach ($texts as $idLang => $text) {
                $old = $stored[$idLang] ?? '';
                if ($text !== $old) {
                    $changes[] = ['field' => $field . ' (' . $isos[$idLang] . ')', 'old' => $old, 'new' => $text];
                    $merged[$idLang] = $text;
                }
            }
            if ($merged !== $stored) {
                $set[$field] = $merged;
            }
        }

        foreach (self::PLAIN_TEXTS as $field) {
            if (isset($plan[$field]) && $plan[$field] !== (string) $store->{$field}) {
                $changes[] = ['field' => $field, 'old' => (string) $store->{$field}, 'new' => $plan[$field]];
                $set[$field] = $plan[$field];
            }
        }

        if (isset($plan['id_country'])) {
            if ((int) $plan['id_country'] !== (int) $store->id_country) {
                $country = new Country((int) $plan['id_country']);
                $changes[] = ['field' => 'country', 'old' => $read['country'] ?? null, 'new' => (string) $country->iso_code];
                $set['id_country'] = (int) $plan['id_country'];
            }
            $idState = (int) ($plan['id_state'] ?? 0);
            if ($idState !== (int) $store->id_state) {
                $state = $idState > 0 ? new State($idState) : null;
                $changes[] = ['field' => 'state', 'old' => $read['state'] ?? null, 'new' => $state !== null ? (string) $state->iso_code : null];
                $set['id_state'] = $idState;
            }
        }

        foreach (['latitude', 'longitude'] as $field) {
            if (isset($plan[$field]) && round((float) $plan[$field], 8) !== round((float) $store->{$field}, 8)) {
                $changes[] = ['field' => $field, 'old' => $read[$field] ?? null, 'new' => (float) $plan[$field]];
                $set[$field] = $plan[$field];
            }
        }

        if (isset($plan['active']) && $plan['active'] !== (bool) $store->active) {
            $changes[] = ['field' => 'active', 'old' => (bool) $store->active, 'new' => $plan['active']];
            $set['active'] = $plan['active'];
        }

        if (isset($plan['hours'])) {
            $stored = self::stored($store->hours);
            $merged = $stored;
            foreach ($plan['hours'] as $idLang => $week) {
                $encoded = (string) json_encode($week);
                $old = $stored[$idLang] ?? '';
                if ($encoded !== $old && $week !== self::decodedWeek($old)) {
                    $changes[] = [
                        'field' => 'hours (' . $isos[$idLang] . ')',
                        'old' => SomcpToolListStores::hours($old),
                        'new' => SomcpToolListStores::hours($encoded),
                    ];
                    $merged[$idLang] = $encoded;
                }
            }
            if ($merged !== $stored) {
                $set['hours'] = $merged;
            }
        }

        return [$changes, $set];
    }

    /**
     * Whether a translated text given is the one stored.
     *
     * One text, as the list answers it in one language, is the stored text
     * of a language; keyed by ISO code, every language given holds its text.
     *
     * @param mixed $value
     * @param array<int, string> $stored
     * @param array{filled: array<int, string>, all: array<int, int>, fallback: int} $languages
     *
     * @return bool
     */
    private static function sameTexts($value, array $stored, array $languages): bool
    {
        if (is_string($value)) {
            return in_array(trim($value), array_map('trim', $stored), true);
        }
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            return false;
        }
        $byIso = array_flip($languages['filled']);
        foreach ($value as $iso => $text) {
            $idLang = $byIso[Tools::strtolower((string) $iso)] ?? null;
            if ($idLang === null || !is_string($text) || trim($text) !== trim($stored[$idLang] ?? '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether hours given as the list answers them, seven days, are those of
     * one language as stored.
     *
     * Keyed by ISO code they go through the plan, which compares them per
     * language.
     *
     * @param mixed $value
     * @param array<int, string> $stored the JSON of store_lang.hours, by language
     *
     * @return bool
     */
    private static function sameHours($value, array $stored): bool
    {
        if (!is_array($value) || ($value !== [] && array_intersect(array_keys($value), SomcpToolListStores::DAYS) === [])) {
            return false;
        }
        $week = [];
        foreach (SomcpToolListStores::DAYS as $day) {
            $texts = $value[$day] ?? [];
            if (!is_array($texts) || !array_is_list($texts)) {
                return false;
            }
            foreach ($texts as $text) {
                if (!is_string($text)) {
                    return false;
                }
            }
            $week[] = array_map('trim', $texts);
        }
        if (array_diff(array_keys($value), SomcpToolListStores::DAYS) !== []) {
            return false;
        }
        foreach ($stored as $json) {
            if (self::decodedWeek($json) === $week) {
                return true;
            }
        }

        return false;
    }

    /**
     * The seven lists of a stored week, or null when the row holds another shape.
     *
     * @param string $json
     *
     * @return array<int, array<int, string>>|null
     */
    private static function decodedWeek(string $json): ?array
    {
        $hours = SomcpToolListStores::hours($json);

        return $hours === null ? null : array_values($hours);
    }

    /**
     * A translated property of the store as texts keyed by language identifier.
     *
     * @param mixed $value as the Store object loaded in every language holds it
     *
     * @return array<int, string>
     */
    private static function stored($value): array
    {
        $texts = [];
        foreach (is_array($value) ? $value : [] as $idLang => $text) {
            $texts[(int) $idLang] = (string) $text;
        }

        return $texts;
    }
}
