# GHL → website: WhatsApp replies in the admin inbox

When a guest replies on WhatsApp, GoHighLevel posts the message to
`https://tribalsand.com/api/ghl-webhook.php`. The site matches it to the guest's
lead and adds it to that lead's conversation (Admin → Submissions → the lead) as a
blue "guest reply" bubble, flags it unread and sets the status to **To Follow Up**.
If we have never seen the sender, a new lead is created instead.

Staff still *answer* WhatsApp inside GHL (Conversations). This only makes sure the
team sees every reply next to the enquiry in our own admin.

Code: `api/ghl-webhook.php`, `includes/ghl-webhook.php`. Test: `php -d extension=sodium tests/ghl_logic.php`.

## 1. Deploy + migrate

1. Deploy the code (push to `master` → ECS).
2. Admin → Migrations → run **`add_ghl_webhook_log.sql`** (stops a GHL retry from
   posting the same message twice). The endpoint works without it, it just can't de-dupe.

## 2. Build the workflow in GHL (no marketplace app needed)

In GHL → **Automation → Workflows → Create workflow → Start from scratch**:

1. **Trigger:** *Customer Replied*. Add the filter **Reply channel is WhatsApp**
   (leave the filter off if you want SMS / Facebook / Instagram replies too — the
   channel is shown on each message).
2. **Action:** *Webhook* (a.k.a. Custom Webhook / Outbound Webhook)
   - Method: **POST**
   - URL: `https://tribalsand.com/api/ghl-webhook.php`
   - **Custom data** (add these keys — values from the `{{ }}` picker):
     - `message` → `{{message.body}}`
     - `channel` → `WhatsApp`
   - **Headers:** `X-Webhook-Secret` → the value of `GHL_WEBHOOK_SECRET` (step 3).
3. **Save + Publish** the workflow.

GHL sends the contact's id, name, email and phone automatically — that is what we
use to find the lead (in this order: the GHL contact id we saved when the lead was
pushed → email → last 9 digits of the phone).

## 3. Security (the endpoint refuses anything it can't verify)

A request is accepted if **either** check passes:

- **GHL's signature** — header `X-GHL-Signature`, Ed25519 over the raw body, checked
  against GHL's published public key (built in; override with
  `GHL_WEBHOOK_PUBLIC_KEY` if GHL ever rotates it).
- **Shared secret** — header `X-Webhook-Secret` (or `Authorization: Bearer …`) equal to
  `GHL_WEBHOOK_SECRET`. Set this in the ECS task env to a long random string
  (e.g. `openssl rand -hex 32`) and paste the same value into the workflow header.
  Workflow webhook actions are not guaranteed to be signed, so **set the secret**.

Unverified requests get `401` and are logged. Replies that can't be matched still
return `200` so GHL doesn't retry forever.

## 4. Check it works

Send a WhatsApp message to the business number from a phone that has an enquiry
on file. Within a few seconds it should appear on that lead in Admin → Submissions
(with a red "new reply" badge in the sidebar). GHL → the workflow's **Execution
logs** shows the webhook call and our response (`{"ok":true,"action":"threaded",…}`).

## Related: website → GHL (leads)

Every public form now saves the lead in our inbox first, sends our emails, answers
the guest, and **then** pushes to GHL from the server (`includes/ghl.php`):

| Form | Endpoint | GHL path |
|------|----------|----------|
| Room enquiry / 24h hold | `api/submit-enquiry.php` | `ghl_push_submission()` (contact → opportunity → note) |
| Contact (API) | `api/submit-contact.php` | `ghl_push_submission()` |
| Contact page | `ghl-submit.php` | `ghl_push_submission()` (keeps the page's tags/source) |
| Travel agency | `api/submit-agency.php` | `ghl_push_submission()` |
| Trip Builder | `api/trip-builder.php` | `ghl_forward_webhook()` → the existing GHL inbound webhook workflow |
| Off Duty / Somewhere Café waitlists | `api/submit-waitlist.php` | `ghl_forward_webhook()` → same workflow |

Needs `GHL_API_KEY`, `GHL_LOCATION_ID`, `GHL_PIPELINE_ID`, `GHL_STAGE_ID` in the ECS
env (without the key the push is skipped and nothing else changes). The GHL contact
id is saved on the lead (`payload_json.ghl_contact_id`) so WhatsApp replies match
directly.
