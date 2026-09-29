<?php
/**
 * KES | USD switch (markup) + the client config and script (once per page).
 *
 * Config before include:  $ms_cur  'KES' | 'USD'  — the currency the server
 * rendered the page in (amounts come from rc_money_html()).
 *
 * The script is emitted INLINE via readfile(): admin shell navigation re-runs
 * inline scripts but never an external <script src> inside page content.
 * Depends on includes/db.php (fx_rates(), e(), TS_CURRENCIES).
 */
$__fx   = fx_rates();
$__kes  = (float)($__fx['rates']['KES'] ?? 0);
$__when = !empty($__fx['fetched_at']) ? date('j M', strtotime((string)$__fx['fetched_at'])) : null;
$ms_cur = in_array($ms_cur ?? '', ['KES', 'USD'], true) ? $ms_cur : 'KES';
?>
<span class="mny-wrap">
  <span class="mny-switch" role="group" aria-label="Currency">
    <button type="button" data-money-cur="KES" class="<?= $ms_cur === 'KES' ? 'is-on' : '' ?>" aria-pressed="<?= $ms_cur === 'KES' ? 'true' : 'false' ?>">KES</button>
    <button type="button" data-money-cur="USD" class="<?= $ms_cur === 'USD' ? 'is-on' : '' ?>" aria-pressed="<?= $ms_cur === 'USD' ? 'true' : 'false' ?>">USD</button>
  </span>
  <span class="mny-note"><?= $__kes > 0 ? '1 USD = ' . e(rc_trimz(number_format($__kes, 2, '.', ''))) . ' KES' : 'No exchange rate' ?><?= $__when ? ' · updated ' . e($__when) : ' · rate not synced' ?></span>
</span>
<?php if (empty($GLOBALS['__ms_assets_done'])): $GLOBALS['__ms_assets_done'] = true; ?>
<style>
.mny-wrap{display:inline-flex;align-items:center;gap:10px;flex-wrap:wrap}
.mny-switch{display:inline-flex;border:1.5px solid var(--border);border-radius:999px;overflow:hidden;background:var(--white)}
.mny-switch button{border:0;background:transparent;padding:6px 14px;font:inherit;font-size:12.5px;font-weight:600;color:var(--muted);cursor:pointer}
.mny-switch button.is-on{background:var(--brand);color:#fff}
.mny-note{font-size:11.5px;color:var(--muted)}
.mny.is-approx{font-style:italic}
</style>
<script>window.TS_FX = <?= json_encode(['rates' => $__fx['rates'], 'symbols' => array_map(fn($c) => $c['symbol'], TS_CURRENCIES)], JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script><?php readfile(__DIR__ . '/../admin/assets/admin-money.js'); ?></script>
<?php endif; ?>
