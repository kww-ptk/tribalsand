<?php
declare(strict_types=1);
// Staff room allocation — beds, moving people, full rooms, history.
// Run: php tests/staff_rooms_logic.php
// DB part runs inside ONE transaction that is ROLLED BACK; it SKIPs without a
// database or before add_staff_rooms.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/staff-rooms.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function refused(callable $fn): bool {
    try { $fn(); return false; } catch (StaffRoomRefusal $e) { return true; }
}

check('free bed: first gap', staff_room_free_bed(3, [1, 3]) === 2);
check('free bed: full → null', staff_room_free_bed(2, [1, 2]) === null);
check('scope: owner / HR see every room', staff_room_in_scope(null, null) && staff_room_in_scope(3, null));
check('scope: a manager only their properties', staff_room_in_scope(3, [3, 4]) && !staff_room_in_scope(5, [3]) && !staff_room_in_scope(null, [3]));

try { db(); $haveDb = staff_rooms_supported(); } catch (Throwable $e) { $haveDb = false; }
if (!$haveDb) {
    echo "SKIP  DB checks (no database or add_staff_rooms not applied)\n";
} else {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $mk = function (string $n, string $st = 'active') use ($pdo): int {
            db_query("INSERT INTO hr_staff (full_name, position, department, status) VALUES (:n, 'COOK', 'Kitchen', :st)", [':n' => $n, ':st' => $st]);
            return (int)$pdo->lastInsertId();
        };
        $a = $mk('ROOM TEST A'); $b = $mk('ROOM TEST B'); $c = $mk('ROOM TEST C'); $gone = $mk('ROOM TEST LEFT', 'inactive');
        $r1 = staff_room_save(null, null, 'Test block · Room 1', 2, '');
        $r2 = staff_room_save(null, null, 'Test block · Room 2', 1, 'single');
        check('room: needs a name and 1–20 beds', refused(fn() => staff_room_save(null, null, '  ', 2, '')) && refused(fn() => staff_room_save(null, null, 'X', 0, '')));

        staff_room_assign($r1, $a, null, '2026-10-01', '', null);
        staff_room_assign($r1, $b, null, '2026-10-01', '', null);
        $now = staff_room_of($a);
        check('assign: first free bed', (int)$now['room_id'] === $r1 && (int)$now['bed'] === 1 && (int)staff_room_of($b)['bed'] === 2);
        check('assign: a full room refuses', refused(fn() => staff_room_assign($r1, $c, null, '2026-10-01', '', null)));
        check('assign: someone who left refuses', refused(fn() => staff_room_assign($r2, $gone, null, '2026-10-01', '', null)));
        check('assign: a bed that is taken refuses', refused(fn() => staff_room_assign($r1, $c, 2, '2026-10-01', '', null)));
        check('assign: already there refuses', refused(fn() => staff_room_assign($r1, $a, 1, '2026-10-05', '', null)));
        check('room: beds below the people in it refuses', refused(fn() => staff_room_save($r1, null, 'Test block · Room 1', 1, '')));

        staff_room_assign($r2, $a, null, '2026-10-10', 'moved', null);
        check('move: one current room only', (int)staff_room_of($a)['room_id'] === $r2);
        $hist = staff_room_history($a);
        check('move: the old room closed on the move date', count($hist) === 2 && $hist[1]['date_to'] === '2026-10-10');
        check('move: a date before the current move-in refuses', refused(fn() => staff_room_assign($r1, $a, null, '2026-10-05', '', null)));
        check('move: the freed bed can be taken', staff_room_assign($r1, $c, null, '2026-10-11', '', null) > 0 && (int)staff_room_of($c)['bed'] === 1);

        $list = staff_rooms_list(null);
        $mine = array_values(array_filter($list, fn($r) => (int)$r['id'] === $r1))[0];
        check('list: occupants per room', count($mine['people']) === 2);
        $alloc = (int)staff_room_of($b)['allocation_id'];
        check('vacate: before move-in refuses', refused(fn() => staff_room_vacate($alloc, '2026-09-30')));
        staff_room_vacate($alloc, '2026-10-12');
        check('vacate: no current room after', staff_room_of($b) === null);
        check('remove: a room with people refuses', refused(fn() => staff_room_remove($r1)));
        staff_room_vacate((int)staff_room_of($c)['allocation_id'], '2026-10-12');
        check('remove: a used room is switched off, not deleted', staff_room_remove($r1) === 'deactivated');
        $r3 = staff_room_save(null, null, 'Never used', 1, '');
        check('remove: an unused room is deleted', staff_room_remove($r3) === 'deleted');
    } finally {
        $pdo->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
