<?php
/**
 * Help & guides — the library of how-to guides for the team (design B's "All
 * guides"). Everyone may open it; it lists only the guides about pages this
 * account can open (help_visible_guides() over the resolved nav, the same rule as
 * the on-page Help panel). ?g=<slug> shows one guide in full.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/help-guides.php';
require_login();

$pageTitle  = 'Help & guides';
$activeMenu = 'help';
include __DIR__ . '/_layout.php';

$guides = help_visible_guides(help_guides(), help_visible_pages($__nav));
$slug   = (string)($_GET['g'] ?? '');
$open   = null;
foreach ($guides as $g) if ($g['slug'] === $slug) { $open = $g; break; }
$byArea = [];
foreach ($guides as $g) $byArea[$g['area']][] = $g;
$visiblePages = array_flip(help_visible_pages($__nav));
?>
<style>
.hl-hero { background: var(--brand-dk); color: #fff; border-radius: 14px; padding: 22px 24px; margin: 0 0 22px; display: grid; gap: 10px; }
.hl-hero h1 { margin: 0; font-size: 24px; color: #fff; }
.hl-hero p { margin: 0; color: #cfe0e3; font-size: 14px; max-width: 640px; }
.hl-hero .inp { max-width: 520px; width: 100%; box-sizing: border-box; }
.hl-area { margin: 0 0 22px; }
.hl-area h2 { font-size: 14px; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); margin: 0 0 10px; }
.hl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px; }
.hl-card { display: block; background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 12px 14px; text-decoration: none; color: var(--text); }
.hl-card:hover { border-color: var(--brand); }
.hl-card strong { display: block; font-size: 14.5px; }
.hl-card span { display: block; font-size: 12.5px; color: var(--muted); margin-top: 3px; }
.hl-guide { background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 22px 24px; max-width: 760px; }
.hl-guide h1 { font-size: 22px; margin: 4px 0 6px; }
.hl-guide .help-steps { font-size: 15px; line-height: 1.6; }
.hl-pages { font-size: 13px; color: var(--muted); margin: 14px 0 0; }
</style>

<?php if ($open): ?>
  <p style="margin:0 0 12px"><a href="/admin/help.php" class="help-back" style="text-decoration:none">‹ All guides</a></p>
  <article class="hl-guide">
    <div class="help-guide__meta"><?= e(HELP_AREAS[$open['area']] ?? '') ?> · <?= (int)$open['minutes'] ?> min</div>
    <h1><?= e($open['title']) ?></h1>
    <p style="margin:0 0 16px;color:var(--muted)"><?= e($open['summary']) ?></p>
    <ol class="help-steps">
      <?php foreach ($open['steps'] as $s): ?><li><span><?= help_rich($s['text']) ?></span></li><?php endforeach; ?>
    </ol>
    <?php if (!empty($open['tips'])): ?>
    <div class="help-tips"><?php foreach ($open['tips'] as $t): ?><p><?= help_rich($t) ?></p><?php endforeach; ?></div>
    <?php endif; ?>
    <?php $startPage = null; foreach ($open['pages'] as $p) if ($p !== 'help.php' && isset($visiblePages[$p])) { $startPage = $p; break; } ?>
    <div class="help-actions">
      <?php if ($startPage): ?>
      <a class="btn-primary btn-sm" href="/admin/<?= e($startPage) ?>" data-help-tour-link="<?= e($open['slug']) ?>">Open the page and show me</a>
      <?php endif; ?>
      <a class="btn-outline btn-sm" href="/admin/help.php">More guides</a>
    </div>
  </article>
<?php else: ?>
  <?php if ($slug !== ''): ?><div class="alert alert--info" style="margin-bottom:16px">That guide isn’t available for your account.</div><?php endif; ?>
  <div class="hl-hero">
    <h1>How can we help<?= !empty($admin['name']) ? ', ' . e(strtok((string)$admin['name'], ' ')) : '' ?>?</h1>
    <p>Short guides for the pages you use. On any page, press <strong>Help</strong> at the top right for the guides about that page, and <strong>Show me</strong> to be walked through it.</p>
    <input type="search" class="inp" id="hlSearch" placeholder="Search guides, e.g. convert an enquiry to a hold" autocomplete="off" aria-label="Search guides">
  </div>
  <?php foreach (HELP_AREAS as $key => $label): if (empty($byArea[$key])) continue; ?>
  <section class="hl-area" data-hl-area>
    <h2><?= e($label) ?></h2>
    <div class="hl-grid">
      <?php foreach ($byArea[$key] as $g): ?>
      <a class="hl-card" href="/admin/help.php?g=<?= e(rawurlencode($g['slug'])) ?>" data-hl-text="<?= e(strtolower($g['title'] . ' ' . $g['summary'] . ' ' . $label)) ?>">
        <strong><?= e($g['title']) ?></strong><span><?= e($g['summary']) ?> · <?= (int)$g['minutes'] ?> min</span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
  <p class="help-empty" id="hlNone" hidden>No guide matches your search.</p>
  <script>
  (function () {
    var q = document.getElementById('hlSearch');
    if (!q || q.dataset.bound) return; q.dataset.bound = '1';
    q.addEventListener('input', function () {
      var words = q.value.toLowerCase().split(/\s+/).filter(Boolean), any = false;
      document.querySelectorAll('[data-hl-area]').forEach(function (sec) {
        var shown = 0;
        sec.querySelectorAll('[data-hl-text]').forEach(function (c) {
          var hay = c.getAttribute('data-hl-text'), ok = words.every(function (w) { return hay.indexOf(w) !== -1; });
          c.hidden = !ok; if (ok) shown++;
        });
        sec.hidden = !shown; if (shown) any = true;
      });
      document.getElementById('hlNone').hidden = any;
    });
  })();
  </script>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
