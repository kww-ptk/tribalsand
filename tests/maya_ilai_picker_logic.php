<?php
declare(strict_types=1);
// Maya Ilai "build it yourself" picker: never offer or price a stay the live
// calendar can't take — whole products in one villa each, loose doubles and
// living rooms packed with ONE living room per villa. Pure (no DB).
// Run: php tests/maya_ilai_picker_logic.php
require_once __DIR__ . '/../includes/maya-ilai-inventory.php';
require_once __DIR__ . '/../includes/maya-ilai-pricing.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

/** 8 villas; $taken = [villa number => components already booked]. Villas 7–8 are ring-fenced (reserved 2). */
function villas(array $taken = []): array {
    $out = [];
    for ($n = 1; $n <= 8; $n++) $out[] = ['unit_id' => 100 + $n, 'sort_order' => $n, 'taken' => $taken[$n] ?? []];
    return $out;
}
$place = fn(array $v, array $fixed, int $d, int $b, int $l, int $studios = 0, int $freeStudios = 8)
    => mi_can_place_selection($v, $fixed, $d, $b, $l, $studios, $freeStudios, 2);

// Every component-eligible villa (1–6) has its living room booked already.
$livingGone = [];
for ($n = 1; $n <= 6; $n++) $livingGone[$n] = ['living'];

check('empty compound: 2 doubles + 1 living fit', $place(villas(), [], 2, 0, 1));
check('a living room needs one of our bedrooms', !$place(villas(), [], 0, 0, 1));
check('one living room per villa: 1 double + 2 living refused', !$place(villas(), [], 1, 0, 2));
check('2 doubles + 2 living spread over two villas', $place(villas(), [], 2, 0, 2));
check('living rooms all taken: double alone still fits', $place(villas($livingGone), [], 1, 0, 0));
check('living rooms all taken: double + living refused', !$place(villas($livingGone), [], 1, 0, 1));
check('living rooms all taken: One-Bedroom Suite refused', !$place(villas($livingGone), ['maya-ilai-one-bed-suite'], 0, 0, 0));
check('ring-fenced villas still sell as whole villas', $place(villas($livingGone), ['maya-ilai-villa'], 0, 0, 0));
check('…but only two of them', !$place(villas($livingGone), ['maya-ilai-villa', 'maya-ilai-villa', 'maya-ilai-villa'], 0, 0, 0));

// Only villa 3 has a free living room.
$oneLiving = $livingGone; unset($oneLiving[3]);
check('one free living room: one suite fits', $place(villas($oneLiving), ['maya-ilai-one-bed-suite'], 0, 0, 0));
check('one free living room: two suites refused', !$place(villas($oneLiving), ['maya-ilai-one-bed-suite', 'maya-ilai-one-bed-suite'], 0, 0, 0));
check('one free living room: suite + extra loose living refused', !$place(villas($oneLiving), ['maya-ilai-one-bed-suite'], 1, 0, 1));
check('fixed product takes the living room the loose one wanted', !$place(villas($oneLiving), ['maya-ilai-two-bed-suite'], 1, 0, 1));

// Doubles: villa 1 has both doubles taken, the rest are full.
$full = [];
for ($n = 1; $n <= 6; $n++) $full[$n] = ['double_a', 'double_b', 'bunk', 'living'];
$full[1] = ['double_a', 'double_b'];
check('no free double anywhere: Double Room refused', !$place(villas($full), [], 1, 0, 0));
check('studios: more than free refused', !$place(villas(), [], 0, 0, 0, 3, 2));
check('studios: within free accepted', $place(villas(), [], 0, 0, 0, 2, 2));
check('nothing picked is trivially fine', $place(villas($full), [], 0, 0, 0));
check('unknown product fails closed', !$place(villas(), ['maya-ilai-penthouse'], 0, 0, 0));

// The API helper: rows → products, the one-living-room message.
$cfg  = maya_ilai_pricing_defaults();
$live = ['supported' => true, 'villaStates' => villas($oneLiving), 'freeStudios' => 8, 'reserved' => 2, 'freeVillas' => 2];
$keys = array_column(maya_ilai_products($cfg), 'key');
if (in_array('One-Bedroom Suite', $keys, true)) {
    check('API: one suite on one free living room is bookable',
        maya_ilai_picker_unbookable([['key' => 'One-Bedroom Suite', 'qty' => 1]], ['qtyLiving' => 1], $live, $cfg) === null);
    $why = maya_ilai_picker_unbookable([['key' => 'One-Bedroom Suite', 'qty' => 2]], ['qtyLiving' => 2], $live, $cfg);
    check('API: two suites refused with the living-room reason', is_string($why) && str_contains($why, 'one living room'));
} else {
    echo "SKIP  One-Bedroom Suite not offered at the default rates\n";
}
check('API: Double Room + standalone living on one free living room ok',
    maya_ilai_picker_unbookable([['key' => 'Double Room', 'qty' => 1]], ['qtyLiving' => 1], $live, $cfg) === null);
check('API: Double Room ×2 + 2 standalone living refused',
    maya_ilai_picker_unbookable([['key' => 'Double Room', 'qty' => 2]], ['qtyLiving' => 2], $live, $cfg) !== null);
check('API: unknown product refused', maya_ilai_picker_unbookable([['key' => 'Penthouse', 'qty' => 1]], [], $live, $cfg) !== null);
check('API: studios over the free count named',
    str_contains((string) maya_ilai_picker_unbookable([['key' => 'Studio', 'qty' => 3]], [], ['freeStudios' => 2] + $live, $cfg), 'studio'));
check('API: no calendar → no check', maya_ilai_picker_unbookable([['key' => 'Double Room', 'qty' => 9]], [], ['supported' => false], $cfg) === null);

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
