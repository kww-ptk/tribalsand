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

### Maya Ilai — 2 multi-unit room types + full-compound buyout (adults 16+)
| Room | slug | capacity | units | whole-property |
|------|------|:--:|:--:|:--:|
| Three-Bedroom Villa | `maya-ilai-villa` | 6 | **8** | – |
| Studio Apartment | `maya-ilai-studio` | 2 | **8** | – |
| Maya Ilai — Full Compound Buyout | `maya-ilai-buyout` | 48 | 1 | ✔ |

### Entire-property villas (one whole-property room each)
| Property | capacity | units | notes |
|----------|:--:|:--:|-------|
| Sandbox | 8 | 1 | "sleeps up to 8" (4 bedrooms) |
| My Amani | 10 | 1 | ⚠ provisional — **confirm on prod** |
| Enkare Bofa | **?** | 1 | ⚠ **owner decision** — no seed value |

*Tribal Dunes is the solar site referenced by sustainability figures, not a
bookable stay.*

## Open data decisions (block a fully-correct AI until resolved on prod)

1. **Enkare Bofa capacity** — set the real whole-property occupancy.
2. **My Amani capacity** — confirm the provisional 10.
3. **`maya_ilai/superior-suite`** — a prod room with 0 active units (not in the
   current seed): give it a unit or unpublish it.

Run `bin/audit-capacity-units.php` on prod to see the live state, then reconcile
with `db/backfill_room_capacity.sql`. See
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
