<?php
declare(strict_types=1);
/**
 * Admin: Travel agents (owner-only). Create external agent accounts, set each
 * one's flat trade discount, activate/deactivate, reset password, delete.
 *
 * Agents are NOT admin_users — they live in travel_agents and sign in at
 * /agent, entirely separate from this panel. Their rate is always the published
 * price × (1 − discount) resolved at render time; nothing here stores a net rate.
 *
 * All mutations are PRG + CSRF. Per-venue discount overrides (venue_discounts)
 * are supported by the data model/portal but not yet exposed here — a v2 UI.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agent.php';
require_login();
require_owner();

$pageTitle  = 'Travel agents';
$activeMenu = 'agents';

$supported = agents_supported();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $flash  = ['type' => 'success', 'msg' => ''];
    try {
        if ($action === 'add') {
            $name   = trim($_POST['name']   ?? '');
            $agency = trim($_POST['agency'] ?? '');
            $email  = strtolower(trim($_POST['email'] ?? ''));
            $pass   = (string)($_POST['password'] ?? '');
            $disc   = max(0.0, min(100.0, (float)($_POST['discount_pct'] ?? 0)));
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
                $flash = ['type' => 'error', 'msg' => 'Name, a valid email and a password of at least 8 characters are required.'];
            } elseif (db_query('SELECT 1 FROM travel_agents WHERE email = :e', [':e' => $email])->fetchColumn()) {
                $flash = ['type' => 'error', 'msg' => 'An agent with that email already exists.'];
            } else {
                db_query(
                    'INSERT INTO travel_agents (name, agency, email, password_hash, discount_pct)
                     VALUES (:n, :a, :e, :h, :d)',
                    [':n' => $name, ':a' => $agency, ':e' => $email,
                     ':h' => password_hash($pass, PASSWORD_DEFAULT), ':d' => $disc]
                );
                $flash['msg'] = "Agent {$name} added.";
            }
        } elseif ($action === 'set_discount') {
            $id   = (int)($_POST['id'] ?? 0);
            $disc = max(0.0, min(100.0, (float)($_POST['discount_pct'] ?? 0)));
            db_query('UPDATE travel_agents SET discount_pct = :d WHERE id = :id', [':d' => $disc, ':id' => $id]);
            $flash['msg'] = 'Discount updated.';
        } elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            db_query('UPDATE travel_agents SET is_active = NOT is_active WHERE id = :id', [':id' => $id]);
            $flash['msg'] = 'Account status changed.';
        } elseif ($action === 'reset_password') {
            $id   = (int)($_POST['id'] ?? 0);
            $pass = (string)($_POST['password'] ?? '');
            if (strlen($pass) < 8) {
                $flash = ['type' => 'error', 'msg' => 'New password must be at least 8 characters.'];
            } else {
                db_query('UPDATE travel_agents SET password_hash = :h WHERE id = :id',
                    [':h' => password_hash($pass, PASSWORD_DEFAULT), ':id' => $id]);
                $flash['msg'] = 'Password reset.';
            }
        } elseif ($action === 'set_venue_discounts') {
            // Per-property overrides: keep only venues given a real 0..100 value;
            // a blank clears that venue back to the flat default. Store NULL when
            // nothing is overridden so agent_discount_pct() falls straight through.
            $id  = (int)($_POST['id'] ?? 0);
            $in  = is_array($_POST['venue_pct'] ?? null) ? $_POST['venue_pct'] : [];
            $map = [];
            foreach ($in as $vid => $val) {
                $val = trim((string)$val);
                if ($val === '' || !is_numeric($val)) continue;
                $map[(string)(int)$vid] = max(0.0, min(100.0, (float)$val));
            }
            db_query('UPDATE travel_agents SET venue_discounts = :j WHERE id = :id',
                [':j' => $map ? json_encode($map) : null, ':id' => $id]);
            $flash['msg'] = 'Per-property rates updated.';
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            db_query('DELETE FROM travel_agents WHERE id = :id', [':id' => $id]);
            $flash = ['type' => 'success', 'msg' => 'Agent removed.'];
        }
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => 'Could not complete that action.'];
    }
    $_SESSION['hold_flash'] = $flash;
    header('Location: /admin/agents.php');
    exit;
}

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

$agents = $supported
    ? db_query('SELECT * FROM travel_agents ORDER BY is_active DESC, name ASC')->fetchAll()
    : [];
// Published venues for the per-property override editor.
$venuesAll = $supported
    ? db_query('SELECT id, name FROM venues WHERE is_published = TRUE ORDER BY sort_order ASC, name ASC')->fetchAll()
    : [];

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <div>
    <h1>Travel agents</h1>
    <p class="text-muted" style="margin:4px 0 0;font-size:13px">External trade partners with their own read-only rate portal — set a discount and they see live net prices.</p>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? '') ?></div><?php endif; ?>

<?php if (!$supported): ?>
<div class="card"><div class="card__body"><p class="text-muted" style="margin:0">The trade portal isn’t enabled yet. Ask the owner to run the <code>add_travel_agents.sql</code> migration.</p></div></div>
<?php else: ?>

<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Add an agent</span></div>
  <div class="card__body">
    <form method="POST" action="/admin/agents.php">
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
        <div class="field"><label for="agName">Name</label><input id="agName" name="name" class="inp" required placeholder="Full name" style="width:100%"></div>
        <div class="field"><label for="agAgency">Agency</label><input id="agAgency" name="agency" class="inp" placeholder="e.g. Safari Travel Co." style="width:100%"></div>
        <div class="field"><label for="agEmail">Email</label><input id="agEmail" type="email" name="email" class="inp" required placeholder="agent@agency.com" style="width:100%"></div>
        <div class="field"><label for="agPass">Password (min 8)</label><input id="agPass" type="text" name="password" class="inp" required placeholder="At least 8 characters" style="width:100%"></div>
        <div class="field"><label for="agDisc">Discount %</label><input id="agDisc" type="number" name="discount_pct" class="inp" min="0" max="100" step="0.5" value="10" style="width:100%"></div>
      </div>
      <button type="submit" class="btn-primary btn-sm" style="margin-top:10px">Add agent</button>
    </form>
    <p class="text-muted" style="font-size:12.5px;margin:12px 0 0;line-height:1.65;max-width:760px">
      Agents log in separately at <code>/agent/login.php</code> — they only see rates there, never this admin panel.
      The discount is a flat % off your published nightly rate; their price is worked out <strong>live</strong>, so it updates on its own whenever you change a rate.
      Want different rates for certain properties? Open <strong>Per-property rates</strong> on the agent’s row below.
    </p>
  </div>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Agents</span></div>
  <div class="card__body" style="padding:0">
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Agent</th><th>Email</th><th>Discount</th><th>Status</th><th>Last sign-in</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (!$agents): ?>
        <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted)">No agents yet.</td></tr>
        <?php else: foreach ($agents as $a): $id = (int)$a['id']; ?>
        <tr>
          <td><strong><?= e($a['name']) ?></strong><?php if (trim((string)$a['agency']) !== ''): ?><br><span class="text-muted" style="font-size:12px"><?= e($a['agency']) ?></span><?php endif; ?></td>
          <td><?= e($a['email']) ?></td>
          <td>
            <form method="POST" action="/admin/agents.php" style="display:flex;gap:6px;align-items:center">
              <?= csrf_field() ?><input type="hidden" name="action" value="set_discount"><input type="hidden" name="id" value="<?= $id ?>">
              <input type="number" name="discount_pct" class="inp" min="0" max="100" step="0.5" value="<?= e(rtrim(rtrim(number_format((float)$a['discount_pct'], 2), '0'), '.')) ?>" style="width:74px">%
              <button class="btn-icon btn-icon--outline" title="Save discount" aria-label="Save discount"><?= admin_icon('check') ?></button>
            </form>
          </td>
          <td><span class="badge <?= $a['is_active'] ? 'badge--green' : 'badge--grey' ?>"><?= $a['is_active'] ? 'Active' : 'Inactive' ?></span></td>
          <td><?= !empty($a['last_login_at']) ? e(date('j M Y', strtotime((string)$a['last_login_at']))) : '<span class="text-muted">—</span>' ?></td>
          <td>
            <div class="row-actions" style="flex-wrap:wrap;gap:6px">
              <form method="POST" action="/admin/agents.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn-icon btn-icon--outline" title="<?= $a['is_active'] ? 'Deactivate' : 'Activate' ?>" aria-label="Toggle active"><?= admin_icon($a['is_active'] ? 'x' : 'check') ?></button></form>
              <form method="POST" action="/admin/agents.php" style="display:flex;gap:4px;align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= $id ?>"><input type="text" name="password" class="inp" placeholder="New password" style="width:120px"><button class="btn-icon btn-icon--outline" title="Reset password" aria-label="Reset password"><?= admin_icon('check') ?></button></form>
              <form method="POST" action="/admin/agents.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn-icon btn-icon--danger" title="Delete agent" aria-label="Delete agent" data-confirm="Delete <?= e($a['name']) ?>? This cannot be undone."><?= admin_icon('trash') ?></button></form>
            </div>
          </td>
        </tr>
        <?php
          // Current per-property overrides (JSONB comes back as a string).
          $ov = $a['venue_discounts'] ?? null;
          if (is_string($ov)) $ov = json_decode($ov, true);
          if (!is_array($ov)) $ov = [];
          $ovCount = count(array_filter($ov, fn($x) => is_numeric($x)));
        ?>
        <tr>
          <td colspan="6" style="padding:0 12px 12px">
            <details<?= $ovCount ? ' open' : '' ?>>
              <summary style="cursor:pointer;color:var(--muted);font-size:13px;padding:6px 0">Per-property rates <?= $ovCount ? '<span class="badge badge--blue">' . (int)$ovCount . ' set</span>' : '<span class="text-muted">(using ' . e(rtrim(rtrim(number_format((float)$a['discount_pct'],2),'0'),'.')) . '% everywhere)</span>' ?></summary>
              <?php if (!$venuesAll): ?>
                <p class="text-muted" style="font-size:12px;margin:6px 0 0">No published properties.</p>
              <?php else: ?>
              <form method="POST" action="/admin/agents.php" style="margin-top:8px">
                <?= csrf_field() ?><input type="hidden" name="action" value="set_venue_discounts"><input type="hidden" name="id" value="<?= $id ?>">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
                  <?php foreach ($venuesAll as $v): $vid=(int)$v['id']; $cur = isset($ov[(string)$vid]) && is_numeric($ov[(string)$vid]) ? rtrim(rtrim(number_format((float)$ov[(string)$vid],2),'0'),'.') : ''; ?>
                  <label style="display:flex;align-items:center;gap:6px;font-size:13px"><span style="flex:1"><?= e($v['name']) ?></span><input type="number" name="venue_pct[<?= $vid ?>]" class="inp" min="0" max="100" step="0.5" value="<?= e($cur) ?>" placeholder="def" style="width:70px">%</label>
                  <?php endforeach; ?>
                </div>
                <button class="btn-primary btn-sm" style="margin-top:8px">Save per-property rates</button>
                <span class="text-muted" style="font-size:12px;margin-left:8px">Blank = use the <?= e(rtrim(rtrim(number_format((float)$a['discount_pct'],2),'0'),'.')) ?>% default.</span>
              </form>
              <?php endif; ?>
            </details>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
