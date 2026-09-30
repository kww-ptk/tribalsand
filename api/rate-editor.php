<?php
declare(strict_types=1);
/**
 * Global rate editor (JSON) — owner only. Logic: includes/rate-editor.php
 * (rate_editor_dispatch() is this whole endpoint minus the session, and is what
 * tests/rate_editor_logic.php exercises). Spec:
 * docs/superpowers/specs/2026-09-30-global-rate-editor-design.md
 *
 * ── Transport ──────────────────────────────────────────────────────────────
 * POST application/json  {csrf_token, action, …}   → application/json
 *   csrf_token = the page's csrf_token() (in the BODY — verify_csrf() reads
 *   $_POST, which a JSON fetch does not fill; the session token must be
 *   non-empty, since hash_equals('', '') is true).
 * Errors are always {ok:false, error:"<human sentence>"}:
 *   401 not signed in / account inactive · 403 not the owner · 403 bad CSRF ·
 *   405 not POST · 400 unknown action · 422 refused (validation, currency mix,
 *   price ≤ 0, nothing to change, undo blocked) · 404 unknown change ·
 *   409 log/undo before the add_rate_change_log migration · 500 anything else.
 *
 * ── Dates ──────────────────────────────────────────────────────────────────
 * Every date is 'YYYY-MM-DD' (zero-padded, strict). Ranges are NIGHTS,
 * INCLUSIVE: [first night, last night] — the form's wording. The server stores
 * them exclusive (checkout morning) like every `rates` row. Overlapping or
 * abutting ranges are merged.
 *
 * ── action: "options" — what the form needs ────────────────────────────────
 * → {ok, venues: [{id|null, name, is_published, buyout_room_id|null,
 *                  rooms: [{id, name, currency, base_price, is_entire_place, is_published}]}],
 *    labels: [string…],            // season labels in use, Standard/Mid/Peak first
 *    log_supported: bool,          // false → hide the change log, say "needs the migration"
 *    limits: {max_rooms: 50, max_nights: 1100, max_ranges: 50}}
 *   Every venue (published or not) and every room; unpublished rooms are
 *   marked (the UI labels them "hidden"). venue id null = rooms with no
 *   property. buyout_room_id = the property's single whole-property room when it
 *   also has another published room — show "Also update buyouts" when a selected
 *   room is in that venue and the buyout room is not selected (the preview's
 *   buyouts_available is the authoritative answer).
 *   (admin/rates.php may instead render this server-side with rate_editor_options().)
 *
 * ── action: "labels" ───────────────────────────────────────────────────────
 * → {ok, labels: [string…]}
 *
 * ── A change (the body of "preview" and "apply") ───────────────────────────
 *   rooms:          [room id…]                         1–50, de-duplicated
 *   ranges:         [[first, last], …]  (or [{from, to}] / [{first, last}]) 1–50 ranges, ≤ 1,100 nights in all
 *   mode:           "fixed" | "percent" | "match" | "base"
 *   amount:         number > 0                          (fixed; rooms must share a currency)
 *   pct:            number, -100 < pct ≤ 500, ≠ 0       (percent; e.g. 5 or -10)
 *   match_label:    string                              (match; a label in use)
 *   label:          string | null | absent              (ignored for base)
 *                     absent/null → keep each night's own label (fixed, percent);
 *                                   match defaults to match_label
 *                     "Mid season" → written on every changed night; "" → no label
 *   update_buyouts: bool, default false                 (the UI ticks it by default)
 * Modes:
 *   fixed   — every night = amount.
 *   percent — each PRICED night × (1 + pct/100), rounded half-up to the nearest
 *             KES 10 / 1 unit of any other currency. Nights with no price (≤ 0)
 *             are left alone (a note); a room with none is skipped.
 *   match   — each room's own most common price for match_label in the SAME
 *             calendar year as each night (a tie takes the higher price). A room
 *             without that season in a year leaves those nights alone (note), or
 *             is skipped when it lacks it for every night.
 *   base    — removes the overrides on those nights (room's own price applies).
 * A result ≤ 0 (fixed/percent/match) refuses the whole change (422).
 * Nights already at the target price AND label are not rewritten.
 * Buyouts: for each property touched with exactly one whole-property room that
 * is not selected, when a selected PUBLISHED non-whole-property room changes:
 * the buyout's price on each such night = the sum of the property's published
 * non-whole-property rooms' prices AFTER the change (rooms priced 0 or in
 * another currency are left out and named); label = the label most of those
 * rooms carry that night (a tie prefers a season over none, then the higher
 * season). Written only when update_buyouts is true.
 *
 * ── action: "preview" — nothing is written ─────────────────────────────────
 * → {ok, preview: Preview}
 * Preview = {
 *   mode, summary: "Zuri · 4 rooms · 1 Jul – 31 Aug 2027 · Mid season → KES 51,000",
 *   span: {first, last, nights},             // requested nights (inclusive)
 *   ranges: [{first, last}],                 // merged
 *   label: string|null, keep_label: bool,
 *   rooms: [{                                // selected rooms + any buyout, property order
 *     room_id, name, venue_id, venue_name, currency, is_published,
 *     is_buyout: bool,
 *     status: "change" | "unchanged" | "skipped",
 *     skipped_reason: string|null, notes: [string],
 *     nights: int,                           // nights that change
 *     before: {min, max}|null, after: {min, max}|null,   // own currency, over the changing nights
 *     before_text: "KES 48,000 – 51,000", after_text: "KES 51,000",
 *     labels_before: [string|null],          // null = base price, "Other rate" = unlabelled override
 *     labels_after:  [string|null],
 *     to_base: bool,                         // base mode
 *     runs: [{first, last, nights, price, label|null, base: bool}]   // the new prices, contiguous
 *   }],
 *   buyouts: [{venue_id, venue_name, room_id, name, applied: bool, nights,
 *              left_out: [{room_id, name, reason}], nights_skipped}],
 *   buyouts_available: bool,                 // show the "Also update buyouts" tick box
 *   totals: {rooms, nights, skipped, unchanged},   // rooms/nights = those that change
 *   log_supported: bool
 * }
 *
 * ── action: "apply" ────────────────────────────────────────────────────────
 * Same body as preview. Recomputed on the server (the client's preview is never
 * trusted), written with rates_apply_ranges() / rates_clear_span() and logged in
 * ONE transaction.
 * → {ok, applied: {change_id: int|null, logged: bool, log_note: string|null,
 *                  summary, totals, span: {first, last}, preview: Preview}}
 *   logged false (change_id null) before the migration — show log_note.
 *   422 "Nothing to change — these rates already match." when nothing differs.
 *
 * ── action: "log" ──────────────────────────────────────────────────────────
 *   limit: int 1–100, default 20
 * → {ok, supported: bool, changes: [{id, summary, created_at (ISO 8601),
 *     created_text: "30 Sep 2026, 14:05", by, rooms: [id…], room_names: [string…],
 *     span: {first, last}, undone: bool, undone_at: ISO|null, undone_by: string|null,
 *     can_undo: bool, undo_blocked: {code: "undone"|"newer"|"edited", message}|null}]}
 *   Newest first. supported false (changes []) before the migration.
 *
 * ── action: "undo" ─────────────────────────────────────────────────────────
 *   change_id: int
 * → {ok, undone: {change_id, summary, rooms: [id…], span: {first, last}}}
 *   422 when already undone, when a newer not-undone change overlaps the same
 *   rooms and nights ("Undo the newer change first: …"), or when the rates were
 *   edited elsewhere since ("These rates were edited elsewhere since — undo not
 *   possible."); 404 unknown id; 409 before the migration.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rate-editor.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$admin = current_admin();                 // starts the session
$data  = json_decode((string)file_get_contents('php://input'), true);
$r = rate_editor_dispatch(
    (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    is_array($data) ? $data : [],
    $admin,
    (string)($_SESSION['csrf_token'] ?? '')
);
http_response_code($r['status']);
echo json_encode($r['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
