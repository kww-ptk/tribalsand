# Jira tickets — work since the POS and Inventory build (26 Sep – 7 Oct 2026)

One ticket per feature area, each with its context and step-by-step test instructions. Import `2026-10-07-tickets-since-pos.csv` into Jira (Summary, Description, Issue Type), or copy the text below.

---

## 1. Point of Sale (POS) — tills for the shop, salon & spa and kite school

**Context:** The outlets (shop, salon & spa, kite school) were selling on paper with no link to the guest's bill or to stock. We needed one till that works on a tablet, lets in-house guests charge to their room, and gives managers clean daily takings.

**Description:** Built the till at `/pos/` (tablet-first, also phone and desktop) and the admin under Point of Sale. Staff unlock a registered tablet with their own PIN; managers register tablets and set PINs. Every sale is re-priced on the server and can only be corrected by a void with a reason. Room charges land as one line on the guest's bill with the guest's signature, for guests of any Tribal Sand property. Added VAT (inclusive or added), service charge and tips per outlet, per-delivery consignment terms with supplier statements and payouts, sales history, daily Z-report and CSV. Follow-ups: every outlet sells in KES (one-click switch converts existing prices), linked activities convert from the website currency, receipts can be emailed from the till, and an offline mode saves a sale on the tablet when the connection drops and sends it once it's back (never twice). POS takings also appear on the financial reports page.

**How to test:**
**Before you start:** an outlet exists (Admin → Point of Sale → Outlets) with items, your account is assigned to it, and there is a booking that is checked in today.
1. Admin → Point of Sale → Staff PINs: set your PIN. Try `1234` first — it must be refused as too simple; then set a real 4–6 digit PIN.
2. On a tablet (or a second browser) sign in to admin as owner/manager and open `/pos/register.php`. Name the till and pick its outlet(s). Expected: you are signed out of admin and see the till's lock screen.
3. Tap your name and enter your PIN → the till opens. On another person, enter a wrong PIN 5 times → that person is locked out.
4. Add two items, choose **Cash**, complete. Expected: a receipt shows; the sale is in the till's Sales history and in Admin → Point of Sale → Sales, in KES.
5. **Room charge:** start a new sale, choose the in-house guest, let them sign, complete. Open that booking in admin → **Bill** tab. Expected: one line with the outlet, the items and the POS reference; it has a POS badge and cannot be deleted there.
6. Admin → Point of Sale → Sales → open that sale → **Void** with a reason. Expected: the bill line disappears and the item stock goes back up.
7. On a cash sale's receipt tap **Email**, enter your address → the receipt arrives (a sale can be emailed up to 3 times).
8. **Offline:** switch the tablet's Wi-Fi off, make a cash sale → choose **Save offline**. Switch Wi-Fi back on → the sale sends by itself and appears once (not twice) in Sales.
9. Admin → Point of Sale → Sales → **Z report** for today: totals per currency match the sales you made. Admin → Reports shows the same takings in the Point of sale section.

## 2. Inventory core — one stock database for POS, properties, staff and central stores

**Context:** Stock was only tracked per POS listing, so nothing covered the properties' equipment, linen, furniture or what staff hold. The owner wanted one inventory under everything, with value and accountability.

**Description:** Designed and built a shared inventory: items, places (Main stock, stores, properties and their areas, POS outlets, people), balances and an append-only movement history. One write path handles every stock change (receive, transfer, assign, return, sell, report lost/broken/stolen, replace) with no negative stock and a value snapshot on each move. Serial-numbered items move one unit at a time. Par levels and "restock to par" from a chosen store. POS stock now lives in this inventory, so a sale, void, delivery or count moves the same balances. Stores can belong to a property and be shared with others; unpublished properties get locations too.

**How to test:**
**Before you start:** sign in as owner (or manager for your property).
1. Admin → Inventory → **New item** (counted, e.g. "Beach towel", value KES 1,500). On its page **Receive** 10 into Main stock. Expected: the list shows 10 at Main stock with its value.
2. On the item page **Move** 4 to a property. Expected: Main stock 6, property 4; History lists both movements.
3. Try to move 20 → refused ("not enough stock"). Stock never goes below zero.
4. Create a **serial** item (e.g. "Laptop"), **Add unit** with a serial number, assign it to a team member. Expected: it shows on that person's profile → **Assets** tab.
5. Report 1 towel **Broken** → quantity drops by 1 and History shows the loss with its value.
6. On a property's stock page set a **par level** of 8, then **Restock to par** → the shortfall is moved from the chosen store.
7. Sell a stock-tracked POS item at the till → that outlet's quantity drops on the Inventory list too.
8. Inventory → Locations: add a **store** owned by one property and shared with another. Sign in as the other property's manager → the store is visible.

## 3. Inventory admin pages and stock counts

**Context:** With the database in place, managers and staff needed screens to see where everything is, act on it, and check it regularly.

**Description:** Added the Inventory section: a spreadsheet-style list (place menu, search, category filter, sorting, multi-select with bulk move / set category / delete), an item page (details, where it is, units, history and every action), places (areas, count schedule, responsible person) and a place's stock (par levels, restock). Built a phone-first stock count screen that never moves stock by itself: differences go to a review queue where the owner or that property's manager resolves each line (missing, broken, stolen, found or recount). Counts due show on My Work, the Front Desk and the Dashboard. Employee profiles gained an Assets tab (what they hold, hand over, return or report lost). Added the Storekeeper job, and owner-only corrections (undo a movement, clear to zero, delete an item or place, reset inventory).

**How to test:**
**Test logins (dev):** owner@, manager@, store@ (storekeeper), housekeeping@, reception@ … `@tribalsand.test`.
1. Inventory list: change the **Place** menu, type in search, filter by category, click column headers to sort. Tick several rows (shift-click selects a range) → the bulk bar offers **Move**, **Set category** and (owner only) **Delete**.
2. Sign in as housekeeping or storekeeper → Today → **Stock count** → start a count for a place. Type numbers; a number that differs from Expected turns red. Submit. Expected: stock does **not** change yet.
3. Sign in as owner/manager → Inventory → **Counts**: resolve each difference (Missing / Broken / Stolen / Found / Recount). Expected: stock changes only now.
4. Give a place a count schedule (Inventory → Locations). Expected: it shows as due on My work, the Front desk and the Dashboard.
5. Team → open an employee → **Assets** tab: hand over an item, then return it, then report one lost.
6. Owner only: on an item page **Undo** a movement; delete an item; (on dev only) Reset inventory.
7. Access: storekeeper sees every Inventory page; reception and front-desk see no inventory at all.

## 4. Import supplier lists from Excel → orders → receiving

**Context:** The Maya Ilai fit-out arrived as supplier spreadsheets (master list plus container packing lists). Typing hundreds of items by hand was not realistic, and stock should only count once it is physically received.

**Description:** Import an Excel list into the catalogue: one item per supplier code, sections kept, item-code prefixes mapped to places (remembered per account) with list quantities becoming par levels. The import creates an order instead of stock. The order page receives quantities into places in several deliveries, or a whole container at once, with partial-receipt history and undo. All spreadsheet data is kept: HS codes per line and every packing-list row (boxes, dimensions, weight, cubes), with a packing-list page per container and badges where the packing list differs from the order. Includes a seed for the Maya Ilai shipment.

**How to test:**
**Before you start:** have the supplier Excel file (e.g. the Maya Ilai list) and sign in as owner.
1. Admin → Inventory → **Import** → upload the file.
2. Preview: one item per supplier code; for each code prefix choose where it goes (e.g. R → a room area). Confirm.
3. Expected: you land on the **order** page. Quantities are "Ordered", nothing is in stock yet; the Inventory list shows them under **On order**.
4. On a few rows type **Receive now** quantities and an **Into** place → **Save received**. Expected: stock appears at those places; each receipt is listed under "Received".
5. Pick a **Container** in the filter → **Receive this container** → only that container's quantities are received.
6. Click **Packing list** for a container → every row with boxes, L×W×H, weight and cubes; the totals match the spreadsheet.
7. The **Differences** filter shows lines where the packing list doesn't match the order.
8. **Undo** a receipt → "Still to come" goes back up and the stock comes off.
9. Import the same file again → no second order is created.

## 5. Accounting — companies, room folio, tax invoices and inter-company

**Context:** Properties belong to different legal companies and the shared outlets to a services company. Invoicing, KRA PINs and who-owes-whom had to be handled properly before eTIMS/QuickBooks.

**Description:** Phase 1: companies with KRA PIN checks, VAT/eTIMS flags, money accounts (bank, M-Pesa till/paybill, cash, card) and gapless document numbering, plus what each company owns (properties, outlets, stores). Phase 2a: the booking's Bill tab gained a folio — balance per currency, record payments, issue the tax invoice, credit notes, refunds and pro-forma; invoiced lines are locked; an accountant CSV. Phase 2b: POS sales issue their own invoice inside the sale, inter-company ledger and settlements (room charges and stock transfers between companies), partial credit notes, applying a security deposit to damages, and optional invoice-at-confirmation. Everything stays off until the owner switches invoicing on per company.

**How to test:**
**Before you start:** the accounting migrations have run on this database; sign in as owner.
1. Admin → Finance → **Companies** → New company. A wrong KRA PIN (e.g. `12345`) is refused; `P051234567X` format saves. Add money accounts (a bank account, an M-Pesa till).
2. Assign each property and outlet to a company → the "Still to assign" list empties.
3. Company → Details → **Invoicing starts on** = today.
4. Open a confirmed booking that checks out today or later → **Bill** tab → **Folio**: **Record payment** (M-Pesa asks for the code), then **Issue tax invoice**. Expected: a numbered invoice (e.g. `ABC-INV-000001`) that opens as a printable page.
5. Try deleting an invoiced bill line → refused; it shows an **Invoiced** badge.
6. **Credit note** (owner/manager, reason required) on one line → the balance updates.
7. Make a cash sale at an outlet whose company is live → an invoice is created and marked paid automatically.
8. Finance → **Invoices & payments**: totals per company/currency and **CSV** export. **Between companies** shows what one company owes another after a cross-company room charge.
9. Record a **security deposit**, then apply part of it to damages → the rest stays refundable.

## 6. Bookings — room combinations, no automatic holds, extras while booking

**Context:** Groups needed several rooms booked together, the owner decided the website should never place a hold on its own, and guests should be offered wellness and airport transfers while they book.

**Description:** A room combination is now one request: all rooms are held together or none, with one confirmation and one email, and confirming or cancelling any room applies to the group. Public forms no longer place 24h holds by default (owner switch in Settings): availability is still checked, the request is saved as an enquiry and reservations use Convert to Hold, which now emails the guest their booking link. Wellness treatments and airport transfers are offered in the booking pop-up and in the booking-confirmed email.

**How to test:**
1. Admin → Settings → **Booking Form Mode**: "website holds" is OFF (the owner's rule).
2. On a property page (e.g. Maya Kobe) choose dates and guests → pick a room → **Request to Book** → fill in and send. Expected: the message says it is a request (not "held"); it appears in Admin → Enquiries; the calendar does NOT show a hold.
3. Try dates that are already taken → refused.
4. In that enquiry → **Convert to Hold** with "Email the guest" ticked → the guest receives an email with their booking link; the hold appears on the calendar.
5. **Combination:** choose more guests than one room sleeps → **Request these rooms** → one enquiry listing all rooms. After converting, confirm ONE of the rooms → all rooms confirm together (Holds list shows a **Group** badge) and the guest gets one email.
6. In the booking pop-up, wellness treatments and airport transfers are offered; picked ones show on the enquiry.
7. The booking-confirmed email shows "Make the most of your stay" with extras.

## 7. Email Notifications Center

**Context:** Emails were sent from many places with no record, which caused wrong "Booking confirmed" emails and no way to check what a guest received.

**Description:** Every email now goes through one send path that writes a log row per recipient (including emails switched off or skipped). Owner catalogue of every email (trigger, recipients, on/off, staff recipient override, 30-day stats), preview and test send, an editor for the wording with placeholders and version history, delete/restore, and an email log for owners and managers. Booking confirmed/cancelled emails are a staff choice every time ("Email the guest" tick with a safe default, e.g. off for OTA addresses). Added a reusable type-to-confirm dialog for serious actions and SES delivery/bounce tracking.

**How to test:**
**Before you start:** sign in as owner. On dev, emails land in Mailpit (http://localhost:8025).
1. Admin → Settings → **Emails**: every email is listed with its trigger, recipients, on/off and 30-day stats.
2. Open one → **Preview**, then **Send test to me** → it arrives; the Email log shows it as a test.
3. **Edit** the wording (e.g. a subject using `{{guest_name}}`) → the preview updates live → Save → trigger the real email → it uses the new wording. An unknown placeholder like `{{xyz}}` is refused.
4. Switch an email **off** → trigger it → nothing is sent and the Email log shows **Suppressed**.
5. Confirm a booking with **Email the guest** unticked → no email; the log shows **Skipped**.
6. For a Booking.com guest address (`…@guest.booking.com`) the "Email the guest" tick starts OFF.
7. **Delete** an email (you must type DELETE to confirm) → it leaves the list; restore it from "Deleted emails".
8. Sign in as a manager → Email log shows only their property's emails.

## 8. Rates — compare every property, quote builder, global rate editor

**Context:** Reception had to open each property's calendar to compare prices and built quotes by hand; the owner had no quick way to change rates across rooms.

**Description:** The Rates page now has a Rate card and a Timeline across every property with a KES | USD switch. Bookings → Quote builder (also a pop-up on each enquiry) prices rooms and extras through the same pricing as the website, suggests options from the dates and party, saves options on the enquiry, prints a branded A4 quotation and uses owner-editable terms. The owner's Set-rates editor changes rates for many rooms at once (fixed price, % change, same as season, back to base) with preview, confirm, change log and undo, and a buyout check keeps each whole-property buyout equal to the sum of its rooms. Loaded the DIRECT nightly rates for Zuri, Maya Kobe and the villas (Jan 2027 – Jan 2029).

**How to test:**
1. Admin → **Rates** → **Rate card**: every room × season for the year. Switch **KES | USD** → amounts convert (marked ≈).
2. **Timeline** view: rooms × days coloured by season; holidays and highlights in the header.
3. Bookings → **Quote builder**: pick dates and party, add rooms and extras → totals update. A room with no price shows "no price set for these dates" — never 0. Click **Suggest options** → it fills sensible options.
4. Open an enquiry → **Build quote** → **Save to enquiry**. Expected: the Quotes list shows "Option 1"; **View PDF** opens the branded A4 quotation.
5. Owner only: Rates → **Set rates** → choose rooms, nights and a fixed price → **Preview** → **Confirm**. The new price shows on the Rate card and the property calendar. In **Rate changes** click **Undo** → the old price is back.
6. **Buyout check** (top of Rates): if a whole-property buyout ≠ the sum of its rooms, **Update buyout to match** fixes it.
7. Sign in as manager or reception → Rates is read-only (no Set rates).
8. Cross-check: the website booking widget quotes the same total for the same dates.

## 9. Admin redesign — navigation, Dashboard, tables and page search

**Context:** The sidebar had 63 links, pages reloaded on every click and there was no common landing page, so staff got lost and reported missing access.

**Description:** Sidebar regrouped from 63 to 35 links with tabs for related pages, all from one navigation definition. Every account lands on a role-shaped Dashboard (bento design) showing who they are, what needs them and their shortcuts. Pages use the full width; tables get pagination, full height and clean row lines. Admin links swap the content without reloading. Ctrl+K page search, enquiry page in tabs, new booking inline, a Messages "Desk" (list · chat · guest's stay), a redesigned AI assistant panel, and a Help & guides panel with "Show me" walkthroughs.

**How to test:**
**Test logins (dev):** owner@, manager@, reception@, housekeeping@, security@, shop@, hr@ … `@tribalsand.test`.
1. Sign in as each account → you land on the **Dashboard**, shaped to that role (greeting, "Needs you" list, shortcuts). Nothing it links to is refused.
2. Sidebar: groups with tabs at the top of each page. Clicking links changes the page without a full reload; the browser Back button works.
3. Press **Ctrl+K** → search pages → only pages that account can open are listed.
4. Lists: page size 10 / 25 / 50 / All at the bottom; tables reach the bottom of the window.
5. Open an enquiry → tabs Conversation · Details · Booking · Activity.
6. **Messages**: conversation list · chat · the guest's stay on the right; send a reply.
7. **AI assistant** (needs the AI key): ask "What's free for 2 adults next weekend?" → answer with prices.
8. **Help** at the top right of a page → guides for that page; **Show me** highlights the real buttons.
9. Repeat a few pages at phone width → no sideways scrolling.

## 10. Roles and access — Access by role, HR account, activity log

**Context:** Some pages were open to any logged-in account by URL, and the owner wanted to decide who sees what without a developer.

**Description:** Owner-editable "Access by role" matrix in Team → Staff (sections on/off per role, enforced on the pages, not just hidden). Tightened role gates (rates for owner/manager/reception only; back-of-house jobs never see guest messages). New HR account type: staff directory, employee profiles and private documents, attendance and leave across every property, with no access to bookings, money or the guest side. Employee profiles show an activity log of what the person did (sign-ins, till unlocks, sales, clock in/out, admin actions).

**How to test:**
1. Owner: Team → Staff → **Access by role** → turn **Rates** OFF for Reception → save.
2. Sign in as reception → Rates is gone from the sidebar, and typing `/admin/rates.php` is refused. Turn it back ON → it returns.
3. Sign in as **HR** (hr@tribalsand.test on dev): sees the staff directory, employee profiles, documents and attendance for every property. Typing `/admin/messages.php` or the Front desk URL is refused; no bookings, money or settings.
4. Sign in as housekeeping → no Messages link and the URL is refused.
5. Team → open an employee with a login → **Activity log**: sign-ins, till unlocks, sales, clock in/out and admin actions, with filter chips.

## 11. Channels and sync — two-way iCal, eZee import, Zuri relay

**Context:** OTA cancellations stayed blocked on our calendar and could bounce between channels; Zuri's system requires calls from a fixed IP.

**Description:** Two-way iCal sync: imported OTA blocks are tracked by UID so cancellations and date changes clear automatically, our own dates re-exported by an OTA are recognised as echoes, and a failed fetch never deletes anything. The bookings importer reads eZee's CRS report as downloaded. Calls to Zuri go through a fixed-IP relay, and the sync dashboard shows Zuri's row count and checksum next to a mismatch.

**How to test:**
1. Admin → Bookings → Calendar → **iCal feeds**: paste an OTA calendar link for a room → **Sync now** → its bookings appear as blocks on the calendar.
2. Cancel that test booking on the OTA (or remove it from a test feed) → **Sync now** → the block is removed.
3. Copy our **export** link into the OTA → our bookings, holds and closures show there as "Not available" (no guest names).
4. Paste a broken link → it turns red with an error and nothing is removed.
5. Bookings → **Import**: upload eZee's CRS bookings report (.xls) as downloaded → preview → import → bookings appear on the calendar.
6. Admin → **Zuri sync**: row counts and checksums match for each item.

## 12. Website — search fixes, other properties with space, reviews, menus

**Context:** Guests hit dead ends when a property was full, and several website sections were still hard-coded.

**Description:** Fixed search so one booked room no longer hides a property's other rooms. A full property page now lists the other properties with space for the same dates (same town first, then cheapest). Guest reviews come from the database and are owner-editable. Restaurant menus use the new Cards design (dish photos, currently hidden on the public menu). The guest concierge is linked from About. Maya Ilai's unit map uses the staff's room names.

**How to test:**
1. `/search` with dates where one room of a property is booked → that property's other rooms still show.
2. On a property page pick dates when it is full → the sidebar lists up to 3 **other properties with space**; click one → it opens with your dates and guests filled in.
3. Owner: Admin → Website → **Reviews** → add a review, Published + Show on home page → it appears on the home page.
4. Open `/menu?m=zuri` → the new Cards design with Food | Drinks and course chips; works on a phone.
5. Site menu → About → **Ask our concierge** opens the concierge chat.
6. Admin → Maya Ilai → Unit Map uses the staff names (V1–V8, S1–S8).

## 13. Guest portal — Postcard design, guest extras, one conversation

**Context:** The guest page felt like an admin screen, and owners wanted to choose which extras each property offers.

**Description:** Per-property guest extras chosen by the owner (show, feature, when to offer) built on the existing activities and transfers, with a reminder email before arrival. Guest page redesigned ("Postcard"): property photo and booking card, Extras tab, one conversation for all messages, a room key card, stay info as a bento grid with the property's real map, a day-by-day My trip, a Settings tab with cancel, and instant tab switches.

**How to test:**
1. Owner: Properties → a property → **Guest extras** → choose which extras show, feature up to 3, set when to offer them → Save.
2. Open a booking's guest link (from the booking email or the booking's **Manage** link). Home shows the property photo, the booking card and the featured extras.
3. **Extras** tab → add an extra with a time → it shows in **My trip** as "Waiting". In admin confirm the request → the guest page shows "Confirmed".
4. **Messages**: one conversation; **Reply** on a request card goes into that request's thread (staff see it on that request).
5. **Stay info**: Wi-Fi with Copy, check-in/out times, the property map with Directions, house rules.
6. **Settings** → Cancel booking → a styled confirmation, then the booking is cancelled.
7. Switching tabs doesn't reload the page; check it on a phone.

## 14. Developer setup, security and tests

**Context:** Two developers work in different ways and needed one shared way to run the site and one branch to meet on before production.

**Description:** One-command local environment (Docker) with a README, the `dev` branch workflow (work on dev, go live by merging dev into master), docs gathered in one place, internal folders and build files no longer reachable from the web, and all test suites passing.

**How to test:**
1. Clone the repo, copy `.env.example` to `.env`, set `DEV_ADMIN_PASSWORD`, run `docker compose up`.
2. Open http://localhost:8080 → the website loads. Open http://localhost:8080/admin/login.php → sign in as `owner@tribalsand.test` with that password.
3. Emails sent by the app appear in Mailpit: http://localhost:8025.
4. Internal folders are not web-reachable: e.g. `/docs/README.md` or `/tests/` on the live site return "not found / forbidden".
5. Run a test suite: `docker compose exec app php tests/site_search_logic.php` → "0 failed".

## 15. Password fields — hidden while typing, show/hide eye, proper validation

**Context:** Password fields were inconsistent: no way to check what was typed, browser-default error bubbles, and the change-password form allowed 8 characters while every other form required 10.

**Description:** One shared password component applied to every password and PIN field (admin login, reset password, change password in Settings, staff accounts, till PINs, travel-agent login). Characters stay hidden while typing; an eye button shows or hides them. Errors appear under the field and in the reusable toast (empty, too short, "passwords don't match", PIN must be 4–6 digits), checked before the form posts and again on the server. Live checklist on new passwords, Caps Lock warning, correct autocomplete hints for password managers, and the minimum is now 10 characters everywhere (change password also refuses reusing the current one).

**How to test:**
Check every password field: **Admin login**, **Forgot/Reset password**, **Settings → Change password**, **Team → Staff** (new manager/reception/HR account and "Set password"), **Point of Sale → Staff PINs**, **Agent login**.
1. Type a password → only dots show. Click the **eye** → the text shows and the icon changes; click again → hidden.
2. Submit with the password empty → a red message under the field ("Please enter a password.") AND a toast at the top right. The form does not send.
3. Settings → Change password: new password `short` → "must be at least 10 characters"; a different confirmation → "The passwords don't match"; the checklist under the field ticks ✓ as you type. Using your current password as the new one → refused by the server.
4. Turn **Caps Lock** on while typing → "Caps Lock is on" appears under the field.
5. Team → Staff → new **Manager** with a 5-character password → error, nothing created.
6. Staff PINs: `12a` → "A PIN is 4 to 6 digits"; two different PINs → "The PINs don't match". **Remove PIN** still works without filling the fields.
7. Wrong email/password on the login pages → the error shows as a toast.
8. Repeat on a phone: the eye is easy to tap and nothing overflows.

## 16. Website search — find anything from every page

**Context:** Guests had to navigate menus to find a room, restaurant, activity or guide. The ask was a search bar on the home page and in the sections, and a way to search from every page.

**Description:** Added a "find anything" search across the website: properties, rooms, activities, restaurants and menus, events, area guides, policies and journal articles, read live so new content is searchable once published. A "What are you looking for?" bar under the home page hero and on the Activities and Journal pages shows live, grouped results as you type (keyboard navigation included); `/` or Ctrl+K opens the same search from any page; and a full results page also works without JavaScript. (A header search button was tried and removed — the nav has no room for it.) Separate from the availability search for dates.

**How to test:**
1. Home page: under the big hero there is **"What are you looking for?"**. Type `zuri` → suggestions appear while typing, grouped (Places to stay, Restaurants & menus, Journal…) with the matching letters highlighted.
2. Use ↓ ↑ to move and **Enter** to open a suggestion; **Esc** closes the list.
3. Click a **Popular** chip (e.g. "Weddings") → it searches straight away.
4. Type `kilifi` and press **Search** → the full results page `/site-search?q=kilifi` with grouped results.
5. Type nonsense (e.g. `zzzz`) → "No results" with links to check availability or ask us.
6. The same bar is on the **Activities** and **Journal** pages.
7. On any page press **/** (or **Ctrl+K**) → the search window opens; Esc closes it.
8. Check on a phone: the bar fits; suggestions are readable.
9. The website header has **no** search button and the language switcher is fully visible.

## 17. Check: Laundry staff role in admin

**Context:** Asked whether the admin has a staff role for Laundry.

**Description:** Confirmed: "Laundry" exists as a staff job type (Team → Staff → Login accounts → job). Laundry staff land on My Work with their tasks, are treated as back-of-house (no guest messaging), and guest laundry requests and Laundry service pricing already exist. No change needed.

**How to test:**
1. Admin → Team → Staff → **Login accounts** → New → **Staff** → the **Job type** list includes **Laundry**.
2. Create a laundry account → note its access code.
3. Sign in on the **Onsite staff** tab with that code → lands on the Dashboard / **My work** with their tasks; no Messages link (guest messaging is refused).

## 18. Travel agent (trade) portal — sign-in fix and redesign

**Context:** A test travel-agent account couldn't sign in: the agent's details were typed on the staff login (`/admin/login`), while agents have their own portal login (`/agent/login.php`). The portal pages (Availability, Rates, Your requests, Sign in) also looked plain compared with the rest of the site and needed a modern, clean design.

**Description:** Fixed: an agent's correct email and password typed on the staff login now sign them into the trade portal (agent access only, never the admin) instead of showing "Invalid email or password"; staff logins are unchanged. Design: three directions mocked up for the whole portal (sign in, availability, rates, requests) — A "Clean light" (photo cards, one rounded search bar), B "Workspace" (dark sidebar, key numbers, filters + compact results list) and C "Editorial" (brand teal and sand, serif headings, photo hero, results grouped by property). Mockups: `docs/design/agent-portal-designs.html`. Built: design A "Clean light" (recommended — quickest to scan for occasional bookers and best on a phone): a split-screen sign in, a top bar with icon tabs and the agent's initials, one rounded search bar, every bookable option as a photo card (cheapest first, published price struck through, type and town filter chips), rates as one card per property, and requests with four headline numbers and coloured status pills. Same pricing as before; requests still never place a hold. Also added a local test agent login to the developer setup.

**How to test:**
**Before you start:** owner → Admin → Settings → **Travel agents** → create an agent (email, password, 10% discount). On dev you can use `agent@tribalsand.test` (password = DEV_ADMIN_PASSWORD).
1. Open `/agent/login.php` → split screen: teal panel on the left, sign-in form on the right. Wrong password → error toast. Correct → **Availability**.
2. Also check: type the agent's details on `/admin/login` → you land in the **trade portal** (not the admin).
3. Pick dates and guests → **Search**. Expected: photo cards, cheapest first; each shows the published price crossed out and the agent price (published − 10%), plus tags like "Fits 3 guests", "Rooms together", "Whole property".
4. Click **Boutique hotels**, **Private villas** or a town chip → only matching cards; the option count updates. **All** shows everything again.
5. Click **Request** on a card → fill the traveller's name → **Request to book**. Expected: a success toast and **Your requests** shows it as **Sent**; the numbers at the top update.
6. Admin → Enquiries: the agent's request is there with the agent price. **Convert to Hold** → in the portal the status becomes **On hold** (with a countdown); confirm the hold → **Confirmed**, and "Booked at your rate" increases.
7. **Rates** tab: one card per property with the discount badge; prices match the admin Rates page minus the discount.
8. Check on a phone: the tabs scroll, the search bar stacks, cards are one per row. **Sign out** works.

## 19. Website and admin tidy-ups — nav search button removed, Help moved

**Context:** The search button added to the website header pushed the language switcher off screen, and Help & guides sat at the top of the admin sidebar with an intro line the owner didn't want.

**Description:** Removed the search button from the website header (search stays on the home page, Activities and Journal bars, the results page, and `/` or Ctrl+K). Moved Help & guides to the last link in the admin sidebar (bottom of Settings, still for every account) and removed the intro line on the Help & guides page.

**How to test:**
1. Open the website at full width → the header has no search button and the language switcher (e.g. ENGLISH) is fully visible.
2. Sign in to admin as any account → open the **Settings** group at the bottom of the sidebar → **Help & guides** is the last link, for every role.
3. Open Help & guides → the heading "How can we help…?" has no intro line under it.

## 20. Finance → Reports — the monthly report (design C)

**Context:** The reservations team writes a monthly report by hand from eZee (August 2026: gross → commission → net, extras, each property, daily occupancy, top partners, notes). The admin Reports page showed something different: only gross room revenue, almost everything filed as "OTA", no commission, no extras, an occupancy figure that looked wrong, and revenue counted only on the arrival date.

**Description:** Reports is rebuilt as the monthly report (design C, picked from three mockups in `docs/design/reports-designs.html`; final mockup `docs/design/reports-final-c.html`). A period picker (‹ › with Month / Quarter / Year / 12 months) and a property picker; a headline written from the figures; Total revenue, Rooms gross, Commission, Rooms net and Extras, each with the change against the period before; occupancy, ADR, nights, stays, cancellations and enquiries. Then a card per property (with its photo and a bar per night), occupancy day by day, extras by type or property, booking channels and top partners, nights already booked for the coming months, the portfolio table, and the team's notes for the period (Highlights / Watch / Next steps), which print with the report (**Print / PDF**). Revenue now counts each night of a stay in the month it falls in, the same as eZee's stay-date reports. The eZee import now reads the Source and Commission columns: Booking.com/Expedia/Airbnb → OTA, a named travel agent → agent, walk-in/email/direct → direct; importing a report again corrects the channel and commission on bookings already imported. Removed from the page: the Source filter (channels have their own card) and the separate By source / By month tables. Migration `add_bookings_channel_commission.sql` (run on production via `/admin/migrate.php`). Not yet: eZee's "Extra charges" report is not imported (extras come from priced guest requests and bill charges in our system); QuickBooks is a later phase.

**How to test:**
**Before you start:** run `add_bookings_channel_commission.sql` on the environment. Sign in as the owner (or a house manager).
1. Finance → **Reports**. The header shows the current month; use **‹ ›** to go to August 2026. Check the headline names the month, the total and the top property.
2. The five figures at the top: Total = Rooms net + Extras; Commission shows "% of gross"; the small badges compare with July.
3. Switch **Quarter**, **Year**, **12 months** → the figures and the label change; on Year / 12 months the occupancy grid shows months instead of days.
4. Pick one property in **All properties** → every section shows only that property.
5. Bookings → **Import** → upload the eZee CRS report again for Zuri → rows already on the calendar say "Already imported — channel and commission updated". Back on Reports: **Booking channels** now shows Direct / Travel agents / OTAs, and **Top partners** lists Booking.com and the agents.
6. Hover or tap a square in **Occupancy, day by day** → the exact % for that night.
7. **Write notes for this period** → fill Highlights / Watch / Next steps → **Save notes** → they show under "Notes from reservations" with your name. Another month has its own notes.
8. **Print / PDF** → the print preview shows the report without the sidebar and buttons. **Export CSV** downloads the bookings with gross, commission, net and the part in the period.
9. Check on a phone: everything stacks, the table turns into one card per property, nothing scrolls sideways.
