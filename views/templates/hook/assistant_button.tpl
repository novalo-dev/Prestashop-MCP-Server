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

{* The button that opens the built-in assistant, printed in the back office
   header bar by displayBackOfficeTop. The chat script moves it right after
   "View my shop", or after the shop list that replaces it on a multistore
   shop. *}
{* Written on one line: the header bar lays its components out inline, and the
   whitespace between tags would show as a gap beside the icon. *}
<div class="component" id="somcp-chat-component"><a href="#" id="somcp-chat-button" class="somcp-header-link" role="button" title="{l s='Open the built-in assistant' d='Modules.Somcp.Admin'}"><i class="material-icons">forum</i><span>{l s='Assistant' d='Modules.Somcp.Admin'}</span></a></div>
