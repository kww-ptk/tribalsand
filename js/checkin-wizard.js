(function () {
  var form = document.getElementById('ciForm');
  if (!form) return;
  var steps = Array.prototype.slice.call(document.querySelectorAll('.ci-step'));
  if (!steps.length) return;
  var bar       = document.getElementById('ciBar');
  var intro     = document.getElementById('ciIntro');
  var stepsWrap = document.getElementById('ciSteps');
  var startBtn  = document.getElementById('ciStart');
  var editBtn   = document.getElementById('ciEdit');
  var cur = 0;

  function tok(name) { var el = form.querySelector('input[name=' + name + ']'); return el ? el.value : ''; }
  var CSRF = tok('csrf_token'), REF = tok('ref');

  function show(i) {
    cur = Math.max(0, Math.min(steps.length - 1, i));
    steps.forEach(function (s, idx) { s.hidden = idx !== cur; });
    if (bar) bar.style.width = Math.round(((cur + 1) / steps.length) * 100) + '%';
    window.scrollTo(0, 0);
  }
  function openSteps(i) { if (intro) intro.hidden = true; if (stepsWrap) stepsWrap.hidden = false; show(i || 0); }
  function backToStart() { if (stepsWrap) stepsWrap.hidden = true; if (intro) intro.hidden = false; window.scrollTo(0, 0); }
  if (startBtn) startBtn.addEventListener('click', function () { openSteps(0); });
  if (editBtn)  editBtn.addEventListener('click', function () { openSteps(0); });

  // Save the lead's main-form fields via AJAX, then continue.
  function saveThen(next) {
    var fd = new FormData(form);
    fd.set('do', 'save'); fd.set('ajax', '1');
    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function () { next(); }).catch(function () { next(); });
  }

  // Form-encoded POST with ref + csrf + extra fields.
  function apiPost(url, fields) {
    var body = 'ref=' + encodeURIComponent(REF) + '&csrf_token=' + encodeURIComponent(CSRF);
    for (var k in fields) body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(fields[k] == null ? '' : fields[k]);
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' });
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function updateAddBtn() {
    var btn = form.querySelector('.ci-addguest'); if (!btn) return;
    var need = parseInt(btn.getAttribute('data-need') || '1', 10);
    var have = form.querySelectorAll('.ci-guest[data-guest-id]').length + 1; // + lead
    btn.hidden = have >= need;
    btn.textContent = '+ Add adult (' + have + '/' + need + ')';
  }

  // Mirrors checkin_arrival_flag() in includes/checkin.php. Kept in step with it
  // by the same boundary rules: inside the window and anything unparseable are
  // both "no warning" — a false alarm is worse than none.
  function arrMins(t) {
    var m = /^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec((t || '').trim());
    if (!m) return null;
    var h = parseInt(m[1], 10), i = parseInt(m[2], 10);
    if (h > 23 || i > 59) return null;
    return h * 60 + i;
  }
  function arrFlag(at, from, to) {
    var a = arrMins(at), f = arrMins(from), t = arrMins(to);
    if (a === null || f === null || t === null) return '';
    if (a < f) return 'early';
    if (a > t) return 'late';
    return '';
  }

  // .ci-arrwarn is this codebase's first HIDDEN aria-live region, so there is no
  // local pattern to copy. `hidden` is display:none, which keeps the node out of
  // the accessibility tree entirely — while hidden it is not being monitored.
  // Unhiding it and writing its text in the SAME task is therefore a single
  // insertion as far as the a11y tree is concerned, and NVDA/JAWS/VoiceOver
  // typically register a region on insertion and announce only LATER mutations,
  // so the warning would never be spoken. Hidden → visible must be a two-step:
  // unhide, yield a frame, then write. requestAnimationFrame (not setTimeout) is
  // the yield because it is tied to the paint that actually inserts the box, and
  // because a background tab — where nothing is being announced anyway — simply
  // defers it rather than firing into a page nobody is on.
  //
  // Two cases skip the defer deliberately:
  //   • already visible → the region is already live, so a plain textContent
  //     mutation is exactly what screen readers do announce. Deferring here
  //     would only leave the previous (wrong) sentence on screen for a frame.
  //   • hiding → nothing to announce; clear the text so a later re-show never
  //     flashes a stale sentence before its own deferred write lands.
  //
  // Races: syncArrivalWarning() can fire many times in quick succession. Each
  // call takes the next value of a monotonic counter and closes over it; the
  // deferred write is a no-op unless that token is STILL the latest and the box
  // is STILL visible. So a rapid early → late → inside sequence cannot land a
  // stale string and cannot write into a box that has since been re-hidden. The
  // write always targets the EXISTING .ci-arrwarn__t node — the .ci-arrwarn
  // container is never replaced or re-inserted, which would tear out the live
  // region. (Cancelling the pending frame instead of counting does NOT work
  // unless the cancel is hoisted above both early returns, so it stays a count.)
  var arrSeq = 0;

  // Which time counts as "reaching us": the dedicated field in flight mode,
  // otherwise the time part of the shared arrival datetime.
  function syncArrivalWarning(sec) {
    var times = sec.querySelector('.ci-times'); if (!times) return;
    var box = sec.querySelector('.ci-arrwarn'); if (!box) return;
    var body = box.querySelector('.ci-arrwarn__t'); if (!body) return;
    var from = times.getAttribute('data-ci-from'), to = times.getAttribute('data-ci-to');
    var modeEl = sec.querySelector('.ci-f-mode:checked');
    var mode = modeEl ? modeEl.value : 'flight';
    var t = '';
    if (mode === 'flight') {
      var pa = sec.querySelector('.ci-f-patime');
      t = pa ? pa.value : '';
    } else {
      var at = sec.querySelector('[name="arrival_at"]');
      t = at && at.value ? at.value.split('T')[1] || '' : '';
    }
    var flag = arrFlag(t, from, to);

    var seq = ++arrSeq;

    if (flag === '') { box.hidden = true; body.textContent = ''; return; }

    var msg = flag === 'early'
      ? 'You’ve told us you’ll arrive at ' + t + ', before check-in opens at ' + from
        + '. Your room may still be occupied or being prepared, so it might not be ready when you get here.'
      : 'You’ll arrive at ' + t + ', after check-in closes at ' + to
        + '. Let us know so someone is there to meet you.';

    if (!box.hidden) { body.textContent = msg; return; }   // already live: mutate in place
    box.hidden = false;                                    // 1. unhide
    window.requestAnimationFrame(function () {             // 2. yield one frame
      if (seq !== arrSeq || box.hidden) return;            //    superseded, or re-hidden
      body.textContent = msg;                              // 3. …then write
    });
  }

  function fieldVal(sec, name) {
    var el = sec.querySelector('[name="' + name + '"]');
    return el ? String(el.value).trim() : '';
  }
  function clearErr(sec) { var box = sec.querySelector('.ci-err'); if (box) box.hidden = true; }
  function showErr(sec, items) {
    var box = sec.querySelector('.ci-err');
    if (!box) {
      box = document.createElement('div');
      box.className = 'ci-err';
      box.setAttribute('role', 'alert');
      sec.insertBefore(box, sec.querySelector('.ci-nav'));
    }
    box.textContent = 'Before you continue, please ' + items.join(', ') + '.';
    box.hidden = false;
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  // "Your details" is the consent gate: terms + typed name + a signature, plus
  // the passport fields when that step is configured as required. The wording
  // mirrors checkin_consent_missing() in includes/checkin.php.
  function validateStep(sec) {
    if (!sec) return true;
    clearErr(sec);
    if (sec.getAttribute('data-key') !== 'you') return true;
    var missing = [];
    var agree = sec.querySelector('.ci-agree');
    if (agree) {
      if (!agree.checked) missing.push('agree to the terms');
      if (fieldVal(sec, 'waiver_signed_name') === '') missing.push('type your full name');
      var wrap = sec.querySelector('.ci-signwrap');
      if (wrap && wrap.getAttribute('data-signed') !== '1') {
        var sig = document.getElementById('ciLeadSig');
        if (!sig || sig.value === '') missing.push('draw your signature');
      }
    }
    if (sec.hasAttribute('data-passport-required')) {
      if (fieldVal(sec, 'passport_name') === '')   missing.push('enter your passport name');
      if (fieldVal(sec, 'passport_number') === '') missing.push('enter your passport number');
      var up = sec.querySelector('.ci-upload');
      if (up && up.getAttribute('data-has') !== '1') missing.push('upload your passport scan');
    }
    if (!missing.length) return true;
    showErr(sec, missing);
    return false;
  }

  form.addEventListener('click', function (e) {
    var t = e.target;

    if (t.hasAttribute('data-resign')) {   // swap the stored-signature panel for a blank pad
      e.preventDefault();
      var wrap = t.closest('.ci-signwrap');
      wrap.setAttribute('data-signed', '0');
      var panel = wrap.querySelector('.ci-signed'); if (panel) panel.hidden = true;
      var pad   = wrap.querySelector('.ci-signpad'); if (pad) pad.hidden = false;
      // The canvas was already in the DOM (just hidden) so it is initialised;
      // this is idempotent and guards any future cloned markup.
      if (window.ciSignInitAll) window.ciSignInitAll();
      return;
    }

    if (t.classList.contains('ci-next')) {
      e.preventDefault();
      if (!validateStep(steps[cur])) return;
      saveThen(function () { show(cur + 1); });
      return;
    }
    if (t.classList.contains('ci-back')) { e.preventDefault(); if (cur === 0) backToStart(); else show(cur - 1); return; }

    if (t.classList.contains('ci-addguest')) {   // add adult → append a card in place; never reload
      e.preventDefault();
      var addBtn = t; addBtn.disabled = true; addBtn.textContent = 'Adding…';
      apiPost('/api/checkin-guest.php', { action: 'add_adult' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function (d) {
          var tpl = document.getElementById('ciGuestTpl');
          var card = tpl.content.firstElementChild.cloneNode(true);
          card.setAttribute('data-guest-id', d.guest_id);
          card.querySelector('.ci-kids').setAttribute('data-parent', d.guest_id);
          var link = card.querySelector('.ci-guest__link input');
          if (link) link.value = d.link || '';
          addBtn.parentNode.insertBefore(card, addBtn);
          addBtn.disabled = false;
          updateAddBtn();
          card.querySelector('.ci-guest__name').focus();
        })
        .catch(function () {   // tell the guest, then restore the real label — matches ci-guest__save
          addBtn.disabled = false;
          addBtn.textContent = 'Could not add — try again';
          setTimeout(updateAddBtn, 2000);
        });
      return;
    }
    if (t.classList.contains('ci-guest__remove')) {
      e.preventDefault();
      var card = t.closest('.ci-guest'), gid = card.getAttribute('data-guest-id');
      if (!gid || !confirm('Remove this guest from the booking?')) return;
      apiPost('/api/checkin-guest.php', { action: 'remove', guest_id: gid }).then(function () { card.remove(); updateAddBtn(); });
      return;
    }
    if (t.classList.contains('ci-guest__fill'))  { e.preventDefault(); var c = t.closest('.ci-guest'); c.querySelector('.ci-guest__inline').hidden = false; c.querySelector('.ci-guest__link').hidden = true; return; }
    if (t.classList.contains('ci-guest__share')) { e.preventDefault(); var c = t.closest('.ci-guest'); c.querySelector('.ci-guest__link').hidden = false; c.querySelector('.ci-guest__inline').hidden = true; return; }

    if (t.classList.contains('ci-copy')) {
      e.preventDefault();
      var inp = t.closest('.ci-linkrow').querySelector('input'); inp.select();
      try { navigator.clipboard.writeText(inp.value); } catch (_) { try { document.execCommand('copy'); } catch (__) {} }
      var o = t.textContent; t.textContent = 'Copied ✓'; setTimeout(function () { t.textContent = o; }, 1500);
      return;
    }

    if (t.classList.contains('ci-guest__save')) {   // save an additional adult's data (per-guest AJAX)
      e.preventDefault();
      var card = t.closest('.ci-guest'), gid = card.getAttribute('data-guest-id');
      var fd = new FormData();
      fd.append('ref', REF); fd.append('csrf_token', CSRF); fd.append('guest_id', gid); fd.append('ajax', '1');
      card.querySelectorAll('[data-field]').forEach(function (el) {
        var f = el.getAttribute('data-field');
        if (el.type === 'checkbox') { if (el.checked) fd.append(f, '1'); } else fd.append(f, el.value);
      });
      t.disabled = true; t.textContent = 'Saving…';
      fetch('/api/checkin-save.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function () { t.textContent = 'Saved ✓'; setTimeout(function () { t.textContent = 'Save this guest'; t.disabled = false; }, 1300); })
        .catch(function () { t.textContent = 'Try again'; t.disabled = false; });
      return;
    }

    if (t.classList.contains('ci-addkid')) {
      e.preventDefault();
      var wrap = t.closest('.ci-kids'), parent = wrap.getAttribute('data-parent');
      var name = (window.prompt("Child's full name:") || '').trim(); if (!name) return;
      apiPost('/api/checkin-guest.php', { action: 'add_child', parent_guest_id: parent, passport_name: name })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function (d) {
          var chip = document.createElement('span');
          chip.className = 'ci-kid'; chip.setAttribute('data-guest-id', d.guest_id);
          chip.innerHTML = esc(name) + '<button type="button" class="ci-kid__x" aria-label="Remove">&times;</button>';
          wrap.insertBefore(chip, t);
        });
      return;
    }
    if (t.classList.contains('ci-kid__x')) {
      e.preventDefault();
      var kc = t.closest('.ci-kid'), kid = kc.getAttribute('data-guest-id');
      apiPost('/api/checkin-guest.php', { action: 'remove', guest_id: kid }).then(function () { kc.remove(); });
      return;
    }
  });

  // Arrival: show only the chosen mode's fields, and reveal the free-text
  // airport box when "Other" is picked. First paint is server-rendered.
  form.addEventListener('change', function (e) {
    var t = e.target;
    if (t.classList.contains('ci-f-mode')) {
      var step = t.closest('.ci-step');
      step.querySelectorAll('.ci-mode-fields').forEach(function (g) {
        g.hidden = g.getAttribute('data-mode') !== t.value;
      });
      // The shared arrival field means different things per mode, so its label
      // swaps too — .ci-mode-fields above does not cover a bare <label>.
      step.querySelectorAll('[data-mode-label]').forEach(function (l) {
        l.hidden = (l.getAttribute('data-mode-label') === 'flight') !== (t.value === 'flight');
      });
      syncArrivalWarning(step);
      return;
    }
    if (t.classList.contains('ci-f-patime') || t.name === 'arrival_at') {
      syncArrivalWarning(t.closest('.ci-step'));
      return;
    }
    if (t.classList.contains('ci-f-airport')) {
      var box = t.closest('.ci-mode-fields').querySelector('.ci-airport-other');
      if (box) box.hidden = t.value !== '__other';
      return;
    }
  });

  // Delegated passport upload — any .ci-upload file input. guest_id from the enclosing card (absent → lead).
  form.addEventListener('change', function (e) {
    if (e.target.type !== 'file' || !e.target.closest('.ci-upload')) return;
    var input = e.target, card = input.closest('[data-guest-id]');
    var f = input.files && input.files[0]; if (!f) return;
    var wrap = input.closest('.ci-upload'), state = wrap.querySelector('.ci-upload__state');
    state.textContent = 'Uploading…';
    var fd = new FormData();
    fd.append('ref', REF); fd.append('csrf_token', CSRF);
    if (card) fd.append('guest_id', card.getAttribute('data-guest-id'));
    fd.append('passport', f);
    fetch('/api/checkin-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function () { state.innerHTML = 'Uploaded ✓'; wrap.setAttribute('data-has', '1'); })
      .catch(function () { state.textContent = 'Upload failed — try again'; });
  });

  // Server renders the correct initial state; this keeps it right if the browser
  // restored a value on back-navigation. It also fills .ci-arrwarn__t, which the
  // server deliberately leaves empty so the two can never word the warning
  // differently. Top-level, so every entry path (intro, edit, straight-in) gets it.
  var arrStep = form.querySelector('.ci-step[data-key="arrival"]');
  if (arrStep) syncArrivalWarning(arrStep);

  // Initial view: intro when there is one, else straight into the steps. The old
  // ?ci= resume parameter is gone with the reload that needed it.
  if (!intro && !editBtn) openSteps(0);

  // Final submit re-checks the consent step, so it cannot be skipped by jumping
  // straight to the last step. The server enforces the same rule regardless.
  form.addEventListener('submit', function (e) {
    var you = form.querySelector('.ci-step[data-key="you"]');
    if (you && !validateStep(you)) {
      e.preventDefault();
      var idx = steps.indexOf(you);
      if (idx >= 0) openSteps(idx);
    }
  });

})();
