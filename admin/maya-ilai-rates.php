<?php
/**
 * Admin: Maya Ilai rate & quote tool.
 *
 * A faithful, DB-persisted port of the standalone Maya Ilai calculator. The
 * quote maths is the reference tool's, verbatim; only persistence changed —
 * settings load from and save to the `settings` KV (maya_ilai_pricing) via
 * includes/maya-ilai-pricing.php instead of the browser's localStorage.
 *
 * Gate: owner, or a manager scoped to Maya Ilai (venue 6). STANDALONE — it does
 * NOT feed the live rooms/units/rates booking engine.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/maya-ilai-pricing.php';
require_once __DIR__ . '/../includes/maya-ilai-unitmap.php';   // read-only live occupancy for the Unit Map tab
require_once __DIR__ . '/../includes/icons.php';
require_login();
require_manager();

// Scope: owner sees all; a manager must have Maya Ilai in their venue set.
$__ids = admin_venue_ids();
if ($__ids !== null && !in_array(MAYA_ILAI_VENUE_ID, array_map('intval', $__ids), true)) {
    $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'The Maya Ilai rate tool is only available to Maya Ilai managers.'];
    header('Location: ' . admin_home_url()); exit;
}

// ── AJAX save / reset (JSON, CSRF-in-body) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data  = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = (string)($data['csrf_token'] ?? '');
    if (($_SESSION['csrf_token'] ?? '') === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Invalid session token. Please reload.']));
    }
    $action = (string)($data['action'] ?? 'save');

    // ── Live quote ───────────────────────────────────────────────────────────
    // The tool prices through maya_ilai_quote() — the SAME calculation the guest
    // configurator uses — instead of reimplementing it in the page. It rides this
    // handler rather than api/maya-ilai-quote.php because that endpoint is public
    // (it would need a config override, and deliberately cannot express season /
    // availability pricing, which are staff levers). Here the gate is already the
    // page's own: require_manager + the Maya Ilai venue scope + CSRF-in-body.
    //
    // `state` prices UNSAVED edits: the editor must show what a rate change does
    // before the debounced save lands, and chaining the preview to save success
    // would quote stale rates whenever a save failed. It is coerced by the same
    // sanitiser a save uses and never persisted — and an authenticated manager
    // may already save any config they like, so previewing one grants nothing new.
    if ($action === 'quote') {
        try {
            $cfg = is_array($data['state'] ?? null)
                ? maya_ilai_pricing_sanitize($data['state'])
                : maya_ilai_pricing_get();
            $s = is_array($data['sel'] ?? null) ? $data['sel'] : [];
            $quote = maya_ilai_quote([
                'qtyDouble'   => (int)($s['qtyDouble']   ?? 0), 'guestDouble' => (int)($s['guestDouble'] ?? 0),
                'qtyBunk'     => (int)($s['qtyBunk']     ?? 0), 'guestBunk'   => (int)($s['guestBunk']   ?? 0),
                'qtyStudio'   => (int)($s['qtyStudio']   ?? 0), 'guestStudio' => (int)($s['guestStudio'] ?? 0),
                'qtyVilla'    => (int)($s['qtyVilla']    ?? 0), 'guestVilla'  => (int)($s['guestVilla']  ?? 0),
                'qtyLiving'   => (int)($s['qtyLiving']   ?? 0),
                'nights'      => (int)($s['nights']      ?? 1),
                'season'      => (string)($s['season']   ?? 'high'),
                'program'     => (string)($s['program']  ?? 'group'),
                'availableUnits' => (int)($s['availableUnits'] ?? 0),
            ], $cfg);
            exit(json_encode(['ok' => true, 'quote' => $quote]));
        } catch (Throwable $e) {
            error_log('[maya-ilai-rates] quote: ' . $e->getMessage());
            http_response_code(500); exit(json_encode(['ok' => false, 'error' => 'Could not price this configuration.']));
        }
    }

    // ── Live unit map ──────────────────────────────────────────────────────────
    // READ-ONLY occupancy for the aerial compound view. It reads the live
    // availability_blocks / holds / bookings for one date and returns per-bedroom
    // and per-studio status — it never writes anything, so it does not breach this
    // page's "standalone, does not feed the booking engine" contract (that rule is
    // about WRITES: the pricing tool must not push rates into the live rooms/rates
    // tables). The gate is the page's own: require_manager + Maya Ilai scope + CSRF.
    if ($action === 'unitmap') {
        try {
            require_once __DIR__ . '/../includes/rates.php';   // rates_window_ymd()
            $d = rates_window_ymd((string)($data['date'] ?? ''));
            if ($d === null) { http_response_code(422); exit(json_encode(['ok' => false, 'error' => 'Please choose a valid date.'])); }
            exit(json_encode(['ok' => true, 'map' => mi_unit_map($d)]));
        } catch (Throwable $e) {
            error_log('[maya-ilai-rates] unitmap: ' . $e->getMessage());
            http_response_code(500); exit(json_encode(['ok' => false, 'error' => 'Could not load the unit map.']));
        }
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
  <h1>Maya Ilai — Rate &amp; Quote Tool</h1>
  <a href="/admin/dashboard.php" class="btn-outline btn-sm"><?= admin_icon('arrow-left', 15) ?> Dashboard</a>
</div>
<p class="text-muted" style="margin:-6px 0 18px;font-size:13px;max-width:760px">
  Internal quoting tool for Maya Ilai (USD). Amber fields are editable and save to the server —
  shared across everyone on the team, on every device. This tool is independent of the live
  booking calendar and rates.
</p>

<div id="mi-tool">
<style>
  #mi-tool{--navy:#182247;--navy2:#26335f;--teal:#168e86;--teal-soft:#e6f4f2;--ink:#24324a;--muted:#69758a;--line:#dfe5ec;--panel:#fff;--bg:#f4f7fa;--amber:#fff1cc;--amber-ink:#81520b;--red:#a3362a;--red-soft:#fff0ed;--shadow:0 8px 24px rgba(23,34,71,.06);--r:14px;color:var(--ink);font-size:15px}
  #mi-tool button,#mi-tool input,#mi-tool select{font:inherit}#mi-tool button{cursor:pointer}
  #mi-tool .mi-bar{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
  #mi-tool .status{font-size:.82rem;color:var(--muted)}
  #mi-tool .btn{border:1px solid var(--line);background:#fff;color:var(--navy);border-radius:10px;padding:8px 13px;font-weight:650}#mi-tool .btn:hover{background:var(--bg)}#mi-tool .btn.primary{background:var(--teal);border-color:var(--teal);color:#fff}#mi-tool .btn.danger{color:var(--red);border-color:#f0c8c2}
  #mi-tool nav.mi-nav{display:flex;gap:4px;overflow:auto;border-bottom:1px solid var(--line);margin-bottom:18px}#mi-tool .tab{white-space:nowrap;border:0;color:var(--muted);background:transparent;padding:11px 15px;font-weight:700;border-bottom:2px solid transparent}#mi-tool .tab.active{color:var(--navy);border-bottom-color:var(--teal)}
  #mi-tool .view{display:none}#mi-tool .view.active{display:block}
  #mi-tool .section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:16px}#mi-tool .section-head h2{font-size:1.25rem;margin:0;color:var(--navy)}#mi-tool .section-head p{margin:4px 0 0;color:var(--muted);font-size:.9rem}
  #mi-tool .grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(320px,.8fr);gap:20px}#mi-tool .stack{display:grid;gap:18px}
  #mi-tool .card{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden}#mi-tool .card-head{padding:15px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:12px}#mi-tool .card-head h3{font-size:1rem;margin:0;color:var(--navy)}#mi-tool .card-body{padding:18px}#mi-tool .subtle{font-size:.82rem;color:var(--muted)}
  #mi-tool .form-grid{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:14px}#mi-tool .field{display:grid;gap:6px}#mi-tool .field label{font-size:.78rem;color:var(--muted);font-weight:750}#mi-tool .field input,#mi-tool .field select{width:100%;border:1px solid #cfd7e2;border-radius:10px;padding:9px 11px;color:var(--ink);background:#fff;min-height:42px}#mi-tool .field input:focus,#mi-tool .field select:focus{outline:3px solid rgba(22,142,134,.14);border-color:var(--teal)}#mi-tool .help{font-size:.74rem;color:var(--muted)}
  #mi-tool .config{width:100%;border-collapse:collapse}#mi-tool .config th,#mi-tool .config td{padding:11px 9px;text-align:left;border-bottom:1px solid var(--line);vertical-align:middle}#mi-tool .config th{color:var(--muted);font-size:.74rem;text-transform:uppercase;letter-spacing:.04em}#mi-tool .config tr:last-child td{border-bottom:0}#mi-tool .unit-name{font-weight:760;color:var(--navy)}#mi-tool .unit-note{display:block;color:var(--muted);font-size:.74rem;margin-top:2px}#mi-tool .num{width:88px!important}#mi-tool .money{text-align:right!important;font-variant-numeric:tabular-nums;font-weight:700}#mi-tool .pill{display:inline-flex;padding:4px 8px;border-radius:999px;background:var(--teal-soft);color:#08736c;font-size:.72rem;font-weight:800}
  #mi-tool .summary{position:sticky;top:18px}#mi-tool .totalbox{background:linear-gradient(145deg,var(--navy),var(--navy2));color:#fff;padding:20px}#mi-tool .totalbox .label{color:#cdd5ea;font-size:.8rem}#mi-tool .grand{font-size:2rem;font-weight:820;letter-spacing:-.04em;margin:4px 0}#mi-tool .per{color:#cdd5ea}#mi-tool .metrics{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--line)}#mi-tool .metric{background:#fff;padding:13px 15px}#mi-tool .metric span{display:block;color:var(--muted);font-size:.72rem}#mi-tool .metric strong{font-size:1rem;color:var(--navy);font-variant-numeric:tabular-nums}#mi-tool .breakdown{padding:16px}#mi-tool .line{display:flex;justify-content:space-between;gap:16px;padding:6px 0;font-size:.86rem}#mi-tool .line span:first-child{color:var(--muted)}#mi-tool .line.total{border-top:1px solid var(--line);margin-top:7px;padding-top:12px;font-weight:800}#mi-tool .notice{margin-top:14px;padding:10px 13px;border-radius:10px;background:var(--teal-soft);color:#096c66;font-size:.8rem}#mi-tool .notice.error{background:var(--red-soft);color:var(--red)}#mi-tool .summary-actions{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:14px}
  #mi-tool .settings-grid{display:grid;grid-template-columns:repeat(3,minmax(230px,1fr));gap:18px}#mi-tool .setting-list{display:grid;gap:13px}#mi-tool .setting-row{display:grid;grid-template-columns:1fr 118px;gap:14px;align-items:center}#mi-tool .setting-row label{font-size:.86rem;font-weight:680}#mi-tool .setting-row small{display:block;color:var(--muted);font-weight:400}#mi-tool .setting-row input{width:100%;border:1px solid #ecd89d;background:var(--amber);color:var(--amber-ink);border-radius:9px;padding:8px;font-weight:750;text-align:right}
  #mi-tool .table-wrap{overflow:auto}#mi-tool .data-table{border-collapse:collapse;width:100%;min-width:640px}#mi-tool .data-table th,#mi-tool .data-table td{padding:10px 13px;border-bottom:1px solid var(--line);text-align:right;font-variant-numeric:tabular-nums}#mi-tool .data-table th{background:var(--navy);color:#fff;font-size:.74rem;letter-spacing:.02em;position:sticky;top:0}#mi-tool .data-table th:first-child,#mi-tool .data-table td:first-child{text-align:left}#mi-tool .data-table tbody tr:nth-child(even){background:#f7f9fb}#mi-tool .data-table .bad{color:var(--red);background:var(--red-soft);font-weight:750}#mi-tool .editable-table input{width:88px;background:var(--amber);border:1px solid #ead59b;border-radius:8px;padding:7px;color:var(--amber-ink);font-weight:750;text-align:right}#mi-tool .two-col{display:grid;grid-template-columns:1fr 1fr;gap:18px}
  #mi-tool .hide{display:none!important}
  @media(max-width:1050px){#mi-tool .grid,#mi-tool .two-col{grid-template-columns:1fr}#mi-tool .summary{position:static}#mi-tool .settings-grid{grid-template-columns:1fr 1fr}#mi-tool .form-grid{grid-template-columns:1fr 1fr}}
  @media(max-width:640px){#mi-tool .settings-grid,#mi-tool .form-grid{grid-template-columns:1fr}#mi-tool .config th:nth-child(4),#mi-tool .config td:nth-child(4){display:none}#mi-tool .grand{font-size:1.7rem}}
</style>

  <div class="mi-bar">
    <span class="status" id="saveStatus">Saved on the server</span>
    <button class="btn danger" id="resetBtn" style="margin-left:auto">Reset to defaults</button>
  </div>

  <nav class="mi-nav" aria-label="Tool sections">
    <button class="tab active" data-tab="quote">Quote Builder</button>
    <button class="tab" data-tab="rates">Rates &amp; Fees</button>
    <button class="tab" data-tab="groups">Group Discounts</button>
    <button class="tab" data-tab="availability">Availability Pricing</button>
    <button class="tab" data-tab="unitmap">Unit Map</button>
  </nav>

  <section class="view active" id="view-quote">
    <div class="section-head"><div><h2>Build a quote</h2><p>Select the accommodation mix and allocate guests to each room type.</p></div><span class="pill">Priced on the server</span></div>
    <div class="grid">
      <div class="stack">
        <div class="card"><div class="card-head"><h3>Stay details</h3></div><div class="card-body form-grid">
          <div class="field"><label for="clientName">Guest or group name</label><input id="clientName" placeholder="Optional"></div>
          <div class="field"><label for="season">Season</label><select id="season"><option value="high">High season</option><option value="standard">Standard season</option></select></div>
          <div class="field"><label for="nights">Nights</label><input id="nights" type="number" min="1" value="3"></div>
          <div class="field"><label for="program">Pricing adjustment</label><select id="program"><option value="group">Group discount</option><option value="availability">Availability pricing</option><option value="none">No additional adjustment</option></select></div>
          <div class="field hide" id="availableWrap"><label for="availableUnits">Units still available</label><input id="availableUnits" type="number" min="0" max="8" value="6"><span class="help">Chooses the matching availability band.</span></div>
        </div></div>
        <div class="card"><div class="card-head"><div><h3>Room configuration</h3><span class="subtle">Full villas already include their bedrooms, bunk room and living/kitchen.</span></div></div><div class="card-body table-wrap">
          <table class="config"><thead><tr><th>Accommodation</th><th>Quantity</th><th>Guests allocated</th><th>Capacity</th><th class="money">Nightly</th></tr></thead><tbody>
            <tr><td><span class="unit-name">Double Room</span><span class="unit-note">Single occupancy discount applies</span></td><td><input class="num cfg" id="qtyDouble" type="number" min="0" value="1"></td><td><input class="num cfg" id="guestDouble" type="number" min="0" value="2"></td><td id="capDouble">2</td><td class="money" id="lineDouble">$0.00</td></tr>
            <tr><td><span class="unit-name">Private Bunk Room</span><span class="unit-note">Base includes 3; maximum 6</span></td><td><input class="num cfg" id="qtyBunk" type="number" min="0" value="0"></td><td><input class="num cfg" id="guestBunk" type="number" min="0" value="0"></td><td id="capBunk">0</td><td class="money" id="lineBunk">$0.00</td></tr>
            <tr><td><span class="unit-name">Studio</span><span class="unit-note">Sleeps 2, with kitchenette</span></td><td><input class="num cfg" id="qtyStudio" type="number" min="0" value="0"></td><td><input class="num cfg" id="guestStudio" type="number" min="0" value="0"></td><td id="capStudio">0</td><td class="money" id="lineStudio">$0.00</td></tr>
            <tr><td><span class="unit-name">Full Villa</span><span class="unit-note">Base includes 7; maximum 10</span></td><td><input class="num cfg" id="qtyVilla" type="number" min="0" value="0"></td><td><input class="num cfg" id="guestVilla" type="number" min="0" value="0"></td><td id="capVilla">0</td><td class="money" id="lineVilla">$0.00</td></tr>
            <tr><td><span class="unit-name">Living Room + Kitchen</span><span class="unit-note">Add to room combinations</span></td><td><input class="num cfg" id="qtyLiving" type="number" min="0" value="0"></td><td>—</td><td>—</td><td class="money" id="lineLiving">$0.00</td></tr>
          </tbody></table>
        </div></div>
      </div>
      <aside class="card summary">
        <div class="totalbox"><div class="label">Estimated stay total</div><div class="grand" id="grandTotal">$0.00</div><div class="per" id="perGuest">Add guests to calculate</div></div>
        <div class="metrics"><div class="metric"><span>Guests</span><strong id="metricGuests">0</strong></div><div class="metric"><span>Capacity</span><strong id="metricCapacity">0</strong></div><div class="metric"><span>Accommodation / night</span><strong id="metricNightly">$0.00</strong></div><div class="metric"><span>Adjustment</span><strong id="metricDiscount">0%</strong></div></div>
        <div class="breakdown" id="breakdown"></div>
        <div class="breakdown" style="padding-top:0"><div id="quoteNotice" class="notice">Configuration fits the current inventory.</div><div class="summary-actions"><button class="btn" id="copyQuote">Copy quote</button><button class="btn primary" id="printQuote">Print / PDF</button></div></div>
      </aside>
    </div>
  </section>

  <section class="view" id="view-rates">
    <div class="section-head"><div><h2>Rates &amp; fees</h2><p>Amber fields are editable. Changes save automatically and update every calculation.</p></div></div>
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

  <!-- ── Unit Map — live aerial compound view ─────────────────────────────────
       Unlike the four tabs above (which are the standalone pricing tool), this tab
       is LIVE and READ-ONLY: it reads availability_blocks / holds / bookings for a
       chosen date and paints the compound. Every booking that reaches the calendar
       — website, OTA (iCal), channel-manager import, agent, maintenance — appears. -->
  <section class="view" id="view-unitmap">
    <style>
      #view-unitmap{--um-avail:#e8f5ef;--um-avail-ink:#0d7a4f;--um-occ:#dce9fb;--um-occ-ink:#245b9e;--um-arr:#fff1c9;--um-arr-ink:#8b5d08;--um-dep:#ffe1ca;--um-dep-ink:#9b4c18;--um-blk:#efe8f4;--um-blk-ink:#744b88}
      #view-unitmap .um-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:14px}
      #view-unitmap .um-date{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
      #view-unitmap .um-nav{padding:8px 12px;font-size:1.05rem;line-height:1}
      #view-unitmap .um-dp{min-width:180px}
      #view-unitmap .um-legend{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
      #view-unitmap .um-lg{display:flex;align-items:center;gap:6px;color:var(--muted);font-size:.76rem}
      #view-unitmap .um-dot{width:11px;height:11px;border-radius:3px;flex:none}
      #view-unitmap .um-dot.available{background:var(--um-avail);box-shadow:inset 0 0 0 1px #56b486}
      #view-unitmap .um-dot.occupied{background:#6594d1}#view-unitmap .um-dot.arriving{background:#e9b343}
      #view-unitmap .um-dot.departing{background:#e98546}#view-unitmap .um-dot.blocked{background:#9770aa}
      #view-unitmap .um-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:14px}
      #view-unitmap .um-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:11px 14px}
      #view-unitmap .um-card span{display:block;color:var(--muted);font-size:.72rem}
      #view-unitmap .um-card strong{display:block;color:var(--navy);font-size:1.45rem;line-height:1.1;margin-top:3px}
      #view-unitmap .um-card.is-avail strong{color:var(--um-avail-ink)}
      #view-unitmap .um-body{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:16px;align-items:start}
      #view-unitmap .um-mapwrap{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow);padding:12px;overflow:auto}
      #view-unitmap .um-map svg{display:block;width:100%;height:auto;min-width:760px}
      #view-unitmap .um-map text{font-family:inherit;text-anchor:middle;pointer-events:none}
      #view-unitmap .um-shell{fill:#fff;stroke:#cbd3dd;stroke-width:2}
      #view-unitmap .um-blabel{fill:#203449;font-size:15px;font-weight:800;letter-spacing:1px}
      #view-unitmap .um-hit{cursor:pointer;outline:none}
      #view-unitmap .um-hit text{fill:#fff}
      #view-unitmap .um-floor{fill:#64748b;stroke:#fff;stroke-width:2}
      #view-unitmap .um-rlabel{font-size:15px;font-weight:700}#view-unitmap .um-cap{font-size:13px;opacity:.9}
      #view-unitmap .um-rstat{font-size:13px;font-weight:800}
      #view-unitmap .um-available .um-floor{fill:#0d8a52}#view-unitmap .um-occupied .um-floor{fill:#205bbb}
      #view-unitmap .um-arriving .um-floor{fill:#986300}#view-unitmap .um-departing .um-floor{fill:#ac4214}
      #view-unitmap .um-blocked .um-floor{fill:#7a4e93}
      #view-unitmap .um-hit:hover .um-floor,#view-unitmap .um-hit:focus .um-floor,#view-unitmap .um-hit.is-sel .um-floor{stroke:#102b42;stroke-width:5}
      #view-unitmap .um-pool{fill:none;stroke:#a6b8c8;stroke-width:3}#view-unitmap .um-poollbl{fill:#71869b;font-size:16px;letter-spacing:3px}
      #view-unitmap .um-details{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden;position:sticky;top:18px}
      #view-unitmap .um-empty{padding:30px 20px;text-align:center;color:var(--muted)}
      #view-unitmap .um-empty strong{display:block;color:var(--navy);margin-bottom:5px}
      #view-unitmap .um-hero{padding:16px 18px;color:#fff;background:var(--navy)}
      #view-unitmap .um-hero .um-pill{display:inline-flex;padding:4px 9px;border-radius:999px;background:rgba(255,255,255,.16);font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
      #view-unitmap .um-hero h3{margin:9px 0 2px;font-size:1.15rem}#view-unitmap .um-hero p{margin:0;color:#ccd7ea;font-size:.82rem}
      #view-unitmap .um-dbody{padding:14px 16px}
      #view-unitmap .um-drow{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid var(--line);font-size:.85rem}
      #view-unitmap .um-drow:last-child{border-bottom:0}#view-unitmap .um-drow span{color:var(--muted)}#view-unitmap .um-drow strong{color:var(--navy);text-align:right}
      #view-unitmap .um-note{margin-top:12px;padding:10px 12px;border-radius:9px;background:var(--teal-soft);color:#0a6b64;font-size:.8rem;line-height:1.5}
      #view-unitmap .um-setup{padding:20px;border:1px dashed var(--line);border-radius:12px;color:var(--muted);background:#fff}
      @media(max-width:1050px){#view-unitmap .um-body{grid-template-columns:1fr}#view-unitmap .um-details{position:static}}
      @media(max-width:640px){#view-unitmap .um-summary{grid-template-columns:repeat(2,1fr)}#view-unitmap .um-map svg{min-width:640px}}
    </style>

    <div class="section-head">
      <div><h2>Compound unit map</h2><p>Live booking status for every villa bedroom and studio on a chosen day. Reads the same calendar as the availability Gantt — website holds, OTA imports, channel-manager imports, agent holds and maintenance closures all appear here.</p></div>
      <span class="pill">Live · read-only</span>
    </div>

    <div class="um-toolbar">
      <div class="um-date">
        <button type="button" class="btn um-nav" id="umPrev" aria-label="Previous day">&lsaquo;</button>
        <button type="button" class="dp-btn um-dp" id="umDateBtn" data-dp-target="umDateInput" data-dp-placeholder="Pick a date"></button>
        <input type="hidden" id="umDateInput">
        <button type="button" class="btn um-nav" id="umNext" aria-label="Next day">&rsaquo;</button>
        <button type="button" class="btn um-today" id="umToday">Today</button>
        <span class="status" id="umStatus"></span>
      </div>
      <div class="um-legend">
        <span class="um-lg"><i class="um-dot available"></i>Available</span>
        <span class="um-lg"><i class="um-dot occupied"></i>Occupied</span>
        <span class="um-lg"><i class="um-dot arriving"></i>Arriving</span>
        <span class="um-lg"><i class="um-dot departing"></i>Departing</span>
        <span class="um-lg"><i class="um-dot blocked"></i>Blocked</span>
      </div>
    </div>

    <div class="um-summary">
      <div class="um-card is-avail"><span>Available beds</span><strong id="umSumAvailable">0</strong></div>
      <div class="um-card"><span>Occupied</span><strong id="umSumOccupied">0</strong></div>
      <div class="um-card"><span>Arrivals</span><strong id="umSumArriving">0</strong></div>
      <div class="um-card"><span>Departures</span><strong id="umSumDeparting">0</strong></div>
      <div class="um-card"><span>Blocked</span><strong id="umSumBlocked">0</strong></div>
    </div>

    <div class="um-setup" id="umSetup" hidden>Maya Ilai's composite villa inventory isn’t set up on this database yet, so there’s nothing to map. Once the Maya Ilai rooms and units exist, live bookings will show here.</div>

    <div class="um-body" id="umBody">
      <div class="um-mapwrap"><div id="umMap" class="um-map"></div></div>
      <aside class="um-details" id="umDetails">
        <div class="um-empty"><strong>Select a room or studio</strong>Booking details for that unit on the selected day will appear here.</div>
      </aside>
    </div>
  </section>
</div>

<script>
const MI_DEFAULTS = <?= json_encode(maya_ilai_pricing_defaults(), JSON_UNESCAPED_SLASHES) ?>;
const MI_CSRF = <?= json_encode(csrf_token()) ?>;
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
  // to price a quote: the quote comes from the server (see requestQuote below),
  // which is the single calculation shared with the guest configurator.
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
  // ── Quoting: server-side, one calculation ──────────────────────────────────
  // The page used to reimplement maya_ilai_quote() in JS. It drifted (the
  // living-room rule existed only in PHP), which is exactly the failure two
  // summations over one rate map always produce. Now the selection AND the
  // current (possibly unsaved) config go to the server and every figure below —
  // including each per-unit line — comes back resolved.
  let lastQuote = null, quoteTimer = null, quoteSeq = 0;

  function selection() {
    return {
      qtyDouble: nval('qtyDouble'), guestDouble: nval('guestDouble'),
      qtyBunk:   nval('qtyBunk'),   guestBunk:   nval('guestBunk'),
      qtyStudio: nval('qtyStudio'), guestStudio: nval('guestStudio'),
      qtyVilla:  nval('qtyVilla'),  guestVilla:  nval('guestVilla'),
      qtyLiving: nval('qtyLiving'),
      nights: Math.max(1, nval('nights')),
      season: $('season').value, program: $('program').value,
      availableUnits: nval('availableUnits')
    };
  }

  function requestQuote() {
    $('availableWrap').classList.toggle('hide', $('program').value !== 'availability');
    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(() => {
      const seq = ++quoteSeq;
      fetch(location.pathname, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify({ action: 'quote', sel: selection(), state: state, csrf_token: MI_CSRF })
      })
        .then(r => r.json())
        .then(d => {
          if (seq !== quoteSeq) return;                 // a newer request is in flight
          if (d && d.ok && d.quote) { lastQuote = d.quote; paintQuote(d.quote); }
          else quoteFailed((d && d.error) || 'Could not price this configuration.');
        })
        .catch(() => { if (seq === quoteSeq) quoteFailed('Could not reach the pricing service.'); });
    }, 120);
  }

  // Never leave a stale figure looking current — say so instead.
  function quoteFailed(msg) {
    const notice = $('quoteNotice');
    notice.className = 'notice error';
    notice.innerHTML = `${msg} The figures below may be out of date — retry in a moment.`;
  }

  function paintQuote(x) {
    $('availableWrap').classList.toggle('hide', x.program !== 'availability');
    $('capDouble').textContent = x.caps.double; $('capBunk').textContent = x.caps.bunk;
    $('capStudio').textContent = x.caps.studio; $('capVilla').textContent = x.caps.villa;
    $('lineDouble').textContent = money(x.lines.double); $('lineBunk').textContent = money(x.lines.bunk);
    $('lineStudio').textContent = money(x.lines.studio); $('lineVilla').textContent = money(x.lines.villa);
    $('lineLiving').textContent = money(x.lines.living);
    $('grandTotal').textContent = x.sold ? 'Sold out' : money(x.total);
    $('perGuest').textContent = x.perGuestNight === null ? 'Add guests to calculate' : `${money(x.perGuestNight)} per guest / night`;
    $('metricGuests').textContent = x.guests; $('metricCapacity').textContent = x.capacity;
    $('metricNightly').textContent = x.sold ? '—' : money(x.nightly);
    $('metricDiscount').textContent = x.program === 'none' ? 'None' : pct(x.adjustment);
    $('breakdown').innerHTML = `<div class="line"><span>${x.season === 'high' ? 'High' : 'Standard'}-season base / night</span><strong>${money(x.base)}</strong></div><div class="line"><span>${x.adjustmentLabel} (${pct(x.adjustment)})</span><strong>${money(x.adjustmentAmount)}</strong></div><div class="line"><span>Additional guest supplements / night</span><strong>${money(x.supplements)}</strong></div><div class="line"><span>${x.nights} night${x.nights === 1 ? '' : 's'}</span><strong>${money(x.nightly * x.nights)}</strong></div><div class="line"><span>Eco-Resort Fee · ${x.guests} guests</span><strong>${money(x.eco)}</strong></div><div class="line total"><span>Estimated total</span><strong>${x.sold ? '—' : money(x.total)}</strong></div>`;
    const notice = $('quoteNotice'); notice.className = `notice${x.errors.length ? ' error' : ''}`;
    notice.innerHTML = x.errors.length ? x.errors.join('<br>') : `Configuration fits inventory · uses ${x.physicalVillas} of ${state.inventory.villas} villas and ${x.q.studio} of ${state.inventory.studios} studios.`;
  }
  function renderAll() { renderSettings(); renderGroupSettings(); renderGroupTables(); renderAvailability(); requestQuote(); }

  root.querySelectorAll('.tab').forEach(btn => btn.addEventListener('click', () => { root.querySelectorAll('.tab').forEach(x => x.classList.remove('active')); root.querySelectorAll('.view').forEach(x => x.classList.remove('active')); btn.classList.add('active'); $(`view-${btn.dataset.tab}`).classList.add('active'); }));
  root.querySelectorAll('.cfg,#season,#nights,#program,#availableUnits,#clientName').forEach(el => el.addEventListener('input', requestQuote));
  // Bound once (renderAvailability rebuilds its rows on every save, so its own
  // buttons are re-bound there; this add button lives outside that tbody).
  $('availAddBand').addEventListener('click', () => {
    const last = state.availability[state.availability.length - 1];
    const from = last ? Math.max(0, Number(last.min) - 1) : 0;
    state.availability.push({ min: from, max: from, adjustment: 0, label: 'New band' });
    save();
  });
  $('resetBtn').addEventListener('click', () => {
    if (!confirm('Reset every rate and discount to the original defaults?')) return;
    state = clone(MI_DEFAULTS);
    if ($('saveStatus')) $('saveStatus').textContent = 'Saving…';
    renderAll();
    fetch(location.pathname, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action: 'reset', csrf_token: MI_CSRF }) })
      .then(r => r.json()).then(d => { if (d && d.ok) { state = d.state; renderAll(); } if ($('saveStatus')) $('saveStatus').textContent = d && d.ok ? 'Reset to defaults' : 'Reset failed'; })
      .catch(() => { if ($('saveStatus')) $('saveStatus').textContent = 'Reset failed'; });
  });
  // Copies the LAST server quote — never a locally recomputed one, so the text a
  // guest is sent is the same figure the panel showed.
  $('copyQuote').addEventListener('click', async () => { const x = lastQuote; if (!x) return; const name = $('clientName').value.trim(), lines = [`Maya Ilai${name ? ' · ' + name : ''}`, `${x.season === 'high' ? 'High' : 'Standard'} season · ${x.nights} nights · ${x.guests} guests`, `Configuration: ${x.q.double} double room(s), ${x.q.bunk} bunk room(s), ${x.q.studio} studio(s), ${x.q.villa} full villa(s), ${x.q.living} living room/kitchen(s)`, `Accommodation per night: ${money(x.nightly)}`, `Eco-Resort Fee: ${money(x.eco)}`, `Estimated total: ${money(x.total)}`]; try { await navigator.clipboard.writeText(lines.join('\n')); $('copyQuote').textContent = 'Copied'; setTimeout(() => $('copyQuote').textContent = 'Copy quote', 1400); } catch (e) {} });
  $('printQuote').addEventListener('click', () => window.print());

  renderAll();
})();
</script>

<script>
/* ── Unit Map — live aerial compound view ──────────────────────────────────────
   A self-contained controller, independent of the pricing tool above. It lazy-
   loads on the first time the Unit Map tab is opened (so a manager who only wants
   a quote never triggers the live query), then fetches the map for a chosen date
   and paints the SVG. READ-ONLY: it only ever POSTs {action:'unitmap', date}. */
(function () {
  var UM_CSRF  = <?= json_encode(csrf_token()) ?>;
  var UM_TODAY = <?= json_encode(date('Y-m-d')) ?>;   // Nairobi-local (see db.php tz)
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var STATUS = ['available', 'occupied', 'arriving', 'departing', 'blocked'];
  var CAP = { DA: '2', DB: '2', B: '6', L: 'Kitchen' };

  var mapEl, detailsEl, statusEl, dateInput, dateBtn;
  var cur = UM_TODAY, cells = {}, loaded = false, selected = null, svgReady = false, reqSeq = 0;

  function fmtDisp(ymd) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return ymd;
    return new Date(ymd + 'T12:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  }
  function addDays(ymd, n) {
    var d = new Date(ymd + 'T12:00:00'); d.setDate(d.getDate() + n);
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }

  /* ── The SVG. Ported from the aerial prototype's coordinates so the compound
     reads the same, but every room is a data-key'd hit target we recolour live. */
  function roomSvg(key, x, y, w, label, capacity) {
    return '<g class="um-hit um-available" role="button" tabindex="0" data-key="' + key + '">'
      + '<rect class="um-floor" x="' + x + '" y="' + y + '" width="' + w + '" height="92" rx="3"/>'
      + '<text class="um-rlabel" x="' + (x + w / 2) + '" y="' + (y + 26) + '">' + label + '</text>'
      + '<text class="um-cap" x="' + (x + w / 2) + '" y="' + (y + 47) + '">' + capacity + '</text>'
      + '<text class="um-rstat" x="' + (x + w / 2) + '" y="' + (y + 73) + '"></text></g>';
  }
  function villaSvg(n, x, y) {
    var id = 'V' + pad(n);
    return '<g transform="translate(' + x + ' ' + y + ')">'
      + '<rect class="um-shell" x="-7" y="-35" width="258" height="235" rx="7"/>'
      + '<text class="um-blabel" x="122" y="-13">VILLA ' + pad(n) + '</text>'
      + roomSvg(id + '-DA', 0, 0, 120, 'Double A', '2 guests')
      + roomSvg(id + '-DB', 124, 0, 120, 'Double B', '2 guests')
      + roomSvg(id + '-B', 0, 96, 120, 'Bunk', '6 guests')
      + roomSvg(id + '-L', 124, 96, 120, 'Living', 'Kitchen') + '</g>';
  }
  function studioSvg(n, x, y) {
    return '<g transform="translate(' + x + ' ' + y + ')">'
      + '<text class="um-blabel" x="70" y="-12">STUDIO ' + pad(n) + 'A</text>'
      + roomSvg('S' + pad(n) + 'A', 0, 0, 140, 'Studio', '2 guests') + '</g>';
  }
  function buildSvg() {
    var vp = [[35, 230], [425, 185], [835, 185], [1285, 180], [1285, 685], [975, 650], [330, 650], [35, 685]];
    var sp = [[160, 75], [477, 45], [887, 45], [1370, 420], [1370, 550], [805, 700], [610, 700], [70, 500]];
    var s = '<svg viewBox="0 0 1560 910" xmlns="http://www.w3.org/2000/svg" aria-label="Maya Ilai compound room map"><rect width="1560" height="910" fill="#f5f7fa"/>';
    for (var i = 0; i < 8; i++) s += villaSvg(i + 1, vp[i][0], vp[i][1]);
    for (var j = 0; j < 8; j++) s += studioSvg(j + 1, sp[j][0], sp[j][1]);
    s += '<rect class="um-pool" x="625" y="457" width="310" height="105" rx="22"/><text class="um-poollbl" x="780" y="517">POOL</text></svg>';
    mapEl.innerHTML = s;
    mapEl.querySelectorAll('[data-key]').forEach(function (g) {
      var choose = function () { select(g.dataset.key); };
      g.addEventListener('click', choose);
      g.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(); } });
    });
    svgReady = true;
  }

  function ingest(map) {
    cells = {};
    (map.villas || []).forEach(function (v) {
      var p = 'V' + pad(v.n), r = v.rooms || {};
      var mk = function (rk, k) {
        cells[p + '-' + rk] = { status: (r[k] || {}).status || 'available', booking: (r[k] || {}).booking || null,
          title: v.label + ' · ' + ((r[k] || {}).label || k), room: (r[k] || {}).label || k };
      };
      mk('DA', 'double_a'); mk('DB', 'double_b'); mk('B', 'bunk'); mk('L', 'living');
    });
    (map.studios || []).forEach(function (s) {
      cells['S' + pad(s.n) + 'A'] = { status: s.status || 'available', booking: s.booking || null, title: s.label, room: 'Studio' };
    });
  }
  function paintMap() {
    if (!svgReady) return;
    mapEl.querySelectorAll('[data-key]').forEach(function (g) {
      var c = cells[g.dataset.key] || { status: 'available' };
      STATUS.forEach(function (s) { g.classList.remove('um-' + s); });
      g.classList.add('um-' + c.status);
      var t = g.querySelector('.um-rstat'); if (t) t.textContent = cap(c.status);
    });
  }
  function paintSummary(sum) {
    sum = sum || {};
    document.getElementById('umSumAvailable').textContent = sum.available || 0;
    document.getElementById('umSumOccupied').textContent = sum.occupied || 0;
    document.getElementById('umSumArriving').textContent = sum.arriving || 0;
    document.getElementById('umSumDeparting').textContent = sum.departing || 0;
    document.getElementById('umSumBlocked').textContent = sum.blocked || 0;
  }

  function select(key) {
    selected = key;
    mapEl.querySelectorAll('.is-sel').forEach(function (x) { x.classList.remove('is-sel'); });
    var g = mapEl.querySelector('[data-key="' + key + '"]'); if (g) g.classList.add('is-sel');
    renderDetails(key);
  }
  function lastNight(checkoutYmd) { return checkoutYmd ? fmtDisp(addDays(checkoutYmd, -1)) : ''; }
  function renderDetails(key) {
    var c = cells[key]; if (!c) return;
    var b = c.booking, s = c.status;
    var rows = '';
    if (b) {
      var stayLine = b.check_in ? (fmtDisp(b.check_in) + ' → ' + lastNight(b.check_out) + ' (last night)') : '';
      var pairs = [
        [b.kind === 'block' ? 'Block' : 'Guest', b.guest],
        ['Source', b.source],
        ['Stay', stayLine],
        ['Booking status', cap(b.status)],
        ['Reference', b.ref],
        ['Note', b.note]
      ];
      pairs.forEach(function (p) { if (p[1]) rows += '<div class="um-drow"><span>' + esc(p[0]) + '</span><strong>' + esc(p[1]) + '</strong></div>'; });
    }
    var body = b
      ? rows + (b.hold_id ? '<div class="um-note">Manage this stay from the Availability calendar or Holds — booking ' + esc(String(b.hold_id)) + '.</div>' : '')
      : '<div class="um-note">No booking on ' + esc(fmtDisp(cur)) + '. This unit is available.</div>';
    detailsEl.innerHTML =
      '<div class="um-hero"><span class="um-pill">' + esc(cap(s)) + '</span><h3>' + esc(c.title) + '</h3><p>' + esc(fmtDisp(cur)) + '</p></div>'
      + '<div class="um-dbody">' + body + '</div>';
  }

  function fetchMap() {
    var seq = ++reqSeq;
    statusEl.textContent = 'Loading…';
    fetch(location.pathname, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: JSON.stringify({ action: 'unitmap', date: cur, csrf_token: UM_CSRF })
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (seq !== reqSeq) return;
      if (!d || !d.ok || !d.map) { statusEl.textContent = (d && d.error) || 'Could not load the map.'; return; }
      statusEl.textContent = '';
      var m = d.map;
      document.getElementById('umSetup').hidden = !!m.supported;
      document.getElementById('umBody').hidden = !m.supported;
      if (!m.supported) return;
      if (!svgReady) buildSvg();
      ingest(m); paintMap(); paintSummary(m.summary);
      if (selected) renderDetails(selected);
    }).catch(function () { if (seq === reqSeq) statusEl.textContent = 'Could not reach the server.'; });
  }

  function setDate(ymd) {
    cur = ymd;
    dateInput.value = ymd;
    dateBtn.textContent = fmtDisp(ymd);
    dateBtn.classList.add('dp-btn--active');
    fetchMap();
  }

  function init() {
    if (loaded) return; loaded = true;
    mapEl = document.getElementById('umMap');
    detailsEl = document.getElementById('umDetails');
    statusEl = document.getElementById('umStatus');
    dateInput = document.getElementById('umDateInput');
    dateBtn = document.getElementById('umDateBtn');
    // The shared datepicker writes the hidden input and fires change on single-pick.
    dateInput.addEventListener('change', function () { if (dateInput.value && dateInput.value !== cur) setDate(dateInput.value); });
    document.getElementById('umPrev').addEventListener('click', function () { setDate(addDays(cur, -1)); });
    document.getElementById('umNext').addEventListener('click', function () { setDate(addDays(cur, 1)); });
    document.getElementById('umToday').addEventListener('click', function () { setDate(UM_TODAY); });
    setDate(UM_TODAY);
  }

  // Lazy: initialise the first time the Unit Map tab is opened.
  document.querySelectorAll('.tab[data-tab="unitmap"]').forEach(function (btn) {
    btn.addEventListener('click', init);
  });
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
