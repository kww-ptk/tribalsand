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

    if (t.classList.contains('ci-next')) { e.preventDefault(); saveThen(function () { show(cur + 1); }); return; }
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
        .catch(function () { addBtn.disabled = false; updateAddBtn(); });
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

  // Initial view: intro when there is one, else straight into the steps. The old
  // ?ci= resume parameter is gone with the reload that needed it.
  if (!intro && !editBtn) openSteps(0);
})();
