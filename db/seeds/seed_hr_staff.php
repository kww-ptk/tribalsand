<?php
/**
 * Seed the internal team directory (hr_staff) from the workforce roster.
 *
 * Idempotent: upserts by (full_name, unit_label) so re-running updates in place
 * and never duplicates or clobbers manual edits / login-account links. Source
 * data is db/seeds/hr_roster.json (73 people, extracted from the HR attendance
 * tool: name / position / weekly off-day / property unit).
 *
 *   php db/seeds/seed_hr_staff.php            # upsert all rows
 *   php db/seeds/seed_hr_staff.php --dry-run  # report only, no writes
 *
 * NOTE: local .env is NOT prod — run against prod RDS separately after the
 * add_hr_staff migration.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/hr.php';

$dry = in_array('--dry-run', $argv, true);

if (!hr_staff_supported()) {
    fwrite(STDERR, "hr_staff table missing — run db/migrations/add_hr_staff.sql first.\n");
    exit(1);
}

// Roster unit label → venue slug. Units with no matching venue (office, kite
// school, restaurant, individual plots) map to null and keep their label.
const HR_UNIT_TO_SLUG = [
    'MK'                   => 'maya-kobe',
    'ZURI - WATAMU'        => 'zuri',
    'MAYA ILAI'            => 'maya_ilai',
    'ENKARE BOFA - KILIFI' => 'enkare-bofa',
    'SANDBOX - KILIFI'     => 'sandbox',
    'MY AMANI - VIPINGO'   => 'my-amani',
    // No dedicated venue row → null venue_id, unit_label preserved:
    // 'TRIBAL TABLE', 'Watamu - Plot 6', 'Watamu - Plot 22',
    // 'Tribal Sand Center (office)', 'Tribalsand ltd kite school'
];

$roster = json_decode((string) file_get_contents(__DIR__ . '/hr_roster.json'), true);
if (!is_array($roster)) { fwrite(STDERR, "Could not read hr_roster.json\n"); exit(1); }

// Resolve slugs → venue ids once.
$slugToId = [];
foreach (db_query('SELECT id, slug FROM venues')->fetchAll() as $v) { $slugToId[$v['slug']] = (int)$v['id']; }

$ins = 0; $upd = 0; $order = 0;
foreach ($roster as $r) {
    $name = trim((string)($r['name'] ?? ''));
    if ($name === '') continue;
    $order += 10;
    $unit = trim((string)($r['unit'] ?? ''));
    $slug = HR_UNIT_TO_SLUG[$unit] ?? null;
    $venueId = $slug !== null ? ($slugToId[$slug] ?? null) : null;
    $dept = hr_department($r['position'] ?? '');
    $off  = strtoupper(trim((string)($r['off_day'] ?? '')));

    $existing = db_query(
        "SELECT id FROM hr_staff WHERE full_name = :n AND COALESCE(unit_label,'') = :u",
        [':n' => $name, ':u' => $unit]
    )->fetchColumn();

    if ($dry) { $existing ? $upd++ : $ins++; continue; }

    if ($existing) {
        db_query(
            "UPDATE hr_staff SET position = :p, department = :d, venue_id = :v, unit_label = :u,
                    off_day = :o, sort_order = :so WHERE id = :id",
            [':p' => $r['position'] ?? '', ':d' => $dept, ':v' => $venueId, ':u' => $unit,
             ':o' => $off, ':so' => $order, ':id' => (int)$existing]
        );
        $upd++;
    } else {
        db_query(
            "INSERT INTO hr_staff (full_name, position, department, venue_id, unit_label, off_day, status, sort_order)
             VALUES (:n, :p, :d, :v, :u, :o, 'active', :so)",
            [':n' => $name, ':p' => $r['position'] ?? '', ':d' => $dept, ':v' => $venueId,
             ':u' => $unit, ':o' => $off, ':so' => $order]
        );
        $ins++;
    }
}

printf("%s: %d inserted, %d updated (%d roster rows).\n", $dry ? 'DRY-RUN' : 'Seeded', $ins, $upd, count($roster));
