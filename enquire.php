<?php
require_once 'includes/schema.php';
require_once 'includes/db.php';

/* ── SEO ── */
$page_title  = 'Enquire · Plan Your Stay · Tribal Sand';
$page_desc   = 'Send an enquiry to Tribal Sand — tell us your dates and group size and we\'ll reply within 24 hours with availability and a tailored quote for our Kenya coast villas.';
$page_url    = 'https://tribalsand.com/enquire.php';
$page_image  = 'https://tribalsand.com/images/hero-maya-kobe.jpg';
$page_schema = ts_schema_org() . ts_schema_breadcrumb([
    ['name' => 'Home',    'url' => 'https://tribalsand.com/'],
    ['name' => 'Enquire', 'url' => 'https://tribalsand.com/enquire.php'],
]);

/* Optional: pre-target a villa via ?villa=<slug> (falls back to a general enquiry) */
$enq_room_slug = '';
$enq_room_name = '';
$__vslug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_GET['villa'] ?? '')));
if ($__vslug) {
    try {
        $__r = fetch_room_by_slug($__vslug);
        if ($__r) { $enq_room_slug = $__r['slug']; $enq_room_name = $__r['name']; }
    } catch (Throwable $e) { /* general enquiry */ }
}
if ($enq_room_name) {
    $enq_heading = 'Enquire About Your Stay';
    $enq_intro   = 'Tell us your dates and we’ll reply within 24 hours with availability and a tailored quote for';
}

include 'includes/head.php';
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
