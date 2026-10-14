<?php
declare(strict_types=1);
// Gate pass + gate arrivals, and "Delete imported bookings".
// Pure always: pass token sign/verify/scan, when the pass shows, party maths,
// the guard's verdict, the import-kind SQL. With a reachable DB (and the
// add_gate_arrivals migration) the check-in round-trip and an import clear run
// inside ONE transaction that is rolled back.
// Run: php tests/gate_pass_logic.php
require_once __DIR__ . '/../includes/gate-pass.php';
require_once __DIR__ . '/../includes/booking-import.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Token ──
$sec = 'test-secret';
$t = gate_pass_token(42, $sec);
check('token: shape GP-<id>-<12 hex>', (bool)preg_match('/^GP-42-[0-9a-f]{12}$/', $t));
check('token: verifies', gate_pass_verify($t, $sec) === 42);
check('token: typed in upper case still verifies', gate_pass_verify(strtoupper($t), $sec) === 42);
check('token: other id refused', gate_pass_verify('GP-43-' . substr($t, 6), $sec) === false);
check('token: tampered hash refused', gate_pass_verify(substr($t, 0, -1) . (substr($t, -1) === 'a' ? 'b' : 'a'), $sec) === false);
check('token: other secret refused', gate_pass_verify($t, 'other') === false);
check('token: no secret = no pass', gate_pass_token(42, '') === '' && gate_pass_verify($t, '') === false);
check('token: domain-separated from the guest ref hash', substr($t, 6) !== substr(hash_hmac('sha256', '42', $sec), 0, 12));
check('scan: token found in the full link', gate_pass_from_scan('https://tribalsand.com/admin/gate.php?pass=' . $t) === $t);
check('scan: bare token', gate_pass_from_scan($t) === $t);
check('scan: anything else = empty', gate_pass_from_scan('https://example.com/?q=hello') === '' && gate_pass_from_scan('') === '');

// ── When the guest sees the pass ──
check('ready: confirmed, check-in done', gate_pass_ready('confirmed', false, true, true, '2026-10-20', '2026-10-14')['ok']);
check('ready: confirmed, no check-in needed', gate_pass_ready('confirmed', false, false, false, '2026-10-20', '2026-10-14')['ok']);
$r = gate_pass_ready('confirmed', false, true, false, '2026-10-20', '2026-10-14');
check('not ready: check-in unfinished → says so', !$r['ok'] && str_contains($r['why'], 'check-in'));
check('not ready: web hold awaiting confirmation', !gate_pass_ready('pending', true, false, false, '2026-10-20', '2026-10-14')['ok']);
check('ready: staff-typed pending booking (no expiry)', gate_pass_ready('pending', false, false, false, '2026-10-20', '2026-10-14')['ok']);
check('not ready: cancelled', !gate_pass_ready('cancelled', false, false, false, '2026-10-20', '2026-10-14')['ok']);
check('not ready: stay ended', !gate_pass_ready('confirmed', false, false, false, '2026-10-13', '2026-10-14')['ok']);
check('ready: on the checkout day', gate_pass_ready('confirmed', false, false, false, '2026-10-14', '2026-10-14')['ok']);

// ── Party ──
$guests = [['passport_name' => 'Ann Lee', 'is_child' => false], ['passport_name' => 'Ben Lee', 'is_child' => false],
           ['passport_name' => 'Cal Lee', 'is_child' => true]];
$p = gate_party($guests, 3);
check('party: 3 people, 2 adults 1 child', $p['total'] === 3 && $p['adults'] === 2 && $p['children'] === 1 && count($p['names']) === 3);
check('party label: full split', gate_party_label($p) === '3 people · 2 adults, 1 child');
$p = gate_party([['passport_name' => 'Ann Lee', 'is_child' => false]], 4);
check('party: booking larger than roster → booking size, 3 unnamed', $p['total'] === 4 && $p['unnamed'] === 3);
check('party label: no split when the roster is short', gate_party_label($p) === '4 people');
$p = gate_party([], 0, 'Jess Smith');
check('party: no roster → the lead name, 1 person', $p['total'] === 1 && $p['names'][0]['name'] === 'Jess Smith');
check('party label: singular', gate_party_label($p) === '1 person · 1 adult');
check('party: Postgres "f" is not a child', gate_party([['passport_name' => 'X', 'is_child' => 'f']], 1)['children'] === 0);

// ── Verdict ──
$base = ['status' => 'confirmed', 'check_in' => '2026-10-14', 'check_out' => '2026-10-17', 'venue_name' => 'Zuri'];
check('verdict: expected today = ok', gate_verdict($base, '2026-10-14', true)['level'] === 'ok');
check('verdict: in house = ok', gate_verdict($base, '2026-10-15', true)['level'] === 'ok');
check('verdict: cancelled = stop', gate_verdict(['status' => 'cancelled'] + $base, '2026-10-14', true)['level'] === 'stop');
check('verdict: web hold not confirmed = stop', gate_verdict(['status' => 'pending', 'expires_at' => '2026-10-15 10:00'] + $base, '2026-10-14', true)['level'] === 'stop');
check('verdict: other property = stop, names it', ($v = gate_verdict($base, '2026-10-14', false))['level'] === 'stop' && str_contains($v['msg'], 'Zuri'));
check('verdict: early = warn', gate_verdict($base, '2026-10-12', true)['level'] === 'warn');
check('verdict: stay over = stop', gate_verdict($base, '2026-10-18', true)['level'] === 'stop');
check('verdict: check-in unfinished = warn', gate_verdict(['require_checkin' => true] + $base, '2026-10-14', true)['level'] === 'warn');
check('verdict: check-in submitted by lead = ok', gate_verdict(['require_checkin' => true, 'checkin_submitted_at' => '2026-10-13'] + $base, '2026-10-14', true)['level'] === 'ok');
check('verdict: already in = ok + says when', str_contains(gate_verdict(['gate_arrived_at' => '2026-10-14 12:05'] + $base, '2026-10-14', true)['msg'], '12:05'));

// ── Delete imported bookings: which blocks ──
check('clear sql: eZee only', import_clear_kind_sql(['ezee'], true) === "(b.notes LIKE 'Ezee import · %')");
check('clear sql: iCal tracked uses the feed id', str_contains(import_clear_kind_sql(['ical'], true), 'ical_feed_id IS NOT NULL'));
check('clear sql: iCal pre-tracking uses the note only', import_clear_kind_sql(['ical'], false) === "(b.notes LIKE 'iCal: %')");
check('clear sql: both', substr_count(import_clear_kind_sql(['ezee', 'ical'], true), ' OR ') === 2);
check('clear sql: nothing chosen = nothing', import_clear_kind_sql([], true) === '' && import_clear_kind_sql(['bogus'], true) === '');
check('clear: no venue = no ids, no query', import_clear_block_ids([], ['ezee']) === []);

// ── DB round-trip (rolled back) ──
$pdo = null;
try { $pdo = db(); $pdo->query('SELECT 1'); } catch (Throwable $e) { $pdo = null; }
if (!$pdo) {
    echo "SKIP  DB checks (no database)\n";
} else {
    $pdo->beginTransaction();
    try {
        $u = db_query("SELECT u.id AS unit_id, r.venue_id FROM units u JOIN rooms r ON r.id = u.room_id WHERE r.venue_id IS NOT NULL ORDER BY u.id LIMIT 1")->fetch();
        if (!$u) { echo "SKIP  DB checks (no units)\n"; }
        else {
            $vid = (int)$u['venue_id']; $unit = (int)$u['unit_id'];
            // Import clear: one eZee block, one staff closure, one website-hold block.
            $ez = (int)db_query("INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, notes) VALUES (:u, '2031-01-05', '2031-01-08', 'blocked', 'Ezee import · Test Guest · Res 1') RETURNING id", [':u' => $unit])->fetchColumn();
            $own = (int)db_query("INSERT INTO availability_blocks (unit_id, date_from, date_to, block_type, notes) VALUES (:u, '2031-02-05', '2031-02-08', 'blocked', 'Maintenance') RETURNING id", [':u' => $unit])->fetchColumn();
            $ids = import_clear_block_ids([$vid], ['ezee']);
            check('db clear: finds the eZee block', in_array($ez, $ids, true));
            check('db clear: never a staff closure', !in_array($own, $ids, true));
            check('db clear: future filter keeps a 2031 stay in', in_array($ez, import_clear_block_ids([$vid], ['ezee'], '2030-12-31'), true));
            check('db clear: future filter drops it after it ends', !in_array($ez, import_clear_block_ids([$vid], ['ezee'], '2031-01-08'), true));
            $r = import_clear([$vid], ['ezee']);
            check('db clear: deleted', $r['blocks'] >= 1 && !db_query('SELECT 1 FROM availability_blocks WHERE id = :i', [':i' => $ez])->fetchColumn());
            check('db clear: closure still there', (bool)db_query('SELECT 1 FROM availability_blocks WHERE id = :i', [':i' => $own])->fetchColumn());

            if (!gate_arrivals_supported()) {
                echo "SKIP  gate check-in (run add_gate_arrivals.sql)\n";
            } else {
                $hid = (int)db_query("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status)
                                      VALUES (:u, '2031-03-01', '2031-03-04', 'Gate Test', 'gate@example.test', 'confirmed') RETURNING id", [':u' => $unit])->fetchColumn();
                check('db gate: check in', gate_mark_arrived($hid, 0, 3));
                check('db gate: second check-in refused', !gate_mark_arrived($hid, 0, 3));
                $h = db_query('SELECT gate_arrived_at, gate_people FROM holds WHERE id = :h', [':h' => $hid])->fetch();
                check('db gate: people stored', (int)$h['gate_people'] === 3 && $h['gate_arrived_at'] !== null);
                check('db gate: check out', gate_mark_departed($hid, 0) && !gate_mark_departed($hid, 0));
                check('db gate: undo clears both', gate_undo($hid) && db_query('SELECT gate_arrived_at IS NULL AND gate_departed_at IS NULL FROM holds WHERE id = :h', [':h' => $hid])->fetchColumn());
                $bk = gate_pass_booking($hid);
                check('db gate: booking read with party', $bk && $bk['party']['total'] >= 1 && (int)$bk['venue_id'] > 0);
            }
        }
    } catch (Throwable $e) {
        check('db checks ran without error: ' . $e->getMessage(), false);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
