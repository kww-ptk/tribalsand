/*
 * Check-in photo uploads — passport scans and the deposit card photo.
 *
 * ONE copy, shared by the lead's wizard (includes/app/checkin.php +
 * js/checkin-wizard.js) and a co-guest's own page (includes/app/checkin-guest.php),
 * so the two can't drift apart again. It owns:
 *   - shrinking a big phone photo before it is sent (≤ MAX_SIDE px, JPEG);
 *   - the progress card: Upload (real %) → Read details → Done, with Try again;
 *   - filling the form from what api/checkin-upload.php read off the photo.
 *
 * Fill rules: a field the guest typed on this visit is never overwritten; one we
 * filled from an earlier photo (data-ai-filled) may be replaced. The NAME is the
 * exception: a name the page opened with (data-pp-replace — the booking name, or
 * one typed on an earlier visit) gives way to the name on the passport, and the
 * note offers Undo with the old name.
 *
 * Usage: tsCheckinPassport.bind(form, { formData: function (wrap, isDeposit) {…},
 *                                        onDone: function (wrap, d, isDeposit) {…} })
 * formData returns a FormData carrying the auth fields (ref/g, csrf_token, guest_id).
 */
(function () {
  'use strict';
  if (window.tsCheckinPassport) return;

  var ENDPOINT    = '/api/checkin-upload.php';
  var MAX_SIDE    = 2000;                 // still sharp enough to read the passport's code lines
  var SHRINK_OVER = 1.5 * 1024 * 1024;    // smaller files under MAX_SIDE go as they are
  var MAX_BYTES   = 8 * 1024 * 1024;      // = the limit in api/checkin-upload.php
  var NAME        = 'passport_name';

  // ── Shrinking ─────────────────────────────────────────────────────────────
  // Any problem (old browser, odd file) sends the original — never blocks.
  function shrink(file) {
    return new Promise(function (resolve) {
      if (!/^image\/(jpeg|png)$/.test(file.type || '') || !window.URL || !URL.createObjectURL) return resolve(file);
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        var w = img.naturalWidth, h = img.naturalHeight, big = Math.max(w, h);
        if (!big || (big <= MAX_SIDE && file.size <= SHRINK_OVER)) { URL.revokeObjectURL(url); return resolve(file); }
        var k = Math.min(1, MAX_SIDE / big);
        var c = document.createElement('canvas');
        c.width = Math.round(w * k); c.height = Math.round(h * k);
        var ctx = c.getContext && c.getContext('2d');
        if (!ctx || !c.toBlob) { URL.revokeObjectURL(url); return resolve(file); }
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);   // PNG transparency → white, not black
        ctx.drawImage(img, 0, 0, c.width, c.height);                     // browsers apply the photo's rotation
        URL.revokeObjectURL(url);
        c.toBlob(function (b) { resolve(b && b.size < file.size ? b : file); }, 'image/jpeg', 0.88);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  // ── Sending (XHR: fetch can't report upload progress) ─────────────────────
  // onUp(fraction) while sending, onUp(1, true) once every byte is out and the
  // server is working (storing, then reading the photo).
  function send(fd, onUp) {
    return new Promise(function (resolve, reject) {
      var x = new XMLHttpRequest();
      x.open('POST', ENDPOINT);
      x.withCredentials = true;
      x.timeout = 90000;
      if (x.upload) {
        x.upload.onprogress = function (e) { if (e.lengthComputable) onUp(e.loaded / e.total, false); };
        x.upload.onload = function () { onUp(1, true); };
      }
      x.onload = function () {
        var d = null; try { d = JSON.parse(x.responseText); } catch (e) {}
        if (x.status >= 200 && x.status < 300 && d) resolve(d);
        else reject({ status: x.status, error: d && d.error });
      };
      x.onerror   = function () { reject({ status: 0 }); };
      x.ontimeout = function () { reject({ status: 0, timeout: true }); };
      x.send(fd);
    });
  }

  function errorText(err, isDeposit) {
    var e = (err && err.error) || '';
    if (e === 'too large') return 'That photo is over 8 MB — please choose a smaller one.';
    if (e === 'type not allowed') return isDeposit ? 'Please choose a JPG or PNG photo.' : 'Please choose a JPG or PNG photo, or a PDF.';
    if (err && err.timeout) return 'That took too long — please try again.';
    if (err && err.status === 0) return 'The connection dropped — check your signal and try again.';
    return 'Upload failed — please try again.';
  }

  // ── Progress card ─────────────────────────────────────────────────────────
  function card(wrap) {
    var p = wrap.querySelector('.ci-prog');
    if (p) return p;
    p = document.createElement('div');
    p.className = 'ci-prog';
    p.innerHTML =
        '<span class="ci-prog__thumb" aria-hidden="true"></span>'
      + '<div class="ci-prog__body">'
      +   '<div class="ci-prog__top"><span class="ci-prog__t" role="status"></span><span class="ci-prog__pct" aria-hidden="true"></span></div>'
      +   '<div class="ci-prog__bar"><span></span></div>'
      +   '<ol class="ci-prog__steps"></ol>'
      + '</div>'
      + '<button type="button" class="ci-prog__retry" hidden>Try again</button>';
    wrap.appendChild(p);
    return p;
  }

  function thumb(p, file) {
    var t = p.querySelector('.ci-prog__thumb');
    if (p.__url) { URL.revokeObjectURL(p.__url); p.__url = null; }
    if (/^image\//.test(file.type || '') && window.URL && URL.createObjectURL) {
      p.__url = URL.createObjectURL(file);
      t.style.backgroundImage = 'url("' + p.__url + '")';
      t.textContent = '';
      t.classList.remove('ci-prog__thumb--doc');
    } else {
      t.style.backgroundImage = '';
      t.textContent = 'PDF';
      t.classList.add('ci-prog__thumb--doc');
    }
  }

  function setSteps(p, labels) {
    p.querySelector('.ci-prog__steps').innerHTML = labels.map(function (l) { return '<li>' + l + '</li>'; }).join('');
  }

  // stage: upload | work | done | error.  step: index of the active step.
  function set(p, stage, step, title, frac) {
    p.setAttribute('data-stage', stage);
    p.querySelector('.ci-prog__t').textContent = title;
    var pct = p.querySelector('.ci-prog__pct'), bar = p.querySelector('.ci-prog__bar > span');
    if (stage === 'upload' && frac != null) {
      var n = Math.max(2, Math.round(frac * 100));
      pct.textContent = n + '%'; bar.style.width = n + '%';
    } else {
      pct.textContent = '';
      bar.style.width = stage === 'done' ? '100%' : '';
    }
    var lis = p.querySelectorAll('.ci-prog__steps li');
    for (var i = 0; i < lis.length; i++) {
      lis[i].className = (stage === 'done' || i < step) ? 'is-done' : (i === step ? (stage === 'error' ? 'is-err' : 'is-on') : '');
    }
    p.querySelector('.ci-prog__retry').hidden = stage !== 'error';
  }

  // ── Filling the form from the photo ──────────────────────────────────────
  function norm(s) { return String(s || '').toLowerCase().replace(/\s+/g, ' ').trim(); }
  function mark(el)   { el.setAttribute('data-ai-filled', '1'); el.classList.add('ci-in--ai'); }
  function unmark(el) { el.removeAttribute('data-ai-filled'); el.removeAttribute('data-pp-replace'); el.classList.remove('ci-in--ai'); }

  // ── Passport | Kenyan National ID switch ────────────────────────────────
  // checkin_id_switch_html() renders it; the hidden input is what the form posts,
  // and labels carrying data-idl-<type> show the text for the chosen document.
  /** The details read off the photo, not counting which document it was. */
  function readCount(fields) { return Object.keys(fields || {}).filter(function (k) { return k !== 'id_type'; }).length; }

  function setIdType(pp, type) {
    if (!pp) return;
    var sw = pp.querySelector('.ci-idtype'); if (!sw) return;
    type = type === 'kenya_id' ? 'kenya_id' : 'passport';
    sw.querySelectorAll('[data-idtype]').forEach(function (b) { b.setAttribute('aria-checked', b.getAttribute('data-idtype') === type ? 'true' : 'false'); });
    var h = sw.querySelector('input[type=hidden]'); if (h) h.value = type;
    pp.querySelectorAll('[data-idl-passport]').forEach(function (l) { l.textContent = l.getAttribute('data-idl-' + type) || l.textContent; });
  }

  function fill(pp, d) {
    var scope = pp.closest('.ci-guest') || pp;
    var fields = d.fields || {}, warnings = d.warnings || [], filled = 0, was = null, nameEl = null;
    if (fields.id_type) setIdType(pp, fields.id_type);   // the photo says which document it is
    Object.keys(fields).forEach(function (k) {
      if (k === 'id_type') return;
      var el = scope.querySelector('[name="' + k + '"], [data-field="' + k + '"]');
      if (!el) return;
      var cur = String(el.value).trim(), ours = el.hasAttribute('data-ai-filled');
      if (cur !== '' && !ours && !(k === NAME && el.hasAttribute('data-pp-replace'))) return;   // the guest's own
      if (k === NAME && cur !== '' && !ours && norm(cur) !== norm(fields[k])) was = { el: el, value: cur };
      // The card expiry arrives as YYYY-MM; the guest reads and types MM/YY.
      el.value = (k === 'card_expiry' && /^\d{4}-\d{2}$/.test(fields[k])) ? fields[k].slice(5) + '/' + fields[k].slice(2, 4) : fields[k];
      mark(el);
      filled++;
      if (k === NAME && el.getAttribute('name') === NAME) nameEl = el;   // the signer's own name, not another guest's card
    });

    // The name to sign with follows the passport, so the guest only has to draw
    // the signature. Same rule as every field: never over something they typed —
    // except a copy of the old name (still the booking name).
    var sign = null;
    if (nameEl && nameEl.form) {
      var sg = nameEl.form.querySelector('[name="waiver_signed_name"]');
      if (sg) {
        var sCur = String(sg.value).trim();
        if (sCur === '' || sg.hasAttribute('data-ai-filled') || (was && norm(sCur) === norm(was.value))) {
          sign = { el: sg, value: sg.value };
          sg.value = nameEl.value;
          mark(sg);
        }
      }
    }
    if (was) was.sign = sign;

    var note = pp.querySelector('.ci-pp-note');
    if (note) {
      var msgs = [];
      if (filled) msgs.push('Please check the highlighted details.');
      else if (d.read === false || (readCount(fields) === 0 && !warnings.length)) msgs.push('We couldn’t read the photo clearly — please type the details below.');
      msgs = msgs.concat(warnings);
      note.textContent = msgs.join(' ');
      if (was) {
        var line = document.createElement('span');
        line.className = 'ci-pp-note__was';
        line.appendChild(document.createTextNode('Name updated to match the passport (was “' + was.value + '”). '));
        var undo = document.createElement('button');
        undo.type = 'button'; undo.className = 'ci-pp-undo'; undo.textContent = 'Undo';
        line.appendChild(undo);
        note.appendChild(line);
        note.__was = was;
      } else {
        note.__was = null;
      }
      note.classList.toggle('ci-pp-note--warn', warnings.length > 0);
      note.hidden = note.textContent === '';
    }
    return { filled: filled };
  }

  // ── One upload, start to finish ──────────────────────────────────────────
  function start(wrap, input, file, opts) {
    var isDeposit = wrap.getAttribute('data-kind') === 'deposit';
    // The box the read fills: the passport block, or the deposit card block.
    var pp        = isDeposit ? wrap.closest('.ci-deposit') : wrap.closest('[data-pp]');
    var isImage   = /^image\//.test(file.type || '');
    var reads     = isImage && !!pp && pp.getAttribute('data-pp-ai') === '1';
    var run       = (wrap.__ciRun || 0) + 1;      // a newer photo makes older replies stale
    wrap.__ciRun  = run;
    wrap.__ciFile = file;

    var p = card(wrap), state = wrap.querySelector('.ci-upload__state'), timers = [];
    if (state) state.hidden = true;
    thumb(p, file);
    setSteps(p, reads ? ['Upload', 'Read details', 'Done'] : ['Upload', 'Done']);
    set(p, 'upload', 0, 'Preparing your photo…', null);
    wrap.setAttribute('aria-busy', 'true');
    input.disabled = true;

    function stale() { return wrap.__ciRun !== run; }
    function finish() {
      timers.forEach(clearTimeout);
      if (stale()) return;
      wrap.removeAttribute('aria-busy');
      input.disabled = false;
    }
    function working() {
      if (!reads) { set(p, 'work', 0, 'Saving…', null); return; }
      set(p, 'work', 1, 'Reading your document…', null);
      timers.push(setTimeout(function () { if (!stale()) p.querySelector('.ci-prog__t').textContent = 'Still reading — finding your details…'; }, 6000));
      timers.push(setTimeout(function () { if (!stale()) p.querySelector('.ci-prog__t').textContent = 'Almost there…'; }, 14000));
    }

    shrink(file).then(function (blob) {
      if (stale()) return null;
      if (blob.size > MAX_BYTES) return Promise.reject({ error: 'too large' });
      var fd = opts.formData(wrap, isDeposit);
      var name = blob === file ? (file.name || 'photo') : (isDeposit ? 'card.jpg' : 'passport.jpg');
      fd.append(isDeposit ? 'deposit_card' : 'passport', blob, name);
      set(p, 'upload', 0, 'Uploading…', 0);
      return send(fd, function (frac, sent) {
        if (stale()) return;
        if (sent) working(); else set(p, 'upload', 0, 'Uploading…', frac);
      });
    }).then(function (d) {
      if (d === null || stale()) return;
      finish();
      wrap.setAttribute('data-has', '1');
      var btn = wrap.querySelector('.ci-filebtn__t'); if (btn) btn.textContent = 'Retake picture';
      var title;
      if (isDeposit) {
        var cres = (pp && (d.fields || d.warnings)) ? fill(pp, d) : { filled: 0 };
        title = (d.read === true && cres.filled) ? 'Card read — ' + cres.filled + (cres.filled === 1 ? ' detail' : ' details') + ' filled in' : 'Card photo uploaded';
      } else {
        var res = (pp && (d.fields || d.read === false || d.warnings)) ? fill(pp, d) : { filled: 0 };
        var gotAny = d.read === true && readCount(d.fields) > 0;
        var doc = (d.fields && d.fields.id_type === 'kenya_id') ? 'ID card' : 'Passport';
        if (gotAny && res.filled)      title = doc + ' read — ' + res.filled + (res.filled === 1 ? ' detail' : ' details') + ' filled in';
        else if (gotAny)               title = doc + ' read';   // everything it found was already typed in
        else if (d.read !== undefined) title = 'Photo saved — we couldn’t read it, please type the details';
        else                           title = 'Photo saved — please type the details below';
      }
      set(p, 'done', 0, title, null);
      if (opts.onDone) opts.onDone(wrap, d, isDeposit);
    }).catch(function (err) {
      if (stale()) return;
      finish();
      set(p, 'error', 0, errorText(err, isDeposit), null);
    });
  }

  function busy(root) { return !!(root && root.querySelector('.ci-upload[aria-busy="true"]')); }

  function bind(root, opts) {
    if (!root || root.__ciPpBound) return;
    root.__ciPpBound = true;
    opts = opts || {};

    root.addEventListener('change', function (e) {
      var input = e.target;
      if (!input || input.type !== 'file' || !input.closest('.ci-upload')) return;
      var f = input.files && input.files[0];
      if (!f) return;
      start(input.closest('.ci-upload'), input, f, opts);
      input.value = '';   // choosing the same photo again still fires `change`
    });

    // Typing in a field makes it the guest's own: no highlight, never overwritten.
    root.addEventListener('input', function (e) {
      var el = e.target;
      if (el && el.hasAttribute && (el.hasAttribute('data-ai-filled') || el.hasAttribute('data-pp-replace'))) unmark(el);
    });

    root.addEventListener('click', function (e) {
      var t = e.target;
      var tb = t.closest && t.closest('.ci-idtype__b');
      if (tb) { e.preventDefault(); setIdType(tb.closest('[data-pp]'), tb.getAttribute('data-idtype')); return; }
      if (t.classList.contains('ci-prog__retry')) {
        e.preventDefault();
        var wrap = t.closest('.ci-upload'), input = wrap && wrap.querySelector('input[type=file]');
        if (wrap && input && wrap.__ciFile) start(wrap, input, wrap.__ciFile, opts);
        return;
      }
      if (t.classList.contains('ci-pp-undo')) {
        e.preventDefault();
        var note = t.closest('.ci-pp-note'), was = note && note.__was;
        if (!was) return;
        was.el.value = was.value;
        unmark(was.el);   // their choice now — a later photo leaves it alone
        if (was.sign && was.sign.el.hasAttribute('data-ai-filled')) { was.sign.el.value = was.sign.value; unmark(was.sign.el); }
        note.__was = null;
        var line = t.closest('.ci-pp-note__was'); if (line) line.remove();
        if (note.textContent.trim() === '') note.hidden = true;
      }
    });
  }

  window.tsCheckinPassport = { bind: bind, busy: busy, fill: fill, setIdType: setIdType };
})();
