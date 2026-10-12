<?php
declare(strict_types=1);
/**
 * "Reply on WhatsApp" on an enquiry (admin/submission-view.php).
 *
 * Staff open the guest's chat in the WhatsApp they are signed in to (desktop
 * app or WhatsApp Web, via a wa.me link) with a ready message they can edit
 * first. Nothing is sent from our side — the person presses Send in WhatsApp —
 * and the page notes the message in the enquiry thread.
 *
 * The wording is owner-editable (Admin → Settings → WhatsApp Reply), one text
 * per language, with {{placeholders}} filled from the enquiry. A line whose
 * placeholders all come out empty is dropped, so "You also asked about:
 * {{extras}}" disappears when the guest asked for nothing extra.
 *
 * Everything here is pure except wa_reply_template() (one setting read).
 * Test: php tests/whatsapp_reply_logic.php
 */

const WA_REPLY_LANGS = ['en' => 'English', 'it' => 'Italiano'];

const WA_REPLY_DEFAULTS = [
    'en' => "Hi {{first_name}}, this is {{property}}.\n"
          . "We are answering your enquiry about a stay for {{party}}, {{dates}} ({{nights}}).\n"
          . "You also asked about: {{extras}}.",
    'it' => "Ciao {{first_name}}, siamo {{property}}.\n"
          . "Rispondiamo alla tua richiesta per un soggiorno per {{party}}, {{dates}} ({{nights}}).\n"
          . "Ci hai chiesto anche: {{extras}}.",
];

/** Placeholder → what it means (shown under the editor). */
const WA_REPLY_PLACEHOLDERS = [
    'first_name' => 'Guest first name',
    'guest_name' => 'Guest full name',
    'property'   => 'Property name',
    'room'       => 'Room enquired about',
    'activity'   => 'Activity enquired about',
    'party'      => '“4 adults, 1 child”',
    'dates'      => '“22–24 Oct 2026”',
    'check_in'   => 'Check-in date',
    'check_out'  => 'Check-out date',
    'nights'     => '“2 nights”',
    'extras'     => 'Add-ons and transfers ticked',
    'staff_name' => 'Your name',
];

const WA_REPLY_MAX_LEN = 2000;

function wa_reply_lang(string $lang): string {
    return array_key_exists($lang, WA_REPLY_LANGS) ? $lang : 'en';
}

/** The saved wording for a language, else the built-in default. */
function wa_reply_template(string $lang): string {
    $lang = wa_reply_lang($lang);
    $t = '';
    try { $t = trim((string) setting('whatsapp_reply_' . $lang, '')); } catch (Throwable $e) {}
    return $t !== '' ? $t : WA_REPLY_DEFAULTS[$lang];
}

/**
 * International digits for wa.me, or '' when unusable. "+34 666…" / "0034…" →
 * "34666…"; a Kenyan local "07…"/"01…" → "2547…" (most guests without a "+"
 * typed a Kenyan number). Pure.
 */
function wa_reply_phone_digits(?string $phone): string {
    $raw = trim((string) $phone);
    $d   = preg_replace('/\D+/', '', $raw);
    if ($d === '') return '';
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    elseif ($raw[0] !== '+' && str_starts_with($d, '0') && strlen($d) === 10) $d = '254' . substr($d, 1);
    return (strlen($d) >= 8 && strlen($d) <= 15) ? $d : '';
}

/** "22–24 Oct 2026", "30 Oct – 2 Nov 2026", "28 Dec 2026 – 3 Jan 2027". Pure. */
function wa_reply_date_range(string $in, string $out, string $lang): string {
    $a = strtotime($in); $b = strtotime($out);
    if (!$a) return '';
    $mon = fn(int $t) => $lang === 'it'
        ? ['gen','feb','mar','apr','mag','giu','lug','ago','set','ott','nov','dic'][(int)date('n', $t) - 1]
        : date('M', $t);
    $one = fn(int $t, bool $year) => date('j', $t) . ' ' . $mon($t) . ($year ? ' ' . date('Y', $t) : '');
    if (!$b || $b <= $a) return $one($a, true);
    if (date('Y-m', $a) === date('Y-m', $b)) return date('j', $a) . '–' . $one($b, true);
    if (date('Y', $a) === date('Y', $b))     return $one($a, false) . ' – ' . $one($b, true);
    return $one($a, true) . ' – ' . $one($b, true);
}

/**
 * Placeholder values for one enquiry in one language. Pure.
 * $sub = the submission row (+ room_name / tour_name); $payload = its decoded
 * payload_json; $ctx = ['property' => venue name, 'staff_name' => …].
 */
function wa_reply_vars(array $sub, array $payload, string $lang, array $ctx = []): array {
    $lang = wa_reply_lang($lang);
    $it   = $lang === 'it';
    $name = trim((string)($sub['guest_name'] ?? ''));
    $first = $name !== '' ? preg_split('/\s+/', $name)[0] : '';

    $ad = (int)($sub['guests_adults'] ?? 0);
    $ch = (int)($sub['guests_children'] ?? 0);
    $party = [];
    if ($ad > 0) $party[] = $ad . ' ' . ($it ? ($ad === 1 ? 'adulto' : 'adulti') : ($ad === 1 ? 'adult' : 'adults'));
    if ($ch > 0) $party[] = $ch . ' ' . ($it ? ($ch === 1 ? 'bambino' : 'bambini') : ($ch === 1 ? 'child' : 'children'));

    $in  = (string)($sub['check_in'] ?? '');
    $out = (string)($sub['check_out'] ?? '');
    $nights = ($in !== '' && $out !== '' && strtotime($out) > strtotime($in))
        ? (int) round((strtotime($out) - strtotime($in)) / 86400) : 0;
    $fmt = fn(string $d) => $d !== '' && strtotime($d) ? wa_reply_date_range($d, '', $lang) : '';

    $extras = [];
    foreach (['upsells' => 'name', 'transfers' => 'label'] as $k => $f) {
        foreach (is_array($payload[$k] ?? null) ? $payload[$k] : [] as $row) {
            $v = is_array($row) ? trim((string)($row[$f] ?? '')) : '';
            if ($v !== '') $extras[] = $v;
        }
    }

    return [
        'first_name' => $first,
        'guest_name' => $name,
        'property'   => trim((string)($ctx['property'] ?? '')) ?: 'Tribal Sand',
        'room'       => trim((string)($sub['room_name'] ?? '')),
        'activity'   => trim((string)($sub['tour_name'] ?? '')),
        'party'      => implode(', ', $party),
        'dates'      => $in !== '' ? wa_reply_date_range($in, $out, $lang) : '',
        'check_in'   => $fmt($in),
        'check_out'  => $fmt($out),
        'nights'     => $nights > 0 ? $nights . ' ' . ($it ? ($nights === 1 ? 'notte' : 'notti') : ($nights === 1 ? 'night' : 'nights')) : '',
        'extras'     => implode(', ', array_unique($extras)),
        'staff_name' => trim((string)($ctx['staff_name'] ?? '')),
    ];
}

/**
 * Fill a template. A line that uses placeholders and gets NO value for any
 * of them is dropped (no "You also asked about: ."); unknown placeholders
 * become empty. Pure.
 */
function wa_reply_render(string $template, array $vars): string {
    $lines = [];
    foreach (preg_split('/\r\n|\n|\r/', $template) as $line) {
        $used = 0; $filled = 0;
        $out = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($vars, &$used, &$filled) {
            $used++;
            $v = (string)($vars[$m[1]] ?? '');
            if ($v !== '') $filled++;
            return $v;
        }, $line);
        if ($used > 0 && $filled === 0) continue;
        // Tidy what a partly-empty line leaves behind: "for , 22–24 Oct ()" → "for 22–24 Oct".
        if ($used > $filled) {
            $out = preg_replace(['/\(\s*\)/', '/\s+,\s*/', '/,\s*,/', '/,\s*(?=[.!?)]|$)/', '/ {2,}/', '/\s+([.,!?])/'],
                                ['', ' ', ',', '', ' ', '$1'], $out);
        }
        $lines[] = rtrim($out);
    }
    return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
}

/** Problem with a template the owner typed, or null. Pure. */
function wa_reply_template_problem(string $t): ?string {
    if (mb_strlen($t) > WA_REPLY_MAX_LEN) return 'Each message is limited to ' . number_format(WA_REPLY_MAX_LEN) . ' characters.';
    preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $t, $m);
    $bad = array_diff(array_unique($m[1]), array_keys(WA_REPLY_PLACEHOLDERS));
    if ($bad) return 'Unknown placeholder: {{' . implode('}}, {{', $bad) . '}}.';
    return null;
}

/** https://wa.me link, or '' without a usable number. Pure. */
function wa_reply_link(string $digits, string $text): string {
    return $digits === '' ? '' : 'https://wa.me/' . $digits . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}
