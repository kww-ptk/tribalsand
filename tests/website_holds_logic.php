<?php
declare(strict_types=1);
// No automatic holds — every public booking path asks website_holds_enabled()
// before placing a hold, and the switch defaults to OFF (owner rule, Oct 2026:
// holds are made by the reservations team with Convert to Hold).
// Run: php tests/website_holds_logic.php   (pure source checks always; the
// setting round-trip runs in a rolled-back transaction when a DB is reachable)
require_once __DIR__ . '/../includes/db.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
$src = fn(string $p): string => (string)file_get_contents(__DIR__ . '/../' . $p);

// ── Every public path that can hold is gated by the switch ──
$enq = $src('api/submit-enquiry.php');
check('submit-enquiry: hold decided by $placeHold', str_contains($enq, '$placeHold = $form_mode === \'availability\' && $unit && website_holds_enabled()'));
check('submit-enquiry: hold branch keyed on $placeHold', str_contains($enq, 'if ($placeHold) {'));
check('submit-enquiry: no hold branch keyed on form mode alone', !preg_match('/if \(\$form_mode === \'availability\' && \$unit\) \{/', $enq));

$combo = $src('api/submit-combo.php');
check('submit-combo: hold mode needs website_holds_enabled()', (bool)preg_match('/\$holdMode = .*website_holds_enabled\(\);/', $combo));

$mi = $src('api/maya-ilai-book.php');
check('maya-ilai-book: passes website_holds_enabled() to mi_book_configuration()', str_contains($mi, 'website_holds_enabled()'));
check('maya-ilai-book: reports mode from what was actually held', str_contains($mi, "'mode'        => \$held ? 'hold' : 'enquiry'"));
check('mi_book_configuration: request-only path skips the hold loop', str_contains($src('includes/maya-ilai-hold.php'), 'foreach (($placeHolds ? $picks : []) as $i => $pick)'));

// The guest is only told "held" when the server says it held.
check('booking-widget.js: "held" message only for mode hold', str_contains($src('js/booking-widget.js'), "if (json.mode === 'hold') {"));

// ── Default is OFF, and the switch round-trips ──
try {
    db()->beginTransaction();
    db_query("DELETE FROM settings WHERE setting_key = 'website_holds'");
    check('default (no setting row) = off', website_holds_enabled() === false);
    set_setting('website_holds', '1');
    check('switched on = on', website_holds_enabled() === true);
    set_setting('website_holds', '0');
    check('switched off = off', website_holds_enabled() === false);
    db()->rollBack();
} catch (Throwable $e) {
    if (db_connected_safe()) { try { db()->rollBack(); } catch (Throwable $x) {} }
    echo "SKIP  setting round-trip (no database: " . $e->getMessage() . ")\n";
    check('no database = off (fails closed)', website_holds_enabled() === false);
}

function db_connected_safe(): bool { try { return db()->inTransaction(); } catch (Throwable $e) { return false; } }

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
