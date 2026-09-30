#!/bin/bash
# LOCAL DEVELOPMENT ONLY — the app container's start command in docker-compose.yml.
# Production never runs this: its image starts docker/entrypoint.sh directly.
#
#  1. make the folders Apache writes to (the repo is bind-mounted over the image's copy)
#  2. build / update the database (bin/dev-setup.php — safe on every start)
#  3. hand over to the normal entrypoint: job scheduler + Apache, exactly like production
set -e
cd /var/www/html

mkdir -p logs assets/img/rooms /var/www/private/checkin
chmod 777 logs assets/img/rooms /var/www/private /var/www/private/checkin 2>/dev/null || true

echo "── Tribal Sand (local) — preparing the database…"
php bin/dev-setup.php

echo "── Ready:  site http://localhost:${APP_PORT:-8080}   ·   emails http://localhost:8025"
exec /usr/local/bin/entrypoint.sh
