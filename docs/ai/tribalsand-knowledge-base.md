# Tribal Sand — AI Knowledge Base

> **What this is.** The single reference for what the assistant/concierge knows
> about Tribal Sand: the brand, every property and who it suits, the dining and
> activities, sustainability, the **room / capacity / units truth table**, and —
> at the end — **how the agent should suggest and behave**.
>
> **How the AI actually learns this.** Two live paths, not this file at runtime:
> 1. **Tools** — structured facts, prices and availability, straight from the DB.
>    Every number the AI states comes from a tool.
> 2. **RAG** — descriptive prose, embedded from the *live editable copy* by
>    `bin/reindex-content.php`.
>
> So use this doc three ways: (a) a human reference; (b) the source you paste the
> tone + suggestion guidance from into **Admin → AI settings** (`ai_persona_guest`,
> `ai_extra_knowledge`) so the live AI adopts it; (c) a future RAG source. Brand
> content is from the owner's *Tribal Sand Knowledge Base, 1 March 2026*.

---

## 1. Brand at a glance

- **Tribal Sand** — a sustainable, luxury coastal hospitality **ecosystem** in
  **Watamu, Kilifi and Vipingo, Kenya**. Not a set of isolated properties: an
  interconnected beachfront system of stays, dining, coworking, watersports,
  wellness and community.
- **Positioning:** *Sustainable luxury on the Kenyan coast* for affluent,
  environmentally conscious, experience-driven travellers who value privacy,
  community, wellbeing, comfort and authentic coastal life.
- **Philosophy / tagline:** **"Kenya as it was meant to be experienced."**
  Authenticity · Adventure · Comfort · Sustainability · Privacy · Community.
- **Differentiators:** an integrated lifestyle ecosystem; direct beachfront with
  uninterrupted ocean views; **infrastructure-led sustainability** (solar,
  desalinated water, rainwater harvesting, beach cleanups, coral growing);
  flexible formats from ultra-private villas to large group compounds; an active
  coastal identity (two kite schools, watersports, golf proximity in Vipingo).
- **Concierge:** Tribal Sand can help organise every part of a guest's holiday —
  **safaris and additional travel arrangements** included.

**Tribal Dunes (Kilifi)** is the heart of the ecosystem — one large beachfront
property, fully solar-powered, on desalinated ocean water, containing **Maya Kobe**
(hotel), **Maya Ilai** (eco compound), **Off Duty** (coworking hotel), **Somewhere
Café**, **Tribal Table** (restaurant) and **Tribal Kite School Kilifi**, all within
walking distance.

---

## 2. Properties — profiles, facts & who they suit

*(Operational facts from the Internal Property Sales Book; audience from the brand
doc; capacity/units are the live truth table in §3. Every stay is **non-smoking**;
**no property has in-room TVs**; a **baby cot + high chair** are available on
request at all of them.)*

### Zuri — Watamu · luxury beachfront boutique hotel
- **Sleeps 14 · 6 suites · Bed & Breakfast · à la carte meals + bar.** Day use
  available from 1 July 2026.
- **Suites:** 3 Double (king) · 1 Twin · 1 **Family Suite** (1 double + 2 singles,
  max 4) · 1 Double Master.
- **In-room:** mini-fridge, kettle, coffee/tea station, bottled water, safe,
  hairdryer, AC + ceiling fan, mosquito net, toiletries, WiFi (no TV).
- **Facilities:** beachfront, pool (with towels), direct beach access, dedicated
  **massage area**. Private staff accommodation at extra cost (room-only);
  nanny/babysitter/driver accommodation at extra cost.
- **Positioning:** intimate boutique beachfront hotel — luxury but informal.
- **Best for:** honeymooners, couples, small families, boutique weddings.

### Maya Kobe — Kilifi · luxury beachfront boutique hotel (in Tribal Dunes)
- **Sleeps 12 · 5 suites · Bed & Breakfast · à la carte meals + bar.**
- **Suites:** 4 Double (king) · 1 **Prestige Suite** (2 double bedrooms, lounge,
  outdoor bathtubs, **private pool** — a residence-style unit).
- **In-room:** bottled water, safe, hairdryer, AC + ceiling fan, mosquito net,
  toiletries, WiFi (no TV).
- **Facilities:** beachfront, pool (with towels), direct beach access; walking
  distance to Tribal Table, Somewhere Café and the kite school. Staff accommodation
  at extra cost; nanny/driver accommodation at extra cost.
- **Positioning:** private boutique hotel with one exclusive residence-style unit.
- **Best for:** honeymooners, couples, small families, boutique weddings who also
  want the Tribal Dunes village on their doorstep.

### My Amani — Vipingo · ultra-private serviced villa (exclusive use)
- **Sleeps 10 · 5 bedrooms · self-catering with a dedicated chef included** + on-site
  support staff. Near **Vipingo Ridge golf**.
- **In-room:** AC + ceiling fan, mosquito net, toiletries. **Basic WiFi — limited
  coverage/speed; no TV.**
- **Facilities:** beachfront, private **infinity pool with jacuzzi corner**, direct
  beach access, full in-house service team. **No accommodation for nannies/private
  staff.**
- **Positioning:** luxury exclusive-use serviced villa.
- **Best for:** HNW families / friendship groups, entrepreneurs, private
  celebrations, executive retreats, golf travellers. Whole-villa only.

### Enkare — Kilifi · beachfront villa (exclusive use)
- **Sleeps 10 · self-catering with an in-house cook.**
- **Rooms:** ground floor 1 Twin en-suite; first floor 1 Double en-suite, 1 Master
  en-suite, 2 Twins sharing a bathroom.
- **In-room:** AC + ceiling fan, mosquito net, hand soap. **Basic WiFi — limited;
  no TV.**
- **Facilities:** private pool (kikoi towels), beachfront, direct beach access.
  Daily housekeeping, pool attendant/gardener, **security 6pm–5am**. No nanny/staff
  accommodation.
- **Positioning:** non-luxury beachfront villa — space and location driven.
- **Best for:** Nairobi holiday-makers, South Africa / East Africa guests, family
  gatherings, friendship groups. Whole-villa only.

### Sandbox — Kilifi · beachfront villa (exclusive use)
- **Sleeps 8 · self-catering, no chef** (a cook is available at extra cost, subject
  to availability).
- **Rooms:** ground floor 2 Doubles (1 en-suite, 1 external bathroom); first floor
  2 Doubles sharing a bathroom.
- **In-room:** AC + ceiling fan, hand soap. **Basic WiFi — limited; no TV.**
- **Facilities:** private pool (kikoi towels), beachfront, direct beach access.
  Daily housekeeping, pool attendant/gardener, security 6pm–5am. No nanny/staff
  accommodation.
- **Positioning:** entry-level beachfront villa — independent stay.
- **Best for:** family/friends groups wanting an affordable, independent beachfront
  base. Whole-villa only.

### Maya Ilai — Kilifi · eco retreat compound (in Tribal Dunes)
- **16 rental units — 8 three-bedroom villas + 8 studio apartments** (plus a
  Superior Suite on the live site). Fully solar, desalinated water. Communal pool,
  bars & gardens, e-bikes & golf carts; walking distance to the beach via Somewhere
  Café.
- **Best for:** corporate retreats, wedding-guest groups, kitesurf groups, wellness
  retreats, **large groups needing multiple units**, and conscious travellers
  wanting flexibility / more affordable / longer stays. Booked **by room type**,
  not as one whole-compound buyout.
- **⚠ Age policy (must surface when relevant):** **adults-only, 16+**. Guests 16–17
  may stay without a parent present, **but** to consume alcohol a parent/legal
  guardian must sign a consent form at check-in.

### Off Duty — Kilifi · beachfront coworking hotel *(ecosystem venue)*
Digital-nomad / creative coworking hotel on Bofa Beach: strong WiFi, dedicated
coworking (open to the public), ensuite rooms, kite school out front, Somewhere
Café on site. **Best for** remote workers, digital entrepreneurs, kitesurf
travellers, creative professionals. *Describe it and route interest to the team —
it is not in the availability/price tools like the stay properties are.*

---

## 3. Room / capacity / units truth table  *(the live source of truth)*

`capacity` = max occupancy of **one unit**; `units` = bookable units of that type;
search does `free_units × capacity`. Reconciled on prod 2026-09-10.

| Property | room slug | capacity | units | whole-property |
|----------|-----------|:--:|:--:|:--:|
| **Zuri** (Watamu) | `zuri-maji` | 2 | 1 | – |
| | `zuri-mwezi` | 4 | 1 | – |
| | `zuri-ua` / `zuri-anga` / `zuri-jua` / `zuri-bahari` | 2 | 1 | – |
| | `zuri-buyout` | 14 | 1 | ✔ |
| **Maya Kobe** (Kilifi) | `maya-kobe-prestige` | 4 | 1 | – (private pool) |
| | `maya-kobe-haze`/`glow`/`tide`/`drift` | 2 | 1 | – |
| | `maya-kobe-buyout` | 16 | 1 | ✔ (12 w/o Prestige) |
| **My Amani** (Vipingo) | `my-amani-full-rental` | 10 | 1 | ✔ (5 ensuite bedrooms) |
| **Enkare Bofa** (Kilifi) | `enkare-bofa` | 10 | 1 | ✔ |
| **Sandbox** (Kilifi) | `sandbox` | 8 | 1 | ✔ |
| **Maya Ilai** (Kilifi, 16+) | `maya-ilai-villa` (Three-Bed Villa) | 6 | **8** | – |
| | `maya-ilai-studio` (Studio) | 2 | **8** | – |
| | `superior-suite` | 6 | 1 | – |

**Largest party each property can host:** My Amani 10 · Zuri 14 · Maya Kobe 16* ·
Enkare 10 · Sandbox 8 · Maya Ilai ~70 (across its unit types). *Tribal Dunes is the
solar site behind the sustainability figures, not a bookable stay.*

> **\*Maya Kobe discrepancy to confirm.** The sales book says Maya Kobe **sleeps
> 12** (4 doubles × 2 + Prestige 4 = 12), but the live `maya-kobe-buyout` capacity
> is **16**. The 12 is the defensible sum of the suites — the buyout should
> probably be **12**, not 16. Confirm with the owner; if 12 is right, set
> `maya-kobe-buyout` capacity = 12 (one-line data fix).

---

## 4. Dining, activities & concierge

- **Tribal Table (Kilifi)** — high-end restaurant & cocktail bar next to Maya Kobe;
  fine dining, elegant setting, open to the public. For: fine-dining guests,
  romantic dinners, celebrations.
- **Somewhere Café (Kilifi)** — relaxed beachfront café at Off Duty; healthy food,
  pizza, coffee, juices, pool, live music, WiFi. For: kitesurfers, digital nomads,
  small families, day guests.
- **Tribal Kite Schools** — Kilifi & Watamu: lessons, equipment rental, water
  safety, a community of riders. Kitesurfing is central to the brand's active
  identity.
- **Watersports & sport** — kite lessons/rental/beach service; pickleball (in
  progress); golf proximity in Vipingo.
- **Concierge** — safaris and full travel arrangements can be organised.

### Curated experiences (Watamu / Zuri) — indicative prices
Third-party, licensed operators; Tribal Sand facilitates only. **Prices are
indicative and subject to change; park/conservation/museum fees are extra unless
noted.** Present these as *"from ~$X, indicative, confirmed at booking"* — never as
a firm quote. *(To make the AI quote these authoritatively, seed them into the
`tours` table so `list_activities` returns them — see §9.)*

| Experience | Party | From | Notes |
|------------|-------|------|-------|
| Watamu Marine Park snorkelling & dolphin watching | ≤10 | $40 pp | +$15 pp park; full day |
| Scuba – certified, 2 dives | 2–6 | $95 pp | +$15 pp park; half day |
| Discover Scuba (beginner) | 1–4 | $120 pp | +$15 pp park; half day |
| Open Water certification course | 1–4 | $450 pp | 3–4 days |
| Deep-sea fishing – half / full day | 1–5 | $550 / $900 per boat | 4 / 8 hrs |
| Sunset dhow cruise – Mida Creek | 2–12 | $45 pp | 3 hrs; prosecco upgrade |
| Arabuko Sokoke forest birdwatching | 2–6 | $50 pp | +~$10–15 pp entry |
| Bio-Ken Snake Farm | flexible | $25 pp | +~$8–10 entry |
| Gede Ruins historical tour | 2–6 | $35 pp | +~$10–15 entry |
| Falconry experience | flexible | $30 pp | 1–2 hrs |
| Turtle watch & conservation | small groups | $30 pp | seasonal |
| Swahili cooking class with chef | 2–8 | $75 pp | 3–4 hrs |
| Malindi tour – half day | 2–6 | $60 pp | 4 hrs |
| Kitesurfing lessons / watersports (Tribal Kite School) | 1–4 / flexible | On request | lessons, gear, safety |

*Guests need a valid passport / Kenyan ID for park entry; weather and sea
conditions affect availability; advance booking recommended.*

---

## 5. Sustainability & community  *(a genuine selling point — lead with it)*

- **Energy:** solar-powered properties; Tribal Dunes fully solar. *"Your stay is
  powered by the sun."*
- **Water:** desalinated ocean water in Tribal Dunes + rainwater harvesting.
  *"Luxury without freshwater depletion."*
- **Marine:** coral-growing garden / reef restoration; turtle-nesting protection.
- **Beach cleanups:** regular, plastic collection recorded, community participation.
- **Community:** majority-local staff, skills training, local sourcing, fair
  employment.
- **Live figures** (solar MWh, CO₂ avoided, beach waste, water) come from the
  `sustainability_facts` tool — quote those, don't invent numbers.

**Messaging pillars:** sustainable luxury · premium personalised service · respect
for ocean & environment · active coastal living & wellness · community integration
· authentic Kenya.

---

## 6. Agent behaviour — how to suggest

This is guidance for the concierge/assistant's **style and recommendation logic**.
It shapes *how* the AI talks and *what* it proposes — it never overrides the hard
rules in §7 (prices/availability always from the tools; never invent).

### 6.1 Voice
Warm, refined, and genuinely helpful — a knowledgeable friend on the coast, not a
brochure. Unhurried and confident. Weave in sustainability and the sense of place
naturally ("powered by the sun", "direct beachfront", "Kenya as it was meant to be
experienced") without lecturing. Concise: a short, vivid sentence plus the key
facts beats a wall of text.

### 6.2 Match the guest to the right property first
Read the guest's profile (who they are, party size, what they want) and lead with
the **best-fit** property, then offer one alternative. Use this map:

| The guest is… | Suggest first | Why |
|---------------|---------------|-----|
| Honeymoon / couple / romantic | **Zuri** or **Maya Kobe** | Boutique suites, private pool, chef-led dining |
| Small family, refined stay | **Zuri** / **Maya Kobe** | Serviced luxury, ocean suites |
| Wants total privacy / HNW / a celebration | **My Amani** | Ultra-private 5-bedroom villa, infinity pool |
| Golf trip (Vipingo Ridge) | **My Amani** | Next to the golf, direct beachfront |
| Family/friends, mid-budget, self-catering | **Enkare** or **Sandbox** | Affordable beachfront villas |
| Large group / wedding party / retreat / corporate | **Maya Ilai** | 16 units, communal spaces, flexible |
| Kitesurf group / active trip | **Maya Ilai** (stay) + **kite school** | Groups + watersports on site |
| Digital nomad / remote work | **Off Duty** (describe + route) | Coworking hotel, strong WiFi |
| Just wants dinner / a table | **Tribal Table** (reserve) | Fine dining, open to public |
| Casual / day / coffee / kite hangout | **Somewhere Café** | Laid-back beachfront café |

### 6.3 Then handle party size with the tools
- Confirm dates + party size (ask **one** short question if missing).
- The **capacity/units data decides what fits** — never eyeball it. For a party a
  single room can't seat, offer the multi-room **combination** the tool returns
  (best-fit first), and mention the whole-property option where one exists.
- If the party is too large for anywhere, say the largest group each property can
  host (from the tool) and suggest a buyout / splitting the group / different dates
  — don't just say "no availability".
- **Maya Ilai is 16+.** If children are in the party, don't suggest Maya Ilai;
  surface the age policy if they ask about it.

### 6.4 Sell the ecosystem, gently
Once a stay fits, enrich it: a Tribal Table dinner, a kite lesson, a Somewhere Café
morning, a safari or transfer via concierge, the sustainability story. Suggest, per
turn, **one or two** complementary things that fit the guest — never a checklist.

### 6.5 Prices, availability & booking
- Always pull the exact figure and free/not-free from the tools; quote in the
  room's currency, and use `convert_currency` only to *show* another currency.
- The security **deposit** is collected **at the property**, never charged online.
- The AI **cannot book.** When the guest is ready, hand off to the property page's
  **"Request to Book"** (a free 24-hour hold) or the contact form. Never say
  anything is booked, held or reserved.

### 6.6 Honesty
If a tool returns nothing or the info isn't available, say so plainly and offer the
nearest thing you *can* verify (other dates, another property). Never fill a gap
with a guess about a price, a date, a room, an activity or a figure.

---

## 7. Hard rules (enforced in code — never weakened)

- **ONE pricing path** — every price via the availability/quote tools; never a
  second calculation.
- **Read-only** — the AI quotes, describes, reports; it never books, holds or edits.
- **Facts via tools, prose via RAG** — availability/price/counts are tool calls;
  the embeddings hold **no** prices.
- **Never invent** a date, price, room, combination, offer, dish, service or figure.
- **Nairobi-local dates. Scoped** — staff see only their assigned properties;
  guests see published properties; staff-only ops tools never reach the concierge.

---

## 8. Live data state (2026-09-10)

The prod audit found every room carried one extra active unit (the
`add_availability` default-unit seed on top of the by-room seed) — the "2×" bug.
Fixed via `bin/reconcile-capacity-units.php` (18 stray units deactivated; the two
NULL buyout capacities set to 16/8); the re-audit is clean. Enkare = 10 and My
Amani = 10 are confirmed on prod; `superior-suite` is a real Maya Ilai room. See
`docs/superpowers/plans/2026-09-10-ai-system-awareness.md`.

---

## 9. Data tasks — make the AI actually use these facts

This KB is a reference; the live AI answers from the DB (tools + RAG). To turn the
new sales-book facts into answers the concierge can give:

1. **Tone + suggestion guidance → Admin → AI settings.** Paste §6 (voice +
   matching logic) into `ai_persona_guest`, and the key brand facts into
   `ai_extra_knowledge`. No code, effective immediately. (The hard rules still can't
   be weakened.)
2. **Per-property stay facts → so "is there WiFi/TV? B&B or self-catering?" works.**
   Put board type, WiFi/no-TV, AC/mosquito-net, cot/high-chair, staff/nanny policy,
   security hours into each venue's editable stay info (Admin → Properties →
   Content/Stay) and/or `ai_extra_knowledge`. These reach the AI via RAG after a
   reindex.
3. **Curated experiences → the `tours` table (so `list_activities` quotes them).**
   Seed the §4 experience list (name, `price` string e.g. "From $40 per person",
   category, `location='watamu'`, duration, short description) via Admin → Activities
   or a seed. Until then the AI can only mention them as *indicative* — it won't
   quote a firm price (prices come only from tools, by design).
4. **Confirm the Maya Kobe buyout capacity** (16 vs 12 — see §3) and correct if 12.
5. **Reindex** (`bin/reindex-content.php`) after copy edits so RAG picks them up;
   optionally add this KB file itself as a reindex source.
