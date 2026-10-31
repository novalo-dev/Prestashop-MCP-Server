{*
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
 *}

{* The built-in assistant's stylesheet and its script, printed in the head of
   every back office page by displayBackOfficeHeader. The settings ride on the
   script tag as an escaped attribute, which the script parses: the Addons
   validator refuses nofilter, and an attribute needs no inline script. *}
<link rel="stylesheet" href="{$somcp_assistant_css|escape:'html':'UTF-8'}">
<script id="somcp-assistant-js" src="{$somcp_assistant_js|escape:'html':'UTF-8'}" data-config="{$somcp_assistant_config|escape:'html':'UTF-8'}" defer></script>
