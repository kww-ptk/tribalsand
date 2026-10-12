<?php
/**
 * Admin: POS PINs — the 4–6 digit code that unlocks a registered till.
 *
 *   • Everyone who can sell sets their OWN PIN here.
 *   • The owner can set/clear anyone's; a manager can set/clear the PIN of staff
 *     assigned to outlets they manage (a forgotten PIN, a lockout).
 * The owner's own PIN is only ever changed by the owner.
 *
 * PINs are password_hash()ed; trivial ones (1234, 0000…) are refused
 * (pos_pin_problem()); setting a PIN clears that person's lockout. A PIN does
 * nothing without a registered terminal (includes/pos-auth.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pos-auth.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_login();

$pageTitle  = 'POS PINs';
$activeMenu = 'pos_pins';
$supported  = pos_supported();
$self       = '/admin/pos-pins.php';
$me         = current_admin();
$role       = (string)($me['role'] ?? 'staff');

if ($supported && !pos_is_seller($me)) { $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'You are not set up to sell at a POS till.']; header('Location: ' . admin_home_url()); exit; }

/** People whose PIN this account may manage (excluding itself), keyed by id. */
function posp_people(array $me): array {
    if (!pos_supported()) return [];
    $role = (string)($me['role'] ?? '');
    if ($role === 'owner') {
        $rows = db_query("SELECT * FROM admin_users WHERE is_active = TRUE AND role <> 'owner' ORDER BY name, email")->fetchAll();
    } elseif ($role === 'manager') {
        $mine = pos_manageable_outlet_ids($me);
        if (!$mine) return [];
        $ph = []; $p = [':me' => (int)$me['id']];
        foreach ($mine as $i => $o) { $ph[] = ":o{$i}"; $p[":o{$i}"] = $o; }
        $rows = db_query("SELECT DISTINCT a.* FROM admin_users a JOIN pos_outlet_staff s ON s.admin_user_id = a.id
                           WHERE a.is_active = TRUE AND a.role = 'staff' AND a.id <> :me AND s.outlet_id IN (" . implode(',', $ph) . ")
                           ORDER BY a.name", $p)->fetchAll();
    } else {
        return [];
    }
    $out = [];
    foreach ($rows as $r) $out[(int)$r['id']] = $r;
    return $out;
}

$flash = $_SESSION['posp_flash'] ?? null; unset($_SESSION['posp_flash']);
$people = posp_people($me);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $uid = (int)($_POST['user_id'] ?? 0);
    $isSelf = $uid === (int)$me['id'];
    if (!$isSelf && !isset($people[$uid])) {
        $_SESSION['posp_flash'] = ['type' => 'error', 'msg' => 'You can’t change that person’s PIN.'];
        header('Location: ' . $self); exit;
    }
    $who = $isSelf ? 'Your' : (($people[$uid]['name'] ?? 'Their') . '’s');
    if (($_POST['action'] ?? '') === 'clear') {
        pos_set_pin($uid, '');
        audit_log('pos.pin_clear', 'admin_user', $uid, '');
        $_SESSION['posp_flash'] = ['type' => 'success', 'msg' => "{$who} PIN was removed — they can’t unlock a till until a new one is set."];
    } else {
        $pin = (string)($_POST['pin'] ?? '');
        $again = (string)($_POST['pin_again'] ?? '');
        if ($pin !== $again) $why = 'The two PINs don’t match.';
        else $why = pos_set_pin($uid, $pin);
        if ($why) {
            $_SESSION['posp_flash'] = ['type' => 'error', 'msg' => $why];
        } else {
            audit_log('pos.pin_set', 'admin_user', $uid, $isSelf ? 'self' : 'by manager');
            $_SESSION['posp_flash'] = ['type' => 'success', 'msg' => "{$who} PIN is set" . ($isSelf ? '' : ' — tell them in person, never by message') . '.'];
        }
    }
    header('Location: ' . $self . ($isSelf ? '' : '#u' . $uid)); exit;
}

/** A PIN form (new + confirm), styled numeric fields — no native chrome. */
function posp_form(int $uid, bool $hasPin, string $self): void { ?>
  <form method="POST" action="<?= $self ?>" class="posp-form" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= $uid ?>">
    <input name="pin" type="password" inputmode="numeric" pattern="\d{4,6}" maxlength="6" class="inp posp-pin" placeholder="New PIN" aria-label="New PIN" required autocomplete="new-password" data-pw-label="PIN" data-pw-pattern-msg="A PIN is 4 to 6 digits.">
    <input name="pin_again" type="password" inputmode="numeric" pattern="\d{4,6}" maxlength="6" class="inp posp-pin" placeholder="Repeat" aria-label="Repeat PIN" required autocomplete="new-password" data-pw-label="PIN" data-pw-match="pin" data-pw-pattern-msg="A PIN is 4 to 6 digits.">
    <button type="submit" name="action" value="set" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> <?= $hasPin ? 'Change' : 'Set PIN' ?></button>
    <?php if ($hasPin): ?><button type="submit" name="action" value="clear" formnovalidate class="btn-icon btn-icon--danger" data-confirm="Remove this PIN?" data-tip="Remove PIN" aria-label="Remove PIN"><?= admin_icon('trash', 15) ?></button><?php endif; ?>
  </form>
<?php }

include __DIR__ . '/_layout.php';
$__ini = fn(string $n) => mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($n)) ?: [], 0, 2))));
$__withPin = count(array_filter($people, fn($p) => !empty($p['pos_pin_hash'])));
$__team = $role === 'owner' || $role === 'manager';
?>
<div class="page-header"><h1><?= $people ? 'Staff PINs' : 'My till PIN' ?></h1></div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_pos.sql</code> migration (Settings → Your account → Migrations) to set up the POS.</div>
<?php else: ?>
<div class="posp-split<?= $__team ? '' : ' posp-split--solo' ?>">
  <div class="card posp-mine">
    <div class="posp-mine__top">
      <span class="posp-av posp-av--me"><?= admin_icon('lock', 18) ?></span>
      <div><b>Your PIN</b>
        <?= !empty($me['pos_pin_hash']) ? '<span class="badge badge--green">Set ' . e(date('j M Y', strtotime((string)$me['pos_pin_set_at']))) . '</span>' : '<span class="badge badge--orange">Not set</span>' ?></div>
    </div>
    <p class="text-muted posp-help">On a till tablet you tap your name and enter this PIN. Use 4–6 digits that aren’t easy to guess (not 1234, 0000 or a birth year). Five wrong tries lock it for 15 minutes.</p>
    <?php posp_form((int)$me['id'], !empty($me['pos_pin_hash']), $self); ?>
  </div>

  <?php if ($__team): ?>
  <div class="posp-team">
    <div class="pos-ribbon">
      <div><span>Team</span><b><?= count($people) ?></b></div>
      <div><span>With a PIN</span><b><?= $__withPin ?></b></div>
      <div><span>No PIN yet</span><b class="<?= count($people) - $__withPin ? 'posp-warn' : '' ?>"><?= count($people) - $__withPin ?></b></div>
    </div>
    <?php if (!$people): ?>
      <?php dt_empty($role === 'owner' ? 'No team accounts yet.' : 'No staff are assigned to your outlets yet — the owner assigns them under Setup → Outlets.'); ?>
    <?php else: ?>
    <div class="posp-cards">
      <?php foreach ($people as $p): $has = !empty($p['pos_pin_hash']); ?>
      <div class="card posp-card" id="u<?= (int)$p['id'] ?>">
        <div class="posp-card__top">
          <span class="posp-av"><?= e($__ini((string)($p['name'] ?: $p['email']))) ?></span>
          <span class="posp-card__who"><b><?= e($p['name'] ?: $p['email']) ?></b><small><?= e(pos_role_label($p)) ?></small></span>
          <?= $has ? '<span class="badge badge--green">PIN set</span>' : '<span class="badge badge--grey">No PIN</span>' ?>
        </div>
        <?php posp_form((int)$p['id'], $has, $self); ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<style>
.posp-split{display:grid;grid-template-columns:minmax(260px,320px) minmax(0,1fr);gap:16px;align-items:start}
.posp-split--solo{grid-template-columns:minmax(0,420px)}
.posp-mine{padding:18px;display:flex;flex-direction:column;gap:12px;position:sticky;top:12px}
.posp-mine__top{display:flex;align-items:center;gap:12px}
.posp-mine__top b{display:block;margin-bottom:2px}
.posp-help{font-size:12.5px;margin:0}
.posp-av{width:40px;height:40px;border-radius:50%;background:var(--brand);color:#fff;display:inline-grid;place-items:center;font-weight:700;font-size:13px;flex:none}
.posp-av--me{background:#e6f0f1;color:var(--brand);border-radius:11px}
.posp-team{min-width:0}
.posp-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(320px,100%),1fr));gap:12px}
.posp-card{padding:14px 16px;display:flex;flex-direction:column;gap:12px}
.posp-card__top{display:flex;align-items:center;gap:10px}
.posp-card__who{flex:1;min-width:0}
.posp-card__who b{display:block}
.posp-card__who small{display:block;color:var(--muted);font-size:12px}
.posp-warn{color:#b45309}
.posp-form{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto auto;gap:8px;align-items:center;margin:0}
.posp-form .pwfield{min-width:0;width:100%;display:block}
.posp-form .pwfield > .posp-pin{width:100%;letter-spacing:.25em;text-align:center}
.posp-form .pwfield__error,.posp-form .pwfield__caps{grid-column:1/-1}
.posp-mine .posp-form{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
.posp-mine .posp-form button{justify-self:start}
@media (max-width:480px){.posp-form{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}}
@media (max-width:900px){.posp-split{grid-template-columns:minmax(0,1fr)}.posp-mine{position:static}}
</style>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
