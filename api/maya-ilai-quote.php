<?php
/**
 * Public Maya Ilai quote endpoint (JSON, read-only).
 * POST a room/guest selection → returns the priced breakdown from the SAME
 * server-side calculation the staff tool uses (maya_ilai_quote over the saved
 * config). No writes, no auth — it only prices; booking happens via the enquiry.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/maya-ilai-pricing.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'Method not allowed'])); }

if (!maya_ilai_pricing_supported()) { http_response_code(503); exit(json_encode(['ok'=>false,'error'=>'Pricing unavailable.'])); }

$data = json_decode(file_get_contents('php://input'), true) ?? [];

/*
 * Mode 1 — "how many of us are there?" Returns the handful of configurations
 * that actually sleep the party, cheapest first, each already priced by the SAME
 * maya_ilai_quote() the single-selection mode below uses. Every suggestion is
 * quoted error-free before it is returned, so nothing here can propose a stay
 * the guest cannot book.
 *
 * Each suggestion also carries `photo` — ['url','alt','source'] or null — which
 * maya_ilai_suggest() resolves server-side from `room_images` (the dominant
 * product's room), falling back to the venue's own photo and then to nothing.
 * It travels in the payload with everything else so the surface renders what it
 * is handed instead of guessing which room a configuration is of. A null photo
 * is normal: the card drops to its single-column layout.
 *
 * Public and unauthenticated, so both inputs are bounded before any search runs:
 * the party at what the compound can physically sleep, the stay at a month.
 * Rubbish in gets a 422, never a search.
 */
if (($data['mode'] ?? '') === 'suggest') {
    $cfg       = maya_ilai_pricing_get();
    $maxParty  = maya_ilai_max_party($cfg);
    $rawGuests = $data['guests'] ?? null;
    $rawNights = $data['nights'] ?? null;
    if (!is_numeric($rawGuests) || !is_numeric($rawNights)) {
        http_response_code(422);
        exit(json_encode(['ok'=>false, 'error'=>'Tell us how many guests and how many nights.']));
    }
    $guests = (int)$rawGuests;
    $nights = (int)$rawNights;
    if ($guests < 1 || $guests > $maxParty) {
        http_response_code(422);
        exit(json_encode(['ok'=>false, 'error'=>"Party size must be between 1 and {$maxParty} guests.",
                          'maxGuests'=>$maxParty]));
    }
    if ($nights < 1 || $nights > 30) {
        http_response_code(422);
        exit(json_encode(['ok'=>false, 'error'=>'Stays run from 1 to 30 nights.']));
    }
    echo json_encode([
        'ok'          => true,
        'guests'      => $guests,
        'nights'      => $nights,
        'maxGuests'   => $maxParty,
        'minNights'   => (int)$cfg['rules']['minNights'],
        'suggestions' => maya_ilai_suggest($guests, $nights, $cfg, max(1, min(8, (int)($data['limit'] ?? 5)))),
    ]);
    exit;
}

// Mode 2 — price one posted selection (the full "build it yourself" picker).
// Guests always default to filling the selected rooms if not specified, so a
// simple "1 villa" request still prices sensibly.
$cfg = maya_ilai_pricing_get();
$quote = maya_ilai_quote([
    'qtyDouble'   => (int)($data['qtyDouble']   ?? 0),
    'qtyBunk'     => (int)($data['qtyBunk']     ?? 0),
    'qtyStudio'   => (int)($data['qtyStudio']   ?? 0),
    'qtyVilla'    => (int)($data['qtyVilla']    ?? 0),
    'qtyLiving'   => (int)($data['qtyLiving']   ?? 0),
    'guestDouble' => (int)($data['guestDouble'] ?? 0),
    'guestBunk'   => (int)($data['guestBunk']   ?? 0),
    'guestStudio' => (int)($data['guestStudio'] ?? 0),
    'guestVilla'  => (int)($data['guestVilla']  ?? 0),
    'nights'      => (int)($data['nights']      ?? 1),
    // How many of the primitives above arrived from a COMBINATION, and how many
    // combination units there were. The living-room allowance cannot be derived
    // from the primitives alone: "2x One-Bedroom Suite" and "2 doubles + 2 living
    // rooms" expand to identical primitives at an identical price, yet the first
    // is two bedrooms in two villas (allowed, one living room each) and the
    // second is two bedrooms in ONE villa (only one living room exists).
    'comboUnits'  => (int)($data['comboUnits']  ?? 0),
    'comboDouble' => (int)($data['comboDouble'] ?? 0),
    'comboBunk'   => (int)($data['comboBunk']   ?? 0),
    // Guests always get the published (high) rate and automatic group discounts;
    // availability surcharges are a staff/revenue lever, not shown to guests.
    'season'      => 'high',
    'program'     => 'group',
], $cfg);

echo json_encode(['ok'=>true, 'quote'=>$quote]);
