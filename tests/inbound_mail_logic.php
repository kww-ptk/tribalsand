<?php
declare(strict_types=1);
/**
 * Inbound guest-reply intake tests. Run: php tests/inbound_mail_logic.php
 *
 * Pure logic (ref verification, MIME extraction, quoted-history stripping,
 * address parsing, content normalisation, SNS guard rejections) is asserted
 * directly — no network or model call is exercised. The de-dup round-trip runs
 * against the live inbound_mail_log table inside a transaction that is always
 * rolled back.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/inbound-mail.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Reference verification (HMAC re-check) ───────────────────────────────────
$ref = make_submission_ref(34);
check('make_submission_ref shape',              (bool) preg_match('/^TSR-34-[0-9a-f]{6}$/', $ref));
check('verify accepts a genuine tag in subject', verify_submission_ref('Re: Your enquiry — Tribal Sand [' . $ref . ']') === 34);
check('verify is case-insensitive',              verify_submission_ref('re: ... [' . strtoupper($ref) . ']') === 34);
check('verify rejects a forged hash',            verify_submission_ref('Re: [TSR-34-000000]') === false);
check('verify rejects a wrong-id genuine hash',  verify_submission_ref('Re: [TSR-35-' . substr($ref, 7) . ']') === false);
check('verify rejects when no tag present',      verify_submission_ref('Re: just a normal subject') === false);
check('plain parser still trusts any well-formed tag', parse_submission_ref('[TSR-34-000000]') === 34); // contrast

// ── Address parsing ──────────────────────────────────────────────────────────
check('address_only from Name <addr>',          inbound_address_only('Jane Doe <jane@example.com>') === 'jane@example.com');
check('address_only from bare addr',             inbound_address_only('jane@example.com') === 'jane@example.com');
check('address_only lowercases',                 inbound_address_only('JANE@Example.COM') === 'jane@example.com');
check('address_only empty on none',              inbound_address_only('no address here') === '');

// ── Content normalisation (base64 vs raw MIME) ───────────────────────────────
$rawMime = "Content-Type: text/plain\n\nhello";
check('normalise keeps raw MIME as-is',          inbound_normalise_content($rawMime) === $rawMime);
$b64 = base64_encode($rawMime);
check('normalise decodes base64 content',        inbound_normalise_content($b64) === $rawMime);

// ── MIME extraction: simple text/plain ───────────────────────────────────────
$plain = "Subject: Re: hi\nContent-Type: text/plain; charset=UTF-8\n\nThanks, that works for me.";
check('plain text/plain extracted',              inbound_extract_text($plain) === 'Thanks, that works for me.');

// ── MIME extraction: quoted-printable ────────────────────────────────────────
$qp = "Content-Type: text/plain\nContent-Transfer-Encoding: quoted-printable\n\nCaf=C3=A9 sounds great=21";
check('quoted-printable decoded',                inbound_extract_text($qp) === 'Café sounds great!');

// ── MIME extraction: base64 body ─────────────────────────────────────────────
$b64body = "Content-Type: text/plain\nContent-Transfer-Encoding: base64\n\n" . base64_encode('See you then.');
check('base64 body decoded',                     inbound_extract_text($b64body) === 'See you then.');

// ── MIME extraction: multipart prefers text/plain ────────────────────────────
$multi = "Content-Type: multipart/alternative; boundary=\"BND\"\n\n"
       . "--BND\nContent-Type: text/plain\n\nPlain version here.\n"
       . "--BND\nContent-Type: text/html\n\n<p>HTML version</p>\n"
       . "--BND--\n";
check('multipart picks text/plain',              inbound_extract_text($multi) === 'Plain version here.');

// ── MIME extraction: html fallback (tags stripped) ───────────────────────────
$htmlOnly = "Content-Type: text/html; charset=UTF-8\n\n<p>Hello there</p><br>line two";
$got = inbound_extract_text($htmlOnly);
check('html tags stripped',                      stripos($got, '<p>') === false && str_contains($got, 'Hello there'));

// ── Quoted-history stripping ─────────────────────────────────────────────────
$gmail = "Yes, 3 nights works.\n\nOn Mon, 8 Sep 2026 at 10:00, Tribal Sand <reply@mail.tribalsand.com> wrote:\n> Dear guest, here are the options...";
check('gmail attribution stripped',              inbound_strip_quoted($gmail) === 'Yes, 3 nights works.');

$gmailWrapped = "Sounds perfect.\n\nOn Mon, 8 Sep 2026 at 10:00, Tribal Sand\n<reply@mail.tribalsand.com> wrote:\n> original mail";
check('gmail wrapped attribution stripped',      inbound_strip_quoted($gmailWrapped) === 'Sounds perfect.');

$outlook = "Confirmed, thanks.\n\n-----Original Message-----\nFrom: Tribal Sand\nSubject: Your enquiry";
check('outlook divider stripped',                inbound_strip_quoted($outlook) === 'Confirmed, thanks.');

$quotedBlock = "Great, book it.\n\n> line one of our mail\n> line two of our mail";
check('trailing >-quote block stripped',         inbound_strip_quoted($quotedBlock) === 'Great, book it.');

$noQuote = "Just a plain reply with no history at all.";
check('no separator keeps whole message',        inbound_strip_quoted($noQuote) === $noQuote);

// ── SNS guard rejections (no network) ────────────────────────────────────────
check('sns_verify rejects empty message',        sns_verify_signature([]) === false);
check('sns_verify rejects unknown type',         sns_verify_signature(['Type' => 'Nope', 'Signature' => 'x', 'SigningCertURL' => 'https://sns.eu-west-1.amazonaws.com/x.pem']) === false);
check('cert fetch rejects non-amazon host',      sns_fetch_certificate('https://evil.example.com/cert.pem') === null);
check('cert fetch rejects http scheme',          sns_fetch_certificate('http://sns.eu-west-1.amazonaws.com/cert.pem') === null);
check('cert fetch rejects non-.pem path',        sns_fetch_certificate('https://sns.eu-west-1.amazonaws.com/cert.txt') === null);
check('subscribe confirm rejects non-amazon host', sns_confirm_subscription('https://evil.example.com/confirm') === false);

// ── Support guards return bool ───────────────────────────────────────────────
check('inbound_mail_log_supported returns bool', is_bool(inbound_mail_log_supported()));

// ── De-dup round-trip (rolled back) ──────────────────────────────────────────
if (!inbound_mail_log_supported()) {
    echo "\nSKIP  inbound_mail_log round-trip (table missing — run add_inbound_mail_log.sql)\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}
try {
    db()->beginTransaction();
} catch (\Throwable $e) {
    echo "\nSKIP  inbound_mail_log round-trip (database unavailable: " . $e->getMessage() . ")\n";
    echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
    exit($failures ? 1 : 0);
}
try {
    $mid = 'test-msg-' . bin2hex(random_bytes(6)) . '@ses';
    check('unseen messageId → not seen',         inbound_mail_already_seen($mid) === false);
    inbound_mail_record($mid, null, 'Jane <jane@example.com>', 'Re: [TSR-34-abc123]');
    check('after record → seen',                 inbound_mail_already_seen($mid) === true);
    $row = db_query("SELECT from_addr, matched FROM inbound_mail_log WHERE message_id = :m", [':m' => $mid])->fetch();
    check('record stores from + unmatched flag',  $row && $row['from_addr'] === 'Jane <jane@example.com>' && !$row['matched']);
    // ON CONFLICT: a second record of the same id must not throw or duplicate.
    inbound_mail_record($mid, null, 'Jane <jane@example.com>', 'dupe');
    $cnt = (int)db_query("SELECT COUNT(*) FROM inbound_mail_log WHERE message_id = :m", [':m' => $mid])->fetchColumn();
    check('duplicate record is idempotent',       $cnt === 1);
} finally {
    if (db()->inTransaction()) db()->rollBack();
}

echo ($failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n");
exit($failures ? 1 : 0);
