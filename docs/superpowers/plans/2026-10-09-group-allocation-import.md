# Group allocation import — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** `docs/superpowers/specs/2026-10-09-group-allocation-import-design.md`.
**Architecture:** pure parsing/naming/label rules in `includes/group-import.php` (plus thin DB
resolvers + the create writer); one admin page `admin/import-group.php` (upload → preview →
create → links), listed as the "Group import" tab under Calendar. No migration.
**Tech:** PHP 8.2, PDO/pgsql, vanilla JS.

### Task 1: Pure helpers + tests
`tests/group_import_logic.php` first, then `includes/group-import.php`:
- `gi_parse_date(string): ?string` — `2026-10-24`, `24 Oct 2026`, `24/10/2026` → `Y-m-d`; `—`/blank → null.
- `gi_clean_name(string): string` — drop `(…)`, collapse spaces.
- `gi_split_names(string $guests, string $head): list<string>` — split on commas/“ & ”/“ and ”,
  clean, a single-word name equal to the head's first name → head's full name; `—` → [].
- `gi_booking_name(array $names, string $head): string` — head if in names, else first name, else head.
- `gi_resolve_label(string $venueSlug, string $label): ?array` — Maya Ilai only:
  `Studio No. 0NA` → `['kind'=>'studio','n'=>N]`; `Villa 0N: Room NXY` → `['kind'=>'villa','n'=>N,'component'=>double_a|double_b|bunk,'product'=>'maya-ilai-double'|'maya-ilai-bunk-room']`.
- `gi_parse_csv(string $csv): array{rows:list,errors:list}` — header-tolerant (property, room,
  guests, head, email, check_in, check_out), BOM-safe, `;`/`,`/tab.
- `gi_share_text(string $head, array $rooms, string $label): string` and `gi_batch_slug(string $label)`.
- [ ] tests FAIL → implement → PASS → commit.

### Task 2: Resolver + writer (DB)
- `gi_plan(array $rows, array $roomMap, ?array $scope): array` — per row: venue by slug/name,
  scope check, target unit/room/components, adults, names, status (`ready|taken|imported|skipped|unmapped|scope|error`),
  in-batch claims so two rows can't take the same unit/component.
- `gi_create(array $plan, string $label, int $adminId): array` — one transaction; for each ready
  row: `create_hold_with_block(…, 'confirmed', null, $components, $productRoomId)`,
  `require_checkin`, `guest_count`, roster, ledger 0 (`direct`, agent = label), audit; returns hold ids;
  saves batch setting.
- `gi_batch(string $slug): ?array`, `gi_batch_links(array $holdIds): array` (grouped by email).
- [ ] DB test in a rolled-back transaction (skips when Maya Ilai rooms are absent) → commit.

### Task 3: Admin page + nav
- `admin/import-group.php`; tab `Group import` in `admin_nav_definition()` Calendar item; room-map
  setting `group_import_room_map`.
- [ ] `php tests/admin_nav_logic.php`, `php tests/help_logic.php` pass; browser check; commit.

### Task 4: CSV of the wedding + docs
- Transcribe the PDF to `~/Downloads/chris-bini-wedding-allocation.csv` (NOT in the repo — guest PII).
- CLAUDE.md section. Commit.
