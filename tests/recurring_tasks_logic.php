<?php
declare(strict_types=1);
// Recurring-task engine — frequency date math + spawn/dedupe round-trip.
// Run: php tests/recurring_tasks_logic.php
// DB assertions run inside ONE transaction that is ROLLED BACK at the end, so no
// real rows are left behind. Requires add_tasks.sql + add_recurring_tasks.sql.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/recurring-tasks.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Pure logic (no DB) ──────────────────────────────────────────────────────
check('freq options include custom',      array_key_exists('custom', recurring_freq_options()));
check('next daily',        recurrence_next_date('2026-01-10', 'daily')     === '2026-01-11');
check('next weekly',       recurrence_next_date('2026-01-10', 'weekly')    === '2026-01-17');
check('next biweekly',     recurrence_next_date('2026-01-10', 'biweekly')  === '2026-01-24');
check('next monthly',      recurrence_next_date('2026-01-10', 'monthly')   === '2026-02-10');
check('next quarterly',    recurrence_next_date('2026-01-10', 'quarterly') === '2026-04-10');
check('next custom (10d)', recurrence_next_date('2026-01-10', 'custom', 10) === '2026-01-20');
check('custom < 1 clamps', recurrence_next_date('2026-01-10', 'custom', 0) === '2026-01-11');
// Month-end clamping: Jan 31 + 1 month → Feb 28 (2026 is not a leap year).
check('monthly clamps Jan31→Feb28', recurrence_add_months('2026-01-31', 1) === '2026-02-28');
check('monthly clamps Jan31→Apr30', recurrence_add_months('2026-01-31', 3) === '2026-04-30');
check('freq label custom',  recurring_freq_label('custom', 3) === 'Every 3 days');
check('freq label weekly',  recurring_freq_label('weekly') === 'Weekly');

if (!recurring_tasks_supported()) {
    echo "\nSKIP  DB assertions (add_recurring_tasks.sql not applied)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n"); exit($failures ? 1 : 0);
}

$venueId = (int) db_query('SELECT id FROM venues ORDER BY id LIMIT 1')->fetchColumn();
if (!$venueId) { echo "\nSKIP  no venues seeded\n"; exit($failures ? 1 : 0); }

db()->beginTransaction();
try {
    // A weekly rule that started 3 weeks ago → today should back-fill 4 tasks
    // (weeks 0,1,2,3) and land next_run_date in the future.
    $start = date('Y-m-d', strtotime('-21 days'));
    $rid = create_task_recurrence([
        'venue_id'  => $venueId,
        'title'     => 'TEST cut grass',
        'frequency' => 'weekly',
        'start_date'=> $start,
    ]);
    check('recurrence created', $rid > 0);

    $n1 = spawn_due_recurring_tasks(date('Y-m-d'), null);
    $cnt = (int) db_query('SELECT COUNT(*) FROM tasks WHERE recurrence_id = :r', [':r'=>$rid])->fetchColumn();
    check('spawned back-filled occurrences (>=4)', $cnt >= 4);
    check('spawn returned the number it created',  $n1 === $cnt);

    // Idempotent: a second sweep on the same day creates nothing new.
    $n2 = spawn_due_recurring_tasks(date('Y-m-d'), null);
    $cnt2 = (int) db_query('SELECT COUNT(*) FROM tasks WHERE recurrence_id = :r', [':r'=>$rid])->fetchColumn();
    check('second sweep spawns nothing', $n2 === 0 && $cnt2 === $cnt);

    // next_run_date advanced beyond today.
    $next = (string) db_query('SELECT next_run_date FROM task_recurrences WHERE id = :r', [':r'=>$rid])->fetchColumn();
    check('next_run_date is in the future', substr($next,0,10) > date('Y-m-d'));

    // Spawned tasks carry the rule's title and 'todo' status.
    $t = db_query('SELECT title, status FROM tasks WHERE recurrence_id = :r ORDER BY due_date LIMIT 1', [':r'=>$rid])->fetch();
    check('spawned task has title + todo', $t && $t['title'] === 'TEST cut grass' && $t['status'] === 'todo');

    // A paused rule spawns nothing.
    $rid2 = create_task_recurrence(['venue_id'=>$venueId,'title'=>'TEST paused','frequency'=>'daily','start_date'=>date('Y-m-d')]);
    db_query('UPDATE task_recurrences SET is_active = FALSE WHERE id = :r', [':r'=>$rid2]);
    $before = (int) db_query('SELECT COUNT(*) FROM tasks WHERE recurrence_id = :r', [':r'=>$rid2])->fetchColumn();
    spawn_due_recurring_tasks(date('Y-m-d'), null);
    $after = (int) db_query('SELECT COUNT(*) FROM tasks WHERE recurrence_id = :r', [':r'=>$rid2])->fetchColumn();
    check('paused rule spawns nothing', $before === 0 && $after === 0);
} finally {
    db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
