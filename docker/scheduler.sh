#!/bin/bash
# In-container periodic-job scheduler. There is no external cron service, so
# scheduled jobs run inside the app container. Launched in the background by
# docker/entrypoint.sh, so it inherits the full container environment
# (DATABASE_URL, ICAL_SYNC_SECRET, FX_SYNC_SECRET, …). That matters: cron does
# NOT inherit container env, which is why this is a plain background loop rather
# than crond — the PHP script and the loopback HTTP calls all see the same env
# the app sees.
#
# It calls the app's EXISTING, already-tested endpoints over loopback
# (127.0.0.1) instead of duplicating their logic, so nothing here can drift from
# what the admin "Sync Now" buttons do. The ?secret= param is used (not the
# Bearer header) purely for reliability — an internal loopback call never leaves
# the container, and the endpoints support the param for exactly this case.
#
# IDEMPOTENCY: if the ECS service ever runs more than one task, each task runs
# this loop, so every job fires once per task. All three jobs are safe to run
# concurrently/repeatedly — the iCal import skips blocks that already exist,
# hold expiry is a conditional UPDATE, and the FX sync upserts rates.
set -u

BASE="http://127.0.0.1"
APP_DIR="/var/www/html"
LOG="$APP_DIR/logs/scheduler.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG" 2>/dev/null || true; }

# Let Apache finish coming up before the first loopback call.
sleep 30
log "scheduler started"

# ── Job 1: expire stale holds every 5 minutes ───────────────────────────────
# Belt-and-suspenders: the app also expires inline on each availability check
# (find_available_unit → expire_stale_holds), so holds never linger even if this
# loop is down. Runs the PHP script directly (it has DB env from the parent).
(
  while true; do
    php "$APP_DIR/bin/ical-expire-holds.php" >> "$LOG" 2>&1 || log "expire-holds failed"
    sleep 300
  done
) &

# ── Job 2: OTA iCal import hourly + FX rates daily ──────────────────────────
# The iCal import is the one that actually matters for availability: it pulls
# Airbnb/Booking.com blocks into availability_blocks so those channels' bookings
# block the direct site (prevents double-booking). FX is display-only.
(
  fx_counter=0   # 0 → run FX on the first pass (refresh rates on deploy)
  while true; do
    # NOTE: call the CLEAN (extensionless) paths, not the .php ones — the app
    # 301-redirects *.php → the clean path, and these curls intentionally do NOT
    # follow redirects, so hitting .php would stop at the 301 and never sync.
    if [ -n "${ICAL_SYNC_SECRET:-}" ]; then
      curl -fsS --max-time 90 "$BASE/api/sync-ical?secret=$ICAL_SYNC_SECRET" >> "$LOG" 2>&1 \
        || log "ical sync failed"
    else
      log "ICAL_SYNC_SECRET not set — skipping iCal import"
    fi

    if [ "$fx_counter" -le 0 ]; then
      if [ -n "${FX_SYNC_SECRET:-}" ]; then
        curl -fsS --max-time 60 "$BASE/api/fx-sync?secret=$FX_SYNC_SECRET" >> "$LOG" 2>&1 \
          || log "fx sync failed"
      fi
      fx_counter=24   # ~once per 24 hourly cycles
    fi
    fx_counter=$((fx_counter - 1))

    sleep 3600
  done
) &

# ── Job 3: spawn recurring tasks daily ──────────────────────────────────────
# Turns job-timetable / recurring-task rules into real tasks on their cadence.
# Runs the PHP script directly (it has DB env from the parent). Idempotent — the
# unique (recurrence_id, due_date) index means a re-run never double-creates.
# Belt-and-suspenders: the Tasks board also spawns inline on load.
(
  while true; do
    php "$APP_DIR/bin/spawn-recurring-tasks.php" >> "$LOG" 2>&1 || log "spawn-recurring-tasks failed"
    sleep 86400
  done
) &

# ── Job 4: purge old clock-in photos daily ──────────────────────────────────
# Deletes stored punch photos older than 30 days, keeping the punch records.
# The photo is evidence for a disputed shift — a question asked within days or
# weeks, not years — so holding images of staff faces indefinitely serves no
# purpose and grows without bound at two punches a person a day.
# Runs the PHP script directly (it has DB env from the parent). Idempotent: only
# rows that still carry a photo_key are selected, so repeats do nothing.
(
  while true; do
    php "$APP_DIR/bin/purge-attendance-photos.php" >> "$LOG" 2>&1 || log "purge-attendance-photos failed"
    sleep 86400
  done
) &

wait
