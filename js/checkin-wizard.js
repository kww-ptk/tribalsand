(function () {
  var form = document.getElementById('ciForm');
  if (!form || form.classList.contains('ci-done')) {
    // Completed view: still allow re-editing if steps are present.
  }
  var steps = Array.prototype.slice.call(document.querySelectorAll('.ci-step'));
  if (!steps.length) return;
  var bar = document.getElementById('ciBar');
  var cur = 0;

  function show(i) {
    cur = Math.max(0, Math.min(steps.length - 1, i));
    steps.forEach(function (s, idx) { s.hidden = idx !== cur; });
    if (bar) bar.style.width = Math.round(((cur + 1) / steps.length) * 100) + '%';
    window.scrollTo(0, 0);
  }

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
    if (e.target.classList.contains('ci-back')) { e.preventDefault(); show(cur - 1); }
  });

  // Async passport upload (Phase 3 endpoint). Shows uploaded state; no public URL.
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

  show(0);
})();
