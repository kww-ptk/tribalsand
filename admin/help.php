<?php
/**
 * Help & guides — the library of how-to guides for the team (design C, Oct 2026:
 * "read beside the list"). The guide list sits on the left (search + area chips)
 * and the chosen guide's steps and tips read on the right, with "Open the page and
 * show me" to start the walkthrough. On a phone the list and the guide are two
 * screens (?g=<slug> opens the guide screen).
 *
 * Everyone may open it; it lists only the guides about pages this account can open
 * (help_visible_guides() over the resolved nav, the same rule as the on-page Help
 * panel). Every guide is rendered server-side; the script only switches which one
 * shows, so the page works with JavaScript off (each list item is a ?g= link).
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
// Desktop always shows a guide on the right: the asked-for one, else the first.
$current = $open ?? ($guides[0] ?? null);
$first   = ($admin['name'] ?? '') !== '' ? strtok((string)$admin['name'], ' ') : '';
?>
<style>
.hl-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 6px 18px; margin: 0 0 14px; }
.hl-head h1 { margin: 0; font-size: 22px; }
.hl-head p { margin: 0; color: var(--muted); font-size: 13.5px; max-width: 640px; }
.hl-shell { display: grid; grid-template-columns: 340px minmax(0, 1fr); background: var(--white); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; min-height: 600px; }
.hl-side { border-right: 1px solid var(--border); display: flex; flex-direction: column; min-width: 0; }
.hl-side__top { padding: 14px; border-bottom: 1px solid var(--border); display: grid; gap: 10px; }
.hl-side__top .inp { width: 100%; box-sizing: border-box; }
.hl-chips { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; padding-bottom: 2px; }
.hl-chips::-webkit-scrollbar { display: none; }
.hl-chip { flex: none; border: 1px solid var(--border); background: var(--white); border-radius: 99px; padding: 5px 11px; font-size: 12.5px; color: var(--muted); font-weight: 500; cursor: pointer; font-family: inherit; }
.hl-chip.is-on { background: var(--brand-dk); border-color: var(--brand-dk); color: #fff; }
.hl-list { overflow-y: auto; max-height: 72vh; padding-bottom: 8px; }
.hl-grp { padding: 12px 16px 4px; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
.hl-item { display: block; padding: 9px 16px 9px 13px; border-left: 3px solid transparent; text-decoration: none; color: var(--text); }
.hl-item:hover { background: var(--bg); }
.hl-item.is-on { background: #e6f1f2; border-left-color: var(--brand); }
.hl-item strong { display: block; font-size: 13.5px; font-weight: 600; }
.hl-item span { font-size: 12px; color: var(--muted); }
.hl-main { padding: 26px 32px; min-width: 0; }
.hl-guide { max-width: 720px; display: grid; gap: 14px; }
.hl-guide[hidden] { display: none; }
.hl-guide .help-guide__meta { margin: 0; }
.hl-guide h2 { margin: 0; font-size: 24px; line-height: 1.25; }
.hl-guide__sum { margin: 0; color: var(--muted); font-size: 15px; }
.hl-guide .help-steps { font-size: 15px; line-height: 1.6; margin: 4px 0 0; }
.hl-guide .help-tips { margin: 0; }
.hl-back { display: none; text-decoration: none; font-size: 13.5px; color: var(--brand); font-weight: 600; }
.hl-none { padding: 16px; color: var(--muted); font-size: 13.5px; }
@media (max-width: 820px) {
  .hl-shell { grid-template-columns: minmax(0, 1fr); min-height: 0; }
  .hl-side { border-right: 0; }
  .hl-list { max-height: none; }
  .hl-shell.is-reading .hl-side { display: none; }
  .hl-shell:not(.is-reading) .hl-main { display: none; }
  .hl-main { padding: 18px 16px 22px; }
  .hl-back { display: inline-block; }
  .hl-guide h2 { font-size: 20px; }
}
</style>

<div class="hl-head">
  <h1>How can we help<?= $first !== '' ? ', ' . e($first) : '' ?>?</h1>
</div>
<?php if ($slug !== '' && !$open): ?><div class="alert alert--info" style="margin-bottom:14px">That guide isn’t available for your account.</div><?php endif; ?>

<?php if (!$guides): ?>
  <p class="help-empty">There are no guides for the pages you can open yet.</p>
<?php else: ?>
<div class="hl-shell<?= $open ? ' is-reading' : '' ?>" id="hlShell">
  <aside class="hl-side">
    <div class="hl-side__top">
      <input type="search" class="inp" id="hlSearch" placeholder="Search guides, e.g. convert an enquiry to a hold" autocomplete="off" aria-label="Search guides">
      <?php if (count($byArea) > 1): ?>
      <div class="hl-chips" role="group" aria-label="Filter by area">
        <button type="button" class="hl-chip is-on" data-hl-chip="">All</button>
        <?php foreach (HELP_AREAS as $key => $label): if (empty($byArea[$key])) continue; ?>
        <button type="button" class="hl-chip" data-hl-chip="<?= e($key) ?>"><?= e($label) ?></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <nav class="hl-list" aria-label="Guides">
      <?php foreach (HELP_AREAS as $key => $label): if (empty($byArea[$key])) continue; ?>
      <div data-hl-area="<?= e($key) ?>">
        <div class="hl-grp"><?= e($label) ?></div>
        <?php foreach ($byArea[$key] as $g): ?>
        <a class="hl-item<?= $current && $g['slug'] === $current['slug'] ? ' is-on' : '' ?>" href="/admin/help.php?g=<?= e(rawurlencode($g['slug'])) ?>"
           data-hl-slug="<?= e($g['slug']) ?>" data-hl-text="<?= e(strtolower($g['title'] . ' ' . $g['summary'] . ' ' . $label)) ?>">
          <strong><?= e($g['title']) ?></strong><span><?= (int)$g['minutes'] ?> min · <?= count($g['steps']) ?> steps</span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
      <p class="hl-none" id="hlNone" hidden>No guide matches your search. Try a page name, like Calendar or Rates.</p>
    </nav>
  </aside>

  <div class="hl-main">
    <a href="/admin/help.php" class="hl-back" id="hlBack">‹ All guides</a>
    <?php foreach ($guides as $g):
      $startPage = null;
      foreach ($g['pages'] as $p) if ($p !== 'help.php' && isset($visiblePages[$p])) { $startPage = $p; break; } ?>
    <article class="hl-guide" data-hl-guide="<?= e($g['slug']) ?>"<?= $current && $g['slug'] === $current['slug'] ? '' : ' hidden' ?>>
      <div class="help-guide__meta"><?= e(HELP_AREAS[$g['area']] ?? '') ?> · <?= (int)$g['minutes'] ?> min · <?= count($g['steps']) ?> steps</div>
      <h2><?= e($g['title']) ?></h2>
      <p class="hl-guide__sum"><?= e($g['summary']) ?></p>
      <?php if ($startPage): ?>
      <div class="help-actions">
        <a class="btn-primary btn-sm" href="/admin/<?= e($startPage) ?>" data-help-tour-link="<?= e($g['slug']) ?>">Open the page and show me</a>
      </div>
      <?php endif; ?>
      <ol class="help-steps">
        <?php foreach ($g['steps'] as $s): ?><li><span><?= help_rich($s['text']) ?></span></li><?php endforeach; ?>
      </ol>
      <?php if (!empty($g['tips'])): ?>
      <div class="help-tips"><?php foreach ($g['tips'] as $t): ?><p><?= help_rich($t) ?></p><?php endforeach; ?></div>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function () {
  var shell = document.getElementById('hlShell');
  if (!shell || shell.dataset.bound) return; shell.dataset.bound = '1';
  var q = document.getElementById('hlSearch'), area = '';

  function filter() {
    var words = q.value.toLowerCase().split(/\s+/).filter(Boolean), any = false;
    shell.querySelectorAll('[data-hl-area]').forEach(function (sec) {
      var shown = 0, inArea = !area || sec.getAttribute('data-hl-area') === area;
      sec.querySelectorAll('[data-hl-text]').forEach(function (c) {
        var hay = c.getAttribute('data-hl-text'), ok = inArea && words.every(function (w) { return hay.indexOf(w) !== -1; });
        c.hidden = !ok; if (ok) shown++;
      });
      sec.hidden = !shown; if (shown) any = true;
    });
    document.getElementById('hlNone').hidden = any;
  }
  q.addEventListener('input', filter);
  shell.querySelectorAll('[data-hl-chip]').forEach(function (b) {
    b.addEventListener('click', function () {
      area = b.getAttribute('data-hl-chip');
      shell.querySelectorAll('[data-hl-chip]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
      filter();
    });
  });

  // Show a guide in place — no page load. The URL follows (?g=) so a refresh or a
  // shared link opens the same guide; replaceState keeps Back meaning "leave Help".
  function show(slug, url) {
    shell.querySelectorAll('[data-hl-guide]').forEach(function (a) { a.hidden = a.getAttribute('data-hl-guide') !== slug; });
    shell.querySelectorAll('[data-hl-slug]').forEach(function (a) { a.classList.toggle('is-on', a.getAttribute('data-hl-slug') === slug); });
    shell.classList.add('is-reading');
    shell.querySelector('.hl-main').scrollTop = 0;
    if (window.matchMedia('(max-width: 820px)').matches) shell.scrollIntoView({ block: 'start' });
    try { history.replaceState(window.tsShellState ? window.tsShellState({}) : {}, '', url); } catch (x) {}
  }
  shell.querySelectorAll('[data-hl-slug]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      e.preventDefault();   // the admin shell skips prevented clicks
      show(a.getAttribute('data-hl-slug'), a.getAttribute('href'));
    });
  });
  document.getElementById('hlBack').addEventListener('click', function (e) {
    e.preventDefault();
    shell.classList.remove('is-reading');
    try { history.replaceState(window.tsShellState ? window.tsShellState({}) : {}, '', '/admin/help.php'); } catch (x) {}
  });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
