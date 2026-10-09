/*
 * Check-in terms dialog — shared by the lead wizard (js/checkin-wizard.js) and the
 * co-guest page (includes/app/checkin-guest.php).
 *
 * When a guest presses continue / complete without ticking "I agree", we do not
 * show an error or round-trip to the server: we open the terms in the guest
 * dialog (.pa-modal) and let them accept right there.
 *
 *   window.ciTermsDialog(termsEl, onAccept)
 *     termsEl  — the on-page .ci-waiver box (its content is copied, not moved)
 *     onAccept — called after "I accept the terms"; the caller ticks the box and
 *                carries on with what the guest pressed.
 */
(function () {
  if (window.ciTermsDialog) return;

  window.ciTermsDialog = function (termsEl, onAccept) {
    if (document.querySelector('.ci-terms-modal')) return;   // already open
    var back = document.createElement('div');
    back.className = 'pa-modal-backdrop';
    back.innerHTML =
      '<div class="pa-modal ci-terms-modal" role="dialog" aria-modal="true" aria-labelledby="ciTermsT">' +
        '<h3 class="pa-modal__title" id="ciTermsT">Please accept the terms to continue</h3>' +
        '<p class="pa-modal__body">Read the terms below, then tap “I accept the terms”.</p>' +
        '<div class="ci-waiver ci-terms-modal__text" tabindex="0"></div>' +
        '<div class="pa-modal__actions">' +
          '<button type="button" class="pa-btn pa-btn--primary" data-accept>I accept the terms</button>' +
          '<button type="button" class="pa-btn" data-cancel>Not now</button>' +
        '</div>' +
      '</div>';
    // Server-rendered, already-escaped terms (nl2br(e(...))) — safe to copy as HTML.
    back.querySelector('.ci-terms-modal__text').innerHTML = termsEl ? termsEl.innerHTML : '';
    document.body.appendChild(back);
    var prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function close() {
      back.remove();
      document.body.style.overflow = prevOverflow;
      document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);
    back.addEventListener('click', function (e) { if (e.target === back) close(); });
    back.querySelector('[data-cancel]').addEventListener('click', close);
    back.querySelector('[data-accept]').addEventListener('click', function () {
      close();
      if (typeof onAccept === 'function') onAccept();
    });
    back.querySelector('[data-accept]').focus();
  };
})();
