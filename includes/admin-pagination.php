<?php
/**
 * Render helpers for the reusable admin data-table toolbar + pager.
 * Pairs with includes/pagination.php (the data side) and admin-table.js (AJAX).
 *
 * Markup contract each list page follows so the AJAX layer can find things:
 *
 *   <div class="dt" data-dt>
 *     <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search…']); ?>
 *     <div class="dt-body" data-dt-body>
 *       ... card + table (with <tbody>) ...
 *       <?php dt_pager($meta); ?>
 *     </div>
 *   </div>
 *
 * On an AJAX request (?ajax=1) the page echoes ONLY the inner of .dt-body and
 * exits; admin-table.js swaps that in. The toolbar stays put (keeps focus).
 *
 * Requires admin_icon() (includes/icons.php) and e() (includes/db.php).
 */
declare(strict_types=1);

if (!function_exists('dt_current_path')) {
    /** The current request path without its query string. Robust for /x and /x.php. */
    function dt_current_path(): string
    {
        return strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
    }
}

if (!function_exists('dt_url')) {
    /**
     * Build a URL from the CURRENT query string, overriding the given keys.
     * A null value removes the key. `ajax` is always stripped from visible links.
     */
    function dt_url(array $overrides): string
    {
        $q = $_GET;
        unset($q['ajax']);
        foreach ($overrides as $k => $v) {
            if ($v === null) unset($q[$k]);
            else $q[$k] = $v;
        }
        // Drop array-valued params (none of our pagers use them) to keep links simple.
        $q = array_filter($q, static fn($v) => !is_array($v));
        $qs = http_build_query($q);
        return dt_current_path() . ($qs !== '' ? '?' . $qs : '');
    }
}

if (!function_exists('dt_toolbar')) {
    /**
     * Search box (Lucide search icon) + a 10/25/50 per-page dropdown (reusable
     * enhanced select). Renders a GET <form> so it works with no JS; admin-table.js
     * intercepts it for the no-reload path.
     *
     * @param array $opts per (int, current page size), placeholder (string),
     *                    per_page (bool, default true — set false on reorderable
     *                    lists that must show every row on one page).
     */
    function dt_toolbar(array $opts = []): void
    {
        $q           = (string)($_GET['q'] ?? '');
        $per         = (int)($opts['per'] ?? 10);
        $placeholder = (string)($opts['placeholder'] ?? 'Search…');
        $showPerPage = ($opts['per_page'] ?? true) !== false;
        $path        = dt_current_path();

        // Preserve every other current param (existing filter dropdowns, etc.)
        // as hidden inputs so a no-JS search submit doesn't drop them.
        $keep = $_GET;
        foreach (['q', 'per', 'page', 'ajax'] as $k) unset($keep[$k]);
        ?>
        <form class="dt-toolbar" method="get" action="<?= e($path) ?>" data-dt-toolbar role="search">
          <?php foreach ($keep as $k => $v): if (is_array($v)) continue; ?>
            <input type="hidden" name="<?= e((string)$k) ?>" value="<?= e((string)$v) ?>">
          <?php endforeach; ?>
          <input type="hidden" name="page" value="1">

          <label class="filter-field dt-search-field">Search
            <span class="dt-search">
              <span class="dt-search__icon" aria-hidden="true"><?= admin_icon('search', 16) ?></span>
              <input type="search" name="q" value="<?= e($q) ?>" class="dt-search__input"
                     placeholder="<?= e($placeholder) ?>" autocomplete="off" aria-label="Search">
              <button type="submit" class="dt-search__go" title="Search" aria-label="Search"><?= admin_icon('search', 15) ?></button>
            </span>
          </label>

          <?php if ($showPerPage): ?>
          <label class="filter-field dt-perpage">Show
            <select name="per" class="filter-select" aria-label="Results per page">
              <?php foreach ([10, 25, 50] as $n): ?>
              <option value="<?= $n ?>" <?= $n === $per ? 'selected' : '' ?>><?= $n ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php endif; ?>
        </form>
        <?php
    }
}

if (!function_exists('dt_empty')) {
    /**
     * One consistent empty-state for every list. Drop it in place of the table
     * (or grid) body when there are zero rows — a centred icon + message that,
     * with the .dt-body min-height, fills a comfortable height above the pager.
     * Replaces the ad-hoc `<p>` / empty `<tr>` each page used to hand-roll.
     *
     * @param string $msg  Line shown to the user.
     * @param string $icon admin_icon() name (defaults to the inbox glyph).
     */
    function dt_empty(string $msg = 'Nothing to show', string $icon = 'inbox'): void
    {
        ?>
        <div class="dt-empty">
          <span class="dt-empty__icon" aria-hidden="true"><?= admin_icon($icon, 30) ?></span>
          <p class="dt-empty__msg"><?= e($msg) ?></p>
        </div>
        <?php
    }
}

if (!function_exists('dt_pager')) {
    /**
     * "Showing X–Y of Z entries" + first / prev / windowed pages / next / last,
     * using Lucide chevrons. Links preserve all current query params.
     *
     * @param array $meta Output of paginate_meta().
     */
    function dt_pager(array $meta): void
    {
        $page  = (int)$meta['page'];
        $pages = (int)$meta['pages'];
        $total = (int)$meta['total'];

        // Windowed page numbers: up to 5 around the current page.
        $win   = 2;
        $start = max(1, $page - $win);
        $end   = min($pages, $page + $win);
        ?>
        <div class="dt-pager-wrap" data-dt-pager>
          <div class="dt-count" aria-live="polite">
            <?php if ($total === 0): ?>
              No entries
            <?php else: ?>
              Showing <strong><?= (int)$meta['from'] ?>&ndash;<?= (int)$meta['to'] ?></strong> of <strong><?= $total ?></strong> <?= $total === 1 ? 'entry' : 'entries' ?>
            <?php endif; ?>
          </div>

          <?php if ($pages > 1): ?>
          <nav class="dt-pager" aria-label="Pagination">
            <?php
              $on_first = $page <= 1;
              $on_last  = $page >= $pages;

              // First + Prev
              if ($on_first) {
                  echo '<span class="dt-page is-disabled" aria-disabled="true">' . admin_icon('chevrons-left', 16) . '</span>';
                  echo '<span class="dt-page is-disabled" aria-disabled="true">' . admin_icon('chevron-left', 16) . '</span>';
              } else {
                  echo '<a class="dt-page" href="' . e(dt_url(['page' => 1])) . '" aria-label="First page">' . admin_icon('chevrons-left', 16) . '</a>';
                  echo '<a class="dt-page" href="' . e(dt_url(['page' => $page - 1])) . '" aria-label="Previous page">' . admin_icon('chevron-left', 16) . '</a>';
              }

              if ($start > 1) echo '<span class="dt-ellipsis">…</span>';

              for ($p = $start; $p <= $end; $p++) {
                  if ($p === $page) {
                      echo '<span class="dt-page is-current" aria-current="page">' . $p . '</span>';
                  } else {
                      echo '<a class="dt-page" href="' . e(dt_url(['page' => $p])) . '">' . $p . '</a>';
                  }
              }

              if ($end < $pages) echo '<span class="dt-ellipsis">…</span>';

              // Next + Last
              if ($on_last) {
                  echo '<span class="dt-page is-disabled" aria-disabled="true">' . admin_icon('chevron-right', 16) . '</span>';
                  echo '<span class="dt-page is-disabled" aria-disabled="true">' . admin_icon('chevrons-right', 16) . '</span>';
              } else {
                  echo '<a class="dt-page" href="' . e(dt_url(['page' => $page + 1])) . '" aria-label="Next page">' . admin_icon('chevron-right', 16) . '</a>';
                  echo '<a class="dt-page" href="' . e(dt_url(['page' => $pages])) . '" aria-label="Last page">' . admin_icon('chevrons-right', 16) . '</a>';
              }
            ?>
          </nav>
          <?php endif; ?>
        </div>
        <?php
    }
}
