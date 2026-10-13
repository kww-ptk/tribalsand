# Staff establishment, attendance import & HR system: plan

**Date:** 13 Oct 2026 · **Requested by:** Valentina (staff lists + 3 features), HR department (attendance workbook), Patrik (HR system wishlist PDF)
**Goal in one line:** every team member of every company is in Tribal Go with their company and payroll number. Staff clock in and out on the kiosk. HR gets the monthly summary (days, hours, overtime, off days, leave balances, night duty) from the system, not from Excel. Each person's page shows all of it.

---

## 1. What we were given

| Source | What it contains | How we use it |
|---|---|---|
| `MK HOLIDAYS LIMITED.XLS` | 53 people. **Tribal Dunes** (32: Maya Kobe / Maya Ilai / Off Duty) and **Tribal Table** (21). Payroll no., full name, department, unit | Staff establishment import |
| `TRIBALSAND HOLIDAYS LIMITED.XLS` | 17 people: Watamu office, My Amani, Enkare, Sandbox, Plot 6, Plot 22 | Staff establishment import |
| `RETREATS ZURI LIMITED.XLS` | 24 people: Zuri, plus 1 accountant at the Watamu office | Staff establishment import |
| `TRIBAL SAND LIMITED.xlsx` | 4 people: 2 kite school, 2 drivers. **Managed by Enock** | Staff establishment import |
| `STAFF ATTENDANCE 2026 JAN - OCT 2026.xlsx` | 29 sheets. Per month: a **time sheet** (in/out/in/out per day, hours, status, overtime), an **attendance code grid** (P / OFF / SICK / LEAVE… per day), a **summary**. Also Dec 2025 opening balances and a running 2026 summary with leave balances | History import, opening balances, and the reference our calculations must match |
| `HR system.pdf` | Wishlist: staff **requests** (leave, sick, day off, shift swap, attendance correction, overtime, unpaid) with two-step approval; a **Kenya public-holiday calendar** tied to attendance and pay | Phases 6–7 |
| Valentina's email | Add **staff room allocation**, a **weekly reporting grid**, **night allowance / night duty** (Zuri) | Phases 8–10 |

**Total: 98 people across 4 companies.** The system holds **73** today, seeded in Sept from the older HR roster.

---

## 2. What the system already has (we build on this, we don't rebuild it)

- **`hr_staff`**: the directory (name, position, department, home property + extra properties, weekly off day, phone, contract, documents, linked login). The person page is `admin/employee.php`, with tabs Overview · Employment · Documents · Assets.
- **`attendance`**: one row per person per day, with `in1 / out1 / in2 / out2` (minutes, an out after midnight is ≥ 1440) and a status code. **This is exactly the layout of HR's time sheet** (two shift sections around the meal break), so their history fits with no reshaping.
- **Clock kiosk** (`clock.php`): QR card + tablet + photo. It already fills `in1 → out1 → in2 → out2` and handles night shifts across midnight. Printable cards: `admin/attendance-cards.php`. Tablets: `admin/attendance-devices.php`.
- **`admin/attendance.php`** has Daily editor · Month grid · Dashboard · Leave requests (simple, one-step) · CSV export.
- **Kenya public holidays** are computed in `includes/holidays.php`, Easter-based ones included. Extra gazetted days can be added in Calendar highlights.
- **HR role** (`hr`): sees staff, attendance and documents for every property. No bookings, no money.
- **`companies`** (accounting): the 4 legal companies can live there. `hr_staff.company` today is only free text.

---

## 3. What we found in the files (and what it means for the build)

**Staff lists**
1. **Payroll numbers repeat across companies.** "01" exists at both Tribal Sand Holidays and Zuri. A person's key must be **company + payroll no.**, never the number alone.
2. **Payroll formats are mixed inside MK:** `MKH32` vs `MKH032`, `TT010` vs `TTR010`. We store them exactly as given and only normalise for matching. **Ask HR** which one is correct.
3. **Name order differs between files.** Staff lists say `PALMA Lisa Wanjira` (surname first). The attendance sheet says `LISA WANJIRA PALMA`. The existing system says `HENRY OUMA OKAKA` and the list says `OKAKA Henry Ouma`. We match on the **set of name words**, not the order, and anything uncertain goes to a review screen. A few names have two capitalised words (`AUGUSTUS MUMO Mulwa`), so the surname is unclear. HR confirms those.
4. **Some people work at several places:** "TRIBAL DUNES / WATAMU OFFICE", "ENKARE / SANDBOX / TRIBAL DUNES". These map to home property + extra properties (`hr_staff_venues` already supports this).
5. **"Tribal Dunes" is one unit covering three properties.** The attendance sheet does split people into MK / MAYA ILAI sections, so we use that to choose the home property and HR confirms.
6. **Tribal Sand Limited has no property**: kite school + transfers. Enock manages it. Today a manager only sees staff of *their properties*, so Enock would see nobody. We need **company-based scope** (see Phase 1).
7. Some of the 73 people already in the system appear on **no** list (left, or renamed). We never delete them. They are listed for HR to mark **inactive**.

**Attendance workbook**
8. Times are real Excel times. The workbook uses the **1904 date system**, so a night-shift clock-out shows as "1904-01-02 06:00" (= 06:00 the next day). The importer must read that flag, or night shifts come out as −12 hours.
9. **Status codes are typed by hand:** `Sick / sick / SICK`, `Leave / LEAVE / leave`, `REC / Rec`, plus `PH`, `OFF R`, `cons`, `N`. We map them all to the system's codes. **`OFF R`, `cons` and `N` need HR to explain them** (`N` may be Night duty, which would make it our night-allowance data).
10. **Overtime = hours − 8, for everyone.** HR's note: security works 12 h, and the extra 4 h *is* overtime. **The system currently uses 12 h as the standard for Security**, so it would show 0 overtime where HR shows 4 h a day. We must change this to match HR.
11. A single section with no lunch break (07:00–17:00) counts as the full 10 h in HR's sheet, with **no automatic meal deduction**. We keep that rule unless HR says otherwise. On the kiosk, people clock out and back in for the meal break.
12. Each month is laid out a bit differently (people added or removed, property section headers, empty rows, a sheet named "JULY 2027" that is really July 2026). The importer reads **the dates in row 1** and **the names in column B**, never sheet names or fixed row numbers.
13. HR's summary maths, which we will reproduce and test against:
    - *Entitled off days* = weekly off days in the month + public holidays
    - *Expected days* = days in month − entitled off days
    - *OFF + PH accumulated* = days worked − expected days
    - *Overtime in days* = overtime hours ÷ 8
    - *Total pending* = previous balance + accumulated + overtime days (− days paid out)
    - *Pro-rata leave* = **1.75 days per month** (= 21 days a year, the Kenyan minimum)
    - *Leave balance* = pro-rata leave − leave taken (+ balance brought forward)
14. The workbook holds **personal data** (names, sick days). It stays out of the git repo. Tests use a small anonymised copy.

---

## 4. The plan, phase by phase

Each phase ships on its own, on `dev` first, then a PR to `master`. Every phase is safe to deploy before its migration runs: the feature stays hidden until then, which is the project rule.

### Phase 1: Staff establishment (the 4 company lists)
**What:** every person gets a **company**, a **payroll number**, surname / first names, department and unit(s).
**How:**
- Migration `add_hr_staff_establishment.sql`: `hr_staff.company_id` (FK to `companies`), `payroll_no`, `surname`, `first_names`; unique on (company_id, payroll_no). The old `company` text column is kept for one release.
- Create the 4 companies if they don't exist yet (MK Holidays, Tribal Sand Holidays, Retreats Zuri, Tribal Sand Limited).
- **Import screen** `admin/staff-import.php` (owner + HR): upload a list → preview with one row per person: **Matched** (to an existing person, showing what changes) / **New** / **Needs a decision** (several possible matches, or none) → confirm. Re-running it changes nothing. The 4 lists are old `.XLS`, so either HR re-saves them as `.xlsx` or we convert these four once into a seed file (as `hr_roster.json` was done).
- "Not on any list" panel: the 73 existing people not found, each with a *Mark inactive* button.
- **Company scope for managers:** a manager can be given a company (Enock → Tribal Sand Limited) and then sees that company's staff even when they have no property. Every HR page reads it through one helper, next to `admin_venue_ids()`.
- Team directory gets **Company** and **Payroll no.** columns and filters. The employee Overview shows both.

**Done when:** 98 people are in the system with company + payroll no., HR has reviewed the uncertain matches, and Enock sees exactly the 4 TSL staff.

### Phase 2: Import the attendance history (Jan–Oct 2026)
**What:** every day already recorded in Excel goes into the system, so balances and reports run from real data, and each person's page shows their history.
**How:**
- `admin/attendance-import.php` (owner + HR). Upload the workbook → pick the month(s) → preview → confirm.
- Reads the **time sheets** (times + status). The **code grids** fill days that have a status but no times (sick, leave). It reads the 1904 flag, adds a day to night clock-outs, ignores section-header and empty rows, and maps status codes (table in §6).
- Names are matched to people from Phase 1. Unknown names go to a "pick the person" step. The choice is remembered, so the next month matches by itself.
- **Kiosk data always wins:** a day that already has kiosk punches is never overwritten, only reported.
- Each imported row carries its source (`import`, file + month) so HR can tell it apart from kiosk punches and manual edits.
- **Check report:** for each person and month we recompute days worked, hours and overtime and compare them with HR's own SUMMARY sheet. Differences are listed so we find mistakes in the sheet *or* in our maths before go-live.

**Done when:** Jan–Oct is in the system and the check report matches HR's summaries, or every difference has an explanation.

### Phase 3: Balances: off days, leave, recovery (opening + running)
**What:** the "TOTAL PENDING" and "LEAVE BALANCE" columns, kept by the system.
**How:**
- Migration `add_hr_balances.sql`: an **append-only ledger** `hr_balance_moves` (person, balance type `annual_leave | off_days`, days ±, reason, date, who). The balance = the sum of moves. Corrections are new moves and never edits, the same principle as the inventory ledger.
- **Opening balance** at a cut-over date (proposed **30 Sep 2026**, the last closed month), taken from HR's latest summary. We do **not** recompute 2025 history: HR's figures include manual decisions we can't see.
- **Month close** (HR presses *Close month*): writes that month's accrual (1.75 leave days), off days earned vs taken, overtime days, recovery days used, days paid out. Closing twice does nothing the second time.

### Phase 4: Monthly summary that replaces Sheet 2
**What:** HR's monthly table, produced by the system.
**How:** a new **Summary** view in `admin/attendance.php`: per person, the columns from §3.13 plus sick days, leave taken, public holidays worked and night duties, grouped by company and property. **Excel export in HR's own column order**, so payroll's process doesn't change. Standard day = **8 h for everyone** (fixes §3.10).

### Phase 5: The person page shows everything
**What:** on `admin/employee.php`, attendance sits with the documents, tasks and assets.
**How:** a new **Attendance** tab with this month's calendar (status per day, times, hours), month totals, the **balances** (leave, off days), recent requests, night duties, room (Phase 10), and links to the full month and to their QR card. The Overview card shows "This month: 18 days · 6.5 h overtime · 12 leave days left".

### Phase 6: Kenya rules: public holidays and hour categories
**What:** the PDF's holiday features, built so the rules can be changed and are never hardcoded per person.
**How:**
- Holidays: `includes/holidays.php` (computed) + HR can add or correct a gazetted day with a reason (Calendar highlights, flagged as a *public holiday*).
- Every worked day is split into **normal hours · overtime · rest-day hours · public-holiday hours**. A night shift is split at midnight, so only the hours falling on the holiday count as holiday hours.
- Rates (×1.5, ×2…) are **settings**, not code, with a "verify before use" note. Version 1 reports **hours and days per category**. A **money amount** needs salaries in the system, which is sensitive data, and is left as a later step agreed with payroll.

### Phase 7: Staff requests with approval (the PDF)
**What:** one place for leave, sick, day off / recovery, shift swap, attendance correction, overtime and unpaid leave. No more WhatsApp or paper.
**How:**
- Migration `add_staff_requests.sql` grows the current `leave_requests` into `staff_requests`. It records: **for whom** · **submitted by** · type · dates · details · status `pending supervisor → pending HR → approved / declined / cancelled` · who approved and when · reason.
- Most staff have no login, so **a manager or HR submits on their behalf**, through the same form. *Submit and approve* exists for HR and records both actions.
- Approval updates things **automatically**: leave → status on those days + balance move; correction → new times, **with the original kept** (audit); shift swap → both schedules, after the colleague accepts.
- **HR inbox** filtered by property, company, type and status. Reminders to the next approver. Sick-note files are private, like employee documents (HR only).
- Changing or cancelling an approved request needs approval again.

### Phase 8: Weekly reporting grid
**What:** Valentina's weekly table. One week per property: rows = people, columns = Mon–Sun. Each cell shows the shift worked (times or status), with week totals for hours, overtime and night duties.
**How:** a **Week** view in `admin/attendance.php` (picker for property/company, Prev/Next, print + Excel). Flags: missing clock-out, no rest day in 7 days (Kenyan rule: one rest day a week), late start, off day worked.
**Open question:** is it only a *report of what happened*, or also a *rota planned in advance* (who works which shift next week)? If it's a rota, we add planned shifts and the grid shows planned vs actual (see §7).

### Phase 9: Night duty / night allowance (Zuri first)
**What:** count the nights each person was on night duty and show the allowance for the month.
**How:** a day can be marked **Night duty**: automatically when the shift crosses midnight or uses the night shift, or by hand (code `N`, if that is what HR's `N` means). Migration `add_night_duty.sql`. The allowance amount is a **setting per company / property** (KES per night). It is reported on the monthly summary, the weekly grid and the person page, and is switched on for Zuri only until other properties need it.

### Phase 10: Staff room allocation
**What:** which room / bed each staff member is allocated (staff accommodation).
**How:** migration `add_staff_rooms.sql`: `staff_rooms` (property, name/number, beds, notes) + `staff_room_allocations` (person, room, bed, from, to). Page **Team → Staff rooms**: rooms per property with occupied / free beds, assign or move a person (history kept). The person page shows their room.
**Open question:** we read "room allocation" as **staff housing**. If it actually means *which guest rooms a housekeeper is responsible for*, the design is different (see §7).

### Phase 11: Go live on the kiosk
1. Print a **QR card for every person** (`attendance-cards.php`, by company/property) and laminate them.
2. Register **one tablet per site**: Tribal Dunes gate, Tribal Table, Zuri, Enkare, Sandbox, My Amani, Watamu office, kite school / transfers (Enock). The site list is to be confirmed.
3. **Parallel month (proposed November 2026):** staff clock on the kiosk *and* HR keeps the Excel. At month end we compare the two with the check report from Phase 2.
4. **From 1 December 2026** the Excel stops. HR works from the system: the daily exceptions list (missing clock-out, absent without a request), requests inbox, *Close month*, Excel export for payroll.
5. Short HR guide in **Help & guides** (attendance, import, requests, month close), as every new admin page needs one.

---

## 5. Suggested order and size

| # | Phase | Depends on | Rough size |
|---|---|---|---|
| 1 | Staff establishment + company scope | answers to Q1–Q4 | M |
| 2 | Attendance history import + check report | 1, Q5 | L |
| 3 | Balances ledger + month close | 2, Q6 | M |
| 4 | Monthly summary + Excel export | 3 | M |
| 5 | Person page Attendance tab | 3 | S |
| 9 | Night duty / allowance | 2, Q8 | S |
| 8 | Weekly grid | 2, Q7 | M |
| 10 | Staff rooms | Q9 | S–M |
| 6 | Kenya holiday hour categories | 4, Q10 | M |
| 7 | Requests + approvals | 3 | L |
| 11 | Kiosk go-live + parallel month | 1–5 | rollout |

Phases 1–5 + 9 are what HR needs to **stop the Excel**. Phases 6–8 and 10 can follow in any order.

---

## 6. Status codes: Excel → system

| Excel | System | Meaning |
|---|---|---|
| `P` (or times entered) | `P` | Present |
| `OFF` | `OFF` | Weekly off day |
| `Sick`, `sick`, `SICK` | `SK` | Sick |
| `LEAVE`, `Leave`, `leave` | `LV` | Annual leave |
| `REC`, `Rec` | `REC` | Recovery day (uses off-day balance) |
| `PH` | `PH` | Public holiday |
| `OFF R` | **? ask HR** | Off + recovery? |
| `cons` | **? ask HR** | Consecutive? Consultation? |
| `N` | **? ask HR** | Night duty? |

---

## 7. Questions for Valentina / HR (answers needed before each phase)

1. **Payroll numbers (Ph 1):** MK uses both `MKH32` and `MKH032`, `TT010` and `TTR010`. Which format is right?
2. **Surnames (Ph 1):** for names like `AUGUSTUS MUMO Mulwa`, which word is the surname?
3. **Tribal Dunes (Ph 1):** which people are Maya Kobe, which Maya Ilai, which Off Duty? (We'll pre-fill from the attendance sheet sections for HR to correct.)
4. **Enock (Ph 1):** does he already have a login? Should he also see attendance/requests for the 4 TSL staff, or only the directory?
5. **Codes (Ph 2):** what do `OFF R`, `cons` and `N` mean?
6. **Cut-over (Ph 3):** OK to take opening balances from the **30 Sep 2026** summary? Are "pending days paid" (paid out instead of taken) still done?
7. **Weekly grid (Ph 8):** a report of what was worked, or also a rota planned in advance? Which day does the week start? Who receives it, and as print, Excel or email?
8. **Night allowance (Ph 9):** who qualifies at Zuri (security only, or anyone doing night duty)? The amount per night? Is it paid with salary, and should other properties get it too?
9. **Room allocation (Ph 10):** staff housing (which staff room/bed someone sleeps in)? Or which guest rooms a housekeeper looks after? If housing: the list of staff rooms and beds per property.
10. **Kenya rates (Ph 6):** which wage order applies (General or the Hotel & Catering order)? Normal weekly hours? Overtime and holiday rates per contract type? To be confirmed with the payroll accountant.
11. **Meal break:** keep HR's current rule (no automatic deduction; the break is the gap between the two sections)?
12. **Kiosk sites (Ph 11):** where should the tablets go, and who looks after each one?

---

## 8. Kenya rules we plan around (to be verified by HR / payroll, never hardcoded)

| Rule | Source (to verify) | Where it shows in the system |
|---|---|---|
| Annual leave 21 working days / year after 12 months (pro-rata 1.75 / month, as HR already uses) | Employment Act 2007 s.28 | Leave accrual at month close |
| One rest day in every 7 days | Employment Act s.27 | Weekly grid flag; *entitled off days* |
| Sick leave: 7 days full pay + 7 days half pay a year, with a medical certificate, after 2 months' service | Employment Act s.30 | Sick balance + private sick-note files |
| Maternity 3 months, paternity 2 weeks | Employment Act s.29 | Request types |
| Public holidays as gazetted (incl. moving Easter, Eid, extra gazetted days) | Public Holidays Act | Holiday calendar + holiday hours |
| Overtime and holiday-work rates | Regulation of Wages Orders (General / Hotel & Catering) | Settings only, reported as hours per category |

---

## 9. Risks and how we handle them

- **Wrong person matched** → nothing is written without a preview, uncertain matches always need a human click, and the import can be re-run.
- **Old Excel figures don't match ours** → the check report shows every difference before go-live. We don't silently "fix" either side.
- **Overwriting kiosk data** → the importer never touches a day with punches.
- **Personal data** → the workbook and lists stay out of git, sick notes and documents stay private, and the HR role sees no bookings or money.
- **Night shifts** → handled across midnight on import (1904 flag), on the kiosk (14 h rule, already built) and in holiday splitting.
- **Prod** → every migration is added to `ORDER.txt` and run via `/admin/migrate.php`. The import runs on prod after the migrations, never from a local `.env`.

## 10. Not in this plan
Salary amounts and payslips (needs salary data and payroll's agreement), KRA / NSSF / SHIF statutory returns, staff self-service logins on phones (the kiosk + manager-on-behalf covers it for now).

---

### Tests (one per logic file, the project convention)
`tests/staff_establishment_logic.php` (name matching, payroll keys, company scope) · `tests/attendance_import_logic.php` (anonymised mini-workbook: 1904 times, night shifts, code mapping, section rows) · `tests/hr_balances_logic.php` (month close reproduces HR's summary for sample people) · `tests/hr_holiday_hours_logic.php` (midnight split) · `tests/staff_requests_logic.php` · `tests/night_duty_logic.php` · `tests/staff_rooms_logic.php`.
