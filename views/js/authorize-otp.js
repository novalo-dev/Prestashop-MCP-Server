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

/*
 * The code page of the authorization page: six boxes that behave as one
 * field, and the countdown before another code may be asked for.
 *
 * The page works without it - each box takes its digit, the form posts, and
 * the server refuses an early request for a new code whatever the button
 * shows. This only spares the employee the tabbing and the guessing.
 */
(function () {
  'use strict';

  var form = document.getElementById('somcp-otp');
  if (!form) {
    return;
  }
  var boxes = Array.prototype.slice.call(form.querySelectorAll('input.digit'));

  function submitWhenFull() {
    var full = boxes.every(function (box) { return box.value !== ''; });
    if (full) {
      form.submit();
    }
  }

  // Spreads digits over the boxes from the given one, as when a whole code
  // is typed fast or pasted from the e-mail.
  function spread(from, digits) {
    var i;
    for (i = 0; i < digits.length && from + i < boxes.length; i += 1) {
      boxes[from + i].value = digits.charAt(i);
    }
    var next = boxes[Math.min(from + i, boxes.length - 1)];
    next.focus();
    submitWhenFull();
  }

  boxes.forEach(function (box, index) {
    box.addEventListener('input', function () {
      var digits = box.value.replace(/\D/g, '');
      box.value = '';
      if (digits !== '') {
        spread(index, digits);
      }
    });
    box.addEventListener('keydown', function (event) {
      if (event.key === 'Backspace' && box.value === '' && index > 0) {
        boxes[index - 1].value = '';
        boxes[index - 1].focus();
        event.preventDefault();
      }
    });
    box.addEventListener('paste', function (event) {
      var text = (event.clipboardData || window.clipboardData).getData('text') || '';
      var digits = text.replace(/\D/g, '');
      if (digits !== '') {
        event.preventDefault();
        spread(0, digits);
      }
    });
  });
  if (boxes[0]) {
    boxes[0].focus();
  }

  var resend = document.getElementById('somcp-resend');
  if (!resend) {
    return;
  }
  var left = parseInt(resend.getAttribute('data-wait'), 10) || 0;
  var waiting = resend.getAttribute('data-label-wait') || '';
  var ready = resend.getAttribute('data-label') || '';

  function tick() {
    if (left <= 0) {
      resend.disabled = false;
      resend.textContent = ready;

      return;
    }
    var seconds = left % 60;
    resend.textContent = waiting.replace('%time%', Math.floor(left / 60) + ':' + (seconds < 10 ? '0' : '') + seconds);
    left -= 1;
    window.setTimeout(tick, 1000);
  }
  tick();
}());
