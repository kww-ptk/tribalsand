/* Availability assistant chat panel (admin/assistant.php).
 * Posts a question + short history to /api/assistant.php and renders the prose
 * answer plus, when present, a structured availability/quote card. Read-only:
 * the endpoint only ever quotes — there is nothing here that books or writes.
 *
 * The thread is persisted to localStorage (per browser) so a refresh doesn't
 * wipe it; a Clear button empties it. Deliberately NOT server-side: a quote is
 * perishable (prices/availability change), there's no cross-device need, and a
 * quick lookup shouldn't leave records behind. */
(function () {
  var chat = document.getElementById('aiqChat');
  var form = document.getElementById('aiqForm');
  var input = document.getElementById('aiqInput');
  var sendBtn = document.getElementById('aiqSend');
  var empty = document.getElementById('aiqEmpty');
  var suggest = document.getElementById('aiqSuggest');
  var clearBtn = document.getElementById('aiqClear');
  if (!chat || !form || !input) return;

  var endpoint = chat.getAttribute('data-endpoint');
  var csrf = chat.getAttribute('data-csrf');
  var STORE_KEY = 'ts_assistant_thread_v1';
  var thread = loadThread();   // [{role:'user'|'ai', text} | {card:{tool,result}}]
  var busy = false;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function scrollDown() { chat.scrollTop = chat.scrollHeight; }

  // ── Persistence (localStorage, best-effort) ──
  function loadThread() {
    try { var r = localStorage.getItem(STORE_KEY); var a = r ? JSON.parse(r) : []; return Array.isArray(a) ? a : []; }
    catch (e) { return []; }
  }
  function persist() { try { localStorage.setItem(STORE_KEY, JSON.stringify(thread)); } catch (e) {} }
  function hideEmpty() { if (empty) empty.hidden = true; }
  function showEmpty() { if (empty) empty.hidden = false; }
  function refreshClear() { if (clearBtn) clearBtn.hidden = thread.length === 0; }

  // Turns sent back to the model for context (plain text only).
  function apiHistory() {
    return thread.filter(function (e) { return e.role; })
      .map(function (e) { return { role: e.role === 'ai' ? 'assistant' : 'user', text: e.text }; })
      .slice(-10);
  }

  // ── Rendering ──
  function paintBubble(role, text) {
    hideEmpty();
    var el = document.createElement('div');
    el.className = 'aiq-msg aiq-msg--' + (role === 'user' ? 'user' : role === 'err' ? 'err' : 'ai');
    el.textContent = text;
    chat.appendChild(el);
    scrollDown();
    return el;
  }

  function money(amount, currency) {
    if (amount == null) return '—';
    var n = Number(amount);
    var s = n.toLocaleString('en-US', { maximumFractionDigits: n % 1 ? 2 : 0 });
    return (currency === 'USD' || !currency) ? '$' + s : esc(currency) + ' ' + s;
  }
  function row(label, valueHtml) {
    return '<tr><th>' + esc(label) + '</th><td class="num">' + valueHtml + '</td></tr>';
  }
  function buildCard(titleText, innerHtml) {
    hideEmpty();
    var card = document.createElement('div');
    card.className = 'aiq-card';
    card.innerHTML = '<div class="aiq-card__ttl">' + esc(titleText) + '</div>' + innerHtml;
    chat.appendChild(card);
    scrollDown();
  }

  // Render a compact availability / quote card from the structured tool_result.
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
      buildCard(title, '<table><tbody>' + rows + '</tbody></table>');
      return;
    }

    if (tr.tool === 'check_availability') {
      var props = r.properties || [];
      var any = props.some(function (p) { return (p.available_rooms || []).length; });
      if (!any) return;   // the prose already carries the "nothing free" answer
      var head = esc(r.check_in) + ' → ' + esc(r.check_out) +
        ' · ' + r.guests + ' guest' + (r.guests === 1 ? '' : 's');
      var body = '';
      props.forEach(function (p) {
        var av = p.available_rooms || [];
        if (!av.length) return;
        body += '<div class="aiq-card__ttl">' + esc(p.property) + '</div>';
        body += '<table><thead><tr><th>Room</th><th class="num">Nights</th><th class="num">Total</th></tr></thead><tbody>';
        av.forEach(function (rm) {
          body += '<tr><td>' + esc(rm.room) + (rm.whole_property ? ' <span class="badge badge--grey" style="font-size:9px">whole</span>' : '') +
            '</td><td class="num">' + rm.nights + '</td><td class="num">' + money(rm.total, rm.currency) + '</td></tr>';
        });
        body += '</tbody></table>';
      });
      buildCard(head, body);
    }
  }

  function setBusy(on) {
    busy = on;
    // Icon button — don't touch textContent (that would wipe the SVG); use the
    // shared .btn-icon.is-loading spinner state instead.
    if (sendBtn) { sendBtn.disabled = on; sendBtn.classList.toggle('is-loading', on); }
    input.disabled = on;
  }

  var TA_MAX = 140;   // must match .aiq-composer textarea max-height
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
    thread.push({ role: 'user', text: text }); persist(); refreshClear();
    input.value = '';
    autosize();
    setBusy(true);

    var typing = document.createElement('div');
    typing.className = 'aiq-typing';
    typing.setAttribute('role', 'status');
    typing.setAttribute('aria-label', 'Checking the calendar');
    typing.innerHTML = '<span></span><span></span><span></span>';
    chat.appendChild(typing);
    scrollDown();

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text, history: hist, csrf_token: csrf })
    })
      .then(function (res) { return res.json().then(function (j) { return { status: res.status, body: j }; }); })
      .then(function (r) {
        typing.remove();
        var b = r.body || {};
        if (!b.ok) { paintBubble('err', b.error || 'The assistant could not answer just now.'); return; }
        var answer = b.answer || '(no answer)';
        paintBubble('ai', answer);
        thread.push({ role: 'ai', text: answer });
        if (b.tool_result) { paintCard(b.tool_result); thread.push({ card: b.tool_result }); }
        persist(); refreshClear();
      })
      .catch(function () { typing.remove(); paintBubble('err', 'Network error — please try again.'); })
      .finally(function () { setBusy(false); input.focus(); });
  }

  function clearThread() {
    thread = []; persist();
    chat.querySelectorAll('.aiq-msg, .aiq-card, .aiq-typing').forEach(function (n) { n.remove(); });
    showEmpty(); refreshClear(); input.focus();
  }

  // ── Restore any saved thread on load ──
  if (thread.length) {
    hideEmpty();
    thread.forEach(function (e) {
      if (e.role) paintBubble(e.role, e.text);
      else if (e.card) paintCard(e.card);
    });
  }
  refreshClear();
  autosize();   // set the initial one-line height (no scrollbar)

  // ── Wiring ──
  form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
  input.addEventListener('input', autosize);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(input.value); }
  });
  if (suggest) {
    suggest.addEventListener('click', function (e) {
      var chip = e.target.closest('.aiq-chip');
      if (chip) ask(chip.textContent);
    });
  }
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
      history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
      ask(seed);
    }
  } catch (e) {}
})();
