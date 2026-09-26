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
    <input name="pin" type="password" inputmode="numeric" pattern="\d{4,6}" maxlength="6" class="inp posp-pin" placeholder="New PIN" aria-label="New PIN" required autocomplete="new-password">
    <input name="pin_again" type="password" inputmode="numeric" pattern="\d{4,6}" maxlength="6" class="inp posp-pin" placeholder="Repeat" aria-label="Repeat PIN" required autocomplete="new-password">
    <button type="submit" name="action" value="set" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> <?= $hasPin ? 'Change' : 'Set PIN' ?></button>
    <?php if ($hasPin): ?><button type="submit" name="action" value="clear" formnovalidate class="btn-icon btn-icon--danger" data-confirm="Remove this PIN?" data-tip="Remove PIN" aria-label="Remove PIN"><?= admin_icon('trash', 15) ?></button><?php endif; ?>
  </form>
<?php }

include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1><?= $people ? 'POS PINs' : 'My till PIN' ?></h1></div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_pos.sql</code> migration (Admin → Migrations) to set up the POS.</div>
<?php else: ?>
<p class="text-muted" style="margin:-6px 0 18px;font-size:13px;max-width:760px">On a registered till tablet you tap your name and enter this PIN. Use 4–6 digits that aren’t easy to guess (not 1234, 0000 or your birth year). Five wrong tries lock you out for 15 minutes.</p>

<div class="card" style="margin-bottom:18px">
  <div class="card__head"><span class="card__title">Your PIN</span>
    <?= !empty($me['pos_pin_hash']) ? '<span class="badge badge--green">Set ' . e(date('j M Y', strtotime((string)$me['pos_pin_set_at']))) . '</span>' : '<span class="badge badge--orange">Not set</span>' ?></div>
  <div class="card__body" style="padding:16px 20px"><?php posp_form((int)$me['id'], !empty($me['pos_pin_hash']), $self); ?></div>
</div>

<?php if ($role === 'owner' || $role === 'manager'): ?>
<div class="card">
  <div class="card__head"><span class="card__title">Team</span><span class="text-muted" style="font-size:12.5px"><?= $role === 'owner' ? 'Everyone' : 'Staff at the outlets you manage' ?></span></div>
  <?php if (!$people): ?>
    <?php dt_empty($role === 'owner' ? 'No team accounts yet.' : 'No staff are assigned to your outlets yet — the owner assigns them under POS outlets.'); ?>
  <?php else: ?>
  <div class="table-wrap"><table class="data-table posp-table">
    <thead><tr><th>Person</th><th>PIN</th><th>Set / change</th></tr></thead>
    <tbody>
    <?php foreach ($people as $p): $has = !empty($p['pos_pin_hash']); ?>
      <tr id="u<?= (int)$p['id'] ?>">
        <td><strong><?= e($p['name'] ?: $p['email']) ?></strong><div class="text-muted" style="font-size:12px"><?= e(pos_role_label($p)) ?></div></td>
        <td><?= $has ? '<span class="badge badge--green">Set</span>' : '<span class="badge badge--grey">None</span>' ?></td>
        <td><?php posp_form((int)$p['id'], $has, $self); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<style>
.posp-form{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0}
.posp-pin{width:110px;letter-spacing:.3em;text-align:center}
.posp-table td{vertical-align:middle}
</style>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
