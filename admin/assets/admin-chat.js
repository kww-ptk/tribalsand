// Admin live messaging — append on send + poll for incoming guest messages (no refresh).
// Progressive enhancement over the plain PRG form on admin/messages.php.
(function () {
  var thread = document.getElementById('amThread');
  var form = document.getElementById('amForm');
  if (!thread || !form) return;

  var lastId = parseInt(thread.dataset.last || '0', 10) || 0;
  var pollUrl = thread.dataset.pollUrl;

  function atBottom() {
    return (window.innerHeight + window.scrollY) >= (document.body.offsetHeight - 120);
  }

  function appendMsg(m) {
    if (m.id && thread.querySelector('[data-mid="' + m.id + '"]')) return; // dedupe
    var mine = m.sender === 'admin';
    var empty = thread.querySelector('.am-empty');
    if (empty) empty.style.display = 'none';
    var el = document.createElement('div');
    el.className = 'am-msg';
    if (m.id) el.setAttribute('data-mid', m.id);
    el.style.cssText = 'max-width:80%;border-radius:12px;padding:9px 12px;font-size:14px;line-height:1.5;' + (mine
      ? 'align-self:flex-end;background:#102F3A;color:#fff'
      : 'align-self:flex-start;background:#f3f4f6;color:#111');
    el.appendChild(document.createTextNode(m.body));
    var meta = document.createElement('div');
    meta.style.cssText = 'font-size:11px;margin-top:4px;opacity:.7';
    meta.textContent = (mine ? 'Staff' : 'Guest') + ' · ' + m.time_label;
    el.appendChild(meta);
    thread.appendChild(el);
    if (m.id && m.id > lastId) lastId = m.id;
  }

  var polling = false;
  async function poll() {
    if (polling || document.hidden) return;
    polling = true;
    try {
      var url = pollUrl + '?hold=' + encodeURIComponent(thread.dataset.hold)
        + '&thread=' + encodeURIComponent(thread.dataset.thread) + '&after=' + lastId;
      var res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      var data = await res.json();
      if (data.ok && data.messages && data.messages.length) {
        var stick = atBottom();
        data.messages.forEach(appendMsg);
        if (stick) window.scrollTo(0, document.body.scrollHeight);
      }
    } catch (_) { /* transient — retry next tick */ }
    polling = false;
  }
  setInterval(poll, 5000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    var ta = form.querySelector('textarea[name=body]');
    var btn = form.querySelector('button[type=submit]');
    var status = form.querySelector('.am-status');
    var body = (ta && ta.value || '').trim();
    if (!body) return;
    if (btn) btn.disabled = true;
    if (status) { status.style.color = ''; status.textContent = 'Sending…'; }
    try {
      var res = await fetch(pollUrl, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.fromEntries(new FormData(form).entries())),
      });
      var data = await res.json();
      if (data.ok) {
        if (data.message) appendMsg(data.message);
        if (ta) ta.value = '';
        if (status) status.textContent = '';
        window.scrollTo(0, document.body.scrollHeight);
      } else if (status) {
        status.style.color = '#b91c1c';
        status.textContent = data.error || 'Could not send. Please try again.';
      }
    } catch (_) {
      if (status) { status.style.color = '#b91c1c'; status.textContent = 'Network error. Please try again.'; }
    }
    if (btn) btn.disabled = false;
  });
})();
