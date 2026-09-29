/* Quote builder — the all-properties quote tool (admin/quote-builder.php and the
 * enquiry pop-up in admin/submission-view.php).
 *
 * Server-priced: every change posts the selection to /api/quote-builder.php,
 * which re-prices it with the booking engine's own resolvers and returns lines,
 * totals, notices and the quote text. This script only collects input and
 * paints the answer. Amounts are <span class="mny"> painted by admin-money.js.
 *
 * Emitted INLINE by includes/quote-builder-view.php (admin shell navigation
 * re-runs inline scripts only), so it guards against double-binding.
 */
(function () {
  'use strict';

  function q(root, sel) { return root.querySelector(sel); }
  function qa(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }
  function int(v) { var n = parseInt(v, 10); return isNaN(n) || n < 0 ? 0 : n; }
  function num(v) { if (v === '' || v == null) return null; var n = parseFloat(v); return isNaN(n) || n < 0 ? null : n; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function money(o, short) {
    return o ? '<span class="mny" data-amt="' + o.amt + '" data-cur="' + esc(o.cur) + '"' + (short ? ' data-fmt="short"' : '') + '></span>' : '—';
  }
  function curNow() { return window.tsMoney ? window.tsMoney.current() : 'KES'; }

  function init(root) {
    if (root.dataset.qbReady) return;
    root.dataset.qbReady = '1';
    var cat = {};
    try { cat = JSON.parse(q(root, 'script[data-qb-catalog]').textContent || '{}'); } catch (e) { cat = {}; }
    var seq = 0, timer = null, last = null, lastDates = null, freeSeen = {}, xseq = 0;
    root.__qbDates = function () { return q(root, '[data-qb-ci]').value + '|' + q(root, '[data-qb-co]').value; };
    root.__qbSeenDates = root.__qbDates();

    function selection() {
      var ci = q(root, '[data-qb-ci]').value, co = q(root, '[data-qb-co]').value;
      var rooms = qa(root, 'tr[data-room]').map(function (tr) {
        return { id: +tr.getAttribute('data-room'), qty: int(q(tr, '[data-qb-qty]').value), guests: int(q(tr, '[data-qb-guests]').value) };
      }).filter(function (r) { return r.qty > 0; });
      var extras = qa(root, 'tr[data-extra]').map(function (tr) {
        var p = q(tr, '[data-qb-price]');
        var lbl = q(tr, '[data-qb-label]'), pc = q(tr, '[data-qb-pcur]'), bs = q(tr, '[data-qb-basis]');
        return {
          key: tr.getAttribute('data-extra'), kind: tr.getAttribute('data-kind'), id: +(tr.getAttribute('data-id') || 0),
          label: lbl ? lbl.value : '', qty: int(q(tr, '[data-qb-xqty]').value), price: num(p.value),
          edited: p.value !== (p.getAttribute('data-default') || ''),
          price_cur: pc ? pc.value : '', basis: bs ? bs.value : ''
        };
      });
      return {
        name: q(root, '[data-qb-name]').value, check_in: ci, check_out: co,
        adults: int(q(root, '[data-qb-adults]').value), children: int(q(root, '[data-qb-children]').value),
        discount_pct: num(q(root, '[data-qb-disc]').value) || 0, discount_note: q(root, '[data-qb-discnote]').value,
        cur: curNow(), rooms: rooms, extras: extras, want_free: (ci + '|' + co) !== lastDates
      };
    }

    function schedule() { clearTimeout(timer); timer = setTimeout(price, 300); }
    root.__qbSchedule = schedule;

    function price() {
      var s = selection(), my = ++seq, status = q(root, '[data-qb-status]');
      status.textContent = 'Pricing…';
      fetch(root.getAttribute('data-endpoint'), {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: root.getAttribute('data-csrf'), sel: s })
      })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (d) {
          if (my !== seq) return;
          if (!d || !d.ok) { status.textContent = (d && d.error) || 'Could not price this quote.'; return; }
          status.textContent = '';
          if (s.want_free) { lastDates = s.check_in + '|' + s.check_out; freeSeen = {}; }
          last = d.quote;
          paint(d.quote);
        })
        .catch(function () { if (my === seq) status.textContent = 'Could not reach the server. Your last quote is kept.'; });
    }

    function paint(qt) {
      (qt.rooms || []).forEach(function (r) {
        var tr = q(root, 'tr[data-room="' + r.id + '"]');
        if (!tr) return;
        if ('free' in r) freeSeen[r.id] = { free: r.free, exact: r.free_exact };
        var f = freeSeen[r.id], fc = q(tr, '[data-qb-free]');
        fc.textContent = !f || f.free === null ? '—' : (f.exact === false ? (f.free ? 'Yes' : 'No') : String(f.free));
        fc.classList.toggle('is-none', !!f && f.free === 0);
        q(tr, '[data-qb-cap]').textContent = r.capacity || '—';
        q(tr, '[data-qb-avg]').innerHTML = money(r.avg);
        q(tr, '[data-qb-line]').innerHTML = r.line ? money(r.line) : '—';
        q(tr, '[data-qb-mix]').textContent = r.qty > 0 ? r.mix : '';
        tr.classList.toggle('is-picked', r.qty > 0);
      });
      (qt.extras || []).forEach(function (x) {
        var tr = q(root, 'tr[data-extra="' + x.key + '"]');
        if (tr) q(tr, '[data-qb-xline]').innerHTML = x.line ? money(x.line) : '—';
      });
      var s = qt.summary, c = qt.currency;
      function m(a) { return money({ amt: a, cur: c }); }
      var any = (qt.lines || []).length > 1;
      q(root, '[data-qb-total]').innerHTML = any ? m(s.total) : '—';
      q(root, '[data-qb-perguest]').innerHTML = !any ? 'Add rooms or extras'
        : (s.per_guest !== null ? m(s.per_guest) + ' per guest' : 'Add guests for a per-guest figure');
      q(root, '[data-qb-m-nights]').textContent = qt.nights || 0;
      q(root, '[data-qb-m-guests]').textContent = s.guests;
      q(root, '[data-qb-m-cap]').textContent = s.capacity;
      q(root, '[data-qb-m-nightly]').innerHTML = s.nightly !== null ? m(s.nightly) : '—';
      q(root, '[data-qb-breakdown]').innerHTML = any ? (qt.lines || []).map(function (l) {
        return '<div class="qb-line' + (l.kind === 'total' ? ' qb-line--total' : '') + '"><span>' + esc(l.label) + '</span><span>'
          + (l.kind === 'discount' ? '−' : '') + m(l.amt) + '</span></div>';
      }).join('') + (qt.fx_note ? '<p class="qb-fx">' + esc(qt.fx_note) + '</p>' : '') : '';
      q(root, '[data-qb-notices]').innerHTML = (qt.notices || []).map(function (n) {
        return '<div class="qb-notice qb-notice--' + esc(n.type) + '">' + esc(n.text) + '</div>';
      }).join('');
      if (window.tsMoney) window.tsMoney.apply(root);
    }

    // ── Extras ──────────────────────────────────────────────────────────────
    var tbody = q(root, '[data-qb-extras]');
    function selectHtml(attr, opts, val) {
      return '<select ' + attr + '>' + opts.map(function (o) {
        return '<option value="' + esc(o[0]) + '"' + (o[0] === val ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
      }).join('') + '</select>';
    }
    function addExtra(value) {
      var parts = value.split(':'), kind = parts[0], id = +(parts[1] || 0), item = null, key = 'x' + (++xseq);
      if (kind === 'tour') item = (cat.tours || []).filter(function (t) { return t.id === id; })[0];
      if (kind === 'transfer') item = (cat.transfers || []).filter(function (t) { return t.id === id; })[0];
      if (kind !== 'custom' && !item) return;
      var none = q(tbody, '.qb-noextras'); if (none) none.remove();
      var party = int(q(root, '[data-qb-adults]').value) + int(q(root, '[data-qb-children]').value);
      var tr = document.createElement('tr');
      tr.setAttribute('data-extra', key);
      tr.setAttribute('data-kind', kind);
      if (id) tr.setAttribute('data-id', String(id));
      var def = item && item.price !== null ? String(item.price) : '';
      var curLbl = kind === 'tour' ? cat.tour_cur : cat.transfer_cur;
      var basis = kind === 'tour' ? (item.per_person ? 'per person' : 'per trip') : (kind === 'transfer' ? 'per transfer' : '');
      tr.innerHTML =
        '<td>' + (kind === 'custom' ? '<input class="inp inp--sm" data-qb-label placeholder="Private chef dinner">' : esc(item.name)) + '</td>'
        + '<td><input class="inp inp--sm inp--num qb-num" type="number" min="0" data-qb-xqty value="'
        + (kind === 'tour' && item.per_person ? Math.max(1, party) : 1) + '"></td>'
        + '<td><input class="inp inp--sm inp--num" type="number" min="0" step="0.01" data-qb-price data-default="' + esc(def)
        + '" value="' + esc(def) + '" placeholder="Price">'
        + (kind === 'custom' ? ' ' + selectHtml('data-qb-pcur', [['KES', 'KES'], ['USD', 'USD']], curNow())
          : ' <span class="qb-mix" style="display:inline">' + esc(curLbl) + '</span>') + '</td>'
        + '<td>' + (kind === 'custom'
          ? selectHtml('data-qb-basis', [['stay', 'per stay'], ['night', 'per night'], ['person', 'per person']], 'stay')
          : esc(basis)) + '</td>'
        + '<td class="qb-money" data-qb-xline>—</td>'
        + '<td><button type="button" class="btn-icon" data-qb-xrm aria-label="Remove">×</button></td>';
      tbody.appendChild(tr);
      if (window.enhanceSelects) window.enhanceSelects(tr);
      var focus = q(tr, '[data-qb-label]') || q(tr, '[data-qb-price]');
      if (focus && (kind === 'custom' || def === '')) focus.focus();
      schedule();
    }
    var pick = q(root, '[data-qb-pick]');
    pick.addEventListener('change', function () {
      var v = pick.value;
      if (!v) return;
      addExtra(v);
      pick.selectedIndex = 0;
      pick.dispatchEvent(new Event('change'));          // re-sync the enhanced dropdown label
    });
    tbody.addEventListener('click', function (e) {
      var b = e.target.closest('[data-qb-xrm]');
      if (!b) return;
      b.closest('tr').remove();
      if (!q(tbody, 'tr[data-extra]')) tbody.innerHTML = '<tr class="qb-noextras"><td colspan="6">No extras yet.</td></tr>';
      schedule();
    });

    // ── Property chips hide/show room groups (never changes the selection) ──
    qa(root, '[data-qb-venue]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var g = q(root, 'tbody[data-qb-group="' + cb.getAttribute('data-qb-venue') + '"]');
        if (g) g.hidden = !cb.checked;
      });
    });

    // ── Any input change re-prices ──────────────────────────────────────────
    root.addEventListener('input', function (e) { if (!e.target.closest('[data-qb-pick]') && !e.target.closest('[data-qb-venue]')) schedule(); });
    root.addEventListener('change', function (e) { if (!e.target.closest('[data-qb-pick]') && !e.target.closest('[data-qb-venue]')) schedule(); });

    // ── Output ──────────────────────────────────────────────────────────────
    q(root, '[data-qb-copy]').addEventListener('click', function () {
      var b = this;
      if (!last) return;
      var done = function () { var o = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = o; }, 1400); };
      if (navigator.clipboard) navigator.clipboard.writeText(last.text).then(done, function () {});
    });
    q(root, '[data-qb-print]').addEventListener('click', function () {
      if (!last) return;
      var out = q(root, '[data-qb-printout]'), c = last.currency;
      var t = window.tsMoney ? window.tsMoney.text : function (v) { return String(Math.round(v)); };
      var rows = (last.lines || []).map(function (l) {
        return '<tr class="' + (l.kind === 'total' ? 'qb-p-total' : '') + '"><td>' + esc(l.label) + '</td><td>'
          + (l.kind === 'discount' ? '−' : '') + esc(t(l.amt, c, false)) + '</td></tr>';
      }).join('');
      var lines = last.text.split('\n');
      out.innerHTML = '<img src="/images/whitelogo11.png" alt="Tribal Sand">'
        + '<h1>' + esc(lines[0]) + '</h1>' + (lines[1] ? '<p>' + esc(lines[1]) + '</p>' : '')
        + '<table>' + rows + '</table>'
        + (last.fx_note ? '<p>' + esc(last.fx_note) + '</p>' : '')
        + '<p>' + esc(lines[lines.length - 1]) + '</p>';
      // Scope the print stylesheet to this action only (see the view's @media print).
      var body = document.body;
      var done = function () { body.classList.remove('qb-printing'); window.removeEventListener('afterprint', done); };
      body.classList.add('qb-printing');
      window.addEventListener('afterprint', done);
      window.print();
      if (!('onafterprint' in window)) setTimeout(done, 1000);   // fallback where afterprint never fires
    });
    var ins = q(root, '[data-qb-insert]');
    if (ins) ins.addEventListener('click', function () {
      if (!last) return;
      document.dispatchEvent(new CustomEvent('qb:insert', { detail: { text: last.text } }));
    });

    // The enquiry pop-up sits hidden on every page load — pricing it then would fire a
    // full availability sweep for nothing. Its Build quote button calls __qbSchedule()
    // on open (set above, with data-qb-ready), which prices on first open.
    if (root.getAttribute('data-context') !== 'modal') schedule();
  }

  // ── Once per window: dates (the datepicker fires no change for ranges) and
  //    the currency switch re-price every builder on the page. ──────────────
  if (!window.__qbGlobal) {
    window.__qbGlobal = true;
    document.addEventListener('click', function () {
      setTimeout(function () {
        qa(document, '.qb[data-qb-ready]').forEach(function (root) {
          if (!root.__qbDates) return;
          var d = root.__qbDates();
          if (d !== root.__qbSeenDates) { root.__qbSeenDates = d; root.__qbSchedule(); }
        });
      }, 0);
    }, true);
    document.addEventListener('ts:currency', function () {
      qa(document, '.qb[data-qb-ready]').forEach(function (root) { if (root.__qbSchedule) root.__qbSchedule(); });
    });
  }

  qa(document, '.qb[data-qb]').forEach(init);
})();
