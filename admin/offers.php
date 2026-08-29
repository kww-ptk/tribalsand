<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/offers.php';
require_login();
require_owner();   // site-wide promotions = owner only

// ── POST: publish toggle / delete ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && offers_supported()) {
    verify_csrf();
    $oid   = (int)($_POST['offer_id'] ?? 0);
    $offer = fetch_offer($oid);

    if ($offer && isset($_POST['toggle_publish'])) {
        $val = $_POST['is_published'] === '1' ? 'FALSE' : 'TRUE';
        db_query("UPDATE offers SET is_published = {$val}, updated_at = now() WHERE id = :id", [':id' => $oid]);
        audit_log('offer.publish', 'offer', $oid, $offer['title']);
    }
    if ($offer && isset($_POST['delete_offer'])) {
        if (!empty($offer['image_key'])) { require_once __DIR__ . '/../includes/storage.php'; storage_delete($offer['image_key']); }
        db_query('DELETE FROM offers WHERE id = :id', [':id' => $oid]);
        audit_log('offer.delete', 'offer', $oid, $offer['title']);
    }
    header('Location: /admin/offers.php');
    exit;
}

$offers = fetch_all_offers();

$pageTitle  = 'Offers';
$activeMenu = 'offers';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>Offers &amp; Specials</h1>
  <a href="/admin/offer-edit.php" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> New Offer</a>
</div>

<?php if (!offers_supported()): ?>
<div class="alert alert--error">The <code>offers</code> table isn’t there yet — run <code>db/migrations/add_offers.sql</code> on this database first.</div>
<?php endif; ?>

<div class="card">
  <div class="card__head">
    <span class="card__title">All Offers</span>
    <span class="text-muted" style="font-size:12px">Published, in-date offers show in the homepage strip. 3+ auto-scroll as a marquee.</span>
  </div>
  <div class="card__body" style="padding:0">
    <?php if (!$offers): ?>
    <div style="text-align:center;padding:2.5rem;color:var(--muted)">
      No offers yet. <a href="/admin/offer-edit.php">Create your first offer →</a>
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:1%">#</th>
            <th>Offer</th>
            <th>Category</th>
            <th>Badge</th>
            <th>Valid</th>
            <th>Published</th>
            <th style="width:1%;text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($offers as $o): ?>
          <tr>
            <td class="text-muted"><?= (int)$o['sort_order'] ?></td>
            <td>
              <strong><?= e($o['title']) ?></strong>
              <?php if ($o['subtitle']): ?><div class="text-muted" style="font-size:12px"><?= e($o['subtitle']) ?></div><?php endif; ?>
            </td>
            <td><span class="badge badge--blue"><?= e(offer_category_label($o['category'])) ?></span></td>
            <td class="text-muted"><?= $o['badge'] ? e($o['badge']) : '—' ?></td>
            <td class="text-muted" style="font-size:12px">
              <?php
                $vf = $o['valid_from'] ? date('j M Y', strtotime($o['valid_from'])) : null;
                $vt = $o['valid_to']   ? date('j M Y', strtotime($o['valid_to']))   : null;
                if ($vf || $vt) {
                    echo e(($vf ?: '—') . ' → ' . ($vt ?: '—'));
                    if ($o['valid_to'] && $o['valid_to'] < date('Y-m-d')) echo ' <span class="badge badge--red">expired</span>';
                } else { echo 'Always'; }
              ?>
            </td>
            <td>
              <form method="POST" action="/admin/offers.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="toggle_publish" value="1">
                <input type="hidden" name="offer_id" value="<?= (int)$o['id'] ?>">
                <input type="hidden" name="is_published" value="<?= $o['is_published'] ? '1' : '0' ?>">
                <button type="submit" class="badge <?= $o['is_published'] ? 'badge--green' : 'badge--red' ?>" style="border:none;cursor:pointer">
                  <?= $o['is_published'] ? 'Live' : 'Hidden' ?>
                </button>
              </form>
            </td>
            <td style="text-align:right">
              <span class="dt-actions">
                <a href="/admin/offer-edit.php?id=<?= (int)$o['id'] ?>" class="btn-icon btn-icon--outline" title="Edit" aria-label="Edit"><?= admin_icon('edit') ?></a>
                <form method="POST" action="/admin/offers.php" style="display:inline" onsubmit="return confirm('Delete this offer? This cannot be undone.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="delete_offer" value="1">
                  <input type="hidden" name="offer_id" value="<?= (int)$o['id'] ?>">
                  <button type="submit" class="btn-icon btn-icon--outline" title="Delete" aria-label="Delete"><?= admin_icon('trash') ?></button>
                </form>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
