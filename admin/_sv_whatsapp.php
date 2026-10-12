<?php
// Reply on WhatsApp (admin/submission-view.php, Conversation tab).
// Opens the guest's chat in the WhatsApp signed in on this computer (wa.me →
// desktop app or WhatsApp Web) with the owner's message filled in (Admin →
// Settings → Messages → WhatsApp reply). Staff press Send in WhatsApp; the page notes the
// message in the thread above. Nothing is sent from our side.
// Expects $sub, $payload, $id from the page.
require_once __DIR__ . '/../includes/whatsapp-reply.php';

$__waVenue = '';
if ((int)($sub['room_id'] ?? 0) > 0) {
    try {
        $__waVenue = (string) db_query('SELECT v.name FROM rooms r JOIN venues v ON v.id = r.venue_id WHERE r.id = :r',
                                       [':r' => (int)$sub['room_id']])->fetchColumn();
    } catch (Throwable $e) {}
}
$__waMe   = current_admin();
$__waCtx  = ['property' => $__waVenue, 'staff_name' => $__waMe ? trim((string)($__waMe['name'] ?? '')) : ''];
$__waMsgs = [];
foreach (WA_REPLY_LANGS as $__l => $__n) {
    $__waMsgs[$__l] = wa_reply_render(wa_reply_template($__l), wa_reply_vars($sub, $payload, $__l, $__waCtx));
}
$__waPhone  = trim((string)($sub['guest_phone'] ?? ''));
$__waDigits = wa_reply_phone_digits($__waPhone);
$__waLog    = submission_notes_supported();
$__waOkHint = 'Opens WhatsApp with this message ready to send' . ($__waLog ? ', and notes it in the thread above.' : '.');
$__waNoHint = 'Type the guest’s number with the country code to open WhatsApp.';
?>
<div class="card wa-card" id="waReply" data-sid="<?= (int)$id ?>" data-csrf="<?= e(csrf_token()) ?>"
     data-log="<?= $__waLog ? '1' : '0' ?>" data-ok-hint="<?= e($__waOkHint) ?>" data-no-hint="<?= e($__waNoHint) ?>">
  <div class="card__head">
    <span class="card__title wa-card__title">
      <span class="wa-card__icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.88 1.22 3.08.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.05 21.5h-.01a9.4 9.4 0 0 1-4.8-1.31l-.34-.2-3.57.93.95-3.48-.22-.36a9.43 9.43 0 0 1-1.45-5.02c0-5.2 4.24-9.44 9.45-9.44a9.4 9.4 0 0 1 6.68 2.77 9.38 9.38 0 0 1 2.76 6.68c0 5.21-4.24 9.43-9.45 9.43zm8.04-17.47A11.3 11.3 0 0 0 12.05.7C5.78.7.68 5.8.68 12.06c0 2 .52 3.96 1.52 5.68L.58 23.7l6.1-1.6a11.33 11.33 0 0 0 5.37 1.37h.01c6.26 0 11.36-5.1 11.37-11.36 0-3.04-1.18-5.89-3.34-8.04z"/></svg></span>
      Reply on WhatsApp
    </span>
    <nav class="segswitch wa-card__langs" aria-label="Message language">
      <?php foreach (WA_REPLY_LANGS as $__l => $__n): ?>
      <a href="#waReply" data-no-shell data-wa-lang="<?= e($__l) ?>"<?= $__l === 'en' ? ' class="is-on"' : '' ?>><?= e($__n) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
  <div class="card__body" style="padding:20px">
    <div class="field" style="max-width:340px">
      <label class="detail-item__label" for="waPhone">Guest’s WhatsApp number</label>
      <input type="tel" id="waPhone" class="inp" value="<?= e($__waPhone) ?>" placeholder="+34 666 385 737" autocomplete="off">
    </div>
    <div class="field" style="margin-top:14px">
      <label class="detail-item__label" for="waText">Message <span class="text-muted" style="font-weight:400;text-transform:none;letter-spacing:0">edit it here or in WhatsApp</span></label>
      <textarea id="waText" rows="5" class="inp inp--area" style="width:100%;box-sizing:border-box;min-height:120px;resize:vertical"><?= e($__waMsgs['en']) ?></textarea>
    </div>
    <?php foreach ($__waMsgs as $__l => $__m): ?>
    <template data-wa-msg="<?= e($__l) ?>"><?= e($__m) ?></template>
    <?php endforeach; ?>
    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:14px">
      <a id="waOpen" class="btn-primary wa-card__btn" target="_blank" rel="noopener" data-no-shell
         href="<?= e(wa_reply_link($__waDigits, $__waMsgs['en']) ?: '#waReply') ?>"<?= $__waDigits === '' ? ' aria-disabled="true"' : '' ?>>Open in WhatsApp</a>
      <span class="text-muted" id="waHint" style="font-size:12.5px"><?= e($__waDigits === '' ? $__waNoHint : $__waOkHint) ?></span>
    </div>
    <?php if (is_owner()): ?>
    <p class="text-muted" style="font-size:12px;margin:12px 0 0">Change the wording in <a href="/admin/settings#waReplySettings">Settings → Messages → WhatsApp reply</a>.</p>
    <?php endif; ?>
  </div>
</div>
<style>
  .wa-card .card__head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
  .wa-card__title{display:inline-flex;align-items:center;gap:10px}
  .wa-card__icon{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#25d366;color:#fff}
  .wa-card__langs{margin:0}
  a.wa-card__btn{background:#1fa855}
  a.wa-card__btn:hover{background:#178a45}
  a.wa-card__btn[aria-disabled="true"]{opacity:.5;cursor:not-allowed}
</style>
<script>
  (function () {
    var card = document.getElementById('waReply');
    if (!card || card.dataset.waReady) return;
    card.dataset.waReady = '1';
    var phone = document.getElementById('waPhone'), text = document.getElementById('waText'),
        btn = document.getElementById('waOpen'), hint = document.getElementById('waHint');

    // Same rule as wa_reply_phone_digits() in includes/whatsapp-reply.php.
    function digits(v) {
      v = (v || '').trim();
      var d = v.replace(/\D+/g, '');
      if (!d) return '';
      if (d.indexOf('00') === 0) d = d.slice(2);
      else if (v.charAt(0) !== '+' && d.charAt(0) === '0' && d.length === 10) d = '254' + d.slice(1);
      return (d.length >= 8 && d.length <= 15) ? d : '';
    }
    function sync() {
      var d = digits(phone.value), t = text.value.trim();
      if (d) {
        btn.href = 'https://wa.me/' + d + (t ? '?text=' + encodeURIComponent(t) : '');
        btn.removeAttribute('aria-disabled');
        hint.textContent = card.dataset.okHint;
      } else {
        btn.href = '#waReply';
        btn.setAttribute('aria-disabled', 'true');
        hint.textContent = card.dataset.noHint;
      }
    }
    phone.addEventListener('input', sync);
    text.addEventListener('input', sync);

    card.querySelectorAll('[data-wa-lang]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        var tpl = card.querySelector('template[data-wa-msg="' + a.dataset.waLang + '"]');
        if (!tpl) return;
        card.querySelectorAll('[data-wa-lang]').forEach(function (x) { x.classList.toggle('is-on', x === a); });
        text.value = tpl.content.textContent;
        sync();
      });
    });

    btn.addEventListener('click', function (e) {
      if (btn.getAttribute('aria-disabled') === 'true') { e.preventDefault(); phone.focus(); return; }
      // The link opens WhatsApp; note the message in the thread alongside.
      var t = text.value.trim();
      if (card.dataset.log !== '1' || !t) return;
      fetch('/api/submission-thread.php', {
        method: 'POST', keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: Number(card.dataset.sid), kind: 'reply', csrf_token: card.dataset.csrf,
          body: '💬 Opened on WhatsApp to +' + digits(phone.value) + ':\n' + t })
      }).catch(function () {});
    });
    sync();
  })();
</script>
