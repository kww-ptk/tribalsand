<?php
/**
 * "Find anything" search bar — a real GET form to /site-search (works with
 * JS off) that js/site-search.js upgrades into live suggestions under the box.
 * Include on a page; set $ssb_title / $ssb_sub / $ssb_class first to change
 * the copy and $ssb_value to pre-fill the box (escaped here). Assets come from
 * includes/header.php (loaded on every page).
 */
$ssb_title = $ssb_title ?? 'What are you looking for?';
$ssb_sub   = $ssb_sub   ?? 'Search rooms, villas, restaurants, activities, events and guides.';
$ssb_class = $ssb_class ?? '';
$ssb_chips = $ssb_chips ?? ['Beachfront villa', 'Restaurants', 'Activities', 'Weddings', 'Kilifi', 'Watamu', 'Airport transfer'];
?>
<section class="ssbar <?= e($ssb_class) ?>" aria-label="Search the website">
  <div class="ssbar__inner">
    <?php if ($ssb_title !== ''): ?><h2 class="ssbar__title"><?= e($ssb_title) ?></h2><?php endif; ?>
    <?php if ($ssb_sub !== ''): ?><p class="ssbar__sub"><?= e($ssb_sub) ?></p><?php endif; ?>
    <form class="ssbar__form" action="/site-search" method="GET" role="search" data-ss-box>
      <label class="ss-visually-hidden" for="ssbar-q-<?= $__ssbN = ($GLOBALS['__ssb_n'] = ($GLOBALS['__ssb_n'] ?? 0) + 1) ?>">Search the website</label>
      <span class="ssbar__icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span>
      <input id="ssbar-q-<?= $__ssbN ?>" class="ssbar__input" type="search" name="q" placeholder="Try “sea view room”, “dinner in Watamu” or “kitesurfing”" value="<?= e($ssb_value ?? '') ?>" autocomplete="off" maxlength="80" data-ss-input>
      <button type="submit" class="ssbar__btn">Search</button>
      <div class="ss-results ssbar__results" data-ss-results hidden></div>
    </form>
    <?php if ($ssb_chips): ?>
    <div class="ssbar__chips" aria-label="Popular searches">
      <span class="ssbar__chips-lbl">Popular:</span>
      <?php foreach ($ssb_chips as $c): ?>
        <a class="ssbar__chip" href="/site-search?q=<?= e(rawurlencode($c)) ?>" data-ss-chip="<?= e($c) ?>"><?= e($c) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>
