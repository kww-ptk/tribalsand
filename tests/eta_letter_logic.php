<?php
declare(strict_types=1);
// Accommodation confirmation for the Kenya eTA (includes/eta-letter.php):
// when a guest may have one, and what it says. Pure — no database.
// Run: php tests/eta_letter_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/checkin.php';
require_once __DIR__ . '/../includes/eta-letter.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

$hold  = ['id' => 175, 'status' => 'confirmed', 'check_in' => '2026-12-20', 'check_out' => '2026-12-27', 'guest_count' => 2,
          'access_code' => '8GGJTA4K', 'venue_name' => 'Maya Kobe', 'room_name' => 'Ocean Suite', 'venue_address' => 'Kilifi Creek, Kilifi, Kenya'];
$guest = ['id' => 9, 'is_lead' => true, 'passport_name' => 'Anne Smith', 'passport_number' => 'X1234567', 'nationality' => 'United Kingdom', 'id_type' => 'passport'];

// ── When ──
check('confirmed + passport details: available', eta_letter_status($hold, $guest)['ok'] === true);
$s = eta_letter_status(['status' => 'pending'] + $hold, $guest);
check('pending booking: not yet, and says why', !$s['ok'] && str_contains($s['reason'], 'confirmed'));
check('cancelled / expired: no letter', !eta_letter_status(['status' => 'cancelled'] + $hold, $guest)['ok'] && !eta_letter_status(['status' => 'expired'] + $hold, $guest)['ok']);
$s = eta_letter_status($hold, ['passport_number' => ''] + $guest);
check('no passport number: asks for the passport first', !$s['ok'] && str_contains($s['reason'], 'passport'));
check('no nationality: asks for the passport first', !eta_letter_status($hold, ['nationality' => ''] + $guest)['ok']);
$s = eta_letter_status($hold, ['id_type' => 'kenya_id'] + $guest);
check('Kenyan ID: not needed, said plainly', !$s['ok'] && $s['hidden'] === true && str_contains($s['reason'], "don't need"));
check('no guest row: no letter', !eta_letter_status($hold, null)['ok']);
check('a check-out in the past: no letter', !eta_letter_status(['check_out' => '2020-01-02'] + $hold, $guest, '2026-10-14')['ok']);

// ── What ──
$m = eta_letter_model($hold, $guest, null, '2026-10-14');
check('model: guest identity', $m['guest_name'] === 'Anne Smith' && $m['passport_number'] === 'X1234567' && $m['nationality'] === 'United Kingdom');
check('model: stay', $m['property'] === 'Maya Kobe' && $m['address'] === 'Kilifi Creek, Kilifi, Kenya' && $m['nights'] === 7
    && $m['check_in_label'] === 'Sunday 20 December 2026' && $m['check_out_label'] === 'Sunday 27 December 2026');
check('model: party line', $m['party'] === '2 guests on this booking');
check('model: booking reference + code', $m['booking_ref'] === 'TS-175' && $m['access_code'] === '8GGJTA4K');
check('model: issued on', $m['issued_label'] === '14 October 2026');
check('model: no company → Tribal Sand', $m['issuer_name'] === 'Tribal Sand' && $m['issuer_pin'] === '');
$m = eta_letter_model($hold, $guest, ['legal_name' => 'Maya Kobe Ltd', 'name' => 'Maya Kobe', 'kra_pin' => 'P051234567X', 'address' => 'P.O. Box 1, Kilifi'], '2026-10-14');
check('model: company legal name + KRA PIN', $m['issuer_name'] === 'Maya Kobe Ltd' && $m['issuer_pin'] === 'P051234567X' && $m['issuer_address'] === 'P.O. Box 1, Kilifi');
$m = eta_letter_model(['guest_count' => 1, 'venue_address' => ''] + $hold, $guest, null, '2026-10-14');
check('model: one guest, no address → property name only', $m['party'] === '1 guest on this booking' && $m['address'] === '');
check('model: official site named', str_contains(ETA_OFFICIAL_SITE, 'etakenya.go.ke'));

// ── Links ──
check('url: lead link carries ref', eta_letter_url(175, 9, 'TS-175-abc', null) === '/eta-letter.php?hold=175&guest=9&ref=TS-175-abc');
check('url: co-guest link carries g', eta_letter_url(175, 9, null, '9-xyz') === '/eta-letter.php?hold=175&guest=9&g=9-xyz');
check('url: staff link has neither', eta_letter_url(175, 9) === '/eta-letter.php?hold=175&guest=9');

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
