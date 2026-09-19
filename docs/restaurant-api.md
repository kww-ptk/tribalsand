# Restaurant integration API

For a standalone restaurant website (the Zuri restaurant site) that wants to show
the live Tribal Sand menu and take table bookings that land in the Tribal Sand
admin.

**The point of it:** there is exactly ONE source of truth. The menu lives in the
`menus` / `menu_categories` / `menu_items` tables that Admin → Menus edits, and
reservations live in the `reservations` table that Admin → Reservations works
from. These endpoints read and write those same tables through the same helpers
the tribalsand.com pages use. So an edit in the admin is live on the partner site
on its next fetch — there is no copy to keep in step, and no push to schedule.

| | Endpoint | Auth |
|---|---|---|
| List published menus | `GET /api/menu-feed.php` | key optional |
| One menu with items | `GET /api/menu-feed.php?slug=zuri` | key optional |
| Create a booking | `POST /api/reservation-api.php` | key **required** |
| Look a booking up | `GET /api/reservation-api.php?reference=TSR-3-AB12C` | key **required** |

## Setup

Set one environment variable on the Tribal Sand app (ECS task definition):

```
RESTAURANT_API_KEY=<a long random string>
```

Generate one with `openssl rand -hex 32`. Give that value to the restaurant
site's developer. `MENU_API_KEY` is accepted as a legacy alias.

Reads work without a key (a menu is public — `/menu.php` already serves it to
anyone). Writes always require one; with no key configured the write endpoint
answers `503` and refuses, so forgetting the variable can never leave an
unauthenticated writer exposed.

Pass the key either way:

```
Authorization: Bearer <RESTAURANT_API_KEY>
```
```
?key=<RESTAURANT_API_KEY>
```

> **Call the write endpoint from the partner's server, not from the visitor's
> browser.** Browser code would have to ship the key to every visitor. This is
> why `POST /api/reservation-api.php` sends no CORS headers. The read feed does
> send `Access-Control-Allow-Origin: *`, so it is safe to fetch from the browser
> — just don't attach the key when you do.

## Reading the menu

```bash
curl https://tribalsand.com/api/menu-feed.php
```

```json
{
  "ok": true,
  "menus": [
    {
      "slug": "zuri",
      "title": "Zuri",
      "subtitle": "Beach Club & Restaurant",
      "venue_slug": "zuri",
      "feed_url": "https://tribalsand.com/api/menu-feed.php?slug=zuri",
      "public_url": "https://tribalsand.com/menu.php?m=zuri"
    }
  ],
  "generated_at": "2026-09-19T11:02:41+03:00"
}
```

```bash
curl https://tribalsand.com/api/menu-feed.php?slug=zuri
```

```json
{
  "ok": true,
  "menu": {
    "slug": "zuri",
    "title": "Zuri",
    "subtitle": "Beach Club & Restaurant",
    "tagline": "",
    "location": "Kilifi",
    "footer_note": "",
    "currency": "Kes",
    "venue": { "slug": "zuri", "name": "Zuri" },
    "reserve_url": "https://tribalsand.com/reserve.php?venue=zuri",
    "reservations": {
      "enabled": true,
      "venue_slug": "zuri",
      "api_url": "https://tribalsand.com/api/reservation-api.php",
      "public_url": "https://tribalsand.com/reserve.php?venue=zuri",
      "slots": { "12:00": "12:00 PM", "12:30": "12:30 PM" },
      "max_party_size": 30
    },
    "public_url": "https://tribalsand.com/menu.php?m=zuri",
    "updated_at": "2026-09-14T09:12:00+03:00",
    "sections": {
      "food": [
        {
          "name": "Starters",
          "tag": "",
          "icon": "",
          "items": [
            {
              "name": "Coconut Prawns",
              "description": "Tamarind glaze, lime",
              "price": 1200,
              "price_label": "1,200 Kes",
              "attributes": { "veg": false, "vegan": false, "spicy": true,
                              "nuts": false, "gluten": false, "gf": true, "sig": true }
            }
          ]
        }
      ],
      "drinks": []
    }
  }
}
```

Notes:

- Only **published** menus, **visible** categories and **available** items are
  returned — exactly what `/menu.php` renders. Hiding an item in the admin
  removes it from the partner site too.
- `price` is a number for your own formatting; `price_label` is the string
  Tribal Sand renders, already in the menu's currency.
- `reservations.slots` is the authoritative list of bookable times. Build your
  booking form from it and the create call can never be rejected for a bad time.
- Unknown or unpublished slug → `404`.

### Caching

Every read carries an `ETag` derived from the **data**, so send it back and poll
as often as you like:

```bash
curl -H 'If-None-Match: "6f1c…"' https://tribalsand.com/api/menu-feed.php?slug=zuri
# HTTP/1.1 304 Not Modified
```

A `304` genuinely means nothing changed, including edits to individual items
that the menu row's `updated_at` would miss. Responses also carry
`Cache-Control: public, max-age=300`.

## Creating a reservation

```bash
curl -X POST https://tribalsand.com/api/reservation-api.php \
  -H "Authorization: Bearer $RESTAURANT_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{
        "venue": "zuri",
        "date": "2026-10-04",
        "time": "19:30",
        "party_size": 4,
        "guest_name": "Amina Yusuf",
        "guest_phone": "+254712345678",
        "guest_email": "amina@example.com",
        "notes": "Window table if possible",
        "source": "zuri-website"
      }'
```

```json
{
  "ok": true,
  "reservation": {
    "reference": "TSR-3-K7P2M",
    "status": "pending",
    "venue": { "slug": "zuri", "name": "Zuri" },
    "date": "2026-10-04",
    "time": "19:30",
    "time_label": "7:30 PM",
    "party_size": 4,
    "guest_name": "Amina Yusuf",
    "notes": "Window table if possible",
    "source": "zuri-website",
    "created_at": "2026-09-19T11:04:02+03:00"
  }
}
```

- A form-encoded body works too, if that is easier for your HTTP client.
- **It is a request, not a confirmed booking.** Rows arrive `pending`, exactly
  like the ones tribalsand.com takes, and staff confirm or cancel them in
  Admin → Reservations — which is what sends the guest the confirmation email.
  The API cannot set a status; a partner site must not be able to self-confirm.
- Show the guest the same expectation the Tribal Sand form does: *"we'll confirm
  your table shortly"*.
- `source` is free text (slugged, 30 chars) and shows in the admin list, so put
  something identifiable like `zuri-website` there.
- The guest acknowledgement and the staff alert go out automatically — the same
  emails the website form sends.
- Validation is the **same validator** the website form uses
  (`reservation_validate()`), so the API can't create a booking the website
  itself would have refused.

### Errors

| Status | Meaning |
|---|---|
| `401` | Missing or wrong key |
| `422` | Validation failed — see the `errors` object, keyed by field |
| `429` | Rate limited (60 bookings per 10 minutes per calling IP) |
| `503` | `RESTAURANT_API_KEY` isn't set, or reservations aren't enabled |

```json
{
  "ok": false,
  "error": "Validation failed",
  "errors": { "reservation_time": "Please choose a time.", "party_size": "Party size must be between 1 and 30." }
}
```

## Checking a booking's status

```bash
curl -H "Authorization: Bearer $RESTAURANT_API_KEY" \
  'https://tribalsand.com/api/reservation-api.php?reference=TSR-3-K7P2M'
```

Returns the same `reservation` object, with `status` now `pending`, `confirmed`
or `cancelled` — so the partner site can show the guest where their request got
to. The response never includes the client IP or internal row ids.

## Where the code lives

| File | Purpose |
|---|---|
| `includes/restaurant-api.php` | Shared key auth, CORS, ETag, body parsing |
| `api/menu-feed.php` | The read feed |
| `api/reservation-api.php` | Create + status lookup |
| `includes/menu.php` | `menu_feed_payload()` / `menu_feed_index()` data shaping |
| `includes/reservations.php` | `reservation_validate()` and the shared writers |
| `tests/restaurant_api_logic.php` | Tests for the payload shape and the guards |
