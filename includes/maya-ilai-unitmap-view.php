<?php
/**
 * The Maya Ilai unit map — live aerial view of every villa bedroom and studio for
 * one day (Calendar → "Ilai unit map", admin/unit-map.php). Moved out of the Maya
 * Ilai rates page (Oct 2026, owner). READ-ONLY: the script only POSTs
 * {action:'unitmap', date} to $um_endpoint, whose handler calls mi_unit_map().
 * Set $um_endpoint before including (defaults to /admin/unit-map.php).
 */
?>
<div id="ilai-unitmap">
<style>
  /* The tokens the map's styles were written against (they lived on #mi-tool). */
  #ilai-unitmap{--navy:#182247;--teal-soft:#e6f4f2;--muted:#69758a;--line:#dfe5ec;--panel:#fff;--shadow:0 8px 24px rgba(23,34,71,.06);--r:14px;color:#24324a}
  #ilai-unitmap .section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:14px}
  #ilai-unitmap .section-head h2{margin:0 0 4px;font-size:1.15rem}
  #ilai-unitmap .section-head p{margin:0;color:var(--muted);font-size:.86rem;max-width:760px}
  #ilai-unitmap .pill{display:inline-flex;padding:5px 10px;border-radius:999px;background:var(--teal-soft);color:#0a6b64;font-size:.75rem;font-weight:700;white-space:nowrap}
  #ilai-unitmap .status{font-size:.82rem;color:var(--muted)}
  /* The admin .dp-btn is full width by default; here it sits between ‹ and ›. */
  #ilai-unitmap .um-date{flex-wrap:nowrap}
  #ilai-unitmap .um-date .dp-btn{width:auto;flex:0 0 auto;min-width:170px}
  @media(max-width:520px){#ilai-unitmap .um-date{flex-wrap:wrap}}
</style>
<section id="view-unitmap">
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
      <div><p>Live booking status for every villa bedroom and studio on a chosen day. Reads the same calendar as the availability Gantt — website holds, OTA imports, channel-manager imports, agent holds and maintenance closures all appear here.</p></div>
      <span class="pill">Live · read-only</span>
    </div>

    <div class="um-toolbar">
      <div class="um-date">
        <button type="button" class="btn-outline btn-sm um-nav" id="umPrev" aria-label="Previous day">&lsaquo;</button>
        <button type="button" class="dp-btn um-dp" id="umDateBtn" data-dp-target="umDateInput" data-dp-placeholder="Pick a date"></button>
        <input type="hidden" id="umDateInput">
        <button type="button" class="btn-outline btn-sm um-nav" id="umNext" aria-label="Next day">&rsaquo;</button>
        <button type="button" class="btn-outline btn-sm um-today" id="umToday">Today</button>
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
/* ── Unit Map — live aerial compound view ──────────────────────────────────────
   A self-contained controller. It fetches the map for a chosen date and paints
   the SVG. READ-ONLY: it only ever POSTs {action:'unitmap', date}. */
(function () {
  var UM_CSRF  = <?= json_encode(csrf_token()) ?>;
  var UM_ENDPOINT = <?= json_encode($um_endpoint ?? '/admin/unit-map.php') ?>;
  var UM_TODAY = <?= json_encode(date('Y-m-d')) ?>;   // Nairobi-local (see db.php tz)
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var STATUS = ['available', 'occupied', 'arriving', 'departing', 'blocked'];
  var CAP = { DA: '2', DB: '2', B: '6', L: 'Kitchen' };

  var mapEl, detailsEl, statusEl, dateInput, dateBtn;
  var cur = UM_TODAY, cells = {}, heads = {}, loaded = false, selected = null, svgReady = false, reqSeq = 0;

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
  function roomSvg(key, x, y, w, capacity) {
    return '<g class="um-hit um-available" role="button" tabindex="0" data-key="' + key + '">'
      + '<rect class="um-floor" x="' + x + '" y="' + y + '" width="' + w + '" height="92" rx="3"/>'
      + '<text class="um-rlabel" x="' + (x + w / 2) + '" y="' + (y + 26) + '"></text>'
      + '<text class="um-cap" x="' + (x + w / 2) + '" y="' + (y + 47) + '">' + capacity + '</text>'
      + '<text class="um-rstat" x="' + (x + w / 2) + '" y="' + (y + 73) + '"></text></g>';
  }
  /* Names (V1, 1A, S1…) are NOT drawn here — they arrive with the map data
     (mi_unitmap_*_code() in PHP, the one naming rule) and paintMap() writes them. */
  function villaSvg(n, x, y) {
    var id = 'V' + pad(n);
    return '<g transform="translate(' + x + ' ' + y + ')">'
      + '<rect class="um-shell" x="-7" y="-35" width="258" height="235" rx="7"/>'
      + '<text class="um-blabel" x="122" y="-13" data-head="' + id + '"></text>'
      + roomSvg(id + '-DA', 0, 0, 120, '2 guests')
      + roomSvg(id + '-DB', 124, 0, 120, '2 guests')
      + roomSvg(id + '-B', 0, 96, 120, '6 guests')
      + roomSvg(id + '-L', 124, 96, 120, 'Kitchen') + '</g>';
  }
  function studioSvg(n, x, y) {
    return '<g transform="translate(' + x + ' ' + y + ')">'
      + '<text class="um-blabel" x="70" y="-12" data-head="S' + pad(n) + '"></text>'
      + roomSvg('S' + pad(n) + 'A', 0, 0, 140, '2 guests') + '</g>';
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
    cells = {}; heads = {};
    (map.villas || []).forEach(function (v) {
      var p = 'V' + pad(v.n), r = v.rooms || {};
      heads[p] = v.label;
      var mk = function (rk, k) {
        var room = r[k] || {}, name = (room.code ? room.code + ' · ' : '') + (room.label || k);
        cells[p + '-' + rk] = { status: room.status || 'available', booking: room.booking || null,
          label: name, title: name + ' — ' + v.label, room: room.label || k };
      };
      mk('DA', 'double_a'); mk('DB', 'double_b'); mk('B', 'bunk'); mk('L', 'living');
    });
    (map.studios || []).forEach(function (s) {
      heads['S' + pad(s.n)] = s.label;
      cells['S' + pad(s.n) + 'A'] = { status: s.status || 'available', booking: s.booking || null,
        label: 'Studio', title: s.label + ' · Studio', room: 'Studio' };
    });
  }
  function paintMap() {
    if (!svgReady) return;
    mapEl.querySelectorAll('[data-head]').forEach(function (t) { t.textContent = heads[t.dataset.head] || ''; });
    mapEl.querySelectorAll('[data-key]').forEach(function (g) {
      var c = cells[g.dataset.key] || { status: 'available' };
      STATUS.forEach(function (s) { g.classList.remove('um-' + s); });
      g.classList.add('um-' + c.status);
      var l = g.querySelector('.um-rlabel'); if (l) l.textContent = c.label || '';
      var t = g.querySelector('.um-rstat'); if (t) t.textContent = cap(c.status);
      g.setAttribute('aria-label', (c.title || '') + ': ' + cap(c.status));
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
    fetch(UM_ENDPOINT, {
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

  // Its own page now (Calendar → Ilai unit map): start straight away. Inline, so the
  // admin shell re-runs it on every swap onto this page; each run is a fresh closure.
  if (document.getElementById('umMap')) init();
})();
</script>
