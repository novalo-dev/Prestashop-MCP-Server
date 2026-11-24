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
 * somcp_delete_language - one language of International > Localization >
 * Languages deleted, as the Delete of its row does.
 *
 * A language installed by mistake, or one the merchant stops selling in,
 * could only be removed in the back office. Language::delete() removes every
 * text of the shop in that language, so the refusals of the back office are
 * said before the call, and switching the language off is offered instead.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Loaded here rather than left to the order the registry reads tools/ in: the
// language is found as the list finds it.
require_once __DIR__ . '/SomcpToolListLanguages.php';
require_once __DIR__ . '/SomcpToolCreateTax.php';

class SomcpToolDeleteLanguage
{
    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_delete_language',
            Somcp::t('Delete a language'),
            'Deletes one language of International > Localization > Languages, as the Delete of its row: every text '
                . 'of the shop in it goes too (products, categories, pages, e-mails), which cannot be undone. Take '
                . 'id_lang from somcp_list_languages. The default language and the one you use in the back office '
                . 'are refused. To keep the texts and stop offering the language, somcp_update_language with active '
                . 'false instead.',
            [
                'type' => 'object',
                'properties' => [
                    'id_lang' => [
                        'type' => 'integer',
                        'description' => 'Required. The language to delete (ID), from somcp_list_languages.',
                    ],
                ],
                'required' => ['id_lang'],
                'additionalProperties' => false,
            ],
            [SomcpScope::CATALOG_WRITE],
            [self::class, 'handle'],
            // Deleting a language is what the Delete of the languages list
            // asks for in Team, Permissions.
            SomcpTool::acts(SomcpPermission::DELETE, 'AdminLanguages')
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

        $errors = SomcpTool::unknownFields($arguments, ['id_lang']);
        $stored = null;
        $idLang = SomcpTool::id($arguments, 'id_lang');
        if ($idLang === 0) {
            $errors[] = SomcpTool::notAnIdentifier('id_lang');
        } else {
            $stored = SomcpToolListLanguages::language($idShop, $idLang);
            if ($stored === null) {
                $errors[] = SomcpTool::notFound('id_lang', 'language', $idLang, 'somcp_list_languages');
            }
        }
        $default = SomcpToolListLanguages::defaultLanguage($idShop);
        if ($stored !== null) {
            $name = (string) $stored['name'];
            // The refusals of the back office: every tool and the storefront
            // fall back on the default language, so a shop cannot lose it.
            foreach (Shop::getShops(false) as $shop) {
                $id = (int) $shop['id_shop'];
                if ((int) Configuration::get('PS_LANG_DEFAULT', null, null, $id) !== $idLang) {
                    continue;
                }
                $errors[] = 'id_lang: ' . $name . ' is the default language of '
                    . ($id === $idShop ? 'this shop' : 'shop ' . $shop['name']) . ' - PrestaShop never deletes it';
            }
            // The "Used language" check of the back office, on the employee
            // the token acts for: their back office would lose its language.
            $employee = $idEmployee > 0 ? new Employee($idEmployee) : null;
            if ($employee !== null && (int) $employee->id_lang === $idLang) {
                $errors[] = 'id_lang: ' . $name . ' is the language you use in the back office - change it in your '
                    . 'profile first';
            }
            // One row in lang for every shop: deleting it here would remove it
            // from all of them, the rule of somcp_delete_currency.
            $otherShops = SomcpToolCreateTax::otherShops();
            if ($otherShops > 0) {
                $errors[] = 'id_lang: a language is shared by every shop - deleting it here would remove it from the '
                    . $otherShops . ' other shops of this installation too';
            }
        }
        if ($errors !== [] || $stored === null) {
            return SomcpTool::refused('deleted', $errors);
        }

        $name = (string) $stored['name'];
        $iso = (string) $stored['iso_code'];
        $db = Db::getInstance();
        // Counted before: the customers keep their id_lang, and the employees
        // are moved below.
        $customers = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_lang` = ' . (int) $idLang
        );
        $employees = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'employee` WHERE `id_lang` = ' . (int) $idLang
        );

        // Language::delete() works on every shop, as the handler sets it; the
        // context of the request is put back whatever happens.
        $previous = Shop::getContext();
        $previousId = $previous === Shop::CONTEXT_GROUP ? Shop::getContextShopGroupID() : Shop::getContextShopID();
        try {
            Shop::setContext(Shop::CONTEXT_ALL);
            if ($employees > 0) {
                // As the Edit page does when a language is disabled: a back
                // office left on a language that no longer exists would break.
                $db->update('employee', ['id_lang' => (int) $default], '`id_lang` = ' . (int) $idLang);
            }
            $done = (new Language($idLang))->delete();
            $failure = $done ? '' : 'PrestaShop refused to delete it';
            if ($done) {
                // robots.txt lists a path per language, as the handler
                // writes it again after a deletion.
                Tools::generateRobotsFile();
            }
        } catch (Throwable $e) {
            $done = false;
            $failure = $e->getMessage();
        } finally {
            if ($previous === Shop::CONTEXT_ALL) {
                Shop::setContext(Shop::CONTEXT_ALL);
            } else {
                Shop::setContext($previous, $previousId);
            }
        }
        if (!$done) {
            SomcpLogger::log('language_delete_failed', SomcpLogger::OUTCOME_FAILURE, 'language ' . $idLang . ': '
                . Tools::substr($failure, 0, 500), '', $idEmployee);

            return SomcpTool::text(
                'Nothing was deleted. The shop refused to delete language ' . $idLang . ': ' . $failure . ' - read it '
                    . 'again with somcp_list_languages',
                true
            );
        }
        SomcpLogger::log('language_deleted', SomcpLogger::OUTCOME_SUCCESS, 'language ' . $idLang . ' (' . $iso . ')',
            '', $idEmployee);

        $defaultRow = SomcpToolListLanguages::language($idShop, $default);
        $defaultName = $defaultRow !== null ? (string) $defaultRow['name'] : 'the default language';
        $warnings = ['Every text of the shop in ' . $name . ' was deleted with it (products, categories, pages, '
            . 'e-mails); adding the language again starts from the texts of the default language.'];
        if ($employees > 0) {
            $warnings[] = $employees . ' employees used ' . $name . ' in the back office; they now use '
                . $defaultName . '.';
        }
        if ($customers > 0) {
            $warnings[] = $customers . ' customers had chosen ' . $name . '; they now see the shop in '
                . $defaultName . '.';
        }

        return SomcpTool::json([
            'deleted' => true,
            'id_lang' => $idLang,
            'name' => $name,
            'iso_code' => $iso,
            'warning' => implode(' ', $warnings),
        ]);
    }
}
