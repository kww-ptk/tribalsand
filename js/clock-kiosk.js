/* Staff clock in/out kiosk.

   The device token in localStorage is what authenticates a punch — no one is
   signed in at a shared tablet. Registration happens once; after that the page
   goes straight to the camera on every load.

   The camera stream is opened once and kept. jsQR decodes frames off a canvas;
   a decoded card pauses scanning until the person acts or cancels, so a card
   left in front of the lens cannot fire repeatedly. */
(function () {
  'use strict';
  var KEY = 'ts_clock_device_token';

  var kiosk = document.getElementById('kioskMode');
  var setup = document.getElementById('setupMode');
  var video = document.getElementById('video');
  var frame = document.getElementById('frame');
  var person = document.getElementById('person');
  var personName = document.getElementById('personName');
  var personMeta = document.getElementById('personMeta');
  var msg = document.getElementById('msg');

  var token = null;
  try { token = localStorage.getItem(KEY); } catch (e) { token = null; }

  var scanning = false;
  var current = null;

  function say(text, good) {
    msg.textContent = text || '';
    msg.className = 'msg' + (text ? (good ? ' msg--good' : ' msg--bad') : '');
  }

  /* ── Registration ──────────────────────────────────────────────────────── */
  var devSave = document.getElementById('devSave');
  if (devSave) {
    devSave.addEventListener('click', function () {
      var name = (document.getElementById('devName').value || '').trim();
      var out = document.getElementById('setupMsg');
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
            try { localStorage.setItem(KEY, d.token); } catch (e) {}
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

  /* ── Camera + decode loop ──────────────────────────────────────────────── */
  var ctx = frame.getContext('2d', { willReadFrequently: true });

  navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
    .then(function (stream) {
      video.srcObject = stream;
      return video.play();
    })
    .then(function () { scanning = true; requestAnimationFrame(tick); })
    .catch(function () {
      say('No camera. Allow camera access for this page, then reload.', false);
    });

  function tick() {
    if (scanning && video.readyState === video.HAVE_ENOUGH_DATA) {
      frame.width = video.videoWidth;
      frame.height = video.videoHeight;
      ctx.drawImage(video, 0, 0, frame.width, frame.height);
      try {
        var img = ctx.getImageData(0, 0, frame.width, frame.height);
        var code = window.jsQR ? window.jsQR(img.data, img.width, img.height) : null;
        if (code && code.data) onCard(code.data.trim());
      } catch (e) { /* a frame we could not read; try the next one */ }
    }
    requestAnimationFrame(tick);
  }

  /* ── A card was seen ───────────────────────────────────────────────────── */
  function onCard(data) {
    if (!/^[0-9a-f]{32}$/.test(data)) return;   // not one of our cards
    if (current === data) return;
    scanning = false;
    current = data;
    personName.textContent = 'Card read';
    personMeta.textContent = 'Choose clock in or clock out';
    person.classList.remove('hidden');
    say('');
  }

  function reset() {
    current = null;
    person.classList.add('hidden');
    scanning = true;
  }

  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-cancel]')) { reset(); say(''); return; }

    var btn = ev.target.closest('[data-kind]');
    if (!btn || !current) return;

    var body = new FormData();
    body.append('device_token', token);
    body.append('card', current);
    body.append('kind', btn.getAttribute('data-kind'));

    var shot = capture();
    if (shot) body.append('photo', shot, 'punch.jpg');

    Array.prototype.forEach.call(document.querySelectorAll('[data-kind]'), function (b) { b.disabled = true; });
    say('Saving…', true);

    fetch('/api/clock-punch.php', { method: 'POST', body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'That didn’t save. Please try again.' }; }); })
      .then(function (d) {
        Array.prototype.forEach.call(document.querySelectorAll('[data-kind]'), function (b) { b.disabled = false; });
        if (d && d.ok) {
          say(d.name + ' — clocked ' + d.kind.toUpperCase() + ' at ' + d.time, true);
        } else {
          say((d && d.error) || 'That didn’t save.', false);
        }
        setTimeout(function () { reset(); say(''); }, 4000);
      })
      .catch(function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-kind]'), function (b) { b.disabled = false; });
        say('No connection. The punch was NOT saved — try again.', false);
      });
  });

  /* A still from the live stream, as a JPEG blob. Returns null if unavailable —
     the punch still goes through without it. */
  function capture() {
    try {
      if (!frame.width) return null;
      var data = frame.toDataURL('image/jpeg', 0.7).split(',')[1];
      var bin = atob(data);
      var arr = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
      return new Blob([arr], { type: 'image/jpeg' });
    } catch (e) { return null; }
  }
})();
