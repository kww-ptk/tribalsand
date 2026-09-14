<?php
/**
 * Guest-facing Maya Ilai booking configurator (full pricing parity).
 *
 * Guests pick a mix of villas / studios / bunk rooms / double rooms (+ a living
 * room add-on), allocate guests, and get a live price that applies ALL the
 * tool's rules (single-occupancy discount, extra-guest charges, group discounts,
 * Eco-Resort Fee) — priced server-side via api/maya-ilai-quote.php, which shares
 * the exact same calculation as the staff tool (one pricing path).
 *
 * A selection turns into a booking REQUEST (enquiry) — the property confirms it,
 * matching the existing 24h-hold flow. Set nothing before including.
 */
require_once __DIR__ . '/maya-ilai-pricing.php';
$mibCfg   = maya_ilai_pricing_get();
$mibRates = $mibCfg['rates'];
$mibRules = $mibCfg['rules'];

// Unit types shown to guests, in order. cap = default guests when a unit is added.
$mibUnits = [
    ['key'=>'Villa',  'qty'=>'qtyVilla',  'g'=>'guestVilla',  'rate'=>$mibRates['villa'],  'inc'=>(int)$mibRules['villaIncluded'], 'max'=>(int)$mibRules['villaMax'], 'note'=>'3-bedroom villa · sleeps up to '.$mibRules['villaMax']],
    ['key'=>'Studio', 'qty'=>'qtyStudio', 'g'=>'guestStudio', 'rate'=>$mibRates['studio'], 'inc'=>2, 'max'=>2, 'note'=>'Private studio · sleeps 2'],
    ['key'=>'Bunk Room','qty'=>'qtyBunk', 'g'=>'guestBunk',   'rate'=>$mibRates['bunk'],   'inc'=>(int)$mibRules['bunkIncluded'], 'max'=>(int)$mibRules['bunkMax'], 'note'=>'Villa bunk room · up to '.$mibRules['bunkMax']],
    ['key'=>'Double Room','qty'=>'qtyDouble','g'=>'guestDouble','rate'=>$mibRates['double'],'inc'=>2, 'max'=>2, 'note'=>'Villa double room · sleeps 2'],
];
?>
<style>
  .mib{--mib-line:rgba(184,150,90,.22);--mib-ink:#141412;--mib-mut:#6B6050;font-family:'Jost',sans-serif;color:var(--mib-ink)}
  .mib-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(280px,1fr);gap:2rem;align-items:start}
  .mib-rows{display:flex;flex-direction:column;gap:.9rem}
  .mib-row{display:grid;grid-template-columns:1fr auto auto;gap:1rem;align-items:center;border:1px solid var(--mib-line);background:#fff;padding:.9rem 1.1rem}
  .mib-row__name{font-family:'Cormorant Garamond',serif;font-size:1.25rem;color:var(--mib-ink);line-height:1.1}
  .mib-row__note{font-size:.78rem;color:var(--mib-mut);margin-top:.15rem}
  .mib-row__rate{font-size:.8rem;color:var(--teal,#1E5C6B);margin-top:.2rem;font-weight:500}
  .mib-ctl{display:flex;flex-direction:column;align-items:center;gap:.3rem}
  .mib-ctl__lbl{font-size:.6rem;letter-spacing:.14em;text-transform:uppercase;color:var(--mib-mut)}
  .mib-step{display:flex;align-items:center;gap:.5rem}
  .mib-step button{width:30px;height:30px;border:1px solid var(--mib-line);background:#fff;color:var(--teal,#1E5C6B);font-size:1.1rem;line-height:1;cursor:pointer;border-radius:4px}
  .mib-step button:hover{background:var(--sand-faint,#FAF6EE)}
  .mib-step button:disabled{opacity:.35;cursor:not-allowed}
  .mib-step__n{min-width:1.4rem;text-align:center;font-size:1rem;font-variant-numeric:tabular-nums}
  .mib-extra{display:flex;flex-wrap:wrap;gap:1rem;align-items:center;margin-top:1.1rem;padding-top:1.1rem;border-top:1px solid var(--mib-line)}
  .mib-extra label{font-size:.85rem;color:var(--mib-mut);display:flex;align-items:center;gap:.45rem}
  .mib-extra input[type=number]{width:64px;padding:.45rem .5rem;border:1px solid var(--mib-line);font-family:inherit;font-size:.9rem}
  .mib-summary{position:sticky;top:90px;border:1px solid var(--mib-line);background:#fff;box-shadow:0 8px 32px rgba(0,0,0,.06)}
  .mib-sum-top{background:var(--teal-d,#102F3A);color:#fff;padding:1.3rem 1.4rem}
  .mib-sum-lbl{font-size:.6rem;letter-spacing:.24em;text-transform:uppercase;color:rgba(184,150,90,.7)}
  .mib-sum-total{font-family:'Cormorant Garamond',serif;font-size:2.1rem;line-height:1;margin:.3rem 0 .15rem}
  .mib-sum-per{font-size:.78rem;color:rgba(255,255,255,.55)}
  .mib-lines{padding:1rem 1.4rem}
  .mib-line{display:flex;justify-content:space-between;gap:1rem;font-size:.85rem;padding:.32rem 0;color:var(--mib-mut)}
  .mib-line strong{color:var(--mib-ink);font-weight:500}
  .mib-line--total{border-top:1px solid var(--mib-line);margin-top:.35rem;padding-top:.6rem;font-size:.95rem}
  .mib-line--total strong{font-weight:700}
  .mib-notice{margin:0 1.4rem 1rem;padding:.6rem .8rem;font-size:.78rem;background:var(--sand-faint,#FAF6EE);color:#7a5a1e;border:1px solid var(--mib-line)}
  .mib-notice.err{background:rgba(200,80,60,.06);color:#9B3B2A;border-color:rgba(200,80,60,.2)}
  .mib-cta{display:block;width:calc(100% - 2.8rem);margin:0 1.4rem 1.2rem;padding:.9rem;border:none;background:var(--sand,#B8965A);color:var(--teal-d,#102F3A);font-family:'Jost',sans-serif;font-weight:600;font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;cursor:pointer}
  .mib-cta:hover{background:var(--sand-lt,#D4B07A)}
  .mib-cta:disabled{opacity:.4;cursor:not-allowed}
  .mib-fine{padding:0 1.4rem 1.3rem;font-size:.72rem;color:var(--mib-mut);line-height:1.6}
  /* Enquiry modal */
  .mib-modal{position:fixed;inset:0;background:rgba(16,47,58,.55);display:none;align-items:center;justify-content:center;z-index:600;padding:1rem}
  .mib-modal.open{display:flex}
  .mib-card{background:#fff;max-width:440px;width:100%;padding:1.8rem;max-height:90vh;overflow:auto}
  .mib-card h3{font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:400;margin-bottom:.3rem}
  .mib-card p.sub{font-size:.85rem;color:var(--mib-mut);margin-bottom:1.1rem}
  .mib-field{margin-bottom:.8rem}
  .mib-field label{font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:var(--mib-mut);display:block;margin-bottom:.3rem}
  .mib-field input,.mib-field textarea{width:100%;padding:.65rem .8rem;border:1px solid var(--mib-line);font-family:inherit;font-size:.9rem}
  .mib-modal-actions{display:flex;gap:.6rem;margin-top:.5rem}
  .mib-msg{font-size:.85rem;margin-top:.7rem;display:none}
  .mib-msg.show{display:block}
  .mib-msg.ok{color:#2D7A5F}.mib-msg.bad{color:#9B3B2A}
  @media(max-width:820px){.mib-grid{grid-template-columns:1fr}.mib-summary{position:static}}
</style>

<div class="mib" id="mibRoot"
     data-endpoint="/api/maya-ilai-quote.php"
     data-contact="/api/submit-contact.php"
     data-rates='<?= e(json_encode($mibRates)) ?>'
     data-inc='<?= e(json_encode(['Villa'=>(int)$mibRules['villaIncluded'],'Studio'=>2,'Bunk Room'=>(int)$mibRules['bunkIncluded'],'Double Room'=>2])) ?>'
     data-max='<?= e(json_encode(['Villa'=>(int)$mibRules['villaMax'],'Studio'=>2,'Bunk Room'=>(int)$mibRules['bunkMax'],'Double Room'=>2])) ?>'>
  <div class="mib-grid">
    <div>
      <div class="mib-rows">
        <?php foreach ($mibUnits as $u): ?>
        <div class="mib-row" data-unit="<?= e($u['key']) ?>" data-qty="<?= e($u['qty']) ?>" data-g="<?= e($u['g']) ?>" data-inc="<?= (int)$u['inc'] ?>" data-max="<?= (int)$u['max'] ?>">
          <div>
            <div class="mib-row__name"><?= e($u['key']) ?></div>
            <div class="mib-row__note"><?= e($u['note']) ?></div>
            <div class="mib-row__rate" data-rate="<?= (float)$u['rate'] ?>">from $<?= number_format((float)$u['rate']) ?> / night</div>
          </div>
          <div class="mib-ctl">
            <span class="mib-ctl__lbl">Rooms</span>
            <div class="mib-step" data-step="qty">
              <button type="button" data-dir="-1" aria-label="Fewer">−</button>
              <span class="mib-step__n mib-qty">0</span>
              <button type="button" data-dir="1" aria-label="More">+</button>
            </div>
          </div>
          <div class="mib-ctl">
            <span class="mib-ctl__lbl">Guests</span>
            <div class="mib-step" data-step="g">
              <button type="button" data-dir="-1" aria-label="Fewer">−</button>
              <span class="mib-step__n mib-g">0</span>
              <button type="button" data-dir="1" aria-label="More">+</button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="mib-extra">
        <label><input type="checkbox" id="mibLiving"> Add Living Room + Kitchen <span style="color:var(--teal,#1E5C6B)">($<?= number_format((float)$mibRates['living']) ?>/night)</span></label>
        <label>Nights <input type="number" id="mibNights" min="1" value="3"></label>
      </div>
    </div>

    <aside class="mib-summary">
      <div class="mib-sum-top">
        <div class="mib-sum-lbl">Estimated total</div>
        <div class="mib-sum-total" id="mibTotal">$0</div>
        <div class="mib-sum-per" id="mibPer">Add rooms to price your stay</div>
      </div>
      <div class="mib-lines" id="mibLines"></div>
      <div class="mib-notice" id="mibNotice">Choose your rooms and guests.</div>
      <button class="mib-cta" id="mibRequest" disabled>Request to book</button>
      <div class="mib-fine">Prices in USD. The property confirms availability and holds your dates — you are not charged now. Group discounts apply automatically for larger parties (min <?= (int)$mibRules['minNights'] ?> nights).</div>
    </aside>
  </div>

  <!-- Enquiry modal -->
  <div class="mib-modal" id="mibModal">
    <div class="mib-card">
      <h3>Request your Maya Ilai stay</h3>
      <p class="sub" id="mibSummaryText"></p>
      <form id="mibForm">
        <div class="mib-field"><label>Name</label><input name="name" required></div>
        <div class="mib-field"><label>Email</label><input name="email" type="email" required></div>
        <div class="mib-field"><label>Phone</label><input name="phone"></div>
        <div class="mib-field"><label>Dates / notes (optional)</label><textarea name="note" rows="2" placeholder="Preferred dates, questions…"></textarea></div>
        <input type="text" name="website" style="position:absolute;left:-9999px" tabindex="-1" autocomplete="off" aria-hidden="true">
        <div class="mib-modal-actions">
          <button type="submit" class="mib-cta" style="width:auto;margin:0;flex:1">Send request</button>
          <button type="button" class="mib-cta" id="mibCancel" style="width:auto;margin:0;background:#eee;color:#333">Cancel</button>
        </div>
        <div class="mib-msg" id="mibMsg"></div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var root = document.getElementById('mibRoot');
  if (!root) return;
  var endpoint = root.dataset.endpoint, contact = root.dataset.contact;
  var inc = JSON.parse(root.dataset.inc), max = JSON.parse(root.dataset.max);
  var usd = function (n) { return '$' + Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 }); };
  var state = {}; // unit -> {qty, g}
  root.querySelectorAll('.mib-row').forEach(function (r) { state[r.dataset.unit] = { qty: 0, g: 0 }; });
  var living = false, nights = 3, lastQuote = null, timer = null;

  function payload() {
    var p = { nights: nights, qtyLiving: living ? 1 : 0 };
    root.querySelectorAll('.mib-row').forEach(function (r) {
      var s = state[r.dataset.unit];
      p[r.dataset.qty] = s.qty; p[r.dataset.g] = s.g;
    });
    return p;
  }

  function render(row) {
    var u = row.dataset.unit, s = state[u];
    row.querySelector('.mib-qty').textContent = s.qty;
    row.querySelector('.mib-g').textContent = s.g;
  }

  function quote() {
    clearTimeout(timer);
    timer = setTimeout(function () {
      fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload()) })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.ok) { lastQuote = d.quote; paint(d.quote); } })
        .catch(function () {});
    }, 120);
  }

  function priceSpan(n) {
    if (typeof window.tsPriceSpan === 'function') return window.tsPriceSpan(n, 'USD');
    return usd(n);
  }

  function paint(q) {
    var totalEl = document.getElementById('mibTotal'), perEl = document.getElementById('mibPer');
    var linesEl = document.getElementById('mibLines'), noticeEl = document.getElementById('mibNotice'), cta = document.getElementById('mibRequest');
    var hasErr = q.errors && q.errors.length;
    totalEl.innerHTML = q.guests ? priceSpan(q.total) : '$0';
    perEl.textContent = q.guests && !hasErr ? (priceSpanText(q.total / q.guests / q.nights) + ' per guest / night') : 'Add rooms to price your stay';
    var disc = q.base * q.adjustment / 100;
    linesEl.innerHTML =
      row('Accommodation / night', priceSpan(q.base)) +
      (q.adjustment ? row(q.adjustmentLabel + ' (' + q.adjustment + '%)', priceSpan(disc)) : '') +
      (q.supplements ? row('Extra-guest charges / night', priceSpan(q.supplements)) : '') +
      row(q.nights + ' night' + (q.nights === 1 ? '' : 's'), priceSpan(q.nightly * q.nights)) +
      row('Eco-Resort Fee · ' + q.guests + ' guest' + (q.guests === 1 ? '' : 's'), priceSpan(q.eco)) +
      row('<strong>Estimated total</strong>', '<strong>' + priceSpan(q.total) + '</strong>', true);
    noticeEl.className = 'mib-notice' + (hasErr ? ' err' : '');
    noticeEl.innerHTML = hasErr ? q.errors.join('<br>') : ('Fits the compound · ' + q.guests + ' guest' + (q.guests === 1 ? '' : 's') + ', capacity ' + q.capacity + '.');
    cta.disabled = hasErr || !q.guests;
  }
  function priceSpanText(n) { return usd(n); }
  function row(a, b, tot) { return '<div class="mib-line' + (tot ? ' mib-line--total' : '') + '"><span>' + a + '</span><span>' + b + '</span></div>'; }

  // Steppers
  root.querySelectorAll('.mib-row').forEach(function (r) {
    var u = r.dataset.unit;
    r.querySelectorAll('.mib-step').forEach(function (step) {
      var kind = step.dataset.step;
      step.querySelectorAll('button').forEach(function (b) {
        b.addEventListener('click', function () {
          var dir = parseInt(b.dataset.dir, 10), s = state[u];
          if (kind === 'qty') {
            s.qty = Math.max(0, s.qty + dir);
            // Default guests to the included capacity when rooms change.
            s.g = s.qty === 0 ? 0 : Math.max(s.qty, Math.min(s.qty * max[u], (inc[u] || max[u]) * s.qty));
          } else {
            var lo = s.qty, hi = s.qty * (max[u] || 1);
            s.g = Math.min(hi, Math.max(lo, s.g + dir));
          }
          render(r); quote();
        });
      });
    });
  });
  document.getElementById('mibLiving').addEventListener('change', function () { living = this.checked; quote(); });
  document.getElementById('mibNights').addEventListener('input', function () { nights = Math.max(1, parseInt(this.value, 10) || 1); quote(); });

  // Enquiry modal
  var modal = document.getElementById('mibModal');
  document.getElementById('mibRequest').addEventListener('click', function () {
    if (!lastQuote || lastQuote.errors.length || !lastQuote.guests) return;
    document.getElementById('mibSummaryText').textContent = summaryText(lastQuote);
    modal.classList.add('open');
  });
  document.getElementById('mibCancel').addEventListener('click', function () { modal.classList.remove('open'); });
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.classList.remove('open'); });

  function summaryText(q) {
    var parts = [];
    root.querySelectorAll('.mib-row').forEach(function (r) {
      var s = state[r.dataset.unit];
      if (s.qty) parts.push(s.qty + '× ' + r.dataset.unit + ' (' + s.g + ' guests)');
    });
    if (living) parts.push('Living Room + Kitchen');
    return parts.join(', ') + ' · ' + q.nights + ' nights · ' + usd(q.total) + ' total';
  }

  document.getElementById('mibForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target, msg = document.getElementById('mibMsg');
    if (f.website.value) { modal.classList.remove('open'); return; } // honeypot
    var q = lastQuote;
    var message = 'Maya Ilai booking request:\n' + summaryText(q) +
      '\nAccommodation/night: ' + usd(q.nightly) + ' · Eco fee: ' + usd(q.eco) + ' · Estimated total: ' + usd(q.total) +
      (f.note.value.trim() ? ('\n\nGuest note: ' + f.note.value.trim()) : '');
    var btn = f.querySelector('button[type=submit]'); btn.disabled = true;
    fetch(contact, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: f.name.value, email: f.email.value, phone: f.phone.value,
        subject: 'Maya Ilai booking request', message: message,
        quoted_total: q.total, quoted_currency: 'USD',
        'cf-turnstile-response': ''
      })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok && res.j && res.j.ok) {
          msg.className = 'mib-msg ok show'; msg.textContent = 'Request sent — the property will confirm availability by email shortly.';
          setTimeout(function () { modal.classList.remove('open'); }, 2200);
        } else { msg.className = 'mib-msg bad show'; msg.textContent = (res.j && res.j.error) || 'Could not send. Please try again.'; }
      })
      .catch(function () { msg.className = 'mib-msg bad show'; msg.textContent = 'Network error. Please try again.'; })
      .then(function () { btn.disabled = false; });
  });

  quote();
})();
</script>
