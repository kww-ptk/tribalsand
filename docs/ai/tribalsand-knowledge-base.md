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

## 2. Properties — profiles & who they suit

*(Bookable stays the availability/price tools cover. Concept/audience from the
brand doc; capacity/units are the live truth table in §3.)*

### Zuri — Watamu · luxury beachfront boutique hotel
- **Concept:** fully-serviced luxury boutique hotel; ocean-facing suites, private
  pool, chef-led à la carte dining, curated hospitality.
- **Best for:** honeymooners, couples, small families, boutique destination
  weddings — guests seeking refined comfort and premium service.

### Maya Kobe — Kilifi · luxury beachfront boutique hotel (in Tribal Dunes)
- **Concept:** as Zuri, plus walking-distance access to the whole Tribal Dunes
  village (Tribal Table, Somewhere Café, kite school).
- **Best for:** honeymooners, couples, small families, boutique weddings who also
  want the village/ecosystem on their doorstep.

### My Amani — Vipingo · ultra-private beachfront villa
- **Concept:** ultra-private **five-bedroom** luxury villa; total exclusivity,
  direct beach access, infinity pool + hot tub, private compound, concierge-led.
  Near **Vipingo Ridge golf**.
- **Best for:** high-net-worth families or friendship groups, entrepreneurs,
  private celebrations, executive retreats, **golf travellers**. Whole-villa only.

### Enkare — Kilifi · mid-range self-catering beachfront villa
- **Concept:** more affordable, self-catering, beachfront exclusivity.
- **Best for:** Kenyan holiday-makers from Nairobi, guests from South Africa / East
  Africa, family gatherings, friendship groups. Whole-villa only.

### Sandbox — Kilifi · mid-range self-catering beachfront villa
- **Concept & audience:** as Enkare — mid-range, self-catering, family/friends
  groups. Whole-villa only.

### Maya Ilai — Kilifi · eco retreat compound (in Tribal Dunes)
- **Concept:** eco retreat where comfort, simplicity and sustainability meet.
  **16 rental units — 8 three-bedroom villas + 8 studio apartments** (plus a
  Superior Suite on the live site). Communal pool, bars & gardens, e-bikes & golf
  carts, walking distance to the beach via Somewhere Café. Fully solar, desalinated
  water.
- **Best for:** corporate retreats, wedding-guest groups, kitesurf groups, wellness
  retreat organisers, **large groups needing multiple units**, and conscious
  travellers wanting flexibility / more affordable / longer stays. Booked **by
  room type**, not as one whole-compound buyout.
- **⚠ Age policy (must surface when relevant):** Maya Ilai is **adults-only, 16+**.
  Guests 16–17 may stay without a parent present, **but** to consume alcohol a
  parent/legal guardian must sign a consent form at check-in.

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

**Largest party each property can host:** My Amani 10 · Zuri 14 · Maya Kobe 16 ·
Enkare 10 · Sandbox 8 · Maya Ilai ~70 (across its unit types). *Tribal Dunes is the
solar site behind the sustainability figures, not a bookable stay.*

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
