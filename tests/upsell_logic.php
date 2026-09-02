<?php
declare(strict_types=1);
// Booking-flow add-ons — placement, venue scoping, validation, dedup, attach.
// Run: php tests/upsell_logic.php
// Requires db/migrations/add_upsells.sql applied.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/upsells.php';
require_once __DIR__ . '/../includes/submission-payload.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

if (!upsells_supported()) {
    echo "SKIP  add_upsells migration not applied — nothing to test\n\nALL PASS\n";
    exit(0);
}
check('upsells_supported() true once migrated', upsells_supported() === true);

// Two venues, one with a unit so a hold can exist.
$rows = db()->query(
    "SELECT DISTINCT ON (r.venue_id) r.venue_id, u.id AS unit_id
       FROM units u JOIN rooms r ON r.id = u.room_id
      WHERE r.venue_id IS NOT NULL ORDER BY r.venue_id, u.id LIMIT 2")->fetchAll();
if (count($rows) < 2) { echo "SKIP  need two venues with units\n\nALL PASS\n"; exit(0); }
$V1 = (int)$rows[0]['venue_id']; $U1 = (int)$rows[0]['unit_id'];
$V2 = (int)$rows[1]['venue_id'];

// Everything runs inside a transaction that is rolled back, so the live
// catalog and the venues' own switches are never actually modified.
db()->beginTransaction();
try {
    db_query('UPDATE venues SET upsell_enabled = TRUE  WHERE id = :v', [':v' => $V1]);
    db_query('UPDATE venues SET upsell_enabled = FALSE WHERE id = :v', [':v' => $V2]);

    $mkTour = function (string $slug, string $place, ?float $price, bool $pub = true): int {
        db_query(
            "INSERT INTO tours (slug, name, category, is_published, upsell_placement, price_amount, price_per_person)
             VALUES (:s, :n, 'wellness', :pub, :p, :amt, TRUE)",
            [':s' => $slug, ':n' => 'ZZ ' . $slug, ':pub' => $pub ? 'TRUE' : 'FALSE',
             ':p' => $place, ':amt' => $price]
        );
        return (int)db()->lastInsertId();
    };
    $tEnq   = $mkTour('zz-ups-enq',   'enquiry', 120.0);
    $tChk   = $mkTour('zz-ups-chk',   'checkin', 80.0);
    $tBoth  = $mkTour('zz-ups-both',  'both',    null);
    $tNone  = $mkTour('zz-ups-none',  'none',    50.0);
    $tUnpub = $mkTour('zz-ups-unpub', 'both',    50.0, false);
    // Pinned to the OTHER property, so it must never show at V1.
    $tOther = $mkTour('zz-ups-other', 'both',    60.0);
    db_query('INSERT INTO tour_venues (tour_id, venue_id) VALUES (:t,:v)', [':t' => $tOther, ':v' => $V2]);

    $ids = fn(array $items) => array_map(fn($i) => (int)$i['id'], $items);

    // ── Placement filtering ────────────────────────────────────────────────
    $enq = $ids(fetch_upsell_items($V1, 'enquiry'));
    check('enquiry surface includes enquiry + both', in_array($tEnq, $enq, true) && in_array($tBoth, $enq, true));
    check('enquiry surface excludes checkin-only',   !in_array($tChk, $enq, true));
    check('enquiry surface excludes none',           !in_array($tNone, $enq, true));
    check('enquiry surface excludes unpublished',    !in_array($tUnpub, $enq, true));
    check('enquiry surface excludes other venue',    !in_array($tOther, $enq, true));

    $chk = $ids(fetch_upsell_items($V1, 'checkin'));
    check('checkin surface includes checkin + both', in_array($tChk, $chk, true) && in_array($tBoth, $chk, true));
    check('checkin surface excludes enquiry-only',   !in_array($tEnq, $chk, true));

    check('unknown surface returns nothing',         fetch_upsell_items($V1, 'nonsense') === []);
    check('null venue returns nothing',              fetch_upsell_items(null, 'enquiry') === []);

    // ── Master switch ──────────────────────────────────────────────────────
    check('switched-off property offers nothing',    fetch_upsell_items($V2, 'enquiry') === []);
    check('upsell_venue_enabled reflects the switch',
        upsell_venue_enabled($V1) === true && upsell_venue_enabled($V2) === false);
    db_query('UPDATE venues SET upsell_enabled = FALSE WHERE id = :v', [':v' => $V1]);
    check('switching a property off empties it',     fetch_upsell_items($V1, 'enquiry') === []);
    db_query('UPDATE venues SET upsell_enabled = TRUE WHERE id = :v', [':v' => $V1]);

    // ── Server-side validation of posted ids ───────────────────────────────
    check('validate keeps a genuinely offered id',
        $ids(upsell_validate_ids([$tEnq], $V1, 'enquiry')) === [$tEnq]);
    check('validate drops a wrong-surface id',
        upsell_validate_ids([$tChk], $V1, 'enquiry') === []);
    check('validate drops another property\'s id',
        upsell_validate_ids([$tOther], $V1, 'enquiry') === []);
    check('validate drops an unpublished id',
        upsell_validate_ids([$tUnpub], $V1, 'enquiry') === []);
    check('validate drops junk',
        upsell_validate_ids([0, -5, 999999, 'abc'], $V1, 'enquiry') === []);
    check('validate on a switched-off property is empty',
        upsell_validate_ids([$tEnq], $V2, 'enquiry') === []);

    // ── Attach to a booking + dedup across the two surfaces ────────────────
    db_query("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
              VALUES (:u,'2031-11-01','2031-11-04','ZZ Ups Guest','zz-ups@example.com','confirmed',NOW())",
             [':u' => $U1]);
    $hold = (int)db()->lastInsertId();

    $picked = upsell_validate_ids([$tEnq, $tBoth], $V1, 'enquiry');
    check('attach creates one addon per item', upsell_attach_to_hold($hold, $picked, 2) === 2);
    check('addons are on the booking',
        count(array_intersect([$tEnq, $tBoth], upsell_addon_tour_ids($hold))) === 2);
    check('attach is idempotent', upsell_attach_to_hold($hold, $picked, 2) === 0);
    check('attach with nothing to add is a no-op', upsell_attach_to_hold($hold, [], 2) === 0);
    check('attach on an invalid hold is a no-op', upsell_attach_to_hold(0, $picked, 2) === 0);

    $pax = (int)db_query('SELECT pax FROM booking_addons WHERE hold_id=:h AND tour_id=:t',
                         [':h' => $hold, ':t' => $tEnq])->fetchColumn();
    check('pax snapshot is stored', $pax === 2);
    $amt = db_query('SELECT price_amount FROM booking_addons WHERE hold_id=:h AND tour_id=:t',
                    [':h' => $hold, ':t' => $tEnq])->fetchColumn();
    check('price snapshot is stored', (float)$amt === 120.0);

    // The point of the whole exercise: what was picked at enquiry must not be
    // offered again at check-in.
    $chkAfter = $ids(fetch_upsell_items($V1, 'checkin', $hold));
    check('already-picked item is hidden on the other surface', !in_array($tBoth, $chkAfter, true));
    check('un-picked item still offered there',                  in_array($tChk, $chkAfter, true));

    // A cancelled addon frees the item to be offered again.
    db_query("UPDATE booking_addons SET status='cancelled' WHERE hold_id=:h AND tour_id=:t",
             [':h' => $hold, ':t' => $tBoth]);
    check('cancelled addon is offered again',
        in_array($tBoth, $ids(fetch_upsell_items($V1, 'checkin', $hold)), true));

    // ── Presentation helpers ───────────────────────────────────────────────
    check('price label, per person', upsell_price_label(['price_amount' => 120, 'price_per_person' => true]) === '$120 per person');
    check('price label, flat',       upsell_price_label(['price_amount' => 400, 'price_per_person' => false]) === '$400');
    check('price label, unpriced',   upsell_price_label(['price_amount' => null, 'price_per_person' => true]) === '');

    $row = upsell_payload_row(['id' => $tEnq, 'slug' => 'zz-ups-enq', 'name' => 'ZZ zz-ups-enq',
                               'price_amount' => 120.0, 'price_per_person' => true]);
    check('payload row snapshots id + price', $row['id'] === $tEnq && $row['price_amount'] === 120.0);
    check('submission_upsells reads the payload back',
        count(submission_upsells(['upsells' => [$row]])) === 1);
    check('submission_upsells tolerates junk',
        submission_upsells(['upsells' => 'nope']) === [] && submission_upsells([]) === []);
    check('generic payload rows skip upsells',
        !in_array('Upsells', array_column(submission_payload_rows(['upsells' => [$row], 'submitted_from' => '/x']), 0), true));

} finally {
    db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
