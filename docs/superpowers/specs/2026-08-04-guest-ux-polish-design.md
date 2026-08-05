# Guest UX polish — success popup + Home tweaks

**Date:** 2026-08-04
**Status:** Approved design — ready for planning
**Sub-project:** A of five (from the larger user+admin request batch). B–E are designed separately later.

## Problem

Three small guest-portal rough edges:
1. Submitting a concierge **service** or an **activity** request silently flashes a status line then auto-redirects to the request's Messages thread — jarring, and it yanks the guest away with no choice.
2. **My Calendar** opens expanded on load, adding noise above the fold.
3. **"What's on"** (the guest board) sits at the very bottom of Home, below the concierge tiles, where guests rarely scroll.

## Goal

A confirmation popup with a clear next step, a calmer default Home, and a more prominent "What's on".

Non-goals: changing request submission logic, the thread-seeding, or any DB; touching non-request forms' behaviour.

## Decisions (from brainstorming)

- Popup applies to any request that returns a thread link (`data.redirect`) — i.e. concierge **services** and **activity** requests. Other `data-bm` forms (change dates, add-to-plan) keep their current inline confirmation.
- Popup buttons: **Manage request** (primary → the thread) and **Continue** (secondary → dismiss, stay on Home).
- **My Calendar** starts **collapsed**; **Your stay** stays **open**.
- New Home order: booking box → **Your stay** → **What's on** → **My Calendar** → **Need something?**.

## Design

### 1. Success popup (`js/booking-manage.js` + `css/portal-app.css`)

Today, `booking-manage.js`'s success branch is:

```js
if (data.ok) {
  if (status) { status.textContent = form.getAttribute('data-bm-success') || 'Request sent — …'; status.className = 'bm-status ok'; }
  var next = data.redirect;
  setTimeout(function () { window.location = next ? next : window.location.href.split('#')[0]; }, 1200);
}
```

New behaviour:
- **If `data.redirect` is present** → show a modal (no auto-redirect):
  - ✓ icon, title **"Request sent"**, body **"We've started a conversation for this — our team will confirm shortly."**
  - **Manage request** button → `window.location = data.redirect`.
  - **Continue** button (and backdrop click / Escape / ✕) → dismiss the modal, `form.reset()`, re-enable the submit button, and collapse any open concierge tile/form (`.cx-form.open` → remove `open`; `.cx-tile[aria-expanded="true"]` → `false`). Guest stays on Home.
- **If no `data.redirect`** → unchanged: inline `.bm-status ok` flash, then reload after 1200 ms.

The modal is built in JS (a `showRequestSentModal(redirectUrl, form, btn)` helper inside `booking-manage.js`) using app-native markup: `.pa-modal-backdrop` > `.pa-modal` with `.pa-modal__icon`, `.pa-modal__title`, `.pa-modal__body`, and a `.pa-modal__actions` row of `.pa-btn pa-btn--primary` (Manage request) + `.pa-btn` (Continue). Accessibility: `role="dialog"`, `aria-modal="true"`, labelled by the title; Escape and backdrop-click close (= Continue); body scroll locked while open. Styles added to `css/portal-app.css` (`.pa-modal*`), matching the portal's teal/cream tokens.

This reuses the existing `data-redirect` contract (already returned by `api/booking-addon.php` for every request kind), so **both** the concierge service forms *and* the activity request form get the popup with no API change.

### 2. My Calendar collapsed (`includes/app/_trip.php`)

Change the wrapper `<details class="pa-details" open>` to `<details class="pa-details">` (drop `open`). The summary/label and collapse behaviour are unchanged.

### 3. "What's on" after "Your stay" (`includes/app/home.php`)

Reorder the includes from `_stay_essentials → _trip → _services → _greeting_board` to:

```php
<?php include __DIR__ . '/_stay_essentials.php'; ?>   <!-- Your stay -->
<?php include __DIR__ . '/_greeting_board.php'; ?>    <!-- What's on -->
<?php include __DIR__ . '/_trip.php'; ?>              <!-- My Calendar -->
<?php include __DIR__ . '/_services.php'; ?>          <!-- Need something? -->
```

(The booking box / status-header is included by `booking.php` before `home.php`, so it stays on top. `_greeting_board` already renders nothing when there are no board posts.)

## Error handling / edge cases

- **Popup on a form with no redirect**: impossible for service/activity (API always returns a redirect on success), but the `if (data.redirect)` guard means any redirect-less success safely falls back to the old inline flash.
- **Double submit**: the submit button is disabled during the request (existing behaviour); Continue re-enables it after reset.
- **Escaping**: modal title/body are static strings; `data.redirect` is a server-signed URL assigned to `window.location` (not injected into HTML).
- **No JS**: unchanged from today (the forms are `fetch`-driven already; without JS they don't submit — pre-existing behaviour, out of scope).

## Testing

No DB/logic change, so no new `tests/*.php`. `php tests/portal_logic.php` must still pass (regression). Manual verification in the in-app browser:
- Submit a **service** (e.g. Make a request) → popup appears; **Manage request** opens the thread; re-submit and **Continue** dismisses, resets the form, collapses the tile, stays on Home.
- Submit an **activity** request → same popup.
- Home order reads booking box → Your stay (open) → What's on → My Calendar (collapsed) → Need something?.

## Rollout

No migration. Ship and it's live.
