<?php
/**
 * Messages "Desk" view helpers — shared by admin/messages.php (Customer),
 * admin/internal-messages.php (Team chat) and the booking workspace's Messages
 * tab, so the three draw the same list rows, avatars and bubbles.
 *
 * Bubbles stay plain `.am-msg` (+ `.am-msg__meta`): the live poll scripts
 * (admin-chat.js, admin-internal-chat.js) append exactly that markup, so a
 * server-rendered and a polled bubble look the same. Day separators (`.am-day`)
 * are server-rendered only. Pure except where noted; all output is escaped.
 */
declare(strict_types=1);

/** Up to two initials from a name ("Amara Okafor" → "AO"); "?" when empty. Pure. */
function mx_initials(string $name): string {
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) return '?';
    $first = mb_substr($parts[0], 0, 1);
    $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/** A stable avatar colour for a key (same guest → same colour). Pure. */
function mx_color(string $key): string {
    $pal = ['#1E5C6B', '#B8965A', '#7a5c99', '#2e7d32', '#c2621b', '#3d7ea6', '#a0465a', '#5b6b2e'];
    return $pal[abs(crc32($key)) % count($pal)];
}

/** Avatar span (initials on a colour). */
function mx_avatar(string $name, string $key = '', bool $small = false): string {
    return '<span class="mx-av' . ($small ? ' mx-av--sm' : '') . '" style="background:' . e(mx_color($key !== '' ? $key : $name)) . '" aria-hidden="true">'
         . e(mx_initials($name)) . '</span>';
}

/**
 * Short "when" for a list row: 09:42 today, "Yesterday", the weekday within a
 * week, else "3 Oct". Nairobi-local like the rest of the app. Pure given $now.
 */
function mx_when($ts, ?int $now = null): string {
    if ($ts === null || $ts === '') return '';
    $t = is_int($ts) ? $ts : strtotime((string)$ts);
    if ($t === false) return '';
    $now   = $now ?? time();
    $today = strtotime(date('Y-m-d', $now));
    if ($t >= $today) return date('H:i', $t);
    if ($t >= $today - 86400) return 'Yesterday';
    if ($t >= $today - 6 * 86400) return date('D', $t);
    return date('j M', $t);
}

/** Day separator label for a bubble group: "Today", "Yesterday", "Mon 3 Oct". Pure given $now. */
function mx_day_label($ts, ?int $now = null): string {
    $t = is_int($ts) ? $ts : strtotime((string)$ts);
    if ($t === false) return '';
    $now   = $now ?? time();
    $today = strtotime(date('Y-m-d', $now));
    if ($t >= $today) return 'Today';
    if ($t >= $today - 86400) return 'Yesterday';
    return date(date('Y', $t) === date('Y', $now) ? 'D j M' : 'D j M Y', $t);
}

/**
 * Bubbles for a thread, oldest → newest, with a day separator whenever the date
 * changes. Each $msg: ['id','body','created_at','mine'(bool),'who'(string)].
 */
function mx_bubbles_html(array $msgs): string {
    $out = ''; $lastDay = '';
    foreach ($msgs as $m) {
        $day = date('Y-m-d', strtotime((string)$m['created_at']));
        if ($day !== $lastDay) { $out .= '<div class="am-day">' . e(mx_day_label((string)$m['created_at'])) . '</div>'; $lastDay = $day; }
        $out .= '<div class="am-msg ' . (!empty($m['mine']) ? 'am-msg--staff' : 'am-msg--guest') . '" data-mid="' . (int)$m['id'] . '">'
              . e((string)$m['body'])
              . '<div class="am-msg__meta">' . e((string)$m['who']) . ' · ' . e(message_time_label($m['created_at'])) . '</div></div>';
    }
    return $out;
}

/** Chip for a request's status (requested / in progress / done …). */
function mx_status_chip(string $status): string {
    $cls = match ($status) {
        'requested' => 'mx-chip--req',
        'confirmed' => 'mx-chip',
        'completed' => 'mx-chip--done',
        default     => 'mx-chip--off',
    };
    return '<span class="mx-chip ' . $cls . '">' . e(addon_status_label($status)) . '</span>';
}

/**
 * The composer every chat uses: quick replies (optional), a growing textarea,
 * a round send button. Enter sends, Shift+Enter is a new line (admin-chat.js).
 * $hidden = [name => value] carried with the message.
 */
function mx_composer_html(string $action, array $hidden, string $placeholder, array $quick = []): string {
    $h = '<form id="amForm" method="POST"' . ($action !== '' ? ' action="' . e($action) . '"' : '') . ' class="am-composer mx-composer">' . csrf_field();
    foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="' . e((string)$k) . '" value="' . e((string)$v) . '">';
    if ($quick) {
        $h .= '<div class="mx-quick" aria-label="Quick replies">';
        foreach ($quick as $q) $h .= '<button type="button" data-mx-quick="' . e($q) . '">' . e($q) . '</button>';
        $h .= '</div>';
    }
    $h .= '<div class="mx-inputrow"><textarea name="body" rows="1" required maxlength="2000" placeholder="' . e($placeholder) . '"></textarea>'
        . '<button type="submit" class="mx-send" aria-label="Send" data-tip="Send (Enter)">' . admin_icon('send', 17) . '</button></div>'
        . '<div class="am-composer__actions"><span class="mx-hint">Enter to send · Shift+Enter for a new line</span>'
        . '<span class="am-status text-muted" aria-live="polite" style="font-size:12px;margin-left:auto"></span></div>'
        . '</form>';
    return $h;
}
