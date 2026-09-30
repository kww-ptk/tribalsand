<?php
declare(strict_types=1);
// Every migration is listed in db/migrations/ORDER.txt exactly once, and every
// listed name exists. bin/dev-setup.php builds local databases in that order;
// alphabetical order builds the wrong schema (add_staff_role / add_team_roles /
// add_reception_role rewrite the same CHECK constraint).
// Run: php tests/migration_order_logic.php   (pure — no database)

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$dir   = __DIR__ . '/../db/migrations';
$files = array_map('basename', glob($dir . '/*.sql') ?: []);
$lines = array_values(array_filter(
    array_map('trim', file($dir . '/ORDER.txt', FILE_IGNORE_NEW_LINES) ?: []),
    fn($l) => $l !== '' && $l[0] !== '#'
));

$missing = array_diff($files, $lines);
$stale   = array_diff($lines, $files);
$dupes   = array_keys(array_filter(array_count_values($lines), fn($n) => $n > 1));

check('every migration is listed in ORDER.txt' . ($missing ? ' — add: ' . implode(', ', $missing) : ''), !$missing);
check('every ORDER.txt line is an existing file' . ($stale ? ' — remove: ' . implode(', ', $stale) : ''), !$stale);
check('no migration listed twice' . ($dupes ? ' — ' . implode(', ', $dupes) : ''), !$dupes);

$pos = array_flip($lines);
check('role constraint migrations run in the order production ran them',
    isset($pos['add_staff_role.sql'], $pos['add_team_roles.sql'], $pos['add_reception_role.sql'])
    && $pos['add_staff_role.sql'] < $pos['add_team_roles.sql']
    && $pos['add_team_roles.sql'] < $pos['add_reception_role.sql']);
check('POS job types run after team roles reset job_type',
    isset($pos['add_team_roles.sql'], $pos['add_pos_job_types.sql'])
    && $pos['add_team_roles.sql'] < $pos['add_pos_job_types.sql']);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
