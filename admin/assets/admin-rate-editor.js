/* Global rate editor — admin/rates.php, OWNER ONLY (markup: includes/rate-editor-view.php).
 *
 * One "Set rates" modal, three ways in:
 *   - the toolbar button [data-re-open]            → an empty form
 *   - a Rate-card cell  td[data-re-cell]           → that room (+ same-priced rooms at the
 *                                                     property), that season's nights, fixed price
 *   - a Timeline selection (click / shift-click / ctrl-or-cmd-click / drag on td[data-d])
 *                                                   → those rooms, those nights
 * Preview and Apply go to api/rate-editor.php (JSON, CSRF in the body). Every figure shown
 * in the preview is the server's; Apply recomputes on the server — nothing here prices.
 *
 * Emitted INLINE by includes/rate-editor-view.php (admin shell navigation re-runs inline
 * scripts, never a <script src> inside content), so this can run many times per window:
 * every listener is delegated from document and bound ONCE (window.__reBound), and looks
 * the page's editor up at event time.
 */
(function () {
  'use strict';

  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var DAY = 86400000;
  var FLASH_KEY = 're_flash';

  // ── Small helpers ──────────────────────────────────────────────────────────
  function root() { return document.querySelector('[data-re]'); }
  function q(sel, el) { el = el || root(); return el ? el.querySelector(sel) : null; }
  function qa(sel, el) { el = el || root(); return el ? Array.prototype.slice.call(el.querySelectorAll(sel)) : []; }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function parseYmd(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
    return m ? Date.UTC(+m[1], +m[2] - 1, +m[3]) : null;
  }
  function ymdOf(t) { var d = new Date(t); return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); }
  function fmt(ymd, year) {
    var d = new Date(parseYmd(ymd));
    return d.getUTCDate() + ' ' + MONTHS[d.getUTCMonth()] + (year ? ' ' + d.getUTCFullYear() : '');
  }
  function fmtRange(a, b) {
    if (a === b) return fmt(a, true);
    return a.slice(0, 4) === b.slice(0, 4) ? fmt(a) + ' – ' + fmt(b, true) : fmt(a, true) + ' – ' + fmt(b, true);
  }
  // Mirrors rc_season_class() (includes/rates-compare.php) for the preview pills.
  function seasonClass(label) {
    if (label == null || label === '') return 'base';
    var l = String(label).toLowerCase();
    if (l.indexOf('peak') >= 0 || l.indexOf('high') >= 0) return 'peak';
    if (l.indexOf('mid') >= 0) return 'mid';
    if (l.indexOf('standard') >= 0) return 'std';
    return 'other';
  }
  function pill(label) {
    return '<span class="rc-pill rc-' + seasonClass(label) + '">' + esc(label == null ? 'Base' : label) + '</span>';
  }
  // An amount in its own currency; admin-money.js (when on the page) repaints it in KES | USD.
  function money(amt, cur) {
    var txt = window.tsMoney ? window.tsMoney.text(amt, cur) : cur + ' ' + Math.round(amt).toLocaleString('en-US');
    return '<span class="mny" data-amt="' + esc(amt) + '" data-cur="' + esc(cur) + '">' + esc(txt) + '</span>';
  }
  function moneyRange(r, cur) {
    if (!r) return '—';
    return Math.abs(r.max - r.min) < 0.005 ? money(r.min, cur) : money(r.min, cur) + ' – ' + money(r.max, cur);
  }
  function toast(msg, type) {
    if (typeof window.tsToast === 'function') window.tsToast(msg, type);
  }
  function fine() { try { return window.matchMedia('(pointer:fine)').matches; } catch (e) { return true; } }

  // ── Modal ──────────────────────────────────────────────────────────────────
  function modal() { return q('[data-re-modal]'); }
  function isOpen() { var m = modal(); return !!(m && !m.hidden); }

  function openEditor(pre) {
    var r = root(), m = modal();
    if (!r || !m) return;
    resetForm(pre || {});
    setStep('form');
    showError('');
    m.hidden = false;
    document.body.style.overflow = 'hidden';
    m.scrollTop = 0;
    if (fine()) {
      var focus = (pre && pre.rooms && pre.rooms.length) ? q('[data-re-amount]') : q('[data-re-room]');
      if (focus && !focus.closest('[hidden]')) try { focus.focus({ preventScroll: true }); } catch (e) {}
    }
  }
  function closeEditor() {
    var m = modal();
    if (m) m.hidden = true;
    document.body.style.overflow = '';
  }
  function setStep(step) {
    qa('[data-re-step]').forEach(function (el) { el.hidden = el.getAttribute('data-re-step') !== step; });
    qa('[data-re-for-step]').forEach(function (el) { el.hidden = el.getAttribute('data-re-for-step') !== step; });
  }
  function showError(msg) {
    var el = q('[data-re-error]');
    if (!el) return;
    el.textContent = msg || '';
    el.hidden = !msg;
    if (msg) { var m = modal(); if (m && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' }); }
  }
  function busy(btn, on, text) {
    if (!btn) return;
    if (on) { btn.setAttribute('data-label', btn.innerHTML); btn.textContent = text; btn.disabled = true; }
    else { if (btn.hasAttribute('data-label')) btn.innerHTML = btn.getAttribute('data-label'); btn.disabled = false; }
  }

  // ── The form ───────────────────────────────────────────────────────────────
  var rangeSeq = 0;
  function addRange(first, last) {
    var box = q('[data-re-ranges]'), tpl = q('[data-re-range-tpl]');
    if (!box || !tpl) return;
    rangeSeq++;
    var host = document.createElement('div');
    // Always cloned from the <template>: a clone of a live row would carry data-dp-bound
    // and datepicker.js would never bind it.
    host.innerHTML = tpl.innerHTML.replace(/__PAIR__/g, 'rep' + rangeSeq).replace(/__ID__/g, 'rer' + rangeSeq);
    var row = host.firstElementChild;
    [[first, '[data-re-first]', 'ci'], [last && last !== first ? last : '', '[data-re-last]', 'co']].forEach(function (x) {
      if (!x[0]) return;
      row.querySelector(x[1]).value = x[0];
      var b = row.querySelector('.dp-btn[data-dp-role="' + x[2] + '"]');
      b.textContent = fmt(x[0], true);
      b.classList.add('dp-btn--active');
    });
    box.appendChild(row);
    if (typeof window.initDatepickers === 'function') window.initDatepickers();
  }

  function resetForm(pre) {
    var rooms = (pre.rooms || []).map(String);
    qa('[data-re-room]').forEach(function (c) { c.checked = rooms.indexOf(c.value) >= 0; });

    var box = q('[data-re-ranges]');
    if (box) box.innerHTML = '';
    (pre.ranges || []).forEach(function (r) { addRange(r[0], r[1]); });
    if (!pre.ranges || !pre.ranges.length) addRange('', '');

    var mode = pre.mode || 'fixed';
    qa('[data-re-mode]').forEach(function (i) { i.checked = i.value === mode; });
    var amt = q('[data-re-amount]');
    if (amt) amt.value = pre.amount != null && pre.amount !== '' ? pre.amount : '';
    var pct = q('[data-re-pct]');
    if (pct) pct.value = '';
    var match = q('[data-re-match]');
    if (match && pre.match_label) { match.value = pre.match_label; match.dispatchEvent(new Event('change', { bubbles: true })); }

    var label = q('[data-re-label]'), keep = q('[data-re-keep]');
    var hasLabel = typeof pre.label === 'string';
    if (keep) keep.checked = !hasLabel;
    if (label) label.value = hasLabel ? pre.label : '';
    var bo = q('[data-re-buyouts]');
    if (bo) bo.checked = true;

    var ctx = q('[data-re-context]');
    if (ctx) { ctx.textContent = pre.context || ''; ctx.hidden = !pre.context; }
    sync();
  }

  function checkedRooms() { return qa('[data-re-room]').filter(function (c) { return c.checked; }); }
  function mode() { var m = qa('[data-re-mode]').filter(function (i) { return i.checked; })[0]; return m ? m.value : 'fixed'; }
  function num(v) { v = String(v == null ? '' : v).replace(/[,\s]/g, ''); return v === '' || isNaN(+v) ? null : +v; }

  function readRanges() {
    var out = [], bad = false;
    qa('[data-re-range]').forEach(function (row) {
      var f = row.querySelector('[data-re-first]').value, l = row.querySelector('[data-re-last]').value;
      if (!f && !l) return;
      if (!f) { bad = true; return; }
      out.push([f, l && l >= f ? l : f]);        // no last night = that one night
    });
    return { ranges: out, bad: bad };
  }

  function buyoutVisible() {
    var picked = checkedRooms().map(function (c) { return c.value; });
    return qa('[data-re-vgroup]').some(function (g) {
      var b = g.getAttribute('data-buyout');
      if (!b || b === '0' || picked.indexOf(b) >= 0) return false;
      return qa('[data-re-room]', g).some(function (c) { return c.checked && c.value !== b; });
    });
  }

  function readForm() {
    var rooms = checkedRooms().map(function (c) { return +c.value; });
    if (!rooms.length) return { error: 'Choose at least one room.' };
    var rr = readRanges();
    if (rr.bad) return { error: 'Each date range needs a first night.' };
    if (!rr.ranges.length) return { error: 'Add the nights to change.' };
    var m = mode();
    var body = { rooms: rooms, ranges: rr.ranges, mode: m, update_buyouts: buyoutVisible() && !!(q('[data-re-buyouts]') || {}).checked };
    var label = (q('[data-re-label]') || {}).value || '';
    var keep = !!(q('[data-re-keep]') || {}).checked;
    if (m === 'fixed') body.amount = num((q('[data-re-amount]') || {}).value);
    if (m === 'percent') body.pct = num((q('[data-re-pct]') || {}).value);
    if (m === 'match') {
      var sel = q('[data-re-match]');
      if (!sel || !sel.value) return { error: 'There is no season to match yet.' };
      body.match_label = sel.value;
      if (label.trim()) body.label = label.trim();        // blank = the matched season's own label
    } else if (m !== 'base' && !keep) {
      body.label = label.trim();                          // '' = no label
    }
    return { body: body };
  }

  function sync() {
    var r = root();
    if (!r) return;
    // Property chips: on when every room is ticked, marked when only some are.
    qa('[data-re-vgroup]').forEach(function (g) {
      var boxes = qa('[data-re-room]', g), n = boxes.filter(function (c) { return c.checked; }).length;
      var chip = g.querySelector('[data-re-venue]');
      if (!chip) return;
      chip.checked = n > 0 && n === boxes.length;
      chip.closest('.optchip').classList.toggle('is-part', n > 0 && n < boxes.length);
    });
    var sel = checkedRooms();
    var count = q('[data-re-count]');
    if (count) count.textContent = !sel.length ? 'No rooms selected'
      : sel.length === 1 ? '1 room: ' + sel[0].getAttribute('data-name') : sel.length + ' rooms selected';

    var curs = [];
    sel.forEach(function (c) { var k = c.getAttribute('data-cur'); if (curs.indexOf(k) < 0) curs.push(k); });
    curs.sort();
    var m = mode();
    var curEl = q('[data-re-cur]');
    if (curEl) curEl.textContent = curs.length === 1 ? curs[0] : (curs.length ? curs.join(' / ') : '—');
    var warn = q('[data-re-curwarn]');
    if (warn) {
      var mixed = m === 'fixed' && curs.length > 1;
      warn.hidden = !mixed;
      if (mixed) warn.textContent = 'These rooms price in ' + curs.join(' and ') + '. A fixed price needs one currency — make one change per currency, or use “Change by %”.';
    }

    qa('[data-re-for]').forEach(function (el) { el.hidden = el.getAttribute('data-re-for') !== m; });
    var lr = q('[data-re-labelrow]'); if (lr) lr.hidden = m === 'base';
    var kw = q('[data-re-keepwrap]'); if (kw) kw.hidden = m === 'match';
    var label = q('[data-re-label]'), keep = q('[data-re-keep]');
    if (label) {
      label.disabled = m !== 'match' && !!(keep && keep.checked);
      label.placeholder = m === 'match' ? 'Same as the matched season' : (label.disabled ? 'Each night keeps its label' : 'No label');
    }
    var bw = q('[data-re-buyoutwrap]'); if (bw) bw.hidden = !buyoutVisible();

    // Nights (unique, inclusive ranges).
    var rr = readRanges(), seen = {}, n = 0;
    rr.ranges.forEach(function (x) {
      for (var t = parseYmd(x[0]), e = parseYmd(x[1]); t !== null && e !== null && t <= e && n < 5000; t += DAY) {
        var k = ymdOf(t); if (!seen[k]) { seen[k] = 1; n++; }
      }
    });
    var ne = q('[data-re-nights]'); if (ne) ne.textContent = n ? plural(n, 'night') : '';
    var add = q('[data-re-add-range]');
    if (add) add.disabled = qa('[data-re-range]').length >= (+r.getAttribute('data-max-ranges') || 50);
  }

  // ── Server calls ───────────────────────────────────────────────────────────
  function api(action, body) {
    var r = root();
    var payload = {};
    Object.keys(body || {}).forEach(function (k) { payload[k] = body[k]; });
    payload.action = action;
    payload.csrf_token = r ? r.getAttribute('data-csrf') : '';
    return fetch(r ? r.getAttribute('data-endpoint') : '/api/rate-editor.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.json().then(function (d) {
        if (!d || !d.ok) {
          var err = new Error((d && d.error) || 'Could not complete that. Try again.');
          err.status = res.status;
          throw err;
        }
        return d;
      }, function () { throw new Error('Unexpected response from the server (' + res.status + '). Try again.'); });
    }, function () { throw new Error('Could not reach the server. Check your connection and try again.'); });
  }

  var lastBody = null;
  function doPreview() {
    var f = readForm();
    if (f.error) { showError(f.error); return; }
    showError('');
    var btn = q('[data-re-preview-btn]');
    busy(btn, true, 'Checking…');
    api('preview', f.body).then(function (d) {
      busy(btn, false);
      lastBody = f.body;
      // Apply sends back exactly what was previewed: the same body plus the preview's
      // fingerprint (when the server issues one), so a double submit or a rate changed
      // since the preview is refused instead of applied twice.
      if (d.preview && d.preview.fingerprint) lastBody.fingerprint = d.preview.fingerprint;
      renderPreview(d.preview);
      setStep('preview');
      var m = modal(); if (m) m.scrollTop = 0;
    }, function (e) { busy(btn, false); showError(e.message); });
  }

  function renderPreview(p) {
    var el = q('[data-re-preview]');
    if (!el) return;
    var t = p.totals || {};
    var tot = [t.rooms ? plural(t.rooms, 'room') + ' · ' + plural(t.nights, 'night') + ' change' : 'Nothing changes'];
    if (t.unchanged) tot.push(t.unchanged + ' already set');
    if (t.skipped) tot.push(t.skipped + ' skipped');
    var h = '<p class="re-pv__sum">' + esc(p.summary) + '</p><p class="re-pv__tot">' + esc(tot.join(' · ')) + '</p>';
    if (!p.log_supported) h += '<p class="re-pv__note">This change will not be logged and cannot be undone here — run add_rate_change_log.sql to enable the change log.</p>';

    h += '<div class="re-pv__rows">';
    (p.rooms || []).forEach(function (row) {
      var meta = [row.venue_name || 'No property'];
      if (!row.is_published) meta.push('hidden');
      h += '<div class="re-pv__row is-' + esc(row.status) + '">'
        + '<div class="re-pv__room"><strong>' + esc(row.name) + '</strong>'
        + (row.is_buyout ? ' <span class="re-pill re-pill--buyout">Buyout</span>' : '')
        + '<small>' + esc(meta.join(' · ')) + '</small></div>';
      if (row.status === 'change') {
        h += '<div class="re-pv__n">' + esc(plural(row.nights, 'night')) + '</div>'
          + '<div class="re-pv__money">' + moneyRange(row.before, row.currency) + '<span class="re-pv__arrow">→</span>'
          + '<span class="re-pv__after">' + (row.to_base ? 'base ' : '') + moneyRange(row.after, row.currency) + '</span></div>';
      } else {
        h += '<div class="re-pv__n"></div><div class="re-pv__money">'
          + esc(row.status === 'skipped' ? 'Skipped' : 'Already set — no change') + '</div>';
      }
      var extra = [];
      if (row.status === 'change') {
        var lb = (row.labels_before || []).map(pill).join(''), la = (row.labels_after || []).map(pill).join('');
        if (lb !== la) extra.push('<div class="re-pv__labels">' + lb + '<span class="re-pv__arrow">→</span>' + la + '</div>');
        if (row.runs && row.runs.length > 1) {
          var runs = row.runs.slice(0, 6).map(function (x) {
            return '<li>' + esc(fmtRange(x.first, x.last)) + ': ' + (x.base ? 'base ' : '') + money(x.price, row.currency)
              + (x.label ? ' · ' + esc(x.label) : '') + '</li>';
          }).join('');
          if (row.runs.length > 6) runs += '<li>+ ' + (row.runs.length - 6) + ' more</li>';
          extra.push('<ul class="re-pv__runs">' + runs + '</ul>');
        }
      }
      if (row.skipped_reason) extra.push('<div>' + esc(row.skipped_reason) + '</div>');
      (row.notes || []).forEach(function (n) { extra.push('<div>' + esc(n) + '</div>'); });
      if (extra.length) h += '<div class="re-pv__extra">' + extra.join('') + '</div>';
      h += '</div>';
    });
    h += '</div>';

    var bl = [];
    (p.buyouts || []).forEach(function (b) {
      var who = b.name + (b.venue_name ? ' (' + b.venue_name + ')' : '');
      if (!b.applied) bl.push('Buyout ' + who + ' is not updated — tick “Also update buyouts” to keep it equal to the sum of its rooms.');
      (b.left_out || []).forEach(function (x) { bl.push('Left out of the ' + who + ' sum: ' + x.name + ' — ' + x.reason + '.'); });
    });
    if (bl.length) h += '<ul class="re-pv__buyouts">' + bl.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>';

    el.innerHTML = h;
    if (window.tsMoney) window.tsMoney.apply(el);
    var ap = q('[data-re-apply]');
    if (ap) { ap.disabled = !t.rooms; ap.textContent = t.rooms ? 'Confirm & save' : 'Nothing to change'; }
  }

  function doApply() {
    if (!lastBody) { setStep('form'); return; }
    var btn = q('[data-re-apply]');
    showError('');
    busy(btn, true, 'Saving…');
    api('apply', lastBody).then(function (d) {
      var a = d.applied || {};
      flashAndReload('Saved: ' + (a.summary || 'rates updated') + (a.log_note ? ' — ' + a.log_note : ''));
    }, function (e) {
      busy(btn, false);
      // 409 = the rates changed since this preview: back to the form to preview again.
      if (e.status === 409) { lastBody = null; setStep('form'); }
      showError(e.message);
    });
  }

  function doUndo(btn) {
    var id = +btn.getAttribute('data-re-undo');
    var summary = btn.getAttribute('data-summary') || '';
    function go() {
      busy(btn, true, 'Undoing…');
      api('undo', { change_id: id }).then(function (d) {
        flashAndReload('Undone: ' + ((d.undone && d.undone.summary) || summary));
      }, function (e) { busy(btn, false); toast(e.message, 'err'); if (typeof window.tsToast !== 'function') window.alert(e.message); });
    }
    var msg = 'Undo “' + summary + '”? These rooms’ rates go back to how they were before this change.';
    if (typeof window.tsConfirm === 'function') window.tsConfirm(msg, go, { title: 'Undo rate change', label: 'Undo' });
    else if (window.confirm(msg)) go();
  }

  // The page reloads so every figure is the resolver's again; the message survives it.
  // The URL keeps ?cur (admin-money.js writes it), so the currency stays.
  function flashAndReload(msg) {
    try { sessionStorage.setItem(FLASH_KEY, msg); } catch (e) {}
    location.reload();
  }
  function showFlash() {
    var msg = null;
    try { msg = sessionStorage.getItem(FLASH_KEY); sessionStorage.removeItem(FLASH_KEY); } catch (e) {}
    if (msg) toast(msg, 'ok');
  }

  // ── Rate card: click a price cell ──────────────────────────────────────────
  function cardPrefill(cell) {
    var room = cell.getAttribute('data-room'), venue = cell.getAttribute('data-venue');
    var key = cell.getAttribute('data-key'), price = cell.getAttribute('data-price') || '';
    var cur = cell.getAttribute('data-cur'), year = cell.getAttribute('data-year');
    var name = cell.getAttribute('data-name') || 'this room';
    var ranges = [];
    try { ranges = JSON.parse(cell.getAttribute('data-ranges') || '[]'); } catch (e) {}
    var pre = { rooms: [room], ranges: ranges, mode: 'fixed', amount: price };

    if (cell.getAttribute('data-base') === '1') {
      pre.context = name + ' · the nights on its base price in ' + year
        + '. A fixed price here adds a rate on those nights; the base price itself is set on the room page.';
      return pre;
    }
    // "Other rate" (an unlabelled override) keeps its no-label; a season is written by name.
    if (cell.getAttribute('data-other') !== '1') pre.label = key;
    var others = [];
    if (price && cell.getAttribute('data-uniform') === '1') {
      qa('[data-re-cell]', cell.closest('table')).forEach(function (c) {
        if (c === cell || c.getAttribute('data-base') === '1') return;
        if (c.getAttribute('data-venue') === venue && c.getAttribute('data-key') === key
            && c.getAttribute('data-uniform') === '1' && c.getAttribute('data-price') === price
            && c.getAttribute('data-cur') === cur && others.indexOf(c.getAttribute('data-room')) < 0) {
          others.push(c.getAttribute('data-room'));
        }
      });
    }
    pre.rooms = pre.rooms.concat(others);
    if (!price) {
      pre.context = name + ' has no ' + key + ' price in ' + year + ' — the dates are the property’s ' + key + ' nights.';
    } else {
      pre.context = key + ' ' + year + ' · ' + name
        + (others.length ? ' + ' + plural(others.length, 'other room') + ' at the same price' : '')
        + (cell.getAttribute('data-uniform') === '1' ? '' : ' · its price varies, the most common one is filled in')
        + '. Dates are ' + name + '’s ' + key + ' nights.';
    }
    return pre;
  }

  // ── Timeline: click, shift-click, ctrl/cmd-click, drag ─────────────────────
  // sel = {table, rows: [room id…], anchorRow, anchor, from, to}; dates are Y-m-d strings.
  var sel = null, drag = null;
  function tlRows(table) { return Array.prototype.slice.call(table.querySelectorAll('tr[data-re-row]')); }
  function paintSel() {
    document.querySelectorAll('[data-re-tl] td.is-sel').forEach(function (td) { td.classList.remove('is-sel'); });
    var bar = q('[data-re-selbar]');
    if (!sel || !document.contains(sel.table)) { sel = null; if (bar) bar.hidden = true; return; }
    tlRows(sel.table).forEach(function (tr) {
      if (sel.rows.indexOf(tr.getAttribute('data-room')) < 0) return;
      tr.querySelectorAll('td[data-d]').forEach(function (td) {
        var d = td.getAttribute('data-d');
        if (d >= sel.from && d <= sel.to) td.classList.add('is-sel');
      });
    });
    if (!bar) return;
    var n = Math.round((parseYmd(sel.to) - parseYmd(sel.from)) / DAY) + 1;
    bar.querySelector('[data-re-selgo]').textContent = 'Set rates for ' + plural(sel.rows.length, 'room') + ' · '
      + (sel.from === sel.to ? fmt(sel.from) : fmt(sel.from) + ' – ' + fmt(sel.to)) + ' (' + plural(n, 'night') + ')';
    bar.hidden = false;
  }
  function clearSel() { sel = null; drag = null; paintSel(); }
  function tlPrefill() {
    if (!sel) return null;
    // Fill the price when every selected cell carries the same one (same currency).
    var prices = {};
    sel.table.querySelectorAll('td.is-sel .mny[data-amt]').forEach(function (m) {
      prices[m.getAttribute('data-cur') + ' ' + m.getAttribute('data-amt')] = m.getAttribute('data-amt');
    });
    var keys = Object.keys(prices);
    var names = sel.rows.map(function (id) {
      var tr = sel.table.querySelector('tr[data-re-row][data-room="' + id + '"]');
      return tr ? tr.getAttribute('data-name') : '';
    }).filter(Boolean);
    return {
      rooms: sel.rows.slice(), ranges: [[sel.from, sel.to]], mode: 'fixed',
      amount: keys.length === 1 ? prices[keys[0]] : '',
      context: 'From the timeline: ' + (names.length <= 3 ? names.join(', ') : plural(names.length, 'room')) + ' · ' + fmtRange(sel.from, sel.to) + '.'
    };
  }
  function onTlDown(e) {
    var td = e.target.closest ? e.target.closest('[data-re-tl] td[data-d]') : null;
    if (!td || e.button !== 0) return;
    var tr = td.closest('tr[data-re-row]'), table = td.closest('[data-re-tl]');
    if (!tr) return;
    e.preventDefault();                               // no text selection while dragging
    var id = tr.getAttribute('data-room'), d = td.getAttribute('data-d');
    var same = sel && sel.table === table;
    if (same && e.shiftKey) {
      sel.from = d < sel.anchor ? d : sel.anchor;
      sel.to = d < sel.anchor ? sel.anchor : d;
      if (sel.rows.indexOf(id) < 0) sel.rows.push(id);
    } else if (same && (e.ctrlKey || e.metaKey)) {
      var i = sel.rows.indexOf(id);
      if (i >= 0) sel.rows.splice(i, 1); else sel.rows.push(id);
      if (!sel.rows.length) sel = null;
    } else {
      sel = { table: table, rows: [id], anchorRow: id, anchor: d, from: d, to: d };
      drag = { table: table, rowIdx: tlRows(table).indexOf(tr) };
    }
    paintSel();
  }
  function onTlOver(e) {
    if (!drag || !sel) return;
    var td = e.target.closest ? e.target.closest('[data-re-tl] td[data-d]') : null;
    if (!td || td.closest('[data-re-tl]') !== drag.table) return;
    var tr = td.closest('tr[data-re-row]');
    if (!tr) return;
    var rows = tlRows(drag.table), i = rows.indexOf(tr), a = Math.min(i, drag.rowIdx), b = Math.max(i, drag.rowIdx);
    var d = td.getAttribute('data-d');
    sel.from = d < sel.anchor ? d : sel.anchor;
    sel.to = d < sel.anchor ? sel.anchor : d;
    sel.rows = rows.slice(a, b + 1).map(function (r) { return r.getAttribute('data-room'); });
    paintSel();
  }

  // ── Wiring (once per window) ───────────────────────────────────────────────
  function datepickerOpen() { var p = document.querySelector('.dp-pop'); return !!(p && !p.hidden); }

  if (!window.__reBound) {
    window.__reBound = true;

    document.addEventListener('click', function (e) {
      var t = e.target;
      if (!t.closest || !root()) return;
      if (t.closest('[data-re-open]')) { e.preventDefault(); openEditor({}); return; }
      var cell = t.closest('[data-re-cell]');
      if (cell && root()) { openEditor(cardPrefill(cell)); return; }
      if (t.closest('[data-re-selgo]')) { var pre = tlPrefill(); if (pre) openEditor(pre); return; }
      if (t.closest('[data-re-selclear]')) { clearSel(); return; }
      var undo = t.closest('[data-re-undo]');
      if (undo && !undo.disabled) { doUndo(undo); return; }
      if (!t.closest('[data-re-modal]')) return;

      if (t.closest('[data-re-close]')) { closeEditor(); return; }
      if (t.closest('[data-re-preview-btn]')) { doPreview(); return; }
      if (t.closest('[data-re-back]')) { setStep('form'); showError(''); return; }
      if (t.closest('[data-re-apply]')) { doApply(); return; }
      if (t.closest('[data-re-add-range]')) { addRange('', ''); sync(); return; }
      var rm = t.closest('[data-re-rm-range]');
      if (rm) {
        rm.closest('[data-re-range]').remove();
        if (!qa('[data-re-range]').length) addRange('', '');
        sync();
        return;
      }
      if (t.closest('[data-re-clear-rooms]')) {
        qa('[data-re-room]').forEach(function (c) { c.checked = false; });
        sync();
      }
    });

    // The range datepicker fires no change event and stops its clicks from bubbling —
    // watch clicks in the capture phase and re-read the ranges right after.
    document.addEventListener('click', function () { if (isOpen()) setTimeout(sync, 0); }, true);

    document.addEventListener('change', function (e) {
      var t = e.target;
      if (!t.closest || !t.closest('[data-re-modal]')) return;
      if (t.hasAttribute('data-re-venue')) {
        var g = t.closest('[data-re-vgroup]'), boxes = qa('[data-re-room]', g);
        var all = boxes.every(function (c) { return c.checked; });
        boxes.forEach(function (c) { c.checked = !all; });
      }
      if (t.hasAttribute('data-re-mode') && t.value === 'percent') {
        var keep = q('[data-re-keep]'); if (keep) keep.checked = true;   // % keeps each night's label by default
      }
      sync();
    });

    document.addEventListener('keydown', function (e) {
      var cell = e.target.closest ? e.target.closest('[data-re-cell]') : null;
      if (cell && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openEditor(cardPrefill(cell)); return; }
      if (e.key === 'Enter' && e.target.matches && e.target.matches('[data-re-amount],[data-re-pct]') && isOpen()) {
        var form = q('[data-re-step="form"]');
        if (form && !form.hidden) { e.preventDefault(); doPreview(); }
      }
    });
    // Capture phase: sees the datepicker popup / confirm dialog still open (their own
    // Escape handlers close them), so Escape closes only the topmost thing.
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || datepickerOpen() || document.querySelector('.adm-confirm-back')) return;
      if (isOpen()) { closeEditor(); return; }
      if (sel) clearSel();
    }, true);

    document.addEventListener('mousedown', onTlDown);
    document.addEventListener('mouseover', onTlOver);
    document.addEventListener('mouseup', function () { drag = null; });

    // Back/Forward swaps the page without a reload; never leave the body scroll-locked.
    window.addEventListener('popstate', function () { document.body.style.overflow = ''; sel = null; drag = null; });
  }

  // Per run (page load or shell navigation). The listeners above belong to the FIRST
  // run's closure; a stale Timeline selection is dropped by paintSel() once its table
  // has left the document.
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', showFlash);
  else setTimeout(showFlash, 0);
})();
