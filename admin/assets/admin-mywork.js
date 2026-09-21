/* My Work — staff day view. Progressive enhancement over the plain PRG forms:
   each task card is a real <form method="POST"> that already works with
   JavaScript off (task-action.php redirects back here and the flash shows).
   With JS on, the SAME form posts as FormData/JSON and the card patches in
   place on success — no navigation, no reload.

   This is the opposite trade from the admin timetable grid (Task 8), which
   reloads on success. That is fine for a manager occasionally acting from a
   desktop. It is wrong here: a staff member works down many tasks on a phone,
   often on a slow connection, and a full reload per tap is slow, costly, and
   loses their place in the list. admin-chat.js is the house precedent for
   patching a live view after a mutation instead of reloading it.

   Listener is delegated on the single task-list container (#mwTaskCard), the
   same scoped pattern as admin-timetable.js's grid-scoped listener — not a
   document-level listener that would run for the lifetime of every admin page
   that happens to load this script. */
(function () {
  'use strict';
  var root = document.getElementById('mwTaskCard');
  if (!root) return;

  /* The page hands us the token on #mwTaskCard's data-csrf attribute — read it
     from there, not from a form input, the same rule as admin-timetable.js
     and admin-assistant.js. */
  function token() { return root.getAttribute('data-csrf') || ''; }

  function setMsg(card, text) {
    var m = card.querySelector('[data-task-msg]');
    if (m) m.textContent = text || '';
  }

  /* Patch the card to reflect the new status: strike the title (via the
     is-done class, which the CSS already strikes through), grey the card, hide
     any "Overdue" indicator once done (a completed task is never late), and
     swap the button to its opposite action. Never navigate. */
  function patchCard(card, form, status) {
    var done = status === 'done';
    card.classList.toggle('is-done', done);
    if (done) {
      card.classList.remove('is-late');
      var overdue = card.querySelector('.mw-task__overdue');
      if (overdue) overdue.hidden = true;
    }

    var oldBtn = form.querySelector('button[name="status"]');
    if (!oldBtn) return;
    var btn = document.createElement('button');
    btn.type = 'submit';
    btn.name = 'status';
    if (done) {
      btn.value = 'todo';
      btn.className = 'mw-btn mw-btn--undo';
      btn.textContent = 'Reopen';
    } else {
      btn.value = 'done';
      btn.className = 'mw-btn mw-btn--done';
      btn.textContent = 'Mark done';
    }
    oldBtn.replaceWith(btn);
  }

  root.addEventListener('submit', function (ev) {
    var form = ev.target.closest ? ev.target.closest('[data-task-form]') : null;
    if (!form) return;
    ev.preventDefault();

    var card = form.closest('[data-task-card]');
    var idInput = form.querySelector('input[name="id"]');
    var btn = form.querySelector('button[name="status"]');
    if (!card || !idInput || !btn) return;

    var body = new FormData();
    body.append('csrf_token', token());
    body.append('id', idInput.value);
    body.append('status', btn.value);
    body.append('format', 'json');

    btn.disabled = true;
    setMsg(card, '');

    /* verify_csrf() answers an expired session with a 403 and a PLAIN TEXT
       body, before task-action.php's JSON flag exists — check the status
       BEFORE calling .json(), or a dead session reads as "Network problem"
       and a staff phone left open all shift taps forever for nothing. */
    fetch('/admin/task-action.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 403) return { ok: false, error: 'Your session expired. Reload the page and sign in again.' };
        return r.json().catch(function () { return { ok: false, error: 'That didn’t save. Please reload and try again.' }; });
      })
      .then(function (d) {
        btn.disabled = false;
        if (d && d.ok) {
          patchCard(card, form, d.status);
        } else {
          setMsg(card, (d && d.error) || 'That didn’t save. Please reload and try again.');
        }
      })
      .catch(function () {
        btn.disabled = false;
        setMsg(card, 'Network problem. Please try again.');
      });
  });
})();
