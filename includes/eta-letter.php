<?php
declare(strict_types=1);
/**
 * Accommodation confirmation for the Kenya eTA — one page per guest, served by
 * /eta-letter.php (guest-facing URL, never /admin, like the signed record).
 *
 * The Kenya Electronic Travel Authorisation asks travellers to upload proof of
 * where they are staying. This is that proof: a confirmation FROM US of a
 * confirmed booking. It is not a visa or a government document, and says so.
 *
 * Only for a confirmed stay that hasn't ended, and only once the guest's own
 * passport details are in. Never for a Kenyan National ID holder (citizens need
 * no eTA). Each letter shows only that guest's passport number.
 *
 * Pure functions are marked; eta_letter_issuer() and eta_letter_address() read the DB.
 * Test: php tests/eta_letter_logic.php
 */

require_once __DIR__ . '/checkin.php';

const ETA_OFFICIAL_SITE = 'https://www.etakenya.go.ke';
const ETA_CONTACT_EMAIL = 'reservations@tribalsand.com';
const ETA_CONTACT_PHONE = '+254 115 115 247';

/**
 * May this guest have a letter now? PURE.
 * @return array{ok:bool,reason:string,hidden:bool} hidden = don't even offer the button
 */
function eta_letter_status(array $hold, ?array $guest, ?string $today = null): array {
    $no = fn(string $why, bool $hidden = false) => ['ok' => false, 'reason' => $why, 'hidden' => $hidden];
    if ($guest === null) return $no('Guest not found.', true);
    if (checkin_id_type($guest['id_type'] ?? null) === 'kenya_id') {
        return $no("Not needed — Kenyan citizens don't need an eTA.", true);
    }
    if (in_array((string)($hold['status'] ?? ''), ['cancelled', 'expired', 'declined'], true)) {
        return $no('This booking is no longer active.', true);
    }
    if ((string)($hold['check_out'] ?? '') !== '' && (string)$hold['check_out'] < ($today ?? date('Y-m-d'))) {
        return $no('This stay has ended.', true);
    }
    if (($hold['status'] ?? '') !== 'confirmed') {
        return $no('Your eTA letter is available once your booking is confirmed.');
    }
    $has = fn($k) => trim((string)($guest[$k] ?? '')) !== '';
    if (!$has('passport_name') || !$has('passport_number') || !$has('nationality')) {
        return $no('Add your passport details (name, number and nationality) to get your eTA letter.');
    }
    return ['ok' => true, 'reason' => '', 'hidden' => false];
}

/**
 * Everything the letter prints. PURE. $issuer = the owning company row
 * (legal_name / name / kra_pin / address) or null → "Tribal Sand".
 */
function eta_letter_model(array $hold, array $guest, ?array $issuer, string $today): array {
    $ci = (string)$hold['check_in']; $co = (string)$hold['check_out'];
    $nights = max(1, (int)round((strtotime($co) - strtotime($ci)) / 86400));
    $party  = max(1, (int)($hold['guest_count'] ?? 1));
    $issuerName = trim((string)($issuer['legal_name'] ?? '')) ?: trim((string)($issuer['name'] ?? '')) ?: 'Tribal Sand';
    return [
        'guest_name'      => trim((string)$guest['passport_name']),
        'passport_number' => trim((string)$guest['passport_number']),
        'nationality'     => trim((string)$guest['nationality']),
        'is_lead'         => !empty($guest['is_lead']),
        'property'        => trim((string)($hold['venue_name'] ?? '')),
        'room'            => trim((string)($hold['room_name'] ?? '')),
        'address'         => trim((string)($hold['venue_address'] ?? '')),
        'check_in_label'  => date('l j F Y', strtotime($ci)),
        'check_out_label' => date('l j F Y', strtotime($co)),
        'nights'          => $nights,
        'party'           => $party . ($party === 1 ? ' guest' : ' guests') . ' on this booking',
        'booking_ref'     => 'TS-' . (int)$hold['id'],
        'access_code'     => trim((string)($hold['access_code'] ?? '')),
        'issued_label'    => date('j F Y', strtotime($today)),
        'issuer_name'     => $issuerName,
        'issuer_pin'      => trim((string)($issuer['kra_pin'] ?? '')),
        'issuer_address'  => trim((string)($issuer['address'] ?? '')),
    ];
}

/** Link to a guest's letter. $ref = lead token, $g = co-guest token, neither = staff. PURE. */
function eta_letter_url(int $holdId, int $guestId, ?string $ref = null, ?string $g = null): string {
    $u = '/eta-letter.php?hold=' . $holdId . '&guest=' . $guestId;
    if ($ref !== null && $ref !== '') return $u . '&ref=' . rawurlencode($ref);
    if ($g !== null && $g !== '')     return $u . '&g=' . rawurlencode($g);
    return $u;
}

/** The company that owns the property, for the letterhead. Null when not set up (or no accounting). Never throws. */
function eta_letter_issuer(?int $venueId): ?array {
    if (!$venueId) return null;
    try {
        require_once __DIR__ . '/companies.php';
        $cid = company_for_venue($venueId);
        if (!$cid) return null;
        $row = db_query('SELECT name, legal_name, kra_pin, address FROM companies WHERE id = :c', [':c' => $cid])->fetch();
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

/** The property's street address ('' when none). Never throws. */
function eta_letter_address(?int $venueId): string {
    if (!$venueId) return '';
    try { return trim((string)db_query('SELECT address FROM venues WHERE id = :v', [':v' => $venueId])->fetchColumn()); }
    catch (Throwable $e) { return ''; }
}
