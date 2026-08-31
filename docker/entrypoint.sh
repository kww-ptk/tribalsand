#!/bin/bash
# Container entrypoint: start the in-container periodic-job scheduler in the
# background (see docker/scheduler.sh — the AWS replacement for the Render cron
# service), then hand off to Apache in the foreground so it stays PID 1's child
# and controls the container lifecycle. If Apache exits, the container exits.
set -e

/usr/local/bin/scheduler.sh &

exec apache2-foreground
