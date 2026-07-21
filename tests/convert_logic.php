<?php
declare(strict_types=1);
// DB-backed checks for convert-to-hold helpers. Run: php tests/convert_logic.php
require_once __DIR__ . '/../includes/db.php';

$failures = 0;
function check(string $label, bool $cond): void {
    global $failures;
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// fetch_room_unit_options: returns rows with unit_id/unit_name/room_name
$opts = fetch_room_unit_options();
check('room-unit options is a list', is_array($opts));
check('options have unit_id + room_name + unit_name keys',
      $opts === [] || (isset($opts[0]['unit_id'], $opts[0]['room_name'], $opts[0]['unit_name'])));
check('unit_id values are numeric', $opts === [] || ctype_digit((string)$opts[0]['unit_id']));

// fetch_hold_by_submission: false for a submission id that cannot exist
check('no hold for submission id 0', fetch_hold_by_submission(0) === false);

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
