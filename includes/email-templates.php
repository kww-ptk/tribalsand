<?php
/**
 * Email Notifications Center — the template registry, on/off switches,
 * recipient overrides and editable wording.
 *
 * Triggers stay in code; content is data. Every email the system sends has ONE
 * entry in email_registry(): what triggers it (in plain words), who gets it, its
 * default wording and the {{placeholders}} that wording may use. The senders in
 * includes/mail.php read their subject / heading / intro / footer note through
 * email_field(), which resolves: property override → global override → the
 * default below. The fixed parts of an email (detail tables, buttons, manage
 * links) stay code-rendered, so booking data in them is always right.
 *
 * Plain text only. Owner-edited wording is escaped at render time (see
 * _email_rich() in mail.php) — no HTML is ever stored or trusted.
 *
 * Every DB read is pre-migration-safe: before add_email_templates.sql runs the
 * defaults are used, and a settings hiccup falls back to "on".
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** The editable fields every template may carry, in editor order. */
const EMAIL_TEMPLATE_FIELDS = ['subject', 'heading', 'intro', 'footer_note'];

/** Placeholders every template can use (added by email_base_vars()). */
function email_common_placeholders(): array {
    return [
        'reply_email' => 'Our reservations address (reservations@tribalsand.com)',
        'site_url'    => 'The website address',
    ];
}

/**
 * The registry — one entry per email. Keys are stable: they are stored on every
 * email_log row and in the on/off settings, so never rename one.
 *
 *   name, audience (guest|staff), topic, trigger (plain words), sources (call
 *   sites), recipients (plain words), locked (the staff action IS sending it —
 *   can't be switched off), default_on, note (shown on the card), fields
 *   (editable field => default text), placeholders (name => description),
 *   sample (callable that calls the REAL sender with sample data).
 */
function email_registry(): array {
    static $r = null;
    if ($r !== null) return $r;

    $guestStay = [
        'guest_name'    => 'Guest name',
        'room_name'     => 'Room (or every room of a multi-room booking)',
        'check_in'      => 'Check-in date',
        'check_out'     => 'Check-out date',
        'property_name' => 'Property name',
        'property_town' => 'Property town (e.g. Watamu, Kilifi)',
    ];
    $ackPh = [
        'guest_name'    => 'Guest name',
        'room_name'     => 'Room / villa asked about (may be empty)',
        'check_in'      => 'Check-in date (may be empty)',
        'check_out'     => 'Check-out date (may be empty)',
    ];
    $ackFooter = 'If your enquiry is urgent you can simply reply to this email, or write to {{reply_email}}.';
    $ackHeading = 'Thank you for contacting us';
    $ackSources = ['api/submit-enquiry.php', 'api/submit-contact.php', 'api/submit-agency.php', 'api/submit-combo.php',
                   'api/maya-ilai-book.php', 'ghl-submit.php', 'properties.php', 'adwords.php', 'includes/agent.php'];

    $sampleHold = fn(array $extra = []) => array_merge([
        'id' => 0, 'guest_name' => 'Amina Otieno', 'guest_email' => 'guest@example.com',
        'room_name' => 'Ocean Suite', 'unit_name' => 'Ocean Suite 1', 'check_in' => date('Y-m-d', strtotime('+30 days')),
        'check_out' => date('Y-m-d', strtotime('+34 days')), 'venue_id' => null, 'venue_name' => 'Zuri Boutique Hotel',
        'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')), 'access_code' => 'AB12CD',
    ], $extra);
    $sampleRes = fn(array $extra = []) => array_merge([
        'id' => 0, 'venue_name' => 'Zuri Boutique Hotel', 'reservation_date' => date('Y-m-d', strtotime('+3 days')),
        'reservation_time' => '19:30', 'party_size' => 4, 'guest_name' => 'Amina Otieno', 'guest_email' => 'guest@example.com',
        'guest_phone' => '+254 700 000 000', 'reference' => 'TSR-ZURI-7K2M', 'notes' => 'A table by the pool, please.',
    ], $extra);

    return $r = [
        // ── Guest: enquiries & requests ──────────────────────────────────
        'ack_enquiry' => [
            'name' => 'Enquiry received', 'audience' => 'guest', 'topic' => 'Enquiries & requests',
            'trigger' => 'A guest sends an enquiry from a property page, the search page or a landing page (no dates held), or a travel agent sends a request from the trade portal.',
            'sources' => $ackSources, 'recipients' => 'The guest who sent the enquiry — for a trade-portal request, the agent.',
            'fields' => [
                'subject' => 'We’ve received your enquiry — Tribal Sand',
                'heading' => $ackHeading,
                'intro'   => 'Thank you for your enquiry. We’ve received your message and a member of our reservations team will get back to you within 24 hours.',
                'footer_note' => $ackFooter,
            ],
            'placeholders' => $ackPh,
            'sample' => fn() => send_guest_acknowledgement(['kind' => 'enquiry', 'guest_name' => 'Amina Otieno', 'guest_email' => 'guest@example.com',
                'room_name' => 'Ocean Suite', 'check_in' => date('Y-m-d', strtotime('+30 days')), 'check_out' => date('Y-m-d', strtotime('+34 days')),
                'guests_adults' => 2, 'guests_children' => 1, 'message' => 'Do you have a cot for our little one?']),
        ],
        'ack_hold' => [
            'name' => 'Booking request received (24h hold)', 'audience' => 'guest', 'topic' => 'Enquiries & requests',
            'trigger' => 'A guest clicks "Request to Book" and the dates are held for 24 hours while staff confirm.',
            'sources' => ['api/submit-enquiry.php', 'api/submit-combo.php', 'api/maya-ilai-book.php'],
            'recipients' => 'The guest who made the request.',
            'fields' => [
                'subject' => 'We’ve received your booking request — Tribal Sand',
                'heading' => $ackHeading,
                'intro'   => 'Thank you for your booking request. We’re holding your selected dates for 24 hours while our team confirms availability — you’ll receive a separate confirmation email shortly.',
                'footer_note' => $ackFooter,
            ],
            'placeholders' => $ackPh,
            'sample' => fn() => send_guest_acknowledgement(['kind' => 'hold', 'guest_name' => 'Amina Otieno', 'guest_email' => 'guest@example.com',
                'room_name' => 'Ocean Suite', 'check_in' => date('Y-m-d', strtotime('+30 days')), 'check_out' => date('Y-m-d', strtotime('+34 days')),
                'guests_adults' => 2, 'hold_id' => 0, 'access_code' => 'AB12CD']),
        ],
        'ack_contact' => [
            'name' => 'Message received (contact form)', 'audience' => 'guest', 'topic' => 'Enquiries & requests',
            'trigger' => 'A visitor sends the contact form or asks about a tour.',
            'sources' => ['api/submit-contact.php', 'ghl-submit.php'], 'recipients' => 'The person who sent the message.',
            'fields' => [
                'subject' => 'We’ve received your message — Tribal Sand',
                'heading' => $ackHeading,
                'intro'   => 'Thank you for getting in touch. We’ve received your message and a member of our team will reply as soon as possible.',
                'footer_note' => $ackFooter,
            ],
            'placeholders' => $ackPh,
            'sample' => fn() => send_guest_acknowledgement(['kind' => 'contact', 'guest_name' => 'Amina Otieno', 'guest_email' => 'guest@example.com',
                'subject' => 'Dhow sunset cruise', 'message' => 'Is the cruise running next Saturday?']),
        ],
        'ack_agency' => [
            'name' => 'Trade enquiry received', 'audience' => 'guest', 'topic' => 'Enquiries & requests',
            'trigger' => 'A travel agency sends the trade enquiry form on the website.',
            'sources' => ['api/submit-agency.php'], 'recipients' => 'The agency that sent it.',
            'fields' => [
                'subject' => 'We’ve received your enquiry — Tribal Sand',
                'heading' => $ackHeading,
                'intro'   => 'Thank you for your interest in working with us. We’ve received your travel agency enquiry and our team will be in touch shortly.',
                'footer_note' => $ackFooter,
            ],
            'placeholders' => $ackPh,
            'sample' => fn() => send_guest_acknowledgement(['kind' => 'agency', 'guest_name' => 'Jo Mwangi', 'guest_email' => 'agent@example.com',
                'agency_name' => 'Coastline Safaris', 'message' => 'We’d like to discuss trade rates for 2027.']),
        ],
        'trip_builder_guest' => [
            'name' => 'Trip plan received (Trip Builder)', 'audience' => 'guest', 'topic' => 'Enquiries & requests',
            'trigger' => 'A guest finishes the Trip Builder.',
            'sources' => ['api/trip-builder.php'], 'recipients' => 'The guest who built the trip.',
            'fields' => [
                'subject' => 'Your Kenya coast trip plan — Tribal Sand ({{reference}})',
                'intro'   => 'Thank you for planning your Kenya coast escape with us. Our concierge team will personally review everything below and reply within **24 hours** with a tailored quote. **No payment is taken at this stage.**',
            ],
            'placeholders' => ['guest_name' => 'Guest first name', 'reference' => 'Trip reference (TSB-…)'],
            'sample' => fn() => send_trip_builder_emails(email_sample_trip(), 0, 'guest'),
        ],

        // ── Guest: bookings ──────────────────────────────────────────────
        'hold_confirmed' => [
            'name' => 'Booking confirmed', 'audience' => 'guest', 'topic' => 'Bookings',
            'trigger' => 'Staff confirm a pending booking — in the booking workspace, the Holds list, or from the Confirm link in the staff email. Staff choose each time whether to email the guest; it defaults to off for bookings with no real guest address (OTA relay, internal, blank).',
            'sources' => ['admin/booking.php', 'admin/holds.php', 'admin/hold-action.php'],
            'recipients' => 'The email on the booking — for a trade booking that is the travel agent.',
            'fields' => [
                'subject' => 'Booking Confirmed — {{room_name}} — {{check_in}} to {{check_out}}',
                'heading' => 'Booking Confirmed',
                'intro'   => 'We are delighted to confirm your booking at {{property_name}}. Everything is set — we look forward to welcoming you!',
                'footer_note' => 'If you have any questions, simply reply to this email or write to {{reply_email}}.',
            ],
            'placeholders' => $guestStay + ['reference' => 'Booking reference'],
            'sample' => fn(array $o = []) => send_hold_confirmed($sampleHold($o)),
        ],
        'hold_cancelled' => [
            'name' => 'Booking cancelled', 'audience' => 'guest', 'topic' => 'Bookings',
            'trigger' => 'Staff cancel or decline a booking (workspace, Holds list, the Decline link in the staff email, or keeping the OTA booking on the Conflicts page), or the guest cancels it themselves from their booking page.',
            'sources' => ['admin/booking.php', 'admin/holds.php', 'admin/hold-action.php', 'admin/conflicts.php', 'booking.php'],
            'recipients' => 'The email on the booking.',
            'fields' => [
                'subject' => 'Hold Cancelled — {{room_name}} — {{check_in}}',
                'heading' => 'Your hold has been cancelled',
                'intro'   => 'Your hold request for {{room_name}} has been cancelled.',
                'footer_note' => 'We’d love to help you find alternative dates — just reply to this email or write to {{reply_email}}.',
            ],
            'placeholders' => $guestStay,
            'sample' => fn(array $o = []) => send_hold_cancelled($sampleHold($o), 'cancelled'),
        ],
        'hold_expired' => [
            'name' => 'Hold expired', 'audience' => 'guest', 'topic' => 'Bookings',
            'trigger' => 'Automatic: a 24-hour hold was not confirmed in time. The expiry job runs every 5 minutes.',
            'sources' => ['includes/db.php (expire_stale_holds)', 'bin/ical-expire-holds.php'],
            'recipients' => 'The email on the booking.',
            'fields' => [
                'subject' => 'Hold Expired — {{room_name}} — {{check_in}}',
                'heading' => 'We couldn’t confirm your dates',
                'intro'   => 'Unfortunately we were unable to confirm your hold request for {{room_name}} within the 24-hour window.',
                'footer_note' => 'We’d love to help you find alternative dates — just reply to this email or write to {{reply_email}}.',
            ],
            'placeholders' => $guestStay,
            'sample' => fn(array $o = []) => send_hold_cancelled($sampleHold($o), 'expired'),
        ],
        'admin_reply' => [
            'name' => 'Reply to an enquiry', 'audience' => 'guest', 'topic' => 'Enquiries & requests',
            'trigger' => 'A team member sends a reply from the enquiry page.',
            'sources' => ['admin/submission-view.php'], 'recipients' => 'The guest who sent the enquiry.',
            'locked' => true,
            'note' => 'Always on — sending it is the whole point of the staff action. The [TSR-…] tag is always added to the subject so the guest’s answer threads back.',
            'fields' => [
                'subject' => 'Re: Your enquiry — Tribal Sand',
                'heading' => 'A reply to your enquiry',
            ],
            'placeholders' => ['guest_name' => 'Guest name'],
            'sample' => fn() => send_admin_reply(['id' => 0, 'guest_name' => 'Amina Otieno', 'guest_email' => 'guest@example.com'],
                "Thank you for your enquiry — the Ocean Suite is free on those dates at USD 320 a night.\n\nShall we hold it for you?"),
        ],

        // ── Guest: restaurant & POS ─────────────────────────────────────
        'reservation_received_guest' => [
            'name' => 'Table request received', 'audience' => 'guest', 'topic' => 'Restaurant',
            'trigger' => 'A guest requests a table on the Reserve page (or through the restaurant API).',
            'sources' => ['api/submit-reservation.php', 'api/reservation-api.php'],
            'recipients' => 'The guest, when they left an email.',
            'fields' => [
                'subject' => 'We’ve received your table request — {{property_name}}',
                'heading' => 'Table request received',
                'intro'   => 'Thank you for your table request at {{property_name}}. It’s **pending confirmation** — a member of our team will be in touch shortly to confirm your reservation.',
                'footer_note' => 'Need to change anything? Simply reply to this email, or write to {{reply_email}}.',
            ],
            'placeholders' => ['guest_name' => 'Guest name', 'property_name' => 'Restaurant / property', 'when' => 'Date and time', 'reference' => 'Reservation reference'],
            'sample' => fn(array $o = []) => send_reservation_received($sampleRes($o)),
        ],
        'reservation_confirmed' => [
            'name' => 'Table confirmed', 'audience' => 'guest', 'topic' => 'Restaurant',
            'trigger' => 'Staff confirm a table reservation on the Reservations page.',
            'sources' => ['admin/reservations.php'], 'recipients' => 'The guest, when they left an email.',
            'fields' => [
                'subject' => 'Your table is confirmed — {{property_name}}',
                'heading' => 'Table confirmed',
                'intro'   => 'We’re delighted to confirm your table at {{property_name}}. We look forward to welcoming you.',
                'footer_note' => 'If your plans change, simply reply to this email, or write to {{reply_email}}.',
            ],
            'placeholders' => ['guest_name' => 'Guest name', 'property_name' => 'Restaurant / property', 'when' => 'Date and time', 'reference' => 'Reservation reference'],
            'sample' => fn(array $o = []) => send_reservation_confirmed($sampleRes($o)),
        ],
        'pos_receipt' => [
            'name' => 'Till receipt', 'audience' => 'guest', 'topic' => 'Point of sale',
            'trigger' => 'A cashier taps "Email" on a receipt at the till.',
            'sources' => ['api/pos/receipt-email.php'], 'recipients' => 'The address the cashier types.',
            'locked' => true, 'note' => 'Always on — the cashier is sending it on request.',
            'fields' => [
                'subject' => 'Your receipt — {{outlet_name}} ({{reference}})',
                'heading' => 'Your receipt',
                'intro'   => 'Thank you for visiting {{outlet_name}}. Here is your receipt.',
                'footer_note' => 'Questions? Simply reply to this email, or write to {{reply_email}}.',
            ],
            'placeholders' => ['outlet_name' => 'Shop / outlet', 'reference' => 'Receipt number'],
            'sample' => fn() => send_pos_receipt(email_sample_sale(), 'guest@example.com'),
        ],

        // ── Staff alerts ─────────────────────────────────────────────────
        'staff_new_lead' => [
            'name' => 'New lead alert', 'audience' => 'staff', 'topic' => 'Staff alerts',
            'trigger' => 'Any website form creates a lead: enquiry, contact, trade enquiry, event enquiry, search lead, landing pages.',
            'sources' => ['api/submit-enquiry.php', 'api/submit-contact.php', 'api/submit-agency.php', 'api/submit-event.php',
                          'api/search-lead.php', 'api/submit-combo.php', 'api/maya-ilai-book.php', 'ghl-submit.php', 'properties.php', 'adwords.php', 'includes/agent.php'],
            'recipients' => 'The notification address in Settings (reservations@ by default).',
            'fields' => [
                'subject' => '[{{type}}] {{room_name}} — {{guest_name}} — {{date}}',
                'heading' => 'New {{type}} enquiry',
                'intro'   => 'A new {{type}} has come in through the website.',
            ],
            'placeholders' => ['type' => 'Enquiry type (Enquiry, Contact, Agency…)', 'guest_name' => 'Guest name', 'room_name' => 'Room asked about (may be empty)', 'date' => 'Date received'],
            'sample' => fn() => send_notification(['id' => 0, 'type' => 'enquiry', 'guest_name' => 'Amina Otieno', 'guest_email' => 'guest@example.com',
                'guest_phone' => '+254 700 000 000', 'room_name' => 'Ocean Suite', 'message' => 'Do you have a cot?', 'source_page' => '/zuri',
                'check_in' => date('Y-m-d', strtotime('+30 days')), 'check_out' => date('Y-m-d', strtotime('+34 days'))]),
        ],
        'staff_hold_request' => [
            'name' => 'New booking request — needs action', 'audience' => 'staff', 'topic' => 'Staff alerts',
            'trigger' => 'A guest places a 24-hour hold ("Request to Book", or several rooms at once).',
            'sources' => ['api/submit-enquiry.php', 'api/submit-combo.php'],
            'recipients' => 'The notification address in Settings.',
            'fields' => [
                'subject' => '[Hold Request] {{room_name}} — {{guest_name}} — {{check_in}} to {{check_out}}',
                'heading' => 'New Hold Request',
            ],
            'placeholders' => ['guest_name' => 'Guest name', 'room_name' => 'Room(s)', 'check_in' => 'Check-in', 'check_out' => 'Check-out'],
            'sample' => fn() => send_hold_notification($sampleHold()),
        ],
        'trip_builder_staff' => [
            'name' => 'Trip Builder alert', 'audience' => 'staff', 'topic' => 'Staff alerts',
            'trigger' => 'A guest finishes the Trip Builder.',
            'sources' => ['api/trip-builder.php'], 'recipients' => 'The notification address in Settings.',
            'fields' => ['subject' => '[Trip Builder] {{guest_name}} — {{property_name}} — {{reference}}'],
            'placeholders' => ['guest_name' => 'Guest name', 'property_name' => 'Property chosen', 'reference' => 'Trip reference'],
            'sample' => fn() => send_trip_builder_emails(email_sample_trip(), 0, 'staff'),
        ],
        'staff_guest_cancelled' => [
            'name' => 'Guest cancelled their booking', 'audience' => 'staff', 'topic' => 'Staff alerts',
            'trigger' => 'A guest cancels their own booking from their booking page.',
            'sources' => ['booking.php'], 'recipients' => 'The notification address in Settings.',
            'fields' => [
                'subject' => '[Guest Cancelled] {{room_name}} — {{guest_name}} — {{check_in}} to {{check_out}}',
                'heading' => 'Guest cancelled a booking',
                'intro'   => 'A guest has cancelled their own booking. The dates have been freed and the guest has been notified.',
            ],
            'placeholders' => ['guest_name' => 'Guest name', 'room_name' => 'Room', 'check_in' => 'Check-in', 'check_out' => 'Check-out'],
            'sample' => fn() => send_admin_guest_cancelled($sampleHold()),
        ],
        'staff_change_request' => [
            'name' => 'Guest change request', 'audience' => 'staff', 'topic' => 'Staff alerts',
            'trigger' => 'A guest asks to change dates or guest numbers from their booking page.',
            'sources' => ['api/booking-change.php'], 'recipients' => 'The notification address in Settings.',
            'fields' => [
                'subject' => 'Change request — hold #{{hold_id}} ({{guest_name}})',
                'heading' => 'Guest change request',
            ],
            'placeholders' => ['guest_name' => 'Guest name', 'hold_id' => 'Booking number', 'room_name' => 'Room'],
            'sample' => fn() => send_change_request_notification($sampleHold(), ['check_in' => date('Y-m-d', strtotime('+31 days')), 'check_out' => '', 'guests' => 3, 'note' => 'One more night please']),
        ],
        'staff_addon_request' => [
            'name' => 'Guest add-on request', 'audience' => 'staff', 'topic' => 'Staff alerts',
            'trigger' => 'A guest adds a tour, transfer or other extra from their booking page.',
            'sources' => ['api/booking-addon.php'], 'recipients' => 'The notification address in Settings.',
            'fields' => [
                'subject' => 'Add-on request — hold #{{hold_id}} ({{guest_name}})',
                'heading' => 'Guest add-on request',
            ],
            'placeholders' => ['guest_name' => 'Guest name', 'hold_id' => 'Booking number', 'room_name' => 'Room', 'addon' => 'What they added'],
            'sample' => fn() => send_addon_request_notification($sampleHold(), ['kind' => 'transfer', 'details' => 'Airport pickup, flight KQ612']),
        ],
        'reservation_received_staff' => [
            'name' => 'New table reservation', 'audience' => 'staff', 'topic' => 'Staff alerts',
            'trigger' => 'A guest requests a table (same moment as "Table request received").',
            'sources' => ['api/submit-reservation.php', 'api/reservation-api.php'],
            'recipients' => 'The notification address in Settings.',
            'fields' => [
                'subject' => '[Reservation] {{property_name}} — {{guest_name}} — {{when}}',
                'heading' => 'New reservation request',
                'intro'   => 'A new table reservation request has come in through the website. It’s pending — please confirm or cancel.',
            ],
            'placeholders' => ['guest_name' => 'Guest name', 'property_name' => 'Restaurant / property', 'when' => 'Date and time'],
            'sample' => fn(array $o = []) => send_reservation_received($sampleRes($o)),
        ],
        'staff_checkin_completed' => [
            'name' => 'Pre-check-in completed', 'audience' => 'staff', 'topic' => 'Staff alerts',
            'trigger' => 'A guest finishes the online pre-check-in.',
            'sources' => ['includes/checkin.php'], 'recipients' => 'The notification address in Settings.',
            'default_on' => false,
            'note' => 'Off by default: before this center it only worked with the old Resend setup, so production never actually sent it. Switch it on to start receiving it.',
            'fields' => [
                'subject' => 'Pre-check-in complete — {{guest_name}}',
                'heading' => 'Pre-check-in complete',
                'intro'   => 'A guest has completed their pre-check-in.',
            ],
            'placeholders' => ['guest_name' => 'Guest name', 'room_name' => 'Room'],
            'sample' => fn() => send_checkin_completed($sampleHold(), ['flight_number' => 'KQ612', 'arrival_airport' => 'Malindi', 'arrival_at' => '14:20', 'needs_transfer' => true, 'transfer_details' => 'Airport pickup', 'dietary' => 'Vegetarian', 'special_requests' => '']),
        ],
        'password_reset' => [
            'name' => 'Admin password reset', 'audience' => 'staff', 'topic' => 'Staff alerts',
            'trigger' => 'Someone asks for a password reset on the admin sign-in page.',
            'sources' => ['admin/forgot-password.php'], 'recipients' => 'The admin account that asked for it.',
            'locked' => true, 'note' => 'Always on, and never re-routed — it must reach the account owner.',
            'fields' => ['subject' => 'Tribal Sand Admin — Password Reset', 'heading' => 'Reset your password'],
            'placeholders' => [],
            'sample' => fn() => send_password_reset('you@example.com', 'https://tribalsand.com/admin/reset-password.php?token=sample'),
        ],
    ];
}

/** Emails whose senders know the property, so a per-property wording applies. */
const EMAIL_VENUE_AWARE = ['hold_confirmed', 'hold_cancelled', 'hold_expired',
                           'reservation_received_guest', 'reservation_confirmed', 'reservation_received_staff'];

/** Registry entry or null. */
function email_template(string $key): ?array {
    return email_registry()[$key] ?? null;
}

/** Keys grouped for the catalogue: ['guest' => [...keys], 'staff' => [...]]. */
function email_registry_grouped(): array {
    $out = ['guest' => [], 'staff' => []];
    foreach (email_registry() as $k => $t) $out[$t['audience']][] = $k;
    return $out;
}

// ── On / off ─────────────────────────────────────────────────────────────

/**
 * Deleted emails. Every email is defined in code (its trigger is code), so
 * "delete" can't remove the trigger — it removes the email from the catalogue
 * and stops it for good: never sent (logged as suppressed "Deleted"), custom
 * wording and recipients cleared. Restorable from the "Deleted emails" list,
 * because deleting e.g. "Booking confirmed" by mistake must be undoable.
 * Locked emails (the staff action IS the send) can't be deleted.
 */
function email_template_deleted(string $key): bool {
    try { return setting('email_deleted_' . $key, '') !== ''; }
    catch (Throwable $e) { return false; }
}

/** ['at' => 'Y-m-d H:i:s', 'by' => ?int] for a deleted email, else null. */
function email_template_deleted_info(string $key): ?array {
    try { $raw = setting('email_deleted_' . $key, ''); } catch (Throwable $e) { return null; }
    if ($raw === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : ['at' => null, 'by' => null];
}

function email_template_delete(string $key, ?int $userId): void {
    $t = email_template($key);
    if (!$t) throw new InvalidArgumentException('Unknown email.');
    if (!empty($t['locked'])) throw new InvalidArgumentException('“' . $t['name'] . '” can’t be deleted — sending it is what the staff action does.');
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        set_setting('email_deleted_' . $key, json_encode(['at' => date('Y-m-d H:i:s'), 'by' => $userId]));
        set_setting('email_to_' . $key, '');
        if (email_templates_supported()) {
            $had = (int) db_query('SELECT COUNT(*) FROM email_template_overrides WHERE template_key = :k', [':k' => $key])->fetchColumn();
            db_query('DELETE FROM email_template_overrides WHERE template_key = :k', [':k' => $key]);
            // Keep the wording history restorable: note the reset in every scope that had wording.
            if ($had) db_query("INSERT INTO email_template_versions (template_key, venue_id, action, saved_by) VALUES (:k, NULL, 'reset', :u)",
                               [':k' => $key, ':u' => $userId]);
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Bring a deleted email back — switched on, built-in wording, normal recipients. */
function email_template_restore(string $key): void {
    $t = email_template($key);
    if (!$t) throw new InvalidArgumentException('Unknown email.');
    set_setting('email_deleted_' . $key, '');
    set_setting('email_enabled_' . $key, '1');
}

/** Is this email switched on? Locked templates always are; deleted ones never. Fails to the default. */
function email_template_enabled(string $key): bool {
    $t = email_template($key);
    if (!$t) return true;                                // unknown key (e.g. a test send) — never silently drop
    if (!empty($t['locked'])) return true;
    if (email_template_deleted($key)) return false;
    $default = ($t['default_on'] ?? true) ? '1' : '0';
    try { return setting('email_enabled_' . $key, $default) === '1'; }
    catch (Throwable $e) { return $default === '1'; }
}

function email_template_set_enabled(string $key, bool $on): void {
    $t = email_template($key);
    if (!$t) throw new InvalidArgumentException('Unknown email.');
    if (!empty($t['locked'])) throw new InvalidArgumentException('This email is always on.');
    set_setting('email_enabled_' . $key, $on ? '1' : '0');
}

// ── Recipient override (staff alerts only) ───────────────────────────────

/** Parse a comma/space separated address list, keeping only valid, unique addresses. */
function email_parse_list(string $raw): array {
    $out = [];
    foreach (preg_split('~[\s,;]+~', trim($raw)) ?: [] as $a) {
        $a = strtolower(trim($a));
        if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) && !in_array($a, $out, true)) $out[] = $a;
    }
    return $out;
}

/** The override list for a staff alert, [] = send to the normal address. */
function email_recipient_override(string $key): array {
    $t = email_template($key);
    if (!$t || $t['audience'] !== 'staff' || !empty($t['locked'])) return [];
    try { return email_parse_list(setting('email_to_' . $key, '')); }
    catch (Throwable $e) { return []; }
}

function email_set_recipient_override(string $key, string $raw): array {
    $t = email_template($key);
    if (!$t || $t['audience'] !== 'staff' || !empty($t['locked'])) throw new InvalidArgumentException('Recipients can’t be changed for this email.');
    $list = email_parse_list($raw);
    set_setting('email_to_' . $key, implode(', ', $list));
    return $list;
}

/** Default staff recipient — the notification address in Settings. */
function email_staff_address(): string {
    try { $a = trim(setting('notify_email', 'reservations@tribalsand.com')); }
    catch (Throwable $e) { $a = ''; }
    return $a !== '' ? $a : 'reservations@tribalsand.com';
}

/**
 * Where a guest's reply to any of our emails should go: the monitored brand
 * mailbox, never noreply@ (MAIL_FROM). Same address send_admin_reply() uses, so
 * the M365 forward threads replies back (docs/inbound-mail-setup.md).
 */
function email_guest_reply_to(): string {
    return 'reservations@tribalsand.com';
}

// ── Editable wording ─────────────────────────────────────────────────────

function email_templates_supported(): bool {
    static $s = null;
    if ($s !== null) return $s;
    try {
        return $s = (bool) db_query("SELECT to_regclass('public.email_template_overrides') IS NOT NULL
                                        AND to_regclass('public.email_template_versions') IS NOT NULL")->fetchColumn();
    } catch (Throwable $e) { return $s = false; }
}

/** A draft (editor preview) that wins over everything for this request. */
function email_template_draft(?string $key = null, ?array $fields = null, bool $set = false): ?array {
    static $draft = null;
    if ($set) { $draft = $key === null ? null : ['key' => $key, 'fields' => $fields ?? []]; }
    return $draft;
}

/** The active override rows for a template, keyed by venue id (0 = every property). Cached. */
function email_template_overrides(string $key): array {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    if (!email_templates_supported()) return $cache[$key] = [];
    try {
        $rows = db_query('SELECT * FROM email_template_overrides WHERE template_key = :k AND is_active = TRUE',
                         [':k' => $key])->fetchAll();
    } catch (Throwable $e) { $rows = []; }
    $out = [];
    foreach ($rows as $r) $out[(int)($r['venue_id'] ?? 0)] = $r;
    return $cache[$key] = $out;
}

/**
 * The raw (unfilled) text for one field: draft → property override → global
 * override → code default. A blank override falls through to the next level.
 */
function email_field_raw(string $key, string $field, ?int $venueId = null): string {
    $t = email_template($key);
    $default = (string)($t['fields'][$field] ?? '');
    if (!array_key_exists($field, $t['fields'] ?? [])) return '';

    $d = email_template_draft();
    if ($d && $d['key'] === $key && trim((string)($d['fields'][$field] ?? '')) !== '') return (string)$d['fields'][$field];

    $rows = email_template_overrides($key);
    foreach ($venueId ? [$venueId, 0] : [0] as $v) {
        $txt = trim((string)($rows[$v][$field] ?? ''));
        if ($txt !== '') return $txt;
    }
    return $default;
}

/** Common placeholder values every template can use. */
function email_base_vars(): array {
    $env = parse_env();
    return [
        'reply_email' => email_guest_reply_to(),
        'site_url'    => rtrim($env['SITE_URL'] ?? $env['APP_URL'] ?? 'https://tribalsand.com', '/'),
    ];
}

/** Replace {{ name }} with its value; an unknown placeholder renders empty. Pure. */
function email_fill(string $text, array $vars): string {
    return (string) preg_replace_callback('~\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}~i', function ($m) use ($vars) {
        $v = $vars[strtolower($m[1])] ?? '';
        return is_scalar($v) ? (string)$v : '';
    }, $text);
}

/**
 * The text for one field of one email, placeholders filled. PLAIN TEXT —
 * callers escape it (the _email_* helpers do). A subject is flattened to one
 * line and tidied (an empty placeholder must not leave "—  —" behind).
 */
function email_field(string $key, string $field, array $vars = [], ?int $venueId = null): string {
    $txt = email_fill(email_field_raw($key, $field, $venueId), $vars + email_base_vars());
    if ($field === 'subject') {
        $txt = preg_replace('~\s+~', ' ', $txt);
        $txt = preg_replace('~(\s*[—–-]\s*){2,}~u', ' — ', $txt);     // "a —  — b" → "a — b"
        $txt = preg_replace('~^\s*[—–-]\s*|\s*[—–-]\s*$~u', '', $txt);
        $txt = preg_replace('~\[\s*\]\s*~', '', $txt);
        $txt = preg_replace('~\]\s*[—–-]\s*~u', '] ', $txt);          // "[Contact] — Bo" → "[Contact] Bo"
        $txt = trim($txt);
    }
    return $txt;
}

/** Placeholder names used in a text. Pure. */
function email_placeholders_in(string $text): array {
    preg_match_all('~\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}~i', $text, $m);
    return array_values(array_unique(array_map('strtolower', $m[1] ?? [])));
}

/** Placeholders this template may use (its own + the common ones). */
function email_allowed_placeholders(string $key): array {
    $t = email_template($key);
    return ($t['placeholders'] ?? []) + email_common_placeholders();
}

/**
 * Validate posted wording for a template. Returns field => error (empty = ok).
 * Refuses unknown placeholders, fields the template doesn't have, HTML, a
 * multi-line subject, and anything over the length caps.
 */
function email_validate_fields(string $key, array $fields): array {
    $t = email_template($key);
    if (!$t) return ['_' => 'Unknown email.'];
    $allowed = array_keys(email_allowed_placeholders($key));
    $caps = ['subject' => 200, 'heading' => 120, 'intro' => 2000, 'footer_note' => 1000];
    $err = [];
    foreach ($fields as $f => $v) {
        $v = (string)$v;
        if (!in_array($f, EMAIL_TEMPLATE_FIELDS, true) || !array_key_exists($f, $t['fields'])) {
            if (trim($v) !== '') $err[$f] = 'This email has no such field.';
            continue;
        }
        if (mb_strlen($v) > $caps[$f]) { $err[$f] = 'Too long (max ' . $caps[$f] . ' characters).'; continue; }
        if (($f === 'subject' || $f === 'heading') && preg_match('~[\r\n]~', $v)) { $err[$f] = 'Must be one line.'; continue; }
        if (preg_match('~<\s*/?\s*[a-z!][^>]*>~i', $v)) { $err[$f] = 'HTML isn’t allowed — use plain text (**bold** works).'; continue; }
        $bad = array_diff(email_placeholders_in($v), $allowed);
        if ($bad) { $err[$f] = 'Unknown placeholder: {{' . implode('}}, {{', $bad) . '}}'; continue; }
        if (substr_count($v, '{{') !== substr_count($v, '}}')) $err[$f] = 'A {{placeholder}} isn’t closed.';
    }
    return $err;
}

/** The saved override for one scope (venue null = every property), or null. */
function email_template_override_row(string $key, ?int $venueId): ?array {
    if (!email_templates_supported()) return null;
    $row = db_query('SELECT * FROM email_template_overrides WHERE template_key = :k AND COALESCE(venue_id, 0) = :v',
                    [':k' => $key, ':v' => (int)$venueId])->fetch();
    return $row ?: null;
}

/**
 * Save wording for one scope. Fields equal to the code default (or blank) are
 * stored as NULL so a later change to the default still reaches this email.
 * Writes a version row. Throws InvalidArgumentException with the first error.
 */
function email_template_save(string $key, ?int $venueId, array $fields, ?int $userId, string $action = 'save'): void {
    if (!email_templates_supported()) throw new RuntimeException('Run the add_email_templates migration first.');
    $t = email_template($key);
    if (!$t) throw new InvalidArgumentException('Unknown email.');
    $err = email_validate_fields($key, $fields);
    if ($err) throw new InvalidArgumentException(reset($err));

    $vals = [];
    foreach (EMAIL_TEMPLATE_FIELDS as $f) {
        if (!array_key_exists($f, $t['fields'])) { $vals[$f] = null; continue; }
        $v = trim(str_replace("\r\n", "\n", (string)($fields[$f] ?? '')));
        // Store only what differs from the level below: the code default for the
        // all-properties wording, the all-properties wording for a property. So a
        // later change underneath still reaches every field left alone.
        $below = $venueId === null ? trim((string)$t['fields'][$f]) : trim(email_field_raw($key, $f, null));
        $vals[$f] = ($v === '' || $v === $below) ? null : $v;
    }
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $existing = email_template_override_row($key, $venueId);
        $p = [':k' => $key, ':s' => $vals['subject'], ':h' => $vals['heading'], ':i' => $vals['intro'], ':f' => $vals['footer_note'], ':u' => $userId];
        if ($existing) {
            db_query('UPDATE email_template_overrides SET subject = :s, heading = :h, intro = :i, footer_note = :f,
                             is_active = TRUE, updated_by = :u, updated_at = now() WHERE id = :id',
                     array_diff_key($p, [':k' => 1]) + [':id' => (int)$existing['id']]);
        } else {
            db_query('INSERT INTO email_template_overrides (template_key, venue_id, subject, heading, intro, footer_note, updated_by)
                      VALUES (:k, :v, :s, :h, :i, :f, :u)', $p + [':v' => $venueId]);
        }
        db_query('INSERT INTO email_template_versions (template_key, venue_id, subject, heading, intro, footer_note, action, saved_by)
                  VALUES (:k, :v, :s, :h, :i, :f, :a, :u)', $p + [':v' => $venueId, ':a' => $action]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Reset one scope to the code default (global) or to the global wording (property). Logged as a version. */
function email_template_reset(string $key, ?int $venueId, ?int $userId): void {
    if (!email_templates_supported()) return;
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        db_query('DELETE FROM email_template_overrides WHERE template_key = :k AND COALESCE(venue_id, 0) = :v',
                 [':k' => $key, ':v' => (int)$venueId]);
        db_query("INSERT INTO email_template_versions (template_key, venue_id, action, saved_by) VALUES (:k, :v, 'reset', :u)",
                 [':k' => $key, ':v' => $venueId, ':u' => $userId]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Version history for one scope, newest first. */
function email_template_versions(string $key, ?int $venueId, int $limit = 20): array {
    if (!email_templates_supported()) return [];
    return db_query('SELECT v.*, a.name AS saved_by_name FROM email_template_versions v
                       LEFT JOIN admin_users a ON a.id = v.saved_by
                      WHERE v.template_key = :k AND COALESCE(v.venue_id, 0) = :v
                      ORDER BY v.saved_at DESC, v.id DESC LIMIT ' . max(1, $limit),
                    [':k' => $key, ':v' => (int)$venueId])->fetchAll();
}

// ── Booking-confirmation choice (Phase 3) ────────────────────────────────

/**
 * Addresses that are not a guest we should email: OTA relay addresses (the
 * channel forwards or drops them — and a "Booking Confirmed" from us next to the
 * OTA's own confirmation confuses the guest) and our own domain (a room staff
 * blocked for internal use). Pure.
 */
function email_address_is_relay_or_internal(string $email): ?string {
    $email = strtolower(trim($email));
    $domain = substr(strrchr($email, '@') ?: '', 1);
    if ($domain === '') return null;
    $relays = ['guest.booking.com', 'booking.com', 'guest.airbnb.com', 'airbnb.com', 'm.expediapartnercentral.com',
               'expediapartnercentral.com', 'guest.expedia.com', 'agoda-messaging.com', 'reply.agoda.com',
               'guest.trip.com', 'vrbo.com', 'messages.homeaway.com'];
    foreach ($relays as $r) if ($domain === $r || str_ends_with($domain, '.' . $r)) return 'OTA relay address';
    if ($domain === 'tribalsand.com' || str_ends_with($domain, '.tribalsand.com')) return 'Our own address';
    return null;
}

/**
 * Should the "Email the guest" box start ticked for this hold? Pure.
 * $hold: guest_email, agent_id, submission_id, expires_at, and optionally
 * ledger_source (the bookings row's source, when the hold came from an import).
 * Returns ['default' => bool, 'reason' => plain words, 'can' => bool].
 */
function email_hold_confirm_default(array $hold): array {
    $to = trim((string)($hold['guest_email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['default' => false, 'can' => false, 'reason' => 'No guest email on this booking.'];
    }
    if ($why = email_address_is_relay_or_internal($to)) {
        return ['default' => false, 'can' => true, 'reason' => $why . ' (' . $to . ') — probably not a guest to email.'];
    }
    if (in_array((string)($hold['ledger_source'] ?? ''), ['ota', 'import'], true)) {
        return ['default' => false, 'can' => true, 'reason' => 'Imported from a booking channel — the channel already confirmed it.'];
    }
    if (!empty($hold['agent_id'])) {
        return ['default' => true, 'can' => true, 'reason' => 'Trade booking — the email goes to the travel agent (' . $to . ').'];
    }
    if (empty($hold['submission_id']) && empty($hold['expires_at'])) {
        return ['default' => true, 'can' => true, 'reason' => 'Created by staff — untick if this only blocks the room.'];
    }
    return ['default' => true, 'can' => true, 'reason' => 'Goes to ' . $to . '.'];
}

/** The bookings-ledger source of a hold ('' when none / pre-migration). */
function email_hold_ledger_source(int $holdId): string {
    static $has = null;
    if ($holdId <= 0) return '';
    try {
        $has ??= (bool) db_query("SELECT to_regclass('public.bookings') IS NOT NULL")->fetchColumn();
        if (!$has) return '';
        return (string) (db_query('SELECT source FROM bookings WHERE hold_id = :h ORDER BY id LIMIT 1', [':h' => $holdId])->fetchColumn() ?: '');
    } catch (Throwable $e) { return ''; }
}

/**
 * Render the "Email the guest" toggle for a confirm / cancel form. $kind is
 * 'confirm' or 'cancel'. Cancel defaults on whenever there is a real address.
 */
function email_guest_toggle(array $hold, string $kind = 'confirm', bool $compact = false): string {
    $hold['ledger_source'] = $hold['ledger_source'] ?? email_hold_ledger_source((int)($hold['id'] ?? 0));
    $d = email_hold_confirm_default($hold);
    if ($kind === 'cancel' && $d['can']) $d['default'] = email_address_is_relay_or_internal((string)$hold['guest_email']) === null;
    if (!$d['can']) {
        return '<input type="hidden" name="email_guest" value="0">'
             . ($compact ? '' : '<p class="text-muted" style="font-size:12px;margin:6px 0 0">' . e($d['reason']) . ' No email will be sent.</p>');
    }
    $label = $kind === 'confirm' ? 'Email the guest the confirmation' : 'Email the guest that it’s cancelled';
    if ($compact) {
        return '<input type="hidden" name="email_choice" value="1">'
             . '<label class="ckwrap" data-tip="' . e($d['reason']) . '" style="font-size:12px"><input type="checkbox" name="email_guest" value="1"'
             . ($d['default'] ? ' checked' : '') . '><span class="ck"></span>Email</label>';
    }
    return '<input type="hidden" name="email_choice" value="1">'
         . '<label class="togglerow"><span class="toggle"><input type="checkbox" name="email_guest" value="1"' . ($d['default'] ? ' checked' : '')
         . '><span class="toggle-slider"></span></span><span>' . e($label)
         . ' <span class="text-muted">(' . e($d['reason']) . ')</span></span></label>';
}

/**
 * The Holds list keeps its action buttons as plain icons: each form carries the
 * choice as HIDDEN fields (email_guest_hidden()), and the row shows ONE visible
 * tick (email_guest_row_toggle()) that admin/holds.php's script copies into them.
 * Without JavaScript the hidden default stands — the same default the old
 * per-button checkbox started from.
 */
function email_guest_hidden(array $hold, string $kind = 'confirm'): string {
    $hold['ledger_source'] = $hold['ledger_source'] ?? email_hold_ledger_source((int)($hold['id'] ?? 0));
    $d = email_hold_confirm_default($hold);
    if (!$d['can']) return '<input type="hidden" name="email_guest" value="0">';
    if ($kind === 'cancel') $d['default'] = email_address_is_relay_or_internal((string)$hold['guest_email']) === null;
    return '<input type="hidden" name="email_choice" value="1">'
         . '<input type="hidden" name="email_guest" value="' . ($d['default'] ? '1' : '0') . '" data-email-guest>';
}

/** The row's one visible "Email guest" tick ('' when this guest cannot be emailed). */
function email_guest_row_toggle(array $hold, string $kind = 'confirm'): string {
    $hold['ledger_source'] = $hold['ledger_source'] ?? email_hold_ledger_source((int)($hold['id'] ?? 0));
    $d = email_hold_confirm_default($hold);
    if (!$d['can']) return '<span class="hold-mail hold-mail--off" data-tip="' . e($d['reason']) . '">No guest email</span>';
    if ($kind === 'cancel') $d['default'] = email_address_is_relay_or_internal((string)$hold['guest_email']) === null;
    return '<label class="ckwrap hold-mail" data-tip="' . e($d['reason']) . '"><input type="checkbox" data-email-master'
         . ($d['default'] ? ' checked' : '') . '><span class="ck"></span>Email guest</label>';
}

/**
 * Read the posted choice. A form that never carried the toggle (an old tab)
 * falls back to the default rule rather than silently emailing.
 */
function email_guest_choice_posted(array $hold): bool {
    if (!isset($_POST['email_choice']) && !isset($_POST['email_guest'])) {
        $hold['ledger_source'] = $hold['ledger_source'] ?? email_hold_ledger_source((int)($hold['id'] ?? 0));
        return email_hold_confirm_default($hold)['default'];
    }
    return ($_POST['email_guest'] ?? '0') === '1';
}

// ── Sample data for previews ─────────────────────────────────────────────

/**
 * Render one email with sample data through its REAL sender (capture mode —
 * nothing is sent or logged). $draft = editor fields to preview unsaved;
 * $venueId previews a property's wording. Needs includes/mail.php loaded.
 * Returns the captured message (key, to, subject, html, text, reply_to) or null.
 */
function email_render_sample(string $key, ?int $venueId = null, ?array $draft = null): ?array {
    $t = email_template($key);
    if (!$t || !is_callable($t['sample'] ?? null)) return null;
    $o = [];
    if ($venueId) {
        try {
            $v = db_query('SELECT id, name FROM venues WHERE id = :v', [':v' => $venueId])->fetch();
            if ($v) $o = ['venue_id' => (int)$v['id'], 'venue_name' => (string)$v['name']];
        } catch (Throwable $e) {}
    }
    email_template_draft($draft !== null ? $key : null, $draft, true);
    try {
        $msgs = mail_capture(fn() => ($t['sample'])($o));
    } finally {
        email_template_draft(null, null, true);
    }
    foreach ($msgs as $m) if ($m['key'] === $key) return $m;
    return null;
}

function email_sample_trip(): array {
    return [
        'guest' => ['firstName' => 'Amina', 'lastName' => 'Otieno', 'email' => 'guest@example.com', 'phone' => '+254 700 000 000', 'nationality' => 'Kenyan'],
        'trip'  => ['prop' => 'zuri', 'arrDate' => date('Y-m-d', strtotime('+40 days')), 'depDate' => date('Y-m-d', strtotime('+45 days')),
                    'adults' => 2, 'children' => 1, 'purpose' => 'Family holiday', 'arrMode' => 'flight', 'flightNum' => 'KQ612',
                    'airport' => 'Malindi', 'arrTime' => '14:20', 'transfer' => 'yes'],
        'departure' => ['flight' => 'KQ613', 'airport' => 'Malindi', 'time' => '15:10', 'transfer' => 'yes'],
        'special'   => ['occasions' => ['anniversary'], 'diet' => ['vegetarian'], 'pace' => 'Relaxed'],
        'itinerary' => [['label' => 'Day 2', 'slots' => [['name' => 'Dhow sunset cruise', 'time' => '17:00']]]],
    ];
}

function email_sample_sale(): array {
    return [
        'id' => 0, 'currency' => 'KES', 'outlet_name' => 'Tribal Shop', 'created_at' => date('Y-m-d H:i:s'),
        'reference' => 'POS-000123', 'service_charge' => 0, 'vat_amount' => 552, 'vat_pct' => '16', 'vat_inclusive' => true,
        'tip_amount' => 0, 'total' => 4000, 'payment_method' => 'mpesa',
        'lines' => [['qty' => 2, 'name' => 'Kikoy wrap', 'line_total' => 3000], ['qty' => 1, 'name' => 'Coconut soap', 'line_total' => 1000]],
    ];
}
