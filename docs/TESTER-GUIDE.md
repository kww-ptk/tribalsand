# Tribal Sand — Tester Guide

This guide is for anyone **testing** the Tribal Sand website and back office. It explains:

1. how to run the whole system on your own computer (from zero),
2. which test accounts to sign in with,
3. what every page does, and
4. what to test, step by step.

You do not need to be a developer. Where you have to type a command, it is shown in a grey box — copy it exactly.

> **Never test on the live site (tribalsand.com).** It takes real bookings, sends real emails to real guests and charges real money. Everything in this guide runs on **your computer only**. Nothing you do there reaches a real guest or the live website.

---

## Contents

- [Part 1 — Set it up on your computer](#part-1--set-it-up-on-your-computer)
- [Part 2 — Test accounts (who to sign in as)](#part-2--test-accounts-who-to-sign-in-as)
- [Part 3 — How the local copy differs from the live site](#part-3--how-the-local-copy-differs-from-the-live-site)
- [Part 4 — The public website](#part-4--the-public-website)
- [Part 5 — The guest's booking page (guest portal)](#part-5--the-guests-booking-page-guest-portal)
- [Part 6 — The admin (back office), page by page](#part-6--the-admin-back-office-page-by-page)
- [Part 7 — Who can see what (roles)](#part-7--who-can-see-what-roles)
- [Part 8 — Other apps: travel-agent portal, till, clock kiosk](#part-8--other-apps-travel-agent-portal-till-clock-kiosk)
- [Part 9 — Test scripts (step by step)](#part-9--test-scripts-step-by-step)
- [Part 10 — How to report a bug](#part-10--how-to-report-a-bug)
- [Glossary](#glossary)

---

## Part 1 — Set it up on your computer

You will run three things, all inside **Docker** (a program that runs ready-made "boxes" of software, so you don't have to install a web server or database yourself):

| Box | What it is | Address |
|---|---|---|
| **app** | the website + admin + background jobs | http://localhost:8080 |
| **db** | the database, filled with demo data | (no address — used by the app) |
| **mailpit** | catches **every email** the system sends, so you can read them | http://localhost:8025 |

### Step 1 — Install two programs (once)

1. **Git** — https://git-scm.com/downloads (keep all the default options).
2. **Docker Desktop** — https://www.docker.com/products/docker-desktop/
   - **Windows:** during install it asks to turn on **WSL 2** — say yes and restart the computer.
   - After installing, **open Docker Desktop** and wait until it says *Engine running* (bottom-left). It must be running whenever you test.

You need access to the GitHub repository `kww-ptk/tribalsand`. Ask the team lead to invite your GitHub account if the next step says "Repository not found".

### Step 2 — Download the code

Open a terminal:
- **Windows:** open the Start menu, type **Git Bash**, press Enter.
- **Mac:** open **Terminal** (Applications → Utilities).

Then run these three commands, one after the other:

```bash
git clone https://github.com/kww-ptk/tribalsand.git
```

```bash
cd tribalsand
```

```bash
git checkout dev
```

`dev` is the branch with the newest work that is waiting to go live — that is what you test.

### Step 3 — Start it

```bash
docker compose up
```

- The **first** start takes about **3–5 minutes**: it downloads the boxes and builds the database with demo data.
- Leave this terminal window open — it shows the system's log.
- It is ready when the log shows a line starting with **`── Ready:`**.

Now open these in your browser:

| What | Address |
|---|---|
| The website | **http://localhost:8080** |
| The admin (back office) sign-in | **http://localhost:8080/admin/login** |
| Every email the system sent (Mailpit) | **http://localhost:8025** |

### Step 4 — Stopping, restarting, updating

| I want to… | Do this |
|---|---|
| **Stop** the system | Click in the terminal running it and press **Ctrl + C**. Or run `docker compose down` in another terminal (inside the `tribalsand` folder). |
| **Start** it again later | Open Docker Desktop, open a terminal in the `tribalsand` folder, run `docker compose up`. |
| **Get the newest version** to test | Stop it, then run `git pull origin dev`, then `docker compose up` again. New database changes are applied automatically on start. |
| **Start over with a clean database** (wipe everything you created) | `docker compose down -v` then `docker compose up` |

> **Opening a terminal "in the tribalsand folder":** in Git Bash / Terminal type `cd tribalsand` (or `cd ` followed by the full path to the folder) before running a command.

### If something goes wrong

| Problem | Fix |
|---|---|
| `docker: command not found` / "Cannot connect to the Docker daemon" | Docker Desktop is not running. Open it and wait for *Engine running*. |
| "port is already allocated" | Something else uses port 8080. Stop the other program, or start with `APP_PORT=8090 docker compose up` and use http://localhost:8090 instead. |
| The page shows an error right after starting | Wait for the `── Ready:` line, then refresh. |
| "Repository not found" when cloning | Your GitHub account needs access — ask the team lead. |
| The site looks broken after `git pull` | Stop and start again (`docker compose down`, then `docker compose up`). |

---

## Part 2 — Test accounts (who to sign in as)

All test accounts use the **same password**:

> **`TribalLocal-2026`**

Sign in at **http://localhost:8080/admin/login** using the **email + password** form.

| Email | Kind of account | Can see / do | Property |
|---|---|---|---|
| `owner@tribalsand.test` | **Owner** | Everything: bookings, prices, website, staff, settings, money | All properties |
| `manager@tribalsand.test` | **Manager** | Day-to-day running: guest requests, tasks, attendance, restaurant, till & stock, reports | My Amani only |
| `reception@tribalsand.test` | **Reception** | Bookings, calendar, enquiries, quotes, rates, guest requests, messages, gate, table bookings | My Amani only |
| `frontdesk@tribalsand.test` | **Front-desk staff** | Front desk, guest requests, guest messages | My Amani only |
| `housekeeping@tribalsand.test` | **Housekeeping staff** | Their own tasks ("My work"), stock counts | My Amani only |
| `security@tribalsand.test` | **Gate security staff** | The gate (visitors in/out) | My Amani only |
| `shop@tribalsand.test` | **Shop staff (till)** | The till (point of sale) | First shop outlet |
| `store@tribalsand.test` | **Storekeeper** | Stock list, orders/deliveries, stock counts | My Amani only |

Why "My Amani only": every account except the owner is limited to the properties assigned to it, so you can check that people **cannot** see other properties' data.

> **Real staff** on the live site sign in with a short **staff access code** instead of email + password (the second box on the sign-in page). The test accounts use email + password for convenience. To test the access-code sign-in, create a staff account (Admin → Team → Staff → Login accounts → Add account) and use the code it shows.

**Guests and travel agents** don't have test accounts — you create them as you test (see Part 9).

---

## Part 3 — How the local copy differs from the live site

| Thing | Live site | Your local copy |
|---|---|---|
| Emails | Sent to real people | **Caught by Mailpit** — read them at http://localhost:8025. Nothing leaves your computer. |
| Anti-spam check on forms ("I'm human" box) | On | **Off** — forms submit without it |
| AI assistant & guest concierge | On | **Hidden** (needs a paid AI key). AI pages say it isn't set up — that is expected. |
| Sync with Zuri's restaurant system | On | **Off** |
| CRM (GoHighLevel) | Leads are pushed | **Off** (forms still work) |
| Online card payments | None anywhere — the system never charges a card online | Same |
| Room prices | Set | **Many demo rooms have no price**, so totals show "—". To test prices, set one in Admin → Website → Properties & rooms → (room) → Details, or in Admin → Bookings → Rates. |
| Photos you upload | Stored in the cloud | Stored on your computer (in the project folder) |
| Background jobs (expire 24-hour holds, currency rates…) | Run on a timer | Also run, inside the app box |

**Things that are normal, not bugs:** AI pages saying they are not configured; empty reports when you have made no bookings yet; "no price" labels on unpriced rooms.

---

## Part 4 — The public website

Start at **http://localhost:8080**. This is what guests see.

### Main pages

| Page | Address | What it does |
|---|---|---|
| Home | `/` | Introduction to the properties, live sustainability figures, reviews, a short film (loads when you press play), current offers. |
| Search | `/search` | Pick dates and number of guests → every property with space, with prices. For big groups it suggests **combinations of rooms**. |
| Property pages | `/maya-kobe`, `/zuri`, `/maya_ilai`, `/my-amani`, `/enkare-bofa`, `/sandbox`, `/tribal-dunes` | Photos, description, rooms, map, reviews, and the **booking box** on the side. |
| Photo gallery | `/gallery?venue=zuri` (any property) | All photos of one property, with a full-screen viewer. |
| Activities | `/activities` | Tours and experiences (snorkelling, safaris, kitesurfing, wellness…), filter by type/place, "Enquire" opens a form. |
| Restaurant menu | `/menu?m=zuri`, `/menu?m=maya-kobe-breakfast` | The digital menu: Food / Drinks switch, course chips, each dish as a card (with photo when one is set), dietary marks (V, VG, S, N, G, GF), "Reserve a table". |
| Reserve a table | `/reserve?venue=zuri` | Restaurant table request: property, date, time, party size. Staff confirm it later. |
| Restaurants | `/zuri-restaurant`, `/tribal-table`, `/a-la-carte-dining` | Restaurant information pages. |
| Plan your trip | `/trip-builder` | A multi-step trip planner that sends a request to the team. |
| Enquire / Contact | `/enquire`, `/contact` | General enquiry and contact forms. |
| For travel agents | `/for-agents` | Information for agents + a trade enquiry form. |
| Events / weddings | `/events` | Weddings and events information + enquiry. |
| Sustainability | `/sustainability` | Solar, water, beach-clean figures (they grow slowly over time on purpose). |
| Coming soon | `/off-duty`, `/somewhere-cafe`, `/tribal-gym` | Pre-launch pages with a **waiting list** sign-up. |
| Journal | `/blog` | Articles about the coast. |
| Area guides | `/kilifi`, `/watamu`, `/kenya-coast-guide`… | Travel information pages. |
| Policies | `/tc`, `/privacy_policy`, `/sffp`, `/licences` | Terms, privacy, smoke-free policy, licences. |

### The booking box (on property pages) — the most important thing to test

There are two kinds:

- **Multi-room properties** (Maya Kobe, Zuri, Maya Ilai): pick dates + guests → **Check availability** → it lists the rooms that fit, the whole property, and (for big groups) combinations. **Select** a room opens the booking pop-up.
- **Whole-property places** (My Amani, Enkare Bofa, Sandbox): the booking box is on the page itself, in steps: **Dates → Extras → Details**.

In both you can tick **"Add to your stay"** extras (massage, manicure, pedicure, airport pick-up / drop-off). Then enter name, email, phone and press **Request to Book**.

What happens next: the website checks the dates are free, then sends a **request**. It does **not** hold the dates (owner rule, Oct 2026). The request appears in **Bookings → Enquiries**; reservations press **Convert to Hold**, which blocks the dates for 24 hours and emails the guest their booking link. (The owner can let the website hold by itself again in Settings → Booking Form Mode.)

If nothing is free for your dates, the box suggests **other properties with space**.

Note: the button says **"Request to Book"**, never "Book Now" — it is a request, not an instant booking.

---

## Part 5 — The guest's booking page (guest portal)

Every booking has a private page for the guest. The link is in the guest's emails ("View your booking"). In the admin you can also open it from the booking (see Part 6).

| Tab | What the guest can do |
|---|---|
| **Home** | A photo of the property, the booking card (dates, code, status, hold countdown), the extras picked for that property and a link to ask for services. |
| **My trip** | "Added to my stay" (each extra with its status: Waiting / Confirmed / Done) and the day-by-day calendar. |
| **Extras** | Everything the property offers to add (activities, wellness, transfers). Tap one → choose the day, time of day and people → **Add to my stay**. Staff confirm it; it goes on the bill once confirmed. |
| **Request** (from Home) | Ask for laundry, housekeeping, maintenance, restaurant, anything else. Each request opens a chat. |
| **Messages** | Chat with the team. Updates every few seconds. |
| **Check-in** | Online pre-check-in: guest details, passport scan, signing the terms, the security-deposit card photo. Co-guests can get their own link. |
| **Bill** | What has been charged to the room (extras, till purchases) and payments. Appears only after staff press **Share reservation** on the booking's Check-in tab. |

The **signed record** (`/record?…`) is the printable proof of what the guest signed at check-in.

---

## Part 6 — The admin (back office), page by page

Sign in at **http://localhost:8080/admin/login**. Everyone lands on the **Dashboard**. The left sidebar is grouped; some items have **tabs** across the top of the page (e.g. Calendar · Conflicts · Highlights · Import).

**What you see depends on who you are** — see Part 7. The descriptions below are for the owner (who sees everything).

### Dashboard
| Page | What it does |
|---|---|
| **Dashboard** | Everyone's home page, shaped to the role: a greeting, the "Needs you" list (requests to answer, unread messages…), arrivals/departures today, rooms occupied, revenue this month (owner/manager), your own tasks. Read-only. |
| **Help & guides** | How-to guides for the pages you can open. On any page, the **Help** button (top right of the tabs, or bottom right) lists that page's guides; **Show me** walks you through it on the real page. Ctrl+K also finds guides. |

### Today
| Page | What it does |
|---|---|
| **Front desk** | Today's arrivals, departures and who is in-house, per property. Check-in status, quick actions. |
| **My work** | A staff member's own task list for today (housekeeping, laundry, driver…). |
| **Stock count** | (Ops staff) Count the stock at a place on a phone/tablet: expected number vs what you count. |
| **Guest requests** | Everything guests asked for (transfers, laundry, activities…): assign to staff, mark done. |
| **Messages** → Customers | Chats with guests about their bookings. |
| **Messages** → Team chat | Internal chat between staff (groups and direct messages). |
| **Gate** | Gate security: who is arriving today, sign visitors in and out. |
| **AI assistant** | Ask "what's free for 4 people next weekend and how much?" — answers from the live system. (Hidden locally without an AI key.) |

### Bookings
| Page | What it does |
|---|---|
| **Bookings** | All bookings and holds. Filter by status/property. Each row: **Confirm**, **Cancel**, open. The **"Email guest"** tick decides whether the guest gets the confirmation/cancellation email. **New booking** creates one by hand. |
| *(a booking)* | The booking workspace, with tabs: **Requests** (extras, transfers, activities), **Messages**, **Plan**, **Bill** (charges, payments, invoices), **Check-in** (guests, passports, signatures, deposit card), **Activity** (history + emails sent), **Details** (dates, room, guest, confirm/cancel). |
| **Calendar** | The availability calendar: every room × every day, bookings as coloured bars. Weekends grey, public holidays red, your highlights in colour. Drag to create or move a block. **Sync iCal** pulls bookings from Booking.com/Airbnb calendars. |
| Calendar → **Conflicts** | Double bookings between our site and the online travel agents, to sort out. |
| Calendar → **Highlights** | Mark dates on the calendars (school holidays, events, peak season) with a name and colour. They also show on the Rates page. |
| Calendar → **Import** | Upload a channel-manager (eZee) spreadsheet of bookings into the calendar. |
| **Enquiries** | Every enquiry from every form. Open one to read it, reply by email (the guest's answer comes back into the same thread), change status, **Convert to booking**, **Build quote** (saved as numbered options with a printable PDF). |
| Enquiries → **Trends** | Charts of enquiries over time, by source, by property. |
| **Rates** | Prices: **Rate card** (each room's price per season), **Timeline** (rooms × days, looks like the calendar, with holidays/highlights), **Calendar** (month grids per room). Owner can **Set rates** for many rooms at once, with a preview, and undo. The **Buyout check** warns when a whole-property price doesn't equal the sum of its rooms. |
| Rates → **Maya Ilai** | Maya Ilai's own pricing tool + a live **Unit Map** (aerial view of which bedroom is booked). |
| **Quote builder** | Build a price quote for any dates/party across properties (rooms, activities, transfers, discount), in KES or USD; "Suggest options" fills it in. |

### Team
| Page | What it does |
|---|---|
| **Tasks** | Create and assign tasks to staff. |
| Tasks → **Job timetables** | Repeating schedules per job (e.g. housekeeping rounds). |
| Tasks → **Week view** | Everyone's week as a timetable. |
| **Attendance** | Staff hours: clock-ins/outs, edit times, totals. |
| Attendance → **Clock kiosks / Clock cards** | Set up the tablet that staff scan their card on, and print the cards (owner switches the kiosk on). |
| **Staff** → Directory | Everyone who works for Tribal Sand: position, property, contact, documents, activity log. |
| **Staff** → Login accounts | Who can sign in to the admin: create accounts (owner / manager / reception / staff with a job), assign properties, reset passwords, new access codes. |
| **Staff** → **Access by role** | **The owner decides which sections each kind of account can open.** Pick a role (Manager, Reception, Housekeeping, Storekeeper…) and switch sections on or off. See Part 7. |

### Restaurant
| Page | What it does |
|---|---|
| **Reservations** | Table bookings from `/reserve`: confirm or cancel (the guest is emailed). |
| **Menus & setup** → Menus | Edit restaurant menus: courses, dishes, prices, dietary marks, **dish photos**, hide a dish. The Zuri menu has **"Import photos from Zuri's website"**. |
| **Menus & setup** → Hours & tables | Opening hours and the restaurant's tables. |

### Point of Sale (the till)
| Page | What it does |
|---|---|
| **Open till** | Opens the till (`/pos`) in a new tab — see Part 8. |
| **Sales** | Every till sale; open one to see it or **void** it (with a reason); daily **Z-report**; download CSV. |
| **Catalogue & stock** → Catalogue | What each shop sells, prices, photos. |
| → Stock | Receive deliveries, count stock, history. |
| → Suppliers | Consignment suppliers and what they are owed. |
| **Setup** → Outlets | (Owner) The shops/outlets: property, service charge, VAT, room charge on/off, staff. |
| → Terminals | Tablets registered as tills. |
| → Staff PINs | Each person's till PIN. |

### Inventory
| Page | What it does |
|---|---|
| **Inventory** | The stock list — like a spreadsheet: what we own, where it is, how many, value. Filter by place, search, sort, bulk move. **Import from Excel**. |
| Inventory → **Locations** | Places stock lives: stores, properties, areas (kitchen, villa 3…), count schedules. |
| **Orders** | Supplier orders/shipments: what was ordered, what has arrived; **receive** deliveries into a place; container packing lists. |
| **Counts** | Stock counts that didn't match — the manager/owner decides (missing, broken, found…). |

### Finance
| Page | What it does |
|---|---|
| **Reports** | Revenue, average rate, occupancy, by property / month / source; till takings. Download CSV. |
| **Accounting** → Invoices & payments | Tax invoices, credit notes and payments per company. |
| → Between companies | What the companies owe each other. |
| → Companies | (Owner) Legal companies, KRA PINs, bank/M-Pesa accounts, invoice numbering. |

### Website (owner)
| Page | What it does |
|---|---|
| **Properties & rooms** | Each property's details, text, photos, rates, deposit, **Guest extras** (what guests can add from their booking page, order, featured, reminder email); each room's price, capacity, photos, units. "For sale" listings. |
| **Website content** | Page text and photos, the top menu (mega menu), media library, sustainability figures. |
| **Activities & services** | Activities (tours): text, price, photos, **offer as an extra while booking**. **Service pricing**: laundry and transfer prices, and which transfers are **offered when booking**. |
| **Marketing** | Offers, guest reviews, partners, the guest notice board. |

### Settings (owner)
| Page | What it does |
|---|---|
| **Settings** | Site-wide settings, pre-check-in settings, and **Migrations** (applies database updates on the live site). |
| **Emails** | Every email the system sends: switch on/off, change the wording, preview, send a test. **Email log** = every email sent (or not sent, and why). |
| **AI** | AI tone and knowledge, questions the guest concierge couldn't answer, the AI search index. |
| **Travel agents** | Agent accounts and their discounts. |
| **Zuri sync** | Health of the Zuri restaurant sync. |
| **Audit log** | Who changed what, and when. |

---

## Part 7 — Who can see what (roles)

These are the **default** rules. The owner can change them in **Team → Staff → Access by role** (except owner-only areas and the Dashboard, which are fixed).

| Area | Owner | Manager | Reception | Front desk | Housekeeping / ops | Gate | Till (shop/spa/kite) | Storekeeper |
|---|---|---|---|---|---|---|---|---|
| Dashboard | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Front desk, guest requests, guest messages | ✓ | ✓ | ✓ | ✓ | – | – | – | – |
| Team chat, own timetable | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Gate | ✓ | ✓ | ✓ | – | – | ✓ | – | – |
| Bookings, calendar, enquiries, quote builder | ✓ | – | ✓ | – | – | – | – | – |
| Rates (prices) | ✓ (edit) | view (no menu link) | view | – | – | – | – | – |
| Tasks | ✓ | ✓ | ✓ | – | – | – | – | – |
| My work | – | – | ✓ | – | ✓ | – | – | – |
| Attendance | ✓ | ✓ | – | – | – | – | – | – |
| Restaurant reservations | ✓ | ✓ | ✓ | – | – | – | – | – |
| Menus & hours | ✓ | ✓ | – | – | – | – | – | – |
| Till (Open till) | if assigned | if assigned | if assigned | if assigned | – | – | ✓ | – |
| Till management (sales, catalogue, stock) | ✓ | ✓ | – | – | – | – | – | – |
| Inventory (stock list, orders, counts review) | ✓ | ✓ | – | – | – | – | – | ✓ |
| Stock count | ✓ | ✓ | – | – | ✓ | – | – | ✓ |
| Reports, accounting | ✓ | ✓ | – | – | – | – | – | – |
| Staff, website, settings, emails, prices setup | ✓ | – | – | – | – | – | – | – |

Also important:
- Everyone except the owner only sees **their own properties'** data.
- Opening a forbidden page by typing its address sends you back to the Dashboard with the message *"…isn't available for your account"*. **Test this** — it is a security check.
- Inside a page, some actions stay owner-only even when others can open it (e.g. deleting stock items, changing an item's value).

### Access by role (the owner's control)
**Team → Staff → Access by role:**
1. Pick a role at the top (Manager, Reception, Front desk, Housekeeping, … Storekeeper).
2. Each section shows **Default: on/off** and a switch. Owner-only sections show a lock.
3. Change switches → **Save access for …**. Changed switches are marked **Changed**; the role gets an orange dot.
4. **Reset to defaults** puts that role back.
5. The change applies on the person's next page load: switched **off** = gone from their menu and the page refuses them; switched **on** = appears in their menu (still limited to their own properties).

---

## Part 8 — Other apps: travel-agent portal, till, clock kiosk

### Travel-agent (trade) portal — `/agent/login`
For travel agencies. They see live availability at **their discounted price** and send booking **requests** (which never hold rooms — reservations staff convert them).
- To test: as owner, create an agent in **Settings → Travel agents** (give an email, password, discount %), then sign in at http://localhost:8080/agent/login.
- Pages: **Availability** (search + request), **Rates** (price list at their discount), **Your requests** (status of each request).

### Point of Sale till — `/pos/`
A tablet till for the shop, salon & spa, and kite school.
- Sign in to the admin as `shop@tribalsand.test`, then click **Open till** in the sidebar.
- Pick items → choose the customer: **walk-in** or **an in-house guest** (charge to their room — needs their signature) → pay (cash / card / M-Pesa) → receipt (print or email).
- Things to test: room charge appears on the guest's **Bill**; **void** a sale from Admin → Sales; the receipt email (in Mailpit).
- A registered tablet uses **staff PINs** (lock screen → tap name → PIN). Registering is done in Point of Sale → Setup → Terminals.

### Clock kiosk — `/clock`
A tablet at the staff entrance: staff scan their card, the camera takes a photo, it records clock-in/out.
- It is **off** until the owner switches it on in **Team → Attendance → Clock kiosks** and registers the device. Print cards in **Clock cards**.
- The camera only turns on after pressing START, and turns off again on its own.

---

## Part 9 — Test scripts (step by step)

Tick each step. If a step doesn't do what it says → report it (Part 10).

### A. Smoke test (10 minutes — do this first, every new version)
1. Open http://localhost:8080 — the home page loads with photos.
2. Open each property page (`/maya-kobe`, `/zuri`, `/my-amani`) — photos and booking box show.
3. Open `/activities`, `/search`, `/menu?m=zuri`, `/reserve?venue=zuri` — all load.
4. Sign in as **owner** — Dashboard loads; click every sidebar item once — no page shows an error.
5. Open Mailpit http://localhost:8025 — it loads.

### B. A booking from start to finish
1. On `/maya-kobe`, pick dates ~2 months ahead, 2 adults → **Check availability** → **Select** a room.
2. In the pop-up, tick **Deep Tissue Massage** and **Airport → Property**, fill name + **your own made-up email** (e.g. `test1@example.com`) → **Request to Book**.
3. ✓ A success message with a 24-hour countdown appears.
4. In Mailpit: ✓ the guest got an acknowledgement email; ✓ the staff alert "new hold" email (sent to the staff address in Settings).
5. Sign in as **owner** → **Bookings**: ✓ the hold is there as *pending*.
6. Open it → **Requests** tab: ✓ the massage and the airport transfer are listed.
7. Back in Bookings, tick **Email guest** and **Confirm**.
8. In Mailpit: ✓ "Booking Confirmed" email, with **View your booking** and a **"Make the most of your stay"** box offering the extras **not** already booked (no massage, no arrival transfer).
9. Click **View your booking** in that email: ✓ the guest page opens; try each tab (Request a laundry, send a Message, start Check-in).
10. As owner, open the booking → **Messages**: ✓ the guest's message is there; reply → ✓ it appears on the guest page.
11. **Cancel** the booking with "Email guest" ticked → ✓ cancellation email in Mailpit; ✓ the dates are free again on the Calendar.

### C. Enquiry → quote → booking
1. Send an enquiry from `/enquire` or `/contact`.
2. Owner → **Enquiries**: open it → **Build quote** → save → **Print / PDF** ✓ a branded quotation page.
3. Reply to the guest from the enquiry → ✓ email in Mailpit, and the reply shows in the thread.
4. **Convert to booking** → ✓ a booking is created and linked.

### D. Roles and access (security)
For each test account in Part 2:
1. Sign in → ✓ the sidebar shows only what Part 7 says.
2. Type addresses of pages they should **not** see (e.g. as reception: `/admin/inventory`, `/admin/reports`; as housekeeping: `/admin/rates`, `/admin/holds`) → ✓ you are sent back to the Dashboard with an "isn't available" message.
3. As **manager/reception**, check you only see **My Amani** data (bookings, rates, stock of other properties are not listed).

Then test **Access by role**:
1. Owner → Team → Staff → **Access by role** → **Reception** → switch **Inventory** on and **Rates** off → Save.
2. Sign in as reception → ✓ Inventory appears, Rates is gone; `/admin/rates` is refused.
3. Owner → Reset to defaults → ✓ reception is back to normal.

### E. Storekeeper
1. Sign in as `store@tribalsand.test` → ✓ sidebar: Dashboard, Team chat, Timetable, Inventory, Orders, Counts — nothing about bookings or money.
2. Inventory → open an item → **Receive** some stock into My Amani → ✓ the number goes up.
3. Orders → open the order → receive part of a line → ✓ "still to come" goes down.
4. Count a place (Counts / Stock count) with a different number → ✓ it waits for a manager; sign in as **manager** → Counts → resolve it.

### F. Rates and calendar
1. Owner → Calendar → Highlights → add "Test festival" (blue) for next week.
2. ✓ It shows on the **Calendar** header and on **Rates → Timeline** and **Rates → Calendar**.
3. Rates → **Set rates** → pick rooms, dates, a price → **Preview** → **Confirm** → ✓ the prices change; ✓ **Undo** in the change log puts them back.

### G. Restaurant
1. `/menu?m=zuri`: ✓ Food/Drinks switch works; ✓ course chips jump to the course; ✓ looks right on a phone (make the browser window narrow).
2. Owner → Restaurant → Menus & setup → Zuri → edit a dish → **Add a photo** → Save → ✓ the photo shows on the public menu.
3. Hide a dish (click "Available") → ✓ it disappears from the public menu.
4. Reserve a table at `/reserve?venue=zuri` → owner → Reservations → **Confirm** → ✓ confirmation email in Mailpit.

### H. Till
1. Sign in as `shop@tribalsand.test` → Open till → sell an item for **cash** → ✓ receipt.
2. Owner → Point of Sale → Sales → ✓ the sale is listed; **Void** it with a reason → ✓ marked void.

### I. On a phone
Repeat A and B on a phone, or with the browser narrowed to phone width (Chrome: F12 → the phone icon). ✓ Nothing is cut off, nothing scrolls sideways, buttons are easy to tap.

---

## Part 10 — How to report a bug

Write one report per problem, with:

1. **Title** — short: *"Reception can open the Reports page"*.
2. **Account** you were signed in as (or "guest / not signed in").
3. **Address** of the page (copy it from the browser bar).
4. **Steps** — numbered, so someone else can repeat it.
5. **What you expected** vs **what happened**.
6. **Screenshot** (and for emails, a screenshot from Mailpit).
7. **Phone or computer**, and which browser.

Example:
> **Title:** Confirmation email has no "View your booking" button
> **Account:** owner@tribalsand.test
> **Page:** http://localhost:8080/admin/holds
> **Steps:** 1. Made a booking on /maya-kobe. 2. Confirmed it with "Email guest" ticked. 3. Opened the email in Mailpit.
> **Expected:** a "View your booking" button. **Happened:** no button.
> **Screenshot:** attached. **Device:** Windows laptop, Chrome.

---

## Glossary

| Word | Meaning |
|---|---|
| **Hold** | A booking request that blocks the dates for 24 hours until staff confirm or decline it. |
| **Enquiry** | A message/request from a form, answered by the team. Can become a booking. |
| **Property / venue** | One of our places: My Amani, Maya Kobe, Zuri, Enkare Bofa, Sandbox, Maya Ilai, Tribal Dunes. |
| **Unit** | One physical room/villa of a room type (a room type can have several units). |
| **OTA** | Online travel agency — Booking.com, Airbnb, Expedia. Their bookings arrive through **iCal** calendar links. |
| **Extras / add-ons / upsell** | Things a guest adds to a stay: massage, airport transfer, activities. |
| **Guest portal** | The guest's private booking page (`/booking?ref=…`). |
| **Outlet** | A place that sells through the till: shop, salon & spa, kite school. |
| **Room charge** | A till sale put on the guest's room bill instead of paid at the till. |
| **Mailpit** | The local "fake inbox" that catches every email (http://localhost:8025). |
| **Migration** | A database update. Locally they run automatically; on the live site the owner runs them in Settings → Migrations. |
| **KES / USD** | Kenyan shillings / US dollars. Prices can be in either; money in different currencies is never added together. |
