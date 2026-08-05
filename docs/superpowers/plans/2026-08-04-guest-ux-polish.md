# Guest UX Polish — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the silent redirect after a service/activity request with a "Request sent" popup (Manage request / Continue), collapse My Calendar by default, and move "What's on" up under "Your stay".

**Architecture:** Pure front-end. The success popup lives in `js/booking-manage.js` (which already receives the thread URL as `data.redirect`) with styles in `css/portal-app.css`; the two layout tweaks are one-line edits in `includes/app/_trip.php` and `includes/app/home.php`. No DB, no API change.

**Tech Stack:** Vanilla JS, vanilla CSS (portal tokens `--pa-ink`, `--pa-muted`, existing `.pa-btn`/`.pa-btn--primary`), PHP includes.

**Conventions:** Commit trailer `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. Branch `feature/guest-ux-polish` — no branch switch, no push.

---

## File map

| File | Change |
|------|--------|
| `js/booking-manage.js` | **Modify** — show the popup on `data.redirect`; add `showRequestSentModal()` |
| `css/portal-app.css` | **Modify** — append `.pa-modal*` styles |
| `includes/app/_trip.php` | **Modify** — My Calendar `<details>` starts collapsed |
| `includes/app/home.php` | **Modify** — new include order |

---

## Task 1: Success popup (JS + CSS)

**Files:**
- Modify: `js/booking-manage.js`
- Modify: `css/portal-app.css`

- [ ] **Step 1: Rewrite `js/booking-manage.js`**

Replace the entire file with:

```js
// Guest booking manage — fetch submit for add-on & change forms.
(function () {
  // Collapse any open concierge tile/form (after a request is sent).
  function collapseConciergeForms() {
    document.querySelectorAll('.cx-form.open').forEach(function (f) { f.classList.remove('open'); });
    document.querySelectorAll('.cx-tile[aria-expanded="true"]').forEach(function (t) { t.setAttribute('aria-expanded', 'false'); });
  }

  // "Request sent" popup — offered when the request opened a message thread.
  function showRequestSentModal(redirectUrl, form, btn) {
    var back = document.createElement('div');
    back.className = 'pa-modal-backdrop';
    back.innerHTML =
      '<div class="pa-modal" role="dialog" aria-modal="true" aria-label="Request sent">' +
        '<div class="pa-modal__icon" aria-hidden="true">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
        '</div>' +
        '<h3 class="pa-modal__title">Request sent</h3>' +
        '<p class="pa-modal__body">We’ve started a conversation for this — our team will confirm shortly.</p>' +
        '<div class="pa-modal__actions">' +
          '<button type="button" class="pa-btn pa-btn--primary" data-pa-manage>Manage request</button>' +
          '<button type="button" class="pa-btn" data-pa-continue>Continue</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(back);
    document.body.style.overflow = 'hidden';

    function done() { back.remove(); document.body.style.overflow = ''; document.removeEventListener('keydown', onKey); }
    function cont() {
      done();
      if (form) form.reset();
      if (btn) { btn.disabled = false; if (btn.dataset.label) btn.textContent = btn.dataset.label; }
      collapseConciergeForms();
    }
    function onKey(e) { if (e.key === 'Escape') cont(); }

    back.querySelector('[data-pa-manage]').addEventListener('click', function () { window.location = redirectUrl; });
    back.querySelector('[data-pa-continue]').addEventListener('click', cont);
    back.addEventListener('click', function (e) { if (e.target === back) cont(); });
    document.addEventListener('keydown', onKey);
  }

  document.querySelectorAll('form[data-bm]').forEach(function (form) {
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      var btn = form.querySelector('button[type=submit]');
      var status = form.querySelector('.bm-status');
      var payload = Object.fromEntries(new FormData(form).entries());
      if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = 'Sending…'; }
      try {
        var res = await fetch(form.getAttribute('action'), {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        var data = await res.json();
        if (data.ok) {
          if (data.redirect) {
            // A request that opened a message thread — offer Manage / Continue.
            showRequestSentModal(data.redirect, form, btn);
          } else {
            if (status) { status.textContent = form.getAttribute('data-bm-success') || 'Request sent — we’ll be in touch by email.'; status.className = 'bm-status ok'; }
            setTimeout(function () { window.location = window.location.href.split('#')[0]; }, 1200);
          }
        } else {
          if (status) { status.textContent = data.error || 'Something went wrong. Please try again.'; status.className = 'bm-status err'; }
          if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
          if (window.turnstile) window.turnstile.reset();
        }
      } catch (_) {
        if (status) { status.textContent = 'Network error. Please try again.'; status.className = 'bm-status err'; }
        if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
      }
    });
  });
})();
```

- [ ] **Step 2: Append the modal styles to `css/portal-app.css`**

```css
/* ── Request-sent popup ── */
.pa-modal-backdrop{position:fixed;inset:0;background:rgba(16,47,58,.55);display:flex;align-items:center;justify-content:center;padding:24px;z-index:1000;}
.pa-modal{background:#fff;border-radius:18px;max-width:360px;width:100%;padding:26px 22px;text-align:center;box-shadow:0 20px 60px rgba(16,47,58,.35);}
.pa-modal__icon{width:56px;height:56px;margin:0 auto 14px;border-radius:50%;background:#dcfce7;color:#166534;display:flex;align-items:center;justify-content:center;}
.pa-modal__icon svg{width:28px;height:28px;}
.pa-modal__title{font-family:'Cormorant Garamond',serif;font-size:24px;font-weight:500;margin:0 0 6px;color:var(--pa-ink);}
.pa-modal__body{font-size:14px;color:var(--pa-muted);line-height:1.6;margin:0 0 20px;}
.pa-modal__actions{display:flex;flex-direction:column;gap:8px;}
```

- [ ] **Step 3: Verify (local, in-app browser)**

Start the `tribalsand` dev server, open a seeded portal (`/booking.php?ref=…`). Tap **Make a request**, type something, send: the "Request sent" popup appears. **Manage request** navigates to `…&view=messages&thread=<id>`. Re-open the tile, send again, click **Continue**: the popup closes, the form resets, the tile collapses, and you stay on Home. Repeat via an **activity** request (Activities tab) → same popup. Check `read_console_messages` for JS errors (expect none).

- [ ] **Step 4: Commit**

```bash
git add js/booking-manage.js css/portal-app.css
git commit -m "feat(portal): request-sent popup with Manage request / Continue

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: My Calendar collapsed + Home reorder

**Files:**
- Modify: `includes/app/_trip.php`
- Modify: `includes/app/home.php`

- [ ] **Step 1: Collapse My Calendar by default**

In `includes/app/_trip.php`, change:

```php
<details class="pa-details" open>
```

to:

```php
<details class="pa-details">
```

- [ ] **Step 2: Reorder Home so "What's on" sits under "Your stay"**

Replace the body of `includes/app/home.php`:

```php
<?php /** Home — stay essentials + calendar + concierge + board. Expects $hold, $ref, $status. */ ?>
<?php include __DIR__ . '/_stay_essentials.php'; ?>
<?php include __DIR__ . '/_trip.php'; ?>
<?php include __DIR__ . '/_services.php'; ?>
<?php include __DIR__ . '/_greeting_board.php'; ?>
```

with:

```php
<?php /** Home — stay, what's on, calendar, concierge. Expects $hold, $ref, $status. */ ?>
<?php include __DIR__ . '/_stay_essentials.php'; ?>   <!-- Your stay -->
<?php include __DIR__ . '/_greeting_board.php'; ?>    <!-- What's on -->
<?php include __DIR__ . '/_trip.php'; ?>              <!-- My Calendar -->
<?php include __DIR__ . '/_services.php'; ?>          <!-- Need something? -->
```

- [ ] **Step 3: Verify (local)**

Confirm `php -l includes/app/_trip.php` and `php -l includes/app/home.php` are clean. Reload the portal Home: order is booking box → Your stay (open) → What's on → My Calendar (collapsed) → Need something?. Tapping My Calendar expands it.

- [ ] **Step 4: Commit**

```bash
git add includes/app/_trip.php includes/app/home.php
git commit -m "feat(portal): My Calendar collapsed by default; What's on under Your stay

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Regression

- [ ] **Step 1: Run the portal test suite**

Run: `php tests/portal_logic.php`
Expected: `ALL PASS` (no logic changed; confirms no regression).

- [ ] **Step 2: Lint the touched files**

Run: `php -l includes/app/_trip.php && php -l includes/app/home.php && node --check js/booking-manage.js 2>/dev/null || echo "no node; skip JS lint"`
Expected: PHP files clean. (JS lint optional — the browser verification in Task 1 is the real check.)

- [ ] **Step 3: Final review**

Use superpowers:requesting-code-review to review the branch against this plan and the spec; fix any findings before finishing the branch.

---

## Rollout

No migration. Ship and it's live.
