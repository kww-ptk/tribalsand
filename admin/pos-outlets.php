<?php
/**
 * Admin: POS outlets (owner-only) — the tills staff sell from.
 * Per outlet: name, kind, property, currency, service charge, room charge on/off,
 * who may sell there, which other outlets' items it also sells (cross-sell), and
 * its till categories (drag to reorder). All forms PRG + CSRF; reorders are AJAX.
 * Logic + rules: includes/pos.php · spec docs/pos/POS-PLAN.md.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pos.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_login();
require_owner();   // outlets, staff assignment and currencies are site-wide config

$pageTitle  = 'POS outlets';
$activeMenu = 'pos_outlets';
$supported  = pos_supported();
$self       = '/admin/pos-outlets.php';

function posx_flash(string $type, string $msg): void { $_SESSION['posx_flash'] = ['type' => $type, 'msg' => $msg]; }
function posx_back(int $open = 0): void { global $self; header('Location: ' . $self . ($open ? '?open=' . $open . '#outlet-' . $open : '')); exit; }
function posx_json(array $p): void { header('Content-Type: application/json'); exit(json_encode($p)); }
function posx_ids(string $key): array { return array_values(array_unique(array_filter(array_map('intval', (array)($_POST[$key] ?? []))))); }

$flash = $_SESSION['posx_flash'] ?? null; unset($_SESSION['posx_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    $oid = (int)($_POST['outlet_id'] ?? 0);
    $outlet = $oid ? pos_fetch_outlet($oid) : false;

    if ($act === 'outlet_add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $kind = isset(POS_KINDS[$_POST['kind'] ?? '']) ? $_POST['kind'] : 'other';
        if ($name === '' || mb_strlen($name) > 120) { posx_flash('error', 'Give the outlet a name (up to 120 characters).'); posx_back(); }
        $slug = pos_slugify($name); $base = $slug; $n = 2;
        while (db_query('SELECT 1 FROM pos_outlets WHERE slug = :s', [':s' => $slug])->fetchColumn()) { $slug = substr($base, 0, 55) . '-' . $n++; }
        $max = (int) db_query('SELECT COALESCE(MAX(sort_order), -1) FROM pos_outlets')->fetchColumn();
        db_query('INSERT INTO pos_outlets (name, slug, kind, currency, sort_order) VALUES (:n, :s, :k, :c, :o)',
            [':n' => $name, ':s' => $slug, ':k' => $kind, ':c' => POS_DEFAULT_CURRENCY, ':o' => $max + 1]);
        $new = (int) db()->lastInsertId();
        audit_log('pos.outlet_add', 'pos_outlet', $new, $name);
        posx_flash('success', "{$name} added — set its property, staff and categories below.");
        posx_back($new);
    }

    if ($act === 'outlet_reorder') {
        foreach (array_values(array_map('intval', (array)(json_decode($_POST['order'] ?? '[]', true) ?: []))) as $i => $id) {
            db_query('UPDATE pos_outlets SET sort_order = :o WHERE id = :id', [':o' => $i, ':id' => $id]);
        }
        posx_json(['ok' => true]);
    }

    if (!$outlet) {
        if (str_ends_with($act, '_reorder')) posx_json(['ok' => false]);
        posx_flash('error', 'That outlet no longer exists.'); posx_back();
    }

    if ($act === 'outlet_save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $kind = isset(POS_KINDS[$_POST['kind'] ?? '']) ? $_POST['kind'] : 'other';
        $vid  = (int)($_POST['venue_id'] ?? 0);
        $vid  = $vid && db_query('SELECT 1 FROM venues WHERE id = :v', [':v' => $vid])->fetchColumn() ? $vid : null;
        $cur  = strtoupper((string)($_POST['currency'] ?? ''));
        $pct  = (string)($_POST['service_charge_pct'] ?? '0');
        if ($name === '' || mb_strlen($name) > 120)            { posx_flash('error', 'Give the outlet a name (up to 120 characters).'); posx_back($oid); }
        if (!isset(TS_CURRENCIES[$cur]))                        { posx_flash('error', 'Pick a currency from the list.'); posx_back($oid); }
        if (!is_numeric($pct) || (float)$pct < 0 || (float)$pct > 100) { posx_flash('error', 'Service charge must be between 0 and 100%.'); posx_back($oid); }
        $vat = (string)($_POST['vat_pct'] ?? '0');
        if (!is_numeric($vat) || (float)$vat < 0 || (float)$vat > 100) { posx_flash('error', 'VAT must be between 0 and 100%.'); posx_back($oid); }
        $v2 = pos_v2_supported();
        // Room charge: which properties' guests may charge here. None ticked = all.
        $chargeVenues = array_values(array_intersect(posx_ids('charge_venues'), array_map(fn($v) => (int)$v['id'], db_query('SELECT id FROM venues')->fetchAll())));
        // Changing currency re-prices nothing, so warn when the outlet already has sales.
        $hadSales = strtoupper((string)$outlet['currency']) !== $cur
            && db_query('SELECT 1 FROM pos_sales WHERE outlet_id = :o LIMIT 1', [':o' => $oid])->fetchColumn();

        $staff = posx_ids('staff');
        $links = array_values(array_filter(posx_ids('links'), fn($x) => $x !== $oid));
        pos_tx(function () use ($oid, $name, $kind, $vid, $cur, $pct, $staff, $links, $vat, $v2, $chargeVenues): void {
            if ($v2) {
                db_query('UPDATE pos_outlets SET vat_pct = :v, vat_inclusive = :vi, tips_enabled = :t, require_signature = :sg WHERE id = :id',
                    [':v' => round((float)$vat, 2), ':vi' => !empty($_POST['vat_inclusive']) ? 'TRUE' : 'FALSE',
                     ':t' => !empty($_POST['tips_enabled']) ? 'TRUE' : 'FALSE', ':sg' => !empty($_POST['require_signature']) ? 'TRUE' : 'FALSE', ':id' => $oid]);
                db_query('DELETE FROM pos_outlet_charge_venues WHERE outlet_id = :o', [':o' => $oid]);
                foreach ($chargeVenues as $cv) db_query('INSERT INTO pos_outlet_charge_venues (outlet_id, venue_id) VALUES (:o, :v) ON CONFLICT DO NOTHING', [':o' => $oid, ':v' => $cv]);
            }
            db_query('UPDATE pos_outlets SET name = :n, kind = :k, venue_id = :v, currency = :c, service_charge_pct = :p,
                             allow_room_charge = :rc, is_active = :a WHERE id = :id',
                [':n' => $name, ':k' => $kind, ':v' => $vid, ':c' => $cur, ':p' => round((float)$pct, 2),
                 ':rc' => !empty($_POST['allow_room_charge']) ? 'TRUE' : 'FALSE',
                 ':a'  => !empty($_POST['is_active']) ? 'TRUE' : 'FALSE', ':id' => $oid]);
            db_query('DELETE FROM pos_outlet_staff WHERE outlet_id = :o', [':o' => $oid]);
            foreach ($staff as $u) {
                // Only real, non-owner accounts (the owner already sells everywhere).
                db_query("INSERT INTO pos_outlet_staff (outlet_id, admin_user_id)
                          SELECT :o, id FROM admin_users WHERE id = :u AND role <> 'owner' ON CONFLICT DO NOTHING", [':o' => $oid, ':u' => $u]);
            }
            db_query('DELETE FROM pos_outlet_links WHERE outlet_id = :o', [':o' => $oid]);
            foreach ($links as $s) {
                db_query('INSERT INTO pos_outlet_links (outlet_id, source_outlet_id)
                          SELECT :o, id FROM pos_outlets WHERE id = :s ON CONFLICT DO NOTHING', [':o' => $oid, ':s' => $s]);
            }
        });
        audit_log('pos.outlet_save', 'pos_outlet', $oid, $name);
        posx_flash($hadSales ? 'info' : 'success', $hadSales
            ? "{$name} saved. Its currency changed: earlier sales keep their original currency, and item prices were NOT converted — check them."
            : "{$name} saved.");
        posx_back($oid);
    }

    if ($act === 'outlet_delete') {
        if (db_query('SELECT 1 FROM pos_sales WHERE outlet_id = :o LIMIT 1', [':o' => $oid])->fetchColumn()) {
            db_query('UPDATE pos_outlets SET is_active = FALSE WHERE id = :o', [':o' => $oid]);
            posx_flash('info', "{$outlet['name']} has sales history, so it was closed instead of deleted.");
        } else {
            db_query('DELETE FROM pos_outlets WHERE id = :o', [':o' => $oid]);
            posx_flash('success', "{$outlet['name']} deleted.");
        }
        audit_log('pos.outlet_delete', 'pos_outlet', $oid, (string)$outlet['name']);
        posx_back();
    }

    if ($act === 'cat_add') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) { posx_flash('error', 'Category names are 1–80 characters.'); posx_back($oid); }
        $max = (int) db_query('SELECT COALESCE(MAX(sort_order), -1) FROM pos_categories WHERE outlet_id = :o', [':o' => $oid])->fetchColumn();
        db_query('INSERT INTO pos_categories (outlet_id, name, sort_order) VALUES (:o, :n, :s)', [':o' => $oid, ':n' => $name, ':s' => $max + 1]);
        posx_back($oid);
    }
    if ($act === 'cat_save') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name !== '' && mb_strlen($name) <= 80) {
            db_query('UPDATE pos_categories SET name = :n WHERE id = :c AND outlet_id = :o', [':n' => $name, ':c' => (int)($_POST['cat_id'] ?? 0), ':o' => $oid]);
        }
        posx_back($oid);
    }
    if ($act === 'cat_delete') {
        // Items in it fall back to "no category" (FK ON DELETE SET NULL) — nothing is lost.
        db_query('DELETE FROM pos_categories WHERE id = :c AND outlet_id = :o', [':c' => (int)($_POST['cat_id'] ?? 0), ':o' => $oid]);
        posx_back($oid);
    }
    if ($act === 'cat_reorder') {
        foreach (array_values(array_map('intval', (array)(json_decode($_POST['order'] ?? '[]', true) ?: []))) as $i => $id) {
            db_query('UPDATE pos_categories SET sort_order = :s WHERE id = :c AND outlet_id = :o', [':s' => $i, ':c' => $id, ':o' => $oid]);
        }
        posx_json(['ok' => true]);
    }
    posx_back($oid);
}

$outlets = $supported ? pos_fetch_outlets(null, false) : [];
$venues  = db_query('SELECT id, name FROM venues ORDER BY sort_order, name')->fetchAll();
$people  = $supported ? db_query(
    "SELECT id, name, email, role" . (db_query("SELECT 1 FROM information_schema.columns WHERE table_name = 'admin_users' AND column_name = 'job_type'")->fetchColumn() ? ', job_type' : ", NULL AS job_type") . "
       FROM admin_users WHERE role <> 'owner' AND is_active = TRUE ORDER BY name, email"
)->fetchAll() : [];
$openId  = (int)($_GET['open'] ?? 0);

$staffOf = []; $linksOf = []; $itemCount = [];
if ($supported) {
    foreach (db_query('SELECT outlet_id, admin_user_id FROM pos_outlet_staff')->fetchAll() as $r) $staffOf[(int)$r['outlet_id']][] = (int)$r['admin_user_id'];
    foreach (db_query('SELECT outlet_id, source_outlet_id FROM pos_outlet_links')->fetchAll() as $r) $linksOf[(int)$r['outlet_id']][] = (int)$r['source_outlet_id'];
    foreach (db_query('SELECT outlet_id, COUNT(*) AS n FROM pos_items WHERE is_active = TRUE GROUP BY outlet_id')->fetchAll() as $r) $itemCount[(int)$r['outlet_id']] = (int)$r['n'];
}
$v2 = $supported && pos_v2_supported();
$chargeOf = [];
if ($v2) foreach (db_query('SELECT outlet_id, venue_id FROM pos_outlet_charge_venues')->fetchAll() as $r) $chargeOf[(int)$r['outlet_id']][] = (int)$r['venue_id'];
$roleLabel = fn(array $p) => $p['role'] === 'staff' ? ucfirst((string)($p['job_type'] ?: 'frontdesk')) : ucfirst((string)$p['role']);

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>POS outlets</h1>
  <?php if ($supported): ?><a href="/admin/pos-items.php" class="btn-outline btn-sm"><?= admin_icon('grip', 15) ?> Catalogue</a><?php endif; ?>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_pos.sql</code> migration (Admin → Migrations), then <code>db/seeds/seed_pos.php</code> for the starter outlets.</div>
<?php else: ?>

<p class="text-muted posx-intro">Each outlet is a till: the Experiences desk, the shop, the spa, the kite school. Staff only see the outlets they are assigned to (managers also see every outlet at their property). <strong>Also sells from</strong> lets one desk ring up another outlet's items — the sale still counts for the outlet that owns the item. Drag the handle to reorder.</p>

<div class="posx-list" id="posxList">
<?php foreach ($outlets as $o):
    $oid = (int)$o['id']; $mine = $staffOf[$oid] ?? []; $lk = $linksOf[$oid] ?? [];
    $cats = pos_fetch_categories($oid); ?>
  <details class="card posx" id="outlet-<?= $oid ?>" data-id="<?= $oid ?>" <?= $openId === $oid ? 'open' : '' ?>>
    <summary class="posx__head">
      <span class="posx__grip" draggable="true" data-tip="Drag to reorder" aria-hidden="true"><?= admin_icon('grip', 18) ?></span>
      <span class="posx__title"><?= e($o['name']) ?></span>
      <span class="posx__meta">
        <span class="badge badge--grey"><?= e(POS_KINDS[$o['kind']] ?? $o['kind']) ?></span>
        <span class="badge badge--blue"><?= e($o['venue_name'] ?? 'All properties') ?></span>
        <span class="badge badge--teal"><?= e($o['currency']) ?><?= (float)$o['service_charge_pct'] > 0 ? ' · ' . e(rtrim(rtrim((string)$o['service_charge_pct'], '0'), '.')) . '% service' : '' ?><?= $v2 && (float)$o['vat_pct'] > 0 ? ' · VAT ' . e(rtrim(rtrim((string)$o['vat_pct'], '0'), '.')) . '%' : '' ?></span>
        <span class="text-muted"><?= (int)($itemCount[$oid] ?? 0) ?> items · <?= count($mine) ?> staff</span>
        <?php if (!pos_bool($o['is_active'])): ?><span class="badge badge--orange">Closed</span><?php endif; ?>
      </span>
      <svg class="posx__chev" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
    </summary>

    <div class="posx__body">
      <form method="POST" action="<?= $self ?>" class="posx-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="outlet_save">
        <input type="hidden" name="outlet_id" value="<?= $oid ?>">
        <div class="posx-grid">
          <div class="field"><label>Name</label><input name="name" class="inp" maxlength="120" value="<?= e($o['name']) ?>" required></div>
          <div class="field"><label>Kind</label>
            <select name="kind" class="eselect"><?php foreach (POS_KINDS as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $o['kind'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Property</label>
            <select name="venue_id" class="eselect"><option value="0">All properties (shared)</option>
              <?php foreach ($venues as $v): ?><option value="<?= (int)$v['id'] ?>" <?= (int)$o['venue_id'] === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Currency</label>
            <select name="currency" class="eselect"><?php foreach (TS_CURRENCIES as $c => $meta): ?><option value="<?= e($c) ?>" <?= strtoupper($o['currency']) === $c ? 'selected' : '' ?>><?= e($c . ' — ' . $meta['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Service charge (%)</label><input name="service_charge_pct" type="number" class="inp inp--num no-spin" min="0" max="100" step="0.01" value="<?= e(rtrim(rtrim((string)$o['service_charge_pct'], '0'), '.') ?: '0') ?>"></div>
          <?php if ($v2): ?>
          <div class="field"><label>VAT / tax (%)</label><input name="vat_pct" type="number" class="inp inp--num no-spin" min="0" max="100" step="0.01" value="<?= e(rtrim(rtrim((string)$o['vat_pct'], '0'), '.') ?: '0') ?>" placeholder="e.g. 16"></div>
          <?php endif; ?>
        </div>
        <div class="posx-toggles">
          <label class="togglerow"><span class="toggle"><input type="checkbox" name="allow_room_charge" value="1" <?= pos_bool($o['allow_room_charge']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Room charge — in-house guests can put it on their bill<?= strtoupper($o['currency']) !== strtoupper(setting('site_currency', 'USD')) ? ' <span class="badge badge--blue">converted to ' . e(strtoupper(setting('site_currency', 'USD'))) . ' on the bill at the day’s rate</span>' : '' ?></span></label>
          <label class="togglerow"><span class="toggle"><input type="checkbox" name="is_active" value="1" <?= pos_bool($o['is_active']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Open — shows on the till</span></label>
          <?php if ($v2): ?>
          <label class="togglerow"><span class="toggle"><input type="checkbox" name="vat_inclusive" value="1" <?= pos_bool($o['vat_inclusive']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Prices already include VAT <span class="text-muted">(off = VAT is added on top at the till)</span></span></label>
          <label class="togglerow"><span class="toggle"><input type="checkbox" name="tips_enabled" value="1" <?= pos_bool($o['tips_enabled']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Ask for a tip at checkout</span></label>
          <label class="togglerow"><span class="toggle"><input type="checkbox" name="require_signature" value="1" <?= pos_bool($o['require_signature']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Guest signs on the tablet for a room charge</span></label>
          <?php endif; ?>
        </div>

        <?php if ($v2): $cv = $chargeOf[$oid] ?? []; ?>
        <div class="posx-sub">Room charge — whose guests can charge here</div>
        <div class="posx-chips">
          <?php foreach ($venues as $v): ?>
          <label class="optchip"><input type="checkbox" name="charge_venues[]" value="<?= (int)$v['id'] ?>" <?= in_array((int)$v['id'], $cv, true) ? 'checked' : '' ?>><?= e($v['name']) ?></label>
          <?php endforeach; ?>
        </div>
        <p class="text-muted posx-hint">None ticked = guests of <strong>every</strong> property. Tick properties to limit it — e.g. the Tribal Dunes shop for Tribal Dunes, Maya Ilai and Off Duty guests. The till shows which property each guest is staying at, and the bill line says where it was bought.</p>
        <?php endif; ?>

        <div class="posx-sub">Who can sell here</div>
        <?php if (!$people): ?><p class="text-muted posx-hint">No staff accounts yet — add them under Admin → Team.</p><?php else: ?>
        <div class="posx-chips">
          <?php foreach ($people as $p): ?>
          <label class="optchip"><input type="checkbox" name="staff[]" value="<?= (int)$p['id'] ?>" <?= in_array((int)$p['id'], $mine, true) ? 'checked' : '' ?>><?= e($p['name'] ?: $p['email']) ?> <span class="posx-chip-role"><?= e($roleLabel($p)) ?></span></label>
          <?php endforeach; ?>
        </div>
        <p class="text-muted posx-hint">You (the owner) can sell everywhere. Managers already see every outlet at their property.</p>
        <?php endif; ?>

        <?php $others = array_filter($outlets, fn($x) => (int)$x['id'] !== $oid); if ($others): ?>
        <div class="posx-sub">Also sells from</div>
        <div class="posx-chips">
          <?php foreach ($others as $x): ?>
          <label class="optchip"><input type="checkbox" name="links[]" value="<?= (int)$x['id'] ?>" <?= in_array((int)$x['id'], $lk, true) ? 'checked' : '' ?>><?= e($x['name']) ?></label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="posx-actions">
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Save outlet</button>
        </div>
      </form>

      <div class="posx-sub">Till categories</div>
      <div class="posx-cats" data-outlet="<?= $oid ?>">
        <?php if (!$cats): ?><p class="text-muted posx-hint">No categories yet — the till then shows one "All" list.</p><?php endif; ?>
        <?php foreach ($cats as $c): ?>
        <form method="POST" action="<?= $self ?>" class="posx-cat" draggable="true" data-id="<?= (int)$c['id'] ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="outlet_id" value="<?= $oid ?>"><input type="hidden" name="cat_id" value="<?= (int)$c['id'] ?>">
          <span class="posx__grip" aria-hidden="true"><?= admin_icon('grip', 16) ?></span>
          <input name="name" class="inp" maxlength="80" value="<?= e($c['name']) ?>" aria-label="Category name">
          <button type="submit" name="action" value="cat_save" class="btn-icon btn-icon--primary" data-tip="Rename" aria-label="Save category"><?= admin_icon('check', 15) ?></button>
          <button type="submit" name="action" value="cat_delete" class="btn-icon btn-icon--danger" data-confirm="Delete this category? Its items stay, uncategorised." data-tip="Delete" aria-label="Delete category"><?= admin_icon('trash', 15) ?></button>
        </form>
        <?php endforeach; ?>
      </div>
      <form method="POST" action="<?= $self ?>" class="posx-catadd">
        <?= csrf_field() ?><input type="hidden" name="action" value="cat_add"><input type="hidden" name="outlet_id" value="<?= $oid ?>">
        <input name="name" class="inp" maxlength="80" placeholder="New category" required aria-label="New category">
        <button type="submit" class="btn-outline btn-sm"><?= admin_icon('plus', 14) ?> Add category</button>
      </form>

      <div class="posx-foot">
        <a href="/admin/pos-items.php?outlet=<?= $oid ?>" class="btn-outline btn-sm"><?= admin_icon('edit', 14) ?> Items</a>
        <form method="POST" action="<?= $self ?>" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="action" value="outlet_delete"><input type="hidden" name="outlet_id" value="<?= $oid ?>">
          <button type="submit" class="btn-icon btn-icon--danger" data-confirm="Delete <?= e($o['name']) ?>? An outlet with sales is closed instead." data-tip="Delete outlet" aria-label="Delete outlet"><?= admin_icon('trash', 16) ?></button>
        </form>
      </div>
    </div>
  </details>
<?php endforeach; ?>
</div>
<?php if (!$outlets): ?><?php dt_empty('No outlets yet — add one below, or run db/seeds/seed_pos.php for the starter four.', 'inbox'); ?><?php endif; ?>

<div class="card" style="margin-top:18px">
  <div class="card__head"><span class="card__title">Add an outlet</span></div>
  <div class="card__body" style="padding:16px 20px">
    <form method="POST" action="<?= $self ?>" class="ws-addform">
      <?= csrf_field() ?><input type="hidden" name="action" value="outlet_add">
      <label class="wsf wsf--grow"><span>Name</span><input name="name" class="inp" maxlength="120" placeholder="e.g. Beach Bar" required></label>
      <label class="wsf"><span>Kind</span><select name="kind" class="eselect"><?php foreach (POS_KINDS as $k => $lbl): ?><option value="<?= e($k) ?>"><?= e($lbl) ?></option><?php endforeach; ?></select></label>
      <button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add outlet</button>
    </form>
  </div>
</div>

<style>
.card__head{flex-wrap:wrap;gap:8px 12px}
.posx-intro{margin:-6px 0 18px;font-size:13px;max-width:820px}
.posx-list{display:grid;gap:12px}
.posx{overflow:hidden}
.posx__head{display:flex;align-items:center;gap:12px;padding:14px 18px;cursor:pointer;list-style:none}
.posx__head::-webkit-details-marker{display:none}
.posx__grip{color:var(--muted);cursor:grab;display:inline-flex}
.posx__title{font-weight:600;font-size:15px}
.posx__meta{display:flex;flex-wrap:wrap;align-items:center;gap:6px;font-size:12.5px;flex:1}
.posx__chev{color:var(--muted);transition:transform .15s}
.posx[open] .posx__chev{transform:rotate(180deg)}
.posx__body{border-top:1px solid var(--border);padding:18px}
.posx-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0 16px}
.posx-grid .inp{width:100%}
.posx-toggles{display:flex;flex-direction:column;gap:10px;margin:2px 0 6px}
.posx-sub{font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);margin:18px 0 8px}
.posx-chips{display:flex;flex-wrap:wrap;gap:8px}
.posx-chip-role{opacity:.65;font-size:11.5px;margin-left:2px}
.posx-hint{font-size:12.5px;margin:8px 0 0}
.posx-actions{margin-top:16px}
.posx-cats{display:grid;gap:6px;max-width:460px}
.posx-cat{display:flex;align-items:center;gap:8px;margin:0}
.posx-cat .inp{flex:1}
.posx-cat.is-dragging,.posx.is-dragging{opacity:.5}
.posx-catadd{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;max-width:460px}
.posx-catadd .inp{flex:1 1 160px;min-width:0}
.posx-cat .inp{min-width:0}
.posx-foot{display:flex;justify-content:space-between;align-items:center;margin-top:20px;padding-top:14px;border-top:1px solid var(--border)}
@media (max-width:640px){
  .posx__head{flex-wrap:wrap;padding:12px 14px;gap:8px}
  .posx__meta{flex-basis:100%;order:3}
  .posx__meta .text-muted{display:none}
  .posx__body{padding:14px}
  .posx-cat,.posx-catadd{max-width:none}
  .posx-foot{flex-wrap:wrap;gap:10px}
}
</style>
<script>
(function(){
  var CSRF = <?= json_encode(csrf_token()) ?>;
  function post(data){ var fd=new FormData(); Object.keys(data).forEach(function(k){ fd.append(k, data[k]); }); fd.append('csrf_token', CSRF);
    return fetch(<?= json_encode($self) ?>, {method:'POST', body:fd, credentials:'same-origin'}); }
  function sortable(list, rowSel, onDone){
    var dragged = null;
    list.querySelectorAll(rowSel).forEach(function(row){
      row.addEventListener('dragstart', function(e){ if (e.target !== row && !e.target.closest('.posx__grip')) return; dragged = row; row.classList.add('is-dragging'); e.stopPropagation(); });
      row.addEventListener('dragend',   function(){ if (!dragged) return; dragged.classList.remove('is-dragging'); dragged = null; onDone(); });
      row.addEventListener('dragover',  function(e){ if (dragged) e.preventDefault(); });
      row.addEventListener('dragenter', function(e){ if (!dragged || dragged === row || row.parentNode !== dragged.parentNode) return; e.preventDefault();
        var k=[].slice.call(list.querySelectorAll(rowSel)), di=k.indexOf(dragged), ri=k.indexOf(row); list.insertBefore(dragged, di<ri ? row.nextSibling : row); });
    });
    list.querySelectorAll('input').forEach(function(i){ i.addEventListener('mousedown', function(e){ e.stopPropagation(); }); });
  }
  // Outlet cards: the grip in the summary is the drag handle.
  var outlets = document.getElementById('posxList');
  if (outlets) {
    var dragged = null;
    outlets.querySelectorAll('.posx').forEach(function(card){
      var grip = card.querySelector('.posx__head .posx__grip');
      grip.addEventListener('click', function(e){ e.preventDefault(); });
      grip.addEventListener('dragstart', function(e){ dragged = card; card.classList.add('is-dragging'); e.dataTransfer.setDragImage(card, 20, 20); });
      grip.addEventListener('dragend', function(){ if (!dragged) return; dragged.classList.remove('is-dragging'); dragged = null;
        post({action:'outlet_reorder', order: JSON.stringify([].map.call(outlets.querySelectorAll('.posx'), function(x){ return x.dataset.id; }))}); });
      card.addEventListener('dragover', function(e){ if (dragged) e.preventDefault(); });
      card.addEventListener('dragenter', function(e){ if (!dragged || dragged === card) return; e.preventDefault();
        var k=[].slice.call(outlets.querySelectorAll('.posx')), di=k.indexOf(dragged), ri=k.indexOf(card); outlets.insertBefore(dragged, di<ri ? card.nextSibling : card); });
    });
  }
  document.querySelectorAll('.posx-cats').forEach(function(list){
    sortable(list, '.posx-cat', function(){
      post({action:'cat_reorder', outlet_id: list.dataset.outlet, order: JSON.stringify([].map.call(list.querySelectorAll('.posx-cat'), function(x){ return x.dataset.id; }))});
    });
  });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
