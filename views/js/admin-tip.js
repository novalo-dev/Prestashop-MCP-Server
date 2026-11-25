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
/**
 * Opens a tip on the side that has room for it.
 *
 * The style sheet alone shows a tip under its mark; without this script it is
 * only less well placed. Here the tip is measured and put under its mark,
 * else above it, else to its right, else to its left - whichever side holds
 * it whole - then slid along that side until it sits inside the window.
 *
 * It is placed against the window rather than against its mark: a tip in a
 * table that scrolls sideways, or in a panel that hides what overflows, was
 * cut by its own container.
 */
(function () {
    'use strict';

    var GAP = 8;
    var EDGE = 8;
    var current = null;

    // The bar PrestaShop pins to the top of the back office covers what is
    // behind it: room above a mark is counted from under that bar.
    function ceiling() {
        var bars = document.querySelectorAll('#header_infos, .main-header, #header .navbar-header, .page-head');
        var low = 0;
        for (var i = 0; i < bars.length; i++) {
            if (window.getComputedStyle(bars[i]).position === 'fixed') {
                low = Math.max(low, bars[i].getBoundingClientRect().bottom);
            }
        }

        return low;
    }

    function place(tip) {
        var text = tip.querySelector('.nv-tip-text');
        if (!text || window.getComputedStyle(text).display === 'none') {
            return;
        }

        // Put at the origin first: where it lands tells where "0, 0" is. It
        // is the window, except under an ancestor that moves its own content
        // (a modal window does), and the tip is then placed from there.
        text.style.position = 'fixed';
        text.style.left = '0px';
        text.style.top = '0px';
        var box = text.getBoundingClientRect();
        var originX = box.left;
        var originY = box.top;

        var mark = tip.getBoundingClientRect();
        var width = document.documentElement.clientWidth;
        var height = document.documentElement.clientHeight;
        var top = ceiling() + EDGE;
        var room = {
            bottom: height - EDGE - mark.bottom - GAP,
            top: mark.top - GAP - top,
            right: width - EDGE - mark.right - GAP,
            left: mark.left - GAP - EDGE
        };

        var side;
        if (room.bottom >= box.height) {
            side = 'bottom';
        } else if (room.top >= box.height) {
            side = 'top';
        } else if (room.right >= box.width) {
            side = 'right';
        } else if (room.left >= box.width) {
            side = 'left';
        } else {
            // Nowhere whole: the side that cuts the least.
            side = room.bottom >= room.top ? 'bottom' : 'top';
        }

        var x;
        var y;
        if (side === 'bottom' || side === 'top') {
            x = mark.left - 8;
            y = side === 'bottom' ? mark.bottom + GAP : mark.top - GAP - box.height;
        } else {
            x = side === 'right' ? mark.right + GAP : mark.left - GAP - box.width;
            y = mark.top + mark.height / 2 - box.height / 2;
        }
        x = Math.max(EDGE, Math.min(x, width - EDGE - box.width));
        y = Math.max(top, Math.min(y, height - EDGE - box.height));

        text.style.left = Math.round(x - originX) + 'px';
        text.style.top = Math.round(y - originY) + 'px';
        text.setAttribute('data-side', side);
    }

    function show(event) {
        var tip = event.target && event.target.closest ? event.target.closest('.nv-tip') : null;
        if (!tip) {
            return;
        }
        current = tip;
        place(tip);
    }

    // The page moves under a tip that stays open: it follows its mark.
    function follow() {
        if (current && document.body.contains(current)) {
            place(current);
        }
    }

    // A mark inside a label would act as the label does when clicked: tick
    // its box, or move the cursor into its field. A click on the mark only
    // opens the tip, which is also how a finger reaches it.
    document.addEventListener('click', function (event) {
        var tip = event.target && event.target.closest ? event.target.closest('.nv-tip') : null;
        if (tip && tip.closest('label')) {
            event.preventDefault();
            tip.focus();
        }
    });

    document.addEventListener('mouseover', show);
    document.addEventListener('focusin', show);
    window.addEventListener('scroll', follow, true);
    window.addEventListener('resize', follow);
})();
