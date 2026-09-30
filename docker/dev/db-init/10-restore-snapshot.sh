#!/bin/bash
# LOCAL DEVELOPMENT ONLY. Runs once, when the db container starts with an EMPTY
# volume (first `docker compose up`, or after `docker compose down -v`).
#
# If a production snapshot sits in db/dev/snapshots/ (mounted at /snapshots), it
# is restored here — with this container's own PostgreSQL 18 tools, which can
# read a dump taken from the PostgreSQL 18 production server. The app container
# then notices tables it did not create, scrubs personal data and records the
# migrations as applied (bin/dev-setup.php).
#
# No snapshot → nothing happens and dev-setup builds a demo database instead.
set -e

psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" <<'SQL'
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS vector;
SQL

snap=$(ls -1t /snapshots/*.dump /snapshots/*.sql /snapshots/*.sql.gz 2>/dev/null | head -n 1 || true)
[ -z "$snap" ] && { echo "[db-init] no snapshot in db/dev/snapshots — demo data will be used"; exit 0; }

echo "[db-init] restoring snapshot: $(basename "$snap")"
case "$snap" in
  *.dump)   pg_restore --no-owner --no-privileges -U "$POSTGRES_USER" -d "$POSTGRES_DB" "$snap" || echo "[db-init] pg_restore reported warnings (usually harmless role/extension notices)" ;;
  *.sql.gz) gunzip -c "$snap" | psql -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" ;;
  *.sql)    psql -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f "$snap" ;;
esac
echo "[db-init] snapshot restored — the app will scrub personal data on start"
