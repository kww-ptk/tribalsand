<?php
declare(strict_types=1);
// Calendar blocks never land on a taken night + a block converts to a booking
// only with a guest (admin/gantt.php, includes/gantt-block-guard.php,
// bookings_convert_block_to_hold()). Also: /register's share image is editable.
// Run: php tests/gantt_block_overlap_logic.php
//   Pure checks always; the DB part runs in ONE rolled-back transaction when a DB is reachable.
require_once __DIR__ . '/../includes/gantt-block-guard.php';
require_once __DIR__ . '/../includes/bookings.php';
require_once __DIR__ . '/../includes/page-content.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Taken nights (pure) ──────────────────────────────────────────
$blocks = [
    ['date_from' => '2099-03-01', 'date_to' => '2099-03-04'],   // nights 1, 2, 3
    ['date_from' => '2099-03-03', 'date_to' => '2099-03-05'],   // overlaps: 3, 4
];
check('taken nights: union, sorted, no duplicates',
    gantt_taken_nights($blocks, '2099-02-01', '2099-04-01') === ['2099-03-01', '2099-03-02', '2099-03-03', '2099-03-04']);
check('taken nights: the checkout morning is free',
    !in_array('2099-03-05', gantt_taken_nights($blocks, '2099-02-01', '2099-04-01'), true));
check('taken nights: clipped to the asked window',
    gantt_taken_nights($blocks, '2099-03-02', '2099-03-03') === ['2099-03-02']);
check('taken nights: nothing → empty', gantt_taken_nights([], '2099-01-01', '2099-12-31') === []);

// ── Refusal wording (pure) ───────────────────────────────────────
$m = gantt_overlap_message(['block_type' => 'booked', 'date_from' => '2099-11-02', 'date_to' => '2099-11-05', 'unit_label' => 'Prestige Suite — Unit A']);
check('overlap message names the unit, the kind and the nights',
    str_contains($m, 'Prestige Suite — Unit A') && str_contains($m, 'a booking') && str_contains($m, '2 Nov – 4 Nov 2099'));
check('overlap message: a hold reads as a 24h hold',
    str_contains(gantt_overlap_message(['block_type' => 'hold', 'date_from' => '2099-11-02', 'date_to' => '2099-11-03']), '24h hold'));

// ── A booking needs a guest (pure) ───────────────────────────────
check('convert: no name → refused',  bookings_convert_guest_problem('', 'a@b.co') !== null);
check('convert: no email → refused', bookings_convert_guest_problem('Jo Guest', '') !== null);
check('convert: bad email → refused', bookings_convert_guest_problem('Jo Guest', 'not-an-email') !== null);
check('convert: name + email → allowed', bookings_convert_guest_problem('Jo Guest', 'jo@example.com') === null);

// ── /register share image is in Website content (pure) ───────────
$reg = page_content_registry();
check('register page is editable in Website content', isset($reg['register']) && ($reg['register']['url'] ?? '') === '/register');
check('register page has a Social share image slot', (page_slots('register')['og_image']['type'] ?? '') === 'image');
check('register share image defaults to the site default (empty slot)', (page_slots('register')['og_image']['default'] ?? 'x') === '');

// ── DB round trip ────────────────────────────────────────────────
try {
    $unit = db_query("SELECT u.id FROM units u JOIN rooms r ON r.id = u.room_id
                       WHERE u.is_active = TRUE AND r.is_entire_place = FALSE ORDER BY u.id LIMIT 1")->fetchColumn();
} catch (Throwable $e) { $unit = false; echo "SKIP  DB part: " . $e->getMessage() . "\n"; }

if ($unit) {
    $unit = (int)$unit;
    db()->beginTransaction();
    try {
        db_query("INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, notes)
                  VALUES (:u, '2099-03-01', '2099-03-05', 'booked', 'Maintenance')", [':u' => $unit]);
        $bid = (int) db()->lastInsertId();

        check('db: a block over a booked night is refused',
            gantt_block_overlap_reason($unit, '2099-03-04', '2099-03-06') !== null);
        check('db: a block starting on the checkout morning is fine',
            gantt_block_overlap_reason($unit, '2099-03-05', '2099-03-07') === null);
        check('db: a block ending on the arrival morning is fine',
            gantt_block_overlap_reason($unit, '2099-02-27', '2099-03-01') === null);
        check('db: moving a block within its own nights does not refuse itself',
            gantt_block_overlap_reason($unit, '2099-03-02', '2099-03-06', $bid) === null);
        check('db: the picker gets exactly the taken nights',
            gantt_taken_nights(gantt_unit_taken_blocks($unit, '2099-02-01', '2099-04-01'), '2099-02-01', '2099-04-01')
                === ['2099-03-01', '2099-03-02', '2099-03-03', '2099-03-04']);

        $noGuest = bookings_convert_block_to_hold($bid);
        check('db: converting a block with no guest is refused', ($noGuest['ok'] ?? true) === false);
        check('db: …and the block note never becomes the guest name',
            (int) db_query('SELECT COUNT(*) FROM holds WHERE guest_name = :n AND check_in = :ci', [':n' => 'Maintenance', ':ci' => '2099-03-01'])->fetchColumn() === 0);
        $ok = bookings_convert_block_to_hold($bid, '', ['name' => 'Jo Guest', 'email' => 'jo@example.com']);
        check('db: converting with name + email creates the booking', ($ok['ok'] ?? false) === true);
        $h = $ok['ok'] ? db_query('SELECT guest_name, guest_email FROM holds WHERE id = :h', [':h' => $ok['hold_id']])->fetch() : null;
        check('db: the booking carries the guest staff typed',
            $h && $h['guest_name'] === 'Jo Guest' && $h['guest_email'] === 'jo@example.com');
    } catch (Throwable $e) {
        check('db part ran without error: ' . $e->getMessage(), false);
    } finally {
        if (db()->inTransaction()) db()->rollBack();
    }
} elseif ($unit === false) {
    // already reported
} else {
    echo "SKIP  DB part: no active unit\n";
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
