# Email delivery status — AWS SES events setup

The Email log (Admin → Email log) records every email the moment we hand it to
Amazon SES ("Sent"). This pipeline adds what happened **after** that — delivered,
bounced, or marked as spam — so staff can see that a guest's confirmation actually
arrived.

```
We send via SES SMTP ─▶ header X-SES-CONFIGURATION-SET: tribalsand-events
                     ─▶ SES publishes Delivery / Bounce / Complaint / Reject
                        events for that configuration set to an SNS topic
                     ─▶ SNS HTTPS subscription POSTs to
                        https://tribalsand.com/api/ses-events.php
                     ─▶ endpoint verifies the SNS signature, finds the email_log
                        row by the SES Message-ID, updates its status
```

**Region:** eu-west-1 (Ireland), same as the rest of the stack and the SES
identity we send from.

---

## What the code already does

- `send_smtp()` (`includes/mail.php`) reads SES's `250 Ok <message-id>` reply and
  stores the id on the log row (`email_log.provider_id`). It adds the
  `X-SES-CONFIGURATION-SET` header **only** when `SES_CONFIGURATION_SET` is set.
- `api/ses-events.php` — the webhook. Reuses the SNS signature check and the
  auto-confirm from the inbound-mail webhook. Returns 200 for any authentic
  message, 403 only for a bad signature or the wrong topic.
- A later event never downgrades an earlier one: a bounce or spam complaint after
  "Delivered" wins, a late "Delivered" never hides a bounce. A *transient* bounce
  (mailbox full, greylisting — SES keeps retrying) only adds a note.

Nothing changes until the env var below is set, so the code can deploy first.

---

## Step-by-step (in this order)

1. **Deploy** the code and run `db/migrations/add_email_log.sql` via
   `/admin/migrate.php`.

2. **SNS topic.** SNS → Topics → Create topic → *Standard* → name
   `tribalsand-ses-events`. Copy the topic ARN.

3. **Subscription.** In the topic → Create subscription → Protocol **HTTPS** →
   Endpoint `https://tribalsand.com/api/ses-events.php`. Leave "raw message
   delivery" **off**. Within a few seconds the subscription shows **Confirmed**
   (the endpoint confirms it itself). If it stays *Pending*, check
   `logs/` / CloudWatch for `[ses-events]` lines.

4. **Configuration set.** SES → Configuration sets → Create → name
   `tribalsand-events`. Then *Event destinations* → Add destination → event types
   **Deliveries, Hard bounces (Bounce), Complaints, Rejects** (Delivery delays is
   optional) → destination **Amazon SNS** → topic `tribalsand-ses-events`.

5. **ECS environment** (task definition → container env, then deploy):
   ```
   SES_CONFIGURATION_SET=tribalsand-events
   SES_EVENTS_SNS_TOPIC_ARN=<the topic ARN from step 2>   # optional, pins the webhook to this topic
   ```

6. **Test.** Admin → Emails → any email → Preview → **Send test to me**. In
   Admin → Email log the test shows *Sent*, then *Delivered* within a minute. To
   test a bounce, send a test from an account whose address is
   `bounce@simulator.amazonses.com` (SES's mailbox simulator) — it shows *Bounced*.

---

## Notes

- **No history before this.** SES keeps no per-message list — only totals — so
  emails sent before the log existed can't be looked up. The log covers sends
  from its deploy onward.
- The Resend path (`RESEND_API_KEY`) is a dormant leftover; it has its own
  webhooks and is not wired here. Never set that key on AWS — it would bypass SES.
- An address that hard-bounces lands on SES's account-level suppression list;
  later sends to it are dropped by SES and will show as *Bounced* again.
