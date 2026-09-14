#!/usr/bin/env php
<?php
/**
 * Pre-fill each property's operational stay facts (WiFi/TV, board, staff/nanny
 * policy, security hours, age policy) into the editable venue stay-info fields,
 * so they show on the site's stay pages and feed the RAG layer after a reindex.
 *
 * Source: the Internal Property Sales Book. Writes `stay_wifi` and
 * `stay_house_rules` per venue.
 *
 * NON-DESTRUCTIVE: only fills a field that is currently NULL/blank — it never
 * overwrites owner-edited content (a field already set is reported and skipped).
 * SAFE BY DEFAULT: dry-run unless --apply. Pre-migration-safe (skips if the
 * columns are absent). Idempotent.
 *
 * Run on PROD via an ECS run-task command override:
 *     php bin/seed-stay-facts.php            # preview
 *     php bin/seed-stay-facts.php --apply    # write (blank fields only)
 *
 * NOTE: these facts reach the AI via RAG (needs an embeddings key + a reindex).
 * For the AI to use them immediately without RAG, also paste the same facts into
 * Admin → AI settings → Extra knowledge (ai_extra_knowledge).
 */
declare(strict_types=1);
chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/db.php';

$apply = in_array('--apply', $argv, true);

// venue slug => [stay_wifi, stay_house_rules]
$FACTS = [
    'zuri' => [
        'WiFi throughout the property. No televisions in the rooms.',
        'Non-smoking property. Board: bed & breakfast, with an à la carte menu for other meals and bar service. A baby cot and high chair are available on request. Private staff, nanny/babysitter and driver accommodation available at extra cost (room only). Dedicated massage area on site. Day use available from 1 July 2026.',
    ],
    'maya-kobe' => [
        'WiFi throughout. No televisions in the rooms.',
        'Non-smoking property. Board: bed & breakfast, à la carte for other meals and bar. Cot and high chair on request. Staff and nanny/driver accommodation at extra cost. Part of Tribal Dunes — walking distance to Tribal Table, Somewhere Café and the kite school.',
    ],
    'my-amani' => [
        'Basic WiFi with limited coverage and speed. No televisions.',
        'Non-smoking property. Board: self-catering with a dedicated chef and on-site support staff included. Cot and high chair on request. No accommodation for nannies or private staff. Whole-villa exclusive use.',
    ],
    'enkare-bofa' => [
        'Basic WiFi with limited coverage and speed. No televisions.',
        'Non-smoking property. Board: self-catering with an in-house cook. Daily housekeeping and a pool attendant/gardener; security staff on site 6pm–5am. Cot and high chair on request. No nanny/private-staff accommodation. Whole-villa exclusive use.',
    ],
    'sandbox' => [
        'Basic WiFi with limited coverage and speed. No televisions.',
        'Non-smoking property. Board: self-catering (no chef; a cook can be arranged at extra cost, subject to availability). Daily housekeeping and a pool attendant/gardener; security 6pm–5am. Cot and high chair on request. No nanny/private-staff accommodation. Whole-villa exclusive use.',
    ],
    'maya_ilai' => [
        '',   // WiFi not specified in the source — leave for the owner
        'Adults-only property: guests must be 16 or older. Guests aged 16–17 may stay without a parent present, but a parent or legal guardian must sign a consent form at check-in to authorise alcohol consumption. Fully solar-powered with a desalinated water system. Communal pool, bars and gardens; electric bikes and golf carts on site.',
    ],
];

/** True if the venues column exists. */
function venues_has_column(string $col): bool {
    try {
        return (bool) db_query(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'venues' AND column_name = :c LIMIT 1",
            [':c' => $col]
        )->fetchColumn();
    } catch (\Throwable $e) { return false; }
}

if (!venues_has_column('stay_wifi') || !venues_has_column('stay_house_rules')) {
    fwrite(STDERR, "The venue stay-info columns are missing — run db/migrations/add_venue_stay_info.sql first.\n");
    exit(1);
}

echo "Pre-fill venue stay facts (blank fields only)  (" . date('Y-m-d H:i:s') . ")\n";
echo $apply ? "MODE: APPLY\n" : "MODE: DRY-RUN (no changes — pass --apply)\n";
echo str_repeat('=', 92) . "\n";

if ($apply) db()->beginTransaction();
try {
    $set = 0; $skipped = 0; $missing = 0;
    foreach ($FACTS as $slug => [$wifi, $rules]) {
        $v = db_query('SELECT id, stay_wifi, stay_house_rules FROM venues WHERE slug = :s', [':s' => $slug])->fetch();
        if (!$v) { echo "  ! {$slug}: venue not found — skipped\n"; $missing++; continue; }

        foreach ([['stay_wifi', $wifi], ['stay_house_rules', $rules]] as [$col, $val]) {
            if ($val === '') continue;                       // nothing to seed for this field
            $cur = trim((string)($v[$col] ?? ''));
            if ($cur !== '') { echo "  · {$slug}.{$col}: already set — skipped\n"; $skipped++; continue; }
            echo "  • {$slug}.{$col}: fill" . ($apply ? '  [set]' : '  [would set]') . "\n";
            if ($apply) db_query("UPDATE venues SET {$col} = :val, updated_at = NOW() WHERE id = :id", [':val' => $val, ':id' => (int)$v['id']]);
            $set++;
        }
    }
    if ($apply) db()->commit();
    echo str_repeat('=', 92) . "\n";
    echo ($apply ? "APPLIED. " : "DRY-RUN. ") . "Fields " . ($apply ? 'set' : 'to set') . ": {$set}; already-set skipped: {$skipped}" . ($missing ? "; venues missing: {$missing}" : '') . ".\n";
    echo "Run bin/reindex-content.php afterwards so RAG picks these up (needs an embeddings key).\n";
} catch (\Throwable $ex) {
    if ($apply && db()->inTransaction()) db()->rollBack();
    fwrite(STDERR, "\nFAILED (rolled back): " . $ex->getMessage() . "\n");
    exit(1);
}
