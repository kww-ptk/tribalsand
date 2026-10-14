<?php
/**
 * Admin: Gate — the gate guard's screen. Today's arrivals, who is in house and
 * who leaves, for the viewer's properties (Front Desk data), with Check in /
 * Check out at the gate (add_gate_arrivals.sql). A guest's GATE PASS (the QR in
 * their booking page after online check-in) opens here: ?pass=GP-... shows the
 * party, where they are going and whether to admit them; ?code= finds a booking
 * by its booking code. Plus the walk-up visitor register (sign in / sign out).
 * Owner, managers, reception and gate-security staff (require_gate()).
 * Logic: includes/gate-pass.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';    // team (visitor) helpers
require_once __DIR__ . '/../includes/frontdesk.php';
require_once __DIR__ . '/../includes/checkin.php';   // checkin_badge() on arrival rows
require_once __DIR__ . '/../includes/gate-pass.php';
require_once __DIR__ . '/../includes/visitor-store.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';

// A pass scanned with a phone camera before signing in: come back to it after.
session_init();
$__passIn = gate_pass_from_scan((string)($_GET['pass'] ?? ''));
if (empty($_SESSION['admin_id']) && $__passIn !== '') $_SESSION['admin_after_login'] = '/admin/gate.php?pass=' . $__passIn;
$__vpIn = visitor_pass_from_scan((string)($_GET['vp'] ?? ''));
if (empty($_SESSION['admin_id']) && $__vpIn !== '') $_SESSION['admin_after_login'] = '/admin/gate.php?vp=' . $__vpIn;

require_login();
require_gate();

$pageTitle  = 'Gate';
$activeMenu = 'gate';

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

$meId     = (int)($_SESSION['admin_id'] ?? 0);
$venueIds = admin_venue_ids();                 // null = owner (all)
$inScope  = fn(int $vid): bool => $venueIds === null || in_array($vid, $venueIds, true);

// Venues in scope (for the sign-in form's property picker).
if ($venueIds === null) {
    $venues = db_query("SELECT id, name FROM venues ORDER BY sort_order, name")->fetchAll();
} elseif ($venueIds) {
    $ph = []; $p = [];
    foreach ($venueIds as $i => $v) { $n = ":v{$i}"; $ph[] = $n; $p[$n] = (int)$v; }
    $venues = db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', $ph) . ") ORDER BY sort_order, name", $p)->fetchAll();
} else {
    $venues = [];
}
$scopedVenueIds = array_map(fn($v) => (int)$v['id'], $venues);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ── Let a registered visitor in (scanned VP pass) ──
    if ($action === 'visitor_in') {
        $vpTok = visitor_pass_from_scan((string)($_POST['vp'] ?? ''));
        $vid   = $vpTok !== '' ? visitor_pass_verify($vpTok) : false;
        $res   = $vid ? visitor_let_in((int)$vid, frontdesk_today_ymd(), (int)($_POST['place_id'] ?? 0) ?: null, $meId, $venueIds)
                      : ['ok' => false, 'msg' => 'This visitor pass isn’t valid.'];
        $_SESSION['hold_flash'] = ['type' => $res['ok'] ? 'success' : 'error', 'msg' => $res['msg']];
        header('Location: /admin/gate.php' . ($vpTok !== '' && !$res['ok'] ? '?vp=' . $vpTok : '')); exit;
    }

    // ── Check a booked party in / out at the gate ──
    if (in_array($action, ['arrive', 'depart', 'undo'], true)) {
        $hid  = (int)($_POST['hold_id'] ?? 0);
        $bk   = $hid ? gate_pass_booking($hid) : null;
        $back = '/admin/gate.php' . (($p = gate_pass_from_scan((string)($_POST['pass'] ?? ''))) !== '' ? '?pass=' . $p : '?hold=' . $hid);
        if (!gate_arrivals_supported()) {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Gate check-in isn’t enabled yet — run add_gate_arrivals.sql.'];
        } elseif (!$bk || !$inScope((int)($bk['venue_id'] ?? 0))) {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'That booking isn’t at a property you cover.'];
        } elseif ($action === 'arrive') {
            if (gate_verdict($bk, frontdesk_today_ymd(), true)['level'] === 'stop') {
                $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'This booking can’t be checked in at the gate. Call reception.'];
            } else {
                $ppl    = max(1, min(99, (int)($_POST['people'] ?? 0) ?: (int)$bk['party']['total']));
                $booked = (int)$bk['party']['total'];
                // More people than booked: only after the guard confirmed the warning (the page
                // asks first; a post without the confirmation is refused — JS off, double tap).
                $over = gate_over_party($ppl, $booked);
                if ($over && ($_POST['over_ok'] ?? '') !== '1') {
                    $_SESSION['hold_flash'] = ['type'=>'error','msg'=>gate_over_party_message($ppl, $booked) . ' Nothing was saved.'];
                } else {
                    $_SESSION['hold_flash'] = gate_mark_arrived($hid, $meId, $ppl, $over ? 'booked for ' . $booked . ' — guard confirmed extra' : '')
                        ? ['type'=>'success','msg'=>($bk['guest_name'] ?: 'Guest') . ' checked in — ' . $ppl . ' ' . ($ppl === 1 ? 'person' : 'people') . '.'
                            . ($over ? ' Booked for ' . $booked . ' — tell reception.' : '')]
                        : ['type'=>'error','msg'=>'Already checked in.'];
                }
            }
        } elseif ($action === 'depart') {
            $_SESSION['hold_flash'] = gate_mark_departed($hid, $meId)
                ? ['type'=>'success','msg'=>($bk['guest_name'] ?: 'Guest') . ' checked out.']
                : ['type'=>'error','msg'=>'Already checked out.'];
        } else {
            $_SESSION['hold_flash'] = gate_undo($hid)
                ? ['type'=>'success','msg'=>'Gate check-in undone.']
                : ['type'=>'error','msg'=>'Nothing to undo.'];
        }
        header('Location: ' . $back); exit;
    }

    if (!visitors_supported()) {
        $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'The visitor register isn’t enabled yet — run add_visitors.sql.'];
        header('Location: /admin/gate.php'); exit;
    }

    if ($action === 'log') {
        $venue   = (int)($_POST['venue_id'] ?? 0);
        $name    = trim((string)($_POST['visitor_name'] ?? ''));
        $visiting = trim((string)($_POST['visiting'] ?? ''));
        $purpose = trim((string)($_POST['purpose'] ?? ''));
        $vehicle = trim((string)($_POST['vehicle'] ?? ''));
        if (!in_array($venue, $scopedVenueIds, true)) {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Choose a property you cover.'];
        } elseif ($name === '') {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Enter the visitor’s name.'];
        } else {
            db_query(
                "INSERT INTO visitors (venue_id, visitor_name, visiting, purpose, vehicle, logged_by)
                 VALUES (:v, :n, :vg, :p, :veh, :lb)",
                [':v'=>$venue, ':n'=>$name, ':vg'=>($visiting !== '' ? $visiting : null),
                 ':p'=>($purpose !== '' ? $purpose : null), ':veh'=>($vehicle !== '' ? $vehicle : null),
                 ':lb'=>$meId ?: null]
            );
            audit_log('visitor.in', 'visitor', (int)db()->lastInsertId(), $name);
            $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Visitor signed in.'];
        }
        header('Location: /admin/gate.php'); exit;
    }

    if ($action === 'signout') {
        $id = (int)($_POST['id'] ?? 0);
        $vr = $id ? fetch_visitor($id) : false;
        if ($vr && $inScope((int)$vr['venue_id'])) {
            $n = db_query("UPDATE visitors SET time_out = NOW() WHERE id = :id AND time_out IS NULL", [':id'=>$id])->rowCount();
            if ($n) { audit_log('visitor.out', 'visitor', $id, ''); $_SESSION['hold_flash'] = ['type'=>'success','msg'=>'Visitor signed out.']; }
            else    { $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Already signed out.']; }
        } else {
            $_SESSION['hold_flash'] = ['type'=>'error','msg'=>'Not your property.'];
        }
        header('Location: /admin/gate.php'); exit;
    }
}

$today  = frontdesk_today_ymd();
$day    = frontdesk_day($venueIds, $today);
$gateOn = gate_arrivals_supported();

// ── A scanned pass (?pass=GP-…) or a typed booking code (?code=…) ──
$passTok = $__passIn;
$codeIn  = trim((string)($_GET['code'] ?? ''));
$holdIn  = (int)($_GET['hold'] ?? 0);      // the Check in button on a list row (signed-in staff; scope checked below)
$passBk  = null; $passErr = '';
if ($passTok !== '' || $codeIn !== '' || $holdIn > 0) {
    $pid = $passTok !== '' ? gate_pass_verify($passTok) : ($holdIn > 0 ? $holdIn : gate_find_by_code($codeIn, $venueIds, $today));
    if ($pid) $passBk = gate_pass_booking((int)$pid);
    if (!$passBk) $passErr = $passTok !== '' ? 'This gate pass isn’t valid. Ask for the booking code and call reception.' : 'No booking found with that code for today at your properties.';
    elseif ($passTok === '') $passTok = gate_pass_token((int)$passBk['id']);
}
// A booking at a property this account doesn't cover shows only where it is for — never the party.
if ($passBk && !$inScope((int)($passBk['venue_id'] ?? 0))) {
    $passErr = 'This pass is for ' . ((string)($passBk['venue_name'] ?? '') ?: 'another property') . ', which you don’t cover. Do not admit — call reception.';
    $passBk = null;
}
$passVerdict = $passBk ? gate_verdict($passBk, $today, $inScope((int)($passBk['venue_id'] ?? 0))) : null;

// ── A scanned visitor pass (?vp=VP-…) ──
$vpTok = $__vpIn; $vpCard = null; $vpErr = '';
if ($vpTok !== '') {
    $vid = visitor_pass_verify($vpTok);
    $vpCard = $vid ? visitor_gate_card((int)$vid, $today, $venueIds) : null;
    if (!$vpCard) $vpErr = 'This visitor pass isn’t valid. Ask them to register, or register them on this tablet.';
}
$vpPlaces = $vpCard && !$vpCard['visit'] ? visitor_places(true, $venueIds) : [];

// Live "on site" count — everyone still signed in, independent of the log filters.
$onsite = count(fetch_visitors($venueIds, true));

// ── Visitor log filters + pagination ────────────────────────────
$scopeKey = in_array($_GET['scope'] ?? '', ['onsite','all'], true) ? $_GET['scope'] : 'onsite';
$dateKey  = trim((string)($_GET['vdate'] ?? ''));
if ($dateKey !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateKey)) $dateKey = '';

$pg   = paginate_params(25);
$vpaged = fetch_visitors_paged($venueIds, [
    'scope'  => $scopeKey,
    'date'   => $dateKey,
    'q'      => $pg['q'],
    'per'    => $pg['per'],
    'offset' => 0,   // placeholder; recomputed against meta below
]);
$meta = paginate_meta($vpaged['total'], $pg['page'], $pg['per']);
// Re-fetch the correct page slice now that offset is known.
$vpaged = fetch_visitors_paged($venueIds, [
    'scope' => $scopeKey, 'date' => $dateKey, 'q' => $pg['q'],
    'per'   => $meta['per'], 'offset' => $meta['offset'],
]);
$log = $vpaged['rows'];

// ── Swappable body (visitor log table + pager) — reused for AJAX + full page ──
ob_start(); ?>
<div class="card">
  <div class="card__body" style="padding:0">
    <?php if (!$log): ?>
    <?php dt_empty($pg['q'] !== '' ? 'No visitors match your search.' : 'No visitors match this filter.'); ?>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Visitor</th><th>Property</th><th>Visiting</th><th>Purpose</th><th>Vehicle</th><th>In</th><th>Out</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($log as $vr): $open = empty($vr['time_out']); ?>
        <tr<?= $open ? '' : ' style="opacity:.6"' ?>>
          <td><strong><?= e($vr['visitor_name']) ?></strong><?php if (visitor_reg_supported()): ?> <?= !empty($vr['registered_visitor_id']) ? '<span class="badge badge--green">Registered</span>' : '<span class="badge">Not registered</span>' ?><?php endif; ?></td>
          <td><?= e($vr['venue_name'] ?? '') ?></td>
          <td><?= !empty($vr['visiting']) ? e($vr['visiting']) : '<span class="text-muted">—</span>' ?></td>
          <td><?= !empty($vr['purpose']) ? e($vr['purpose']) : '<span class="text-muted">—</span>' ?></td>
          <td><?= !empty($vr['vehicle']) ? e($vr['vehicle']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-muted"><?= e(date('j M H:i', strtotime((string)$vr['time_in']))) ?></td>
          <td class="text-muted"><?= $open ? '<span class="badge badge--green">On site</span>' : e(date('j M H:i', strtotime((string)$vr['time_out']))) ?></td>
          <td>
            <div class="row-actions">
            <?php if ($open): ?>
            <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="signout"><input type="hidden" name="id" value="<?= (int)$vr['id'] ?>"><button class="btn-icon btn-icon--outline" title="Sign out visitor" aria-label="Sign out <?= e($vr['visitor_name']) ?>"><?= admin_icon('logout') ?></button></form>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php dt_pager($meta); ?>
  </div>
</div>
<?php
$dtBody = ob_get_clean();

// AJAX fragment: emit only the swappable body and stop.
if ($pg['ajax']) { echo $dtBody; exit; }

include __DIR__ . '/_layout.php';
?>

<?php
  // Reveal the visitor sign-in form on load only when a visitor sign-in just failed.
  $signInOpen = $flash && ($flash['type'] ?? '') === 'error' && $passBk === null && strpos((string)($flash['msg'] ?? ''), 'isitor') !== false;
  $arrivedN = count(array_filter($day['arriving'], fn($r) => !empty($r['gate_arrived_at'])));
  $leftN    = count(array_filter($day['departing'], fn($r) => !empty($r['gate_departed_at'])));
  $placeOf  = fn(array $r): string => trim(((string)($r['venue_name'] ?? '')) . ' · ' . ((string)($r['room_name'] ?? ''))
              . (!empty($r['unit_name']) && ($r['unit_name'] ?? '') !== ($r['room_name'] ?? '') ? ' · ' . $r['unit_name'] : ''), ' ·');
  // One row of a list: who, where, party, status, and the gate button.
  $gateRow = function (array $r, string $kind) use ($gateOn, $inScope): string {
      $vid = (int)($r['venue_id'] ?? 0);
      $n   = max(1, (int)($r['guest_count'] ?? 1));
      $in  = !empty($r['gate_arrived_at']); $out = !empty($r['gate_departed_at']);
      $badge = checkin_badge($r);
      ob_start(); ?>
      <div class="gt-row<?= $out ? ' is-out' : ($in ? ' is-in' : '') ?>">
        <div class="gt-row__who">
          <b><?= e($r['guest_name'] ?? 'Guest') ?><?php if (($r['status'] ?? '') !== 'confirmed'): ?> <span class="badge badge--orange">Pending</span><?php endif; ?></b>
          <span><?= e(trim(((string)($r['venue_name'] ?? '')) . ' · ' . ((string)($r['room_name'] ?? '')), ' ·')) ?> · <?= $n ?> <?= $n === 1 ? 'guest' : 'guests' ?><?php if (!empty($r['access_code'])): ?> · <span class="gt-code"><?= e((string)$r['access_code']) ?></span><?php endif; ?></span>
          <span class="gt-row__tags">
            <?php if ($badge): ?><span class="ci-badge <?= e($badge['class']) ?>"><?= e($badge['label']) ?></span><?php endif; ?>
            <?php if ($out): ?><span class="badge badge--grey">Left <?= e(date('H:i', strtotime((string)$r['gate_departed_at']))) ?></span>
            <?php elseif ($in): ?><span class="badge badge--green">In <?= e(date(date('Y-m-d', strtotime((string)$r['gate_arrived_at'])) === date('Y-m-d') ? 'H:i' : 'j M H:i', strtotime((string)$r['gate_arrived_at']))) ?><?= !empty($r['gate_people']) ? ' · ' . (int)$r['gate_people'] . ((int)$r['gate_people'] === 1 ? ' person' : ' people') : '' ?></span><?php endif; ?>
          </span>
        </div>
        <?php if ($gateOn && $inScope($vid)): ?>
        <div class="gt-row__act">
          <?php if ($kind === 'leave' && !$out): ?>
            <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="depart"><input type="hidden" name="hold_id" value="<?= (int)$r['id'] ?>"><button class="btn-outline btn-sm"><?= admin_icon('logout', 14) ?> Check out</button></form>
          <?php elseif ($kind !== 'leave' && !$in): ?>
            <a class="btn-primary btn-sm" href="/admin/gate.php?hold=<?= (int)$r['id'] ?>"><?= admin_icon('check', 14) ?> Check in</a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php return (string)ob_get_clean();
  };
?>
<style>
.gt-top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px}
.gt-find{display:flex;gap:8px;flex:1 1 280px;max-width:420px;margin:0}
.gt-find input{flex:1;min-width:0;padding:9px 12px;border:1px solid var(--border,#d9d2c6);border-radius:8px;font:inherit;font-size:16px;text-transform:uppercase}
.gt-find input::placeholder{text-transform:none}
.gt-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px}
.gt-kpi{background:#fff;border:1px solid var(--border,#e7e1d7);border-radius:12px;padding:14px 16px}
.gt-kpi b{display:block;font-size:26px;line-height:1.1}.gt-kpi span{color:var(--muted,#7a756c);font-size:13px}
.gt-lists{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(300px,100%),1fr));gap:16px;margin-bottom:16px}
.gt-row{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid var(--border,#efe9df)}
.gt-row:first-child{border-top:0}.gt-row.is-out{opacity:.6}
.gt-row__who{display:flex;flex-direction:column;gap:3px;min-width:0}
.gt-row__who>span{color:var(--muted,#7a756c);font-size:13px}
.gt-row__tags{display:flex;flex-wrap:wrap;gap:6px}.gt-row__tags:empty{display:none}
.gt-row__act{flex:0 0 auto}.gt-row__act form{margin:0}
.gt-code{font-family:ui-monospace,monospace;letter-spacing:.04em}
.gt-empty{padding:1.5rem;text-align:center;color:var(--muted,#7a756c)}
.gt-pass{border-radius:14px;border:2px solid #cfe7d8;background:#f3fbf6;padding:18px 20px;margin-bottom:16px}
.gt-pass--warn{border-color:#f1d9a8;background:#fff9ec}.gt-pass--stop{border-color:#f0c2bd;background:#fff3f2}
.gt-pass__verdict{font-weight:700;font-size:15px;margin-bottom:10px}
.gt-pass--ok .gt-pass__verdict{color:#1f7a4a}.gt-pass--warn .gt-pass__verdict{color:#9a6200}.gt-pass--stop .gt-pass__verdict{color:#b42318}
.gt-pass__name{font-size:22px;font-weight:700;margin:0 0 4px}
.gt-pass__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px 18px;margin:12px 0}
.gt-pass__grid dt{font-size:12px;color:var(--muted,#7a756c);text-transform:uppercase;letter-spacing:.04em}.gt-pass__grid dd{margin:2px 0 0;font-weight:600}
.gt-pass__names{margin:6px 0 0;padding:0;list-style:none;display:flex;flex-wrap:wrap;gap:6px}
.gt-pass__names li{background:#fff;border:1px solid var(--border,#e7e1d7);border-radius:999px;padding:3px 10px;font-size:13px}
.gt-pass__act{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:14px}.gt-pass__act form{display:flex;gap:10px;align-items:center;margin:0}
.gt-step{display:inline-flex;align-items:center;border:1px solid var(--border,#d9d2c6);border-radius:999px;background:#fff}
.gt-step button{width:38px;height:38px;border:0;background:none;cursor:pointer;display:grid;place-items:center}
.gt-over{color:#9a6200;font-weight:600;font-size:13px}.gt-over[hidden]{display:none}
.gt-step input{width:42px;text-align:center;border:0;font:inherit;font-size:16px;font-weight:700;background:none}
.gt-scan{position:fixed;inset:0;z-index:1000;background:rgba(10,20,20,.9);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:16px}
.gt-scan[hidden]{display:none}
.gt-scan video{width:min(92vw,460px);aspect-ratio:1;object-fit:cover;border-radius:16px;background:#000}
.gt-scan p{color:#fff;margin:0;text-align:center}
@media (max-width:640px){.gt-kpis{gap:8px}.gt-kpi{padding:10px}.gt-kpi b{font-size:20px}.gt-row{flex-wrap:wrap}}
</style>

<div class="page-header">
  <h1>Gate</h1>
  <div class="actions" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span class="text-muted"><?= e(date('l j M', strtotime($today))) ?></span>
    <button type="button" class="btn-primary btn-sm" id="gtScanBtn" data-help="gate-scan"><?= admin_icon('search', 15) ?> Scan gate pass</button>
    <?php if (visitors_supported() && $venues): ?>
    <?php if (visitor_reg_supported()): ?>
    <a class="btn btn-sm" href="/register.php?gate=1" data-no-shell data-help="gate-register"><?= admin_icon('plus', 15) ?> Register visitor</a>
    <?php endif; ?>
    <button type="button" class="btn-outline btn-sm" id="gateSignInToggle" aria-expanded="<?= $signInOpen ? 'true' : 'false' ?>" aria-controls="gateSignInCard"><?= admin_icon('plus', 15) ?> No phone? Sign in without registering</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? '') ?></div><?php endif; ?>

<?php if (!$gateOn): ?>
<div class="alert alert--error">Checking guests in at the gate needs the <code>add_gate_arrivals.sql</code> migration — ask the owner to run it (Settings → Your account → Migrations).</div>
<?php endif; ?>

<div class="gt-top">
  <form method="GET" action="/admin/gate.php" class="gt-find" data-help="gate-find">
    <input type="text" name="code" value="<?= e($codeIn) ?>" placeholder="Booking code" aria-label="Booking code" autocomplete="off" autocapitalize="characters">
    <button class="btn-outline btn-sm" type="submit">Find</button>
  </form>
</div>

<?php if ($passErr !== ''): ?>
<div class="gt-pass gt-pass--stop" id="gtPass"><div class="gt-pass__verdict">✕ <?= e($passErr) ?></div><a class="btn-outline btn-sm" href="/admin/gate.php">Close</a></div>
<?php elseif ($passBk): $pv = $passVerdict; $pp = $passBk['party']; $pin = !empty($passBk['gate_arrived_at']); $pout = !empty($passBk['gate_departed_at']); ?>
<div class="gt-pass gt-pass--<?= e($pv['level']) ?>" id="gtPass">
  <div class="gt-pass__verdict"><?= $pv['level'] === 'stop' ? '✕ ' : ($pv['level'] === 'warn' ? '! ' : '✓ ') ?><?= e($pv['msg']) ?></div>
  <p class="gt-pass__name"><?= e($passBk['guest_name'] ?: 'Guest') ?></p>
  <div class="text-muted"><?= e(gate_party_label($pp)) ?></div>
  <dl class="gt-pass__grid">
    <div><dt>Going to</dt><dd><?= e($placeOf($passBk)) ?></dd></div>
    <div><dt>Stay</dt><dd><?= e(date('D j M', strtotime((string)$passBk['check_in'])) . ' → ' . date('D j M', strtotime((string)$passBk['check_out']))) ?></dd></div>
    <div><dt>Booking code</dt><dd class="gt-code"><?= e((string)($passBk['access_code'] ?: '—')) ?></dd></div>
    <div><dt>Online check-in</dt><dd><?php $b = checkin_badge($passBk + ['ci_complete_count' => $passBk['party_done']]); echo $b ? e($b['label']) : 'Not needed'; ?></dd></div>
  </dl>
  <?php if ($pp['names']): ?>
  <div class="text-muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.04em">Guests</div>
  <ul class="gt-pass__names">
    <?php foreach ($pp['names'] as $g): ?><li><?= e($g['name']) ?><?= $g['child'] ? ' (child)' : '' ?></li><?php endforeach; ?>
    <?php if ($pp['unnamed'] > 0): ?><li>+ <?= (int)$pp['unnamed'] ?> not named yet</li><?php endif; ?>
  </ul>
  <?php endif; ?>
  <div class="gt-pass__act">
    <?php if ($gateOn && $pv['level'] !== 'stop' && !$pin): ?>
    <form method="POST" data-help="gate-checkin" class="gt-arrive" data-booked="<?= (int)$pp['total'] ?>">
      <input type="hidden" name="over_ok" value="">
      <?= csrf_field() ?><input type="hidden" name="action" value="arrive"><input type="hidden" name="hold_id" value="<?= (int)$passBk['id'] ?>"><input type="hidden" name="pass" value="<?= e($passTok) ?>">
      <span class="gt-step"><button type="button" data-step="-1" aria-label="One fewer"><?= admin_icon('minus', 14) ?></button><input type="text" inputmode="numeric" name="people" value="<?= (int)$pp['total'] ?>" aria-label="People arriving"><button type="button" data-step="1" aria-label="One more"><?= admin_icon('plus', 14) ?></button></span>
      <button class="btn-primary"><?= admin_icon('check', 15) ?> Check in</button>
      <span class="gt-over" hidden></span>
    </form>
    <?php elseif ($gateOn && $pin && !$pout): ?>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="depart"><input type="hidden" name="hold_id" value="<?= (int)$passBk['id'] ?>"><input type="hidden" name="pass" value="<?= e($passTok) ?>"><button class="btn-outline"><?= admin_icon('logout', 15) ?> Check out</button></form>
    <?php endif; ?>
    <?php if ($gateOn && $pin && $pv['level'] !== 'stop'): ?>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="undo"><input type="hidden" name="hold_id" value="<?= (int)$passBk['id'] ?>"><input type="hidden" name="pass" value="<?= e($passTok) ?>"><button class="btn-outline btn-sm" data-confirm="Undo this gate check-in?">Undo</button></form>
    <?php endif; ?>
    <a class="btn-outline btn-sm" href="/admin/gate.php">Close</a>
  </div>
</div>
<?php endif; ?>

<?php if ($vpErr !== ''): ?>
<div class="alert alert--error" style="margin-bottom:16px"><?= e($vpErr) ?></div>
<?php elseif ($vpCard): include __DIR__ . '/_gate_visitor.php'; endif; ?>

<div class="gt-kpis">
  <div class="gt-kpi"><b><?= count($day['arriving']) ?></b><span>Arriving<?= $gateOn ? ' · ' . $arrivedN . ' in' : '' ?></span></div>
  <div class="gt-kpi"><b><?= count($day['inhouse']) ?></b><span>In house</span></div>
  <div class="gt-kpi"><b><?= count($day['departing']) ?></b><span>Leaving<?= $gateOn ? ' · ' . $leftN . ' gone' : '' ?></span></div>
</div>

<div class="gt-lists" data-help="gate-lists">
  <?php foreach ([['Arriving today', $day['arriving'], 'arrive', 'No arrivals today.'],
                  ['In house', $day['inhouse'], 'stay', 'Nobody staying over.'],
                  ['Leaving today', $day['departing'], 'leave', 'No departures today.']] as [$title, $rows, $kind, $empty]): ?>
  <div class="card">
    <div class="card__head"><span class="card__title"><?= e($title) ?> (<?= count($rows) ?>)</span></div>
    <div class="card__body" style="padding:0">
      <?php if (!$rows): ?><div class="gt-empty"><?= e($empty) ?></div>
      <?php else: foreach ($rows as $r) echo $gateRow($r, $kind); endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="gt-scan" id="gtScan" hidden role="dialog" aria-modal="true" aria-label="Scan gate pass">
  <video id="gtScanVideo" playsinline muted></video>
  <p id="gtScanMsg">Point the camera at the guest’s gate pass.</p>
  <button type="button" class="btn-outline" id="gtScanClose" style="background:#fff">Close</button>
</div>

<?php if (!visitors_supported()): ?>
<div class="card"><div class="card__body"><p class="text-muted" style="margin:0">The visitor register isn’t enabled yet. Ask the owner to run the <code>add_visitors.sql</code> migration.</p></div></div>
<?php else: ?>

<div class="card" id="gateSignInCard" data-inline-form style="margin-bottom:16px<?= $signInOpen ? '' : ';display:none' ?>">
  <div class="card__head"><span class="card__title">Sign in a visitor</span></div>
  <div class="card__body" style="padding:20px 24px">
    <?php if (!$venues): ?>
      <p class="text-muted" style="margin:0">You have no properties assigned yet.</p>
    <?php else: ?>
    <form method="POST" style="display:flex;flex-wrap:wrap;gap:16px;align-items:end">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="log">
      <label style="flex:1 1 200px;min-width:0">Visitor name
        <input type="text" name="visitor_name" required placeholder="Enter visitor name" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
      </label>
      <label style="flex:1 1 200px;min-width:0">Property
        <select name="venue_id" required style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
          <?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label style="flex:1 1 200px;min-width:0">Visiting <span class="text-muted">(guest / room)</span>
        <input type="text" name="visiting" placeholder="Enter guest or room being visited" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
      </label>
      <label style="flex:1 1 200px;min-width:0">Purpose <span class="text-muted">(optional)</span>
        <input type="text" name="purpose" placeholder="Enter purpose of visit" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
      </label>
      <label style="flex:1 1 200px;min-width:0">Vehicle / plate <span class="text-muted">(optional)</span>
        <input type="text" name="vehicle" placeholder="Enter vehicle or plate number" style="display:block;width:100%;margin-top:4px;padding:8px;border:1px solid #d9d2c6;border-radius:6px">
      </label>
      <div style="flex:0 0 auto"><button type="submit" class="btn-primary">Sign in</button></div>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="page-header" style="margin-top:8px">
  <h2 style="margin:0;font-size:18px">Visitor log</h2>
  <span class="badge badge--blue"><?= (int)$onsite ?> on site</span>
</div>

<div class="dt" data-dt>
  <div class="dt-controls">
<form method="GET" action="/admin/gate.php" class="filters" id="gateLogFilters">
  <input type="hidden" name="q"   value="<?= e($pg['q']) ?>">
  <input type="hidden" name="per" value="<?= (int)$meta['per'] ?>">
  <label class="filter-field">Show
    <select name="scope" class="filter-select" aria-label="Filter by presence" onchange="this.form.submit()">
      <option value="onsite" <?= $scopeKey === 'onsite' ? 'selected' : '' ?>>On site now</option>
      <option value="all"    <?= $scopeKey === 'all'    ? 'selected' : '' ?>>All visitors</option>
    </select>
  </label>
  <label class="filter-field">Date
    <button type="button" class="dp-btn" data-dp-target="gateVdate" data-dp-placeholder="Any date" style="width:150px"><?= $dateKey !== '' ? e(date('j M Y', strtotime($dateKey))) : 'Any date' ?></button>
    <input type="hidden" id="gateVdate" name="vdate" value="<?= e($dateKey) ?>">
  </label>
</form>
    <?php dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search name, visiting, purpose or vehicle…']); ?>
  </div>
  <div class="dt-body" data-dt-body><?= $dtBody ?></div>
</div>

<?php endif; ?>

<script>
(function () {
  var btn  = document.getElementById('gateSignInToggle');
  var card = document.getElementById('gateSignInCard');
  if (btn && card) {
    btn.addEventListener('click', function () {
      var open = card.style.display !== 'none';
      card.style.display = open ? 'none' : '';
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
      if (!open) { var f = card.querySelector('input[name="visitor_name"]'); if (f) f.focus(); }
    });
  }
  // Gate-log date filter — auto-submit when a date is picked.
  var vd = document.getElementById('gateVdate');
  var lf = document.getElementById('gateLogFilters');
  if (vd && lf) vd.addEventListener('change', function () { lf.submit(); });

  // People stepper on the pass, with a warning when more people come than are booked.
  var arrive = document.querySelector('form.gt-arrive');
  function overMsg(n, booked) {
    return 'Booked for ' + booked + ' ' + (booked === 1 ? 'person' : 'people') + ' — you are letting in ' + n +
           '. Call reception before you let the extra ' + (n - booked === 1 ? 'person' : 'people') + ' in.';
  }
  function showOver() {
    if (!arrive) return;
    var booked = parseInt(arrive.getAttribute('data-booked'), 10) || 0;
    var n = parseInt(arrive.querySelector('[name="people"]').value, 10) || 0;
    var el = arrive.querySelector('.gt-over');
    var over = booked > 0 && n > booked;
    el.hidden = !over;
    el.textContent = over ? '! Booked for ' + booked : '';
  }
  document.querySelectorAll('.gt-step [data-step]').forEach(function (b) {
    b.addEventListener('click', function () {
      var inp = b.parentNode.querySelector('input');
      inp.value = Math.max(1, Math.min(99, (parseInt(inp.value, 10) || 1) + parseInt(b.getAttribute('data-step'), 10)));
      showOver();
    });
  });
  if (arrive) {
    arrive.querySelector('[name="people"]').addEventListener('input', showOver);
    arrive.addEventListener('submit', function (e) {
      var booked = parseInt(arrive.getAttribute('data-booked'), 10) || 0;
      var n = parseInt(arrive.querySelector('[name="people"]').value, 10) || 0;
      var ok = arrive.querySelector('[name="over_ok"]');
      if (!(booked > 0 && n > booked) || ok.value === '1' || !window.tsConfirm) return;   // no dialog: the server refuses it with the same warning
      e.preventDefault();
      var yes = function () { ok.value = '1'; arrive.submit(); };
      window.tsConfirm(overMsg(n, booked), yes, { title: 'More people than booked', label: 'Let ' + n + ' in' });
    });
  }

  // Scan a gate pass with the device camera (jsQR, as the clock kiosk). The
  // camera runs only while the scanner is open; it is released on close, on a
  // hit, and after a minute so a tablet never keeps it on.
  var sb = document.getElementById('gtScanBtn'), box = document.getElementById('gtScan');
  var video = document.getElementById('gtScanVideo'), msg = document.getElementById('gtScanMsg');
  var stream = null, raf = 0, timer = 0, canvas = document.createElement('canvas');
  var ctx = canvas.getContext('2d', { willReadFrequently: true });
  function stop() {
    if (raf) cancelAnimationFrame(raf); raf = 0;
    if (timer) clearTimeout(timer); timer = 0;
    if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); stream = null;
    if (box) box.hidden = true;
  }
  function go(tok) {
    stop();
    var href = '/admin/gate.php?pass=' + encodeURIComponent(tok);
    if (window.tsShellGo) window.tsShellGo(href); else window.location.href = href;
  }
  function tick() {
    if (!stream) return;
    if (video.readyState >= 2 && window.jsQR && video.videoWidth) {
      canvas.width = video.videoWidth; canvas.height = video.videoHeight;
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
      var code = window.jsQR(img.data, img.width, img.height);
      if (code && code.data) {
        var m = /GP-\d{1,10}-[0-9a-fA-F]{12}/.exec(code.data);
        if (m) { go(m[0]); return; }
        var vp = /VP-\d{1,10}-[0-9a-fA-F]{12}/.exec(code.data);
        if (vp) { stop(); var vh = '/admin/gate.php?vp=' + encodeURIComponent(vp[0]); if (window.tsShellGo) window.tsShellGo(vh); else window.location.href = vh; return; }
        msg.textContent = 'That QR code isn’t a Tribal Sand pass.';
      }
    }
    raf = requestAnimationFrame(tick);
  }
  function loadJsQR(cb) {
    if (window.jsQR) return cb();
    var s = document.createElement('script'); s.src = '/js/vendor/jsqr.js'; s.onload = cb; s.onerror = cb; document.head.appendChild(s);
  }
  if (sb && box) {
    sb.addEventListener('click', function () {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        if (window.tsToast) window.tsToast('This device can’t open the camera here. Type the booking code instead.', 'error');
        return;
      }
      box.hidden = false; msg.textContent = 'Point the camera at the guest’s gate pass.';
      loadJsQR(function () {
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (s) {
          if (box.hidden) { s.getTracks().forEach(function (t) { t.stop(); }); return; }
          stream = s; video.srcObject = s; video.play(); tick();
          timer = setTimeout(stop, 60000);
        }).catch(function () { msg.textContent = 'Camera not available. Allow camera access, or type the booking code.'; });
      });
    });
    document.getElementById('gtScanClose').addEventListener('click', stop);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && box && !box.hidden) stop(); });
  }
  var pass = document.getElementById('gtPass');
  if (pass) pass.scrollIntoView({ block: 'start' });
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
