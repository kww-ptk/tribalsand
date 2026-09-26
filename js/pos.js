/* Tribal Sand POS till — /pos/ (pos/index.php). Vanilla JS, no build.
 *
 * The cart lives here; the SERVER decides everything that matters. A sale posts
 * item ids + qty (+ an open price for unpriced items) with a client_uuid; the
 * server re-prices, re-checks stock and room-charge eligibility, and returns the
 * sale it wrote. The uuid is kept across retries of the same sale, so a flaky
 * connection or a double tap can never create two sales.
 */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  var ico = function (id) { return '<svg><use href="#i-' + id + '"/></svg>'; };
  var CSRF = (window.POS_BOOT && window.POS_BOOT.csrf) || window.POS_CSRF || '';

  function api(path, body) {
    var opt = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (body) {
      body.csrf_token = CSRF;
      opt.method = 'POST';
      opt.headers['Content-Type'] = 'application/json';
      opt.body = JSON.stringify(body);
    }
    return fetch(path, opt).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Unexpected reply from the server.' }; }).then(function (d) {
        if (r.status === 401 && d && d.locked) { location.reload(); throw new Error('locked'); }
        d.__status = r.status;
        return d;
      });
    });
  }
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var b = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(b);
    return Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('').replace(/^(.{8})(.{4})(.{4})(.{4})/, '$1-$2-$3-$4-');
  }

  // Any "lock" button on the message screens.
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-action="lock"]')) api('/api/pos/session.php', { action: 'lock' }).then(function () { location.reload(); });
  });

  /* ═══ Lock screen ═══════════════════════════════════════════════════ */
  var lockEl = $('#lock');
  if (lockEl && $('#people')) {
    var pick = null, pin = '', busy = false;
    var dots = $('#dots'), pad = $('#keypad'), err = $('#pinErr');
    var drawDots = function () {
      dots.innerHTML = pick ? [0, 1, 2, 3, 4, 5].slice(0, Math.max(4, pin.length)).map(function (i) { return '<i class="' + (i < pin.length ? 'f' : '') + '"></i>'; }).join('') : '';
    };
    $('#people').addEventListener('click', function (e) {
      var b = e.target.closest('[data-p]'); if (!b) return;
      pick = +b.dataset.p; pin = ''; err.textContent = '';
      document.querySelectorAll('.person').forEach(function (p) { p.classList.toggle('is-on', p === b); });
      pad.hidden = false; drawDots();
    });
    var submit = function () {
      if (busy || pin.length < 4) return;
      busy = true;
      api('/api/pos/session.php', { action: 'pin', user_id: pick, pin: pin }).then(function (d) {
        busy = false;
        if (d.ok) { location.reload(); return; }
        err.textContent = d.error || 'Wrong PIN.'; pin = ''; drawDots();
      }).catch(function () { busy = false; err.textContent = 'No connection — try again.'; });
    };
    pad.addEventListener('click', function (e) {
      var b = e.target.closest('[data-k]'); if (!b || !pick || busy) return;   // ignore taps while a PIN is being checked
      var k = b.dataset.k;
      if (k === 'clear') pin = '';
      else if (k === 'ok') return submit();
      else if (pin.length < 6) pin += k;
      err.textContent = ''; drawDots();
    });
    document.addEventListener('keydown', function (e) {
      if (!pick || busy) return;
      if (/^\d$/.test(e.key) && pin.length < 6) { pin += e.key; drawDots(); }
      else if (e.key === 'Backspace') { pin = pin.slice(0, -1); drawDots(); }
      else if (e.key === 'Enter') submit();
    });
    return;
  }

  /* ═══ Till ══════════════════════════════════════════════════════════ */
  var B = window.POS_BOOT;
  if (!B) return;

  var S = {
    outlet: null, cat: { t: 'all' }, q: '', items: [], cats: [], sources: [],
    cart: [], custMode: 'inhouse', cust: null, guest: null, walk: { name: '', phone: '', id: null },
    pay: null, payRef: '', tender: null, saleKey: null, sending: false, lastSale: null
  };
  var KEY_OUTLET = 'ts_pos_outlet';

  var fmt = function (n) {
    var cur = S.outlet ? S.outlet.currency : 'USD';
    var sym = { USD: '$', EUR: '€', GBP: '£' }[cur];
    var s = Number(n).toLocaleString('en-US', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });
    return sym ? sym + s : cur + ' ' + s;
  };
  var cents = function (n) { return Math.round(Number(n) * 100); };
  var item = function (id) { for (var i = 0; i < S.items.length; i++) if (S.items[i].id === id) return S.items[i]; return null; };
  var initials = function (n) { return String(n).trim().split(/\s+/).map(function (w) { return w.charAt(0); }).join('').slice(0, 2).toUpperCase(); };

  function toast(msg, bad) {
    var t = $('#toast'); t.textContent = msg; t.className = 'toast' + (bad ? ' toast--err' : '');
    clearTimeout(toast._t); toast._t = setTimeout(function () { t.className = 'toast hidden'; }, 3200);
  }

  /* ── Outlets + catalogue ─────────────────────────────────────────── */
  function renderSide() {
    $('#outlets').innerHTML = B.outlets.map(function (o) {
      return '<button type="button" class="outlet ' + (S.outlet && S.outlet.id === o.id ? 'is-on' : '') + '" data-o="' + o.id + '">' + ico(o.icon) + '<span>' + esc(o.name) + '</span></button>';
    }).join('');
  }
  function loadOutlet(id, keepCart) {
    return api('/api/pos/catalog.php?outlet=' + id).then(function (d) {
      if (!d.ok) { toast(d.error || 'Could not load the catalogue.', true); return; }
      var changed = !S.outlet || S.outlet.id !== d.outlet.id;
      S.outlet = d.outlet; S.items = d.items; S.cats = d.categories; S.sources = d.sources;
      try { localStorage.setItem(KEY_OUTLET, String(d.outlet.id)); } catch (e) {}
      if (changed && !keepCart) { S.cart = []; S.cat = { t: 'all' }; S.pay = null; }
      // Drop cart lines that are no longer on sale here.
      S.cart = S.cart.filter(function (l) { return !!item(l.id); });
      if (S.pay === 'room_charge' && !roomOk()) S.pay = null;
      render();
    });
  }
  function stockLeft(it) {
    if (!it.track_stock || it.allow_negative) return null;
    var inCart = 0; S.cart.forEach(function (l) { if (l.id === it.id) inCart += l.qty; });
    return it.stock - inCart;
  }
  function visibleItems() {
    var q = S.q.trim().toLowerCase();
    return S.items.filter(function (i) {
      if (S.cat.t === 'src') { if (i.outlet_id !== S.cat.id) return false; }
      else if (i.outlet_id !== S.outlet.id && !q) return false;         // cross-sold items live under their chip (search finds everything)
      if (S.cat.t === 'cat' && i.category_id !== S.cat.id) return false;
      return !q || i.name.toLowerCase().indexOf(q) !== -1;
    });
  }
  function renderGrid() {
    $('#outletTitle').textContent = S.outlet.name;
    var chips = ['<button type="button" class="chip ' + (S.cat.t === 'all' ? 'is-on' : '') + '" data-c="all">All</button>'];
    S.cats.forEach(function (c) { chips.push('<button type="button" class="chip ' + (S.cat.t === 'cat' && S.cat.id === c.id ? 'is-on' : '') + '" data-c="cat:' + c.id + '">' + esc(c.name) + '</button>'); });
    S.sources.forEach(function (o) { chips.push('<button type="button" class="chip chip--src ' + (S.cat.t === 'src' && S.cat.id === o.id ? 'is-on' : '') + '" data-c="src:' + o.id + '">' + esc(o.name) + '</button>'); });
    $('#chips').innerHTML = chips.join('');
    var list = visibleItems();
    $('#grid').innerHTML = list.map(function (i) {
      var left = stockLeft(i), out = left !== null && left <= 0;
      var tags = [];
      if (i.outlet_id !== S.outlet.id) tags.push('<span class="tag">' + esc(i.outlet_name) + '</span>');
      if (i.track_stock) tags.push('<span class="tag ' + (out ? 'tag--warn' : (i.low_at !== null && left !== null && left <= i.low_at ? 'tag--low' : '')) + '">' + (out ? 'Out of stock' : (left === null ? i.stock : left) + ' in stock') + '</span>');
      if (i.consignor) tags.push('<span class="tag tag--cons">Consignment</span>');
      var img = i.image ? ' style="background-image:url(\'' + esc(i.image) + '\')"' : '';
      var price = i.price === null ? '<small>Enter price</small>' : fmt(i.price) + (i.per_person ? ' <small>/pp</small>' : '');
      return '<button type="button" class="tile" data-i="' + i.id + '"' + (out ? ' disabled' : '') + '>' +
        '<div class="tile__img"' + img + '>' + (i.image ? '' : ico('image')) + '</div>' +
        (tags.length ? '<div class="tags">' + tags.join('') + '</div>' : '') +
        '<div class="tile__b"><div class="tile__n">' + esc(i.name) + '</div><div class="tile__p"><span>' + price + '</span><span class="plus">' + ico('plus') + '</span></div></div></button>';
    }).join('') || '<div class="empty">' + (S.items.length ? 'No items match.' : 'Nothing on sale at this outlet yet.') + '</div>';
  }
  $('#outlets').addEventListener('click', function (e) {
    var b = e.target.closest('[data-o]'); if (!b) return;
    var id = +b.dataset.o;
    if (S.outlet && id === S.outlet.id) return;
    if (S.cart.length) {
      // Switching drops the order (prices and stock belong to the outlet) — ask first.
      S._switchTo = id;
      openModal('<h2>Switch outlet?</h2><div class="sub">The current order will be cleared.</div>' +
        '<div class="btns"><button type="button" class="btn" data-close>Keep order</button><button type="button" class="btn btn--p" id="doSwitch">Switch</button></div>');
      return;
    }
    switchOutlet(id);
  });
  function switchOutlet(id) { S.cart = []; S.cust = null; S.guest = null; S.pay = null; loadOutlet(id).then(renderSide); }
  $('#chips').addEventListener('click', function (e) {
    var b = e.target.closest('[data-c]'); if (!b) return;
    var v = b.dataset.c;
    S.cat = v === 'all' ? { t: 'all' } : { t: v.split(':')[0], id: +v.split(':')[1] };
    renderGrid();
  });
  $('#q').addEventListener('input', function (e) { S.q = e.target.value; renderGrid(); });
  $('#grid').addEventListener('click', function (e) {
    var b = e.target.closest('[data-i]'); if (!b || b.disabled) return;
    var it = item(+b.dataset.i); if (!it) return;
    var left = stockLeft(it);
    if (left !== null && left <= 0) return;
    if (it.price === null) { askPrice(it, null); return; }
    var l = S.cart.find(function (x) { return x.id === it.id; });
    if (l) l.qty++; else S.cart.push({ id: it.id, qty: 1, open: null });
    render();
  });

  /* Open price (an "on request" activity / unpriced service): styled keypad sheet. */
  function askPrice(it, line) {
    var val = line && line.open ? String(line.open) : '';
    openModal('<h2>' + esc(it.name) + '</h2><div class="sub">No set price — enter the agreed price' + (it.per_person ? ' per person' : '') + '.</div>' +
      '<label class="field"><span>' + esc(S.outlet.currency) + '</span><input id="openPrice" type="number" inputmode="decimal" min="0" step="0.01" value="' + esc(val) + '" placeholder="0.00"></label>' +
      '<div class="err" id="openErr"></div>' +
      '<div class="btns"><button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn--p" id="openOk">' + (line ? 'Update' : 'Add') + '</button></div>');
    var inp = $('#openPrice'); setTimeout(function () { inp.focus(); }, 50);
    var ok = function () {
      var v = parseFloat(inp.value);
      if (!(v > 0) || v > 1000000) { $('#openErr').textContent = 'Enter a price above zero.'; return; }
      v = Math.round(v * 100) / 100;
      if (line) line.open = v; else S.cart.push({ id: it.id, qty: 1, open: v });
      closeModal(); render();
    };
    $('#openOk').onclick = ok;
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') ok(); });
  }

  /* ── Customer ────────────────────────────────────────────────────── */
  var searchT = null;
  function renderCust() {
    $('#segIn').classList.toggle('is-on', S.custMode === 'inhouse');
    $('#segWalk').classList.toggle('is-on', S.custMode === 'walkin');
    var h = '';
    if (S.custMode === 'inhouse') {
      if (S.cust) {
        h = '<div class="cust"><span class="avatar">' + esc(initials(S.cust.name)) + '</span>' +
          '<div><strong>' + esc(S.cust.name) + '</strong><small>' + esc(S.cust.room) + ' · ' + esc(S.cust.dates) + (S.cust.ref ? ' · Ref ' + esc(S.cust.ref) : '') + '</small></div>' +
          '<button type="button" class="x" id="custX" aria-label="Remove guest">' + ico('x') + '</button></div>';
        if (S.cust.guests.length > 1) {
          h += '<div class="guests">' + S.cust.guests.map(function (g) {
            return '<button type="button" data-g="' + g.id + '" class="' + (S.guest === g.id ? 'is-on' : '') + '">' + esc(g.name) + (g.lead ? ' (lead)' : '') + '</button>';
          }).join('') + '</div>';
        }
      } else {
        h = '<label class="field">' + ico('search') + '<input id="gq" placeholder="Guest name, room or booking ref…" autocomplete="off"></label><div id="gres"></div>';
      }
    } else {
      h = '<label class="field">' + ico('user') + '<input id="wn" placeholder="Customer name (optional)" value="' + esc(S.walk.name) + '" autocomplete="off"></label>' +
          '<div id="wres"></div>' +
          '<label class="field">' + ico('phone') + '<input id="wp" placeholder="Phone (optional)" value="' + esc(S.walk.phone) + '" inputmode="tel" autocomplete="off"></label>';
    }
    $('#custBox').innerHTML = h;
    var gq = $('#gq');
    if (gq) {
      var run = function () {
        api('/api/pos/inhouse.php?outlet=' + S.outlet.id + '&q=' + encodeURIComponent(gq.value.trim())).then(function (d) {
          if (!$('#gres')) return;
          S._guestResults = d.results || [];
          $('#gres').innerHTML = '<div class="results">' + (S._guestResults.map(function (x, i) {
            return '<button type="button" data-h="' + i + '"><strong>' + esc(x.name) + '</strong><small>' + esc(x.room) + ' · ' + esc(x.dates) + '</small></button>';
          }).join('') || '<div class="empty">No in-house guest found.</div>') + '</div>';
        });
      };
      gq.addEventListener('input', function () { clearTimeout(searchT); searchT = setTimeout(run, 220); });
      run();
    }
    var wn = $('#wn'), wp = $('#wp');
    if (wn) wn.addEventListener('input', function () {
      S.walk.name = wn.value; S.walk.id = null;
      clearTimeout(searchT);
      var v = wn.value.trim();
      if (v.length < 2) { $('#wres').innerHTML = ''; return; }
      searchT = setTimeout(function () {
        api('/api/pos/customers.php?q=' + encodeURIComponent(v)).then(function (d) {
          S._walkResults = d.results || [];
          $('#wres').innerHTML = S._walkResults.length ? '<div class="results">' + S._walkResults.map(function (c, i) {
            return '<button type="button" data-w="' + i + '"><strong>' + esc(c.name) + '</strong>' + (c.phone ? '<small>' + esc(c.phone) + '</small>' : '') + '</button>';
          }).join('') + '</div>' : '';
        });
      }, 250);
    });
    if (wp) wp.addEventListener('input', function () { S.walk.phone = wp.value; S.walk.id = null; });
  }
  $('#custBox').addEventListener('click', function (e) {
    var h = e.target.closest('[data-h]');
    if (h) {
      var r = S._guestResults[+h.dataset.h];
      S.cust = r; var lead = r.guests.filter(function (g) { return g.lead; })[0] || r.guests[0];
      S.guest = lead ? lead.id : null;
      render(); return;
    }
    var w = e.target.closest('[data-w]');
    if (w) { var c = S._walkResults[+w.dataset.w]; S.walk = { name: c.name, phone: c.phone, id: c.id }; renderCust(); return; }
    var g = e.target.closest('[data-g]'); if (g) { S.guest = +g.dataset.g; renderCust(); renderPay(); return; }
    if (e.target.closest('#custX')) { S.cust = null; S.guest = null; if (S.pay === 'room_charge') S.pay = null; render(); }
  });
  $('#segIn').onclick = function () { S.custMode = 'inhouse'; render(); };
  $('#segWalk').onclick = function () { S.custMode = 'walkin'; if (S.pay === 'room_charge') S.pay = null; render(); };

  /* ── Cart + totals (display only — the server re-prices) ──────────── */
  function unitPrice(l) { var it = item(l.id); return it.price === null ? (l.open || 0) : it.price; }
  function totals() {
    var sub = 0; S.cart.forEach(function (l) { sub += cents(unitPrice(l)) * l.qty; });
    var svc = Math.floor((sub * Math.round(S.outlet.service_pct * 100) + 5000) / 10000);
    return { sub: sub / 100, svc: svc / 100, total: (sub + svc) / 100 };
  }
  function renderLines() {
    var n = 0; S.cart.forEach(function (l) { n += l.qty; });
    $('#orderTitle').textContent = 'Order' + (n ? ' (' + n + ' item' + (n === 1 ? '' : 's') + ')' : '');
    $('#lines').innerHTML = S.cart.length ? S.cart.map(function (l, idx) {
      var i = item(l.id), left = stockLeft(i);
      var src = i.outlet_id !== S.outlet.id ? '<div class="line__src">from ' + esc(i.outlet_name) + '</div>' : (i.consignor ? '<div class="line__src">Consignment · ' + esc(i.consignor) + '</div>' : '');
      return '<div class="line"><div><div class="line__n">' + esc(i.name) + '</div>' + src +
        '<div class="qty"><button type="button" data-dec="' + idx + '" aria-label="Less">' + ico(l.qty > 1 ? 'minus' : 'x') + '</button><span>' + l.qty + (i.per_person ? ' pax' : '') + '</span>' +
        '<button type="button" data-inc="' + idx + '" aria-label="More"' + (left !== null && left <= 0 ? ' disabled' : '') + '>' + ico('plus') + '</button>' +
        '<span class="qty__at">× ' + fmt(unitPrice(l)) + '</span>' + (i.price === null ? '<button type="button" class="qty__edit" data-edit="' + idx + '">edit</button>' : '') +
        '</div></div><div class="line__t">' + fmt(unitPrice(l) * l.qty) + '</div></div>';
    }).join('') : '<div class="empty">Tap an item to add it.</div>';
    var t = totals();
    $('#totals').innerHTML = '<div><span>Subtotal</span><span>' + fmt(t.sub) + '</span></div>' +
      (S.outlet.service_pct ? '<div><span>Service charge (' + S.outlet.service_pct + '%)</span><span>' + fmt(t.svc) + '</span></div>' : '') +
      '<div class="grand"><span>Total</span><span>' + fmt(t.total) + '</span></div>';
    $('#handleText').textContent = n ? 'Order · ' + n + ' item' + (n === 1 ? '' : 's') + ' · ' + fmt(t.total) : 'Order';
  }
  $('#lines').addEventListener('click', function (e) {
    var inc = e.target.closest('[data-inc]'), dec = e.target.closest('[data-dec]'), ed = e.target.closest('[data-edit]');
    if (ed) { var le = S.cart[+ed.dataset.edit]; askPrice(item(le.id), le); return; }
    if (inc && !inc.disabled) { var l = S.cart[+inc.dataset.inc], left = stockLeft(item(l.id)); if (left === null || left > 0) l.qty++; }
    if (dec) { var d = S.cart[+dec.dataset.dec]; d.qty--; if (!d.qty) S.cart.splice(+dec.dataset.dec, 1); }
    render();
  });
  $('#clearBtn').onclick = function () { S.cart = []; render(); };

  /* ── Payment ──────────────────────────────────────────────────────── */
  var PAY = [['card', 'card'], ['cash', 'cash'], ['room_charge', 'bed'], ['mobile_money', 'phone'], ['other', 'dots']];
  function roomBlock() {
    if (!S.outlet.room_charge) return S.outlet.room_charge_note || 'Room charge is off here.';
    if (S.custMode !== 'inhouse' || !S.cust) return 'Room charge needs an in-house guest.';
    return S.cust.room_charge_block || null;
  }
  function roomOk() { return S.outlet && !roomBlock(); }
  function renderPay() {
    var block = roomBlock();
    $('#pay').innerHTML = PAY.map(function (p) {
      var k = p[0];
      return '<button type="button" data-pay="' + k + '" class="' + (S.pay === k ? 'is-on' : '') + '"' + (k === 'room_charge' && block ? ' disabled' : '') + '>' + ico(p[1]) + esc(B.payments[k]) + '</button>';
    }).join('');
    var guestName = '';
    if (S.cust) { var g = S.cust.guests.filter(function (x) { return x.id === S.guest; })[0]; guestName = g ? g.name : S.cust.name; }
    $('#payNote').textContent = S.pay === 'room_charge' && S.cust ? 'Posts to ' + guestName + '’s bill (' + S.cust.room + ').' : (block && S.custMode === 'inhouse' && S.cust ? block : '');
    var ready = S.cart.length && S.pay && (S.custMode === 'walkin' || S.cust);
    $('#cta').disabled = !ready;
    $('#cta').innerHTML = 'Review sale ' + (S.cart.length ? fmt(totals().total) : '') + ' ' + ico('arrow');
  }
  $('#pay').addEventListener('click', function (e) { var b = e.target.closest('[data-pay]'); if (!b || b.disabled) return; S.pay = b.dataset.pay; renderPay(); });

  /* ── Review → Complete → Receipt ──────────────────────────────────── */
  function custLabel() {
    if (S.custMode === 'inhouse' && S.cust) {
      var g = S.cust.guests.filter(function (x) { return x.id === S.guest; })[0];
      return (g ? g.name : S.cust.name) + ' · ' + S.cust.room;
    }
    return (S.walk.name.trim() || 'Walk-in') + ' (walk-in)';
  }
  function openModal(html) { $('#sheet').innerHTML = html; $('#modal').classList.remove('hidden'); }
  function closeModal() { $('#modal').classList.add('hidden'); $('#sheet').innerHTML = ''; }
  $('#modal').addEventListener('click', function (e) {
    if (e.target.closest('#doSwitch')) { closeModal(); switchOutlet(S._switchTo); return; }
    if (e.target.id === 'modal' || e.target.closest('[data-close]')) { if (!S.sending) closeModal(); }
  });

  $('#cta').onclick = function () {
    if ($('#cta').disabled) return;
    var t = totals();
    S.saleKey = S.saleKey || uuid();            // same key for every retry of THIS sale
    S.tender = null;
    var refLabel = { card: 'Card slip / last 4 digits (optional)', mobile_money: 'M-Pesa code (optional)', other: 'What was it paid with?' }[S.pay];
    var tenders = [];
    if (S.pay === 'cash') {
      [t.total, Math.ceil(t.total / 10) * 10, Math.ceil(t.total / 50) * 50, Math.ceil(t.total / 100) * 100, Math.ceil(t.total / 1000) * 1000]
        .forEach(function (v) { if (tenders.indexOf(v) === -1 && tenders.length < 4) tenders.push(v); });
    }
    openModal('<h2>Review sale</h2><div class="sub">' + esc(S.outlet.name) + ' · ' + esc(B.user.name) + '</div>' +
      '<div class="rrow"><span>Customer</span><span>' + esc(custLabel()) + '</span></div>' +
      S.cart.map(function (l) { var i = item(l.id); return '<div class="rrow"><span>' + l.qty + (i.per_person ? ' pax' : '') + ' × ' + esc(i.name) + '</span><span>' + fmt(unitPrice(l) * l.qty) + '</span></div>'; }).join('') +
      (t.svc ? '<div class="rrow"><span>Service charge (' + S.outlet.service_pct + '%)</span><span>' + fmt(t.svc) + '</span></div>' : '') +
      '<div class="rrow b"><span>Total</span><span>' + fmt(t.total) + '</span></div>' +
      '<div style="margin-top:10px"><span class="pill">' + ico(PAY.filter(function (p) { return p[0] === S.pay; })[0][1]) + ' ' + esc(B.payments[S.pay]) + '</span></div>' +
      (S.pay === 'cash' ? '<span class="lbl">Cash received</span><div class="cash">' + tenders.map(function (v) { return '<button type="button" data-tend="' + v + '">' + fmt(v) + '</button>'; }).join('') + '</div>' +
        '<label class="field"><span>' + esc(S.outlet.currency) + '</span><input id="tendIn" type="number" inputmode="decimal" min="0" step="0.01" placeholder="Other amount"></label><div id="change" class="change"></div>' : '') +
      (refLabel ? '<span class="lbl">' + esc(refLabel) + '</span><label class="field"><input id="payRef" maxlength="80" autocomplete="off"></label>' : '') +
      (S.pay === 'room_charge' ? '<p class="sub" style="margin:12px 0 0">Added to the guest’s bill for booking <strong>' + esc(S.cust.ref || '#' + S.cust.hold_id) + '</strong> and settled at check-out.</p>' : '') +
      '<div class="err" id="saleErr" role="alert"></div>' +
      '<div class="btns"><button type="button" class="btn" data-close>Back</button><button type="button" class="btn btn--p" id="confirm">Confirm &amp; complete</button></div>');
    var setTender = function (v) {
      S.tender = v;
      var ch = $('#change'); if (!ch) return;
      if (v === null || isNaN(v)) { ch.textContent = ''; return; }
      var diff = cents(v) - cents(t.total);
      ch.className = 'change' + (diff < 0 ? ' change--bad' : '');
      ch.textContent = diff < 0 ? 'Short by ' + fmt(-diff / 100) : 'Change due: ' + fmt(diff / 100);
    };
    $('#sheet').querySelectorAll('[data-tend]').forEach(function (b) {
      b.onclick = function () { $('#sheet').querySelectorAll('[data-tend]').forEach(function (x) { x.classList.toggle('is-on', x === b); }); var ti = $('#tendIn'); if (ti) ti.value = ''; setTender(+b.dataset.tend); };
    });
    var ti = $('#tendIn'); if (ti) ti.addEventListener('input', function () { $('#sheet').querySelectorAll('[data-tend]').forEach(function (x) { x.classList.remove('is-on'); }); setTender(ti.value === '' ? null : parseFloat(ti.value)); });
    $('#confirm').onclick = complete;
  };

  function complete() {
    if (S.sending) return;
    var t = totals();
    if (S.pay === 'cash' && S.tender !== null && cents(S.tender) < cents(t.total)) { $('#saleErr').textContent = 'Cash received is less than the total.'; return; }
    var pr = $('#payRef'); S.payRef = pr ? pr.value.trim() : '';
    if (S.pay === 'other' && !S.payRef) { $('#saleErr').textContent = 'Say how it was paid.'; return; }
    S.sending = true;
    var btn = $('#confirm'); btn.disabled = true; btn.textContent = 'Saving…';
    var customer = S.custMode === 'inhouse' && S.cust
      ? { type: 'inhouse', hold_id: S.cust.hold_id, guest_id: S.guest }
      : { type: 'walkin', name: S.walk.name.trim(), phone: S.walk.phone.trim(), pos_customer_id: S.walk.id };
    api('/api/pos/sale.php', {
      outlet_id: S.outlet.id, client_uuid: S.saleKey,
      lines: S.cart.map(function (l) { return { item_id: l.id, qty: l.qty, open_price: item(l.id).price === null ? l.open : null }; }),
      payment_method: S.pay, payment_ref: S.payRef, cash_tendered: S.pay === 'cash' ? S.tender : null, customer: customer
    }).then(function (d) {
      S.sending = false;
      if (!d.ok) {
        // A refusal is final for this cart; a fresh key lets the corrected cart go through.
        if (d.__status === 422) S.saleKey = null;
        btn.disabled = false; btn.textContent = 'Confirm & complete';
        $('#saleErr').textContent = d.error || 'The sale could not be completed.';
        if (d.__status === 422) loadOutlet(S.outlet.id, true);   // stock may have moved on another tablet
        return;
      }
      S.saleKey = null;
      var tendered = S.tender;
      S.cart = []; S.pay = null; S.cust = null; S.guest = null; S.walk = { name: '', phone: '', id: null }; S.tender = null;
      showReceipt(d.sale, tendered);
      loadOutlet(S.outlet.id, true);
    }).catch(function (err) {
      if (err && err.message === 'locked') return;
      S.sending = false;           // keep the SAME key: a retry returns the sale if it did go through
      btn.disabled = false; btn.textContent = 'Retry';
      $('#saleErr').textContent = 'No connection — nothing is lost. Tap Retry.';
    });
  }

  function receiptHtml(s, tendered) {
    var money = function (n) { var sym = { USD: '$', EUR: '€', GBP: '£' }[s.currency]; var v = Number(n).toLocaleString('en-US', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 }); return sym ? sym + v : s.currency + ' ' + v; };
    return '<div class="rrow"><span>Customer</span><span>' + esc(s.customer) + '</span></div>' +
      s.lines.map(function (l) { return '<div class="rrow"><span>' + l.qty + ' × ' + esc(l.name) + (l.consignment ? '<br><small>Consignment</small>' : '') + '</span><span>' + money(l.line_total) + '</span></div>'; }).join('') +
      (s.service_charge ? '<div class="rrow"><span>Service charge</span><span>' + money(s.service_charge) + '</span></div>' : '') +
      '<div class="rrow b"><span>Total · ' + esc(s.payment_label) + '</span><span>' + money(s.total) + '</span></div>' +
      (s.payment_ref ? '<div class="sub" style="margin:6px 0 0">Ref: ' + esc(s.payment_ref) + '</div>' : '') +
      (tendered ? '<div class="sub" style="margin:6px 0 0">Cash ' + money(tendered) + ' · change ' + money(Math.max(0, (Math.round(tendered * 100) - Math.round(s.total * 100)) / 100)) + '</div>' : '');
  }
  function showReceipt(s, tendered) {
    S.lastSale = s;
    openModal('<div class="center"><div class="ok">' + ico('check') + '</div><h2>Sale complete</h2><div class="sub">' + esc(s.reference) + ' · ' + esc(s.time) + '</div></div>' +
      receiptHtml(s, tendered) +
      (s.payment_method === 'room_charge' ? '<p class="sub" style="margin:10px 0 0">Posted to the guest’s bill.</p>' : '') +
      '<div class="btns"><a class="btn" href="/pos/receipt.php?sale=' + s.id + '&print=1" target="_blank" rel="noopener">' + ico('printer') + ' Print</a><button type="button" class="btn btn--p" data-close>New sale</button></div>');
  }

  /* ── History + void ───────────────────────────────────────────────── */
  $('#histBtn').onclick = function () {
    openModal('<h2>Sales history</h2><div class="sub">' + esc(S.outlet.name) + ' · today</div><div class="hist" id="hist"><div class="empty">Loading…</div></div><div class="btns"><button type="button" class="btn" data-close>Close</button></div>');
    api('/api/pos/sales.php?outlet=' + S.outlet.id).then(function (d) {
      var el = $('#hist'); if (!el) return;
      if (!d.ok) { el.innerHTML = '<div class="empty">' + esc(d.error || 'Could not load sales.') + '</div>'; return; }
      var sums = (d.sums || []).map(function (x) { return x.count + ' sale' + (x.count === 1 ? '' : 's') + ' · ' + x.currency + ' ' + Number(x.total).toLocaleString('en-US', { maximumFractionDigits: 2 }); }).join(' · ');
      el.innerHTML = (sums ? '<div class="sub">' + esc(sums) + '</div>' : '') + (d.sales.length ? '<table><thead><tr><th>Time</th><th>Customer</th><th>Paid</th><th class="num">Total</th></tr></thead><tbody>' +
        d.sales.map(function (s) {
          return '<tr data-s="' + s.id + '" class="' + (s.status === 'voided' ? 'is-void' : '') + '"><td>' + esc(s.time) + '<br><small>' + esc(s.reference) + '</small></td><td>' + esc(s.customer) + '<br><small>' + esc(s.staff) + '</small></td><td>' + esc(s.payment_label) + '</td><td class="num">' + esc(s.currency) + ' ' + Number(s.total).toLocaleString('en-US', { maximumFractionDigits: 2 }) + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div class="empty">No sales yet today.</div>');
    });
  };
  $('#sheet').addEventListener('click', function (e) {
    var r = e.target.closest('tr[data-s]'); if (!r) return;
    api('/api/pos/sales.php?sale=' + r.dataset.s).then(function (d) {
      if (!d.ok) { toast(d.error || 'Sale not found.', true); return; }
      var s = d.sale;
      openModal('<h2>' + esc(s.reference) + '</h2><div class="sub">' + esc(s.outlet) + ' · ' + esc(s.time) + ' · ' + esc(s.staff) + '</div>' +
        (s.status === 'voided' ? '<div style="margin-bottom:10px"><span class="pill pill--void">' + ico('ban') + ' Voided' + (s.void_reason ? ' — ' + esc(s.void_reason) : '') + '</span></div>' : '') +
        receiptHtml(s, null) +
        '<div class="btns"><button type="button" class="btn" data-close>Close</button><a class="btn" href="/pos/receipt.php?sale=' + s.id + '&print=1" target="_blank" rel="noopener">' + ico('printer') + ' Print</a>' +
        (d.can_void ? '<button type="button" class="btn btn--d" id="voidBtn" data-sale="' + s.id + '">Void</button>' : '') + '</div>');
    });
  });
  $('#sheet').addEventListener('click', function (e) {
    var b = e.target.closest('#voidBtn'); if (!b) return;
    var sid = +b.dataset.sale;
    openModal('<h2>Void this sale?</h2><div class="sub">Stock goes back on the shelf and any room charge comes off the guest’s bill. This can’t be undone — ring a new sale if needed.</div>' +
      '<span class="lbl">Reason</span><label class="field"><input id="voidReason" maxlength="200" placeholder="e.g. wrong item, guest changed mind" autocomplete="off"></label>' +
      '<div class="err" id="voidErr"></div><div class="btns"><button type="button" class="btn" data-close>Keep sale</button><button type="button" class="btn btn--d" id="voidGo">Void sale</button></div>');
    $('#voidGo').onclick = function () {
      var reason = $('#voidReason').value.trim();
      if (reason.length < 3) { $('#voidErr').textContent = 'Give a reason.'; return; }
      this.disabled = true;
      api('/api/pos/void.php', { sale_id: sid, reason: reason }).then(function (d) {
        if (!d.ok) { $('#voidErr').textContent = d.error || 'Could not void.'; $('#voidGo').disabled = false; return; }
        closeModal(); toast(d.sale.reference + ' voided.'); loadOutlet(S.outlet.id, true);
      });
    };
  });

  /* ── Bottom sheet (portrait) ─────────────────────────────────────── */
  $('#panelHandle').onclick = function () { $('#panel').classList.toggle('is-open'); };

  /* ── Lock + idle ─────────────────────────────────────────────────── */
  var lockNow = function () { api('/api/pos/session.php', { action: 'lock' }).then(function () { location.reload(); }, function () { location.reload(); }); };
  var lockBtn = $('#lockBtn'); if (lockBtn) lockBtn.onclick = lockNow;
  if (B.idleLock > 0) {
    var idleT = null, active = false;
    var arm = function () { active = true; clearTimeout(idleT); idleT = setTimeout(function () { if (!S.sending) lockNow(); }, B.idleLock * 1000); };
    ['click', 'keydown', 'touchstart', 'input'].forEach(function (ev) { document.addEventListener(ev, arm, { passive: true }); });
    arm();
    // Heartbeat: building a cart makes no request, so tell the server we're still here.
    setInterval(function () { if (active && !document.hidden) { active = false; api('/api/pos/session.php').catch(function () {}); } }, 45000);
  }

  function render() { renderSide(); renderGrid(); renderCust(); renderLines(); renderPay(); }

  var saved = null; try { saved = +localStorage.getItem(KEY_OUTLET); } catch (e) {}
  var first = B.outlets.filter(function (o) { return o.id === saved; })[0] || B.outlets[0];
  loadOutlet(first.id);
})();
