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
  // Where the guest left off, computed server-side from what is already stored.
  // "Continue check-in" resumes there; "Update my details" is a deliberate
  // review of everything, so it still starts from the top.
  var resumeIdx = parseInt((stepsWrap && stepsWrap.getAttribute('data-resume')) || '0', 10);
  if (!(resumeIdx >= 0 && resumeIdx < steps.length)) resumeIdx = 0;

  if (startBtn) startBtn.addEventListener('click', function () { openSteps(resumeIdx); });
  if (editBtn)  editBtn.addEventListener('click', function () { openSteps(0); });

  // Save the lead's main-form fields via AJAX, then continue.
  // A 422 is the server refusing the consent step (checkin_consent_missing) —
  // show its sentence on the current step and stay. Any other failure still
  // continues, so a save problem can never trap the guest.
  function saveThen(next) {
    var fd = new FormData(form);
    fd.set('do', 'save'); fd.set('ajax', '1');
    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        if (r.status !== 422) { next(); return; }
        return r.json().then(function (d) { showMsg(steps[cur], (d && d.error) || 'Please check this step.'); }, next);
      })
      .catch(function () { next(); });
  }

  // Leaving the wizard mid-step — "Message the team" — must not lose what is
  // typed on the current step: the form only autosaves on Save & continue.
  // Save first, then follow the link. saveThen() invokes its callback even when
  // the request fails, so a save problem can never trap the guest on this page.
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[data-ci-leave]') : null;
    if (!a || !a.href) return;
    e.preventDefault();
    var href = a.href;
    saveThen(function () { window.location.href = href; });
  });

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

  // Which time counts as "reaching us": the desired check-in time, asked of
  // every mode. A flight's landing time is never checked against the window.
  function syncArrivalWarning(sec) {
    var times = sec.querySelector('.ci-times'); if (!times) return;
    var box = sec.querySelector('.ci-arrwarn'); if (!box) return;
    var body = box.querySelector('.ci-arrwarn__t'); if (!body) return;
    var from = times.getAttribute('data-ci-from'), to = times.getAttribute('data-ci-to');
    // One field for every mode. The server prefills it via checkin_desired_time(),
    // including the legacy road/other fallback, so this reads exactly the string
    // the server flagged on — no mode branch, and no way for the two to disagree.
    var pa = sec.querySelector('.ci-f-patime');
    var t = pa ? pa.value : '';
    var flag = arrFlag(t, from, to);

    var seq = ++arrSeq;

    if (flag === '') { box.hidden = true; body.textContent = ''; return; }

    var msg = flag === 'early'
      ? 'You’ve asked to check in at ' + t + ', before check-in opens at ' + from
        + '. Your room may still be occupied or being prepared, so it might not be ready that early.'
      : 'You’ve asked to check in at ' + t + ', after check-in closes at ' + to
        + '. Let us know so someone is there to meet you.';

    if (!box.hidden) { body.textContent = msg; return; }   // already live: mutate in place
    box.hidden = false;                                    // 1. unhide
    window.requestAnimationFrame(function () {             // 2. yield one frame
      if (seq !== arrSeq || box.hidden) return;            //    superseded, or re-hidden
      body.textContent = msg;                              // 3. …then write
    });
  }

  // The arrival-transfer block depends on TWO answers that live on different
  // steps: "do you want a transfer" (transfer step) and "how will you arrive"
  // (arrival step). The mode radios stay the single source of truth — this
  // re-reads them rather than copying the value into a hidden field, so the two
  // steps cannot drift apart.
  function syncTransferFields(root) {
    var sec = root.querySelector('.ci-step[data-key="transfer"]');
    if (!sec) return;
    var inEl = sec.querySelector('.ci-f-tin:checked');
    var wantsIn = !!inEl && inEl.value === '1';
    var modeEl = root.querySelector('.ci-f-mode:checked');
    // The radios own the answer whenever the arrival step is rendered. When it
    // is switched off in checkin_steps they do not exist, and the stored mode
    // reaches the page only as data-arrival-mode, which the server stamps from
    // checkin_effective_mode() — same 'unset reads as flight' rule.
    var mode = modeEl ? modeEl.value : (sec.getAttribute('data-arrival-mode') || 'flight');
    var isFlight = mode === 'flight';

    sec.querySelectorAll('.ci-tin-fields').forEach(function (g) {
      var wantFlight = g.getAttribute('data-tmode') === 'flight';
      g.hidden = !wantsIn || (wantFlight !== isFlight);
    });

    var outEl = sec.querySelector('.ci-f-tout:checked');
    var outBox = sec.querySelector('.ci-tout-fields');
    if (outBox) outBox.hidden = !outEl || outEl.value !== '1';
  }

  function fieldVal(sec, name) {
    var el = sec.querySelector('[name="' + name + '"]');
    return el ? String(el.value).trim() : '';
  }
  function clearErr(sec) { var box = sec.querySelector('.ci-err'); if (box) box.hidden = true; }
  function showMsg(sec, text) {
    var box = sec.querySelector('.ci-err');
    if (!box) {
      box = document.createElement('div');
      box.className = 'ci-err';
      box.setAttribute('role', 'alert');
      sec.insertBefore(box, sec.querySelector('.ci-nav'));
    }
    box.textContent = text;
    box.hidden = false;
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  function showErr(sec, items) { showMsg(sec, 'Before you continue, please ' + items.join(', ') + '.'); }

  // ── Deposit: Complete check-in appears once the card is dealt with ─────────
  // data-deposit-gate (set when the "I can't upload" choice exists): the card
  // photo is on file OR a way to pay at arrival is ticked. Mirrors
  // checkin_deposit_handled() / checkin_submit_missing() on the server.
  function depositHandled(sec) {
    var up = sec.querySelector('.ci-upload[data-kind="deposit"]');
    return (up && up.getAttribute('data-has') === '1') || !!sec.querySelector('.ci-f-plan:checked');
  }
  function syncDepositGate() {
    var sec = form.querySelector('.ci-step[data-key="deposit"]');
    if (!sec || !sec.querySelector('[data-deposit-gate]')) return;
    var ok = depositHandled(sec);
    var btn = sec.querySelector('.ci-submit'); if (btn) btn.hidden = !ok;
    var wait = sec.querySelector('.ci-deposit__wait'); if (wait) wait.hidden = ok;
  }

  // ── Passport photo → fields ────────────────────────────────────────────────
  // Fill what the server read from the photo. A field the guest typed is never
  // overwritten; one we filled earlier (data-ai-filled) may be replaced by a
  // newer photo. Filled fields are highlighted until the guest edits them.
  function fillPassport(pp, d) {
    var scope = pp.closest('.ci-guest') || pp;
    var fields = d.fields || {}, warnings = d.warnings || [], filled = 0;
    Object.keys(fields).forEach(function (k) {
      var el = scope.querySelector('[name="' + k + '"], [data-field="' + k + '"]');
      if (!el || (String(el.value).trim() !== '' && !el.hasAttribute('data-ai-filled'))) return;
      el.value = fields[k];
      el.setAttribute('data-ai-filled', '1');
      el.classList.add('ci-in--ai');
      filled++;
    });
    var note = pp.querySelector('.ci-pp-note'); if (!note) return;
    var msgs = [];
    if (filled) msgs.push('We filled in the details from the photo — please check them.');
    else if (d.read === false || (Object.keys(fields).length === 0 && !warnings.length)) msgs.push('We couldn’t read the photo clearly — please type the details below.');
    msgs = msgs.concat(warnings);
    note.textContent = msgs.join(' ');
    note.classList.toggle('ci-pp-note--warn', warnings.length > 0);
    note.hidden = msgs.length === 0;
  }
  form.addEventListener('input', function (e) {
    var el = e.target;
    if (el && el.hasAttribute && el.hasAttribute('data-ai-filled')) { el.removeAttribute('data-ai-filled'); el.classList.remove('ci-in--ai'); }
  });

  // ── Sharing a guest's link — same sentence as checkin_guest_share_text() ───
  function shareText(name, link) {
    var party = form.querySelector('.ci-party');
    var prop  = party ? (party.getAttribute('data-share-property') || '').trim() : '';
    var dates = party ? (party.getAttribute('data-share-dates') || '').trim() : '';
    var first = String(name || '').trim().split(/\s+/)[0] || '';
    var where = prop ? ' for ' + prop : '';
    var when  = (where && dates) ? ', ' + dates : '';
    return 'Hi' + (first ? ' ' + first : '') + ', please complete your check-in' + where + when + ': ' + link;
  }
  function waUrl(text) { return 'https://wa.me/?text=' + encodeURIComponent(text); }

  // "Your details" is the consent gate: terms + typed name + a signature, plus
  // the passport fields when that step is configured as required. The wording
  // mirrors checkin_consent_missing() in includes/checkin.php.
  // retry: what to run again once the guest accepts the terms in the dialog.
  function validateStep(sec, retry) {
    if (!sec) return true;
    clearErr(sec);
    if (sec.getAttribute('data-key') === 'deposit') {
      var dep = sec.querySelector('.ci-deposit');
      if (dep && dep.hasAttribute('data-deposit-gate')) {
        if (!depositHandled(sec)) { showErr(sec, ['upload a photo of your card, or tell us how you’ll pay the deposit']); return false; }
        return true;
      }
      // Pre-migration: when the step is required, the card image must be on file.
      if (dep && dep.hasAttribute('data-deposit-required')) {
        var du = sec.querySelector('.ci-upload');
        if (du && du.getAttribute('data-has') !== '1') { showErr(sec, ['upload a photo of your credit card']); return false; }
      }
      return true;
    }
    if (sec.getAttribute('data-key') !== 'you') return true;
    var missing = [];
    var agree = sec.querySelector('.ci-agree');
    // Unticked terms: show them in a dialog and let the guest accept there,
    // instead of an error. Accepting ticks the box and re-runs the action.
    if (agree && !agree.checked && window.ciTermsDialog && retry) {
      window.ciTermsDialog(sec.querySelector('.ci-waiver'), function () { agree.checked = true; retry(); });
      return false;
    }
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
      // Photo OR typed details — mirrors checkin_guest_passport_complete().
      if (fieldVal(sec, 'passport_name') === '')   missing.push('enter your passport name');
      if (fieldVal(sec, 'passport_number') === '') missing.push('enter your passport number');
      var up = sec.querySelector('.ci-pp .ci-upload');
      var hasPhoto = up && up.getAttribute('data-has') === '1';
      if (!hasPhoto && (fieldVal(sec, 'nationality') === '' || fieldVal(sec, 'passport_expiry') === '')) {
        missing.push('upload a passport photo or enter your nationality and passport expiry');
      }
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
      if (!validateStep(steps[cur], function () { t.click(); })) return;
      saveThen(function () { show(cur + 1); });
      return;
    }

    if (t.classList.contains('ci-noncard__toggle')) {
      e.preventDefault();
      var opts = t.parentNode.querySelector('.ci-noncard__opts');
      var open = opts.hidden;
      opts.hidden = !open;
      t.setAttribute('aria-expanded', open ? 'true' : 'false');
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
          card.setAttribute('data-link', d.link || '');
          var link = card.querySelector('.ci-send .ci-linkrow input');
          if (link) link.value = d.link || '';
          var party = form.querySelector('.ci-party');
          if (party) party.appendChild(card); else addBtn.parentNode.insertBefore(card, addBtn);
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
    if (t.classList.contains('ci-guest__edit')) {   // reopen a saved guest's form
      e.preventDefault();
      var ec = t.closest('.ci-guest');
      ec.querySelector('.ci-guest__form').hidden = false;
      ec.querySelector('.ci-guest__done').hidden = true;
      ec.querySelector('.ci-send').hidden = true;
      var en = ec.querySelector('.ci-guest__name'); if (en) en.focus();
      return;
    }

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
      var nameEl = card.querySelector('.ci-guest__name');
      var gname = nameEl ? nameEl.value.trim() : '';
      if (!gname) {
        if (nameEl) { nameEl.focus(); nameEl.classList.add('is-invalid'); nameEl.setAttribute('placeholder', 'Type their full name first'); }
        return;
      }
      var fd = new FormData();
      fd.append('ref', REF); fd.append('csrf_token', CSRF); fd.append('guest_id', gid); fd.append('ajax', '1');
      // This posts ONE guest card, not the whole form — but it carries `ref`, so the
      // endpoint would otherwise treat it as the lead saving the booking and
      // overwrite every booking-level answer with the fields absent here (arrival,
      // transfers, dietary, requests). `scope` says so explicitly; the endpoint
      // skips its booking-level block when it sees this. Any future partial poster
      // to checkin-save.php must set it too.
      fd.append('scope', 'guest');
      card.querySelectorAll('[data-field]').forEach(function (el) {
        var f = el.getAttribute('data-field');
        if (el.type === 'checkbox') { if (el.checked) fd.append(f, '1'); } else fd.append(f, el.value);
      });
      t.disabled = true; t.textContent = 'Saving…';
      fetch('/api/checkin-save.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) return Promise.reject(); })
        .then(function () {
          // Saved: close the form, show the summary and the link to send them.
          t.textContent = 'Save this guest'; t.disabled = false;
          if (nameEl) nameEl.classList.remove('is-invalid');
          var link = card.getAttribute('data-link') || '';
          card.querySelector('.ci-guest__form').hidden = true;
          var done = card.querySelector('.ci-guest__done');
          done.querySelector('.ci-guest__done-name').textContent = gname;
          var chip = done.querySelector('.ci-chip');
          if (chip && !chip.classList.contains('ci-chip--ok')) chip.textContent = 'Saved ✓';
          done.hidden = false;
          var send = card.querySelector('.ci-send');
          send.querySelector('.ci-send__name').textContent = gname.split(/\s+/)[0];
          send.querySelector('.ci-wa').setAttribute('href', waUrl(shareText(gname, link)));
          var li = send.querySelector('.ci-linkrow input'); if (li && !li.value) li.value = link;
          send.hidden = !!(chip && chip.classList.contains('ci-chip--ok'));
          card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        })
        .catch(function () { t.textContent = 'Could not save — try again'; t.disabled = false; });
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

  // Arrival: show only the chosen mode's fields. Transfer: show the leg the
  // guest asked for, and reveal the free-text airport box when "Other" is
  // picked. First paint is server-rendered.
  form.addEventListener('change', function (e) {
    var t = e.target;
    if (t.classList.contains('ci-f-mode')) {
      var step = t.closest('.ci-step');
      step.querySelectorAll('.ci-mode-fields').forEach(function (g) {
        g.hidden = g.getAttribute('data-mode') !== t.value;
      });
      // The transfer step asks different things of a flier, and the mode lives
      // on THIS step — so changing it has to re-run that step's toggle too.
      syncTransferFields(form);
      return;
    }
    if (t.classList.contains('ci-f-patime')) {
      syncArrivalWarning(t.closest('.ci-step'));
      return;
    }
    if (t.classList.contains('ci-f-tin') || t.classList.contains('ci-f-tout')) {
      syncTransferFields(form);
      return;
    }
    if (t.classList.contains('ci-f-airport')) {
      // The select moved from .ci-mode-fields (arrival step) to .ci-tin-fields
      // (transfer step) — its new wrapper, and the only node that holds the
      // free-text box. Guarded, not widened: if the markup moves again this
      // does nothing rather than toggling a box in an unrelated subtree.
      var grp = t.closest('.ci-tin-fields');
      var box = grp && grp.querySelector('.ci-airport-other');
      if (box) box.hidden = t.value !== '__other';
      return;
    }
  });

  // Delegated file upload — any .ci-upload file input. A wrap tagged
  // data-kind="deposit" uploads the booking-level credit-card image; every other
  // .ci-upload is a per-guest passport scan (guest_id from the enclosing card,
  // absent → lead).
  form.addEventListener('change', function (e) {
    if (e.target.type !== 'file' || !e.target.closest('.ci-upload')) return;
    var input = e.target, card = input.closest('[data-guest-id]');
    var f = input.files && input.files[0]; if (!f) return;
    var wrap = input.closest('.ci-upload'), state = wrap.querySelector('.ci-upload__state');
    var isDeposit = wrap.getAttribute('data-kind') === 'deposit';
    var isImage = /^image\//.test(f.type || '');
    state.textContent = (!isDeposit && isImage) ? 'Uploading and reading your passport…' : 'Uploading…';
    var fd = new FormData();
    fd.append('ref', REF); fd.append('csrf_token', CSRF);
    if (isDeposit) {
      fd.append('deposit_card', f);
    } else {
      if (card) fd.append('guest_id', card.getAttribute('data-guest-id'));
      fd.append('passport', f);
    }
    fetch('/api/checkin-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (d) {
        state.innerHTML = 'Uploaded ✓'; wrap.setAttribute('data-has', '1');
        if (isDeposit) { syncDepositGate(); return; }
        var pp = wrap.closest('[data-pp]');
        if (pp && d && (d.fields || d.read === false || d.warnings)) fillPassport(pp, d);
      })
      .catch(function () { state.textContent = 'Upload failed — try again'; });
  });

  form.addEventListener('change', function (e) {
    if (e.target.classList && e.target.classList.contains('ci-f-plan')) syncDepositGate();
  });

  // Server renders the correct initial state; this keeps it right if the browser
  // restored a value on back-navigation. It also fills .ci-arrwarn__t, which the
  // server deliberately leaves empty so the two can never word the warning
  // differently. Top-level, so every entry path (intro, edit, straight-in) gets it.
  var arrStep = form.querySelector('.ci-step[data-key="arrival"]');
  if (arrStep) syncArrivalWarning(arrStep);
  syncTransferFields(form);

  // Initial view: ?resume=1 (the Resume button on Messages) drops straight back
  // in at the step the guest left off on; otherwise the intro when there is one,
  // else straight into the steps.
  if (stepsWrap && stepsWrap.getAttribute('data-autoresume') === '1') openSteps(resumeIdx);
  else if (!intro && !editBtn) openSteps(0);

  // Final submit re-checks the consent step, so it cannot be skipped by jumping
  // straight to the last step. The server enforces the same rule regardless.
  form.addEventListener('submit', function (e) {
    var submitter = e.submitter || form.querySelector('.ci-submit');
    var retry = function () { if (form.requestSubmit) form.requestSubmit(submitter); else submitter.click(); };
    var you = form.querySelector('.ci-step[data-key="you"]');
    if (you && !validateStep(you, retry)) {
      e.preventDefault();
      var idx = steps.indexOf(you);
      if (idx >= 0 && idx !== cur) openSteps(idx);
      return;
    }
    var dep = form.querySelector('.ci-step[data-key="deposit"]');
    if (dep && !validateStep(dep)) { e.preventDefault(); openSteps(steps.indexOf(dep)); }
  });
  syncDepositGate();

})();
