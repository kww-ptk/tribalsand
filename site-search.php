<?php
declare(strict_types=1);
/**
 * /site-search?q=… — full results for "find anything" (the header overlay's
 * "See all results", and the search bar's no-JS fallback). Same index and
 * ranking as api/site-search.php (includes/site-search.php). Not the
 * availability search — that is /search.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/site-search.php';

$q = site_search_clean_query((string)($_GET['q'] ?? ''));
$results = [];
if (mb_strlen($q) >= 2) {
    try { $results = site_search($q, 60); } catch (Throwable $e) { $results = []; }
}
$groups = site_search_group($results);

$page_title  = ($q !== '' ? 'Search: ' . $q . ' · ' : 'Search · ') . 'Tribal Sand';
$page_desc   = 'Search Tribal Sand — rooms, villas, restaurants, activities, events and travel guides on the Kenya coast.';
$page_url    = site_url('site-search');
$page_robots = 'noindex,follow';

include __DIR__ . '/includes/head.php';
include __DIR__ . '/includes/header.php';

$ssb_title = $q !== '' ? '' : 'Search Tribal Sand';
$ssb_sub   = $q !== '' ? '' : 'Rooms, villas, restaurants, activities, events and guides.';
$ssb_class = 'ssbar--page';
$ssb_value = $q;
?>
<main class="sspage">
  <?php include __DIR__ . '/includes/site-search-bar.php'; ?>

  <div class="sspage__body">
    <?php if ($q === ''): ?>
      <p class="sspage__empty">Type what you're looking for above — a property, a room, a restaurant, an activity or a place.</p>
    <?php elseif (mb_strlen($q) < 2): ?>
      <p class="sspage__empty">Please type at least 2 letters.</p>
    <?php elseif (!$results): ?>
      <div class="sspage__none">
        <h1 class="sspage__h1">No results for “<?= e($q) ?>”</h1>
        <p>Try a shorter or different word — for example a property name, “villa”, “restaurant” or a town like “Kilifi”.</p>
        <p class="sspage__alt">Looking for a room for your dates? <a href="/search">Check availability →</a> &nbsp;·&nbsp; Prefer to ask? <a href="/enquire">Send an enquiry →</a></p>
      </div>
    <?php else: ?>
      <h1 class="sspage__h1"><?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?> for “<?= e($q) ?>”</h1>
      <?php foreach ($groups as $g): ?>
        <section class="sspage__group">
          <h2 class="sspage__glabel"><?= e($g['label']) ?></h2>
          <ul class="sspage__list">
            <?php foreach ($g['items'] as $r): ?>
              <li><a class="sspage__item" href="<?= e($r['url']) ?>">
                <span class="sspage__ititle"><?= e($r['title']) ?></span>
                <?php if (($r['sub'] ?? '') !== ''): ?><span class="sspage__isub"><?= e($r['sub']) ?></span><?php endif; ?>
              </a></li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
