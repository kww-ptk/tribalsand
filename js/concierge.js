/* Public guest concierge (concierge.php).
 * Posts a question + short history to /api/concierge.php and renders the prose
 * answer plus, when present, an availability/quote card with a "Request to Book"
 * hand-off to the property page. Read-only: the endpoint only quotes/describes —
 * nothing here books or writes.
 *
 * Public-form guardrails handled here: sends the CSRF token, a Turnstile token
 * on the first message (when Turnstile is enabled), and a honeypot field. The
 * thread is kept in localStorage (per browser) so a refresh doesn't wipe it. */
(function () {
  var chat = document.getElementById('cncChat');
  var form = document.getElementById('cncForm');
  var input = document.getElementById('cncInput');
  var sendBtn = document.getElementById('cncSend');
  var empty = document.getElementById('cncEmpty');
  var suggest = document.getElementById('cncSuggest');
  var hp = form ? form.querySelector('input[name="website"]') : null;
  var cfBox = document.getElementById('cncCf');
  if (!chat || !form || !input) return;

  var endpoint = chat.getAttribute('data-endpoint');
  var csrf = chat.getAttribute('data-csrf');
  var STORE_KEY = 'ts_concierge_thread_v1';
  var thread = loadThread();
  var busy = false;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function scrollDown() { chat.scrollTop = chat.scrollHeight; }
  function loadThread() {
    try { var r = localStorage.getItem(STORE_KEY); var a = r ? JSON.parse(r) : []; return Array.isArray(a) ? a : []; }
    catch (e) { return []; }
  }
  function persist() { try { localStorage.setItem(STORE_KEY, JSON.stringify(thread)); } catch (e) {} }
  function hideEmpty() { if (empty) empty.hidden = true; }

  function apiHistory() {
    return thread.filter(function (e) { return e.role; })
      .map(function (e) { return { role: e.role === 'ai' ? 'assistant' : 'user', text: e.text }; })
      .slice(-10);
  }

  // The Turnstile token, if the widget is present. Cleared after each use so a
  // one-time token isn't replayed; Turnstile refreshes it for the next need.
  function turnstileToken() {
    var f = document.querySelector('[name="cf-turnstile-response"]');
    return f ? f.value : '';
  }

  function paintBubble(role, text) {
    hideEmpty();
    var el = document.createElement('div');
    el.className = 'cnc-msg cnc-msg--' + (role === 'user' ? 'user' : role === 'err' ? 'err' : 'ai');
    el.textContent = text;
    chat.appendChild(el); scrollDown();
    return el;
  }

  function money(amount, currency) {
    if (amount == null) return '—';
    var n = Number(amount);
    var s = n.toLocaleString('en-US', { maximumFractionDigits: n % 1 ? 2 : 0 });
    return (currency === 'USD' || !currency) ? '$' + s : esc(currency) + ' ' + s;
  }
  function row(label, valueHtml) { return '<tr><th>' + esc(label) + '</th><td class="num">' + valueHtml + '</td></tr>'; }
  function bookLink(slug, label) {
    if (!slug) return '';
    return '<a class="cnc-book" href="/' + encodeURIComponent(slug) + '.php#book">' + esc(label) + ' →</a>';
  }
  function buildCard(titleHtml, innerHtml) {
    hideEmpty();
    var card = document.createElement('div');
    card.className = 'cnc-card2';
    card.innerHTML = '<div class="ttl">' + titleHtml + '</div>' + innerHtml;
    chat.appendChild(card); scrollDown();
  }

  function paintCard(tr) {
    if (!tr || !tr.result) return;
    var r = tr.result;

    if (tr.tool === 'quote_stay') {
      var title = esc(r.room) + (r.property ? ' · ' + esc(r.property) : '');
      var rows =
        row('Dates', esc(r.check_in) + ' → ' + esc(r.check_out) + ' (' + r.nights + ' night' + (r.nights === 1 ? '' : 's') + ')') +
        row('Per night', money(r.price_per_night, r.currency)) +
        row('Total', '<strong>' + money(r.total, r.currency) + '</strong>') +
        row('Available', r.available ? '✓ Yes' : '✕ Not for these dates');
      var link = r.available ? bookLink(r.property_slug, 'Request to book at ' + (r.property || 'this property')) : '';
      buildCard(title, '<table><tbody>' + rows + '</tbody></table>' + link);
      return;
    }

    if (tr.tool === 'check_availability') {
      var props = r.properties || [];
      if (!props.some(function (p) { return (p.available_rooms || []).length; })) return;
      var head = esc(r.check_in) + ' → ' + esc(r.check_out) + ' · ' + r.guests + ' guest' + (r.guests === 1 ? '' : 's');
      var body = '';
      props.forEach(function (p) {
        var av = p.available_rooms || [];
        if (!av.length) return;
        body += '<div class="ttl" style="margin-top:10px">' + esc(p.property) + '</div>';
        body += '<table><thead><tr><th>Room</th><th class="num">Nights</th><th class="num">Total</th></tr></thead><tbody>';
        av.forEach(function (rm) {
          body += '<tr><td>' + esc(rm.room) + (rm.whole_property ? ' (whole)' : '') +
            '</td><td class="num">' + rm.nights + '</td><td class="num">' + money(rm.total, rm.currency) + '</td></tr>';
        });
        body += '</tbody></table>' + bookLink(p.slug, 'Request to book at ' + p.property);
      });
      buildCard(head, body);
    }
  }

  function setBusy(on) {
    busy = on;
    if (sendBtn) { sendBtn.disabled = on; sendBtn.classList.toggle('is-loading', on); }
    input.disabled = on;
  }

  var TA_MAX = 140;
  function autosize() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, TA_MAX) + 'px';
    input.style.overflowY = input.scrollHeight > TA_MAX ? 'auto' : 'hidden';
  }

  function ask(text) {
    if (busy) return;
    text = (text || '').trim();
    if (!text) return;
    if (hp && hp.value) return;   // honeypot filled → bot; do nothing

    var hist = apiHistory();
    paintBubble('user', text);
    thread.push({ role: 'user', text: text }); persist();
    input.value = ''; autosize(); setBusy(true);

    var typing = document.createElement('div');
    typing.className = 'cnc-typing';
    typing.textContent = 'Looking into it…';
    chat.appendChild(typing); scrollDown();

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text, history: hist, csrf_token: csrf, turnstile: turnstileToken(), website: hp ? hp.value : '' })
    })
      .then(function (res) { return res.json().then(function (j) { return { status: res.status, body: j }; }); })
      .then(function (r) {
        typing.remove();
        var b = r.body || {};
        if (!b.ok) {
          paintBubble('err', b.error || 'Sorry — I couldn’t answer just now. Please try again.');
          return;
        }
        // A verification passed (or wasn't needed) — hide the widget for the rest of the session.
        if (cfBox) cfBox.hidden = true;
        var answer = b.answer || '(no answer)';
        paintBubble('ai', answer);
        thread.push({ role: 'ai', text: answer });
        if (b.tool_result) { paintCard(b.tool_result); thread.push({ card: b.tool_result }); }
        persist();
      })
      .catch(function () { typing.remove(); paintBubble('err', 'Network error — please try again.'); })
      .finally(function () { setBusy(false); input.focus(); });
  }

  // Restore saved thread.
  if (thread.length) {
    hideEmpty();
    thread.forEach(function (e) {
      if (e.role) paintBubble(e.role, e.text);
      else if (e.card) paintCard(e.card);
    });
  }
  autosize();

  form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
  input.addEventListener('input', autosize);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(input.value); }
  });
  if (suggest) {
    suggest.addEventListener('click', function (e) {
      var chip = e.target.closest('.cnc-chip');
      if (chip) ask(chip.textContent);
    });
  }
})();
