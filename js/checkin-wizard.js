(function () {
  var form = document.getElementById('ciForm');
  if (!form) return;
  var steps = Array.prototype.slice.call(document.querySelectorAll('.ci-step'));
  if (!steps.length) return;
  var bar       = document.getElementById('ciBar');
  var intro     = document.getElementById('ciIntro');   // landing (absent once checked in)
  var stepsWrap = document.getElementById('ciSteps');
  var startBtn  = document.getElementById('ciStart');    // "Start / Continue check-in"
  var editBtn   = document.getElementById('ciEdit');     // "Update my details" (done state)
  var cur = 0;

  function show(i) {
    cur = Math.max(0, Math.min(steps.length - 1, i));
    steps.forEach(function (s, idx) { s.hidden = idx !== cur; });
    if (bar) bar.style.width = Math.round(((cur + 1) / steps.length) * 100) + '%';
    window.scrollTo(0, 0);
  }
  function openSteps() {
    if (intro) intro.hidden = true;
    if (stepsWrap) stepsWrap.hidden = false;
    show(0);
  }
  function backToStart() {           // Back from step 1 → return to the landing / done card
    if (stepsWrap) stepsWrap.hidden = true;
    if (intro) intro.hidden = false;
    window.scrollTo(0, 0);
  }

  if (startBtn) startBtn.addEventListener('click', openSteps);
  if (editBtn)  editBtn.addEventListener('click', openSteps);

  // Save the current step's fields via AJAX, then advance.
  function saveThen(next) {
    var fd = new FormData(form);
    fd.set('do', 'save'); fd.set('ajax', '1');
    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function () { next(); })
      .catch(function () { next(); }); // save is best-effort; never trap the guest
  }

  form.addEventListener('click', function (e) {
    if (e.target.classList.contains('ci-next')) { e.preventDefault(); saveThen(function () { show(cur + 1); }); }
    if (e.target.classList.contains('ci-back')) { e.preventDefault(); if (cur === 0) { backToStart(); } else { show(cur - 1); } }
  });

  // Async passport upload. Shows uploaded state; the file goes to private storage.
  var fileInput = document.getElementById('ciPassportFile');
  if (fileInput) {
    fileInput.addEventListener('change', function () {
      var f = fileInput.files && fileInput.files[0];
      if (!f) return;
      var wrap = fileInput.closest('.ci-upload');
      var state = wrap.querySelector('.ci-upload__state');
      state.textContent = 'Uploading…';
      var fd = new FormData();
      fd.append('ref', form.querySelector('input[name=ref]').value);
      fd.append('csrf_token', form.querySelector('input[name=csrf_token]').value);
      fd.append('passport', f);
      fetch('/api/checkin-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function () { state.innerHTML = 'Uploaded ✓'; wrap.setAttribute('data-has', '1'); })
        .catch(function () { state.textContent = 'Upload failed — try again'; });
    });
  }

  // Land on the intro (not-done) or the done card. Only jump straight into the
  // steps if there is neither (defensive — shouldn't happen).
  if (!intro && !editBtn) openSteps();
})();
