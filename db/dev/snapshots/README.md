# Production snapshots (local only — never committed)

Put ONE production database dump here to run localhost on real data.
Accepted: `*.dump` (pg_dump custom format — preferred), `*.sql`, `*.sql.gz`.
Everything in this folder except this README is git-ignored.

On the next **empty** database start it is restored, then `bin/dev-setup.php`
scrubs personal data (guest emails, phones, names, passport numbers, IPs,
signatures, secrets) and resets every login's password to `DEV_ADMIN_PASSWORD`.

```bash
docker compose down -v     # wipe the local database volume
docker compose up          # restores the newest file in this folder
```

How to take a snapshot: README.md → "Working with real (production) data".
The raw file contains real guest data: keep it off shared drives and chat,
and delete it when you are done.
