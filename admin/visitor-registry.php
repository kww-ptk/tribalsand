<?php
/**
 * Gate → Visitors: registered visitors this account covers. Search, a side drawer
 * with details / ID photo / group / visits / signed record, and Remove (owner +
 * manager, typed DELETE): the pass stops working and the ID photo is deleted;
 * the signed record and the gate log stay. Owner + managers also give STANDING
 * passes (member, friend, community, contractor: an end date + the places it
 * covers) to a visitor, or invite someone new with one (single-use register link).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/checkin.php';   // waiver_version(), checkin_id_type_label()
require_once __DIR__ . '/../includes/confirm.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/visitor-store.php';
require_login();
require_gate();

$pageTitle  = 'Visitors';
$activeMenu = 'gate';
$venueIds   = admin_venue_ids();                       // null = owner (all)
$me         = (int)($_SESSION['admin_id'] ?? 0);

// Places a pass may cover for this account: the owner → every place; a manager →
// only their properties' places ("all" for a manager = all of THEIR places).
$passPlaces = visitor_places(true, $venueIds);
$passPlaceIds = array_map(fn($p) => (int)$p['id'], $passPlaces);
$canPass = (is_owner() || is_manager()) && visitor_standing_supported();
/** The pass form's places: null = all (owner only), else the picked ids within reach. */
$postedPlaces = function () use ($venueIds, $passPlaceIds): ?array {
    if (($_POST['places'] ?? 'all') === 'all') return $venueIds === null ? null : $passPlaceIds;
    $ids = array_values(array_intersect(array_map('intval', (array)($_POST['place_ids'] ?? [])), $passPlaceIds));
    if (!$ids) throw new InvalidArgumentException('Choose at least one place, or all places.');
    return $ids;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $today = date('Y-m-d');   // the app runs in Africa/Nairobi
    $back = '/admin/visitor-registry.php';
    if ($action === 'remove') {
        if ((is_owner() || is_manager()) && visitor_in_scope($id, $venueIds, $me) && typed_confirmation_ok('DELETE') && visitor_remove($id)) {
            $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => 'Visitor removed. Their pass no longer works.'];
        } else {
            $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Nothing was removed.'];
        }
    } elseif (in_array($action, ['pass_set', 'pass_end', 'pass_invite'], true)) {
        try {
            if (!$canPass) throw new InvalidArgumentException('Only the owner or a manager can give standing passes.');
            if ($action === 'pass_invite') {
                $id = visitor_invite_standing((string)($_POST['full_name'] ?? ''), (string)($_POST['phone'] ?? ''), (string)($_POST['email'] ?? ''),
                    (string)($_POST['type'] ?? ''), trim((string)($_POST['until'] ?? '')), $postedPlaces(), (string)($_POST['note'] ?? ''), $me, $today);
                $sent = false;
                $nv = visitor_find($id);
                if ($nv && !empty($nv['email']) && function_exists('send_visitor_standing_invite')) {
                    try { $sent = send_visitor_standing_invite($nv); } catch (Throwable $e) { error_log('[visitor-registry] invite mail: ' . $e->getMessage()); }
                }
                $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => 'Pass created. ' . ($sent ? 'We emailed them their registration link.' : 'Send them the registration link below.')];
            } else {
                if (!visitor_in_scope($id, $venueIds, $me)) throw new InvalidArgumentException('That visitor isn’t at a property you cover.');
                if ($action === 'pass_set') {
                    visitor_set_standing($id, (string)($_POST['type'] ?? ''), trim((string)($_POST['until'] ?? '')), $postedPlaces(), (string)($_POST['note'] ?? ''), $me, $today);
                    $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => 'Standing pass saved.'];
                } else {
                    visitor_end_standing($id);
                    $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => 'Standing pass ended. They stay registered as a visitor.'];
                }
            }
            $back .= '?open=visitor-' . $id;
        } catch (InvalidArgumentException $e) {
            $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
            if ($id > 0) $back .= '?open=visitor-' . $id;
        }
    }
    header('Location: ' . $back);
    exit;
}

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

/** The pass form (give / change in the drawer, or invite someone new on the page). */
$vrPassForm = function (string $action, int $id, ?array $sp) use ($passPlaces, $venueIds): void {
    $sel = $sp['places'] ?? null; $uid = 'vrUntil' . $action . $id; ?>
    <form method="POST" action="/admin/visitor-registry.php" class="vr-pf"><?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= e($action) ?>"><?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
      <?php if ($action === 'pass_invite'): ?>
      <div class="vr-pf__row"><label>Full name<input class="inp" name="full_name" required placeholder="First name and surname"></label>
        <label>Phone<input class="inp" name="phone" type="tel"></label>
        <label>Email <span class="text-muted">(we send the link)</span><input class="inp" name="email" type="email"></label></div>
      <?php endif; ?>
      <div class="vr-pf__row">
        <label>Kind<select class="inp" name="type" required><?php foreach (VISITOR_PASS_TYPES as $k => $lbl): ?><option value="<?= e($k) ?>"<?= ($sp['type'] ?? '') === $k ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select></label>
        <label>Valid until <span class="text-muted">(empty = no end)</span>
          <input type="hidden" id="<?= e($uid) ?>" name="until" value="<?= e((string)($sp['until'] ?? '')) ?>">
          <span style="display:flex;gap:6px"><button type="button" class="dp-btn inp" style="flex:1" data-dp-target="<?= e($uid) ?>" data-dp-placeholder="No end date">No end date</button>
          <button type="button" class="btn-outline btn-sm" data-dp-clear="<?= e($uid) ?>">Clear</button></span></label>
      </div>
      <fieldset class="vr-pf__places"><legend>Where it counts</legend>
        <label class="optchip"><input type="radio" name="places" value="all"<?= $sel === null ? ' checked' : '' ?>> <?= $venueIds === null ? 'All places' : 'All my places' ?></label>
        <label class="optchip"><input type="radio" name="places" value="pick"<?= $sel !== null ? ' checked' : '' ?>> Only these:</label>
        <div class="vr-pf__chips"><?php foreach ($passPlaces as $p): ?><label class="optchip"><input type="checkbox" name="place_ids[]" value="<?= (int)$p['id'] ?>"<?= $sel !== null && in_array((int)$p['id'], $sel, true) ? ' checked' : '' ?>> <?= e((string)$p['name']) ?></label><?php endforeach; ?></div>
      </fieldset>
      <label>Note <span class="text-muted">(optional, staff only)</span><input class="inp" name="note" maxlength="300" value="<?= e((string)($sp['note'] ?? '')) ?>" placeholder="e.g. Beach club member 2026"></label>
      <button class="btn-primary btn-sm" style="margin-top:8px"><?= $action === 'pass_invite' ? 'Create pass and get the link' : 'Save pass' ?></button>
    </form>
<?php };

// ── Side drawer: one visitor (the list opens ?id=N&drawer=1) ──
if (!empty($_GET['drawer'])) {
    $id = (int)($_GET['id'] ?? 0);
    $v  = ($id > 0 && visitor_in_scope($id, $venueIds, $me)) ? visitor_find($id) : null;
    if (!$v) { http_response_code(404); exit('Not found'); }
    $g    = visitor_group($id);
    $hist = visitor_visit_history($id, $venueIds);
    $cur  = visitor_waiver_current($v, waiver_version(visitor_waiver_text()));
    ?>
    <div class="adrawer__head">
      <h2><?= e((string)$v['full_name']) ?></h2>
      <div class="badges">
        <?= $cur ? '<span class="badge badge--green">Signed</span>'
                 : (visitor_signed($v) ? '<span class="badge badge--orange">Terms changed — must sign again</span>' : '<span class="badge badge--grey">Not signed</span>') ?>
      </div>
    </div>
    <div class="adrawer__body">
      <div class="adrawer__sec">
        <dl class="adrawer__kv">
          <dt><?= e(checkin_id_type_label((string)$v['id_type'])) ?></dt><dd><?= e((string)(($v['id_number'] ?? '') ?: '—')) ?></dd>
          <dt>Nationality</dt><dd><?= e((string)(($v['nationality'] ?? '') ?: '—')) ?></dd>
          <dt>Phone</dt><dd><?= e((string)(($v['phone'] ?? '') ?: '—')) ?></dd>
          <dt>Email</dt><dd><?= e((string)(($v['email'] ?? '') ?: '—')) ?></dd>
          <dt>Registered</dt><dd><?= e(date('j M Y H:i', strtotime((string)$v['created_at']))) ?> · <?= e((string)$v['created_via']) ?></dd>
          <?php if (visitor_signed($v)): ?>
          <dt>Signed record</dt><dd><a href="/record.php?vid=<?= $id ?>" target="_blank" rel="noopener" data-no-shell><?= e((string)$v['waiver_reference']) ?></a></dd>
          <?php endif; ?>
        </dl>
      </div>
      <?php if (!empty($v['id_photo_key'])): ?>
      <div class="adrawer__sec"><h3>ID photo</h3>
        <img src="/admin/visitor-file.php?id=<?= $id ?>" alt="ID photo of <?= e((string)$v['full_name']) ?>" style="max-width:100%;border-radius:8px">
      </div>
      <?php endif; ?>
      <?php if ($g['adults'] || $g['children']): ?>
      <div class="adrawer__sec"><h3>Group</h3><ul style="margin:0;padding-left:18px">
        <?php foreach ($g['adults'] as $a): ?><li><?= e((string)$a['full_name']) ?> — <?= visitor_signed($a) ? 'signed' : 'not signed' ?></li><?php endforeach; ?>
        <?php foreach ($g['children'] as $c): ?><li><?= e((string)$c['full_name']) ?> (child, <?= (int)$c['age'] ?>)</li><?php endforeach; ?>
      </ul></div>
      <?php endif; ?>
      <?php if (visitor_standing_supported()):
        $sp = visitor_standing($v, date('Y-m-d'));
        $spNames = $sp && $sp['places'] !== null ? array_map(fn($p) => (string)$p['name'], array_filter(visitor_places(false), fn($p) => in_array((int)$p['id'], $sp['places'], true))) : [];
        $neverSigned = trim((string)($v['waiver_reference'] ?? '')) === ''; ?>
      <div class="adrawer__sec"><h3>Standing pass</h3>
        <?php if ($sp): ?>
        <p style="margin:0 0 8px"><span class="badge badge--<?= $sp['active'] ? 'green' : 'grey' ?>"><?= e($sp['label']) ?></span>
          <?= $sp['until'] === '' ? 'No end date' : ($sp['active'] ? 'Until ' : 'Ended ') . e(date('j M Y', strtotime($sp['until']))) ?>
          · <?= $sp['places'] === null ? 'All places' : e(implode(', ', $spNames)) ?></p>
        <?php if ($sp['note'] !== ''): ?><p class="text-muted" style="margin:0 0 8px"><?= e($sp['note']) ?></p><?php endif; ?>
        <?php else: ?><p class="text-muted" style="margin:0 0 8px">None — an ordinary visitor.</p><?php endif; ?>
        <?php if ($neverSigned && ($inv = visitor_invite_url($id)) !== ''): ?>
        <div class="alert alert--info" style="margin:8px 0">Not registered yet. Send them this link to register once and sign:
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
            <a class="btn-outline btn-sm" href="https://wa.me/<?= e(visitor_phone_digits((string)($v['phone'] ?? ''))) ?>?text=<?= e(rawurlencode('Hi ' . explode(' ', (string)$v['full_name'])[0] . ', here is your Tribal Sand pass. Register once (it takes two minutes) and you can enter whenever you visit: ' . $inv)) ?>" target="_blank" rel="noopener" data-no-shell>WhatsApp</a>
            <button type="button" class="btn-outline btn-sm" data-copy="<?= e($inv) ?>">Copy link</button>
          </div></div>
        <?php endif; ?>
        <?php if ($canPass): ?>
        <details class="vr-passform"<?= $sp ? '' : ' open' ?>><summary class="btn-outline btn-sm" style="display:inline-block;cursor:pointer;list-style:none"><?= $sp ? 'Change pass' : 'Give a standing pass' ?></summary>
          <?php $vrPassForm('pass_set', $id, $sp); ?>
        </details>
        <?php if ($sp): ?>
        <form method="POST" action="/admin/visitor-registry.php" style="margin:8px 0 0"><?= csrf_field() ?>
          <input type="hidden" name="action" value="pass_end"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn-outline btn-sm" data-confirm="End this standing pass? They stay registered as a visitor.">End pass</button>
        </form>
        <?php endif; endif; ?>
      </div>
      <?php endif; ?>
      <div class="adrawer__sec"><h3>Visits</h3>
        <?php if (!$hist): ?><p class="text-muted" style="margin:0">No visits yet.</p>
        <?php else: ?><ul style="margin:0;padding-left:18px">
          <?php foreach ($hist as $h): ?><li><?= e(date('j M Y H:i', strtotime((string)$h['time_in']))) ?> · <?= e((string)(($h['visiting'] ?? '') ?: ($h['venue_name'] ?? ''))) ?> · <?= (int)$h['people'] ?> <?= (int)$h['people'] === 1 ? 'person' : 'people' ?><?= $h['time_out'] ? '' : ' · on site' ?></li><?php endforeach; ?>
        </ul><?php endif; ?>
      </div>
    </div>
    <div class="adrawer__foot" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <?php /* A visitor registered on the tablet without an email has no pass on a phone: open their gate card here. */ ?>
      <a class="btn-primary btn-sm" href="/admin/gate.php?vp=<?= e(rawurlencode(visitor_pass_token($id))) ?>">Open at the gate</a>
      <?php if (is_owner() || is_manager()): ?>
      <form method="POST" action="/admin/visitor-registry.php" style="margin:0"><?= csrf_field() ?>
        <input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn-danger btn-sm" <?= danger_confirm_attrs('Remove ' . (string)$v['full_name'] . '? Their pass stops working and their ID photo is deleted. The signed record stays.', 'Remove visitor', 'Remove', 'DELETE') ?>>Remove visitor</button>
      </form>
      <?php endif; ?>
    </div>
    <?php
    exit;
}

$pg   = paginate_params(25);
$list = visitor_registry_list($venueIds, $me, $pg['q'], $pg['per'], 0);
$meta = paginate_meta($list['total'], $pg['page'], $pg['per']);
$list = visitor_registry_list($venueIds, $me, $pg['q'], $meta['per'], $meta['offset']);
$ver  = waiver_version(visitor_waiver_text());

ob_start(); ?>
<div class="card"><div class="card__body" style="padding:0">
  <?php if (!$list['rows']): dt_empty($pg['q'] !== '' ? 'No visitors match your search.' : 'No registered visitors yet.'); else: ?>
  <div class="table-wrap"><table class="data-table" data-help="vr-list">
    <thead><tr><th>Visitor</th><th>Phone</th><th>Last place</th><th>Last visit</th><th>Waiver</th></tr></thead>
    <tbody>
    <?php foreach ($list['rows'] as $r): $dUrl = '/admin/visitor-registry.php?id=' . (int)$r['id']; ?>
      <tr data-drawer="<?= e($dUrl) ?>" data-drawer-key="visitor-<?= (int)$r['id'] ?>" style="cursor:pointer">
        <td><a href="<?= e($dUrl) ?>" data-drawer="<?= e($dUrl) ?>" data-drawer-key="visitor-<?= (int)$r['id'] ?>"><strong><?= e((string)$r['full_name']) ?></strong></a><?php if (visitor_standing_supported() && ($rsp = visitor_standing($r, date('Y-m-d')))): ?> <span class="badge badge--<?= $rsp['active'] ? 'green' : 'grey' ?>"><?= e($rsp['label']) ?></span><?php endif; ?></td>
        <td><?= e((string)(($r['phone'] ?? '') ?: '—')) ?></td>
        <td><?= e((string)(($r['last_place'] ?? '') ?: '—')) ?></td>
        <td class="text-muted"><?= !empty($r['last_visit']) ? e(date('j M Y', strtotime((string)$r['last_visit']))) : '—' ?></td>
        <td><?= visitor_waiver_current($r, $ver) ? '<span class="badge badge--green">Signed</span>' : (visitor_signed($r) ? '<span class="badge badge--orange">Out of date</span>' : '<span class="badge badge--grey">Not signed</span>') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; dt_pager($meta); ?>
</div></div>
<?php $dtBody = ob_get_clean();
if ($pg['ajax']) { echo $dtBody; exit; }
include __DIR__ . '/_layout.php';
?>
<style>
.vr-pf{display:flex;flex-direction:column;gap:10px;margin-top:10px}
.vr-pf label{display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:500}
.vr-pf__row{display:flex;gap:10px;flex-wrap:wrap}.vr-pf__row>label{flex:1 1 180px}
.vr-pf__places{border:0;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:6px}
.vr-pf__places legend{font-size:13px;font-weight:500;margin-bottom:4px}
.vr-pf__places label.optchip{flex-direction:row;align-items:center;font-weight:400}
.vr-pf__chips{display:flex;flex-wrap:wrap;gap:6px;width:100%}
.vr-passform summary::-webkit-details-marker{display:none}
</style>
<div class="page-header"><h1>Visitors</h1>
  <?php if ($canPass): ?><button type="button" class="btn-primary btn-sm" aria-controls="vrNewPass" aria-expanded="false" onclick="var c=document.getElementById('vrNewPass');var o=c.style.display==='none';c.style.display=o?'':'none';this.setAttribute('aria-expanded',o?'true':'false');if(o&&window.initDatepickers)window.initDatepickers();"><?= admin_icon('plus', 15) ?> New standing pass</button><?php endif; ?>
</div>
<?php if ($canPass): ?>
<div class="card" id="vrNewPass" data-inline-form style="display:none;margin-bottom:16px">
  <div class="card__head"><span class="card__title">New standing pass</span></div>
  <div class="card__body card__body--pad">
    <p class="text-muted" style="margin:0">For members, friends, the local community or contractors. They register once with their ID and sign the waiver, then show their pass whenever they come. Already registered? Open them in the list below instead.</p>
    <?php $vrPassForm('pass_invite', 0, null); ?>
  </div>
</div>
<script>
(function () {
  if (window.__vrRegistryBound) return; window.__vrRegistryBound = true;
  // The drawer is loaded by fetch: wake the date pickers in it, and wire Copy.
  document.addEventListener('adrawer:loaded', function () { if (window.initDatepickers) window.initDatepickers(); });
  document.addEventListener('click', function (e) {
    var c = e.target.closest && e.target.closest('[data-dp-clear]');
    if (c) {   // back to "no end date"
      var inp = document.getElementById(c.getAttribute('data-dp-clear')), btn = c.parentNode.querySelector('.dp-btn');
      if (inp) inp.value = ''; if (btn) { btn.textContent = btn.getAttribute('data-dp-placeholder') || ''; btn.classList.remove('dp-btn--active'); }
      return;
    }
    var b = e.target.closest && e.target.closest('[data-copy]'); if (!b || !navigator.clipboard) return;
    navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () { b.textContent = 'Copied ✓'; });
  });
})();
</script>
<?php endif; ?>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? '') ?></div><?php endif; ?>
<?php if (!visitor_reg_supported()): ?>
<div class="alert alert--error">Run add_visitor_registration.sql (Settings → Your account → Migrations) to turn on visitor registration.</div>
<?php else:
  dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search name, phone, email or reference']); ?>
<div class="dt-body" data-dt-body><?= $dtBody ?></div>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
