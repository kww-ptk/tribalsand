<?php
declare(strict_types=1);
/**
 * HR directory — multi-property scope (Item 5). Run: php tests/hr_logic.php
 *
 * Pure scope-SQL generation and the single-boundary scope check are asserted
 * directly. A DB round-trip (a person with a home + a second property, seen by a
 * manager of EITHER, hidden from a manager of neither) runs inside ONE
 * transaction that is always rolled back. Needs add_hr_staff + add_hr_staff_venues.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/hr.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Scope SQL generation (pure — no join table needed) ──────────────────────
$sql = ''; $p = []; hr_scope_sql(null, $sql, $p);
check('scope: owner (null) is unscoped', $sql === '' && $p === []);

$sql = ''; $p = []; hr_scope_sql([], $sql, $p);
check('scope: assigned nowhere matches nothing', str_contains($sql, '= -1'));

$sql = ''; $p = []; hr_scope_sql([3, 5], $sql, $p);
check('scope: bare column when no alias', str_contains($sql, 'venue_id IN (:sv0,:sv1)') && !str_contains($sql, '.venue_id'));
check('scope: params carry the venue ids', ($p[':sv0'] ?? null) === 3 && ($p[':sv1'] ?? null) === 5);

$sql = ''; $p = []; hr_scope_sql([3], $sql, $p, 's');
check('scope: aliased column when alias given', str_contains($sql, 's.venue_id IN (:sv0)'));

// ── Single-boundary scope check (pure) ──────────────────────────────────────
check('in-scope: owner sees everyone', hr_staff_in_venue_scope(0, null, null) === true);
check('in-scope: home venue in set → visible', hr_staff_in_venue_scope(0, 3, [3, 5]) === true);
check('in-scope: home venue not in set, no extras → hidden', hr_staff_in_venue_scope(-999, 9, [3, 5]) === false);

// ── DB round-trip (rolled back) ─────────────────────────────────────────────
if (!hr_staff_supported()) {
    echo "\nSKIP  hr_staff table absent — run add_hr_staff.sql\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}
if (!hr_staff_venues_supported()) {
    echo "\nSKIP  hr_staff_venues table absent — run add_hr_staff_venues.sql\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}
try { db()->beginTransaction(); }
catch (Throwable $e) {
    echo "\nSKIP  DB unavailable: " . $e->getMessage() . "\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}
try {
    $vids = array_map('intval', array_column(db_query('SELECT id FROM venues ORDER BY id LIMIT 3')->fetchAll(), 'id'));
    if (count($vids) < 3) {
        echo "SKIP  need at least 3 venues for the scope round-trip\n";
    } else {
        [$home, $extra, $other] = $vids;
        db_query("INSERT INTO hr_staff (full_name, department, venue_id, status) VALUES ('ZZ Multi Venue', 'Housekeeping', :v, 'active')", [':v' => $home]);
        $sid = (int) db()->lastInsertId();

        // Assign a second property; the home venue must be filtered out if posted.
        hr_set_staff_venues($sid, [$extra, $home], $home, $vids);
        check('venues: extra property is stored', hr_staff_venue_ids($sid) === [$extra]);
        check('venues: the home venue is never stored as an extra', !in_array($home, hr_staff_venue_ids($sid), true));

        // Visible to a manager of the HOME property.
        $seenHome  = array_column(fetch_hr_staff([$home]), 'id');
        check('scope: the home-property manager sees them', in_array($sid, array_map('intval', $seenHome), true));
        // Visible to a manager of the SECOND property (the widening).
        $seenExtra = array_column(fetch_hr_staff([$extra]), 'id');
        check('scope: the second-property manager now sees them', in_array($sid, array_map('intval', $seenExtra), true));
        // NOT visible to a manager of an unrelated property (the security boundary).
        $seenOther = array_column(fetch_hr_staff([$other]), 'id');
        check('scope: an unrelated manager does NOT see them', !in_array($sid, array_map('intval', $seenOther), true));

        // The single-boundary helper agrees with the SQL scope.
        check('in-scope: helper agrees for the extra venue', hr_staff_in_venue_scope($sid, $home, [$extra]) === true);
        check('in-scope: helper agrees for an unrelated venue', hr_staff_in_venue_scope($sid, $home, [$other]) === false);

        // Removing the extra hides them again from the second-property manager.
        hr_set_staff_venues($sid, [], $home, $vids);
        check('venues: cleared', hr_staff_venue_ids($sid) === []);
        check('scope: after clearing, the second-property manager no longer sees them',
            !in_array($sid, array_map('intval', array_column(fetch_hr_staff([$extra]), 'id')), true));
    }
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
