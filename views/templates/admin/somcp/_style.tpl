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

{* Inline rather than a media file, for the same reason as the script: the
   settings page is rendered by Symfony on PrestaShop 9, where the controller
   addCSS() would need is a proxy without that method. The stylesheet used to
   sit in views/css and was only ever loaded by the consent screen, so none of
   these rules applied to the settings page at all. *}
<style type="text/css">
/* The heart of the Become a sponsor button, in the thank-you window. A primary
   button, so the heart is white like its text. Drawn as an image rather than
   taken from an icon font: the back office does not load the same icon fonts
   on 8 and 9, and a missing glyph left an empty gap in the button. */
.somcp-heart::before {
    content: "";
    display: inline-block;
    width: 14px;
    height: 13px;
    margin-right: 7px;
    vertical-align: -1px;
    background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 15'%3E%3Cpath fill='%23fff' d='M8 14.5 1.6 8.1A4 4 0 0 1 8 2.9a4 4 0 0 1 6.4 5.2Z'/%3E%3C/svg%3E") no-repeat center / contain;
}

.somcp-heart {
    display: inline;
}

/* The heart of the Support us button of the page header is PrestaShop's own
   icon, but its template only spaces the process-icon set from the label.
   The button is found by its address: PrestaShop rewrites its id. Only the
   settings page has the button, and only it is given the address. */
{if isset($somcp_sponsor_url)}
a[href="{$somcp_sponsor_url|escape:'html':'UTF-8'}"] > i.icon-heart {
    margin-right: 6px;
}
{/if}

/* A native dialog dressed as the back office modal. Its own box is cleared so
   only modal-content draws the frame. */
.somcp .somcp-sponsor {
    width: 520px;
    max-width: calc(100% - 32px);
    padding: 0;
    border: 0;
    background: transparent;
    overflow: visible;
}

.somcp .somcp-sponsor::backdrop {
    background: rgba(0, 0, 0, .45);
}

.somcp .somcp-sponsor .modal-body {
    text-align: center;
    padding: 24px 28px;
}

.somcp .somcp-sponsor-heart {
    display: block;
    width: 110px;
    height: 102px;
    margin: 0 auto 12px;
    color: #db61a2;
}

/* A pane is hidden, never removed: the settings form spans two of them and
   posts every field from whichever page holds the Save button. */
.somcp .somcp-pane[hidden] {
    display: none;
}

/* The back office draws a heavy outline on every focused link and button
   (.bootstrap a:focus), and a clicked button keeps the focus, so the box
   stayed around it until the merchant clicked elsewhere. It is dropped for a
   mouse click only: :focus-visible keeps it for someone moving with the
   keyboard. */
.somcp .btn:focus:not(:focus-visible) {
    outline: 0;
}

.somcp .somcp-foot {
    font-size: 12px;
}

/* The credit now sits in a panel title, which the back office writes in
   capitals and bold: undone here so it keeps its footnote look. */
.somcp .panel-heading .somcp-foot {
    font-weight: normal;
    text-transform: none;
}

/* Address block: one row per shop, the shop name as a quiet heading above
   PrestaShop's own input group, so the address and its Copy button look like
   every other field of the back office. */
.somcp .somcp-endpoint + .somcp-endpoint {
    margin-top: 12px;
}

.somcp .somcp-endpoint-shop {
    text-transform: uppercase;
    letter-spacing: .06em;
    font-size: 11px;
    font-weight: 700;
    color: #6c7a89;
    margin-bottom: 6px;
}

.somcp .somcp-badge {
    background: #25b9d7;
    text-transform: none;
    letter-spacing: 0;
    font-weight: 400;
    margin-left: 6px;
}

/* Bootstrap's buttons set display themselves and would show through the
   hidden attribute the pairing screen toggles. */
.somcp .somcp-relay-steps [hidden] {
    display: none !important;
}

/* The pairing of a local assistant: one row per step, its mark telling
   whether it is done, being checked, given up, or the one to do now. */
.somcp .somcp-relay-progress .progress {
    height: 8px;
    margin: 16px 0;
}

.somcp .somcp-relay-steps {
    list-style: none;
    margin: 0;
    padding: 0;
}

.somcp .somcp-relay-steps > li {
    display: flex;
    gap: 12px;
    padding: 12px 0;
    border-top: 1px solid #eef0f3;
    color: #6c7a89;
}

.somcp .somcp-relay-steps > li:first-child {
    border-top: 0;
}

.somcp .somcp-relay-steps > li p {
    margin: 6px 0 0;
}

.somcp .somcp-relay-steps > li > div {
    flex: 1 1 auto;
    min-width: 0;
}

.somcp .somcp-relay-mark {
    flex: 0 0 auto;
    width: 22px;
    height: 22px;
    margin-top: 1px;
    border-radius: 50%;
    border: 2px solid #c9ced6;
    box-sizing: border-box;
}

.somcp .somcp-relay-steps > li.is-done,
.somcp .somcp-relay-steps > li.is-checking,
.somcp .somcp-relay-steps > li.is-current,
.somcp .somcp-relay-steps > li.is-failed {
    color: inherit;
}

.somcp .is-done > .somcp-relay-mark {
    border-color: #4cbb6c;
    background: #4cbb6c url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath d='M4 8.5l2.5 2.5L12 5.5' fill='none' stroke='%23fff' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") center / 14px no-repeat;
}

.somcp .is-checking > .somcp-relay-mark {
    border-color: #25b9d7;
    border-right-color: transparent;
    animation: somcp-relay-spin 0.9s linear infinite;
}

.somcp .is-current > .somcp-relay-mark {
    border-color: #25b9d7;
    background: #25b9d7;
    box-shadow: inset 0 0 0 4px #fff;
}

.somcp .is-failed > .somcp-relay-mark {
    border-color: #e08f95;
    background: #e08f95;
}

@keyframes somcp-relay-spin {
    to {
        transform: rotate(360deg);
    }
}

/* Read aloud or copied by hand into the relay: big enough to read from a
   step back, and in a font where letters and digits cannot be mistaken. */
.somcp .somcp-relay-code code {
    display: inline-block;
    font-family: "SFMono-Regular", Menlo, Consolas, monospace;
    font-size: 28px;
    letter-spacing: 4px;
    padding: 6px 14px;
}

.somcp #somcp-relay-command {
    font-family: "SFMono-Regular", Menlo, Consolas, monospace;
}

.somcp .somcp-endpoint input[readonly] {
    font-family: "SFMono-Regular", Menlo, Consolas, monospace;
    background: #fff;
    cursor: text;
}

/* The diagnostic bar: shop picker and button on one line, so the merchant
   reads "run this, on that shop" as a single sentence. */
/* The shop picker and its button sit right under the results of the last run,
   and the table's own bottom margin is not enough to keep them from reading as
   one more row of it. */
.somcp .somcp-diag-run {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 18px;
}

.somcp .somcp-diagnostic {
    margin-bottom: 0;
}

.somcp .somcp-note {
    margin-top: 10px;
    color: #7a7a7a;
    font-size: 12px;
}

.somcp .somcp-diagnostic th {
    white-space: nowrap;
    width: 1%;
}

.somcp .somcp-status {
    width: 1%;
    font-size: 16px;
    vertical-align: middle;
}

.somcp .somcp-status-ok {
    color: #3ca63c;
}

.somcp .somcp-status-warning {
    color: #ff9a52;
}

.somcp .somcp-status-error {
    color: #e08f95;
}

.somcp .somcp-status-skipped {
    color: #a0a0a0;
}

.somcp .somcp-log code {
    white-space: nowrap;
}

.somcp .somcp-log-failure td {
    background: #fdf3f4;
}

/* Consent screen: narrow column, because the merchant has one decision to make
   and a full-width back office table buries it. */
.somcp-consent {
    max-width: 680px;
    margin: 0 auto;
}

.somcp-consent .somcp-lead {
    font-size: 15px;
}

.somcp-consent .somcp-facts th {
    width: 35%;
    white-space: nowrap;
}

.somcp-consent .somcp-scopes {
    margin-bottom: 16px;
}

.somcp-consent .somcp-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 0;
}

/* Walking away belongs at the other end of the row. Put next to "send", it is
   one slip away from the button that does the opposite, and on this page the
   slip costs the merchant the files they just chose. */
.somcp-consent .somcp-actions-split {
    justify-content: space-between;
}

.somcp-consent .somcp-actions-go {
    display: inline-flex;
    gap: 8px;
}

/* The deposit area. A merchant arriving here has been sent by an assistant and
   is not looking for a form to fill in - they want to drop what they were
   asked for and go back. So the whole rectangle is the target, the file input
   itself is hidden behind it, and dragging works as well as clicking. */
.somcp-consent .somcp-drop {
    display: block;
    margin-top: 18px;
    border: 2px dashed #bfc9d4;
    border-radius: 6px;
    background: #fafbfc;
    padding: 28px 16px;
    text-align: center;
    cursor: pointer;
    transition: border-color .15s, background .15s;
}

.somcp-consent .somcp-drop:hover,
.somcp-consent .somcp-drop.is-over {
    border-color: #25b9d7;
    background: #f2fbfd;
}

.somcp-consent .somcp-drop input[type="file"] {
    display: none;
}

/* Both are spans so the whole label stays one click target, so each has to be
   told to take its own line. */
.somcp-consent .somcp-drop-title {
    display: block;
    font-weight: 600;
    margin-bottom: 6px;
}

.somcp-consent .somcp-drop-hint {
    display: block;
    color: #6c7a89;
    font-size: 12px;
}

/* The names of what is about to be sent, listed under the target so the
   merchant sees their own choice before pressing anything. */
.somcp-consent .somcp-chosen {
    margin: 10px 0 0;
    padding: 0;
    list-style: none;
    font-size: 13px;
}

.somcp-consent .somcp-chosen li {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 3px 0;
    color: #363a41;
}

/* Dropping one file from the pile before sending. Small, quiet, and only
   meaningful next to the name it belongs to. */
.somcp-consent .somcp-drop-one {
    border: 0;
    background: none;
    color: #98a3b0;
    font-size: 16px;
    line-height: 1;
    padding: 0 2px;
    cursor: pointer;
}

.somcp-consent .somcp-drop-one:hover {
    color: #c0392b;
}

/* A file the page turned away before sending anything. Said in place, next to
   its own name, rather than as one message above the list. */
.somcp-consent .somcp-chosen li.somcp-rejected {
    color: #c0392b;
}

.somcp-consent .btn[disabled] {
    opacity: .5;
    cursor: default;
}

/* Every list on these two pages ends the same way. PrestaShop draws a rule
   above each row and none below the last, so a table stopped in mid-air and
   whatever came next - a button, a form - sat against its final line and read
   as one more row of it. Close the table, then leave the same gap the tool
   paging already leaves under its own. */
.somcp .table,
.somcp-consent .table {
    border-bottom: 1px solid #eef1f4;
}

.somcp .table + form,
.somcp .table + .somcp-actions,
.somcp-consent .table + form,
.somcp-consent .table + p,
.somcp-consent .table + h4,
.somcp-consent .table + .alert {
    margin-top: 18px;
}

/* Inheritance control: deliberately quiet. It sits under every setting on a
   multistore shop, so it must read as a note, not compete with the field. */
.somcp .somcp-inherit {
    margin-top: 6px;
    font-size: 12px;
}

.somcp .somcp-inherit label {
    font-weight: normal;
    margin: 0 8px 0 0;
}

/* The list of tools, paged five at a time rather than scrolled: a shop with a
   few modules would otherwise have a list long enough to push the Save button
   off the screen. The wrapper stays for the horizontal overflow on a narrow
   screen - and the table keeps its own display, because display:block on a
   table makes it shrink to its content instead of filling the panel. */
.somcp .somcp-tools-scroll {
    margin-top: 10px;
    overflow-x: auto;
}

.somcp .somcp-tools {
    width: 100%;
    margin-bottom: 0;
}

.somcp .somcp-tools code {
    font-size: 11px;
}

.somcp .somcp-tools td,
.somcp .somcp-tools th {
    vertical-align: middle;
}

/* The switch column takes what a checkbox needs and no more, so the width goes
   to the tool name, which is the longest thing in the row. */
.somcp .somcp-tools .somcp-tool-on {
    width: 48px;
    text-align: center;
}

/* Buttons under a list, on the right: the tool paging, and the button that
   adds an assistant. The paging stays hidden until the script takes over, so
   a page without JavaScript shows the whole list rather than two dead
   buttons. */
.somcp .somcp-tools-nav,
.somcp .somcp-panel-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    /* The same gap the diagnostic leaves under its own table: buttons sitting
       against the last row read as one more row of it. */
    margin-top: 18px;
}

.somcp .somcp-tools-nav[hidden] {
    display: none;
}

.somcp .somcp-tools-count {
    color: #6c868e;
    font-size: 12px;
}

/* Host names as tags, drawn like the tags of a product page: a field that
   grows with its content, one pill per name, the typing area after the last. */
.somcp .somcp-tags {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
    height: auto;
    min-height: 34px;
    padding: 3px 6px;
    cursor: text;
}

.somcp .somcp-tags.is-disabled {
    background: #eee;
    cursor: not-allowed;
}

.somcp .somcp-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 4px 2px 8px;
    border-radius: 3px;
    background: #25b9d7;
    color: #fff;
    font-size: 12px;
    line-height: 18px;
}

.somcp .somcp-tags.is-disabled .somcp-tag {
    background: #a8b4bd;
}

.somcp .somcp-tag-remove {
    border: 0;
    background: none;
    color: inherit;
    font-size: 15px;
    line-height: 1;
    padding: 0 2px;
    opacity: .8;
    cursor: pointer;
}

.somcp .somcp-tag-remove:hover {
    opacity: 1;
}

.somcp .somcp-tags-input {
    flex: 1 0 120px;
    border: 0;
    outline: 0;
    background: transparent;
    min-height: 24px;
    padding: 0 2px;
}

/* The suggestions under the field: quiet outlined pills, so they read as
   something on offer rather than something already chosen. */
.somcp .somcp-tags-suggest {
    margin: 8px 0 0;
    font-size: 12px;
}

.somcp .somcp-tags-suggest[hidden],
.somcp .somcp-tag-suggest[hidden] {
    display: none;
}

.somcp .somcp-tag-suggest {
    margin: 2px 4px 2px 0;
    padding: 1px 8px;
    border: 1px dashed #bfc9d4;
    border-radius: 3px;
    background: #fff;
    color: #363a41;
    font-size: 12px;
    line-height: 18px;
    cursor: pointer;
}

.somcp .somcp-tag-suggest:hover {
    border-color: #25b9d7;
}

.somcp .somcp-tag-suggest[disabled] {
    opacity: .5;
    cursor: not-allowed;
}

/* An icon in a button keeps a gap before its label. Inside an alert the back
   office lays the icon out its own way, and on the update notice it ran into
   the first letter of Download the zip and See what is new. */
.somcp .btn > [class^="icon-"],
.somcp .btn > [class*=" icon-"] {
    display: inline-block;
    position: static;
    margin-right: 4px;
}

/* Meet our contributors: one row that never wraps - the top contributor,
   then a page of six, then the link to everyone past seven. Every card has
   the width of the top one, so a count and a share each keep to one line. */
.somcp .somcp-contributors {
    display: flex;
    flex-wrap: nowrap;
    align-items: stretch;
    gap: 8px;
    overflow: hidden;
    margin: 0;
}

.somcp .somcp-contributor {
    box-sizing: border-box;
    display: flex;
    /* Narrower screens shrink the cards rather than cut the last one off. */
    flex: 0 1 112px;
    flex-direction: column;
    min-width: 88px;
    align-items: center;
    width: 112px;
    padding: 8px 4px;
    border: 1px solid #dbe6e9;
    border-radius: 4px;
    font-size: 11px;
    text-align: center;
}

.somcp .somcp-contributor[hidden] {
    display: none;
}

.somcp .somcp-contributor > a {
    width: 100%;
}

.somcp .somcp-contributor img {
    display: block;
    margin: 0 auto 5px;
    border-radius: 50%;
}

.somcp .somcp-contributor-name {
    display: block;
    overflow: hidden;
    font-weight: bold;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.somcp .somcp-contributor-share {
    margin-top: 2px;
    color: #6c868e;
    font-size: 10.5px;
    line-height: 1.3;
}

.somcp .somcp-contributor-top {
    flex: 0 0 112px;
    width: 112px;
    border-color: #25b9d7;
    background: #f4fbfd;
}

.somcp .somcp-contributor-badge {
    margin-top: 5px;
    background: #25b9d7;
    font-size: 9.5px;
}

.somcp .somcp-contributors-sep {
    flex: 0 0 1px;
    margin: 0 2px;
    background: #dbe6e9;
}

/* Pushed to the right end of the row whatever the page holds. */
.somcp .somcp-contributor-all {
    flex-shrink: 0;
    justify-content: center;
    margin-left: auto;
    border-style: dashed;
    border-color: #25b9d7;
    font-weight: bold;
}

.somcp .somcp-contributor-total {
    display: block;
    width: 40px;
    height: 40px;
    margin: 0 auto 6px;
    border-radius: 50%;
    background: #e8f7fb;
    font-size: 13px;
    line-height: 40px;
}

.somcp .somcp-contributors-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 14px;
}

.somcp .somcp-contributors-bar .somcp-tools-nav {
    margin-top: 0;
}

/* A tool that changes something, marked where the merchant decides whether to
   offer it at all. */
.somcp .somcp-writes {
    background: #fbc02d;
    color: #333;
}
</style>
