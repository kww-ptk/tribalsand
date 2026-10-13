<?php
declare(strict_types=1);
// "Only for booked guests" activities (tours.guests_only, add_tour_guests_only.sql):
// offered to booked guests (portal, check-in, emails), never on the public website.
// Run: php tests/tour_guests_only_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/upsells.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Every public reader of tours goes through tours_public_sql() ──
$root = dirname(__DIR__);
foreach (['activities.php', 'includes/cross-sell-tours.php', 'includes/site-search.php', 'includes/assistant-rag.php'] as $f) {
    check("public reader uses tours_public_sql(): {$f}", str_contains((string)file_get_contents("{$root}/{$f}"), 'tours_public_sql('));
}
check('fetch_tour_by_slug() (public enquiry) is public-only', str_contains((string)file_get_contents("{$root}/includes/db.php"), "is_published = TRUE' . tours_public_sql"));

// ── DB (rolled back) ──
try {
    $db = db();
    if (!tours_guests_only_supported()) { echo "SKIP  DB checks (add_tour_guests_only.sql not applied)\n"; }
    else {
        check('fragment names the alias', tours_public_sql('x') === ' AND NOT x.guests_only');
        $db->beginTransaction();
        $ins = "INSERT INTO tours (name, slug, category, is_published, guests_only) VALUES (:n, :s, 'marine', TRUE, :g) RETURNING id";
        $pub = (int)db_query($ins, [':n' => 'ZZ Public cruise', ':s' => 'zz-public-cruise', ':g' => 'FALSE'])->fetchColumn();
        $gst = (int)db_query($ins, [':n' => 'ZZ Romantic dinner', ':s' => 'zz-romantic-dinner', ':g' => 'TRUE'])->fetchColumn();
        check('public enquiry: a public activity is found', (bool)fetch_tour_by_slug('zz-public-cruise'));
        check('public enquiry: a guests-only activity is not', fetch_tour_by_slug('zz-romantic-dinner') === false);
        $portal = array_column(fetch_portal_activities(null), 'id');
        check('guest portal: both are offered', in_array($pub, $portal, true) && in_array($gst, $portal, true));
        check('booked guest can request it (portal validation)', (bool)fetch_tour_for_booking('zz-romantic-dinner', null));
        $public = array_map('intval', db_query('SELECT id FROM tours t WHERE t.is_published = TRUE' . tours_public_sql('t'))->fetchAll(PDO::FETCH_COLUMN));
        check('public list: guests-only left out', in_array($pub, $public, true) && !in_array($gst, $public, true));
        $db->rollBack();
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo "SKIP  DB checks (" . strtok($e->getMessage(), "\n") . ")\n";
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
