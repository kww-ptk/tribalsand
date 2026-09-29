<?php
declare(strict_types=1);
/**
 * Admin: Quote builder — quote any mix of rooms across properties, activities,
 * transfers and custom lines, in KES or USD. READ-ONLY: nothing is saved, held
 * or sent (see includes/quote-builder.php). Same audience as the Bookings menu.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/quote-builder.php';

require_bookings();

$pageTitle  = 'Quote builder';
$activeMenu = 'quote_builder';
include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Quote builder</h1>
  <a class="btn-outline btn-sm" href="/admin/rates.php" data-keep-cur>Rates <?= admin_icon('chevron-right', 14) ?></a>
</div>
<?php
$qb_context = 'page';
$qb_prefill = [];
$qb_cur     = in_array($_GET['cur'] ?? '', ['KES', 'USD'], true) ? (string)$_GET['cur'] : 'KES';
include __DIR__ . '/../includes/quote-builder-view.php';
?>
<?php include __DIR__ . '/_layout_end.php'; ?>
