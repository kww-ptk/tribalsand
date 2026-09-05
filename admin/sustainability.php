<?php
/**
 * Admin: Sustainability metrics — the live figures on sustainability.php and the
 * home page's "Live Data" cards.
 *
 * Each row stores the last KNOWN-TRUE reading plus an optional daily accrual
 * rate; the public pages derive today's number from them. Saving a changed value
 * re-bases the accrual (see sus_metric_save() in includes/sustainability.php),
 * so entering a fresh meter reading never compounds on an accrued one.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sustainability.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();
require_owner();

/** Small metric-specific glyph (Lucide paths) keyed by metric_key. */
function sus_icon(string $key, int $s = 18): string {
    $p = match (true) {
        str_contains($key, 'solar')            => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        str_contains($key, 'co2') || str_contains($key, 'carbon') => '<path d="M17.5 19a4.5 4.5 0 0 0 0-9h-1.8A7 7 0 1 0 4 15.7"/>',
        str_contains($key, 'beach') || str_contains($key, 'waste') => '<path d="M3 6h18M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        str_contains($key, 'water') || str_contains($key, 'desal') => '<path d="M12 2.5S6 9 6 14a6 6 0 0 0 12 0c0-5-6-11.5-6-11.5z"/>',
        default                                => '<path d="M11 20A7 7 0 0 1 4 13c0-6 7-11 7-11s7 5 7 11a7 7 0 0 1-7 7z"/><path d="M11 20v-9"/>',
    };
    return '<svg viewBox="0 0 24 24" width="' . $s . '" height="' . $s . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'save' && sustainability_supported()) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            sus_metric_save($id, [
                'label'          => $_POST['label']          ?? '',
                'value'          => $_POST['value']          ?? 0,
                'growth_per_day' => $_POST['growth_per_day'] ?? 0,
                'max_value'      => $_POST['max_value']      ?? '',
                'unit'           => $_POST['unit']           ?? '',
                'decimals'       => $_POST['decimals']       ?? 2,
                'note'           => $_POST['note']           ?? '',
                'sort_order'     => $_POST['sort_order']     ?? 0,
                'is_published'   => isset($_POST['is_published']),
            ], $_SESSION['admin_id'] ?? null);
            audit_log('sustainability.save', 'sustainability_metric', $id);
        }
    }
    header('Location: /admin/sustainability.php?saved=1'); exit;
}

$pageTitle  = 'Sustainability';
$activeMenu = 'sustainability';
$rows       = sus_metrics_all();

/** Trim a stored NUMERIC to a tidy editable value: 4.2857 → "4.2857", 0.0000 → "0". */
$fmt = fn($v, $dp = 4) => $v === null ? '' : (rtrim(rtrim(number_format((float) $v, $dp, '.', ''), '0'), '.') ?: '0');

include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1 style="display:inline-flex;align-items:center;gap:10px"><?= sus_icon('leaf', 22) ?> Sustainability</h1>
  <a href="/sustainability.php" target="_blank" rel="noopener" class="btn-outline btn-sm"><?= admin_icon('external-link', 15) ?> View page</a>
</div>
<p class="text-muted" style="margin:0 0 20px;font-size:13px">
  The figures on the sustainability page and the home page's Live Data cards. Enter the number you can
  verify today — if you set a daily rate, the site works out the rest from the date you entered it.
</p>

<?php if (!sustainability_supported()): ?>
<div class="alert alert--error" style="margin-bottom:16px">
  Editing is unavailable until the <code>add_sustainability_metrics.sql</code> migration is applied
  (Admin → Migrations). Both pages still render their built-in figures in the meantime, so nothing is broken.
</div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert--success is-flash" style="margin-bottom:16px">Saved.</div>
<?php endif; ?>

<style>
.sus-readout{display:flex;align-items:flex-start;gap:8px;margin-top:14px;padding:10px 12px;
  background:var(--bg,#f4efe9);border:1px solid var(--border,#e7ded7);border-radius:8px;
  font-size:12.5px;line-height:1.6;color:var(--muted,#8a7f74)}
.sus-readout__ic{flex:0 0 auto;color:var(--accent,#1E5C6B);margin-top:1px}
.sus-readout strong{color:var(--ink,#102F3A)}
</style>

<?php foreach ($rows as $r):
    $now     = sus_metric_display($r);
    $in30    = sus_metric_display($r, time() + 30 * 86400);
    $accrues = (float) $r['growth_per_day'] > 0; ?>
<div class="card" style="margin-bottom:14px">
  <div class="card__head">
    <span class="card__title" style="display:inline-flex;align-items:center;gap:8px"><span style="color:var(--accent,#1E5C6B)"><?= sus_icon($r['metric_key']) ?></span> <?= e($r['label']) ?></span>
    <span class="text-muted" style="font-size:12px">
      <code><?= e($r['metric_key']) ?></code>
      <?php if (!$r['is_published']): ?> · <span class="badge badge--orange">hidden</span><?php endif; ?>
    </span>
  </div>
  <div class="card__body">
    <form method="POST" action="/admin/sustainability.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">

      <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end">
        <label class="wsf wsf--grow" style="min-width:200px">
          <span>Label</span>
          <input type="text" name="label" class="inp" value="<?= e($r['label']) ?>" required>
        </label>
        <label class="wsf">
          <span>Reading today</span>
          <input type="number" name="value" class="inp inp--num no-spin" style="width:130px"
                 value="<?= e($fmt($r['value'], 2)) ?>" step="0.01" required>
        </label>
        <label class="wsf">
          <span>Unit</span>
          <input type="text" name="unit" class="inp" style="width:80px" value="<?= e($r['unit']) ?>" placeholder="MWh">
        </label>
        <label class="wsf">
          <span>Decimals</span>
          <input type="number" name="decimals" class="inp inp--num no-spin" style="width:80px"
                 value="<?= (int) $r['decimals'] ?>" min="0" max="4">
        </label>
        <label class="wsf">
          <span>Growth per day</span>
          <input type="number" name="growth_per_day" class="inp inp--num no-spin" style="width:130px"
                 value="<?= e($fmt($r['growth_per_day'])) ?>" step="0.0001" min="0">
        </label>
        <label class="wsf">
          <span>Cap (optional)</span>
          <input type="number" name="max_value" class="inp inp--num no-spin" style="width:120px"
                 value="<?= e($fmt($r['max_value'], 2)) ?>" step="0.01" placeholder="none">
        </label>
        <label class="wsf wsf--grow" style="min-width:200px">
          <span>Small print</span>
          <input type="text" name="note" class="inp" value="<?= e($r['note']) ?>" placeholder="Tribal Dunes · updated weekly">
        </label>
        <label class="wsf">
          <span>Order</span>
          <input type="number" name="sort_order" class="inp inp--num no-spin" style="width:80px" value="<?= (int) $r['sort_order'] ?>">
        </label>
        <label class="toggle" data-tip="<?= $r['is_published'] ? 'Shown on the site' : 'Hidden from the site' ?>" style="margin-bottom:6px">
          <input type="checkbox" name="is_published" value="1" <?= $r['is_published'] ? 'checked' : '' ?>>
          <span class="toggle-slider"></span>
        </label>
        <button type="submit" class="btn-primary btn-sm" style="margin-bottom:4px"><?= admin_icon('check', 15) ?> Save</button>
      </div>

      <div class="sus-readout">
        <span class="sus-readout__ic"><?= admin_icon('eye', 15) ?></span>
        <span>
          Renders today as <strong><?= e($now) ?><?= $r['unit'] !== '' ? ' ' . e($r['unit']) : '' ?></strong>
          <?php if ($accrues): ?>
            · in 30 days <strong><?= e($in30) ?><?= $r['unit'] !== '' ? ' ' . e($r['unit']) : '' ?></strong>
            · reading taken <?= e(date('j M Y', strtotime((string) $r['baseline_at']))) ?>
          <?php else: ?>
            · <span class="badge badge--grey">fixed</span> growth per day is 0 — set a rate to let it accrue
          <?php endif; ?>
        </span>
      </div>
      <p class="text-muted" style="margin:8px 0 0;font-size:12px">
        Entering a different reading re-starts the growth from that number, today. Changing only the label,
        note or rate leaves the running total alone.
      </p>
    </form>
  </div>
</div>
<?php endforeach; ?>

<?php if (sustainability_supported() && !$rows): ?>
<div class="card"><div class="card__body" style="padding:18px;font-size:14px">
  No metrics yet — re-run <code>add_sustainability_metrics.sql</code> to seed the five defaults.
</div></div>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
