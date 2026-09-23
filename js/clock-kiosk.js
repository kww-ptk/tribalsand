/* Staff clock in/out kiosk.

   The device token in localStorage is what authenticates a punch — no one is
   signed in at a shared tablet. Registration happens once.

   FOUR STATES, and the camera belongs to them:

     idle    [camera OFF]  breathing logo, START
     scan    [camera ON]   decoding frames, 45s to show a card
     confirm [camera ON]   greeting + the one button that applies, 20s to act
     result  [camera OFF]  what was recorded, 4s, then idle

   The camera is RELEASED on every return to idle — track.stop(), not a paused
   <video>, which would leave the hardware indicator lit and give up the whole
   point. It is stopped the moment the evidence photo is captured, before the
   punch is even sent: nothing after that needs the lens.

   getUserMedia runs on the START press, not on load. The permission prompt then
   follows a deliberate tap, and on HTTPS the grant persists so later presses go
   straight to the camera. */
(function () {
  'use strict';
  var KEY       = 'ts_clock_device_token';
  var VENUE_KEY = 'ts_clock_venue_name';

  var SCAN_TIMEOUT_MS    = 45000;   // tapped START and walked away
  var CONFIRM_TIMEOUT_MS = 20000;   // scanned and walked away
  var RESULT_MS          = 4000;

  var setup   = document.getElementById('setupMode');
  var kiosk   = document.getElementById('kioskMode');
  var idle    = document.getElementById('idleMode');
  var scan    = document.getElementById('scanMode');
  var person  = document.getElementById('person');
  var video   = document.getElementById('video');
  var frame   = document.getElementById('frame');

  var personName = document.getElementById('personName');
  var personMeta = document.getElementById('personMeta');
  var personActs = document.getElementById('personActs');
  var idleMsg    = document.getElementById('idleMsg');
  var msg        = document.getElementById('msg');

  var token = null;
  try { token = localStorage.getItem(KEY); } catch (e) { token = null; }

  function say(el, text, good) {
    if (!el) return;
    el.textContent = text || '';
    el.className = 'msg' + (text ? (good ? ' msg--good' : ' msg--bad') : '');
  }

  /* ── Registration ──────────────────────────────────────────────────────── */
  var devSave = document.getElementById('devSave');
  if (devSave) {
    devSave.addEventListener('click', function () {
      var name = (document.getElementById('devName').value || '').trim();
      var out  = document.getElementById('setupMsg');
      if (!name) { out.textContent = 'Give the tablet a name first.'; out.className = 'msg msg--bad'; return; }

      var body = new FormData();
      var tokenField = document.querySelector('input[name="csrf_token"]');
      body.append('csrf_token', tokenField ? tokenField.value : '');
      body.append('name', name);
      body.append('venue_id', document.getElementById('devVenue').value);
      devSave.disabled = true;

      fetch('/api/clock-register.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Could not register. Reload and try again.' }; }); })
        .then(function (d) {
          if (d && d.ok) {
            try {
              localStorage.setItem(KEY, d.token);
              if (d.venue_name) localStorage.setItem(VENUE_KEY, d.venue_name);
            } catch (e) {}
            window.location.reload();
            return;
          }
          devSave.disabled = false;
          out.textContent = (d && d.error) || 'Could not register.';
          out.className = 'msg msg--bad';
        })
        .catch(function () {
          devSave.disabled = false;
          out.textContent = 'Network problem. Please try again.';
          out.className = 'msg msg--bad';
        });
    });
  }

  if (!token) return;   // stay in setup mode

  setup.classList.add('hidden');
  kiosk.classList.remove('hidden');

  /* ── Idle furniture: the property name, and a clock started from the
        SERVER's time so it can never disagree with what a punch records. ──── */
  (function () {
    var where = document.getElementById('idleWhere');
    var venue = null;
    try { venue = localStorage.getItem(VENUE_KEY); } catch (e) {}
    if (where && venue) where.textContent = venue + ' · ' + where.textContent;

    var el = document.getElementById('idleClock');
    if (!el) return;
    var parts = (el.getAttribute('data-now') || '').split(':');
    if (parts.length !== 2) return;
    var mins = (parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10));
    var base = Date.now();
    setInterval(function () {
      var m = (mins + Math.floor((Date.now() - base) / 60000)) % 1440;
      el.textContent = ('0' + Math.floor(m / 60)).slice(-2) + ':' + ('0' + (m % 60)).slice(-2);
    }, 10000);
  })();

  /* ── State ─────────────────────────────────────────────────────────────── */
  var stream = null, scanning = false, current = null, pending = null;
  var scanTimer = null, confirmTimer = null, resultTimer = null;
  var ctx = frame.getContext('2d', { willReadFrequently: true });

  function show(which) {
    idle.classList.toggle('hidden',   which !== 'idle');
    scan.classList.toggle('hidden',   which !== 'scan');
    person.classList.toggle('hidden', which !== 'confirm');
  }

  function stopCamera() {
    scanning = false;
    if (stream) {
      stream.getTracks().forEach(function (t) { t.stop(); });
      stream = null;
    }
    try { video.srcObject = null; } catch (e) {}
  }

  function clearTimers() {
    clearTimeout(scanTimer); clearTimeout(confirmTimer); clearTimeout(resultTimer);
    scanTimer = confirmTimer = resultTimer = null;
  }

  function toIdle() {
    clearTimers();
    stopCamera();
    current = null; pending = null;
    say(msg, ''); say(idleMsg, '');
    show('idle');
  }

  function toScanning() {
    say(idleMsg, '');
    show('scan');
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
      .then(function (s) { stream = s; video.srcObject = s; return video.play(); })
      .then(function () {
        scanning = true;
        scanTimer = setTimeout(toIdle, SCAN_TIMEOUT_MS);
        requestAnimationFrame(tick);
      })
      .catch(function () {
        stopCamera();
        show('idle');
        say(idleMsg, 'No camera. Allow camera access for this page, then reload.', false);
      });
  }

  document.getElementById('startBtn').addEventListener('click', toScanning);

  /* ── Decode loop ───────────────────────────────────────────────────────── */
  function tick() {
    if (scanning && video.readyState === video.HAVE_ENOUGH_DATA) {
      frame.width = video.videoWidth;
      frame.height = video.videoHeight;
      ctx.drawImage(video, 0, 0, frame.width, frame.height);
      try {
        var img  = ctx.getImageData(0, 0, frame.width, frame.height);
        var code = window.jsQR ? window.jsQR(img.data, img.width, img.height) : null;
        if (code && code.data) onCard(code.data.trim());
      } catch (e) { /* a frame we could not read; try the next one */ }
    }
    if (stream) requestAnimationFrame(tick);
  }

  /* ── A card was seen: ask the server who it is ─────────────────────────── */
  function onCard(data) {
    if (!/^[0-9a-f]{32}$/.test(data)) return;   // not one of our cards
    if (current === data) return;
    scanning = false;
    current  = data;
    clearTimeout(scanTimer);

    personName.textContent = 'Reading card…';
    personMeta.textContent = '';
    personActs.innerHTML   = '';
    show('confirm');

    var body = new FormData();
    body.append('device_token', token);
    body.append('card', current);

    fetch('/api/clock-card.php', { method: 'POST', body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Could not read that card.' }; }); })
      .then(function (d) { d && d.ok ? renderPerson(d) : renderRefusal(d && d.error); })
      .catch(function () { renderRefusal('No connection. Try again in a moment.'); });
  }

  /* The greeting, and the ONE button that applies. Never a fallback to two
     buttons: that hands the decision back to the person, which is the thing
     this replaced. */
  function renderPerson(d) {
    pending = d.action;
    // "Hello," opens a shift, "Hi" greets someone already under way or finished
    // for the day. Keyed on 'in' rather than 'out' so the no-action states
    // (done, on leave) also read as "Hi Moses", per the spec's copy table.
    personName.textContent = (d.action === 'in' ? 'Hello, ' : 'Hi ') + (d.name || 'there');
    personMeta.textContent = d.message || '';
    personActs.innerHTML   = '';

    if (d.action === 'in' || d.action === 'out') {
      var b = document.createElement('button');
      b.className = 'big ' + (d.action === 'in' ? 'big--in' : 'big--out');
      b.setAttribute('data-kind', d.action);
      b.textContent = d.action === 'in' ? 'Clock in' : 'Clock out';
      personActs.appendChild(b);
    }

    var c = document.createElement('button');
    c.className = 'big big--ghost';
    c.setAttribute('data-cancel', '');
    c.textContent = (d.action === 'in' || d.action === 'out') ? 'Cancel' : 'Done';
    personActs.appendChild(c);

    confirmTimer = setTimeout(toIdle, CONFIRM_TIMEOUT_MS);
  }

  function renderRefusal(text) {
    pending = null;
    personName.textContent = '';
    personActs.innerHTML   = '';
    say(msg, text || 'Could not read that card.', false);
    resultTimer = setTimeout(toIdle, RESULT_MS);
  }

  /* ── Confirm and record ────────────────────────────────────────────────── */
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-cancel]')) { toIdle(); return; }

    var btn = ev.target.closest('[data-kind]');
    if (!btn || !current || !pending) return;

    clearTimeout(confirmTimer);

    var body = new FormData();
    body.append('device_token', token);
    body.append('card', current);
    body.append('kind', btn.getAttribute('data-kind'));

    // Capture BEFORE releasing the camera, then release it immediately — the
    // evidence photo is in hand and nothing downstream needs the lens.
    var shot = capture();
    if (shot) body.append('photo', shot, 'punch.jpg');
    stopCamera();

    btn.disabled = true;
    say(msg, 'Saving…', true);

    fetch('/api/clock-punch.php', { method: 'POST', body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'That didn’t save. Please try again.' }; }); })
      .then(function (d) {
        if (d && d.ok) {
          var who = (d.name || '').split(/\s+/)[0];
          say(msg, 'Thanks ' + who + ' — clocked ' + d.kind + ' at ' + d.time + '.', true);
        } else {
          say(msg, (d && d.error) || 'That didn’t save.', false);
        }
        personActs.innerHTML = '';
        resultTimer = setTimeout(toIdle, RESULT_MS);
      })
      .catch(function () {
        say(msg, 'No connection. The punch was NOT saved — try again.', false);
        personActs.innerHTML = '';
        resultTimer = setTimeout(toIdle, RESULT_MS);
      });
  });

  /* A still from the live stream, as a JPEG blob. Returns null if unavailable —
     the punch still goes through without it.

     Downscaled to at most 640px on the long edge before encoding. The decode
     canvas runs at the camera's native size (often 1280x720) because jsQR needs
     the detail to read a card, but the stored evidence does not: 640px is ample
     to recognise a face, and at roughly a quarter of the pixels it cuts each
     file from ~150KB to ~40KB. Across 73 staff punching twice a day that is the
     difference between ~1GB and ~250MB a month. */
  var SHOT_MAX_EDGE = 640;
  var shotCanvas = document.createElement('canvas');

  function capture() {
    try {
      if (!frame.width || !frame.height) return null;

      var scale = Math.min(1, SHOT_MAX_EDGE / Math.max(frame.width, frame.height));
      shotCanvas.width  = Math.max(1, Math.round(frame.width * scale));
      shotCanvas.height = Math.max(1, Math.round(frame.height * scale));
      shotCanvas.getContext('2d').drawImage(frame, 0, 0, shotCanvas.width, shotCanvas.height);

      var data = shotCanvas.toDataURL('image/jpeg', 0.7).split(',')[1];
      var bin  = atob(data);
      var arr  = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
      return new Blob([arr], { type: 'image/jpeg' });
    } catch (e) { return null; }
  }

  toIdle();
})();
