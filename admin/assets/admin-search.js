/**
 * Admin page search (Ctrl+K / ⌘K, or "/" outside a text field).
 *
 * The index is printed by admin_nav_search_html() from the RESOLVED sidebar, so
 * it only ever lists pages this account can open. Results are plain <a href>
 * links: the admin shell (admin-nav.js) claims the click, so opening a result
 * swaps the page without a reload exactly like a sidebar link. The window lives
 * outside .admin-content, so page swaps never remove it — bind once per window.
 */
(function () {
  if (window.__navSearchBound) return;
  window.__navSearchBound = true;

  var root = document.getElementById('navSearch');
  var input = document.getElementById('navSearchInput');
  var list = document.getElementById('navSearchList');
  var data = document.getElementById('navSearchIndex');
  if (!root || !input || !list || !data) return;

  var idx;
  try { idx = JSON.parse(data.textContent); } catch (e) { return; }
  var items = idx.items || [], icons = idx.icons || {};
  var RECENT_KEY = 'ts_navsearch_recent_v1';
  var results = [], active = 0, lastFocus = null;

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function norm(s) { return String(s || '').toLowerCase(); }
  function icon(name) {
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (icons[name] || '') + '</svg>';
  }

  function loadRecent() {
    try { var a = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); return Array.isArray(a) ? a : []; }
    catch (e) { return []; }
  }
  function remember(href) {
    var r = [href].concat(loadRecent().filter(function (h) { return h !== href; })).slice(0, 5);
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(r)); } catch (e) {}
  }

  // Is every character of `q` in `s`, in order? (catches "qbld" → Quote builder)
  function subseq(q, s) {
    var j = 0;
    for (var i = 0; i < s.length && j < q.length; i++) if (s.charAt(i) === q.charAt(j)) j++;
    return j === q.length;
  }

  // Score one entry against the query; -1 = no match. Every word typed must
  // match somewhere; the page name counts most, then its group and keywords.
  function score(it, q, words) {
    var t = norm(it.t), g = norm(it.g), k = norm(it.k), total = 0;
    if (t === q) total += 200;
    else if (t.indexOf(q) === 0) total += 120;
    for (var i = 0; i < words.length; i++) {
      var w = words[i];
      if ((' ' + t).indexOf(' ' + w) !== -1) total += 40;        // a word of the name starts with it
      else if (t.indexOf(w) !== -1) total += 20;
      else if ((' ' + g + ' ' + k).indexOf(' ' + w) !== -1) total += 10;
      else if ((g + ' ' + k).indexOf(w) !== -1) total += 5;
      else if (w.length > 2 && subseq(w, t)) total += 2;
      else return -1;
    }
    return total;
  }

  function mark(text, words) {
    // Highlight the first occurrence of each typed word in the page name.
    var lower = text.toLowerCase(), spans = [];
    words.forEach(function (w) {
      var at = lower.indexOf(w);
      if (at !== -1) spans.push([at, at + w.length]);
    });
    if (!spans.length) return esc(text);
    spans.sort(function (a, b) { return a[0] - b[0]; });
    var out = '', pos = 0;
    spans.forEach(function (s) {
      if (s[0] < pos) return;
      out += esc(text.slice(pos, s[0])) + '<mark>' + esc(text.slice(s[0], s[1])) + '</mark>';
      pos = s[1];
    });
    return out + esc(text.slice(pos));
  }

  function render() {
    var q = norm(input.value).trim();
    var words = q.split(/\s+/).filter(Boolean);
    var html = '';
    if (!q) {
      var byHref = {};
      items.forEach(function (it) { byHref[it.h] = it; });
      var recent = loadRecent().map(function (h) { return byHref[h]; }).filter(Boolean);
      var rest = items.filter(function (it) { return recent.indexOf(it) === -1; });
      results = recent.concat(rest);
      if (recent.length) html += '<div class="navsearch__sect">Recent</div>' + recent.map(row).join('');
      html += '<div class="navsearch__sect">All pages</div>' + rest.map(function (it, i) { return row(it, recent.length + i); }).join('');
    } else {
      var scored = items.map(function (it, i) { return { it: it, s: score(it, q, words), i: i }; })
        .filter(function (r) { return r.s >= 0; });
      // Letters-in-order guesses ("qbld") only when nothing matches properly —
      // otherwise "cal" would list Catalogue under Calendar.
      var strong = scored.filter(function (r) { return r.s >= words.length * 5; });
      results = (strong.length ? strong : scored)
        .sort(function (a, b) { return b.s - a.s || a.i - b.i; })
        .map(function (r) { return r.it; });
      html = results.length
        ? results.map(function (it, i) { return row(it, i, words); }).join('')
        : '<p class="navsearch__none">No page called “' + esc(input.value.trim()) + '”. Try another word, like calendar, rates or invoices.</p>';
    }
    list.innerHTML = html;
    list.scrollTop = 0;
    active = 0;
    paintActive();
  }

  function row(it, i, words) {
    i = i || 0;
    return '<a class="navsearch__item" role="option" id="navsr-' + i + '" data-i="' + i + '" href="' + esc(it.h) + '"' +
      (it.b ? ' target="_blank" rel="noopener"' : '') + '>' +
      '<span class="navsearch__ic">' + icon(it.i) + '</span>' +
      '<span class="navsearch__t">' + (words ? mark(it.t, words) : esc(it.t)) + '</span>' +
      '<span class="navsearch__g">' + esc(it.g) + '</span></a>';
  }

  function paintActive() {
    var nodes = list.querySelectorAll('.navsearch__item');
    nodes.forEach(function (n) { n.classList.toggle('is-active', +n.getAttribute('data-i') === active); });
    var cur = list.querySelector('.navsearch__item.is-active');
    if (cur) { cur.scrollIntoView({ block: 'nearest' }); input.setAttribute('aria-activedescendant', cur.id); }
    else input.removeAttribute('aria-activedescendant');
  }

  function open() {
    if (!root.hidden) { input.select(); return; }
    lastFocus = document.activeElement;
    root.hidden = false;
    document.body.classList.add('navsearch-open');
    input.value = '';
    render();
    input.focus();
  }
  function close() {
    if (root.hidden) return;
    root.hidden = true;
    document.body.classList.remove('navsearch-open');
    if (lastFocus && lastFocus.focus && document.contains(lastFocus)) lastFocus.focus();
  }

  function isEditable(el) {
    if (!el) return false;
    var tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
  }

  document.addEventListener('keydown', function (e) {
    var k = e.key;
    if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (k === 'k' || k === 'K')) {
      e.preventDefault();
      root.hidden ? open() : close();
      return;
    }
    if (k === '/' && root.hidden && !e.ctrlKey && !e.metaKey && !e.altKey && !isEditable(e.target)) {
      e.preventDefault();
      open();
    }
  });

  input.addEventListener('input', render);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); if (results.length) { active = (active + 1) % results.length; paintActive(); } }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (results.length) { active = (active - 1 + results.length) % results.length; paintActive(); } }
    else if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'Enter') {
      e.preventDefault();
      var cur = list.querySelector('.navsearch__item.is-active');
      if (cur) cur.click();   // the shell's click listener swaps the page
    }
  });
  list.addEventListener('mousemove', function (e) {
    var a = e.target.closest('.navsearch__item');
    if (a && +a.getAttribute('data-i') !== active) { active = +a.getAttribute('data-i'); paintActive(); }
  });
  // Bubble phase on the list: runs before the shell's window listener, which
  // then swaps the page. We only remember and close.
  list.addEventListener('click', function (e) {
    var a = e.target.closest('.navsearch__item');
    if (!a) return;
    remember(a.getAttribute('href'));
    close();
  });

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-navsearch-open]')) { e.preventDefault(); open(); return; }
    if (e.target.closest('[data-navsearch-close]')) { e.preventDefault(); close(); }
  });
})();
