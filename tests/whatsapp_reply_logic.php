<?php
declare(strict_types=1);
// "Reply on WhatsApp" on an enquiry: phone → wa.me digits, placeholder values,
// template rendering (empty lines dropped), owner template validation. Pure.
// Run: php tests/whatsapp_reply_logic.php
require_once __DIR__ . '/../includes/whatsapp-reply.php';

$failures = 0;
function check(string $label, bool $cond): void {
    if ($cond) { echo "PASS  {$label}\n"; }
    else       { echo "FAIL  {$label}\n"; $GLOBALS['failures']++; }
}

// ── Phone numbers ──
check('phone: +34 kept', wa_reply_phone_digits('+34 666 385 737') === '34666385737');
check('phone: 00 prefix dropped', wa_reply_phone_digits('0039 333 1234567') === '393331234567');
check('phone: Kenyan local → 254', wa_reply_phone_digits('0712 345 678') === '254712345678');
check('phone: +254 with leading 0 kept as typed', wa_reply_phone_digits('+254712345678') === '254712345678');
check('phone: empty', wa_reply_phone_digits('') === '' && wa_reply_phone_digits(null) === '');
check('phone: too short', wa_reply_phone_digits('12345') === '');

// ── Dates ──
check('dates: same month', wa_reply_date_range('2026-10-22', '2026-10-24', 'en') === '22–24 Oct 2026');
check('dates: across months', wa_reply_date_range('2026-10-30', '2026-11-02', 'en') === '30 Oct – 2 Nov 2026');
check('dates: across years', wa_reply_date_range('2026-12-28', '2027-01-03', 'en') === '28 Dec 2026 – 3 Jan 2027');
check('dates: Italian months', wa_reply_date_range('2026-10-22', '2026-10-24', 'it') === '22–24 ott 2026');

// ── The screenshot's enquiry ──
$sub = ['guest_name' => 'Jess Smith', 'guests_adults' => 4, 'guests_children' => 0,
        'check_in' => '2026-10-22', 'check_out' => '2026-10-24', 'room_name' => 'Garden Room'];
$payload = ['transfers' => [['id' => 3, 'label' => 'Mombasa Airport transfer']]];
$vars = wa_reply_vars($sub, $payload, 'en', ['property' => 'Maya Kobe']);
check('vars: first name', $vars['first_name'] === 'Jess');
check('vars: party', $vars['party'] === '4 adults');
check('vars: nights', $vars['nights'] === '2 nights');
check('vars: extras', $vars['extras'] === 'Mombasa Airport transfer');
$msg = wa_reply_render(WA_REPLY_DEFAULTS['en'], $vars);
check('render: default English', $msg === "Hi Jess, this is Maya Kobe.\n"
    . "We are answering your enquiry about a stay for 4 adults, 22–24 Oct 2026 (2 nights).\n"
    . "You also asked about: Mombasa Airport transfer.");

$it = wa_reply_render(WA_REPLY_DEFAULTS['it'], wa_reply_vars($sub + ['guests_children' => 1], $payload, 'it', ['property' => 'Maya Kobe']));
check('render: Italian', str_contains($it, 'Ciao Jess, siamo Maya Kobe.') && str_contains($it, '4 adulti, 22–24 ott 2026 (2 notti)'));
$it1 = wa_reply_vars(['guests_adults' => 2, 'guests_children' => 1] + $sub, [], 'it');
check('vars: Italian children', $it1['party'] === '2 adulti, 1 bambino');

// ── Missing details ──
$bare = wa_reply_render(WA_REPLY_DEFAULTS['en'], wa_reply_vars(['guest_name' => 'Ann'], [], 'en'));
check('render: no dates/party/extras → only the greeting', $bare === 'Hi Ann, this is Tribal Sand.');
$noParty = wa_reply_render(WA_REPLY_DEFAULTS['en'], wa_reply_vars(['guest_name' => 'Ann'] + array_diff_key($sub, ['guests_adults' => 1]), [], 'en'));
check('render: missing party tidies the comma', str_contains($noParty, 'a stay for 22–24 Oct 2026 (2 nights).'));
check('render: unknown placeholder empty', wa_reply_render('Hi {{nope}} {{first_name}}', ['first_name' => 'Jo']) === 'Hi Jo');
check('render: plain lines kept', wa_reply_render("Line one\n\nThanks", []) === "Line one\n\nThanks");

// ── Owner templates ──
check('template: defaults valid', wa_reply_template_problem(WA_REPLY_DEFAULTS['en']) === null && wa_reply_template_problem(WA_REPLY_DEFAULTS['it']) === null);
check('template: unknown placeholder refused', wa_reply_template_problem('Hi {{frist_name}}') !== null);
check('template: too long refused', wa_reply_template_problem(str_repeat('a', WA_REPLY_MAX_LEN + 1)) !== null);

// ── Link ──
check('link: encoded', wa_reply_link('34666', "Hi Jess,\nok") === 'https://wa.me/34666?text=Hi%20Jess%2C%0Aok');
check('link: no number → empty', wa_reply_link('', 'x') === '');

echo $failures ? "\n{$failures} FAILED\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
