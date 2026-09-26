<?php
declare(strict_types=1);
// POS core logic. Run: php tests/pos_logic.php
// Pure rules always run. The DB block (sale / stock / room charge / void /
// permissions) runs inside ONE transaction that is rolled back, and SKIPs when
// no database is reachable or add_pos.sql has not been applied.
// No DB handy? Force a fast connect failure instead of a localhost hang:
//   DATABASE_URL=postgres://x:y@127.0.0.1:70000/none php tests/pos_logic.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pos.php';
require_once __DIR__ . '/../includes/pos-auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();   // before any output — role helpers read $_SESSION

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}
function near(float $a, float $b): bool { return abs($a - $b) < 0.001; }

// ── Totals ──────────────────────────────────────────────────────────────────
$t = pos_cart_totals([['unit_price' => 70, 'qty' => 3]], 10);
check('totals: 10% service on 210 → 21 / 231', near($t['subtotal'], 210) && near($t['service_charge'], 21) && near($t['total'], 231));
$t = pos_cart_totals([['unit_price' => 28, 'qty' => 2]], 0);
check('totals: zero-service outlet', near($t['service_charge'], 0) && near($t['total'], 56));
$t = pos_cart_totals([['unit_price' => 0.1, 'qty' => 3], ['unit_price' => 0.2, 'qty' => 1]], 0);
check('totals: cents math has no float drift (0.1×3 + 0.2 = 0.50)', near($t['total'], 0.5) && pos_cents($t['total']) === 50);
$t = pos_cart_totals([['unit_price' => 0.05, 'qty' => 1]], 10);   // 0.5 cent → rounds half-up
check('totals: service charge rounds half-up to the cent', near($t['service_charge'], 0.01));
$t = pos_cart_totals([['line_total' => 12.34], ['line_total' => 7.66]], 12.5);
check('totals: accepts line_total rows; 12.5% of 20 = 2.50', near($t['subtotal'], 20) && near($t['service_charge'], 2.5) && near($t['total'], 22.5));
$t = pos_cart_totals([], 10);
check('totals: empty cart is zero', near($t['total'], 0));

// ── Line resolution ────────────────────────────────────────────────────────
$item = ['id' => 5, 'outlet_id' => 2, 'name' => 'Cap', 'kind' => 'product', 'price' => '28.00', 'is_active' => 't'];
$tour = ['price_amount' => '40.00', 'price_per_person' => 't'];
$l = pos_resolve_line($item + ['tour_id' => 9], $tour, 2, null);
check('line: item price beats tour price', is_array($l) && near($l['unit_price'], 28) && near($l['line_total'], 56));
$l = pos_resolve_line(['price' => null, 'tour_id' => 9] + $item, $tour, 3, null);
check('line: tour price used when item price is NULL', is_array($l) && near($l['unit_price'], 40) && near($l['line_total'], 120) && $l['per_person'] === true && $l['tour_id'] === 9);
$l = pos_resolve_line(['price' => null] + $item, null, 1, null);
check('line: no item/tour price and no open price → refused', is_string($l));
$l = pos_resolve_line(['price' => null] + $item, ['price_amount' => null], 1, 15.5);
check('line: open price used when both are NULL', is_array($l) && near($l['unit_price'], 15.5));
check('line: open price must be above zero', is_string(pos_resolve_line(['price' => null] + $item, null, 1, 0.0)));
check('line: open price over the cap refused', is_string(pos_resolve_line(['price' => null] + $item, null, 1, 2000000.0)));
check('line: qty 0 refused', is_string(pos_resolve_line($item, null, 0, null)));
check('line: negative qty refused', is_string(pos_resolve_line($item, null, -2, null)));
check('line: qty above the max refused', is_string(pos_resolve_line($item, null, POS_MAX_QTY + 1, null)));
check('line: inactive item refused', is_string(pos_resolve_line(['is_active' => 'f'] + $item, null, 1, null)));
$l = pos_resolve_line($item, null, 1, 999.0);
check('line: an open price never overrides a set item price', is_array($l) && near($l['unit_price'], 28));
$l = pos_resolve_line(['consignor_id' => 4, 'consignor_commission_pct' => '20.00'] + $item, null, 2, null);
check('line: consignment snapshots consignor + commission', is_array($l) && $l['consignor_id'] === 4 && near((float)$l['consignor_commission_pct'], 20) && $l['consignor_cost'] === null);
$l = pos_resolve_line(['consignor_id' => 4, 'consignor_commission_pct' => '20.00', 'consignor_cost' => '8.00'] + $item, null, 2, null);
check('line: a fixed consignor cost wins over the commission %', is_array($l) && near((float)$l['consignor_cost'], 8) && $l['consignor_commission_pct'] === null);
check('owed: 20% commission on 2×12 → supplier gets 19.20', near(pos_consignor_owed(['consignor_id' => 1, 'qty' => 2, 'line_total' => 24, 'consignor_commission_pct' => 20]), 19.2));
check('owed: fixed cost 8 × 3 → 24', near(pos_consignor_owed(['consignor_id' => 1, 'qty' => 3, 'line_total' => 60, 'consignor_cost' => 8]), 24));
check('owed: our own stock owes nothing', near(pos_consignor_owed(['consignor_id' => null, 'qty' => 3, 'line_total' => 60]), 0));

// ── Stock shortfall ────────────────────────────────────────────────────────
check('stock: enough on hand', pos_stock_shortfall(['track_stock' => 't', 'stock_qty' => 10, 'name' => 'Cap'], 10) === null);
check('stock: short → "Only 2 left"', (string)pos_stock_shortfall(['track_stock' => 't', 'stock_qty' => 2, 'name' => 'Cap'], 3) === 'Only 2 left of Cap.');
check('stock: none → out of stock', str_contains((string)pos_stock_shortfall(['track_stock' => 't', 'stock_qty' => 0, 'name' => 'Cap'], 1), 'out of stock'));
check('stock: untracked never short', pos_stock_shortfall(['track_stock' => 'f', 'stock_qty' => 0], 5) === null);
check('stock: allow_negative never short', pos_stock_shortfall(['track_stock' => 't', 'allow_negative' => 't', 'stock_qty' => 0], 5) === null);

// ── Room-charge eligibility ────────────────────────────────────────────────
$today  = '2026-09-26';
$outlet = ['allow_room_charge' => 't', 'venue_id' => 3, 'currency' => 'KES', 'charge_venue_ids' => []];
$hold   = ['status' => 'confirmed', 'expires_at' => null, 'check_in' => '2026-09-24', 'check_out' => '2026-09-30', 'venue_id' => 3, 'venue_name' => 'Tribal Dunes'];
check('room charge: in house today ✔', pos_room_charge_eligible($hold, $today, $outlet) === null);
check('room charge: arrival day ✔', pos_room_charge_eligible(['check_in' => $today] + $hold, $today, $outlet) === null);
check('room charge: departure day ✔', pos_room_charge_eligible(['check_out' => $today] + $hold, $today, $outlet) === null);
check('room charge: arrives tomorrow ✘', pos_room_charge_eligible(['check_in' => '2026-09-27'] + $hold, $today, $outlet) !== null);
check('room charge: checked out yesterday ✘', pos_room_charge_eligible(['check_out' => '2026-09-25'] + $hold, $today, $outlet) !== null);
check('room charge: pending web enquiry (has expiry) ✘', pos_room_charge_eligible(['status' => 'pending', 'expires_at' => '2026-09-27 10:00'] + $hold, $today, $outlet) !== null);
check('room charge: staff-typed pending (no expiry) ✔', pos_room_charge_eligible(['status' => 'pending', 'expires_at' => null] + $hold, $today, $outlet) === null);
check('room charge: cancelled ✘', pos_room_charge_eligible(['status' => 'cancelled'] + $hold, $today, $outlet) !== null);
check('room charge: no property list = guests of EVERY property ✔', pos_room_charge_eligible(['venue_id' => 4, 'venue_name' => 'Maya Ilai'] + $hold, $today, $outlet) === null);
check('room charge: property on the list ✔', pos_room_charge_eligible(['venue_id' => 4] + $hold, $today, ['charge_venue_ids' => [3, 4]] + $outlet) === null);
$why = (string) pos_room_charge_eligible(['venue_id' => 5, 'venue_name' => 'Zuri'] + $hold, $today, ['charge_venue_ids' => [3, 4]] + $outlet);
check('room charge: property not on the list ✘, names it', str_contains($why, 'Zuri'));
check('room charge: a KES outlet is NOT refused for currency (it converts)', pos_room_charge_eligible($hold, $today, ['currency' => 'KES'] + $outlet) === null);
check('room charge: outlet has room charge off ✘', pos_room_charge_eligible($hold, $today, ['allow_room_charge' => 'f'] + $outlet) !== null);

// ── VAT, tips, FX, signatures, consignment terms (v2) ─────────────────────
$t = pos_cart_totals([['line_total' => 1160]], 0, 16, true);
check('vat inclusive: 1160 incl. 16% → VAT 160, total unchanged', near($t['vat'], 160) && near($t['total'], 1160));
$t = pos_cart_totals([['line_total' => 1000]], 0, 16, false);
check('vat exclusive: 1000 + 16% → VAT 160, total 1160', near($t['vat'], 160) && near($t['total'], 1160));
$t = pos_cart_totals([['line_total' => 1000]], 10, 16, false);
check('vat exclusive on top of service: (1000+100) × 16% = 176 → 1276', near($t['service_charge'], 100) && near($t['vat'], 176) && near($t['total'], 1276));
$t = pos_cart_totals([['line_total' => 1000]], 10, 16, false, 50);
check('tip added last, no service/VAT on it → 1326', near($t['tip'], 50) && near($t['before_tip'], 1276) && near($t['total'], 1326));
$t = pos_cart_totals([['line_total' => 99.99]], 0, 16, true);
check('vat inclusive rounds half-up to the cent (99.99 → 13.79)', near($t['vat'], 13.79));
check('tip: 10% of 1276.00 = 127.60', pos_tip_cents(127600, 10, null) === 12760);
check('tip: typed amount', pos_tip_cents(127600, null, '50') === 5000);
check('tip: none', pos_tip_cents(127600, null, null) === 0);
check('tip: more than the bill refused', is_string(pos_tip_cents(1000, null, 11)));
check('tip: negative / >100% refused', is_string(pos_tip_cents(1000, null, -1)) && is_string(pos_tip_cents(1000, 150, null)));
$rates = ['USD' => 1.0, 'KES' => 129.0, 'EUR' => 0.92];
check('fx: KES per USD = 129', near((float)pos_fx_rate_from($rates, 'KES', 'USD'), 129));
check('fx: same currency = 1', pos_fx_rate_from($rates, 'USD', 'USD') === 1.0);
check('fx: missing rate → null', pos_fx_rate_from($rates, 'GBP', 'USD') === null);
check('fx: KES 1,500 → USD 11.63 (to the cent)', near(pos_fx_convert(1500, 129), 11.63));
$png = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\0", 24));
check('signature: a PNG data URL is valid', pos_valid_signature($png));
check('signature: junk / JPEG / empty refused', !pos_valid_signature('') && !pos_valid_signature('data:image/png;base64,AAAA') && !pos_valid_signature('data:image/jpeg;base64,' . base64_encode('xxxxxxxxxx')));
check('terms: our own stock', pos_consign_terms(['mode' => 'own'], [3]) === ['consignor_id' => null, 'consign_pct' => null, 'consignor_cost' => null]);
check('terms: commission 25%', pos_consign_terms(['mode' => 'commission', 'consignor_id' => 3, 'value' => '25'], [3]) === ['consignor_id' => 3, 'consign_pct' => 25.0, 'consignor_cost' => null]);
check('terms: fixed 800 per item', pos_consign_terms(['mode' => 'fixed', 'consignor_id' => 3, 'value' => '800'], [3]) === ['consignor_id' => 3, 'consign_pct' => null, 'consignor_cost' => 800.0]);
check('terms: unknown supplier / missing value / >100% refused', is_string(pos_consign_terms(['mode' => 'fixed', 'consignor_id' => 9, 'value' => 1], [3]))
    && is_string(pos_consign_terms(['mode' => 'commission', 'consignor_id' => 3, 'value' => ''], [3])) && is_string(pos_consign_terms(['mode' => 'commission', 'consignor_id' => 3, 'value' => 101], [3])));
$l = pos_resolve_line(['id' => 1, 'outlet_id' => 1, 'name' => 'Kikoy', 'price' => 100, 'is_active' => 't', 'consignor_id' => 3, 'consign_pct' => '30', 'consignor_commission_pct' => '20'], null, 1, null);
check('terms: the item\'s own % beats the supplier default', is_array($l) && near((float)$l['consignor_commission_pct'], 30));
check('bill label: carries the FX note and still ends with the ref', str_ends_with(pos_bill_label('Shop (Tribal Dunes)', [['name' => 'Cap', 'qty' => 1]], 'POS-SHOP-1', 'KES 1,500 @ 129 KES/USD'), '— KES 1,500 @ 129 KES/USD (POS-SHOP-1)'));

// ── Small helpers ──────────────────────────────────────────────────────────
check('ref prefix: salon-spa → SALONSPA', pos_ref_prefix('salon-spa') === 'SALONSPA');
check('ref prefix: capped at 10 chars', strlen(pos_ref_prefix('experiences-desk-north')) === 10);
check('slugify: "Salon & Spa" → salon-spa', pos_slugify('Salon & Spa') === 'salon-spa');
$lbl = pos_bill_label('Shop', [['name' => 'Cap', 'qty' => 2], ['name' => 'Coconut Water', 'qty' => 1]], 'POS-SHOP-1001');
check('bill label: summary + reference', $lbl === 'Shop: 2× Cap, Coconut Water (POS-SHOP-1001)');
$long = pos_bill_label('Shop', array_fill(0, 40, ['name' => 'Very long souvenir name', 'qty' => 1]), 'POS-SHOP-1002');
check('bill label: fits 200 chars and keeps the reference', mb_strlen($long) <= 200 && str_ends_with($long, '(POS-SHOP-1002)'));
check('uuid: accepts a v4 uuid', pos_valid_uuid('3f2b8c1e-9a4d-4e57-b2a1-0c9d8e7f6a5b'));
check('uuid: rejects junk', !pos_valid_uuid("x'; DROP") && !pos_valid_uuid('short'));
check('bool: pg "t"/"f"', pos_bool('t') && !pos_bool('f') && pos_bool(true) && !pos_bool(null));

// ── Admin item form validation ─────────────────────────────────────────────
[$v, $e] = pos_item_from_post(['name' => ' Cap ', 'kind' => 'product', 'price' => '28', 'category_id' => '3', 'is_active' => '1'], [3], [], []);
check('item form: clean product', !$e && $v['name'] === 'Cap' && $v['category_id'] === 3 && near((float)$v['price'], 28) && $v['is_active']);
[$v, $e] = pos_item_from_post(['name' => 'X', 'price' => ''], [], [], []);
check('item form: blank price stays NULL (tour / open price)', !$e && $v['price'] === null);
[$v, $e] = pos_item_from_post(['name' => 'X', 'category_id' => '99'], [3], [], []);
check('item form: a foreign category id is dropped', $v['category_id'] === null);
[$v, $e] = pos_item_from_post(['name' => 'X', 'tour_id' => '7'], [], [5], []);
check('item form: an unlisted activity is refused', isset($e['tour_id']) && $v['tour_id'] === null);
[$v, $e] = pos_item_from_post(['name' => 'X', 'consignor_id' => '8'], [], [], [4]);
check('item form: an unlisted supplier is refused', isset($e['consignor_id']));
[$v, $e] = pos_item_from_post(['name' => 'Massage', 'kind' => 'service', 'track_stock' => '1'], [], [], []);
check('item form: a service cannot track stock', isset($e['track_stock']));
[$v, $e] = pos_item_from_post(['name' => '', 'price' => '-1'], [], [], []);
check('item form: name required, negative price refused', isset($e['name'], $e['price']));
[$v, $e] = pos_item_from_post(['name' => 'X', 'track_stock' => '1', 'low_stock_at' => '2', 'allow_negative' => '1', 'consignor_id' => '4', 'consignor_cost' => '8'], [], [], [4]);
check('item form: stock + consignment fields kept', !$e && $v['low_stock_at'] === 2 && $v['allow_negative'] && near((float)$v['consignor_cost'], 8));
[$v, $e] = pos_item_from_post(['name' => 'X', 'low_stock_at' => '3', 'allow_negative' => '1'], [], [], []);
check('item form: stock fields cleared when stock is off', $v['low_stock_at'] === null && $v['allow_negative'] === false);

// ── PIN rules (P5) ─────────────────────────────────────────────────────────
check('pin: 4–6 digits accepted', pos_pin_problem('4830') === null && pos_pin_problem('590317') === null);
check('pin: too short / too long / letters refused', pos_pin_problem('123') !== null && pos_pin_problem('1234567') !== null && pos_pin_problem('12a4') !== null);
check('pin: repeated digit refused', pos_pin_problem('0000') !== null && pos_pin_problem('777777') !== null);
check('pin: straight runs refused', pos_pin_problem('1234') !== null && pos_pin_problem('4321') !== null && pos_pin_problem('56789') !== null);
check('pin: common PINs refused', pos_pin_problem('1212') !== null && pos_pin_problem('2580') !== null);
check('initials: two words', pos_initials('Amina Kariuki') === 'AK' && pos_initials('  jamal ') === 'J');
check('token: 64 hex, hashed with sha256', (bool)preg_match('/^[0-9a-f]{64}$/', pos_new_terminal_token()) && strlen(pos_token_hash('x')) === 64);

// ── DB-backed: the sale write path ─────────────────────────────────────────
try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    echo "\nSKIP  DB block (database unavailable: " . $e->getMessage() . ")\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}
if (!pos_supported()) {
    echo "\nSKIP  DB block (add_pos.sql not applied)\n";
    echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
    exit($failures ? 1 : 0);
}

db()->beginTransaction();
try {
    $sfx   = substr(bin2hex(random_bytes(4)), 0, 8);
    $cur   = strtoupper(setting('site_currency', 'USD'));
    $other = $cur === 'KES' ? 'USD' : 'KES';
    $today = frontdesk_today_ymd();
    $d     = fn(int $days) => (new DateTime($today))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    $ins   = function (string $sql, array $p = []): int { db_query($sql, $p); return (int) db()->lastInsertId(); };
    $uuid  = fn() => 'zz-' . bin2hex(random_bytes(12));
    $stock = fn(int $id) => (int) db_query('SELECT stock_qty FROM pos_items WHERE id = :i', [':i' => $id])->fetchColumn();
    $count = fn(string $sql, array $p = []) => (int) db_query($sql, $p)->fetchColumn();

    // Two properties, a room + unit each, an in-house booking at A and one at B.
    $vA = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Venue A')", [':s' => "zz-pos-a-{$sfx}"]);
    $vB = $ins("INSERT INTO venues (slug, name) VALUES (:s, 'ZZ Venue B')", [':s' => "zz-pos-b-{$sfx}"]);
    $rA = $ins("INSERT INTO rooms (slug, name, venue_id) VALUES (:s, 'ZZ Room A', :v)", [':s' => "zz-pos-ra-{$sfx}", ':v' => $vA]);
    $rB = $ins("INSERT INTO rooms (slug, name, venue_id) VALUES (:s, 'ZZ Room B', :v)", [':s' => "zz-pos-rb-{$sfx}", ':v' => $vB]);
    $uA = $ins("INSERT INTO units (room_id, name) VALUES (:r, 'ZZ Unit A')", [':r' => $rA]);
    $uB = $ins("INSERT INTO units (room_id, name) VALUES (:r, 'ZZ Unit B')", [':r' => $rB]);
    $hA = $ins("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
                VALUES (:u, :ci, :co, 'ZZ Emma Carter', 'zz@example.com', 'confirmed', NULL)", [':u' => $uA, ':ci' => $d(-2), ':co' => $d(3)]);
    $hB = $ins("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
                VALUES (:u, :ci, :co, 'ZZ Other Guest', 'zz2@example.com', 'confirmed', NULL)", [':u' => $uB, ':ci' => $d(-1), ':co' => $d(2)]);
    $hWeb = $ins("INSERT INTO holds (unit_id, check_in, check_out, guest_name, guest_email, status, expires_at)
                VALUES (:u, :ci, :co, 'ZZ Web Enquiry', 'zz3@example.com', 'pending', NOW() + INTERVAL '1 day')", [':u' => $uA, ':ci' => $d(0), ':co' => $d(2)]);
    $gLead = $ins("INSERT INTO checkin_guests (hold_id, is_lead, passport_name) VALUES (:h, TRUE, 'ZZ Emma Carter')", [':h' => $hA]);
    $g2    = $ins("INSERT INTO checkin_guests (hold_id, is_lead, passport_name) VALUES (:h, FALSE, 'ZZ Liam Carter')", [':h' => $hA]);
    $gB    = $ins("INSERT INTO checkin_guests (hold_id, is_lead, passport_name) VALUES (:h, TRUE, 'ZZ Other Guest')", [':h' => $hB]);

    // People.
    $mkUser = fn(string $role, string $tag) => $ins("INSERT INTO admin_users (email, role, name, is_active) VALUES (:e, :r, :n, TRUE)",
        [':e' => "zz-pos-{$tag}-{$sfx}@example.com", ':r' => $role, ':n' => "ZZ {$tag}"]);
    $owner   = $mkUser('owner', 'owner');
    $manager = $mkUser('manager', 'manager');
    $amina   = $mkUser('staff', 'shopstaff');
    $stranger= $mkUser('staff', 'stranger');
    db_query('INSERT INTO admin_user_venues (admin_user_id, venue_id) VALUES (:u, :v)', [':u' => $manager, ':v' => $vA]);

    // Outlets.
    $mkOutlet = fn(string $name, string $slug, string $kind, ?int $venue, string $curr, float $svc) => $ins(
        "INSERT INTO pos_outlets (name, slug, kind, venue_id, currency, service_charge_pct) VALUES (:n, :s, :k, :v, :c, :p)",
        [':n' => $name, ':s' => $slug . '-' . $sfx, ':k' => $kind, ':v' => $venue, ':c' => $curr, ':p' => $svc]);
    $shop = $mkOutlet('ZZ Shop', 'zzshop', 'shop', $vA, $cur, 0);
    $exp  = $mkOutlet('ZZ Experiences', 'zzexp', 'experiences', null, $cur, 10);
    $kite = $mkOutlet('ZZ Kite', 'zzkite', 'kite', null, $cur, 0);
    $kes  = $mkOutlet('ZZ Market', 'zzmarket', 'shop', null, $other, 0);
    db_query('INSERT INTO pos_outlet_links (outlet_id, source_outlet_id) VALUES (:o, :s)', [':o' => $exp, ':s' => $kite]);
    db_query('INSERT INTO pos_outlet_staff (outlet_id, admin_user_id) VALUES (:o, :u)', [':o' => $shop, ':u' => $amina]);

    // Catalogue.
    $cons  = $ins("INSERT INTO pos_consignors (name, commission_pct) VALUES ('ZZ Mama Neema', 20)");
    $tourId = $ins("INSERT INTO tours (slug, name, price_amount, price_per_person) VALUES (:s, 'ZZ Snorkelling', 40, TRUE)", [':s' => "zz-pos-tour-{$sfx}"]);
    $cap   = $ins("INSERT INTO pos_items (outlet_id, name, kind, price, track_stock, stock_qty) VALUES (:o, 'ZZ Cap', 'product', 28, TRUE, 10)", [':o' => $shop]);
    $brace = $ins("INSERT INTO pos_items (outlet_id, name, kind, price, track_stock, stock_qty, consignor_id) VALUES (:o, 'ZZ Bracelet', 'product', 12, TRUE, 9, :c)", [':o' => $shop, ':c' => $cons]);
    $lesson= $ins("INSERT INTO pos_items (outlet_id, name, kind, price) VALUES (:o, 'ZZ Kite Taster', 'service', 120)", [':o' => $kite]);
    $snork = $ins("INSERT INTO pos_items (outlet_id, name, kind, price, tour_id) VALUES (:o, 'ZZ Snorkelling', 'service', NULL, :t)", [':o' => $exp, ':t' => $tourId]);
    $req   = $ins("INSERT INTO pos_items (outlet_id, name, kind, price) VALUES (:o, 'ZZ Private Charter', 'service', NULL)", [':o' => $exp]);
    $kesTee= $ins("INSERT INTO pos_items (outlet_id, name, kind, price) VALUES (:o, 'ZZ Tee', 'product', 1500)", [':o' => $kes]);

    $v2  = pos_v2_supported();
    $sig = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\0", 24));
    $sale = fn(int $outlet, array $lines, string $pay, array $cust, int $user, ?string $key = null, array $extra = []) => pos_complete_sale([
        'outlet_id' => $outlet, 'client_uuid' => $key ?? $uuid(), 'lines' => $lines,
        'payment_method' => $pay, 'customer' => $cust,
        // Room charges carry the guest's signature unless a test says otherwise.
        'signature' => $pay === 'room_charge' ? $sig : null,
    ] + $extra, $user);
    $walkin = ['type' => 'walkin'];

    // Permissions.
    check('perm: owner sees every outlet', count(array_intersect([$shop, $exp, $kite, $kes], pos_user_outlet_ids(pos_user($owner)))) === 4);
    $mgrOutlets = pos_user_outlet_ids(pos_user($manager));
    check('perm: manager sees their venue outlet, not venue-less ones unassigned', in_array($shop, $mgrOutlets, true) && !in_array($exp, $mgrOutlets, true));
    check('perm: staff sees only the assigned outlet', pos_user_outlet_ids(pos_user($amina)) === [$shop]);
    check('perm: can_sell cross-sold item via link', pos_can_sell_item(pos_user($owner), $exp, $lesson));
    check('perm: cannot sell another outlet\'s item without a link', !pos_can_sell_item(pos_user($owner), $shop, $lesson));

    $mgrManage = pos_manageable_outlet_ids(pos_user($manager));
    check('manage: manager manages their venue outlet only', in_array($shop, $mgrManage, true) && !in_array($exp, $mgrManage, true));
    check('manage: staff manage nothing', pos_manageable_outlet_ids(pos_user($amina)) === []);
    db_query('UPDATE pos_outlets SET is_active = FALSE WHERE id = :o', [':o' => $kes]);
    check('manage: owner still manages a closed outlet', in_array($kes, pos_manageable_outlet_ids(pos_user($owner)), true));
    check('perm: a closed outlet is not sellable', !in_array($kes, pos_user_outlet_ids(pos_user($owner)), true));
    db_query('UPDATE pos_outlets SET is_active = TRUE WHERE id = :o', [':o' => $kes]);

    // 1. Sell 2 of 10 → stock 8 + one sale move.
    $r = $sale($shop, [['item_id' => $cap, 'qty' => 2]], 'cash', $walkin, $amina);
    check('sale: assigned staff sells 2 caps', $r['ok'] === true);
    $s1 = $r['sale'] ?? [];
    check('sale: total re-priced server-side (2 × 28 = 56)', near((float)($s1['total'] ?? 0), 56));
    check('sale: stock 10 → 8', $stock($cap) === 8);
    check('sale: one "sale" stock move of -2', $count("SELECT COUNT(*) FROM pos_stock_moves WHERE item_id = :i AND reason = 'sale' AND qty_delta = -2 AND sale_id = :s", [':i' => $cap, ':s' => (int)($s1['id'] ?? 0)]) === 1);
    check('sale: reference is POS-<OUTLET>-1001', ($s1['reference'] ?? '') === 'POS-' . pos_ref_prefix("zzshop-{$sfx}") . '-1001');
    check('sale: walk-in with no name is "Walk-in"', ($s1['customer_name'] ?? '') === 'Walk-in');

    // 2. Oversell refused, nothing written.
    $salesBefore = $count('SELECT COUNT(*) FROM pos_sales WHERE outlet_id = :o', [':o' => $shop]);
    $r = $sale($shop, [['item_id' => $cap, 'qty' => 5], ['item_id' => $cap, 'qty' => 4]], 'cash', $walkin, $amina);
    check('sale: 9 of 8 (split across two lines) refused', $r['ok'] === false && str_contains($r['error'], 'Only 8 left'));
    check('sale: refused sale wrote nothing', $stock($cap) === 8 && $count('SELECT COUNT(*) FROM pos_sales WHERE outlet_id = :o', [':o' => $shop]) === $salesBefore);
    check('sale: outer transaction still usable after a refusal', $count('SELECT 1') === 1);

    // 3. Idempotency.
    $key = $uuid();
    $a = $sale($shop, [['item_id' => $cap, 'qty' => 1]], 'card', $walkin, $amina, $key);
    $b = $sale($shop, [['item_id' => $cap, 'qty' => 1]], 'card', $walkin, $amina, $key);
    check('idempotency: same client_uuid twice → one sale', $a['ok'] && $b['ok'] && $b['duplicate'] === true && (int)$a['sale']['id'] === (int)$b['sale']['id']);
    check('idempotency: stock taken once', $stock($cap) === 7);
    check('idempotency: receipt numbers are sequential', ($a['sale']['reference'] ?? '') === 'POS-' . pos_ref_prefix("zzshop-{$sfx}") . '-1002');

    // 4. Client-posted prices are ignored.
    $r = $sale($shop, [['item_id' => $cap, 'qty' => 1, 'unit_price' => 0.01, 'price' => 0.01]], 'card', $walkin, $amina);
    check('pricing: a posted unit_price is ignored (28, not 0.01)', $r['ok'] && near((float)$r['sale']['total'], 28));

    // 5. Permission refusals.
    $r = $sale($shop, [['item_id' => $cap, 'qty' => 1]], 'cash', $walkin, $stranger);
    check('perm: unassigned staff refused', $r['ok'] === false);
    $r = $sale($shop, [['item_id' => $lesson, 'qty' => 1]], 'cash', $walkin, $owner);
    check('perm: item from an unlinked outlet refused', $r['ok'] === false);

    // 6. Cross-sell + service charge.
    $r = $sale($exp, [['item_id' => $lesson, 'qty' => 1]], 'card', $walkin, $owner);
    check('cross-sell: Experiences sells a Kite lesson', $r['ok'] === true);
    check('cross-sell: owning_outlet_id is the Kite outlet', (int)($r['sale']['lines'][0]['owning_outlet_id'] ?? 0) === $kite);
    check('cross-sell: 10% service on 120 → 132', near((float)($r['sale']['service_charge'] ?? 0), 12) && near((float)($r['sale']['total'] ?? 0), 132));

    // 7. Tour-linked price, open price.
    $r = $sale($exp, [['item_id' => $snork, 'qty' => 3]], 'card', $walkin, $owner);
    check('tour link: 3 pax × tour price 40 = 120 (+10%)', $r['ok'] && near((float)$r['sale']['subtotal'], 120) && (int)$r['sale']['lines'][0]['tour_id'] === $tourId);
    $r = $sale($exp, [['item_id' => $req, 'qty' => 1]], 'card', $walkin, $owner);
    check('open price: missing → refused', $r['ok'] === false);
    $r = $sale($exp, [['item_id' => $req, 'qty' => 1, 'open_price' => 250]], 'card', $walkin, $owner);
    check('open price: 250 accepted', $r['ok'] && near((float)$r['sale']['subtotal'], 250));

    // 8. Consignment snapshot.
    $r = $sale($shop, [['item_id' => $brace, 'qty' => 2]], 'cash', ['type' => 'walkin', 'name' => 'ZZ Walker', 'phone' => '0700'], $amina);
    $ln = $r['sale']['lines'][0] ?? [];
    check('consignment: line snapshots consignor + 20% commission', (int)($ln['consignor_id'] ?? 0) === $cons && near((float)($ln['consignor_commission_pct'] ?? -1), 20));
    check('consignment: supplier is owed 19.20 of 24', near(pos_consignor_owed($ln), 19.2));
    check('walk-in: a named walk-in is saved for next time', (int)($r['sale']['pos_customer_id'] ?? 0) > 0 && ($r['sale']['customer_name'] ?? '') === 'ZZ Walker');
    // Commission edited later doesn't rewrite history.
    db_query('UPDATE pos_consignors SET commission_pct = 50 WHERE id = :c', [':c' => $cons]);
    $again = pos_fetch_sale((int)$r['sale']['id']);
    check('consignment: later commission edits never rewrite the sale', near((float)$again['lines'][0]['consignor_commission_pct'], 20));

    // 9. Room charge.
    $billBefore = bill_total($hA);
    $rc = $sale($shop, [['item_id' => $cap, 'qty' => 2]], 'room_charge', ['type' => 'inhouse', 'hold_id' => $hA, 'guest_id' => $g2], $amina);
    check('room charge: in-house guest at this property', $rc['ok'] === true);
    if (!$rc['ok']) echo '      → ' . ($rc['error'] ?? '') . "\n";
    $rcId = (int)($rc['sale']['id'] ?? 0);
    $rows = db_query('SELECT * FROM bill_items WHERE pos_sale_id = :s', [':s' => $rcId])->fetchAll();
    check('room charge: exactly one bill_items row linked to the sale', count($rows) === 1);
    check('room charge: bill row attributed to the chosen guest', !bill_item_guest_supported() || (int)($rows[0]['guest_id'] ?? 0) === $g2);
    check('room charge: bill label carries the reference', str_ends_with((string)($rows[0]['label'] ?? ''), '(' . ($rc['sale']['reference'] ?? '?') . ')'));
    check('room charge: bill_total rises by the sale total', near(bill_total($hA) - $billBefore, 56));
    check('room charge: sale snapshots the guest name', ($rc['sale']['customer_name'] ?? '') === 'ZZ Liam Carter');

    $r = $sale($shop, [['item_id' => $cap, 'qty' => 1]], 'room_charge', $walkin, $amina);
    check('room charge: walk-in refused', $r['ok'] === false);
    $r = $sale($exp, [['item_id' => $lesson, 'qty' => 1]], 'room_charge', ['type' => 'inhouse', 'hold_id' => $hB, 'guest_id' => $gB], $owner);
    check('room charge: shared outlet charges any property\'s guest', $r['ok'] === true);
    $r = $sale($shop, [['item_id' => $cap, 'qty' => 1]], 'room_charge', ['type' => 'inhouse', 'hold_id' => $hWeb], $owner);
    check('room charge: pending web enquiry refused', $r['ok'] === false);
    $r = $sale($shop, [['item_id' => $cap, 'qty' => 1]], 'room_charge', ['type' => 'inhouse', 'hold_id' => $hA, 'guest_id' => $gB], $owner);
    check('room charge: guest from another booking refused', $r['ok'] === false);
    if ($v2) {
        // Property list: by default any property's guest may charge at the shop (venue A)…
        $r = $sale($shop, [['item_id' => $cap, 'qty' => 1]], 'room_charge', ['type' => 'inhouse', 'hold_id' => $hB, 'guest_id' => $gB], $owner);
        check('room charge: a guest of ANOTHER property charges by default', $r['ok'] === true && (int)($r['sale']['guest_venue_id'] ?? 0) === $vB);
        $lbl = (string) db_query('SELECT label FROM bill_items WHERE pos_sale_id = :s', [':s' => (int)($r['sale']['id'] ?? 0)])->fetchColumn();
        check('room charge: the bill line says where it was bought', str_starts_with($lbl, 'ZZ Shop (ZZ Venue A):'));
        // …and once the shop limits it to venue A, venue B's guest is refused.
        db_query('INSERT INTO pos_outlet_charge_venues (outlet_id, venue_id) VALUES (:o, :v)', [':o' => $shop, ':v' => $vA]);
        $r = $sale($shop, [['item_id' => $cap, 'qty' => 1]], 'room_charge', ['type' => 'inhouse', 'hold_id' => $hB, 'guest_id' => $gB], $owner);
        check('room charge: property not ticked for the outlet → refused, named', $r['ok'] === false && str_contains($r['error'], 'ZZ Venue B'));
        check('in-house search: limited to the ticked properties', !array_filter(pos_inhouse_payload(pos_fetch_outlet($shop), ''), fn($x) => $x['hold_id'] === $hB));
        db_query('DELETE FROM pos_outlet_charge_venues WHERE outlet_id = :o', [':o' => $shop]);
        $found = array_values(array_filter(pos_inhouse_payload(pos_fetch_outlet($shop), ''), fn($x) => $x['hold_id'] === $hB));
        check('in-house search: another property\'s guest is flagged', count($found) === 1 && $found[0]['other_property'] === true && $found[0]['venue'] === 'ZZ Venue B');

        // Signature is required for a room charge by default.
        $r = pos_complete_sale(['outlet_id' => $shop, 'client_uuid' => $uuid(), 'lines' => [['item_id' => $cap, 'qty' => 1]],
                                'payment_method' => 'room_charge', 'customer' => ['type' => 'inhouse', 'hold_id' => $hA]], $owner);
        check('signature: room charge without one refused', $r['ok'] === false && str_contains($r['error'], 'sign'));
        $rs = pos_sale_signature($rcId);
        check('signature: stored with the signer name', $rs && $rs['signature'] === $sig && $rs['signer_name'] === 'ZZ Liam Carter');
        check('signature: the sale knows it was signed', pos_fetch_sale($rcId)['signed'] === true);

        // A KES (or other-currency) outlet posts to the bill converted, rate kept.
        $rate = pos_fx_rate($other, $cur);
        $r = $sale($kes, [['item_id' => $kesTee, 'qty' => 1]], 'room_charge', ['type' => 'inhouse', 'hold_id' => $hA, 'guest_id' => $gLead], $owner);
        check('fx: other-currency outlet CAN room-charge', $r['ok'] === true);
        $bi = db_query('SELECT amount, label FROM bill_items WHERE pos_sale_id = :s', [':s' => (int)($r['sale']['id'] ?? 0)])->fetch();
        check('fx: bill line is the converted amount in the bill currency', $rate && $bi && near((float)$bi['amount'], pos_fx_convert(1500, $rate)));
        check('fx: sale keeps the rate, bill currency and amount', $rate && near((float)$r['sale']['fx_rate'], $rate) && $r['sale']['bill_currency'] === $cur && near((float)$r['sale']['bill_amount'], pos_fx_convert(1500, $rate)));
        check('fx: bill label shows the original amount and rate', $bi && str_contains((string)$bi['label'], '@'));
        check('catalog: other-currency outlet offers room charge with its rate', ($c = pos_catalog_payload(pos_fetch_outlet($kes))['outlet'])['room_charge'] === true && near((float)$c['fx_rate'], (float)$rate));

        // VAT + service + tip, server-computed.
        db_query('UPDATE pos_outlets SET vat_pct = 16, vat_inclusive = FALSE, tips_enabled = TRUE WHERE id = :o', [':o' => $exp]);
        $r = $sale($exp, [['item_id' => $lesson, 'qty' => 1]], 'card', $walkin, $owner, null, ['tip_pct' => 10]);
        // 120 + 10% service = 132; + 16% VAT = 21.12 → 153.12; tip 10% = 15.31 → 168.43
        check('vat+tip: 120 +svc 12 +VAT 21.12 +tip 15.31 = 168.43', $r['ok'] && near((float)$r['sale']['vat_amount'], 21.12) && near((float)$r['sale']['tip_amount'], 15.31) && near((float)$r['sale']['total'], 168.43));
        check('vat+tip: the sale snapshots the rates', $r['ok'] && near((float)$r['sale']['vat_pct'], 16) && !pos_bool($r['sale']['vat_inclusive']) && near((float)$r['sale']['service_pct'], 10));
        db_query('UPDATE pos_outlets SET tips_enabled = FALSE WHERE id = :o', [':o' => $exp]);
        $r = $sale($exp, [['item_id' => $lesson, 'qty' => 1]], 'card', $walkin, $owner, null, ['tip_amount' => 5]);
        check('tips off: a tip is refused', $r['ok'] === false);
        db_query('UPDATE pos_outlets SET vat_pct = 0, vat_inclusive = TRUE, tips_enabled = TRUE WHERE id = :o', [':o' => $exp]);

        // Consignment terms chosen when the delivery arrives.
        $cons2 = $ins("INSERT INTO pos_consignors (name, commission_pct) VALUES ('ZZ Watamu Weavers', 15)");
        pos_stock_receive($cap, 4, 6.0, 'Kikoy delivery', $manager, pos_consign_terms(['mode' => 'fixed', 'consignor_id' => $cons2, 'value' => '9'], [$cons2]));
        $mv = db_query("SELECT consignor_id, consignor_cost FROM pos_stock_moves WHERE item_id = :i AND reason = 'receive' ORDER BY id DESC LIMIT 1", [':i' => $cap])->fetch();
        check('delivery terms: the ledger row records supplier + fixed cost', $mv && (int)$mv['consignor_id'] === $cons2 && near((float)$mv['consignor_cost'], 9));
        $r = $sale($shop, [['item_id' => $cap, 'qty' => 1]], 'cash', $walkin, $owner);
        check('delivery terms: the next sale owes the fixed 9 per item', $r['ok'] && near(pos_consignor_owed($r['sale']['lines'][0]), 9));
        pos_stock_receive($cap, 1, null, '', $manager, pos_consign_terms(['mode' => 'own'], [$cons2]));
        check('delivery terms: switching back to our own stock clears the supplier', db_query('SELECT consignor_id FROM pos_items WHERE id = :i', [':i' => $cap])->fetchColumn() === null);
    } else {
        echo "SKIP  v2 (add_pos_v2.sql not applied)\n";
    }
    db_query('UPDATE pos_outlets SET allow_room_charge = FALSE WHERE id = :o', [':o' => $shop]);
    $r = $sale($shop, [['item_id' => $cap, 'qty' => 1]], 'room_charge', ['type' => 'inhouse', 'hold_id' => $hA], $owner);
    check('room charge: outlet with room charge off refused', $r['ok'] === false);
    db_query('UPDATE pos_outlets SET allow_room_charge = TRUE WHERE id = :o', [':o' => $shop]);

    // 10. Void.
    $capBefore = $stock($cap);
    $v = pos_void_sale($rcId, 'wrong guest', $amina);
    check('void: staff cannot void', $v['ok'] === false);
    $v = pos_void_sale($rcId, '', $manager);
    check('void: reason required', $v['ok'] === false);
    $billPreVoid = bill_total($hA);
    $v = pos_void_sale($rcId, 'wrong guest', $manager);
    check('void: manager of the outlet voids', $v['ok'] === true && ($v['sale']['status'] ?? '') === 'voided');
    check('void: stock restored', $stock($cap) === $capBefore + 2);
    check('void: bill line removed', $count('SELECT COUNT(*) FROM bill_items WHERE pos_sale_id = :s', [':s' => $rcId]) === 0);
    check('void: bill_total drops by exactly the voided charge', near($billPreVoid - bill_total($hA), 56));
    check('void: one "void" stock move', $count("SELECT COUNT(*) FROM pos_stock_moves WHERE sale_id = :s AND reason = 'void'", [':s' => $rcId]) === 1);
    check('void: cannot void twice', pos_void_sale($rcId, 'again', $manager)['ok'] === false);

    // 11. Stock admin.
    $n = pos_stock_receive($cap, 5, 9.5, 'delivery', $manager);
    check('stock: receive 5', $n === $stock($cap));
    $n = pos_stock_adjust($cap, 3, 'stock take', $manager);
    check('stock: adjust to the counted 3', $n === 3 && $stock($cap) === 3);
    $threw = false; try { pos_stock_adjust($cap, 2, '', $manager); } catch (PosRefusal $e) { $threw = true; }
    check('stock: adjust needs a reason', $threw && $stock($cap) === 3);
    $threw = false; try { pos_stock_receive($cap, 0, null, '', $manager); } catch (PosRefusal $e) { $threw = true; }
    check('stock: receive needs qty ≥ 1', $threw);
    $ledger = (int) db_query('SELECT COALESCE(SUM(qty_delta),0) FROM pos_stock_moves WHERE item_id = :i', [':i' => $cap])->fetchColumn();
    check('stock: cached stock_qty equals 10 + the ledger', 10 + $ledger === $stock($cap));

    // 12. In-house search.
    $found = pos_inhouse_search([$vA], 'emma', $today);
    $hit = array_values(array_filter($found, fn($h) => (int)$h['id'] === $hA));
    check('in-house: found by guest name, scoped to the venue', count($hit) === 1);
    check('in-house: carries the adults on the booking', count($hit[0]['guests'] ?? []) === 2);
    check('in-house: other venue excluded', !array_filter(pos_inhouse_search([$vA], '', $today), fn($h) => (int)$h['id'] === $hB));
    check('in-house: web enquiry excluded', !array_filter(pos_inhouse_search(null, '', $today), fn($h) => (int)$h['id'] === $hWeb));

    // 13. PINs + terminals (P5).
    check('pin: set refuses a trivial PIN', pos_set_pin($amina, '1234') !== null);
    check('pin: set stores a hash, not the PIN', pos_set_pin($amina, '4830') === null
        && ($h = (string) db_query('SELECT pos_pin_hash FROM admin_users WHERE id = :i', [':i' => $amina])->fetchColumn()) !== '4830' && password_verify('4830', $h));
    for ($i = 0; $i < POS_PIN_MAX_FAILS; $i++) {
        db_query("INSERT INTO login_attempts (email, ip_address, success) VALUES (:e, '10.9.9.9', FALSE)", [':e' => 'pos:' . $amina]);
    }
    check('pin: locked out after the max wrong tries', pos_pin_locked_out($amina, '10.1.1.1'));
    check('pin: another user is not locked by it', !pos_pin_locked_out($manager, '10.1.1.1'));
    check('pin: wrong PINs never lock ADMIN logins from the same IP', !is_rate_limited('zz-nobody@example.com', '10.9.9.9'));
    pos_set_pin($amina, '4830');
    check('pin: a new PIN clears the lockout', !pos_pin_locked_out($amina, '10.1.1.1'));
    db_query("INSERT INTO pos_terminals (name, token_hash) VALUES ('ZZ Tablet', :h)", [':h' => pos_token_hash('zz' . $sfx)]);
    $term = db_query('SELECT * FROM pos_terminals WHERE id = :i', [':i' => (int) db()->lastInsertId()])->fetch();
    check('terminal: no outlets → nobody can unlock it', pos_terminal_people($term) === []);
    db_query('INSERT INTO pos_terminal_outlets (terminal_id, outlet_id) VALUES (:t, :o)', [':t' => (int)$term['id'], ':o' => $shop]);
    $ppl = array_column(pos_terminal_people($term), 'id');
    check('terminal: assigned staff with a PIN appear on the lock screen', in_array($amina, $ppl, true));
    check('terminal: people without a PIN do not appear', !in_array($stranger, $ppl, true) && !in_array($manager, $ppl, true));

    // Job types (P5): shop/spa/kite staff land on the till and get no guest messaging.
    if (pos_jobs_supported()) {
        $kiteGuy = $ins("INSERT INTO admin_users (email, role, name, job_type, is_active) VALUES (:e, 'staff', 'ZZ Kite Guy', 'kite', TRUE)", [':e' => "zz-pos-kite-{$sfx}@example.com"]);
        $prevSession = $_SESSION['admin_id'] ?? null;
        $_SESSION['admin_id'] = $kiteGuy;
        check('jobs: kite staff is a POS job', job_is_pos(admin_job()) && !job_is_ops(admin_job()));
        check('jobs: kite staff home is the till', admin_home_url() === '/pos/');
        if ($prevSession === null) unset($_SESSION['admin_id']); else $_SESSION['admin_id'] = $prevSession;
    } else {
        echo "SKIP  jobs (add_pos_job_types.sql not applied)
";
    }

    // 14. Till payloads (P3).
    $cat = pos_catalog_payload(pos_fetch_outlet($exp));
    $ids = array_column($cat['items'], 'id');
    check('catalog: own + cross-sold items, with the source outlet as a chip', in_array($snork, $ids, true) && in_array($lesson, $ids, true) && array_column($cat['sources'], 'id') === [$kite]);
    $row = array_values(array_filter($cat['items'], fn($x) => $x['id'] === $snork))[0];
    check('catalog: a linked activity shows the tour price, per person', near((float)$row['price'], 40) && $row['per_person'] === true);
    $row = array_values(array_filter($cat['items'], fn($x) => $x['id'] === $req))[0];
    check('catalog: an unpriced item shows price null (open price)', $row['price'] === null);
    check('catalog: room charge allowed for a same-currency outlet', $cat['outlet']['room_charge'] === true);
    check('catalog: a foreign-currency outlet can room-charge (converted) once v2 is in', pos_catalog_payload(pos_fetch_outlet($kes))['outlet']['room_charge'] === $v2);
    $ih = pos_inhouse_payload(pos_fetch_outlet($shop), 'carter');
    $mine = array_values(array_filter($ih, fn($x) => $x['hold_id'] === $hA));
    check('in-house payload: finds the guest with adults and no room-charge block', count($mine) === 1 && count($mine[0]['guests']) === 2 && $mine[0]['room_charge_block'] === null);

    // 15. Reports (P6).
    $q = pos_sales_query(['outlet_ids' => [$shop], 'from' => $today, 'to' => $today]);
    check('sales query: finds the shop sales, voided included', $q['total'] >= 5 && in_array($rcId, array_map(fn($r) => (int)$r['id'], $q['rows']), true));
    $sumCur = array_column($q['sums'], null, 'currency');
    $expected = (float) db_query("SELECT SUM(total) FROM pos_sales WHERE outlet_id = :o AND status = 'completed'", [':o' => $shop])->fetchColumn();
    check('sales query: per-currency sum excludes voids', isset($sumCur[$cur]) && near((float)$sumCur[$cur]['total'], $expected));
    check('sales query: status filter', pos_sales_query(['outlet_ids' => [$shop], 'from' => $today, 'to' => $today, 'status' => 'voided'])['total'] === 1);
    check('sales query: empty scope returns nothing', pos_sales_query(['outlet_ids' => []])['total'] === 0);
    $z = pos_z_report([$shop, $exp], $today);
    $zShopCash = array_values(array_filter($z['by_method'], fn($r) => (int)$r['outlet_id'] === $shop && $r['payment_method'] === 'cash'));
    check('z-report: cash at the shop is broken out', count($zShopCash) === 1 && (int)$zShopCash[0]['n'] >= 2);
    check('z-report: the voided room charge is counted as a void', array_sum(array_map(fn($v) => (int)$v['n'], $z['voids'])) === 1);
    check('z-report: cross-sold lesson attributed to the Kite outlet', (bool) array_filter($z['items'], fn($r) => $r['name'] === 'ZZ Kite Taster' && $r['outlet'] === 'ZZ Kite'));
    $st = array_values(array_filter(pos_consignment_statement($today, $today), fn($r) => $r['consignor_id'] === $cons));
    check('statement: owed from the snapshotted 20% (not the edited 50%)', count($st) === 1 && $st[0]['qty'] === 2 && near($st[0]['owed'], 19.2) && near($st[0]['kept'], 4.8));
    pos_record_payout($cons, $today, $today, 10, $cur, 'part', $owner);
    $st = array_values(array_filter(pos_consignment_statement($today, $today), fn($r) => $r['consignor_id'] === $cons));
    check('statement: a payout reduces the balance', near($st[0]['paid'], 10) && near($st[0]['balance'], 9.2));
    $threw = false; try { pos_record_payout($cons, $today, $today, 0, $cur, '', $owner); } catch (PosRefusal $e) { $threw = true; }
    check('statement: a zero payout is refused', $threw);
    db_query('UPDATE pos_items SET low_stock_at = 5 WHERE id = :i', [':i' => $cap]);
    check('low stock: not listed while above the alert', !array_filter(pos_low_stock([$exp]), fn($r) => (int)$r['id'] === $cap));
    check('low stock: an out/low item is listed', (bool) array_filter(pos_low_stock([$shop]), fn($r) => (int)$r['id'] === $cap));
} catch (Throwable $e) {
    echo "FAIL  DB block threw: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
