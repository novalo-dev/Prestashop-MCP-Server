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
 * SomcpProductImage - the public address of a product image.
 *
 * One place for it, because PrestaShop 8 and 9 want the image named
 * differently, and every tool that hands out a product image has to agree
 * with the others on which form it uses.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpProductImage
{
    /**
     * The address of one product image in the given image type.
     *
     * @param Link $link
     * @param int $idProduct
     * @param string $linkRewrite the product's friendly URL in the language read
     * @param int $idImage
     * @param string $type an image type name, for example "large" or "small"
     *
     * @return string
     */
    public static function url(Link $link, int $idProduct, string $linkRewrite, int $idImage, string $type): string
    {
        // PrestaShop 8 takes "product-image" and works out the legacy image
        // names from its own setting; PrestaShop 9 removed that setting, wants
        // the image identifier alone and flags the old form as deprecated. So
        // the form follows the version, and the setting is never read here.
        $name = version_compare(_PS_VERSION_, '9.0.0', '<') ? $idProduct . '-' . $idImage : (string) $idImage;

        return (string) $link->getImageLink($linkRewrite, $name, ImageType::getFormattedName($type));
    }
}
