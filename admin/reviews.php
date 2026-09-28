<?php
/**
 * Admin: guest reviews (owner-only — marketing copy, like the property pages).
 * Per property (or the whole group), star rating, published, "show on home page";
 * drag to reorder within a property. PRG + CSRF; reorder is AJAX.
 * Logic: includes/reviews.php. Pages keep their built-in reviews until published rows exist.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/reviews.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_login();
require_owner();

$pageTitle  = 'Reviews';
$activeMenu = 'reviews';
$self       = '/admin/reviews.php';
$supported  = reviews_supported();

$flash = $_SESSION['rv_flash'] ?? null; unset($_SESSION['rv_flash']);
$back  = function (string $type, string $msg, string $anchor = '') use ($self): never {
    $_SESSION['rv_flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: ' . $self . ($anchor !== '' ? '#' . $anchor : '')); exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    $id  = (int)($_POST['review_id'] ?? 0) ?: null;
    if ($act === 'reorder') {
        reviews_reorder((array)(json_decode((string)($_POST['order'] ?? '[]'), true) ?: []));
        header('Content-Type: application/json'); exit(json_encode(['ok' => true]));
    }
    if ($act === 'save') {
        [$d, $err] = review_clean($_POST);
        if ($d['venue_id'] !== null && !db_query('SELECT 1 FROM venues WHERE id = :v', [':v' => $d['venue_id']])->fetchColumn()) $err['venue_id'] = 'Pick a property from the list.';
        if ($id !== null && !db_query('SELECT 1 FROM reviews WHERE id = :i', [':i' => $id])->fetchColumn()) $back('error', 'That review no longer exists.');
        if ($err) $back('error', implode(' ', $err), $id ? 'rv-' . $id : 'rv-new');
        $id = review_save($id, $d);
        audit_log('review.save', 'review', $id, $d['author']);
        $back('success', "Review by {$d['author']} saved.", 'rv-' . $id);
    }
    if ($act === 'delete' && $id) {
        db_query('DELETE FROM reviews WHERE id = :i', [':i' => $id]);
        audit_log('review.delete', 'review', $id, '');
        $back('success', 'Review deleted.');
    }
    $back('error', 'Nothing changed.');
}

$venues  = db_query('SELECT id, name, slug FROM venues ORDER BY sort_order, name')->fetchAll();
$reviews = reviews_all();
$groups  = [];
foreach ($reviews as $r) $groups[$r['venue_id'] === null ? 0 : (int)$r['venue_id']][] = $r;
$groupName = fn(int $vid) => $vid === 0 ? 'Tribal Sand (all properties)' : ((string)(array_values(array_filter($venues, fn($v) => (int)$v['id'] === $vid))[0]['name'] ?? '?'));
$shownOn = ['my-amani' => true, 'maya-kobe' => true];   // property pages with a reviews section

$form = function (?array $r) use ($self, $venues): void {
    $isNew = $r === null;
    $r ??= ['id' => 0, 'venue_id' => null, 'author' => '', 'detail' => '', 'rating' => 5, 'quote' => '', 'is_published' => true, 'show_on_home' => false];
    $on = fn($v) => $v === true || $v === 't' || $v === 1 || $v === '1';
    ?>
    <form method="POST" action="<?= e($self) ?>" class="rv-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <?php if (!$isNew): ?><input type="hidden" name="review_id" value="<?= (int)$r['id'] ?>"><?php endif; ?>
      <div class="rv-grid">
        <div class="field"><label>Guest</label><input name="author" maxlength="120" value="<?= e($r['author']) ?>" required placeholder="e.g. Sophie M."></div>
        <div class="field"><label>Detail <span class="text-muted">(optional)</span></label><input name="detail" maxlength="120" value="<?= e($r['detail']) ?>" placeholder="e.g. Germany · January 2026"></div>
        <div class="field"><label>Property</label>
          <select name="venue_id"><option value="0">Tribal Sand (all properties)</option>
            <?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>" <?= (int)$r['venue_id'] === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Rating</label>
          <select name="rating"><?php for ($s = 5; $s >= 1; $s--): ?><option value="<?= $s ?>" <?= (int)$r['rating'] === $s ? 'selected' : '' ?>><?= review_stars($s) ?> (<?= $s ?>)</option><?php endfor; ?></select></div>
      </div>
      <div class="field"><label>What they said</label><textarea name="quote" rows="3" maxlength="<?= REVIEW_QUOTE_MAX ?>" required placeholder="Without quote marks — the page adds them."><?= e($r['quote']) ?></textarea></div>
      <div class="rv-foot">
        <label class="togglerow"><span class="toggle"><input type="checkbox" name="is_published" value="1" <?= $on($r['is_published']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Published</span></label>
        <label class="togglerow"><span class="toggle"><input type="checkbox" name="show_on_home" value="1" <?= $on($r['show_on_home']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Show on the home page</span></label>
        <button type="submit" class="btn-primary btn-sm"><?= admin_icon($isNew ? 'plus' : 'check', 15) ?> <?= $isNew ? 'Add review' : 'Save review' ?></button>
      </div>
    </form>
    <?php
};

include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>Reviews</h1></div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_reviews.sql</code> migration (Admin → Migrations), then <code>db/seeds/seed_reviews.php</code> to start from the reviews the site shows today.</div>
<?php else: ?>
<p class="text-muted rv-intro">Guest reviews on the property pages (My Amani and Maya Kobe have a reviews section) and the home page (up to 3, ticked <strong>Show on the home page</strong>). A page keeps its built-in reviews until it has published ones here. Only publish genuine reviews, with the guest's permission.</p>

<?php if (!$reviews): ?><?php dt_empty('No reviews yet — add one below, or run db/seeds/seed_reviews.php to copy in the ones the site shows today.', 'inbox'); ?><?php endif; ?>

<?php foreach ($groups as $vid => $rows): $slug = $vid ? (string)(array_values(array_filter($venues, fn($v) => (int)$v['id'] === $vid))[0]['slug'] ?? '') : ''; ?>
<div class="rv-group">
  <div class="rv-group__head"><h2><?= e($groupName($vid)) ?></h2>
    <?php if ($vid && empty($shownOn[$slug])): ?><span class="badge badge--grey" data-tip="This property's page has no reviews section yet — these show only if picked for the home page">Page has no reviews section</span><?php endif; ?></div>
  <div class="rv-list" data-rv-list>
    <?php foreach ($rows as $r): $rid = (int)$r['id']; ?>
    <details class="card rv" id="rv-<?= $rid ?>" data-id="<?= $rid ?>">
      <summary class="rv__head">
        <span class="rv__grip" draggable="true" data-tip="Drag to reorder" aria-hidden="true"><?= admin_icon('grip', 18) ?></span>
        <span class="rv__stars"><?= review_stars((int)$r['rating']) ?></span>
        <span class="rv__author"><?= e($r['author']) ?></span>
        <span class="rv__quote text-muted"><?= e(mb_strimwidth((string)$r['quote'], 0, 90, '…')) ?></span>
        <span class="rv__badges">
          <?php if (!$r['is_published']): ?><span class="badge badge--orange">Hidden</span><?php endif; ?>
          <?php if ($r['show_on_home']): ?><span class="badge badge--blue">Home page</span><?php endif; ?>
        </span>
      </summary>
      <div class="rv__body">
        <?php $form($r); ?>
        <form method="POST" action="<?= e($self) ?>" class="rv__del">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="review_id" value="<?= $rid ?>">
          <button type="submit" class="btn-icon btn-icon--danger" data-confirm="Delete this review?" data-tip="Delete" aria-label="Delete review"><?= admin_icon('trash', 15) ?></button>
        </form>
      </div>
    </details>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<div class="card rv-add" id="rv-new">
  <div class="card__head"><span class="card__title">Add a review</span></div>
  <div class="card__body" style="padding:18px 20px"><?php $form(null); ?></div>
</div>

<style>
.rv-intro{margin:-6px 0 18px;font-size:13px;max-width:860px}
.rv-group{margin-bottom:22px}
.rv-group__head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.rv-group__head h2{font-size:15px;font-weight:600;margin:0}
.rv-list{display:grid;gap:8px}
.rv{overflow:hidden}
.rv.is-dragging{opacity:.5}
.rv__head{display:flex;align-items:center;gap:10px;padding:12px 16px;cursor:pointer;list-style:none;flex-wrap:wrap}
.rv__head::-webkit-details-marker{display:none}
.rv__grip{color:var(--muted);cursor:grab;display:inline-flex}
.rv__stars{color:#B8965A;letter-spacing:.05em;font-size:13px}
.rv__author{font-weight:600}
.rv__quote{flex:1 1 200px;min-width:0;font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rv__badges{display:flex;gap:4px}
.rv__body{border-top:1px solid var(--border);padding:16px;position:relative}
.rv__del{position:absolute;right:16px;bottom:16px;margin:0}
.rv-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:0 16px}
.rv-grid .field{min-width:0}
.rv-form .field textarea{width:100%}
.rv-foot{display:flex;flex-wrap:wrap;align-items:center;gap:12px 20px;padding-right:44px}
.rv-add{margin-top:10px}
@media (max-width:640px){ .rv__quote{flex-basis:100%;white-space:normal} }
</style>
<script>
(function(){
  var CSRF = <?= json_encode(csrf_token()) ?>;
  document.querySelectorAll('[data-rv-list]').forEach(function(list){
    var dragged = null;
    list.querySelectorAll('.rv').forEach(function(card){
      var grip = card.querySelector('.rv__grip');
      grip.addEventListener('click', function(e){ e.preventDefault(); });
      grip.addEventListener('dragstart', function(e){ dragged = card; card.classList.add('is-dragging'); e.dataTransfer.setDragImage(card, 20, 20); });
      grip.addEventListener('dragend', function(){
        if (!dragged) return; dragged.classList.remove('is-dragging'); dragged = null;
        var fd = new FormData(); fd.append('action', 'reorder'); fd.append('csrf_token', CSRF);
        fd.append('order', JSON.stringify([].map.call(list.querySelectorAll('.rv'), function(x){ return x.dataset.id; })));
        fetch(<?= json_encode($self) ?>, {method:'POST', body:fd, credentials:'same-origin'});
      });
      card.addEventListener('dragover', function(e){ if (dragged) e.preventDefault(); });
      card.addEventListener('dragenter', function(e){ if (!dragged || dragged === card) return; e.preventDefault();
        var k = [].slice.call(list.querySelectorAll('.rv')), di = k.indexOf(dragged), ri = k.indexOf(card); list.insertBefore(dragged, di < ri ? card.nextSibling : card); });
    });
  });
  if (location.hash && /^#rv-\d+$/.test(location.hash)) { var d = document.querySelector(location.hash); if (d) d.open = true; }
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
