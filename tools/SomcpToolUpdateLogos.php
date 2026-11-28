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
 * somcp_update_logos - the Header logo, the Mail logo and the Invoice logo of
 * the panel Logo of Design > Theme & Logo, as choosing a file then Save does.
 *
 * LogoUploader::update() takes its file through move_uploaded_file(), which
 * refuses a file the module already holds, so the tool writes what it
 * writes: a new PS_IMG_UPDATE_TIME, as UploadLogosHandler sets it, the files
 * in img/ under the name of getLogoName(), one per image format of the shop,
 * the setting of the shop of the token, the size of the header logo the
 * themes read, and the old file removed unless another shop still names it.
 *
 * The favicon takes an .ico file only, a type the module never takes in, so
 * it stays with the back office.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// The logos read and given back, and the image formats of the shop.
require_once __DIR__ . '/SomcpToolGetTheme.php';
require_once __DIR__ . '/SomcpToolCreateProduct.php';
require_once __DIR__ . '/SomcpToolUpdateGeneralSettings.php';

class SomcpToolUpdateLogos
{
    /** @var array<string, string> The logos this tool writes, by the prefix LogoUploader names their files with. */
    private const PREFIXES = [
        'header' => 'logo',
        'mail' => 'logo_mail',
        'invoice' => 'logo_invoice',
    ];

    /** @var array<string, string> Each logo as a refusal names it: SomcpUpload::source() puts the article itself. */
    private const ON = [
        'header' => 'header logo',
        'mail' => 'mail logo',
        'invoice' => 'invoice logo',
    ];

    /** @var array<int, string> The keys of the read the shop works out. */
    private const COMPUTED = ['mail_uses_header', 'invoice_uses_header'];

    /**
     * @return SomcpTool
     */
    public static function define(): SomcpTool
    {
        return new SomcpTool(
            'somcp_update_logos',
            Somcp::t('Change the shop logos'),
            'Changes the Header logo, the Mail logo and the Invoice logo of Design > Theme & Logo, as choosing a file '
                . 'then Save does: each by url or upload_ref, only the ones given. The logos of somcp_get_theme can '
                . 'be given back as read, which changes nothing. Show the image to the merchant and confirm before '
                . 'calling. The favicon takes an .ico file and stays with the back office. Not for the logo of a '
                . 'carrier (somcp_update_carrier), a brand or a supplier.',
            [
                'type' => 'object',
                'properties' => [
                    'header' => SomcpUpload::schema('Merchant. The header logo of the shop, at the top of every page.', 'the header logo'),
                    'mail' => SomcpUpload::schema('Merchant. The logo of the e-mails.', 'the mail logo'),
                    'invoice' => SomcpUpload::schema('Merchant. The logo of the invoices.', 'the invoice logo'),
                    'favicon' => [
                        'type' => ['object', 'null'],
                        'description' => 'Comes with the logos of somcp_get_theme: passes given back as read; the '
                            . 'favicon is changed in Design > Theme & Logo only.',
                    ],
                    'mail_uses_header' => [
                        'type' => 'boolean',
                        'description' => 'Comes with the logos of somcp_get_theme: worked out by the shop, passes given back as read.',
                    ],
                    'invoice_uses_header' => [
                        'type' => 'boolean',
                        'description' => 'Comes with the logos of somcp_get_theme: worked out by the shop, passes given back as read.',
                    ],
                ],
            ],
            [SomcpScope::APPEARANCE_WRITE],
            [self::class, 'handle'],
            // Save of the Logo panel takes the edit right on the themes page.
            SomcpTool::acts(SomcpPermission::EDIT, 'AdminThemes'),
            files: ['header' => SomcpUpload::images(), 'mail' => SomcpUpload::images(), 'invoice' => SomcpUpload::images()]
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

        // The back office refuses to save the logos in demo mode.
        if (SomcpBackOffice::constant('_PS_MODE_DEMO_')) {
            return SomcpTool::text('This shop runs in demo mode, where the logos cannot be changed. Nothing was changed.', true);
        }

        $read = SomcpToolGetTheme::logos($idShop);
        $errors = [];
        $rest = $arguments;
        // The read given back whole changes nothing: what the shop works out
        // passes when it holds what the shop holds, and is named otherwise.
        foreach (self::COMPUTED as $key) {
            if (array_key_exists($key, $rest) && $rest[$key] === $read[$key]) {
                unset($rest[$key]);
            }
        }
        if (array_key_exists('favicon', $rest)) {
            if (!SomcpUpload::same($rest['favicon'], 'shop_logo', 'favicon')) {
                $errors[] = 'favicon: an .ico file, changed in Design > Theme & Logo only';
            }
            unset($rest['favicon']);
        }
        $errors = array_merge($errors, SomcpTool::unknownFields($rest, array_keys(self::PREFIXES), self::COMPUTED));

        $planned = [];
        foreach (self::ON as $field => $what) {
            if (!array_key_exists($field, $rest) || SomcpUpload::same($rest[$field], 'shop_logo', $field)) {
                continue;
            }
            $source = SomcpUpload::argument($rest, $field, $errors, $what);
            if ($source !== null) {
                $planned[$field] = $source;
            }
        }
        if ($errors !== []) {
            return SomcpTool::refused('changed', $errors);
        }
        if ($planned === []) {
            return SomcpTool::json([
                'changed' => false,
                'reason' => 'The shop already holds every logo given. Nothing was written.',
                'logos' => $read,
            ]);
        }

        $files = [];
        try {
            // Every image in hand before anything is written, so no logo
            // changes while another one is refused.
            foreach ($planned as $field => $source) {
                $file = tempnam(_PS_TMP_IMG_DIR_, 'somcp');
                if ($file === false) {
                    $errors[] = $field . ': the shop has no room to receive it';
                    continue;
                }
                $files[$field] = $file;
                try {
                    $refusal = SomcpUpload::fetchImage($source, $file);
                } catch (Throwable $e) {
                    $refusal = 'the shop could not save it';
                }
                if ($refusal !== '') {
                    $errors[] = $field . ': ' . $refusal;
                }
            }
            if ($errors !== []) {
                return SomcpTool::refused('changed', $errors);
            }

            return SomcpToolCreateProduct::asEmployee($idEmployee, static function () use ($files, $read, $idShop, $idEmployee): array {
                return self::write($files, $read, $idShop, $idEmployee);
            });
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Writes the logos in hand, in the order of the screen, and answers with what the shop holds after.
     *
     * @param array<string, string> $files the image of each logo to write, by its name
     * @param array<string, mixed> $read the logos before the call, as somcp_get_theme answers them
     * @param int $idShop
     * @param int $idEmployee
     *
     * @return array<string, mixed>
     */
    private static function write(array $files, array $read, int $idShop, int $idEmployee): array
    {
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        // A new time at every save, as UploadLogosHandler sets it: it names
        // the new files, so a browser never shows the old logo from its cache.
        $time = time();
        Configuration::updateValue('PS_IMG_UPDATE_TIME', $time, false, $idShopGroup, $idShop);

        $saved = [];
        foreach (self::PREFIXES as $field => $prefix) {
            if (!isset($files[$field])) {
                continue;
            }
            $key = SomcpDownload::SHOP_LOGOS[$field];
            try {
                $name = self::logoName($prefix, $time, $idShop, '.jpg');
                foreach (SomcpToolCreateProduct::imageFormats() as $format) {
                    if (!ImageManager::resize($files[$field], _PS_IMG_DIR_ . self::logoName($prefix, $time, $idShop, '.' . $format), null, null, $format)) {
                        return SomcpToolUpdateGeneralSettings::failed($saved, $field . ' could not be saved', $idEmployee, 'The logos', 'somcp_get_theme', 'logos_update_failed');
                    }
                }
                $old = basename((string) Configuration::get($key, null, $idShopGroup, $idShop));
                if (!Configuration::updateValue($key, $name, false, $idShopGroup, $idShop)) {
                    return SomcpToolUpdateGeneralSettings::failed($saved, $field . ' could not be saved', $idEmployee, 'The logos', 'somcp_get_theme', 'logos_update_failed');
                }
                self::dropOld($key, $old, $name, $idShop);
                if ($field === 'header') {
                    // What updateHeader() stores, which the themes read for the size of the logo.
                    $size = @getimagesize(_PS_IMG_DIR_ . $name);
                    if (is_array($size)) {
                        Configuration::updateValue('SHOP_LOGO_WIDTH', (int) round($size[0]), false, $idShopGroup, $idShop);
                        Configuration::updateValue('SHOP_LOGO_HEIGHT', (int) round($size[1]), false, $idShopGroup, $idShop);
                    }
                }
            } catch (Throwable $e) {
                return SomcpToolUpdateGeneralSettings::failed($saved, $field . ' could not be saved', $idEmployee, 'The logos', 'somcp_get_theme', 'logos_update_failed');
            }
            $saved[] = $field;
        }

        SomcpLogger::log('logos_updated', SomcpLogger::OUTCOME_SUCCESS, implode(', ', $saved), '', $idEmployee);

        $warnings = [];
        foreach (['mail' => 'e-mails', 'invoice' => 'invoices'] as $field => $on) {
            if (in_array($field, $saved, true) && ($read[$field . '_uses_header'] ?? false) === true) {
                $warnings[] = 'The ' . $on . ' now carry this logo instead of the header logo.';
            }
        }
        if (SomcpToolCreateTax::otherShops() > 0) {
            $warnings[] = 'The logos changed for this shop only.';
        }

        $result = [
            'changed' => true,
            'changes' => $saved,
            'logos' => SomcpToolGetTheme::logos($idShop),
        ];
        if ($warnings !== []) {
            $result['warning'] = implode(' ', $warnings);
        }

        return SomcpTool::json($result);
    }

    /**
     * The name of a logo file, by the rule of LogoUploader::getLogoName():
     * the number of the shop only once there are several.
     *
     * @param string $prefix
     * @param int $time PS_IMG_UPDATE_TIME
     * @param int $idShop
     * @param string $extension with its dot
     *
     * @return string
     */
    private static function logoName(string $prefix, int $time, int $idShop, string $extension): string
    {
        return $prefix . '-' . $time . (Shop::isFeatureActive() ? (string) $idShop : '') . $extension;
    }

    /**
     * Removes the file a logo replaced, as LogoUploader::update() does,
     * unless another shop, its group or the whole installation still names it.
     *
     * @param string $key
     * @param string $old the file the shop named before, a basename
     * @param string $new the file it names now
     * @param int $idShop
     *
     * @return void
     */
    private static function dropOld(string $key, string $old, string $new, int $idShop): void
    {
        if ($old === '' || $old === $new || !is_file(_PS_IMG_DIR_ . $old)) {
            return;
        }
        $named = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration`'
            . ' WHERE `name` = \'' . pSQL($key) . '\' AND `value` = \'' . pSQL($old) . '\''
            . ' AND NOT (`id_shop` <=> ' . $idShop . ')'
        );
        if ($named === 0) {
            @unlink(_PS_IMG_DIR_ . $old);
        }
    }
}
