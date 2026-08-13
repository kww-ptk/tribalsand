# Handoff — check-in / check-out times

Everything below is state a fresh session cannot infer from the repo.

## Start here

1. Read `docs/superpowers/specs/2026-08-12-checkin-times-design.md` (the why)
2. Read `docs/superpowers/plans/2026-08-12-checkin-times.md` (the how — 7 tasks, 34 steps)
3. Execute it with **superpowers:subagent-driven-development**

Branch **`feat/checkin-times`** is already checked out and pushed, containing only those two
documents. No implementation has started. Task 1 is the first code.

## What this is

Valentina reported (in Italian) that check-in is 14:00–20:00 and check-out 10:00–11:00, but
neither appears anywhere in the system. A guest types "I arrive at 10:00", the wizard accepts it
silently, and they turn up expecting a room that is still occupied or being cleaned. She also
wants it said that early check-in and late check-out are available for a fee, subject to
availability.

**The decision that shapes everything:** `booking_checkin.arrival_at` is the **flight landing
time** in flight mode. The complaint is about **reaching the property** — a flight landing at
10:00 in Mombasa puts the guest at the villa around 12:00–13:00. So a new `property_arrival_time`
column is added and the window is checked against *that*, never the landing time.

Three decisions already taken with the requester — do not relitigate:

| Question | Decision |
|---|---|
| Which time to check | Ask both; warn on arrival at the property |
| Early arrival | **Warn, never block.** A block just makes guests enter a false time |
| Scope | One global setting, not per property |

## Repo conventions that have already caused production bugs

- **Never bind a PHP bool.** `PDO::ATTR_EMULATE_PREPARES` renders `false` as `''` and Postgres
  rejects it for a boolean column. Use `'TRUE'`/`'FALSE'`. This took down the check-in save in
  production (fixed in PR #56).
- **Server render and live JS must agree.** A pre-migration fallback existed in the JS but not the
  matching PHP, so the two disagreed. Task 5 Step 3 diffs the PHP and JS rules for exactly this.
- **Build time arithmetic in SQL, not PHP** where a DB clock is involved.
- Every migration-added column read sits behind a cached `*_supported()` guard.
- Tests are plain PHP scripts with a `check()` helper. `tests/checkin_logic.php` is the pure
  harness; `tests/frontdesk_logic.php` and `tests/portal_logic.php` are DB-backed.
  `tests/manage_logic.php` is explicitly no-DB — never put DB assertions there.

## Working-tree state

`.claude/launch.json` is modified and two `Archive*.zip` files are untracked. All three are
**pre-existing and unrelated**. Never `git add -A` or `git add .`.

## Test baseline

All suites end `ALL PASS` except `tests/team_logic.php`, which has **one** known failure —
`owner: home = dashboard`. It reproduces on `master`, is unrelated, and is tracked separately.
Do not try to fix it.

## Production deployment

`master` auto-deploys to Render. Two migrations are already applied to production:

```sql
ALTER TABLE booking_checkin ADD COLUMN IF NOT EXISTS arrival_mode TEXT, ADD COLUMN IF NOT EXISTS arrival_vehicle TEXT, ADD COLUMN IF NOT EXISTS arrival_note TEXT;
ALTER TABLE holds ALTER COLUMN expires_at DROP NOT NULL;
```

This plan adds a **third**, which the owner must run:

```sql
ALTER TABLE booking_checkin ADD COLUMN IF NOT EXISTS property_arrival_time TIME;
```

Order-independent — the support guard means the field is simply absent if the code ships first.

## Verification expectations

Do not claim a walkthrough you did not run. Admin pages cannot be logged into from this
environment (no credentials, and entering passwords is off-limits) — render the partials
server-side instead, with an owner session faked via `$_SESSION['admin_id']`. The guest portal
*can* be driven in the browser at 375px via the `tribalsand` launch config.

**Build fixtures that match the state being tested.** Two production bugs in this project got past
verification because the test data was further along than a real booking — a booking that already
had guest rows, and a transfer step clicked past without answering it. Clean up any fixture rows
you create.

## Recent context

A teammate (**ali-mango**) commits directly to `master`, which auto-deploys. They rewrote
`includes/app/activities.php` after the last merge — the activity request form is now hosted in an
overlay rather than expanding inside the card. Pull `master` before branching, and re-check
anything you assume about the portal's current shape.
