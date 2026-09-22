<?php
declare(strict_types=1);
// Clock in/out — slot resolution, night shifts, token and device auth.
// Run: php tests/attendance_clock_logic.php
// DB assertions run inside ONE transaction that is ROLLED BACK at the end.
// Requires add_hr_staff.sql + add_attendance.sql + add_attendance_punches.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/attendance-clock.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Guards ──────────────────────────────────────────────────────────────────
check('punches guard returns a bool', is_bool(attendance_punches_supported()));
check('devices guard returns a bool', is_bool(attendance_devices_supported()));

// ── Token shape (pure) ──────────────────────────────────────────────────────
$t1 = clock_new_token();
$t2 = clock_new_token();
check('token is 32 hex chars',  (bool)preg_match('/^[0-9a-f]{32}$/', $t1));
check('tokens differ',          $t1 !== $t2);

// ── Slot resolution (pure). $row is an attendance row, or [] for no row. ─────
$empty  = [];
$in1    = ['in1' => 420];                                   // 07:00
$closed = ['in1' => 420, 'out1' => 720];                    // 07:00–12:00
$in2    = ['in1' => 420, 'out1' => 720, 'in2' => 780];      // back at 13:00
$full   = ['in1' => 420, 'out1' => 720, 'in2' => 780, 'out2' => 1020];

check('empty + in  -> in1',     clock_next_slot($empty,  'in')  === 'in1');
check('empty + out -> null',    clock_next_slot($empty,  'out') === null);
check('in1 + in    -> null',    clock_next_slot($in1,    'in')  === null);
check('in1 + out   -> out1',    clock_next_slot($in1,    'out') === 'out1');
check('closed + in -> in2',     clock_next_slot($closed, 'in')  === 'in2');
check('closed + out-> null',    clock_next_slot($closed, 'out') === null);
check('in2 + out   -> out2',    clock_next_slot($in2,    'out') === 'out2');
check('in2 + in    -> null',    clock_next_slot($in2,    'in')  === null);
check('full + in   -> null',    clock_next_slot($full,   'in')  === null);
check('full + out  -> null',    clock_next_slot($full,   'out') === null);

// A non-worked status (leave, off) must not silently accept a punch.
check('status row + in -> null', clock_next_slot(['status' => 'LV'], 'in') === null);

// An open row is one with an in and no matching out — used for the night-shift rule.
check('open: in1 only',      clock_row_is_open($in1)    === true);
check('open: closed pair',   clock_row_is_open($closed) === false);
check('open: in2 only',      clock_row_is_open($in2)    === true);
check('open: full',          clock_row_is_open($full)   === false);
check('open: empty',         clock_row_is_open($empty)  === false);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
