/*
 * Guest portal — "Add to my stay" bottom sheet (design 1, Oct 2026).
 *
 * Any element with data-extra="<key>" opens the sheet for that extra (Home's
 * featured cards, the Extras list). The guest picks a day, a time of day and how
 * many people; the request goes to /api/booking-addon.php — the portal's one
 * request path, which re-validates everything (the activity is offered at this
 * property, the date is inside the stay, the transfer option exists) and prices
 * it from the catalogue. Nothing here decides a price that is stored.
 * ?extra=<key> in the URL (links in our emails) opens that extra on load.
 */
(function () {
  'use strict';
  var dataEl = document.getElementById('paExtrasData');
  var sheet = document.getElementById('paSheet');
  if (!dataEl || !sheet || sheet.dataset.bound) return;
  sheet.dataset.bound = '1';
  var data; try { data = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
  var items = {}; (data.items || []).forEach(function (x) { items[x.k] = x; });
  var $ = function (id) { return document.getElementById(id); };
  var cur = null, day = null, part = null, pax = 1, lastFocus = null;

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function money(n) { return data.cur + ' ' + Math.round(n).toLocaleString(); }
  function chip(group, value, label, on) {
    return '<button type="button" class="pa-chip' + (on ? ' is-active' : '') + '" data-' + group + '="' + esc(value) + '" aria-pressed="' + (on ? 'true' : 'false') + '">' + esc(label) + '</button>';
  }
  function total() {
    if (!cur || cur.price == null) return null;
    return cur.pp ? cur.price * pax : cur.price;
  }
  function refresh() {
    $('paSheetPax').textContent = pax;
    var t = total();
    $('paSheetGo').textContent = 'Add to my stay' + (t ? ' · ' + money(t) : '');
    $('paSheetGo').disabled = !day || !data.active;
  }

  function open(key) {
    var x = items[key];
    if (!x || !sheet.hidden) return;
    if (x.status) { toast(x.name + ' is already on your stay.', 'ok'); return; }
    cur = x; pax = x.kind === 'tour' ? Math.min(2, x.max) : 1; part = 'morning';
    day = data.days.length ? data.days[0].v : null;
    lastFocus = document.activeElement;
    var img = $('paSheetImg');
    img.style.backgroundImage = x.img ? "url('" + x.img.replace(/'/g, '%27') + "')" : '';
    img.className = 'pa-sheet__img pa-xthumb--' + (x.cat || 'any');
    $('paSheetTitle').textContent = x.name;
    $('paSheetPrice').textContent = x.pl;
    $('paSheetMeta').textContent = [x.group, x.dur].filter(Boolean).join(' · ');
    $('paSheetDesc').textContent = x.desc || '';
    $('paSheetDesc').hidden = !x.desc;
    $('paSheetDays').innerHTML = data.days.length
      ? data.days.map(function (d, i) { return chip('day', d.v, d.l, i === 0); }).join('')
      : '<span class="pa-sheet__fine">Your stay has ended.</span>';
    $('paSheetParts').innerHTML = Object.keys(data.parts).map(function (k) { return chip('part', k, data.parts[k], k === part); }).join('');
    $('paSheetPeopleRow').hidden = x.kind !== 'tour';
    $('paSheetNoteLabel').textContent = x.kind === 'transfer' ? 'Flight number or arrival time (optional)' : 'Notes (optional)';
    $('paSheetNote').value = '';
    $('paSheetErr').textContent = '';
    refresh();
    sheet.hidden = false;
    document.body.style.overflow = 'hidden';
    setTimeout(function () { var c = sheet.querySelector('.pa-sheet__x'); if (c) c.focus(); }, 30);
  }
  function close() {
    sheet.hidden = true;
    document.body.style.overflow = '';
    cur = null;
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  function toast(message, type) {
    var wrap = document.getElementById('ts-toasts');
    if (!wrap) { wrap = document.createElement('div'); wrap.id = 'ts-toasts'; document.body.appendChild(wrap); }
    var t = document.createElement('div');
    t.className = 'ts-toast ts-toast--' + (type === 'err' ? 'err' : 'ok');
    t.setAttribute('role', 'status');
    t.innerHTML = '<span class="ts-toast__icon" aria-hidden="true">' + (type === 'err' ? '✕' : '✓') + '</span><span class="ts-toast__msg"></span>';
    t.querySelector('.ts-toast__msg').textContent = message;
    wrap.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('is-in'); });
    setTimeout(function () { t.classList.remove('is-in'); t.classList.add('is-out'); setTimeout(function () { t.remove(); }, 260); }, 4500);
  }

  function markWaiting(key) {
    if (items[key]) items[key].status = 'requested';
    document.querySelectorAll('[data-extra-status="' + key + '"]').forEach(function (el) {
      el.innerHTML = '<span class="pa-pill pa-pill--pend">Waiting</span>';
    });
    document.querySelectorAll('.pa-xcard[data-extra="' + key + '"]').forEach(function (el) {
      var add = el.querySelector('.pa-xcard__add'); if (add) add.textContent = 'Added ✓';
    });
  }

  function send() {
    if (!cur || !day) return;
    var btn = $('paSheetGo'), err = $('paSheetErr');
    var note = $('paSheetNote').value.trim();
    var body = { ref: data.ref, kind: cur.kind, details: note };
    if (cur.kind === 'tour') {
      body.tour_slug = cur.slug; body.pax = pax; body.at_date = day; body.at_time = part;
    } else {
      body.transfer = cur.id;
      body.scheduled_for = day + ' ' + ({ morning: '09:00', afternoon: '14:00', evening: '18:00' }[part] || '09:00');
      if (data.parts[part]) body.details = (note ? note + ' · ' : '') + data.parts[part].toLowerCase();
    }
    btn.disabled = true; btn.textContent = 'Sending…'; err.textContent = '';
    fetch('/api/booking-addon.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (j) {
        if (j && j.ok) {
          var key = cur.k, name = cur.name;
          close();
          markWaiting(key);
          toast(name + ' added. We’ll confirm it shortly.', 'ok');
        } else {
          err.textContent = (j && j.error) || 'We couldn’t send that. Please try again.';
          refresh();
        }
      })
      .catch(function () { err.textContent = 'No connection. Please try again.'; refresh(); });
  }

  document.addEventListener('click', function (e) {
    var t = e.target; if (!t || !t.closest) return;
    var opener = t.closest('[data-extra]');
    if (opener && !opener.disabled) { e.preventDefault(); open(opener.getAttribute('data-extra')); return; }
    if (sheet.hidden) return;
    if (t.closest('[data-sheet-close]')) { close(); return; }
    var d = t.closest('[data-day]'), p = t.closest('[data-part]'), s = t.closest('[data-pax]');
    if (d) { day = d.getAttribute('data-day'); sheet.querySelectorAll('[data-day]').forEach(function (c) { c.classList.toggle('is-active', c === d); c.setAttribute('aria-pressed', c === d ? 'true' : 'false'); }); refresh(); }
    if (p) { part = p.getAttribute('data-part'); sheet.querySelectorAll('[data-part]').forEach(function (c) { c.classList.toggle('is-active', c === p); c.setAttribute('aria-pressed', c === p ? 'true' : 'false'); }); }
    if (s) { pax = Math.max(1, Math.min(cur.max || 8, pax + parseInt(s.getAttribute('data-pax'), 10))); refresh(); }
    if (t.closest('#paSheetGo')) send();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !sheet.hidden) close(); });

  // Category chips on the Extras list.
  var chips = document.getElementById('paXChips');
  if (chips) chips.addEventListener('click', function (e) {
    var c = e.target.closest('[data-xgroup]'); if (!c) return;
    var g = c.getAttribute('data-xgroup');
    chips.querySelectorAll('[data-xgroup]').forEach(function (x) { x.classList.toggle('is-active', x === c); x.setAttribute('aria-selected', x === c ? 'true' : 'false'); });
    document.querySelectorAll('[data-xitem-group]').forEach(function (it) { it.hidden = g !== '' && it.getAttribute('data-xitem-group') !== g; });
  });

  // Opened from an email link: ?extra=tour:12
  var want = new URLSearchParams(location.search).get('extra');
  if (want && items[want] && data.active) setTimeout(function () { open(want); }, 200);
})();
