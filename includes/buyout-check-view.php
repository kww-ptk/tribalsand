<?php
/**
 * Buyout check — admin/rates.php, OWNER ONLY (the caller includes it only when
 * is_owner(); api/rate-editor.php re-checks). For Maya Kobe, Zuri and any other
 * property with one whole-property room and two or more published rooms: the
 * nights where the buyout is not the sum of the rooms, and an "Update buyout to
 * match" button.
 *
 * Nothing here computes a price: rate_editor_buyout_checks() is the rate
 * editor's own mode 'sum' preview, and the button sends that request + its
 * fingerprint to the normal apply — so a rate changed since the page loaded is
 * refused (409), and the change lands in "Rate changes" with undo.
 * Needs includes/rate-editor.php loaded.
 */
$__bc = rate_editor_buyout_checks(date('Y-m-d'));
if (!$__bc) return;
$__bcOff  = array_values(array_filter($__bc, fn($c) => $c['nights_off'] > 0 || $c['error']));
$__bcBase = array_values(array_filter($__bc, fn($c) => $c['base_sum'] > 0 && abs($c['base_price'] - $c['base_sum']) >= 0.005));
$__bcShow = 6;   // ranges listed per property before "+N more"
?>
<details class="card bc" data-bc data-endpoint="/api/rate-editor.php" data-csrf="<?= e(csrf_token()) ?>"<?= $__bcOff ? ' open' : '' ?>>
  <summary class="card__head">
    <span class="card__title">Buyout check
      <span class="bc-help" data-tip="A whole-property buyout should cost the sum of its published rooms. Checked night by night from today to <?= e(date('j M Y', strtotime($__bc[0]['last']))) ?>. Rooms with no price, or in another currency, are left out."><?= admin_icon('info', 14) ?></span></span>
    <span class="bc-meta">
      <?php if ($__bcOff): ?>
      <span class="badge badge--orange"><?= count($__bcOff) ?> <?= count($__bcOff) === 1 ? 'buyout needs' : 'buyouts need' ?> an update</span>
      <?php else: ?>
      <span class="badge badge--green">Every buyout matches its rooms</span>
      <?php endif; ?>
      <?= admin_icon('chevron-down', 15) ?>
    </span>
  </summary>
  <div class="bc-body">
    <?php foreach ($__bc as $c): $cur = $c['currency']; ?>
    <div class="bc-venue">
      <div class="bc-venue__head">
        <div class="bc-venue__name"><strong><?= e($c['venue_name']) ?></strong> <span class="text-muted">· <?= e($c['room_name']) ?></span></div>
        <?php if ($c['error']): ?>
        <span class="badge badge--red">Can't check</span>
        <?php elseif ($c['nights_off'] > 0): ?>
        <span class="badge badge--orange"><?= (int)$c['nights_off'] ?> <?= $c['nights_off'] === 1 ? 'night differs' : 'nights differ' ?></span>
        <button type="button" class="btn-primary btn-sm" data-bc-fix
                data-bc-req="<?= e(json_encode($c['request'], JSON_UNESCAPED_SLASHES)) ?>"
                data-bc-fp="<?= e((string)$c['fingerprint']) ?>"
                data-bc-what="<?= e($c['venue_name'] . ' · ' . $c['room_name']) ?>"
                data-bc-nights="<?= (int)$c['nights_off'] ?>">Update buyout to match</button>
        <?php else: ?>
        <span class="badge badge--green">All <?= number_format((int)$c['nights_checked']) ?> nights match</span>
        <?php endif; ?>
      </div>

      <?php if ($c['error']): ?>
      <p class="bc-note"><?= e($c['error']) ?></p>
      <?php endif; ?>

      <?php if ($c['runs']): ?>
      <ul class="bc-runs">
        <?php foreach (array_slice($c['runs'], 0, $__bcShow) as $run): ?>
        <li>
          <span class="bc-runs__when"><?= e(re_nights_text($run['first'], $run['last'])) ?> <span class="text-muted">· <?= (int)$run['nights'] ?> <?= $run['nights'] === 1 ? 'night' : 'nights' ?></span></span>
          <span class="bc-runs__price"><span class="text-muted"><?= e(re_range_text($run['was_min'], $run['was_max'], $cur)) ?></span> → <strong><?= e(re_money_text($run['price'], $cur)) ?></strong><?php if ($run['label'] !== null): ?> <span class="text-muted">· <?= e($run['label']) ?></span><?php endif; ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($c['runs']) > $__bcShow): ?>
      <p class="bc-note">+ <?= count($c['runs']) - $__bcShow ?> more <?= count($c['runs']) - $__bcShow === 1 ? 'range' : 'ranges' ?> — all of them are updated together.</p>
      <?php endif; ?>
      <?php endif; ?>

      <?php foreach ($c['notes'] as $n): ?><p class="bc-note"><?= e($n) ?></p><?php endforeach; ?>

      <?php if ($c['base_sum'] > 0 && abs($c['base_price'] - $c['base_sum']) >= 0.005): ?>
      <div class="bc-base">
        <span><span class="text-muted">Base price</span> <strong><?= e(re_money_text($c['base_price'], $cur)) ?></strong>
          <span class="text-muted">· rooms add up to</span> <strong><?= e(re_money_text($c['base_sum'], $cur)) ?></strong></span>
        <a href="/admin/room-edit.php?id=<?= (int)$c['room_id'] ?>" class="btn-outline btn-sm"
           data-tip="The base price applies on nights without a seasonal rate. It is set on the room page.">Edit base price</a>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</details>

<?php if (empty($GLOBALS['__bc_assets_done'])): $GLOBALS['__bc_assets_done'] = true; ?>
<style>
.bc{margin:0 0 16px}
.bc > summary{cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between;gap:10px}
.bc > summary::-webkit-details-marker{display:none}
.bc-meta{display:inline-flex;align-items:center;gap:8px;color:var(--muted)}
.bc[open] .bc-meta svg{transform:rotate(180deg)}
.bc-body{padding:4px 18px 16px;display:grid;gap:12px}
.bc-help{display:inline-flex;vertical-align:-2px;margin-left:4px;color:var(--muted);cursor:help}
.bc-base{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:13px;background:#fbf3ec;border-radius:8px;padding:7px 10px}
.bc-venue{border:1px solid var(--border);border-radius:12px;padding:12px 14px;display:grid;gap:8px;min-width:0}
.bc-venue__head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.bc-venue__name{flex:1 1 auto;min-width:0}
.bc-runs{list-style:none;margin:0;padding:0;display:grid;gap:4px;font-size:13px}
.bc-runs li{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:5px 0;border-bottom:1px solid var(--border)}
.bc-runs li:last-child{border-bottom:0}
.bc-note{margin:0;font-size:12.5px;color:var(--muted)}
.bc-note--warn{color:#a3362a}
</style>
<script>
(function () {
  if (window.__bcBound) return;   // admin shell navigation re-runs inline scripts
  window.__bcBound = true;
  function say(msg, tone) {
    if (typeof window.tsToast === 'function') window.tsToast(msg, tone === 'err' ? 'err' : 'ok');
    else window.alert(msg);
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-bc-fix]');
    if (!btn) return;
    var box = btn.closest('[data-bc]');
    var n = +btn.getAttribute('data-bc-nights');
    var msg = 'Set ' + btn.getAttribute('data-bc-what') + ' to the sum of its rooms on ' + n + ' night' + (n === 1 ? '' : 's') +
      '? The change is listed under Rate changes, where you can undo it.';
    function go() {
      var body;
      try { body = JSON.parse(btn.getAttribute('data-bc-req')); } catch (err) { say('Reload the page and try again.', 'err'); return; }
      body.action = 'apply';
      body.fingerprint = btn.getAttribute('data-bc-fp');
      body.csrf_token = box.getAttribute('data-csrf');
      btn.disabled = true;
      fetch(box.getAttribute('data-endpoint'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(body)
      }).then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
        .then(function (x) {
          if (x.d && x.d.ok) {
            // Same flash key as the rate editor, so its toast shows after the reload.
            try { sessionStorage.setItem('re_flash', 'Buyout updated — ' + x.d.applied.summary); } catch (err) {}
            location.reload();
            return;
          }
          btn.disabled = false;
          if (x.status === 409) { say('Rates changed since this page loaded — reloading to check again.', 'err'); setTimeout(function () { location.reload(); }, 1200); return; }
          say((x.d && x.d.error) || 'Could not update the buyout. Try again.', 'err');
        })
        .catch(function () { btn.disabled = false; say('Could not reach the server. Try again.', 'err'); });
    }
    if (typeof window.tsConfirm === 'function') window.tsConfirm(msg, go, { title: 'Update buyout', label: 'Update' });
    else if (window.confirm(msg)) go();
  });
})();
</script>
<?php endif; ?>
