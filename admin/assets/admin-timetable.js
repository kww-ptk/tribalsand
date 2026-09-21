/* Timetable grid — the task detail panel.
   The chip already carries its task as JSON (the week query fetched it), so
   opening the panel costs no request. Status buttons post FormData to the same
   endpoint the staff cards use, so one permission model serves both.

   Listeners are scoped to the grid table and the panel themselves (not
   document), matching admin-gallery.js's `grid`-scoped pattern — a
   document-level click listener would run for the lifetime of every admin
   page that loads this script, not just this one. Escape stays a document
   listener (it has to catch focus anywhere on the page) but backs off when
   focus is inside a form control, since the filter selects live alongside
   the panel. */
(function () {
  'use strict';
  var panel = document.getElementById('ttPanel');
  var grid = document.querySelector('.tt-grid');
  if (!panel || !grid) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* The page hands us the token on #ttPanel's data-csrf — this page has no
     form to read one from, the way admin-assistant.js and admin-gallery.js
     read theirs off a data attribute. */
  function token() { return panel.getAttribute('data-csrf') || ''; }

  function close() { panel.hidden = true; panel.innerHTML = ''; }

  function open(t) {
    var proc = t.procedure
      ? '<div style="margin-top:1rem"><strong style="font-size:13px">Procedure</strong><div class="tt-proc">' + esc(t.procedure) + '</div></div>'
      : '';
    var detail = t.detail ? '<p style="font-size:13px;color:#6b6256">' + esc(t.detail) + '</p>' : '';
    var when = t.time ? esc(t.time) : 'Anytime';
    panel.innerHTML =
      '<div class="tt-panel__head">' +
        '<h3>' + esc(t.title) + '</h3>' +
        '<button type="button" class="btn-icon btn-icon--outline" data-tt-close aria-label="Close">&times;</button>' +
      '</div>' +
      '<p class="text-muted tt-panel__meta">' + when + ' · ' + esc(t.assignee || 'Unassigned') + '</p>' +
      detail + proc +
      '<div class="tt-panel__actions">' +
        (t.status === 'done'
          ? '<button type="button" class="btn-icon btn-icon--outline" data-tt-set="todo" data-tt-id="' + t.id + '">Reopen</button>'
          : '<button type="button" class="btn-icon btn-icon--primary" data-tt-set="done" data-tt-id="' + t.id + '">Mark done</button>') +
      '</div>' +
      '<p data-tt-msg class="tt-panel__msg"></p>';
    panel.hidden = false;
  }

  grid.addEventListener('click', function (ev) {
    var chip = ev.target.closest ? ev.target.closest('.tt-chip') : null;
    if (!chip) return;
    try { open(JSON.parse(chip.getAttribute('data-task'))); } catch (e) { /* malformed payload: leave the panel shut */ }
  });

  panel.addEventListener('click', function (ev) {
    if (ev.target.closest && ev.target.closest('[data-tt-close]')) { close(); return; }

    var btn = ev.target.closest ? ev.target.closest('[data-tt-set]') : null;
    if (!btn) return;

    var body = new FormData();
    body.append('csrf_token', token());
    body.append('id', btn.getAttribute('data-tt-id'));
    body.append('status', btn.getAttribute('data-tt-set'));
    body.append('format', 'json');
    btn.disabled = true;

    /* verify_csrf() answers an expired session with a 403 and a PLAIN TEXT body,
       before task-action.php's JSON flag exists — so check the status before
       parsing, or .json() throws and we blame the network for a dead session. */
    fetch('/admin/task-action.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 403) return { ok: false, error: 'Your session expired. Reload the page and sign in again.' };
        return r.json().catch(function () { return { ok: false, error: 'That didn’t save. Please reload and try again.' }; });
      })
      .then(function (d) {
        if (d && d.ok) { window.location.reload(); return; }
        btn.disabled = false;
        var m = panel.querySelector('[data-tt-msg]');
        if (m) m.textContent = (d && d.error) || 'That didn’t save. Please reload and try again.';
      })
      .catch(function () {
        btn.disabled = false;
        var m = panel.querySelector('[data-tt-msg]');
        if (m) m.textContent = 'Network problem. Please try again.';
      });
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    var tag = (document.activeElement && document.activeElement.tagName) || '';
    if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') return;
    close();
  });
})();
