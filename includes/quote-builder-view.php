<?php
/**
 * The Quote Builder UI — one partial for the page (admin/quote-builder.php) and
 * the enquiry pop-up (admin/submission-view.php). Layout mirrors the Maya Ilai
 * rate tool's Quote Builder tab: Stay details · Rooms · Extras | Summary.
 *
 * Config before include:
 *   $qb_context  'page' | 'modal'   (modal adds "Insert into reply")
 *   $qb_prefill  ['name','check_in','check_out','adults','children','room_id']  (all optional)
 *   $qb_cur      'KES' | 'USD'       initial currency (default KES)
 *
 * Needs includes/quote-builder.php + includes/rates-compare.php loaded. The CSS
 * and the script are emitted once per page, INLINE (shell navigation re-runs
 * inline scripts only). Pricing is always the server's (api/quote-builder.php).
 */
$qb_context = ($qb_context ?? 'page') === 'modal' ? 'modal' : 'page';
$qb_prefill = is_array($qb_prefill ?? null) ? $qb_prefill : [];
$qb_cur     = in_array($qb_cur ?? '', ['KES', 'USD'], true) ? $qb_cur : 'KES';
$__qbCat    = qb_catalog(admin_venue_ids());
$__pre      = fn(string $k) => (string)($qb_prefill[$k] ?? '');
$__preRoom  = (int)($qb_prefill['room_id'] ?? 0);
$__preCi    = rates_window_ymd($__pre('check_in')) ?? '';
$__preCo    = rates_window_ymd($__pre('check_out')) ?? '';
$__preAd    = max(0, (int)($qb_prefill['adults'] ?? 2));
$__preCh    = max(0, (int)($qb_prefill['children'] ?? 0));
$__byVenue  = [];
foreach ($__qbCat['rooms'] as $r) $__byVenue[(int)$r['venue_id']][] = $r;
$__clientCat = [
    'tours' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => (string)$t['name'],
        'price' => is_numeric($t['price_amount']) && (float)$t['price_amount'] > 0 ? (float)$t['price_amount'] : null,
        'per_person' => qb_bool($t['price_per_person'])], $__qbCat['tours']),
    'transfers' => array_map(fn($t) => ['id' => $t['id'], 'name' => $t['label'],
        'price' => is_numeric($t['price_amount']) && (float)$t['price_amount'] > 0 ? (float)$t['price_amount'] : null], $__qbCat['transfers']),
    'tour_cur' => $__qbCat['site_currency'], 'transfer_cur' => $__qbCat['site_currency'],
];
$__uid = 'qb' . substr(md5((string)mt_rand()), 0, 6);
?>
<div class="qb" data-qb data-context="<?= e($qb_context) ?>"
     data-endpoint="/api/quote-builder.php" data-csrf="<?= e(csrf_token()) ?>">
  <script type="application/json" data-qb-catalog><?= json_encode($__clientCat, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

  <div class="qb-head">
    <?php if ($qb_context === 'page'): ?>
    <p class="qb-sub">Rooms at live website prices, plus activities, transfers and your own lines. Nothing is saved or booked.</p>
    <?php endif; ?>
    <span class="qb-spacer"></span>
    <?php $ms_cur = $qb_cur; include __DIR__ . '/money-switch.php'; ?>
  </div>

  <div class="qb-grid">
    <div class="qb-stack">
      <section class="qb-card">
        <h3>Stay details</h3>
        <div class="qb-form">
          <label class="qb-field qb-field--wide"><span>Guest or group name</span>
            <input class="inp" data-qb-name value="<?= e($__pre('name')) ?>" placeholder="Sofia Martin"></label>
          <div class="qb-field"><span>Check-in</span>
            <button type="button" class="dp-btn" data-dp-role="ci" data-dp-pair="<?= e($__uid) ?>" data-dp-target="<?= e($__uid) ?>_ci" data-dp-placeholder="Pick a date"><?= $__preCi ? e(date('j M Y', strtotime($__preCi))) : 'Pick a date' ?></button>
            <input type="hidden" id="<?= e($__uid) ?>_ci" data-qb-ci value="<?= e($__preCi) ?>"></div>
          <div class="qb-field"><span>Check-out</span>
            <button type="button" class="dp-btn" data-dp-role="co" data-dp-pair="<?= e($__uid) ?>" data-dp-target="<?= e($__uid) ?>_co" data-dp-placeholder="Pick a date"><?= $__preCo ? e(date('j M Y', strtotime($__preCo))) : 'Pick a date' ?></button>
            <input type="hidden" id="<?= e($__uid) ?>_co" data-qb-co value="<?= e($__preCo) ?>"></div>
          <label class="qb-field"><span>Adults</span><input class="inp inp--num" type="number" min="0" data-qb-adults value="<?= $__preAd ?>"></label>
          <label class="qb-field"><span>Children</span><input class="inp inp--num" type="number" min="0" data-qb-children value="<?= $__preCh ?>"></label>
          <label class="qb-field"><span>Discount %</span><input class="inp inp--num" type="number" min="0" max="100" step="0.5" data-qb-disc placeholder="0"></label>
          <label class="qb-field qb-field--wide"><span>Discount note</span><input class="inp" data-qb-discnote placeholder="Returning guest"></label>
        </div>
      </section>

      <section class="qb-card">
        <div class="qb-card__head"><h3>Rooms</h3>
          <?php if (count($__byVenue) > 1): ?>
          <div class="qb-chips" role="group" aria-label="Show properties">
            <?php foreach ($__byVenue as $vid => $vr): ?>
            <label class="optchip qb-chip"><input type="checkbox" data-qb-venue="<?= (int)$vid ?>" checked> <?= e($vr[0]['venue_name']) ?></label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php if (!$__byVenue): ?>
        <p class="qb-empty">No rooms are available to quote for your account.</p>
        <?php else: ?>
        <div class="qb-tablewrap">
          <table class="qb-table">
            <thead><tr><th>Room</th><th>Qty</th><th>Guests</th><th>Sleeps</th><th>Free</th><th class="qb-money">Avg / night</th><th class="qb-money">Stay total</th></tr></thead>
            <?php foreach ($__byVenue as $vid => $vr): ?>
            <tbody data-qb-group="<?= (int)$vid ?>">
              <tr class="qb-group"><td colspan="7"><?= e($vr[0]['venue_name']) ?></td></tr>
              <?php foreach ($vr as $r): $pre = (int)$r['id'] === $__preRoom; ?>
              <tr data-room="<?= (int)$r['id'] ?>" class="<?= $pre ? 'is-picked' : '' ?>">
                <td><span class="qb-rname"><?= e($r['name']) ?></span><span class="qb-mix" data-qb-mix></span></td>
                <td><input class="inp inp--sm inp--num qb-num" type="number" min="0" max="<?= (int)$r['max_qty'] ?>" value="<?= $pre ? 1 : 0 ?>" data-qb-qty aria-label="Quantity"></td>
                <td><input class="inp inp--sm inp--num qb-num" type="number" min="0" value="<?= $pre ? max(1, $__preAd + $__preCh) : 0 ?>" data-qb-guests aria-label="Guests"></td>
                <td data-qb-cap><?= (int)$r['capacity'] ?: '—' ?></td>
                <td data-qb-free>—</td>
                <td class="qb-money" data-qb-avg>—</td>
                <td class="qb-money" data-qb-line>—</td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <?php endforeach; ?>
          </table>
        </div>
        <?php endif; ?>
      </section>

      <section class="qb-card">
        <div class="qb-card__head"><h3>Extras</h3>
          <select class="qb-pick" data-qb-pick aria-label="Add an extra">
            <option value="">+ Add an extra…</option>
            <?php if ($__clientCat['tours']): ?>
            <optgroup label="Activities">
              <?php foreach ($__clientCat['tours'] as $t): ?><option value="tour:<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <?php if ($__clientCat['transfers']): ?>
            <optgroup label="Transfers">
              <?php foreach ($__clientCat['transfers'] as $t): ?><option value="transfer:<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <optgroup label="Other"><option value="custom">Custom line</option></optgroup>
          </select>
        </div>
        <div class="qb-tablewrap">
          <table class="qb-table qb-extras">
            <thead><tr><th>Item</th><th>Qty</th><th>Unit price</th><th>Basis</th><th class="qb-money">Total</th><th></th></tr></thead>
            <tbody data-qb-extras><tr class="qb-noextras"><td colspan="6">No extras yet.</td></tr></tbody>
          </table>
        </div>
      </section>
    </div>

    <aside class="qb-summary">
      <div class="qb-totalbox">
        <div class="qb-totalbox__label">Quote total</div>
        <div class="qb-grand" data-qb-total>—</div>
        <div class="qb-per" data-qb-perguest>Add rooms or extras</div>
      </div>
      <div class="qb-metrics">
        <div><span>Nights</span><strong data-qb-m-nights>0</strong></div>
        <div><span>Guests</span><strong data-qb-m-guests>0</strong></div>
        <div><span>Sleeps</span><strong data-qb-m-cap>0</strong></div>
        <div><span>Rooms / night</span><strong data-qb-m-nightly>—</strong></div>
      </div>
      <div class="qb-breakdown" data-qb-breakdown></div>
      <div class="qb-notices" data-qb-notices></div>
      <div class="qb-status" data-qb-status aria-live="polite"></div>
      <div class="qb-actions">
        <button type="button" class="btn-outline btn-sm" data-qb-copy>Copy quote</button>
        <button type="button" class="btn-outline btn-sm" data-qb-print>Print / PDF</button>
        <?php if ($qb_context === 'modal'): ?>
        <button type="button" class="btn-primary btn-sm qb-insert" data-qb-insert>Insert into reply</button>
        <?php endif; ?>
      </div>
      <p class="qb-foot">Maya Ilai group and availability deals: <a href="/admin/maya-ilai-rates.php">Maya Ilai rate tool</a>.</p>
    </aside>
  </div>

  <div class="qb-print" data-qb-printout aria-hidden="true"></div>
</div>

<?php if (empty($GLOBALS['__qb_assets_done'])): $GLOBALS['__qb_assets_done'] = true; ?>
<style>
.qb{--qb-navy:#182247;--qb-navy2:#26335f;--qb-line:var(--border);color:var(--text)}
.qb-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 14px}
.qb-sub{margin:0;color:var(--muted);font-size:13px}.qb-spacer{flex:1}
.qb-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;align-items:start}
.qb-stack{display:grid;gap:16px;min-width:0}
.qb-card{background:var(--white);border:1px solid var(--qb-line);border-radius:14px;padding:16px 18px;min-width:0}
.qb-card h3{margin:0 0 12px;font-size:15px}
.qb-card__head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.qb-card__head h3{margin:0}
.qb-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.qb-field{display:grid;gap:5px;font-size:12px;color:var(--muted);font-weight:600}
.qb-field--wide{grid-column:span 2}
.qb-field .inp,.qb-field .dp-btn{width:100%;box-sizing:border-box}
.qb-chips{display:flex;flex-wrap:wrap;gap:6px}.qb-chip{padding:4px 11px;font-size:12px}
.qb-tablewrap{overflow-x:auto}
.qb-table{width:100%;border-collapse:collapse;font-size:13px}
.qb-table th,.qb-table td{padding:7px 8px;border-bottom:1px solid var(--qb-line);text-align:left;white-space:nowrap}
.qb-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
.qb-table .qb-money{text-align:right}
.qb-group td{background:#f5f1ea;font-weight:700;font-size:12.5px}
.qb-table tr.is-picked td{background:#f3faf8}
.qb-rname{display:block}.qb-mix{display:block;font-size:11px;color:var(--muted)}
.qb-num{width:64px}
[data-qb-free].is-none{color:#b91c1c;font-weight:700}
.qb-extras .inp{width:100%;min-width:90px;box-sizing:border-box}
.qb-noextras td{color:var(--muted);font-size:12.5px}
.qb-summary{position:sticky;top:18px;background:var(--white);border:1px solid var(--qb-line);border-radius:14px;overflow:hidden}
.qb-totalbox{background:linear-gradient(145deg,var(--qb-navy),var(--qb-navy2));color:#fff;padding:18px 20px}
.qb-totalbox__label{color:#cdd5ea;font-size:12px}
.qb-grand{font-size:1.9rem;font-weight:800;letter-spacing:-.03em;margin:4px 0}
.qb-per{color:#cdd5ea;font-size:13px}
.qb-metrics{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--qb-line)}
.qb-metrics div{background:var(--white);padding:11px 14px}
.qb-metrics span{display:block;color:var(--muted);font-size:11px}
.qb-metrics strong{font-size:15px}
.qb-breakdown{padding:12px 16px}
.qb-line{display:flex;justify-content:space-between;gap:12px;padding:5px 0;font-size:13px}
.qb-line span:first-child{color:var(--muted)}
.qb-line--total{border-top:1px solid var(--qb-line);margin-top:6px;padding-top:10px;font-weight:800}
.qb-line--total span:first-child{color:var(--text)}
.qb-fx{font-size:11.5px;color:var(--muted);margin:8px 0 0}
.qb-notices{padding:0 16px;display:grid;gap:6px}
.qb-notice{padding:8px 11px;border-radius:9px;font-size:12.5px}
.qb-notice--warn{background:#fff0ed;color:#a3362a}.qb-notice--info{background:#e6f4f2;color:#096c66}
.qb-status{padding:6px 16px 0;font-size:12px;color:var(--muted);min-height:18px}
.qb-actions{display:flex;flex-wrap:wrap;gap:8px;padding:10px 16px 14px}
.qb-foot{padding:0 16px 14px;margin:0;font-size:11.5px;color:var(--muted)}
.qb-empty{color:var(--muted);font-size:13px;margin:0}
.qb-print{display:none}
@media (max-width:1100px){.qb-grid{grid-template-columns:minmax(0,1fr)}.qb-summary{position:static}}
@media (max-width:640px){.qb-form{grid-template-columns:repeat(2,minmax(0,1fr))}.qb-field--wide{grid-column:span 2}}
/* Print is scoped to body.qb-printing (set by the Print button only), so a plain
   browser print of the host page (e.g. an enquiry) still prints that page. */
@media print{
  body.qb-printing *{visibility:hidden!important}
  body.qb-printing .qb-print,body.qb-printing .qb-print *{visibility:visible!important}
  body.qb-printing .qb-print{display:block!important;position:absolute;left:0;top:0;width:100%;padding:24px;font-size:13px;color:#000}
  .qb-print img{height:42px;filter:invert(1)}
  .qb-print h1{font-size:20px;margin:14px 0 4px}
  .qb-print table{width:100%;border-collapse:collapse;margin:12px 0}
  .qb-print td{padding:6px 0;border-bottom:1px solid #ddd}
  .qb-print td:last-child{text-align:right}
  .qb-print .qb-p-total td{font-weight:800;border-bottom:0;font-size:15px}
  .qb-print p{margin:4px 0;color:#444}
}
</style>
<script><?php readfile(__DIR__ . '/../admin/assets/admin-quote-builder.js'); ?></script>
<?php endif; ?>
