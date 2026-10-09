/* Quote builder — the all-properties quote tool (admin/quote-builder.php and the
 * enquiry pop-up in admin/submission-view.php).
 *
 * Server-priced: every change posts the selection to /api/quote-builder.php,
 * which re-prices it with the booking engine's own resolvers and returns lines,
 * totals, notices and the quote text. This script only collects input and
 * paints the answer. Amounts are <span class="mny"> painted by admin-money.js.
 *
 * On an enquiry (data-can-save="1"): "Save to enquiry" stores the quote as the
 * enquiry's next option (the server re-prices); "Insert into reply" and
 * "Print / PDF" save first, so every option offered or printed is on record.
 * One save per distinct selection — re-inserting or re-printing an unchanged
 * quote reuses the saved option. Print opens /admin/quote-print.php: a saved
 * quote by id, or (builder page) the selection POSTed for a live re-price.
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
    var canSave = root.getAttribute('data-can-save') === '1';
    var sid = +(root.getAttribute('data-submission-id') || 0);
    // selection key → saved option / in-flight save. Keyed, so A→B→A reuses A's option and a slow
    // earlier save can only ever fill in its OWN key.
    var savedMap = {}, savingMap = {};
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
          var sv = savedFor(s);
          status.textContent = sv ? 'Saved as Option ' + sv.option_no + '.' : '';
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
      }).join('') + (qt.fee_note ? '<p class="qb-fx">' + esc(qt.fee_note) + '</p>' : '')
        + (qt.fx_note ? '<p class="qb-fx">' + esc(qt.fx_note) + '</p>' : '') : '';
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

    // ── Suggest options: what each property can offer for the dates + party ──
    // The server picks (api/quote-builder.php action 'suggest' → the /search
    // function); "Use" only fills the rooms table, and the normal re-price below
    // stays the one source of the figures that get saved, inserted or printed.
    var sugBtn = q(root, '[data-qb-suggest]'), sugBox = q(root, '[data-qb-sugg]'), sugSeq = 0;
    function fmtDay(ymd) {
      var d = new Date(ymd + 'T12:00:00');
      return isNaN(d) ? ymd : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
    }
    function useOption(opt, vid, el) {
      qa(root, 'tr[data-room]').forEach(function (tr) {
        q(tr, '[data-qb-qty]').value = 0;
        q(tr, '[data-qb-guests]').value = 0;
      });
      opt.rooms.forEach(function (r) {
        var tr = q(root, 'tr[data-room="' + r.id + '"]');
        if (!tr) return;
        var qi = q(tr, '[data-qb-qty]'), max = int(qi.getAttribute('max'));
        qi.value = max ? Math.min(r.qty, max) : r.qty;
        q(tr, '[data-qb-guests]').value = r.guests;
      });
      var cb = q(root, '[data-qb-venue="' + vid + '"]'), g = q(root, 'tbody[data-qb-group="' + vid + '"]');
      if (cb) cb.checked = true;
      if (g) g.hidden = false;
      qa(sugBox, '.qb-sugg__opt').forEach(function (o) { o.classList.remove('is-used'); });
      el.classList.add('is-used');
      schedule();
    }
    function renderSuggestions(d, ci, co) {
      sugBox.innerHTML = '';
      var top = document.createElement('div');
      top.className = 'qb-sugg__top';
      top.innerHTML = '<span>Options for ' + esc(fmtDay(ci)) + ' – ' + esc(fmtDay(co)) + ' · ' + d.party + ' guest' + (d.party === 1 ? '' : 's') +
        '. <b>Use</b> loads one into the rooms below — edit it, then save it as an option.</span>' +
        '<button type="button" class="qb-sugg__close" aria-label="Close suggestions">×</button>';
      q(top, '.qb-sugg__close').addEventListener('click', function () { sugBox.hidden = true; });
      sugBox.appendChild(top);
      (d.groups || []).forEach(function (g) {
        var box = document.createElement('div');
        box.className = 'qb-sugg__venue';
        box.innerHTML = '<div class="qb-sugg__vname">' + esc(g.name) + (g.preferred ? '<small>requested</small>' : '') + '</div>';
        if (!g.options.length) {
          box.insertAdjacentHTML('beforeend', '<div class="qb-sugg__none">No availability for ' + d.party + ' guest' + (d.party === 1 ? '' : 's') + ' on these dates' +
            (g.max_capacity > 0 ? ' — it has room for ' + g.max_capacity + '.' : '.') + '</div>');
        }
        g.options.forEach(function (o) {
          var kind = o.kind === 'combo' ? 'Combination' : (o.kind === 'entire' ? 'Whole property' : 'Room');
          var el = document.createElement('div');
          el.className = 'qb-sugg__opt';
          el.innerHTML = '<div class="qb-sugg__main"><div class="qb-sugg__label">' + esc(o.label) + '</div>' +
            '<div class="qb-sugg__meta">' + kind + (o.sleeps ? ' · sleeps ' + o.sleeps : '') + ' · ' + d.nights + ' night' + (d.nights === 1 ? '' : 's') + '</div></div>' +
            (o.total !== null ? '<div class="qb-sugg__price">' + money({ amt: o.total, cur: o.currency }) + '</div>'
                              : '<div class="qb-sugg__price is-unpriced">No price set</div>') +
            '<button type="button" class="btn-primary btn-sm">Use</button>';
          q(el, 'button').addEventListener('click', function () { useOption(o, g.venue_id, el); });
          box.appendChild(el);
        });
        sugBox.appendChild(box);
      });
      sugBox.hidden = false;
      if (window.tsMoney) window.tsMoney.apply(sugBox);
    }
    if (sugBtn && sugBox) sugBtn.addEventListener('click', function () {
      var s = selection(), my = ++sugSeq, status = q(root, '[data-qb-status]');
      sugBtn.disabled = true;
      sugBox.hidden = false;
      sugBox.innerHTML = '<div class="qb-sugg__top"><span>Checking every property…</span></div>';
      fetch(root.getAttribute('data-endpoint'), {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf_token: root.getAttribute('data-csrf'), action: 'suggest',
          prefer_venue: +(root.getAttribute('data-prefer-venue') || 0),
          sel: { check_in: s.check_in, check_out: s.check_out, adults: s.adults, children: s.children }
        })
      })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (d) {
          if (my !== sugSeq) return;
          if (!d || !d.ok) {
            sugBox.innerHTML = '<div class="qb-sugg__top"><span>' + esc((d && d.error) || 'Could not load suggestions.') + '</span></div>';
            return;
          }
          renderSuggestions(d, s.check_in, s.check_out);
        })
        .catch(function () { if (my === sugSeq) sugBox.innerHTML = '<div class="qb-sugg__top"><span>Could not reach the server.</span></div>'; })
        .finally(function () { if (my === sugSeq) sugBtn.disabled = false; });
    });

    // ── Any input change re-prices ──────────────────────────────────────────
    root.addEventListener('input', function (e) { if (!e.target.closest('[data-qb-pick]') && !e.target.closest('[data-qb-venue]')) schedule(); });
    root.addEventListener('change', function (e) { if (!e.target.closest('[data-qb-pick]') && !e.target.closest('[data-qb-venue]')) schedule(); });

    // ── Save (enquiry pop-up only) ──────────────────────────────────────────
    function selKey(s) {
      var c = {};
      Object.keys(s).forEach(function (k) { if (k !== 'want_free') c[k] = s[k]; });
      return JSON.stringify(c);
    }
    function savedFor(s) { return savedMap[selKey(s)] || null; }
    function priced() { return !!last && (last.lines || []).length > 1; }
    // Resolves with the saved option ({id, option_no, ref, text, pdf_url, …}); rejects on failure
    // (the status line says why). An unchanged selection reuses the option already saved.
    function save() {
      var s = selection(), key = selKey(s), status = q(root, '[data-qb-status]');
      if (savedMap[key]) return Promise.resolve(savedMap[key]);
      if (savingMap[key]) return savingMap[key];
      status.textContent = 'Saving…';
      var p = fetch(root.getAttribute('data-endpoint'), {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: root.getAttribute('data-csrf'), action: 'save', submission_id: sid, sel: s })
      })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); },
              function () { return { ok: false, error: 'Could not reach the server. Nothing was saved.' }; })
        .then(function (d) {
          delete savingMap[key];
          var current = key === selKey(selection());          // only the selection now on screen owns the status line
          if (!d || !d.ok) {
            var msg = (d && d.error) || 'Could not save this quote.';
            if (current) status.textContent = msg;
            throw new Error(msg);
          }
          savedMap[key] = d.saved;
          if (current) status.textContent = 'Saved as Option ' + d.saved.option_no + '.';
          document.dispatchEvent(new CustomEvent('qb:saved', { detail: d.saved }));
          return d.saved;
        });
      savingMap[key] = p;
      return p;
    }
    var saveBtn = q(root, '[data-qb-save]');
    if (saveBtn) saveBtn.addEventListener('click', function () { save().catch(function () {}); });

    // ── Output ──────────────────────────────────────────────────────────────
    q(root, '[data-qb-copy]').addEventListener('click', function () {
      var b = this;
      if (!last) return;
      var sv = savedFor(selection());
      var text = sv ? sv.text : last.text;
      var done = function () { var o = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = o; }, 1400); };
      if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () {});
    });
    q(root, '[data-qb-print]').addEventListener('click', function () {
      if (!priced()) { q(root, '[data-qb-status]').textContent = 'Add rooms or extras with a price first.'; return; }
      if (canSave) {
        // Open the window now, inside the click (popup blockers), then point it at the saved quote.
        var w = window.open('', '_blank');
        if (w) { try { w.document.title = 'Quotation'; w.document.body.textContent = 'Preparing the quotation…'; } catch (e) {} }
        save().then(function (sv) {
          if (!w) {
            // Blocked: a second window.open after the await would be blocked too. The quote IS saved.
            q(root, '[data-qb-status]').textContent = 'Saved as Option ' + sv.option_no
              + ' — your browser blocked the new tab; open it from Quotes → View PDF.';
            return;
          }
          if (!w.closed) w.location.href = sv.pdf_url + '&print=1';   // print=1: open the print dialog
        }, function () { if (w) w.close(); });
        return;
      }
      // Builder page: POST the selection to a new tab; the print page re-prices it.
      var f = document.createElement('form');
      f.method = 'POST'; f.action = root.getAttribute('data-print-url'); f.target = '_blank'; f.style.display = 'none';
      [['csrf_token', root.getAttribute('data-csrf')], ['sel', JSON.stringify(selection())]].forEach(function (kv) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = kv[0]; i.value = kv[1];
        f.appendChild(i);
      });
      document.body.appendChild(f);
      f.submit();
      f.remove();
    });
    var ins = q(root, '[data-qb-insert]');
    if (ins) ins.addEventListener('click', function () {
      if (!last) return;
      var send = function (detail) { document.dispatchEvent(new CustomEvent('qb:insert', { detail: detail })); };
      if (!canSave) { send({ text: last.text }); return; }
      ins.disabled = true;
      save().then(function (sv) { send({ text: sv.text, option_no: sv.option_no, ref: sv.ref }); }, function () {})
        .then(function () { ins.disabled = false; });
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
