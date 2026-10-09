<?php
/** window.showSuccessModal(title, body, showCountdown) — the confirmation every public form shows. Shared by includes/footer.php and booking-embed.php. */
?>
<!-- Global success modal -->
<script>
(function(){
  var _countdownInterval = null;

  window.showSuccessModal = function(title, body, showCountdown) {
    // Clear any running countdown
    if (_countdownInterval) { clearInterval(_countdownInterval); _countdownInterval = null; }

    var backdrop = document.createElement('div');
    backdrop.className = 'ts-modal-backdrop';
    backdrop.id = 'tsSuccessModal';

    var countdownHtml = '';
    if (showCountdown) {
      countdownHtml = '<p class="ts-modal__countdown">Hold expires in <strong id="tsHoldCountdown">24:00:00</strong></p>';
    }

    backdrop.innerHTML =
      '<div class="ts-modal" role="dialog" aria-modal="true" aria-label="' + title + '">' +
        '<button class="ts-modal__close" aria-label="Close">&times;</button>' +
        '<div class="ts-modal__icon">' +
          '<svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
        '</div>' +
        '<h3 class="ts-modal__title">' + title + '</h3>' +
        '<p class="ts-modal__body">' + body + '</p>' +
        countdownHtml +
        '<button class="ts-modal__btn ts-modal-close-btn">Got it</button>' +
      '</div>';

    document.body.appendChild(backdrop);
    document.body.style.overflow = 'hidden';

    // Countdown timer
    if (showCountdown) {
      var expiresAt = Date.now() + 24 * 60 * 60 * 1000;
      function tick() {
        var left = Math.max(0, expiresAt - Date.now());
        var h = String(Math.floor(left / 3600000)).padStart(2, '0');
        var m = String(Math.floor((left % 3600000) / 60000)).padStart(2, '0');
        var s = String(Math.floor((left % 60000) / 1000)).padStart(2, '0');
        var el = document.getElementById('tsHoldCountdown');
        if (el) el.textContent = h + ':' + m + ':' + s;
      }
      tick();
      _countdownInterval = setInterval(tick, 1000);
    }

    function closeModal() {
      if (_countdownInterval) { clearInterval(_countdownInterval); _countdownInterval = null; }
      backdrop.remove();
      document.body.style.overflow = '';
    }

    backdrop.querySelector('.ts-modal__close').addEventListener('click', closeModal);
    backdrop.querySelector('.ts-modal-close-btn').addEventListener('click', closeModal);
    backdrop.addEventListener('click', function(e){ if (e.target === backdrop) closeModal(); });
    document.addEventListener('keydown', function esc(e){ if (e.key === 'Escape') { closeModal(); document.removeEventListener('keydown', esc); } });
  };
})();
</script>
