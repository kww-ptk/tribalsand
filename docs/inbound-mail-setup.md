# Inbound guest replies — AWS SES + SNS setup

When a guest replies to one of our enquiry replies, this pipeline lands the reply
straight into the submission thread in the admin panel (as a blue "guest reply")
and flips the lead to **To Follow Up** — no more reading `reservations@` and
pasting by hand.

```
Guest replies ─▶ SES receives on reply@mail.tribalsand.com
             ─▶ SES receipt rule "Publish to SNS"
             ─▶ SNS HTTPS subscription POSTs to
                https://tribalsand.com/api/inbound-mail.php
             ─▶ endpoint verifies the SNS signature, matches the [TSR-<id>]
                subject tag, logs the reply into submission_notes
```

**Region:** everything below is in **eu-west-1** (Ireland) — the same region as the
rest of the stack, and a region where SES email *receiving* is supported. If you
work in another region, use it consistently and swap the MX host accordingly.

---

## What the developer ships (already done in code)

- `api/inbound-mail.php` — the webhook (SNS signature check + `[TSR-<id>]` HMAC
  match + thread write + de-dupe).
- `send_admin_reply()` now sets **Reply-To** to `INBOUND_MAIL_ADDRESS` when it's
  configured, so guest replies go to the inbound address instead of the mailbox.
- Migration `db/migrations/add_inbound_mail_log.sql` (de-dupe + observability).

So the order is: **deploy the code and set the env vars first**, then create the
SNS subscription (the endpoint must be live to auto-confirm it), then point mail at
SES with the MX record.

---

## Step-by-step (do these in order)

### 1. Confirm the domain is verified for receiving (SES)
AWS Console → **SES** → **Configuration → Identities**. Confirm
`mail.tribalsand.com` (or `tribalsand.com`) is listed and **Verified**. It almost
certainly is already, from the sending setup.

### 2. Create the SNS topic
AWS Console → **SNS** → **Topics** → **Create topic**.
- Type: **Standard**
- Name: `tribalsand-inbound-mail`
- Create. **Copy the Topic ARN** (looks like
  `arn:aws:sns:eu-west-1:<acct>:tribalsand-inbound-mail`) — you'll need it twice.

### 3. Create the SES receipt rule
AWS Console → **SES** → **Email receiving** → **Rule sets**.
- If there's no active rule set, **Create rule set** (name it `default`) and set it
  **Active**.
- Inside it, **Create rule**:
  - **Recipients:** add `reply@mail.tribalsand.com`
  - **Add action → Publish to Amazon SNS topic**
    - Topic: `tribalsand-inbound-mail`
    - Encoding: **UTF-8** (leave default)
  - Save. If the console offers to **update the SNS topic policy** so SES can
    publish, allow it. (If it doesn't, see "SNS topic policy" at the bottom.)

> Size note: the "Publish to SNS" action caps the message at ~150 KB. Ordinary text
> replies are far under that. If you expect big replies or attachments, use the
> **S3 action + SNS notify** variant instead (see "If replies get truncated" below).

### 4. Deploy the code + set env vars (ECS)
ECS → **Task Definitions** → new revision → Container → **Environment variables**:

| Name | Value |
|---|---|
| `INBOUND_MAIL_ADDRESS` | `reply@mail.tribalsand.com` |
| `INBOUND_MAIL_SNS_TOPIC_ARN` | the Topic ARN from step 2 |

Update the service to the new revision and let it redeploy. (The developer confirms
the deploy carries `api/inbound-mail.php`.)

### 5. Apply the migration
Once deployed: **`/admin/migrate.php`** → run **`add_inbound_mail_log.sql`**.
(The feature still works without it — you just lose duplicate-suppression — but run
it.)

### 6. Create the SNS → endpoint subscription
Back in **SNS** → your topic → **Create subscription**:
- Protocol: **HTTPS**
- Endpoint: `https://tribalsand.com/api/inbound-mail.php`
- Create. SNS immediately POSTs a confirmation, and the endpoint **auto-confirms**
  it. Refresh — the subscription status should turn **Confirmed** within seconds.
  (If it stays "Pending confirmation", the code isn't deployed yet or the URL is
  wrong.)

### 7. Point the mail subdomain at SES (GoDaddy DNS)
GoDaddy → **tribalsand.com** → **DNS** → **Add record**:

| Field | Value |
|---|---|
| Type | **MX** |
| Name / Host | **`mail`** (⚠️ the subdomain only — never `@`/root, or you'll break the M365 `reservations@` mailbox) |
| Value / Points to | `inbound-smtp.eu-west-1.amazonaws.com` |
| Priority | `10` |
| TTL | 1 Hour |

Save. DNS can take minutes to a couple of hours to propagate.

### 8. Test
1. In the admin panel, open a submission whose guest email is one **you** control.
2. Write a **Reply**, tick **"Also email this reply"**, send.
3. From that inbox, **reply to the email** (keep the subject — the `[TSR-…]` tag
   must survive, which normal "Reply" does).
4. Refresh the submission → your text appears as a blue **guest reply** and the
   status flips to **To Follow Up**.

---

## Troubleshooting

- **Subscription won't confirm** → the code isn't deployed, or the endpoint URL is
  wrong/not HTTPS. Check `api/inbound-mail.php` returns JSON when visited.
- **Reply doesn't thread** → confirm the guest's reply subject still contains
  `[TSR-<id>-<hash>]`. A brand-new subject line has no tag and is logged as
  "unmatched" (visible in `inbound_mail_log`), not threaded.
- **Body shows "could not be captured automatically"** → the SNS action truncated a
  large message; switch to the S3 variant below.
- **Nothing arrives at all** → MX not propagated / on the wrong host, or the SES rule
  set isn't **Active**.

### SNS topic policy (only if the console didn't set it)
Attach to the topic so SES can publish:
```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": { "Service": "ses.amazonaws.com" },
    "Action": "SNS:Publish",
    "Resource": "arn:aws:sns:eu-west-1:<acct>:tribalsand-inbound-mail",
    "Condition": { "StringEquals": { "AWS:SourceAccount": "<acct>" } }
  }]
}
```

### If replies get truncated (S3 variant — follow-up)
Change the SES rule's first action to **Deliver to S3 bucket** (stores the raw
email), keep the **Publish to SNS** action second. Then extend
`api/inbound-mail.php` to fetch the object from S3 (via the existing
`includes/storage.php` S3 client) and parse it with the same `inbound_extract_text()`
already in `includes/inbound-mail.php`. Not needed for text replies.
