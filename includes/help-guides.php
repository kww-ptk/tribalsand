<?php
declare(strict_types=1);
/**
 * Help & guides — the team's how-to library (admin Help, design B, Oct 2026).
 *
 * Guides live HERE, next to the code, so a feature and its guide ship in the same
 * change. Each guide names the admin pages it is about; the on-page Help panel
 * (admin/assets/admin-help.js) lists the guides for the page that is open, and the
 * library (admin/help.php) lists every guide the account may read.
 *
 * Visibility follows the sidebar exactly: a guide is shown to an account only when
 * at least one of its pages is in that account's RESOLVED nav (help_visible_pages()),
 * so reception never reads a guide for an owner-only page. Never show a guide by
 * role name — the owner's "Access by role" overrides are already in the nav.
 *
 * Guide shape:
 *   slug     stable id (links + "Show me" hand-off; never rename)
 *   title    what the person wants to do, in their words
 *   area     library section (HELP_AREAS)
 *   minutes  reading/doing time
 *   pages    admin page files it is about (first = where "Show me" starts)
 *   summary  one sentence
 *   steps    [['text' => …, 'target' => CSS selector (optional), 'open' => selector to click first (optional)]]
 *            text is plain + **bold** (help_rich()); a step whose target is missing on
 *            the page is shown as a plain card in the walkthrough, never an error.
 *   tips     optional list of plain + **bold** lines
 *
 * A new admin page that people use day to day gets a guide here; the test
 * (tests/help_logic.php) fails on a guide naming a page that doesn't exist.
 */

/** Library sections, in display order. */
const HELP_AREAS = [
    'start'    => 'Getting started',
    'bookings' => 'Bookings & enquiries',
    'guests'   => 'Front desk & guests',
    'extras'   => 'Guest extras',
    'rates'    => 'Rates & quotes',
    'team'     => 'Team',
    'restaurant' => 'Restaurant',
    'pos'      => 'Point of Sale',
    'inventory'=> 'Inventory',
    'finance'  => 'Finance & reports',
    'settings' => 'Settings',
];

/** Every guide. Pure data. */
function help_guides(): array {
    return [
        // ── Getting started ─────────────────────────────────────────────
        ['slug' => 'getting-around', 'area' => 'start', 'minutes' => 3, 'pages' => ['dashboard.php', 'help.php'],
         'title' => 'Find your way around the admin',
         'summary' => 'Where things are, how to search for a page, and where to get help.',
         'steps' => [
            ['text' => 'The **Dashboard** is your home page. The **Needs you** list shows what is waiting on you: requests to answer, unread messages, tasks.'],
            ['text' => 'The sidebar groups the pages (Today, Bookings, Team…). You only see the pages your account can open.', 'target' => '.sidebar__nav'],
            ['text' => 'Press **Ctrl K** (⌘K on a Mac) or this button to jump to any page or guide by typing its name.', 'target' => '.navsearch-btn'],
            ['text' => 'Some pages have **tabs** across the top. Each tab is a related page, e.g. Calendar · Conflicts · Highlights.'],
            ['text' => 'Stuck on a page? Press **Help** at the top right of the page. It lists the guides for that page, and **Show me** walks you through it.', 'target' => '[data-help-open]'],
         ],
         'tips' => ['Pages open without reloading, so the back button works as usual.']],

        // ── Finance & reports ──────────────────────────────────────────
        ['slug' => 'monthly-report', 'area' => 'finance', 'minutes' => 3, 'pages' => ['reports.php'],
         'title' => 'Read the monthly report',
         'summary' => 'What the month earned, property by property, and how to print it for the team.',
         'steps' => [
            ['text' => 'Pick the period with the **‹ ›** arrows (Month, Quarter, Year or 12 months) and, if you like, one property. The headline and figures are written from the bookings ledger.', 'target' => '[data-help="rp-band"]'],
            ['text' => '**Total revenue** = rooms after commission + extras. Each figure shows the change against the period before.'],
            ['text' => 'One card per property: its total, gross, commission and extras, and a bar per night showing how full it was.', 'target' => '[data-help="rp-props"]'],
            ['text' => 'Occupancy day by day: darker squares are fuller nights. Tap a square for the exact figure.', 'target' => '[data-help="rp-occupancy"]'],
            ['text' => 'Channels and top partners show where the room revenue came from: direct, travel agents or OTAs.', 'target' => '[data-help="rp-channels"]'],
            ['text' => 'Write the team’s **Highlights**, **Watch** and **Next steps** here. Press **Print / PDF** to send the whole report.', 'target' => '[data-help="rp-notes"]'],
         ],
         'tips' => ['Revenue counts each night of a stay in the month it falls in, the same as eZee’s stay-date reports.',
                    'Import the eZee CRS report again to fill in channels and commission for older bookings.']],

        // ── Bookings & enquiries ───────────────────────────────────────
        ['slug' => 'new-request', 'area' => 'bookings', 'minutes' => 2, 'pages' => ['submissions.php', 'submission-view.php'],
         'title' => 'What happens when a guest books on the website',
         'summary' => 'Every website booking arrives as a request. Nothing is held until you convert it.',
         'steps' => [
            ['text' => 'A guest picks dates and presses **Request to Book**. The website checks the dates are free, but it does **not** hold them.'],
            ['text' => 'The request lands in **Enquiries** with the room, dates, party, the price they were shown and any extras they ticked.'],
            ['text' => 'You reply, or build a quote, and when the guest is ready you press **Convert to Hold**. That blocks the dates for 24 hours and emails the guest their booking link.'],
            ['text' => 'Confirm the booking in **Bookings** once the guest has paid.'],
         ],
         'tips' => ['Two guests can request the same dates. Check the Calendar before converting.',
                    'The owner can let the website hold by itself again in Settings → Booking Form Mode. It is off by default.']],

        ['slug' => 'reply-enquiry', 'area' => 'bookings', 'minutes' => 3, 'pages' => ['submission-view.php', 'submissions.php'],
         'title' => 'Reply to an enquiry',
         'summary' => 'Write to the guest from the enquiry, so the whole conversation stays in one thread.',
         'steps' => [
            ['text' => 'Open the enquiry from **Bookings → Enquiries**.'],
            ['text' => 'Press **Reply**.', 'target' => '[data-sv-reply]'],
            ['text' => 'Write your answer here. **Draft options with AI** can write a first version with live prices for you to edit.', 'target' => '#replyBody'],
            ['text' => 'Choose **Reply sent to guest** and tick **Also email this reply**. An internal note is only seen by the team.', 'target' => '#kindReply'],
            ['text' => 'Press **Add to thread**. The guest’s answer comes back into this thread by itself.'],
         ],
         'tips' => ['Change the enquiry’s status (e.g. Quoted, Booked) so the team knows where it stands.']],

        ['slug' => 'convert-to-hold', 'area' => 'bookings', 'minutes' => 3, 'pages' => ['submission-view.php'],
         'title' => 'Convert an enquiry to a hold',
         'summary' => 'Block the dates for 24 hours and send the guest their booking link.',
         'steps' => [
            ['text' => 'Check the dates are still free on the **Calendar**. Website requests don’t hold dates, so someone else may have taken them.'],
            ['text' => 'Open the **Booking** tab of the enquiry.', 'target' => '[data-sv-tab="booking"]', 'open' => '[data-sv-tab="booking"]'],
            ['text' => 'Pick the room and unit, and check the dates and the guest’s name and email.', 'target' => '[data-sv-panel="booking"] .detail-grid'],
            ['text' => 'Leave **Email the guest their booking link** on. The guest gets an email that their dates are held, with a link to their guest page where they can add extras (massage, kite lessons, transfers).', 'target' => '[data-help="convert-email"]'],
            ['text' => 'Press **Create Hold**. The hold lasts 24 hours; confirm it in **Bookings** once the guest pays.', 'target' => '[data-help="convert-submit"]'],
         ],
         'tips' => ['Extras the guest ticked when they asked are added to the booking automatically.',
                    'After converting, the booking link and code are shown here so you can also send them on WhatsApp.']],

        ['slug' => 'confirm-booking', 'area' => 'bookings', 'minutes' => 2, 'pages' => ['holds.php', 'booking.php'],
         'title' => 'Confirm or cancel a booking',
         'summary' => 'Turn a 24-hour hold into a confirmed booking, or free the dates.',
         'steps' => [
            ['text' => 'Open **Bookings**. Holds waiting for you show as **pending**.'],
            ['text' => 'The **Email** tick decides if the guest is emailed. It is off for booking.com/Airbnb addresses and channel imports, since the channel already told them.', 'target' => '.hold-mail'],
            ['text' => 'Press **✓** to confirm or **✕** to decline/cancel. You are asked once more before anything happens.'],
         ],
         'tips' => ['Rooms booked together (a group) are confirmed or cancelled together.',
                    'Open a booking with the pencil icon to see its Requests, Messages, Bill and Check-in.']],

        ['slug' => 'new-booking', 'area' => 'bookings', 'minutes' => 2, 'pages' => ['holds.php', 'hold-new.php'],
         'title' => 'Create a booking by hand',
         'summary' => 'For a phone, walk-in or WhatsApp booking that didn’t come through the website.',
         'steps' => [
            ['text' => 'In **Bookings**, press **New booking**.', 'target' => '[data-hn-open]'],
            ['text' => 'Pick the room, the dates and enter the guest’s name and email. It is saved as a pending hold.'],
            ['text' => 'Confirm it from the Bookings list when the guest has paid.'],
         ]],

        ['slug' => 'booking-workspace', 'area' => 'bookings', 'minutes' => 3, 'pages' => ['booking.php'],
         'title' => 'Work on one booking',
         'summary' => 'Everything about a stay lives in its tabs.',
         'steps' => [
            ['text' => '**Requests**: extras, activities and transfers the guest asked for. Confirm each one and set its price.'],
            ['text' => '**Messages**: chat with the guest. They see it in their guest page.'],
            ['text' => '**Bill**: charges, payments and invoices. **Check-in**: guests, passports, signatures and the deposit card.'],
            ['text' => '**Details**: dates, room, guest and confirm/cancel. **Activity**: history and every email sent.'],
         ],
         'tips' => ['Press **Share reservation** on the Check-in tab to show the guest their bill in their guest page.']],

        ['slug' => 'calendar', 'area' => 'bookings', 'minutes' => 3, 'pages' => ['gantt.php'],
         'title' => 'Use the calendar',
         'summary' => 'See every room by day, close dates and move bookings.',
         'steps' => [
            ['text' => 'Each row is a room or unit, each column a day. Bars are bookings, holds and closures. Red days are public holidays.'],
            ['text' => 'Drag across empty days on a row to close those dates (maintenance, owner stay).'],
            ['text' => 'Drag a bar to move it to other dates or another unit.'],
            ['text' => 'Press **Sync now** to pull the latest Booking.com and Airbnb bookings.', 'target' => '#syncBtn'],
         ],
         'tips' => ['At Maya Ilai a staff booking takes the whole villa, so it is refused when a bedroom in it is already sold.']],

        ['slug' => 'conflicts', 'area' => 'bookings', 'minutes' => 2, 'pages' => ['conflicts.php'],
         'title' => 'Sort out a double booking',
         'summary' => 'When a channel booking lands on dates we already sold.',
         'steps' => [
            ['text' => 'Each row shows our booking and the channel booking for the same room and dates.'],
            ['text' => '**Keep Hold**: our booking stays and the channel block is discarded, so ask the channel to cancel theirs. **Keep OTA**: our hold is cancelled and the guest told.'],
         ],
         'tips' => ['Move one of the guests to a free room first if you can; then nobody loses their stay.']],

        // ── Front desk & guests ────────────────────────────────────────
        ['slug' => 'front-desk', 'area' => 'guests', 'minutes' => 2, 'pages' => ['frontdesk.php'],
         'title' => 'Run the day at the front desk',
         'summary' => 'Today’s arrivals, departures and who is in house.',
         'steps' => [
            ['text' => 'Pick the property at the top if you look after more than one.'],
            ['text' => '**Arriving**: check who has finished online check-in. **Departing**: settle the bill before they go. **In house**: who is staying tonight.'],
            ['text' => 'Open a guest to see their booking, requests and bill.'],
         ]],

        ['slug' => 'guest-messages', 'area' => 'guests', 'minutes' => 2, 'pages' => ['messages.php'],
         'title' => 'Answer a guest message',
         'summary' => 'Guests write from their guest page; you answer here.',
         'steps' => [
            ['text' => 'Pick a conversation on the left. Unread ones are marked.'],
            ['text' => 'Write here and press **Enter** to send. Quick replies fill in common answers.', 'target' => '#amForm'],
            ['text' => 'The panel on the right shows the guest’s stay. Confirm, finish or decline their request there.'],
         ]],

        ['slug' => 'guest-requests', 'area' => 'guests', 'minutes' => 2, 'pages' => ['concierge-desk.php'],
         'title' => 'Handle guest requests',
         'summary' => 'Extras, transfers, laundry and activities guests asked for.',
         'steps' => [
            ['text' => 'New requests are at the top. Each shows the guest, the booking, the day and time they want.'],
            ['text' => 'Assign it to the person who will do it, then press **✓ Accept** (or **✕ Decline**; the guest is told).'],
            ['text' => 'Set or change the price on the booking’s **Requests** tab (pencil icon). Press **Mark done** when it has happened.'],
         ],
         'tips' => ['A request with no price shows as "On request" to the guest until you set one.']],

        ['slug' => 'ai-assistant', 'area' => 'guests', 'minutes' => 1, 'pages' => ['assistant.php'],
         'title' => 'Ask the AI what’s free and what it costs',
         'summary' => 'Live availability and prices in plain words.',
         'steps' => [
            ['text' => 'Type a question like "Is Zuri free 12–15 Nov for 2 adults? What’s the total?"', 'target' => '#aiqInput'],
            ['text' => 'The answer comes from the live calendar and rates. Press **Copy for guest** or **Build a quote**.'],
         ],
         'tips' => ['The AI can quote but never books or holds. Use Convert to Hold for that.']],

        // ── Guest extras ───────────────────────────────────────────────
        ['slug' => 'guest-page', 'area' => 'extras', 'minutes' => 2, 'pages' => ['booking.php', 'submission-view.php', 'venue-edit.php'],
         'title' => 'What the guest sees on their booking page',
         'summary' => 'The page the booking link opens: their stay, extras, messages and bill.',
         'steps' => [
            ['text' => '**Home**: a photo of the property, their booking card and the extras you chose for that property.'],
            ['text' => '**Extras**: everything they can add. They pick a day, a time of day and how many people, then **Add to my stay**.'],
            ['text' => '**My trip**: each extra with its status: Waiting, Confirmed or Declined. Then **Messages** and the **Bill**.'],
            ['text' => 'Every extra is a request. Nothing is charged online; it goes on the bill once you confirm it.'],
         ]],

        ['slug' => 'guest-extras-setup', 'area' => 'extras', 'minutes' => 4, 'pages' => ['venue-edit.php'],
         'title' => 'Choose the extras a property offers',
         'summary' => 'Decide what guests at each property can add, in what order, and what is featured.',
         'steps' => [
            ['text' => 'Go to **Website → Properties**, open the property and press the **Guest extras** tab.', 'target' => '[data-help="extras-tab"]'],
            ['text' => 'Switch **Offer extras** on for the property.', 'target' => '[data-help="extras-on"]'],
            ['text' => 'Tick **Show** on each activity, transfer or wellness treatment the guests should see. Drag the handles to change the order.', 'target' => '[data-help="extras-list"]'],
            ['text' => '**Featured** extras appear on the guest’s home screen and in the booking email (up to 3). **When** decides if it is offered before arrival, during the stay, or both.'],
            ['text' => 'Press **Save extras**.', 'target' => '[data-help="extras-save"]'],
         ],
         'tips' => ['Below the list: put the featured extras in the booking emails, and choose when the "add to your stay" reminder email goes out (e.g. 3 days before arrival, or off).',
                    'Prices come from the activity or the service price list. Change a price there, not here.',
                    'An extra with no price shows "On request" to the guest.']],

        // ── Rates & quotes ─────────────────────────────────────────────
        ['slug' => 'build-quote', 'area' => 'rates', 'minutes' => 3, 'pages' => ['quote-builder.php', 'submission-view.php'],
         'title' => 'Build and send a quote',
         'summary' => 'Price rooms, activities and transfers for any dates, then send it as a PDF or in a reply.',
         'steps' => [
            ['text' => 'On an enquiry press **Build quote** (or open **Bookings → Quote builder**).', 'target' => '#qbOpenBtn'],
            ['text' => 'Check dates and party, then **Suggest options** to fill in rooms that fit, or add rooms yourself.'],
            ['text' => 'Add activities, transfers or a discount. Switch KES | USD to see either currency.'],
            ['text' => '**Save to enquiry** keeps it as Option 1, 2…; **Insert into reply** puts it in your answer; **Print / PDF** makes the quotation.'],
         ]],

        ['slug' => 'set-rates', 'area' => 'rates', 'minutes' => 3, 'pages' => ['rates.php'],
         'title' => 'Change room prices',
         'summary' => 'Set a price for many rooms and nights at once, check it, and undo if needed.',
         'steps' => [
            ['text' => 'Press **Set rates**, or click a price on the Rate card.', 'target' => '[data-re-open]'],
            ['text' => 'Pick the rooms and nights, then the new price, a % change, or "back to base price".'],
            ['text' => 'Press **Preview** to see every night that changes, then **Confirm & save**.'],
            ['text' => 'Every change is listed under **Rate changes** with **Undo**.'],
         ],
         'tips' => ['Only the owner can change prices. Everyone else sees this page read-only.']],

        // ── Team ───────────────────────────────────────────────────────
        ['slug' => 'staff-accounts', 'area' => 'team', 'minutes' => 3, 'pages' => ['staff.php'],
         'title' => 'Give someone a login',
         'summary' => 'Create an admin account with the right role and properties.',
         'steps' => [
            ['text' => 'Press **Add account**.', 'target' => '#addAccountBtn'],
            ['text' => 'Choose the role: **Manager** (runs properties), **Reception** (bookings and guests) or **Staff** with a job (housekeeping, driver, shop…).'],
            ['text' => 'Pick the properties they work at. They will only see those.'],
            ['text' => 'Managers and reception sign in with their email and a password you set (10+ characters). Staff sign in with an access code. Press **Create**.'],
         ]],

        ['slug' => 'access-by-role', 'area' => 'team', 'minutes' => 2, 'pages' => ['staff.php'],
         'title' => 'Decide what each role can open',
         'summary' => 'Switch sections on or off for a whole role.',
         'steps' => [
            ['text' => 'In **Team → Staff**, open the **Access by role** tab.'],
            ['text' => 'Pick a role, then switch sections on or off. Changed switches are marked.'],
            ['text' => 'Owner-only sections (settings, prices, website) can never be given away.'],
         ]],

        ['slug' => 'staff-directory', 'area' => 'team', 'minutes' => 3, 'pages' => ['staff.php'],
         'title' => 'Add or update someone in the staff directory',
         'summary' => 'Everyone who works for Tribal Sand, with position, property and off day.',
         'steps' => [
            ['text' => 'Open **Team → Staff**. The **Directory** lists everyone, grouped by property.'],
            ['text' => 'Press **Add team member**, or open a person to change their details.', 'target' => '#addPersonBtn'],
            ['text' => 'Fill in **Full name**, **Position**, **Department**, **Property** and the **Weekly off-day** (attendance uses it to mark days off).'],
            ['text' => 'Set **Status** to inactive when someone leaves. Their history and documents stay.'],
         ],
         'tips' => ['A person in the directory does not need a login. Logins are created by the owner under Login accounts.']],

        ['slug' => 'employee-documents', 'area' => 'team', 'minutes' => 2, 'pages' => ['employee.php'],
         'title' => 'Keep contracts and ID documents on an employee',
         'summary' => 'Private files on each person’s profile: contracts, IDs, certificates.',
         'steps' => [
            ['text' => 'Open the person from the staff directory and go to the **Documents** tab.', 'open' => '[data-tab="documents"]', 'target' => '[data-tab="documents"]'],
            ['text' => 'Choose one or more files (PDF, Word, JPG, PNG; up to 15 MB each), add a label and press **Upload**.'],
            ['text' => 'Documents are private: only the owner, managers of that property and HR can open them.'],
            ['text' => 'The **Employment** tab holds contract type, start and end dates and HR notes. Press **Save details**.', 'target' => '[data-tab="employment"]'],
         ]],

        ['slug' => 'attendance-day', 'area' => 'team', 'minutes' => 3, 'pages' => ['attendance.php'],
         'title' => 'Record or correct attendance times',
         'summary' => 'Fill in a day for everyone, fix a missed clock-out, see the month.',
         'steps' => [
            ['text' => 'Open **Team → Attendance**. The **Daily** view shows one day for everyone. Use the arrows to change the day.'],
            ['text' => 'For each person set the **Status** and the **In / Out** times. **Quick** buttons fill a standard shift.'],
            ['text' => '**Bulk fill blank rows** fills everyone not yet done; **Auto off-days** marks people on their weekly off-day.'],
            ['text' => 'Times scanned at a clock tablet appear as **Self-recorded**, with a photo. Correct them here if someone forgot to scan out.'],
            ['text' => '**Month grid** shows everyone × every day, with totals and overtime. **Export CSV** opens it in Excel.'],
         ]],

        ['slug' => 'leave', 'area' => 'team', 'minutes' => 2, 'pages' => ['attendance.php'],
         'title' => 'Record and approve leave',
         'summary' => 'Annual, sick and other leave, approved in one place.',
         'steps' => [
            ['text' => 'Open **Team → Attendance → Leave**. The tab shows how many requests are waiting.'],
            ['text' => 'To record leave, pick the **Staff member**, the **Type**, **Start date** and **End date**, then **Submit request**.'],
            ['text' => 'Press **Approve** or **Decline** on a waiting request. Approved days show on the attendance grid.'],
         ]],

        ['slug' => 'clock-cards', 'area' => 'team', 'minutes' => 3, 'pages' => ['attendance-cards.php', 'attendance-devices.php'],
         'title' => 'Clock cards and the clock tablet',
         'summary' => 'Staff scan a card on a tablet to clock in and out.',
         'steps' => [
            ['text' => 'The owner switches clocking in on under **Attendance → Clock kiosks**.'],
            ['text' => 'On the tablet, sign in and open **/clock** to register it to a property. The tablet then stays on the clock screen.'],
            ['text' => '**Clock cards**: pick the property and press **Print all**. Each person gets a card with their own code.'],
            ['text' => 'Lost card? Press **Reissue card**. The old card stops working straight away.'],
         ],
         'tips' => ['Remove a lost or replaced tablet with **Revoke** on the Clock kiosks page.']],

        // ── Restaurant ─────────────────────────────────────────────────
        ['slug' => 'table-reservations', 'area' => 'restaurant', 'minutes' => 2, 'pages' => ['reservations.php'],
         'title' => 'Confirm a table reservation',
         'summary' => 'Guests ask for a table online; you confirm or cancel.',
         'steps' => [
            ['text' => 'New requests are **pending**. Check you have space at that time.'],
            ['text' => 'Press **✓** to confirm; the guest is emailed. **✕** cancels it.'],
            ['text' => 'Use **Book table** for a phone booking.'],
         ]],

        // ── Point of Sale ──────────────────────────────────────────────
        ['slug' => 'void-sale', 'area' => 'pos', 'minutes' => 2, 'pages' => ['pos-sales.php'],
         'title' => 'Correct a till sale',
         'summary' => 'Sales can’t be edited. A mistake is voided and rung up again.',
         'steps' => [
            ['text' => 'Open the sale from **Sales**.'],
            ['text' => 'Press **Void**, give the reason and confirm. Stock goes back and a room charge leaves the bill.', 'target' => '.pss-voidbtn'],
            ['text' => 'Ring the sale up again correctly on the till.'],
         ],
         'tips' => ['A room charge already on an issued invoice can’t be voided; ask the owner for a credit note.']],

        // ── Inventory ──────────────────────────────────────────────────
        ['slug' => 'stock-count', 'area' => 'inventory', 'minutes' => 3, 'pages' => ['inventory-count.php', 'inventory-counts.php'],
         'title' => 'Count the stock at a place',
         'summary' => 'Count on a phone or tablet; differences go to a manager.',
         'steps' => [
            ['text' => 'Pick the place and press **Start counting**. Places due are at the top.'],
            ['text' => 'For each item type what you count, or press **=** when it matches what is expected.'],
            ['text' => 'Press **Submit count**. Counting never changes the stock; a manager decides on each difference in **Counts**.'],
         ]],

        ['slug' => 'receive-order', 'area' => 'inventory', 'minutes' => 3, 'pages' => ['inventory-orders.php', 'inventory-order.php'],
         'title' => 'Receive a delivery',
         'summary' => 'Book what arrived from a supplier order into a place.',
         'steps' => [
            ['text' => 'Open the order from **Inventory → Orders**.'],
            ['text' => 'For each line, type how many arrived under **Receive now** and pick **Into** (the place).'],
            ['text' => 'Press **Save received**. Part deliveries are fine; the rest stays "still to come".', 'target' => '.io-row-save'],
         ]],

        // ── Settings ───────────────────────────────────────────────────
        ['slug' => 'emails', 'area' => 'settings', 'minutes' => 2, 'pages' => ['emails.php', 'email-edit.php', 'email-log.php'],
         'title' => 'Change or switch off an email',
         'summary' => 'Every email the system sends, its wording and whether it is on.',
         'steps' => [
            ['text' => 'In **Settings → Emails** each email shows when it is sent and to whom.'],
            ['text' => 'Switch it on or off, or press **Edit** to change the wording. The preview shows exactly what the guest gets.'],
            ['text' => '**Email log** lists every email sent, and every one not sent with the reason.'],
         ]],

        ['slug' => 'website-holds', 'area' => 'settings', 'minutes' => 1, 'pages' => ['settings.php'],
         'title' => 'Let the website hold dates (or not)',
         'summary' => 'Off by default: the reservations team makes every hold.',
         'steps' => [
            ['text' => 'In **Settings**, under Booking Form Mode, **Let the website place 24-hour holds by itself** controls all public booking forms.', 'target' => '[data-help="website-holds"]'],
            ['text' => 'Off: the website checks the dates and sends a request; reservations use **Convert to Hold**. On: the website blocks the dates for 24 hours itself.'],
         ]],
    ];
}

/** Every admin page the account can open, from the RESOLVED nav. Pure. */
function help_visible_pages(array $nav): array {
    $pages = [];
    foreach ($nav['groups'] ?? [] as $g) foreach ($g['items'] as $it) foreach ($it['pages'] as $p) $pages[$p] = true;
    return array_keys($pages);
}

/** The guides this account may read (any of its pages visible), in library order. Pure. */
function help_visible_guides(array $guides, array $visiblePages): array {
    $vis = array_flip($visiblePages);
    $out = array_values(array_filter($guides, function (array $g) use ($vis): bool {
        foreach ($g['pages'] as $p) if (isset($vis[$p])) return true;
        return false;
    }));
    $order = array_flip(array_keys(HELP_AREAS));
    usort($out, fn($a, $b) => ($order[$a['area']] ?? 99) <=> ($order[$b['area']] ?? 99));
    return $out;
}

/** Guides about one page. Pure. */
function help_guides_for_page(array $guides, string $page): array {
    return array_values(array_filter($guides, fn($g) => in_array($page, $g['pages'], true)));
}

/** Plain text with **bold** → safe HTML. */
function help_rich(string $text): string {
    return preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
}

/** One guide for the browser: text pre-rendered, only what the panel needs. Pure. */
function help_guide_payload(array $g): array {
    return [
        's' => $g['slug'], 't' => $g['title'], 'a' => HELP_AREAS[$g['area']] ?? '', 'm' => (int)$g['minutes'],
        'p' => $g['pages'], 'sum' => $g['summary'],
        'st' => array_map(fn($s) => array_filter(['h' => help_rich($s['text']), 'q' => $s['target'] ?? null, 'o' => $s['open'] ?? null]), $g['steps']),
        'tips' => array_map('help_rich', $g['tips'] ?? []),
    ];
}

/** Ctrl+K entries for the guides (same shape as admin_nav_search_index() items). Pure. */
function help_search_items(array $guides): array {
    return array_map(fn($g) => ['t' => $g['title'], 'g' => 'Help', 'h' => '/admin/help.php?g=' . rawurlencode($g['slug']), 'i' => 'help',
                                'k' => 'help guide how to ' . $g['summary']], $guides);
}

/**
 * The Help side panel + its guide index, printed once per full page load OUTSIDE
 * .admin-content (like the page search), so no-reload page swaps keep it. The
 * script picks the guides for whatever page is showing when it opens.
 */
function help_drawer_html(array $guides): string {
    $json = json_encode(array_map('help_guide_payload', $guides), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $q = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.6 9.3a2.5 2.5 0 0 1 4.8.9c0 1.7-2.4 2.2-2.4 3.6"/><path d="M12 17h.01"/></svg>';
    return '<button type="button" class="helpfab" data-help-open aria-label="Help for this page">' . $q . '<span>Help</span></button>'
         . '<aside class="helpdrawer" id="helpDrawer" hidden aria-label="Help for this page">'
         . '<div class="helpdrawer__head"><strong data-help-title>Help for this page</strong>'
         . '<button type="button" class="btn-icon" data-help-close aria-label="Close help">×</button></div>'
         . '<div class="helpdrawer__search"><input type="search" class="inp" id="helpSearch" placeholder="Search all guides" autocomplete="off" aria-label="Search all guides"></div>'
         . '<div class="helpdrawer__body" id="helpBody"></div>'
         . '<div class="helpdrawer__foot"><a href="/admin/help.php">All guides →</a></div>'
         . '</aside>'
         . '<script type="application/json" id="helpIndex">' . $json . '</script>';
}
