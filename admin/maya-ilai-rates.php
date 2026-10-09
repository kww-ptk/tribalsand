<?php
/**
 * Admin: Maya Ilai rates & settings — rates & fees, group discounts, availability
 * bands, saved to the `settings` KV (maya_ilai_pricing) via
 * includes/maya-ilai-pricing.php. These settings price Maya Ilai EVERYWHERE: the
 * guest website (api/maya-ilai-quote.php) and the main Quote builder
 * (qb_maya_ilai_price()). This page no longer quotes (Oct 2026, owner: one Quote
 * builder) and the unit map moved to Calendar → Ilai unit map (admin/unit-map.php).
 *
 * Gate: maya_ilai_tool_mode() — owner / Maya Ilai managers edit; reception at Maya
 * Ilai views only (saves refused server-side).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/maya-ilai-pricing.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();

// Owner + Maya Ilai managers edit; reception at Maya Ilai views only
// (maya_ilai_tool_mode()). Everyone else is sent home.
$miMode = maya_ilai_tool_mode();
if ($miMode === null) {
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'The Maya Ilai rate tool is only available to Maya Ilai managers and reception.'];
    header('Location: ' . admin_home_url()); exit;
}
$miCanEdit = $miMode === 'edit';

// ── AJAX save / reset (JSON, CSRF-in-body) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data  = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = (string)($data['csrf_token'] ?? '');
    if (($_SESSION['csrf_token'] ?? '') === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Invalid session token. Please reload.']));
    }
    $action = (string)($data['action'] ?? 'save');

    // Saving and resetting change prices for everyone — view-only accounts
    // (reception) are refused here, whatever the page let them click.
    if (!$miCanEdit) {
        http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'View only — ask the owner or the Maya Ilai manager to change rates.']));
    }
    try {
        if ($action === 'reset') {
            $state = maya_ilai_pricing_save(maya_ilai_pricing_defaults());
            audit_log('maya_ilai_rates.reset', 'venue', MAYA_ILAI_VENUE_ID, '');
        } else {
            $state = maya_ilai_pricing_save(is_array($data['state'] ?? null) ? $data['state'] : []);
            audit_log('maya_ilai_rates.save', 'venue', MAYA_ILAI_VENUE_ID, '');
        }
        exit(json_encode(['ok' => true, 'state' => $state]));
    } catch (Throwable $e) {
        error_log('[maya-ilai-rates] ' . $e->getMessage());
        http_response_code(500); exit(json_encode(['ok' => false, 'error' => 'Could not save. Please try again.']));
    }
}

$pageTitle  = 'Maya Ilai rates';
$activeMenu = 'maya_ilai_rates';
$state      = maya_ilai_pricing_get();

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Maya Ilai — Rates &amp; settings</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Dashboard</a>
</div>
<p class="text-muted" style="margin:-6px 0 18px;font-size:13px;max-width:760px">
  <?php if ($miCanEdit): ?>
  Maya Ilai's rates, group discounts and availability pricing (USD). Amber fields save to the
  server for the whole team. These settings price Maya Ilai on the guest website and in the
  Quote builder.
  <?php else: ?>
  Maya Ilai's rates, group discounts and availability pricing (USD) — what the guest website
  and the Quote builder charge. View only for your account.
  <?php endif; ?>
</p>
<?php $miCanQuote = is_owner() || is_reception() || access_page_granted('quote-builder.php'); /* = require_bookings() */ ?>
<div class="alert" style="margin:0 0 18px;max-width:760px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
  <span>Quotes for Maya Ilai are built in the <strong>Quote builder</strong>, priced with these settings.</span>
  <?php if ($miCanQuote): ?><a class="btn-outline btn-sm" href="/admin/quote-builder.php">Open the Quote builder &rarr;</a><?php endif; ?>
  <?php if (maya_ilai_tool_mode() !== null): ?><a class="btn-outline btn-sm" href="/admin/unit-map.php">Ilai unit map &rarr;</a><?php endif; ?>
</div>

<div id="mi-tool"<?= $miCanEdit ? '' : ' class="mi-viewonly"' ?>>
<style>
  #mi-tool{--navy:#182247;--navy2:#26335f;--teal:#168e86;--teal-soft:#e6f4f2;--ink:#24324a;--muted:#69758a;--line:#dfe5ec;--panel:#fff;--bg:#f4f7fa;--amber:#fff1cc;--amber-ink:#81520b;--red:#a3362a;--red-soft:#fff0ed;--shadow:0 8px 24px rgba(23,34,71,.06);--r:14px;color:var(--ink);font-size:15px}
  #mi-tool button,#mi-tool input,#mi-tool select{font:inherit}#mi-tool button{cursor:pointer}
  #mi-tool .mi-bar{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
  #mi-tool .status{font-size:.82rem;color:var(--muted)}
  #mi-tool .btn{border:1px solid var(--line);background:#fff;color:var(--navy);border-radius:10px;padding:8px 13px;font-weight:650}#mi-tool .btn:hover{background:var(--bg)}#mi-tool .btn.primary{background:var(--teal);border-color:var(--teal);color:#fff}#mi-tool .btn.danger{color:var(--red);border-color:#f0c8c2}
  #mi-tool nav.mi-nav{display:inline-flex;max-width:100%;overflow:auto;scrollbar-width:none;border:1.5px solid var(--line);border-radius:10px;background:#fff;margin-bottom:18px}#mi-tool .tab{white-space:nowrap;border:0;color:var(--muted);background:transparent;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer}#mi-tool .tab:hover{color:var(--teal)}#mi-tool .tab.active{background:var(--teal);color:#fff}
  #mi-tool .view{display:none}#mi-tool .view.active{display:block}
  #mi-tool .section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:16px}#mi-tool .section-head h2{font-size:1.25rem;margin:0;color:var(--navy)}#mi-tool .section-head p{margin:4px 0 0;color:var(--muted);font-size:.9rem}
  #mi-tool .grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(320px,.8fr);gap:20px}#mi-tool .stack{display:grid;gap:18px}
  #mi-tool .card{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden}#mi-tool .card-head{padding:15px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:12px}#mi-tool .card-head h3{font-size:1rem;margin:0;color:var(--navy)}#mi-tool .card-body{padding:18px}#mi-tool .subtle{font-size:.82rem;color:var(--muted)}
  #mi-tool .form-grid{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:14px}#mi-tool .field{display:grid;gap:6px}#mi-tool .field label{font-size:.78rem;color:var(--muted);font-weight:750}#mi-tool .field input,#mi-tool .field select{width:100%;border:1px solid #cfd7e2;border-radius:10px;padding:9px 11px;color:var(--ink);background:#fff;min-height:42px}#mi-tool .field input:focus,#mi-tool .field select:focus{outline:3px solid rgba(22,142,134,.14);border-color:var(--teal)}#mi-tool .help{font-size:.74rem;color:var(--muted)}
  #mi-tool .config{width:100%;border-collapse:collapse}#mi-tool .config th,#mi-tool .config td{padding:11px 9px;text-align:left;border-bottom:1px solid var(--line);vertical-align:middle}#mi-tool .config th{color:var(--muted);font-size:.74rem;text-transform:uppercase;letter-spacing:.04em}#mi-tool .config tr:last-child td{border-bottom:0}#mi-tool .unit-name{font-weight:760;color:var(--navy)}#mi-tool .unit-note{display:block;color:var(--muted);font-size:.74rem;margin-top:2px}#mi-tool .num{width:88px!important}#mi-tool .money{text-align:right!important;font-variant-numeric:tabular-nums;font-weight:700}#mi-tool .pill{display:inline-flex;padding:4px 8px;border-radius:999px;background:var(--teal-soft);color:#08736c;font-size:.72rem;font-weight:800}
  #mi-tool.mi-viewonly input:disabled,#mi-tool.mi-viewonly select:disabled{background:#f1f3f6;color:var(--ink);border-color:var(--line);cursor:not-allowed;opacity:1}
  #mi-tool.mi-viewonly button:disabled{opacity:.45;cursor:not-allowed}
  #mi-tool .summary{position:sticky;top:18px}#mi-tool .fee-note{margin:10px 0 0;font-size:.82rem;color:var(--muted,#64748b);line-height:1.45}#mi-tool .totalbox{background:linear-gradient(145deg,var(--navy),var(--navy2));color:#fff;padding:20px}#mi-tool .totalbox .label{color:#cdd5ea;font-size:.8rem}#mi-tool .grand{font-size:2rem;font-weight:820;letter-spacing:-.04em;margin:4px 0}#mi-tool .per{color:#cdd5ea}#mi-tool .metrics{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--line)}#mi-tool .metric{background:#fff;padding:13px 15px}#mi-tool .metric span{display:block;color:var(--muted);font-size:.72rem}#mi-tool .metric strong{font-size:1rem;color:var(--navy);font-variant-numeric:tabular-nums}#mi-tool .breakdown{padding:16px}#mi-tool .line{display:flex;justify-content:space-between;gap:16px;padding:6px 0;font-size:.86rem}#mi-tool .line span:first-child{color:var(--muted)}#mi-tool .line.total{border-top:1px solid var(--line);margin-top:7px;padding-top:12px;font-weight:800}#mi-tool .notice{margin-top:14px;padding:10px 13px;border-radius:10px;background:var(--teal-soft);color:#096c66;font-size:.8rem}#mi-tool .notice.error{background:var(--red-soft);color:var(--red)}#mi-tool .summary-actions{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:14px}
  #mi-tool .settings-grid{display:grid;grid-template-columns:repeat(3,minmax(230px,1fr));gap:18px}#mi-tool .setting-list{display:grid;gap:13px}#mi-tool .setting-row{display:grid;grid-template-columns:1fr 118px;gap:14px;align-items:center}#mi-tool .setting-row label{font-size:.86rem;font-weight:680}#mi-tool .setting-row small{display:block;color:var(--muted);font-weight:400}#mi-tool .setting-row input{width:100%;border:1px solid #ecd89d;background:var(--amber);color:var(--amber-ink);border-radius:9px;padding:8px;font-weight:750;text-align:right}
  #mi-tool .table-wrap{overflow:auto}#mi-tool .data-table{border-collapse:collapse;width:100%;min-width:640px}#mi-tool .data-table th,#mi-tool .data-table td{padding:10px 13px;border-bottom:1px solid var(--line);text-align:right;font-variant-numeric:tabular-nums}#mi-tool .data-table th{background:var(--navy);color:#fff;font-size:.74rem;letter-spacing:.02em;position:sticky;top:0}#mi-tool .data-table th:first-child,#mi-tool .data-table td:first-child{text-align:left}#mi-tool .data-table tbody tr:nth-child(even){background:#f7f9fb}#mi-tool .data-table .bad{color:var(--red);background:var(--red-soft);font-weight:750}#mi-tool .editable-table input{width:88px;background:var(--amber);border:1px solid #ead59b;border-radius:8px;padding:7px;color:var(--amber-ink);font-weight:750;text-align:right}#mi-tool .two-col{display:grid;grid-template-columns:1fr 1fr;gap:18px}
  #mi-tool .hide{display:none!important}
  @media(max-width:1050px){#mi-tool .grid,#mi-tool .two-col{grid-template-columns:1fr}#mi-tool .summary{position:static}#mi-tool .settings-grid{grid-template-columns:1fr 1fr}#mi-tool .form-grid{grid-template-columns:1fr 1fr}}
  @media(max-width:640px){#mi-tool .settings-grid,#mi-tool .form-grid{grid-template-columns:1fr}#mi-tool .config th:nth-child(4),#mi-tool .config td:nth-child(4){display:none}#mi-tool .grand{font-size:1.7rem}}
</style>

  <div class="mi-bar">
    <span class="status" id="saveStatus"><?= $miCanEdit ? 'Saved on the server' : 'View only — ask the owner or the Maya Ilai manager to change rates' ?></span>
    <?php if ($miCanEdit): ?><button class="btn danger" id="resetBtn" style="margin-left:auto">Reset to defaults</button><?php endif; ?>
  </div>

  <nav class="mi-nav" aria-label="Tool sections">
    <button class="tab active" data-tab="rates">Rates &amp; Fees</button>
    <button class="tab" data-tab="groups">Group Discounts</button>
    <button class="tab" data-tab="availability">Availability Pricing</button>
  </nav>

  <section class="view active" id="view-rates">
    <div class="section-head"><div><h2>Rates &amp; fees</h2><p><?= $miCanEdit ? 'Amber fields are editable. Changes save automatically and update every calculation.' : 'View only — these are the rates every quote uses. Only the owner or the Maya Ilai manager can change them.' ?></p></div></div>
    <div class="settings-grid" id="rateSettings"></div>
  </section>

  <section class="view" id="view-groups">
    <div class="section-head"><div><h2>Group discounts</h2><p>Edit discount tiers, then review the recalculated group examples.</p></div></div>
    <div class="two-col" style="margin-bottom:18px">
      <div class="card"><div class="card-head"><h3>Discount tiers</h3></div><div class="card-body"><div class="setting-list" id="groupSettings"></div></div></div>
      <div class="card"><div class="card-head"><h3>How group pricing works</h3></div><div class="card-body"><p style="margin-top:0">The qualifying discount applies to accommodation for stays meeting the minimum-night rule. The additional bunk guest charge and Eco-Resort Fee are excluded.</p><p style="margin-bottom:0">Group and availability adjustments are kept separate so they do not stack.</p></div></div>
    </div>
    <div class="card" style="margin-bottom:18px"><div class="card-head"><h3>Seven guests per villa</h3><span class="subtle">Uses studios for remaining guests</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Guests</th><th>Villas</th><th>Studios</th><th>Discount</th><th>High / night</th><th>Standard / night</th><th>Status</th></tr></thead><tbody id="groupVillaRows"></tbody></table></div></div>
    <div class="card"><div class="card-head"><h3>Double rooms + studios</h3><span class="subtle">Uses the less expensive double rooms first</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Guests</th><th>Double rooms</th><th>Studios</th><th>Discount</th><th>High / night</th><th>Standard / night</th><th>Status</th></tr></thead><tbody id="groupDoubleRows"></tbody></table></div></div>
  </section>

  <section class="view" id="view-availability">
    <div class="section-head"><div><h2>Availability pricing</h2><p>Set the discount or surcharge used as inventory becomes limited. The guest site applies the matching band automatically, on top of any group discount.</p></div></div>
    <div class="card" style="margin-bottom:18px"><div class="card-head"><h3>Availability bands</h3><span class="subtle">Villas free → adjustment. Negative = discount, positive = higher rate. Edit thresholds, add or remove bands freely; a count matching no band is priced at the reference rate.</span></div><div class="table-wrap"><table class="data-table editable-table"><thead><tr><th>From (villas free)</th><th>To</th><th>Adjustment %</th><th>Label</th><th></th></tr></thead><tbody id="availabilitySettings"></tbody></table></div><div style="padding:12px 13px"><button type="button" class="btn-outline btn-sm" id="availAddBand">+ Add band</button></div></div>
    <div class="card"><div class="card-head"><h3>High-season nightly preview</h3><span class="subtle">Negative values are discounts; positive values are surcharges</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Units available</th><th>Adjustment</th><th>Double Room</th><th>Studio</th><th>Full Villa</th></tr></thead><tbody id="availabilityRows"></tbody></table></div></div>
  </section>

</div>

<script>
const MI_DEFAULTS = <?= json_encode(maya_ilai_pricing_defaults(), JSON_UNESCAPED_SLASHES) ?>;
const MI_CSRF = <?= json_encode(csrf_token()) ?>;
const MI_CAN_EDIT = <?= $miCanEdit ? 'true' : 'false' ?>;
let state = <?= json_encode($state, JSON_UNESCAPED_SLASHES) ?>;
(function () {
  const root = document.getElementById('mi-tool');
  const $ = id => document.getElementById(id);
  const money = n => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(n) || 0);
  const pct = n => `${Number(n) > 0 ? '+' : ''}${Number(n)}%`;
  const nval = id => Math.max(0, Number($(id).value) || 0);
  const clone = x => JSON.parse(JSON.stringify(x));

  // ── Persistence: save the whole state to the server (debounced) ──
  let saveTimer = null;
  function save() {
    if (!MI_CAN_EDIT) { renderAll(); return; }   // view only: the fields are locked anyway
    if ($('saveStatus')) $('saveStatus').textContent = 'Saving…';
    renderAll();
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () {
      fetch(location.pathname, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify({ action: 'save', state: state, csrf_token: MI_CSRF })
      })
        .then(r => r.json())
        .then(d => { if ($('saveStatus')) $('saveStatus').textContent = d && d.ok ? 'Saved on the server' : ((d && d.error) || 'Save failed'); })
        .catch(() => { if ($('saveStatus')) $('saveStatus').textContent = 'Save failed — check connection'; });
    }, 500);
  }

  // rate() and groupDiscount() survive ONLY for the illustrative matrices on the
  // Group Discounts and Availability Pricing tabs — "what would 40 guests cost in
  // villas vs studios", a capacity-packing illustration. They must never be used
  // to price a quote: quotes are built in the main Quote builder, priced on the
  // server by maya_ilai_quote() — the single calculation shared with the guest site.
  function rate(key, season) { const base = Number(state.rates[key]); return season === 'standard' ? base * (1 - state.rules.standardReduction / 100) : base; }
  function groupDiscount(guests, nights = state.rules.minNights) { if (nights < state.rules.minNights) return 0; return state.groups.filter(x => guests >= x.guests).sort((a, b) => b.guests - a.guests)[0]?.discount || 0; }
  function availabilityBand(units) { return state.availability.find(x => units >= x.min && units <= x.max) || state.availability[state.availability.length - 1]; }
  function getPath(path) { return path.split('.').reduce((o, k) => o[k], state); }
  function setPath(path, value) { const p = path.split('.'); let o = state; while (p.length > 1) o = o[p.shift()]; o[p[0]] = value; }
  function fieldCard(title, items) { return `<div class="card"><div class="card-head"><h3>${title}</h3></div><div class="card-body"><div class="setting-list">${items.map(i => `<div class="setting-row"><label>${i.label}<small>${i.note || ''}</small></label><input type="number" step="${i.step || 1}" min="0" data-path="${i.path}" value="${getPath(i.path)}"></div>`).join('')}</div></div></div>`; }

  function renderSettings() {
    const cards = [
      ['High-season base rates', [{ label: 'Double Room', note: 'Per room / night', path: 'rates.double' }, { label: 'Private Bunk Room', note: 'Includes 3 guests', path: 'rates.bunk' }, { label: 'Studio', note: 'Per studio / night', path: 'rates.studio' }, { label: 'Living Room + Kitchen', note: 'Per space / night', path: 'rates.living' }, { label: 'Full Villa', note: 'Includes 7 guests', path: 'rates.villa' }]],
      ['Fees & pricing rules', [{ label: 'Standard-season reduction', note: 'Percentage', path: 'rules.standardReduction', step: .5 }, { label: 'Single-occupancy discount', note: 'Double Room only', path: 'rules.singleDiscount', step: .5 }, { label: 'Additional bunk/villa guest', note: 'Per person / night', path: 'rules.bunkExtra' }, { label: 'Eco-Resort Fee', note: 'Per person / stay', path: 'rules.ecoFee' }, { label: 'Minimum group nights', path: 'rules.minNights' }]],
      ['Capacity & inventory', [{ label: 'Villas', path: 'inventory.villas' }, { label: 'Studios', path: 'inventory.studios' }, { label: 'Double rooms per villa', path: 'inventory.doublePerVilla' }, { label: 'Bunk guests included', path: 'rules.bunkIncluded' }, { label: 'Bunk maximum guests', path: 'rules.bunkMax' }, { label: 'Villa guests included', path: 'rules.villaIncluded' }, { label: 'Villa maximum guests', path: 'rules.villaMax' }]]
    ];
    $('rateSettings').innerHTML = cards.map(c => fieldCard(c[0], c[1])).join('');
    root.querySelectorAll('#rateSettings [data-path]').forEach(el => el.addEventListener('change', () => { setPath(el.dataset.path, Number(el.value)); save(); }));
  }
  function renderGroupSettings() {
    $('groupSettings').innerHTML = `<div class="setting-row"><label>Minimum nights<small>Required before discounts apply</small></label><input type="number" min="1" data-group-rule="min" value="${state.rules.minNights}"></div>` + state.groups.map((g, i) => `<div class="setting-row"><label>From ${g.guests} guests<small>Accommodation discount</small></label><input type="number" min="0" max="100" step="0.5" data-group="${i}" value="${g.discount}"></div>`).join('');
    root.querySelectorAll('[data-group]').forEach(el => el.addEventListener('change', () => { state.groups[Number(el.dataset.group)].discount = Number(el.value); save(); }));
    root.querySelector('[data-group-rule="min"]').addEventListener('change', e => { state.rules.minNights = Number(e.target.value); save(); });
  }
  function renderGroupTables() {
    const sizes = [10, 20, 30, 40, 48, 50, 60, 70, 80, 90], iv = state.inventory.villas, is = state.inventory.studios;
    $('groupVillaRows').innerHTML = sizes.map(g => { let best = null; for (let villas = 0; villas <= iv; villas++) { const studios = Math.max(Math.ceil((g - villas * state.rules.villaIncluded) / 2), 0), cap = villas * state.rules.villaIncluded + studios * 2; if (studios <= is && cap >= g) { const cost = villas * state.rates.villa + studios * state.rates.studio; if (!best || cost < best.cost || (cost === best.cost && villas > best.villas)) best = { villas, studios, cost }; } } const ok = !!best, villas = best?.villas ?? iv, studios = best?.studios ?? Math.max(Math.ceil((g - iv * state.rules.villaIncluded) / 2), 0), disc = groupDiscount(g), hi = (villas * state.rates.villa + studios * state.rates.studio) * (1 - disc / 100), st = (villas * rate('villa', 'standard') + studios * rate('studio', 'standard')) * (1 - disc / 100); return `<tr><td>${g}</td><td>${villas}</td><td>${studios}</td><td>${disc}%</td><td>${ok ? money(hi) : '—'}</td><td>${ok ? money(st) : '—'}</td><td class="${ok ? '' : 'bad'}">${ok ? 'Fits' : 'Exceeds capacity'}</td></tr>`; }).join('');
    const maxD = iv * state.inventory.doublePerVilla;
    $('groupDoubleRows').innerHTML = sizes.map(g => { let units = Math.ceil(g / 2), d = Math.min(units, maxD), st = Math.max(units - maxD, 0), ok = st <= is, disc = groupDiscount(g), hi = (d * state.rates.double + st * state.rates.studio) * (1 - disc / 100), std = (d * rate('double', 'standard') + st * rate('studio', 'standard')) * (1 - disc / 100); return `<tr><td>${g}</td><td>${d}</td><td>${st}</td><td>${disc}%</td><td>${ok ? money(hi) : '—'}</td><td>${ok ? money(std) : '—'}</td><td class="${ok ? '' : 'bad'}">${ok ? 'Fits' : 'Exceeds capacity'}</td></tr>`; }).join('');
  }
  function renderAvailability() {
    // The whole ladder is editable: threshold (from/to), adjustment, label, and
    // the set of bands (add/remove). Inputs fire on 'change' (blur/enter), so the
    // table is only rebuilt between edits and never steals focus mid-keystroke.
    const escA = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    $('availabilitySettings').innerHTML = state.availability.map((b, i) => `<tr>`
      + `<td><input type="number" min="0" step="1" data-band="${i}" data-k="min" value="${b.min}"></td>`
      + `<td><input type="number" min="0" step="1" data-band="${i}" data-k="max" value="${b.max}"></td>`
      + `<td><input type="number" step="0.5" data-band="${i}" data-k="adjustment" value="${b.adjustment}"></td>`
      + `<td><input type="text" data-band="${i}" data-k="label" value="${escA(b.label)}" style="width:160px;text-align:left"></td>`
      + `<td><button type="button" class="btn-icon btn-icon--outline" data-band-del="${i}" title="Remove band" aria-label="Remove band">×</button></td>`
      + `</tr>`).join('');
    root.querySelectorAll('#availabilitySettings [data-band]').forEach(el => el.addEventListener('change', () => {
      const b = state.availability[Number(el.dataset.band)], k = el.dataset.k;
      b[k] = (k === 'label') ? el.value : Number(el.value);
      save();
    }));
    root.querySelectorAll('#availabilitySettings [data-band-del]').forEach(el => el.addEventListener('click', () => {
      state.availability.splice(Number(el.dataset.bandDel), 1);
      if (!state.availability.length) state.availability.push({ min: 0, max: 0, adjustment: 0, label: 'Reference rate' });
      save();
    }));
    $('availabilityRows').innerHTML = state.availability.map(b => { const sold = b.max === 0, m = 1 + b.adjustment / 100; return `<tr><td>${b.min === b.max ? b.min : `${b.min}–${b.max}`}</td><td>${sold ? 'Sold out' : pct(b.adjustment)}</td><td>${sold ? '—' : money(state.rates.double * m)}</td><td>${sold ? '—' : money(state.rates.studio * m)}</td><td>${sold ? '—' : money(state.rates.villa * m)}</td></tr>`; }).join('');
  }
  // View only (reception): every rate / discount / band control is locked after
  // each render.
  function lockIfViewOnly() {
    if (MI_CAN_EDIT) return;
    root.querySelectorAll('#view-rates input, #view-rates select, #view-groups input, #view-groups select, #view-availability input, #view-availability select, #view-availability button')
      .forEach(el => { el.disabled = true; });
  }
  function renderAll() { renderSettings(); renderGroupSettings(); renderGroupTables(); renderAvailability(); lockIfViewOnly(); }

  root.querySelectorAll('.tab').forEach(btn => btn.addEventListener('click', () => { root.querySelectorAll('.tab').forEach(x => x.classList.remove('active')); root.querySelectorAll('.view').forEach(x => x.classList.remove('active')); btn.classList.add('active'); $(`view-${btn.dataset.tab}`).classList.add('active'); }));
  // Bound once (renderAvailability rebuilds its rows on every save, so its own
  // buttons are re-bound there; this add button lives outside that tbody).
  $('availAddBand').addEventListener('click', () => {
    const last = state.availability[state.availability.length - 1];
    const from = last ? Math.max(0, Number(last.min) - 1) : 0;
    state.availability.push({ min: from, max: from, adjustment: 0, label: 'New band' });
    save();
  });
  if ($('resetBtn')) $('resetBtn').addEventListener('click', () => {
    if (!confirm('Reset every rate and discount to the original defaults?')) return;
    state = clone(MI_DEFAULTS);
    if ($('saveStatus')) $('saveStatus').textContent = 'Saving…';
    renderAll();
    fetch(location.pathname, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action: 'reset', csrf_token: MI_CSRF }) })
      .then(r => r.json()).then(d => { if (d && d.ok) { state = d.state; renderAll(); } if ($('saveStatus')) $('saveStatus').textContent = d && d.ok ? 'Reset to defaults' : 'Reset failed'; })
      .catch(() => { if ($('saveStatus')) $('saveStatus').textContent = 'Reset failed'; });
  });

  renderAll();
})();
</script>


<?php include __DIR__ . '/_layout_end.php'; ?>
