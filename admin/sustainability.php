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
require_login();
require_owner();

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
  <h1>Sustainability</h1>
  <a href="/sustainability.php" target="_blank" rel="noopener" class="btn-outline btn-sm">View page</a>
</div>
<p class="text-muted" style="margin:0 0 20px;font-size:13px">
  The figures on the sustainability page and the home page's Live Data cards. Enter the number you can
  verify today — if you set a daily rate, the site works out the rest from the date you entered it.
</p>

<?php if (!sustainability_supported()): ?>
<div class="card" style="border-left:4px solid var(--red,#dc2626);margin-bottom:16px">
  <div class="card__body" style="padding:14px 18px;font-size:14px">
    Editing is unavailable until the <code>add_sustainability_metrics.sql</code> migration is applied
    (Admin → Migrations). Both pages still render their built-in figures in the meantime, so nothing is broken.
  </div>
</div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
<div class="card" style="border-left:4px solid var(--green,#16a34a);margin-bottom:16px">
  <div class="card__body" style="padding:12px 18px;font-size:14px">Saved.</div>
</div>
<?php endif; ?>

<?php foreach ($rows as $r):
    $now     = sus_metric_display($r);
    $in30    = sus_metric_display($r, time() + 30 * 86400);
    $accrues = (float) $r['growth_per_day'] > 0; ?>
<div class="card" style="margin-bottom:14px">
  <div class="card__head">
    <span class="card__title"><?= e($r['label']) ?></span>
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
        <button type="submit" class="btn-primary btn-sm" style="margin-bottom:4px">Save</button>
      </div>

      <p class="text-muted" style="margin:12px 0 0;font-size:12.5px;line-height:1.7">
        Renders today as <strong><?= e($now) ?><?= $r['unit'] !== '' ? ' ' . e($r['unit']) : '' ?></strong>
        <?php if ($accrues): ?>
          · in 30 days <strong><?= e($in30) ?><?= $r['unit'] !== '' ? ' ' . e($r['unit']) : '' ?></strong>
          · reading taken <?= e(date('j M Y', strtotime((string) $r['baseline_at']))) ?>
        <?php else: ?>
          · fixed (growth per day is 0 — set a rate to let it accrue)
        <?php endif; ?>
      </p>
      <p class="text-muted" style="margin:6px 0 0;font-size:12px">
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
