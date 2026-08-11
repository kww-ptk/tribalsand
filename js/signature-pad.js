(function () {
  // Fixed backing store (600x150) so the pad works even if it is initialised while
  // its wizard step is hidden (display:none => 0 client size). Pointer coords are
  // scaled from the on-screen box to the backing store, so strokes follow the finger.
  function initPad(canvas) {
    if (canvas.__ciSignInit) return; canvas.__ciSignInit = true;
    var sel = canvas.getAttribute('data-target');
    var hidden = sel ? document.querySelector(sel) : null;
    var ctx = canvas.getContext('2d');
    canvas.width = 600; canvas.height = 150;
    ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#16375a';
    var drawing = false, dirty = false, last = null;
    function pos(e) {
      var rect = canvas.getBoundingClientRect();
      var t = (e.touches && e.touches[0]) ? e.touches[0] : e;
      var sx = rect.width ? canvas.width / rect.width : 1;
      var sy = rect.height ? canvas.height / rect.height : 1;
      return { x: (t.clientX - rect.left) * sx, y: (t.clientY - rect.top) * sy };
    }
    function start(e) { e.preventDefault(); drawing = true; last = pos(e); }
    function move(e) {
      if (!drawing) return; e.preventDefault();
      var p = pos(e);
      ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
      last = p; dirty = true;
    }
    function end() { if (!drawing) return; drawing = false; if (hidden && dirty) hidden.value = canvas.toDataURL('image/png'); }
    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    canvas.addEventListener('touchend', end);
    var wrap = canvas.closest('.ci-sign');
    var clr = wrap ? wrap.querySelector('.ci-sign-clear') : null;
    if (clr) clr.addEventListener('click', function (e) {
      e.preventDefault();
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      dirty = false; if (hidden) hidden.value = '';
    });
  }
  function initAll() { document.querySelectorAll('canvas.ci-sign-pad').forEach(initPad); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll); else initAll();
  window.ciSignInitAll = initAll; // exposed in case markup is injected later
})();
