/* AI assistant chat panel (admin/assistant.php) — design A, Oct 2026.
 * Posts a question + short history to /api/assistant.php and renders the prose
 * answer plus, when present, a structured availability/quote card with
 * "Copy for guest" and "Build a quote". Read-only: the endpoint only ever
 * quotes — there is nothing here that books or writes.
 *
 * The thread and the "Recent" list are kept in localStorage (per browser) so a
 * refresh doesn't wipe them; "New question" empties the thread. Deliberately NOT
 * server-side: a quote is perishable (prices/availability change), there's no
 * cross-device need, and a quick lookup shouldn't leave records behind. */
(function () {
  var chat = document.getElementById('aiqChat');
  var form = document.getElementById('aiqForm');
  var input = document.getElementById('aiqInput');
  var sendBtn = document.getElementById('aiqSend');
  var empty = document.getElementById('aiqEmpty');
  var clearBtn = document.getElementById('aiqClear');
  var recentBox = document.getElementById('aiqRecent');
  var follow = document.getElementById('aiqFollow');
  var side = document.querySelector('.aiq-side');
  if (!chat || !form || !input) return;

  var endpoint = chat.getAttribute('data-endpoint');
  var csrf = chat.getAttribute('data-csrf');
  var STORE_KEY = 'ts_assistant_thread_v1';
  var RECENT_KEY = 'ts_assistant_recent_v1';
  var thread = load(STORE_KEY);   // [{role:'user'|'ai', text} | {card:{tool,result}}]
  var recent = load(RECENT_KEY);  // [question, …] newest first
  var busy = false;

  var SPARK = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 11l-6.1 2.2L12 19l-1.9-5.8L4 11l6.1-2.2Z"/></svg>';
  var CLOCK = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/></svg>';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function scrollDown() { chat.scrollTop = chat.scrollHeight; }

  // ── Persistence (localStorage, best-effort) ──
  function load(key) {
    try { var r = localStorage.getItem(key); var a = r ? JSON.parse(r) : []; return Array.isArray(a) ? a : []; }
    catch (e) { return []; }
  }
  function save(key, val) { try { localStorage.setItem(key, JSON.stringify(val)); } catch (e) {} }
  function persist() { save(STORE_KEY, thread); }
  function hideEmpty() { if (empty) empty.hidden = true; }
  function showEmpty() { if (empty) empty.hidden = false; }
  function showFollow(on) { if (follow) follow.hidden = !on; }

  // ── Recent questions (side panel) ──
  function remember(text) {
    recent = [text].concat(recent.filter(function (q) { return q !== text; })).slice(0, 8);
    save(RECENT_KEY, recent);
    paintRecent();
  }
  function paintRecent() {
    if (!recentBox) return;
    if (!recent.length) { recentBox.innerHTML = '<p class="aiq-recent__none">Your questions show up here.</p>'; return; }
    recentBox.innerHTML = recent.map(function (q) {
      return '<button type="button" data-ask="' + esc(q) + '" title="' + esc(q) + '">' + CLOCK + '<span>' + esc(q) + '</span></button>';
    }).join('');
  }

  // Turns sent back to the model for context (plain text only).
  function apiHistory() {
    // A question that got no answer is not context — re-sending it would stack
    // the same question on every retry.
    return thread.filter(function (e) { return e.role && !e.failed; })
      .map(function (e) { return { role: e.role === 'ai' ? 'assistant' : 'user', text: e.text }; })
      .slice(-10);
  }

  // ── Rendering ──
  function aiRow(inner) {
    var row = document.createElement('div');
    row.className = 'aiq-row';
    row.innerHTML = '<span class="aiq-av" aria-hidden="true">' + SPARK + '</span>';
    row.appendChild(inner);
    chat.appendChild(row);
    return row;
  }
  function paintBubble(role, text) {
    hideEmpty();
    var el = document.createElement('div');
    el.className = 'aiq-msg aiq-msg--' + (role === 'user' ? 'user' : role === 'err' ? 'err' : 'ai');
    el.textContent = text;
    if (role === 'user') chat.appendChild(el); else aiRow(el);
    scrollDown();
    return el;
  }

  function money(amount, currency) {
    if (amount == null) return '—';
    var n = Number(amount);
    var s = n.toLocaleString('en-US', { maximumFractionDigits: n % 1 ? 2 : 0 });
    return (currency === 'USD' || !currency) ? '$' + s : currency + ' ' + s;
  }
  function row(label, valueHtml) {
    return '<tr><th>' + esc(label) + '</th><td class="num">' + valueHtml + '</td></tr>';
  }
  function niceDate(ymd) {
    var d = new Date(String(ymd) + 'T12:00:00');
    return isNaN(d) ? String(ymd) : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
  }
  function buildCard(titleText, innerHtml, copyText) {
    hideEmpty();
    var card = document.createElement('div');
    card.className = 'aiq-card';
    card.innerHTML = '<div class="aiq-card__ttl">' + esc(titleText) + '</div>' + innerHtml +
      '<div class="aiq-card__ft">' +
        '<button type="button" class="btn-outline btn-sm" data-aiq-copy>Copy for guest</button>' +
        '<a href="/admin/quote-builder.php" class="btn-outline btn-sm">Build a quote</a>' +
      '</div>';
    card.querySelector('[data-aiq-copy]').addEventListener('click', function () {
      var btn = this;
      function done(ok) {
        btn.textContent = ok ? 'Copied' : 'Select and copy';
        setTimeout(function () { btn.textContent = 'Copy for guest'; }, 1600);
      }
      try { navigator.clipboard.writeText(copyText).then(function () { done(true); }, function () { done(false); }); }
      catch (e) { done(false); }
    });
    chat.appendChild(card);
    scrollDown();
  }

  // Render a compact availability / quote card from the structured tool_result.
  function paintCard(tr) {
    if (!tr || !tr.result) return;
    var r = tr.result;

    if (tr.tool === 'quote_stay') {
      var title = r.room + (r.property ? ' · ' + r.property : '');
      var nights = r.nights + ' night' + (r.nights === 1 ? '' : 's');
      var rows =
        row('Dates', esc(niceDate(r.check_in)) + ' → ' + esc(niceDate(r.check_out)) + ' (' + nights + ')') +
        row('Per night', esc(money(r.price_per_night, r.currency))) +
        row('Total', '<strong>' + esc(money(r.total, r.currency)) + '</strong>') +
        row('Available', r.available ? '✓ Yes' : '✕ Not for these dates');
      var copy = title + '\n' + niceDate(r.check_in) + ' – ' + niceDate(r.check_out) + ' (' + nights + ')\n' +
        'Total: ' + money(r.total, r.currency) + (r.available ? '' : '\n(Not available for these dates)');
      buildCard(title, '<table><tbody>' + rows + '</tbody></table>', copy);
      return;
    }

    if (tr.tool === 'check_availability') {
      var props = r.properties || [];
      var any = props.some(function (p) { return (p.available_rooms || []).length; });
      if (!any) return;   // the prose already carries the "nothing free" answer
      var head = 'Free ' + niceDate(r.check_in) + ' – ' + niceDate(r.check_out) +
        ' · ' + r.guests + ' guest' + (r.guests === 1 ? '' : 's');
      var body = '', copy = head + '\n';
      props.forEach(function (p) {
        var av = p.available_rooms || [];
        if (!av.length) return;
        body += '<div class="aiq-card__ttl">' + esc(p.property) + '</div>';
        body += '<table><thead><tr><th>Room</th><th class="num">Nights</th><th class="num">Total</th></tr></thead><tbody>';
        copy += '\n' + p.property + '\n';
        av.forEach(function (rm) {
          body += '<tr><td>' + esc(rm.room) + (rm.whole_property ? ' <span class="badge badge--grey" style="font-size:9px">whole</span>' : '') +
            '</td><td class="num">' + rm.nights + '</td><td class="num">' + esc(money(rm.total, rm.currency)) + '</td></tr>';
          copy += '• ' + rm.room + ' — ' + money(rm.total, rm.currency) + ' for ' + rm.nights + ' night' + (rm.nights === 1 ? '' : 's') + '\n';
        });
        body += '</tbody></table>';
      });
      buildCard(head, body, copy.trim());
    }
  }

  function setBusy(on) {
    busy = on;
    // Icon button — don't touch textContent (that would wipe the SVG); use the
    // shared .btn-icon.is-loading spinner state instead.
    if (sendBtn) { sendBtn.disabled = on; sendBtn.classList.toggle('is-loading', on); }
    input.disabled = on;
  }

  var TA_MAX = 140;   // must match .aiq-inputrow textarea max-height
  function autosize() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, TA_MAX) + 'px';
    // No scrollbar while it can still grow — only once it hits the cap.
    input.style.overflowY = input.scrollHeight > TA_MAX ? 'auto' : 'hidden';
  }

  function ask(text) {
    if (busy) return;
    text = (text || '').trim();
    if (!text) return;

    var hist = apiHistory();               // context BEFORE adding this turn
    paintBubble('user', text);
    var turn = { role: 'user', text: text };
    thread.push(turn); persist();
    function failed(msg) { turn.failed = true; persist(); paintBubble('err', msg); }
    remember(text);
    input.value = '';
    autosize();
    setBusy(true);
    showFollow(false);

    var typing = document.createElement('div');
    typing.className = 'aiq-typing';
    typing.setAttribute('role', 'status');
    typing.setAttribute('aria-label', 'Checking the calendar');
    typing.innerHTML = '<span></span><span></span><span></span>';
    var typingRow = aiRow(typing);
    scrollDown();

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text, history: hist, csrf_token: csrf })
    })
      .then(function (res) { return res.json().then(function (j) { return { status: res.status, body: j }; }); })
      .then(function (r) {
        typingRow.remove();
        var b = r.body || {};
        if (!b.ok) { failed(b.error || 'The assistant could not answer just now.'); return; }
        var answer = b.answer || '(no answer)';
        paintBubble('ai', answer);
        thread.push({ role: 'ai', text: answer });
        if (b.tool_result) { paintCard(b.tool_result); thread.push({ card: b.tool_result }); }
        persist();
        showFollow(true);
      })
      .catch(function () { typingRow.remove(); failed('Network error — please try again.'); })
      .finally(function () { setBusy(false); input.focus(); });
  }

  function clearThread() {
    thread = []; persist();
    chat.querySelectorAll('.aiq-msg, .aiq-card, .aiq-row').forEach(function (n) { n.remove(); });
    showEmpty(); showFollow(false); input.focus();
  }

  // ── Restore any saved thread on load ──
  if (thread.length) {
    hideEmpty();
    thread.forEach(function (e) {
      if (e.role) paintBubble(e.role, e.text);
      else if (e.card) paintCard(e.card);
    });
    showFollow(thread[thread.length - 1].role !== 'user');
  }
  paintRecent();
  autosize();   // set the initial one-line height (no scrollbar)

  // ── Wiring ──
  form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
  input.addEventListener('input', autosize);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); ask(input.value); }
  });
  // Sample questions, recent questions and follow-up chips all carry data-ask.
  [side, follow].forEach(function (box) {
    if (!box) return;
    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-ask]');
      if (b) ask(b.getAttribute('data-ask'));
    });
  });
  if (clearBtn) clearBtn.addEventListener('click', clearThread);

  // ── Deep link: /admin/assistant?message=… auto-fills and submits once. ──
  // The param is stripped from the URL afterwards so a refresh doesn't re-ask
  // (and a bookmarked link stays a one-shot, not a loop).
  try {
    var params = new URLSearchParams(window.location.search);
    var seed = params.get('message');
    if (seed && seed.trim()) {
      params.delete('message');
      var qs = params.toString();
      history.replaceState(history.state, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
      ask(seed);
    }
  } catch (e) {}
})();
