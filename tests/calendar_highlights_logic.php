<?php
declare(strict_types=1);
// Calendar highlights (admin-editable date ranges + Kenyan public holidays).
// Run: php tests/calendar_highlights_logic.php
// Pure map/validation always; save → map → delete in a rolled-back transaction
// when the DB + add_calendar_highlights migration exist.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/calendar-highlights.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Validation ───────────────────────────────────────────────────
[$c, $e] = cal_highlight_validate(['label' => ' School holiday ', 'date_from' => '2026-12-01', 'date_to' => '2026-12-31', 'color' => 'blue']);
check('valid range passes',               $e === [] && $c['label'] === 'School holiday' && $c['color'] === 'blue');
[$c, $e] = cal_highlight_validate(['label' => 'Easter Sunday', 'date_from' => '2027-03-28', 'date_to' => '']);
check('blank last day = one-day highlight', $e === [] && $c['date_to'] === '2027-03-28');
[, $e] = cal_highlight_validate(['label' => '', 'date_from' => '2026-01-01']);
check('name required',                    isset($e['label']));
[, $e] = cal_highlight_validate(['label' => 'x', 'date_from' => '2026-02-30']);
check('impossible date refused',          isset($e['date_from']));
[, $e] = cal_highlight_validate(['label' => 'x', 'date_from' => '2026-05-10', 'date_to' => '2026-05-01']);
check('last day before first refused',    isset($e['date_to']));
[, $e] = cal_highlight_validate(['label' => 'x', 'date_from' => '2026-01-01', 'date_to' => '2062-01-01']);
check('huge range (typo) refused',        isset($e['date_to']));
[$c] = cal_highlight_validate(['label' => 'x', 'date_from' => '2026-01-01', 'color' => 'hotpink']);
check('unknown colour falls back to amber', $c['color'] === 'amber');

// ── Day map (pure) ───────────────────────────────────────────────
$rows = [
    ['label' => 'School holiday', 'date_from' => '2026-12-20', 'date_to' => '2027-01-04', 'color' => 'amber'],
    ['label' => 'Kite week',      'date_from' => '2026-12-24', 'date_to' => '2026-12-26', 'color' => 'blue'],
];
$map = cal_build_day_map('2026-12-22', '2026-12-31', $rows);
check('range clipped to window start',  !isset($map['2026-12-21']) && isset($map['2026-12-22']));
check('range clipped to window end',    isset($map['2026-12-31']) && !isset($map['2027-01-01']));
check('date_to is INCLUSIVE',           in_array('Kite week', array_column($map['2026-12-26'], 'label'), true)
                                     && !in_array('Kite week', array_column($map['2026-12-27'] ?? [], 'label'), true));
check('Christmas = public holiday first', ($map['2026-12-25'][0]['source'] ?? '') === 'ke' && $map['2026-12-25'][0]['label'] === 'Christmas Day');
check('overlaps stack on one day',      count($map['2026-12-25']) === 3);   // Christmas + school + kite

$xmas = cal_day_info($map['2026-12-25']);
check('public holiday keeps red class', $xmas['class'] === 'is-holiday');
check('tooltip lists every label',      $xmas['title'] === 'Christmas Day · School holiday · Kite week');
$plain = cal_day_info($map['2026-12-22']);
check('custom day gets its colour class', $plain['class'] === 'is-hl is-hl--amber');
check('empty day → no class',           cal_day_info([])['class'] === '');

$legend = cal_map_custom_legend($map);
check('legend lists each custom highlight once', count($legend) === 2);

$noKe = cal_build_day_map('2026-12-25', '2026-12-25', [], false);
check('includeKe=false skips holidays', $noKe === []);
check('bad window → empty map',         cal_build_day_map('2026-12-31', '2026-12-01', $rows) === []);

// Existing behaviour kept: the computed Kenya list still works on its own.
check('Good Friday 2026 still computed', ke_holiday_name('2026-04-03') === 'Good Friday');
check('range label same year',          cal_range_label('2026-04-03', '2026-04-12') === '3 Apr – 12 Apr 2026');
check('range label one day',            cal_range_label('2026-04-03', '2026-04-03') === '3 Apr 2026');

// ── DB round-trip (rolled back) ──────────────────────────────────
$dbOk = false;
try { db(); $dbOk = cal_highlights_supported(); } catch (Throwable $e) {}
if (!$dbOk) {
    echo "SKIP  DB or add_calendar_highlights migration unavailable — pure checks only\n";
} else {
    db()->beginTransaction();
    try {
        $r = cal_highlight_save(0, ['label' => 'ZZ Test holiday', 'date_from' => '2099-08-01', 'date_to' => '2099-08-10', 'color' => 'green'], null);
        check('DB: create', $r['ok'] && $r['id'] > 0);
        $bad = cal_highlight_save(0, ['label' => '', 'date_from' => 'x'], null);
        check('DB: invalid create refused', !$bad['ok'] && isset($bad['errors']['label']));

        $m = cal_day_map('2099-08-09', '2099-08-12');
        check('DB: shows inside window',      isset($m['2099-08-09']) && isset($m['2099-08-10']));
        check('DB: gone after last day',      !isset($m['2099-08-11']));
        check('DB: carries colour',           ($m['2099-08-10'][0]['color'] ?? '') === 'green');

        $u = cal_highlight_save($r['id'], ['label' => 'ZZ Renamed', 'date_from' => '2099-08-01', 'date_to' => '2099-08-11', 'color' => 'purple'], null);
        $m2 = cal_day_map('2099-08-11', '2099-08-11');
        check('DB: update moves last day',    $u['ok'] && ($m2['2099-08-11'][0]['label'] ?? '') === 'ZZ Renamed');
        check('DB: update of missing id fails', !cal_highlight_save(999999999, ['label' => 'x', 'date_from' => '2099-01-01'], null)['ok']);

        check('DB: delete',                   cal_highlight_delete($r['id']));
        check('DB: gone from map',            cal_day_map('2099-08-01', '2099-08-11') === []);
        check('DB: delete twice is false',    !cal_highlight_delete($r['id']));
    } finally {
        db()->rollBack();
    }
}

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
