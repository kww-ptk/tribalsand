<?php
declare(strict_types=1);
/**
 * Trade portal — "Request to book". GET shows the option (re-quoted live), the net
 * price and the traveller form; POST (CSRF) saves the request through
 * agent_submit_request(), e-mails, and redirects (PRG) to the request list.
 * A request NEVER places a hold — reservations check the dates and place it
 * (Convert to Hold), so nothing here blocks inventory. Nothing from the form is
 * trusted for money: the price is re-derived from the agent row and the room.
 */
require_once __DIR__ . '/../includes/agent.php';

agent_require_login();
$agent = agent_current();

$in  = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$str = fn(string $k): string => trim((string)($in[$k] ?? ''));

$req = [
    'kind'        => ($str('venue') !== '' && $str('rooms') !== '') ? 'combo' : 'room',
    'room_slug'   => $str('room'),
    'venue_slug'  => $str('venue'),
    'rooms'       => agent_parse_rooms_param($str('rooms')),
    'check_in'    => $str('check_in'),
    'check_out'   => $str('check_out'),
    'adults'      => max(1, min(30, (int)($in['adults'] ?? 1))),
    'children'    => max(0, min(20, (int)($in['children'] ?? 0))),
    'guest_name'  => $str('guest_name'),
    'guest_email' => $str('guest_email'),
    'guest_phone' => $str('guest_phone'),
    'notes'       => $str('notes'),
];
$backUrl = '/agent/availability.php?' . http_build_query([
    'check_in' => $req['check_in'], 'check_out' => $req['check_out'],
    'adults' => $req['adults'], 'children' => $req['children'],
]);

// ── Resolve + quote what the link points at (read-only) ─────────────────────
$error = '';
$view  = null;
$stay  = agent_valid_stay($req['check_in'], $req['check_out']);
if ($stay === null) {
    $error = 'Those dates aren’t valid any more — please search again.';
} else {
    [$ci, $co, $nights] = $stay;
    $lines = [];
    $venue = false;
    if ($req['kind'] === 'room') {
        $room  = fetch_room_by_slug($req['room_slug']);
        $venue = ($room && agent_room_bookable($room))
            ? db_query('SELECT id, slug, name FROM venues WHERE id = :id AND is_published = TRUE', [':id' => $room['venue_id']])->fetch()
            : false;
        if ($room && $venue) $lines[] = ['room' => $room, 'units' => 1, 'quote' => agent_stay_quote($room, $agent, $ci, $co)];
    } else {
        $venue = db_query('SELECT id, slug, name FROM venues WHERE slug = :s AND is_published = TRUE', [':s' => $req['venue_slug']])->fetch();
        foreach ($venue ? $req['rooms'] : [] as $pick) {
            $room = fetch_room_by_slug($pick['slug']);
            if (!$room || !agent_room_bookable($room) || (int)$room['venue_id'] !== (int)$venue['id']) { $lines = []; break; }
            $lines[] = ['room' => $room, 'units' => $pick['units'], 'quote' => agent_stay_quote($room, $agent, $ci, $co)];
        }
    }
    if (!$venue || !$lines) {
        $error = 'That option isn’t available to book — please search again.';
    } else {
        $currency  = (string)$lines[0]['quote']['currency'];
        $published = 0.0;
        $net       = 0.0;
        $available = true;
        foreach ($lines as $l) {
            if ((int)$l['quote']['nights'] === 0 || $l['quote']['currency'] !== $currency) {
                $error = 'That option can’t be priced — please search again.';
                break;
            }
            $published += $l['quote']['published'] * $l['units'];
            $net       += $l['quote']['net']       * $l['units'];
            // Informational only: the request goes to reservations either way.
            $free = $req['kind'] === 'room'
                ? (bool) find_available_unit((int)$l['room']['id'], $ci, $co)
                : count_available_units((int)$l['room']['id'], $ci, $co, $l['room']) >= $l['units'];
            if (!$free) $available = false;
        }
        if ($error === '') {
            $view = [
                'venue' => $venue, 'lines' => $lines, 'ci' => $ci, 'co' => $co, 'nights' => $nights,
                'quote' => ['nights' => $nights, 'published' => round($published, 2), 'net' => round($net, 2),
                            'currency' => $currency, 'discount_pct' => agent_discount_pct($agent, (int)$venue['id'])],
                'available' => $available,
            ];
        }
    }
}

// ── POST: save the request, then e-mail, then PRG ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && $view !== null) {
    verify_csrf();
    $res = agent_submit_request($agent, $req, [
        'source_page' => site_url('/agent/request.php'),
        'utm_source'  => 'trade-portal',
        'utm_medium'  => 'agent-portal',
        'user_agent'  => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'ip'          => client_ip(),
    ]);
    if (!$res['ok']) {
        $error = $res['error'];
    } else {
        // A double submit reuses the earlier request — it was already e-mailed.
        if (empty($res['dedupe'])) agent_send_request_emails($agent, $res);   // best-effort, after the write
        header('Location: /agent/requests.php?sent=' . (int)$res['submission_id']);
        exit;
    }
}

$agentPageTitle = 'Request to book';
$agentActive    = 'availability';
include __DIR__ . '/_layout.php';
?>
<h1>Request to book</h1>

<?php if ($error !== '' && $view === null): ?>
  <div class="alert alert--error"><?= e($error) ?></div>
  <p><a href="<?= e($backUrl) ?>">← Back to availability</a></p>
<?php else: $q = $view['quote']; $pct = (float)$q['discount_pct']; ?>
<div class="card"><div class="card__body card__body--pad">
  <h2><?= e($view['venue']['name']) ?></h2>
  <p class="tp-cardsub">Reservations check the dates and place the hold for you — nothing is held or charged until they confirm by email.</p>

  <div class="tp-summary">
    <div><small>Room<?= count($view['lines']) > 1 ? 's' : '' ?></small><span>
      <?php foreach ($view['lines'] as $l): ?><?= e($l['room']['name']) ?><?= $l['units'] > 1 ? ' ×' . (int)$l['units'] : '' ?><br><?php endforeach; ?>
    </span></div>
    <div><small>Dates</small><span><?= e(date('D j M Y', strtotime($view['ci']))) ?> → <?= e(date('D j M Y', strtotime($view['co']))) ?></span></div>
    <div><small>Nights</small><span><?= (int)$view['nights'] ?></span></div>
    <div><small>Guests</small><span><?= (int)$req['adults'] ?> adult<?= $req['adults'] === 1 ? '' : 's' ?><?= $req['children'] ? ', ' . (int)$req['children'] . ' child' . ($req['children'] === 1 ? '' : 'ren') : '' ?></span></div>
    <div><small>Published</small><span><?= $q['published'] > 0 ? e(format_price((float)$q['published'], $q['currency'])) : 'On request' ?></span></div>
    <div><small>Your rate<?= $pct > 0 ? ' · ' . e(agent_pct_label($pct)) . '% off' : '' ?></small><span class="tp-net"><?= $q['published'] > 0 ? e(format_price((float)$q['net'], $q['currency'])) : 'On request' ?></span></div>
  </div>

  <?php if ($error !== ''): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$view['available']): ?>
    <div class="alert alert--info">These dates currently show as unavailable. You can still send the request — reservations will check and suggest alternatives if needed.</div>
  <?php endif; ?>

  <form method="POST" action="/agent/request.php" novalidate
        onsubmit="var b=this.querySelector('button[type=submit]');if(b.disabled)return false;b.disabled=true;b.textContent='Sending…';return true;">
    <?= csrf_field() ?>
    <input type="hidden" name="room"      value="<?= e($req['room_slug']) ?>">
    <input type="hidden" name="venue"     value="<?= e($req['venue_slug']) ?>">
    <input type="hidden" name="rooms"     value="<?= e(agent_rooms_param($req['rooms'])) ?>">
    <input type="hidden" name="check_in"  value="<?= e($view['ci']) ?>">
    <input type="hidden" name="check_out" value="<?= e($view['co']) ?>">
    <input type="hidden" name="adults"    value="<?= (int)$req['adults'] ?>">
    <input type="hidden" name="children"  value="<?= (int)$req['children'] ?>">
    <div class="tp-form" style="margin-bottom:14px">
      <div class="field"><label for="rqName">Travelling guest’s name</label><input type="text" id="rqName" class="inp" name="guest_name" value="<?= e($req['guest_name']) ?>" required autofocus style="width:100%"></div>
      <div class="field"><label for="rqEmail">Guest’s email (optional)</label><input type="email" id="rqEmail" class="inp" name="guest_email" value="<?= e($req['guest_email']) ?>" style="width:100%"></div>
      <div class="field"><label for="rqPhone">Guest’s phone (optional)</label><input type="tel" id="rqPhone" class="inp" name="guest_phone" value="<?= e($req['guest_phone']) ?>" style="width:100%"></div>
    </div>
    <div class="field"><label for="rqNotes">Notes for reservations (optional)</label><textarea id="rqNotes" class="inp inp--area" name="notes" rows="3" style="width:100%"><?= e($req['notes']) ?></textarea></div>
    <p class="tp-note" style="margin-bottom:14px">We’ll write to you at <strong><?= e($agent['email']) ?></strong> — you are the contact for this booking; the traveller is not emailed.</p>
    <button type="submit" class="btn-primary">Request to book</button>
    <a href="<?= e($backUrl) ?>" class="btn-outline btn-sm" style="margin-left:8px">Back to availability</a>
  </form>
</div></div>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
