<?php
/**
 * Property → Guest extras tab (included by admin/venue-edit.php, owner only).
 * Which activities, wellness treatments and transfers this property's guests can
 * add from their booking page, in what order, which are featured and when.
 * Logic: includes/guest-extras.php. Expects $id, $venue.
 */
$__xSupported = guest_extras_supported();
$__xCatalogue = guest_extras_catalogue((int)$id);
$__xSettings  = guest_extras_settings((int)$id);
$__xResolved  = guest_extras_resolve($__xCatalogue, $__xSettings);
$__xOpts      = guest_extras_venue_options((int)$id);
$__xStats     = guest_extras_stats((int)$id);
$__xOn        = upsells_supported() ? !empty($venue['upsell_enabled']) : true;
$__xNotice    = (string)($_SESSION['venue_extras_notice'] ?? '');
unset($_SESSION['venue_extras_notice']);
?>
<div class="tab-panel" id="tab-extras">
  <style>
  .vx-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 14px; }
  .vx-stats { font-size: 13px; color: var(--muted); }
  .vx-stats b { color: var(--text); font-variant-numeric: tabular-nums; }
  .vx-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
  .vx-table th { text-align: left; font-size: 11px; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); padding: 8px 10px; border-bottom: 1px solid var(--border); white-space: nowrap; }
  .vx-table td { padding: 9px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
  .vx-row.is-off td:not(.vx-keep) { opacity: .5; }
  .vx-row.is-drag { opacity: .4; }
  .vx-grip { cursor: grab; color: var(--muted); width: 28px; }
  .vx-name { min-width: 240px; }
  .vx-name strong { display: block; }
  .vx-name span { font-size: 12px; color: var(--muted); }
  .vx-thumb { width: 40px; height: 40px; border-radius: 8px; background: var(--bg) center/cover no-repeat; flex: 0 0 auto; }
  .vx-price { white-space: nowrap; }
  .vx-price.is-none { color: #b45309; }
  .vx-opts { display: flex; gap: 22px; flex-wrap: wrap; align-items: center; margin: 16px 0 4px; font-size: 13.5px; }
  .vx-scroll { overflow-x: auto; }
  .vx-tools { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin: 0 0 10px; }
  .vx-tools .inp { max-width: 260px; }
  </style>
  <div class="card">
    <div class="card__head"><span class="card__title">Guest extras</span></div>
    <div class="card__body" style="padding:20px">
    <?php if (!$__xSupported): ?>
      <div class="alert alert--info">Guest extras need a database update. Run <code>add_venue_extras.sql</code> in Settings → Migrations. Until then guests see every activity, as before.</div>
    <?php else: ?>
      <?php if ($__xNotice !== ''): ?><div class="alert alert--info" style="margin-bottom:14px"><?= e($__xNotice) ?></div><?php endif; ?>
      <form method="POST" id="vxForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_extras">
        <input type="hidden" name="order" id="vxOrder" value="">
        <div class="vx-head">
          <div>
            <p style="margin:0 0 6px;font-size:13.5px;color:var(--muted);max-width:640px">What guests at <?= e($venue['name']) ?> can add to their stay from their booking page. Each one is a request your team confirms; once confirmed it goes on the guest&rsquo;s bill. Prices come from <a href="/admin/tours.php">Activities</a> and <a href="/admin/services.php">Service pricing</a>.</p>
            <div class="vx-stats">Last 30 days: <b><?= (int)$__xStats['requested'] ?></b> requested · <b><?= (int)$__xStats['confirmed'] ?></b> confirmed<?php if ($__xStats['value'] > 0): ?> · <b><?= e(format_price($__xStats['value'])) ?></b> confirmed value<?php endif; ?></div>
            <?php if (!$__xSettings): ?><p style="margin:8px 0 0;font-size:12.5px;color:#b45309">Not set up yet: guests see everything below that is ticked. Save once to make this list the property&rsquo;s own.</p><?php endif; ?>
          </div>
          <?php if (upsells_supported()): ?>
          <label class="togglerow" data-help="extras-on"><span class="toggle"><input type="checkbox" name="upsell_enabled" value="1"<?= $__xOn ? ' checked' : '' ?>><span class="toggle-slider"></span></span><span><strong>Offer extras at <?= e($venue['name']) ?></strong></span></label>
          <?php endif; ?>
        </div>

        <?php if (!$__xResolved): ?>
          <p class="text-muted">No activities or transfers are available to this property yet. Add them in <a href="/admin/tours.php">Activities</a> or <a href="/admin/services.php">Service pricing</a>.</p>
        <?php else: ?>
        <div class="vx-tools">
          <input type="search" class="inp" id="vxFind" placeholder="Find an extra" autocomplete="off" aria-label="Find an extra">
          <label class="togglerow"><span class="toggle"><input type="checkbox" id="vxOnlyShown"><span class="toggle-slider"></span></span><span>Only what guests see</span></label>
          <span class="text-muted" id="vxCount" style="font-size:12.5px"></span>
        </div>
        <div class="vx-scroll" data-help="extras-list">
        <table class="vx-table">
          <thead><tr><th></th><th>Extra</th><th>Comes from</th><th>Price shown</th><th>When to offer</th><th>Featured</th><th>Show</th></tr></thead>
          <tbody id="vxBody">
          <?php foreach ($__xResolved as $x): $k = $x['key']; ?>
            <tr class="vx-row<?= $x['shown'] ? '' : ' is-off' ?>" draggable="true" data-key="<?= e($k) ?>">
              <td class="vx-grip vx-keep" data-tip="Drag to reorder" aria-hidden="true"><?= admin_icon('grip', 18) ?></td>
              <td class="vx-name"><div style="display:flex;gap:10px;align-items:center">
                <span class="vx-thumb"<?= $x['image'] !== '' ? ' style="background-image:url(\'' . e($x['image']) . '\')"' : '' ?>></span>
                <div><strong><?= e($x['name']) ?></strong><span><?= e(trim($x['group'] . ($x['duration'] !== '' ? ' · ' . $x['duration'] : ''))) ?></span></div></div></td>
              <td><?= $x['kind'] === 'transfer' ? 'Service pricing' : 'Activities' ?></td>
              <td class="vx-price<?= $x['price'] === null ? ' is-none' : '' ?>"><?= e(guest_extra_price_label($x)) ?></td>
              <td><select name="when[<?= e($k) ?>]" aria-label="When to offer <?= e($x['name']) ?>">
                <?php foreach (GUEST_EXTRAS_WHEN as $wk => $wl): ?><option value="<?= e($wk) ?>"<?= $x['when'] === $wk ? ' selected' : '' ?>><?= e($wl) ?></option><?php endforeach; ?>
              </select></td>
              <td><label class="ckwrap" data-tip="Shown on the guest's home screen and in the booking emails (up to <?= GUEST_EXTRAS_MAX_FEATURED ?>)"><input type="checkbox" name="featured[<?= e($k) ?>]" value="1"<?= $x['featured'] ? ' checked' : '' ?> aria-label="Feature <?= e($x['name']) ?>"><span class="ck"></span></label></td>
              <td class="vx-keep"><label class="togglerow"><span class="toggle"><input type="checkbox" name="shown[<?= e($k) ?>]" value="1" data-vx-shown<?= $x['shown'] ? ' checked' : '' ?> aria-label="Show <?= e($x['name']) ?> to guests"><span class="toggle-slider"></span></span></label></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>

        <div class="vx-opts">
          <label class="togglerow"><span class="toggle"><input type="checkbox" name="extras_in_email" value="1"<?= $__xOpts['in_email'] ? ' checked' : '' ?>><span class="toggle-slider"></span></span><span>Put the featured extras in the booking emails</span></label>
          <label style="display:inline-flex;gap:8px;align-items:center">Reminder email before arrival
            <select name="extras_reminder_days" aria-label="Reminder email before arrival">
              <?php foreach ([0 => 'Off', 1 => '1 day before', 2 => '2 days before', 3 => '3 days before', 5 => '5 days before', 7 => '7 days before', 14 => '14 days before'] as $dv => $dl): ?>
              <option value="<?= $dv ?>"<?= $__xOpts['reminder_days'] === $dv ? ' selected' : '' ?>><?= e($dl) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <button type="submit" class="btn-primary btn-sm" style="margin-top:16px" data-help="extras-save">Save extras</button>
      </form>
      <script>
      (function () {
        var body = document.getElementById('vxBody'), form = document.getElementById('vxForm');
        if (!form || form.dataset.bound) return; form.dataset.bound = '1';
        var dragged = null;
        if (body) {
          body.addEventListener('dragstart', function (e) { dragged = e.target.closest('.vx-row'); if (dragged) dragged.classList.add('is-drag'); });
          body.addEventListener('dragend', function () { if (dragged) dragged.classList.remove('is-drag'); dragged = null; });
          body.addEventListener('dragover', function (e) {
            e.preventDefault();
            var over = e.target.closest('.vx-row'); if (!over || !dragged || over === dragged) return;
            var r = over.getBoundingClientRect();
            body.insertBefore(dragged, (e.clientY - r.top) > r.height / 2 ? over.nextSibling : over);
          });
          body.addEventListener('change', function (e) {
            if (e.target.matches('[data-vx-shown]')) e.target.closest('.vx-row').classList.toggle('is-off', !e.target.checked);
          });
        }
        // Find + "only what guests see" — client-side, rows stay in the form either way.
        var find = document.getElementById('vxFind'), only = document.getElementById('vxOnlyShown'), count = document.getElementById('vxCount');
        function filter() {
          var q = (find && find.value || '').toLowerCase().trim(), shown = 0, total = 0;
          form.querySelectorAll('.vx-row').forEach(function (r) {
            var on = r.querySelector('[data-vx-shown]').checked; total++; if (on) shown++;
            r.hidden = (q !== '' && r.textContent.toLowerCase().indexOf(q) === -1) || (only && only.checked && !on);
          });
          if (count) count.textContent = shown + ' of ' + total + ' shown to guests';
        }
        if (find) find.addEventListener('input', filter);
        if (only) only.addEventListener('change', filter);
        if (body) body.addEventListener('change', filter);
        filter();
        form.addEventListener('submit', function () {
          document.getElementById('vxOrder').value = Array.prototype.map.call(form.querySelectorAll('.vx-row'), function (r) { return r.dataset.key; }).join(',');
        });
      })();
      </script>
    <?php endif; ?>
    </div>
  </div>
</div>
