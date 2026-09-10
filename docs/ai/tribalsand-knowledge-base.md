# Tribal Sand — AI Knowledge Base

> **What this is.** A human-maintained reference for the properties the AI talks
> about, plus the **room / capacity / units truth table** the live data must
> match. **The AI does not read this file at runtime.** Its knowledge comes from
> the live database via two paths:
>
> 1. **Tools** — structured facts, prices and availability, straight from the DB
>    (`includes/assistant-tools.php`). This is where every number comes from.
> 2. **RAG** — descriptive prose, embedded from the *live editable copy* by
>    `bin/reindex-content.php` into `content_embeddings` (not from this doc).
>
> So this doc is (a) the source-of-truth for Phase A reconciliation, and (b) an
> optional future RAG source. Keep it in step with `db/seed_rooms_2026.sql` and
> the owner's decisions. Reconstructed 2026-09-10 (the original was lost,
> uncommitted). The **brand narrative** section is a TODO — it needs the owner's
> March 2026 brand PDF and must not be invented.

## How the assistant is allowed to answer (hard rules, enforced in code)

- **ONE pricing path** — every price comes from `room_stay_quote()` /
  `ts_search_availability()`. Never a second calculation.
- **Read-only** — it quotes, describes and reports; it never books, holds or edits.
- **Facts via tools, prose via RAG** — availability/price/counts are tool calls;
  only descriptions are retrieved prose. The embeddings hold **no** prices.
- **Never invent** a date, price, room, combination, offer, dish or figure.
- **Nairobi-local dates.** **Scoped** — staff see only their assigned properties;
  guests see published properties; staff-only ops tools never reach the concierge.

## Room / capacity / units truth table

`capacity` = max occupancy of **one unit** of that room type. `units` = number of
bookable units (physical rooms) of that type. Search does `free_units × capacity`,
so a single-room suite MUST have exactly **1 active unit**. A NULL/0 capacity is
"unknown" and the room is skipped by guest-count search. **Whole-property**
("entire place") rooms back a buyout.

### Zuri — ocean suites + full-property buyout
| Room | slug | capacity | units | whole-property |
|------|------|:--:|:--:|:--:|
| Maji Suite | `zuri-maji` | 2 | 1 | – |
| Mwezi Suite | `zuri-mwezi` | 4 | 1 | – |
| Ua Suite | `zuri-ua` | 2 | 1 | – |
| Anga Suite | `zuri-anga` | 2 | 1 | – |
| Jua Suite | `zuri-jua` | 2 | 1 | – |
| Bahari Suite | `zuri-bahari` | 2 | 1 | – (⚠ may be dropped on prod) |
| Zuri — Full Property Buyout | `zuri-buyout` | 14 | 1 | ✔ |

### Maya Kobe — 5 suites + full-property buyout
| Room | slug | capacity | units | whole-property |
|------|------|:--:|:--:|:--:|
| Prestige Suite | `maya-kobe-prestige` | 4 | 1 | – |
| Haze Suite | `maya-kobe-haze` | 2 | 1 | – |
| Glow Suite | `maya-kobe-glow` | 2 | 1 | – |
| Tide Suite | `maya-kobe-tide` | 2 | 1 | – |
| Drift Suite | `maya-kobe-drift` | 2 | 1 | – |
| Maya Kobe — Full Property Buyout | `maya-kobe-buyout` | 16 | 1 | ✔ (12 without Prestige; 16 with) |

### Maya Ilai — 3 room types (adults 16+)  *(prod reality, per the 2026-09-10 audit)*
| Room | slug | capacity | units | whole-property |
|------|------|:--:|:--:|:--:|
| Superior Suite | `superior-suite` | 6 | 1 | – |
| Three-Bedroom Villa | `maya-ilai-villa` | 6 | **8** | – |
| Studio Apartment | `maya-ilai-studio` | 2 | **8** | – |

*Prod has no `maya-ilai-buyout` (the old seed did); Maya Ilai is booked by
room type, not as a whole-compound buyout. `superior-suite` IS a real published
room — the earlier "0 units, unpublish" note was based on a stale comment and
was wrong.*

### Entire-property villas (one whole-property room each)  *(capacities confirmed on prod)*
| Property | room slug | capacity | units | notes |
|----------|-----------|:--:|:--:|-------|
| Sandbox | `sandbox` | 8 | 1 | capacity was NULL on prod — set to 8 |
| My Amani | `my-amani-full-rental` | 10 | 1 | confirmed 10 on prod |
| Enkare Bofa | `enkare-bofa` | 10 | 1 | confirmed 10 on prod (no longer an open question) |

*Tribal Dunes is the solar site referenced by sustainability figures, not a
bookable stay.*

## Data state (2026-09-10 audit) — one systemic defect

The prod audit found **every room carries exactly one extra active unit** (single
suites show 2, the Maya Ilai villa/studio show 9) — the `add_availability`
migration's default-unit seed ran on top of the by-room seed. Plus two buyouts
(`maya-kobe-buyout`, `sandbox`) had a NULL capacity. Fix, booking-aware and
dry-run-first: **`bin/reconcile-capacity-units.php`** (deactivates only the empty
surplus unit per room; sets the two capacities), then re-run the audit. The three
earlier "owner decisions" are resolved: Enkare = 10, My Amani = 10, and
`superior-suite` is a real room (keep, drop its extra unit). See
`docs/superpowers/plans/2026-09-10-ai-system-awareness.md`.

## What the AI can answer today (and the tool behind it)

| Question kind | Tool |
|---------------|------|
| What's free for N pax, X→Y, and the price? | `check_availability`, `quote_stay` |
| Which name is a property vs a room? | `list_properties` |
| Room count / max occupancy / deposit / checkout / location | `property_facts` |
| Offers, which menus exist, table reservations | `whats_on` |
| Activities/tours + prices | `list_activities` |
| Actual dishes, prices, dietary options | `menu_details` |
| Sustainability figures | `sustainability_facts` |
| Paid services (transfers, laundry) | `list_services` |
| Show a price in another currency | `convert_currency` |
| Soonest available dates (flexible guest) | `find_next_availability` |
| Descriptions, amenities, policies, area guides | `search_property_info` (RAG) |
| **Staff:** revenue/occupancy; today's arrivals/departures | `occupancy_report`, `daily_operations` |

## Brand narrative — TODO (needs the March 2026 brand PDF)

The property stories, positioning, and voice should be captured here (and/or fed
to RAG) from the owner's brand document. **Do not fabricate this** — leave blank
until the source is supplied.
