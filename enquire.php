<?php
require_once 'includes/schema.php';
require_once 'includes/db.php';

/* ── SEO ── */
$page_title  = 'Enquire · Plan Your Stay · Tribal Sand';
$page_desc   = 'Send an enquiry to Tribal Sand — share your dates and group size and we\'ll reply within 24 hours with availability and a tailored Kenya coast quote.';
$page_url    = 'https://tribalsand.com/enquire.php';
$page_image  = asset_url('images/hero-maya-kobe.jpg');
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name' => 'Home',    'url' => 'https://tribalsand.com/'],
    ['name' => 'Enquire', 'url' => 'https://tribalsand.com/enquire.php'],
]);

/* Optional: pre-target a villa via ?villa=<slug> or a tour via ?tour=<slug>
   (falls back to a general enquiry if neither resolves). */
$enq_room_slug = '';
$enq_room_name = '';
$enq_tour_slug = '';
$enq_tour_name = '';

$__vslug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_GET['villa'] ?? '')));
if ($__vslug) {
    try {
        $__r = fetch_room_by_slug($__vslug);
        if ($__r) { $enq_room_slug = $__r['slug']; $enq_room_name = $__r['name']; }
    } catch (Throwable $e) { /* general enquiry */ }
}

$__tslug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_GET['tour'] ?? '')));
if (!$enq_room_slug && $__tslug) {
    try {
        $__t = fetch_tour_by_slug($__tslug);
        if ($__t) { $enq_tour_slug = $__t['slug']; $enq_tour_name = $__t['name']; }
    } catch (Throwable $e) { /* general enquiry */ }
}

if ($enq_room_name) {
    $enq_heading = 'Enquire About Your Stay';
    $enq_intro   = 'Tell us your dates and we’ll reply within 24 hours with availability and a tailored quote for';
} elseif ($enq_tour_name) {
    $enq_heading = 'Enquire About This Experience';
    $enq_intro   = 'Tell us your preferred dates and group size and we’ll reply within 24 hours to arrange';
}

include 'includes/head.php';
?>
<!-- Reusable styled datepicker (dp-btn) for the enquiry form's dates -->
<link rel="stylesheet" href="css/booking.css?v=<?= filemtime(__DIR__ . '/css/booking.css') ?>">
<script src="js/datepicker.js?v=<?= filemtime(__DIR__ . '/js/datepicker.js') ?>" defer></script>
<?php
include 'includes/header.php';
?>
<style>
.enq-page{background:#FAF8F4;padding:120px 0 5rem;min-height:70vh;}
@media(max-width:600px){.enq-page{padding-top:96px;}}
</style>

<div class="enq-page">
  <?php include __DIR__ . '/includes/enquiry-multistep.php'; ?>
</div>

<?php include 'includes/footer.php'; ?>
