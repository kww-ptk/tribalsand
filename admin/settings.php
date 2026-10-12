<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin-media-picker.php';
require_once __DIR__ . '/../includes/quote-docs.php';      // QB_QUOTE_TERMS_DEFAULT
require_once __DIR__ . '/../includes/whatsapp-reply.php';  // WA_REPLY_*
require_login();
require_owner();

// Settings → General (design C, Oct 2026): every setting is in ONE form saved by
// ONE "Save changes" bar (action save_all). Groups open in place and show what is
// set now when closed. Actions that are not settings keep their own button and
// form: Fetch latest rates (fx_sync), Change password, Export holds.

$success = '';
$error   = '';
$posted  = null;   // the submitted form, re-shown after a refused save so nothing typed is lost

$ST_CURRENCIES = [
    'USD'=>'USD — US Dollar', 'EUR'=>'EUR — Euro', 'GBP'=>'GBP — British Pound',
    'KES'=>'KES — Kenyan Shilling', 'TZS'=>'TZS — Tanzanian Shilling', 'UGX'=>'UGX — Ugandan Shilling',
    'ZAR'=>'ZAR — South African Rand', 'AED'=>'AED — UAE Dirham', 'CHF'=>'CHF — Swiss Franc',
    'AUD'=>'AUD — Australian Dollar', 'CAD'=>'CAD — Canadian Dollar', 'JPY'=>'JPY — Japanese Yen',
    'CNY'=>'CNY — Chinese Yuan', 'INR'=>'INR — Indian Rupee',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'export_holds') {
        require_once __DIR__ . '/../includes/booking.php';
        $holds = db_query(
            "SELECT h.*, u.name AS unit_name, r.name AS room_name, s.guest_phone
             FROM holds h
             JOIN units u ON u.id = h.unit_id
             JOIN rooms r ON r.id = " . hold_room_id_sql('h', 'u') . "
             LEFT JOIN submissions s ON s.id = h.submission_id
             WHERE h.status IN ('pending','confirmed')
             ORDER BY h.check_in ASC"
        )->fetchAll();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="holds-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Reference', 'Status', 'Room', 'Unit', 'Guest Name', 'Email', 'Phone', 'Check-in', 'Check-out', 'Expires', 'Created']);
        foreach ($holds as $h) {
            $ref = make_guest_ref((int)$h['id']);
            fputcsv($out, [
                $ref ?: 'HOLD-' . $h['id'],
                $h['status'],
                $h['room_name'],
                $h['unit_name'],
                $h['guest_name'],
                $h['guest_email'],
                $h['guest_phone'] ?? '',
                $h['check_in'],
                $h['check_out'],
                $h['expires_at'] ?? '',
                $h['created_at'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    if ($action === 'save_all') {
        // Validate EVERYTHING first; write nothing unless all of it is good.
        $str  = fn(string $k) => trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? '')));
        $errs = [];

        $new = [
            'form_mode'            => in_array($_POST['form_mode'] ?? '', ['enquiry', 'availability'], true) ? $_POST['form_mode'] : 'enquiry',
            'website_holds'        => isset($_POST['website_holds']) ? '1' : '0',
            'unit_assignment'      => in_array($_POST['unit_assignment'] ?? '', ['auto', 'manual'], true) ? $_POST['unit_assignment'] : 'auto',
            'notify_email'         => $str('notify_email'),
            'checkin_instructions' => $str('checkin_instructions'),
            'site_currency'        => strtoupper($str('site_currency')) ?: 'USD',
            'quote_terms'          => $str('quote_terms'),
            'site_share_image'     => $str('site_share_image'),
        ];
        if ($new['notify_email'] !== '' && !filter_var($new['notify_email'], FILTER_VALIDATE_EMAIL)) $errs[] = 'Notification email: enter a valid address.';
        if (!preg_match('/^[A-Z]{3}$/', $new['site_currency'])) $errs[] = 'Default currency: pick one from the list.';
        if (mb_strlen($new['quote_terms']) > 2000) $errs[] = 'Quote terms are limited to 2,000 characters.';
        if ($new['site_share_image'] === SITE_SHARE_IMAGE_DEFAULT) $new['site_share_image'] = '';
        if ($new['site_share_image'] !== '' && !share_image_key_ok($new['site_share_image'])) $errs[] = 'Share image: choose it again from the image library.';
        foreach (WA_REPLY_LANGS as $l => $lname) {
            $t = $str('whatsapp_reply_' . $l);
            if ($p = wa_reply_template_problem($t)) $errs[] = 'WhatsApp message (' . $lname . '): ' . $p;
            $new['whatsapp_reply_' . $l] = $t === trim(WA_REPLY_DEFAULTS[$l]) ? '' : $t;   // the default is stored as blank
        }

        $fxNow  = fx_rates(true);
        $rates  = $fxNow['rates'] ?? [];
        $locked = [];
        foreach (array_keys(TS_CURRENCIES) as $c) {
            if ($c === 'USD') { $rates['USD'] = 1.0; continue; }   // base fixed
            $v = (float) str_replace(',', '', (string)($_POST['rate'][$c] ?? ''));
            if ($v > 0) $rates[$c] = $v; else $errs[] = 'Exchange rate ' . $c . ': enter a number above 0.';
            if (!empty($_POST['locked'][$c])) $locked[$c] = true;
        }

        if ($errs) {
            $error  = 'Nothing was saved. ' . implode(' ', array_unique($errs));
            $posted = $_POST;
        } else {
            $labels = [
                'form_mode' => 'Booking form mode', 'website_holds' => 'Website holds', 'unit_assignment' => 'Unit assignment',
                'notify_email' => 'Notification email', 'checkin_instructions' => 'Check-in instructions',
                'site_currency' => 'Default currency', 'quote_terms' => 'Quote terms', 'site_share_image' => 'Share image',
                'whatsapp_reply_en' => 'WhatsApp message (English)', 'whatsapp_reply_it' => 'WhatsApp message (Italiano)',
            ];
            $changed = [];
            foreach ($new as $k => $v) {
                $old = $k === 'website_holds' ? (website_holds_enabled() ? '1' : '0') : (string) setting($k, '');
                if ($k === 'form_mode' && $old === '') $old = 'enquiry';
                if ($k === 'unit_assignment' && $old === '') $old = 'auto';
                if ((string)$v !== $old) { set_setting($k, (string)$v); $changed[] = $labels[$k] ?? $k; }
            }
            $oldRates = $fxNow['rates'] ?? []; $oldLocked = is_array($fxNow['locked'] ?? null) ? array_filter($fxNow['locked']) : [];
            $fxChanged = $locked != $oldLocked;
            foreach ($rates as $c => $v) if (abs((float)$v - (float)($oldRates[$c] ?? 0)) > 1e-9) $fxChanged = true;
            if ($fxChanged) { fx_save($rates, $locked); $changed[] = 'Exchange rates'; }

            if ($changed) {
                audit_log('settings.save', '', 0, implode(', ', $changed));
                $success = 'Saved: ' . implode(', ', $changed) . '.';
            } else {
                $success = 'Nothing had changed.';
            }
        }
    }

    if ($action === 'fx_sync') {
        $r = fx_sync_rates();
        if (!empty($r['ok'])) {
            audit_log('settings.fx_sync', '', 0, implode(',', $r['updated'] ?? []));
            $success = 'Exchange rates refreshed'
                     . (!empty($r['updated']) ? ' (' . implode(', ', $r['updated']) . ').' : '.');
        } else {
            $error = $r['message'] ?? 'Rate sync failed.';
        }
    }

    if ($action === 'change_password') {
        $current  = $_POST['current_password']  ?? '';
        $newPw    = $_POST['new_password']       ?? '';
        $confirm  = $_POST['confirm_password']   ?? '';

        $admin = current_admin();
        $user  = db_query('SELECT * FROM admin_users WHERE id = :id', [':id' => $admin['id']])->fetch();

        if (!password_verify($current, $user['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($newPw) < 10) {
            $error = 'New password must be at least 10 characters.';
        } elseif ($newPw === $current) {
            $error = 'The new password must be different from the current one.';
        } elseif ($newPw !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            db_query(
                'UPDATE admin_users SET password_hash = :hash WHERE id = :id',
                [':hash' => password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]), ':id' => $admin['id']]
            );
            $success = 'Password changed successfully.';
        }
    }
}

// Current values — or what was typed, after a refused save.
$val = function (string $key, string $default = '') use ($posted): string {
    if ($posted !== null) return trim(str_replace("\r\n", "\n", (string)($posted[$key] ?? '')));
    return (string) setting($key, $default);
};
$form_mode            = $val('form_mode', 'enquiry') ?: 'enquiry';
$website_holds        = $posted !== null ? isset($posted['website_holds']) : website_holds_enabled();
$unit_assignment      = $val('unit_assignment', 'auto') ?: 'auto';
$notify_email         = $val('notify_email');
$site_currency        = $val('site_currency', 'USD') ?: 'USD';
$checkin_instructions = $val('checkin_instructions');
$quote_terms          = $val('quote_terms');
$share_key            = $val('site_share_image');
$wa_reply = [];
foreach (array_keys(WA_REPLY_LANGS) as $l) {
    $wa_reply[$l] = $posted !== null ? trim(str_replace("\r\n", "\n", (string)($posted['whatsapp_reply_' . $l] ?? ''))) : wa_reply_template($l);
}
// Keep an unlisted stored code selectable so saving never loses it.
if (!isset($ST_CURRENCIES[$site_currency])) $ST_CURRENCIES = [$site_currency => $site_currency] + $ST_CURRENCIES;

$fx           = fx_rates(true); // fresh read so a just-saved/synced value shows immediately
$fx_rates_map = $fx['rates']  ?? [];
$fx_locked    = is_array($fx['locked'] ?? null) ? $fx['locked'] : [];
$fx_fetched   = $fx['fetched_at'] ?? null;
if ($posted !== null) {
    foreach (array_keys(TS_CURRENCIES) as $c) {
        if ($c === 'USD') continue;
        $fx_rates_map[$c] = (string)($posted['rate'][$c] ?? '');
        $fx_locked[$c]    = !empty($posted['locked'][$c]);
    }
}
$fmtRate = fn($v) => is_numeric($v) ? rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.') : (string)$v;

// One-line summaries shown on each closed group.
$lockedList = array_keys(array_filter($fx_locked));
$sum = [
    'booking'  => ($form_mode === 'availability' ? 'Live availability' : 'Enquiry form')
                . ' · website holds ' . ($website_holds ? 'on' : 'off')
                . ' · units ' . ($unit_assignment === 'manual' ? 'chosen by staff' : 'automatic'),
    'guests'   => ($notify_email !== '' ? $notify_email : 'No notification email')
                . ' · ' . ($checkin_instructions !== '' ? 'check-in instructions set' : 'no check-in instructions'),
    'money'    => $site_currency
                . ($lockedList ? ' · ' . implode(', ', $lockedList) . ' locked' : '')
                . ' · rates updated ' . ($fx_fetched ? date('j M, H:i', strtotime($fx_fetched)) : 'never'),
    'messages' => ($quote_terms !== '' ? 'Own quote terms' : 'Default quote terms')
                . ' · WhatsApp reply in ' . implode(' and ', WA_REPLY_LANGS),
    'website'  => $share_key !== '' ? 'Own share image' : 'Built-in share image',
];

$pageTitle  = 'Settings';
$activeMenu = 'settings';
include __DIR__ . '/_layout.php';
?>
<style>
  .st-intro{margin:-4px 0 16px;color:var(--muted);font-size:13px}
  .st-acc{background:var(--white);border:1px solid var(--border);border-radius:14px;margin-bottom:12px;transition:box-shadow .15s,border-color .15s}
  .st-acc.is-open{box-shadow:0 8px 28px rgba(16,47,58,.08);border-color:#d6cabd}
  .st-acc__head{width:100%;display:grid;grid-template-columns:40px minmax(0,1fr) auto 20px;gap:14px;align-items:center;border:0;background:transparent;padding:16px 20px;text-align:left;cursor:pointer;font:inherit;color:inherit}
  .st-acc__ic{width:40px;height:40px;border-radius:11px;background:#e6f0f1;color:var(--brand);display:grid;place-items:center}
  .st-acc__t b{display:block;font-size:15px}
  .st-acc__t span{display:block;font-size:12.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .st-acc__badge{font-size:11.5px;font-weight:700;color:#7a5b20;background:#f6ecd8;border-radius:999px;padding:2px 9px}
  .st-acc__badge[hidden]{display:none}
  .st-acc__chev{color:var(--muted);transition:transform .2s;display:flex}
  .st-acc.is-open .st-acc__chev{transform:rotate(180deg)}
  .st-acc__body{display:none;padding:2px 20px 20px 74px}
  .st-acc.is-open .st-acc__body{display:block}
  .st-f{padding:14px 0}
  .st-f + .st-f{border-top:1px solid #f1ebe2}
  .st-f__l{display:flex;align-items:center;gap:6px;font-size:13.5px;font-weight:600;margin:0 0 2px}
  .st-f__h{font-size:12.5px;color:var(--muted);margin:0 0 10px;line-height:1.5}
  .st-f.is-changed .st-f__l::after{content:"";width:7px;height:7px;border-radius:50%;background:var(--accent)}
  .st-rcs{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px;max-width:760px}
  .st-rc{position:relative;display:flex;gap:11px;align-items:flex-start;border:1.5px solid var(--border);border-radius:11px;padding:12px 14px;background:var(--white);cursor:pointer;transition:border-color .15s,background .15s}
  .st-rc input{position:absolute;opacity:0;pointer-events:none}
  .st-rc__dot{width:18px;height:18px;border-radius:50%;border:2px solid #cbbfb4;flex:none;margin-top:1px;display:grid;place-items:center}
  .st-rc__dot::after{content:"";width:8px;height:8px;border-radius:50%;background:var(--brand);transform:scale(0);transition:transform .15s}
  .st-rc b{display:block;font-size:13.5px}
  .st-rc small{display:block;font-size:12.5px;color:var(--muted);line-height:1.45;margin-top:2px}
  .st-rc:has(input:checked){border-color:var(--brand);background:#e6f0f1}
  .st-rc:has(input:checked) .st-rc__dot{border-color:var(--brand)}
  .st-rc:has(input:checked) .st-rc__dot::after{transform:scale(1)}
  .st-rc:has(input:focus-visible){box-shadow:0 0 0 3px rgba(30,92,107,.18)}
  .st-cur .eselect{display:flex;width:100%}
  .st-cur .eselect__btn{width:100%}
  .st-rates{border-collapse:collapse;font-size:13px;width:100%;max-width:620px}
  .st-rates th{text-align:left;font-size:11.5px;font-weight:600;color:var(--muted);letter-spacing:.04em;text-transform:uppercase;padding:0 10px 8px 0}
  .st-rates td{padding:6px 10px 6px 0;border-top:1px solid #f1ebe2}
  .st-rates .inp{width:130px}
  .st-lock{position:relative;display:inline-flex;align-items:center;gap:6px;border:1.5px solid var(--border);border-radius:999px;padding:4px 11px;background:var(--white);font-size:12px;font-weight:600;color:var(--muted);cursor:pointer;user-select:none;white-space:nowrap}
  .st-lock input{position:absolute;opacity:0;pointer-events:none}
  .st-lock:has(input:checked){background:var(--brand);border-color:var(--brand);color:#fff}
  .st-lock:has(input:focus-visible){box-shadow:0 0 0 3px rgba(30,92,107,.18)}
  .st-lock .on{display:none}.st-lock:has(input:checked) .on{display:inline}.st-lock:has(input:checked) .off{display:none}
  .st-wa-top{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap}
  .st-wa-top .segswitch{margin:0}
  .st-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
  .st-chip{border:1px solid var(--border);background:var(--bg);border-radius:999px;padding:3px 10px;font-size:12px;color:var(--brand);font-weight:600;cursor:pointer;font-family:inherit}
  .st-chip:hover{border-color:var(--brand)}
  .st-own{border:1px dashed #d6cabd;border-radius:12px;padding:14px 16px;background:#fffdf9;display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
  .st-own p{margin:0;font-size:12.5px;color:var(--muted);flex:1;min-width:220px}
  .st-own p b{color:var(--text)}
  .st-pw{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;width:100%;margin-top:4px}
  .st-pw .field{margin:0}
  .st-sys{font-size:12px;color:var(--muted);display:flex;gap:18px;flex-wrap:wrap;margin-top:6px}
  .st-savebar{position:sticky;bottom:0;z-index:30;display:none;align-items:center;gap:14px;margin:6px -4px 0;padding:12px 20px;border-radius:12px;background:var(--brand-dk);color:#fff;box-shadow:0 -8px 24px rgba(16,47,58,.18)}
  .st-savebar.is-on{display:flex}
  .st-savebar__n{display:inline-grid;place-items:center;min-width:24px;height:24px;border-radius:999px;background:var(--accent);color:var(--brand-dk);font-weight:700;font-size:12px;padding:0 7px}
  .st-savebar__list{font-size:12.5px;color:#a9c4c7;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .st-savebar__discard{background:transparent;border:0;color:#cfe0dc;font:inherit;font-weight:600;font-size:13px;cursor:pointer;padding:6px 10px}
  .st-savebar__discard:hover{color:#fff}
  .st-savebar__save{background:#fff;color:var(--brand-dk);border:0;border-radius:9px;padding:9px 18px;font:inherit;font-weight:700;font-size:13.5px;cursor:pointer}
  @media (max-width:760px){
    .st-acc__body{padding:2px 16px 18px}
    .st-acc__head{grid-template-columns:36px minmax(0,1fr) 20px;padding:14px 16px}
    .st-acc__badge{display:none}
    .st-savebar{padding:10px 14px}
    .st-savebar__list{display:none}
  }
</style>
<?php
$ico = [
    'booking'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
    'guests'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>',
    'money'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M15 9.5c-.5-1-1.6-1.5-3-1.5-1.7 0-3 .9-3 2.2 0 3 6 1.6 6 4.6 0 1.3-1.3 2.2-3 2.2-1.4 0-2.5-.5-3-1.5M12 6v2M12 16v2"/></svg>',
    'messages' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
    'website'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>',
    'account'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>',
];
$chev = '<span class="st-acc__chev"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span>';
$accHead = fn(string $k, string $title, string $summary) =>
    '<button type="button" class="st-acc__head" aria-expanded="false"><span class="st-acc__ic">' . $ico[$k] . '</span>'
  . '<span class="st-acc__t"><b>' . $title . '</b><span>' . e($summary) . '</span></span><span class="st-acc__badge" hidden></span>' . $chev . '</button>';
$radio = function (string $name, string $value, string $current, string $title, string $sub): string {
    return '<label class="st-rc"><input type="radio" name="' . e($name) . '" value="' . e($value) . '"' . ($current === $value ? ' checked' : '') . '>'
         . '<span class="st-rc__dot"></span><span><b>' . $title . '</b><small>' . $sub . '</small></span></label>';
};
?>

<div class="page-header">
  <h1>Settings</h1>
</div>

<nav class="tabs" aria-label="Settings sections">
  <a href="/admin/settings.php" class="tab-btn is-active">General</a>
  <a href="/admin/checkin-settings.php" class="tab-btn">Pre-Check-in</a>
  <a href="/admin/migrate.php" class="tab-btn">Migrations</a>
</nav>

<?php if ($success): ?><div class="alert alert--success is-flash"><?= e($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert--error is-flash"><?= e($error) ?></div><?php endif; ?>

<p class="st-intro">Each line shows what is set now. Open one to change it — everything saves together with one button.</p>

<form method="POST" action="/admin/settings" id="stForm" data-shell-form>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_all">

  <!-- Booking -->
  <div class="st-acc is-open" data-acc="booking">
    <?= $accHead('booking', 'Booking', $sum['booking']) ?>
    <div class="st-acc__body">
      <div class="st-f" data-label="Booking form mode">
        <div class="st-f__l">Booking form mode</div>
        <p class="st-f__h">Used by every room set to “Use global setting” (Rooms → Edit). Live on the website straight away.</p>
        <div class="st-rcs">
          <?= $radio('form_mode', 'enquiry', $form_mode, 'Enquiry form', 'Guests send name, dates and a message. It goes to your inbox.') ?>
          <?= $radio('form_mode', 'availability', $form_mode, 'Live availability <span class="badge badge--orange" style="font-size:10px">v2</span>', 'Guests pick open dates on the calendar. Their request comes to reservations, who use Convert to Hold.') ?>
        </div>
      </div>
      <div class="st-f" data-label="Website holds">
        <div class="st-f__l">Automatic holds</div>
        <label class="togglerow" data-help="website-holds">
          <span class="toggle"><input type="checkbox" name="website_holds" value="1" <?= $website_holds ? 'checked' : '' ?>><span class="toggle-slider"></span></span>
          <span>Let the website place 24-hour holds by itself <span class="text-muted">(off: every hold is made by the reservations team — the booking box, room combinations and the Maya Ilai configurator only send a request)</span></span>
        </label>
      </div>
      <div class="st-f" data-label="Unit assignment">
        <div class="st-f__l">Unit assignment</div>
        <p class="st-f__h">Which physical room a booking gets.</p>
        <div class="st-rcs">
          <?= $radio('unit_assignment', 'auto', $unit_assignment, 'Automatic', 'The first free unit is given when the request comes in.') ?>
          <?= $radio('unit_assignment', 'manual', $unit_assignment, 'Staff choose', 'Pick the unit on the Holds page before confirming.') ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Guests & email -->
  <div class="st-acc" data-acc="guests">
    <?= $accHead('guests', 'Guests &amp; email', $sum['guests']) ?>
    <div class="st-acc__body">
      <div class="st-f" data-label="Notification email">
        <label class="st-f__l" for="stNotify">Notification email</label>
        <p class="st-f__h">Every form on the website sends a copy here.</p>
        <input type="email" id="stNotify" name="notify_email" class="inp" style="max-width:420px" value="<?= e($notify_email) ?>" placeholder="reservations@tribalsand.com">
      </div>
      <div class="st-f" data-label="Check-in instructions">
        <label class="st-f__l" for="stCheckin">Check-in instructions</label>
        <p class="st-f__h">Added to the booking confirmation email. Leave blank to leave it out.</p>
        <textarea id="stCheckin" name="checkin_instructions" rows="4" class="inp inp--area" style="width:100%;min-height:100px" placeholder="e.g. Check-in is from 14:00. Our reception is open 24 hours. Please WhatsApp us your flight details 48 hours before arrival..."><?= e($checkin_instructions) ?></textarea>
      </div>
    </div>
  </div>

  <!-- Money -->
  <div class="st-acc" data-acc="money">
    <?= $accHead('money', 'Money', $sum['money']) ?>
    <div class="st-acc__body">
      <div class="st-f" data-label="Default currency">
        <label class="st-f__l" for="stCurrency">Default currency</label>
        <p class="st-f__h">Prices show in this currency until a guest switches.</p>
        <div class="st-cur" style="max-width:280px">
          <select id="stCurrency" name="site_currency" class="inp">
            <?php foreach ($ST_CURRENCIES as $code => $lbl): ?>
            <option value="<?= e($code) ?>" <?= $site_currency === $code ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="st-f" data-label="Exchange rates">
        <div class="st-f__l">Exchange rates</div>
        <p class="st-f__h">What guests see when they switch currency on the website. <strong>Display only</strong> — every booking still settles in the property's real price. Base is USD. A locked rate is never changed by the daily update.</p>
        <table class="st-rates">
          <thead><tr><th>Currency</th><th>Per 1 USD</th><th>Daily update</th></tr></thead>
          <tbody>
          <?php foreach (TS_CURRENCIES as $code => $meta): if ($code === 'USD') continue; ?>
            <tr>
              <td><strong><?= e($code) ?></strong> <span class="text-muted" style="font-size:12px"><?= e($meta['name']) ?></span></td>
              <td><input type="text" name="rate[<?= e($code) ?>]" value="<?= e($fmtRate($fx_rates_map[$code] ?? 0)) ?>" class="inp" inputmode="decimal" aria-label="<?= e($code) ?> per 1 USD"></td>
              <td>
                <label class="st-lock">
                  <input type="checkbox" name="locked[<?= e($code) ?>]" value="1" <?= !empty($fx_locked[$code]) ? 'checked' : '' ?>>
                  <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                  <span class="on">Locked — I set it</span><span class="off">Updates daily</span>
                </label>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:12px">
          <button type="submit" form="stFxSync" class="btn-outline btn-sm" id="stFxBtn"><?= admin_icon('rotate', 14) ?> Fetch latest rates now</button>
          <span class="text-muted" style="font-size:12.5px">Last updated <?= $fx_fetched ? e(date('j M Y, H:i', strtotime($fx_fetched))) : 'never — using built-in rates' ?>. Unlocked currencies update; locked ones stay.</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Messages -->
  <div class="st-acc" data-acc="messages">
    <?= $accHead('messages', 'Messages', $sum['messages']) ?>
    <div class="st-acc__body">
      <div class="st-f" data-label="Quote terms">
        <label class="st-f__l" for="stTerms">Quote terms</label>
        <p class="st-f__h">Printed at the foot of every quotation from the Quote builder. A saved quote keeps the terms it was issued with. Leave blank to use the default.</p>
        <textarea id="stTerms" name="quote_terms" rows="4" maxlength="2000" class="inp inp--area" style="width:100%;min-height:96px" placeholder="<?= e(QB_QUOTE_TERMS_DEFAULT) ?>"><?= e($quote_terms) ?></textarea>
      </div>
      <div class="st-f" id="waReplySettings" data-label="WhatsApp reply">
        <div class="st-f__l">WhatsApp reply</div>
        <p class="st-f__h">Filled in when staff press <strong>Open in WhatsApp</strong> on an enquiry; they can still edit it. A line whose details are all missing is left out. Clear a box to go back to the default.</p>
        <div class="st-wa-top">
          <nav class="segswitch" aria-label="Message language" data-st-wa-seg>
            <?php $__first = true; foreach (WA_REPLY_LANGS as $l => $lname): ?>
            <a href="#waReplySettings" data-no-shell data-l="<?= e($l) ?>"<?= $__first ? ' class="is-on"' : '' ?>><?= e($lname) ?></a>
            <?php $__first = false; endforeach; ?>
          </nav>
          <span class="text-muted" style="font-size:12px">Click a detail to insert it</span>
        </div>
        <?php $__first = true; foreach (WA_REPLY_LANGS as $l => $lname): ?>
        <textarea name="whatsapp_reply_<?= e($l) ?>" data-st-wa="<?= e($l) ?>" rows="4" maxlength="<?= WA_REPLY_MAX_LEN ?>" class="inp inp--area"
                  style="width:100%;min-height:96px" aria-label="WhatsApp message (<?= e($lname) ?>)" placeholder="<?= e(WA_REPLY_DEFAULTS[$l]) ?>"<?= $__first ? '' : ' hidden' ?>><?= e($wa_reply[$l]) ?></textarea>
        <?php $__first = false; endforeach; ?>
        <div class="st-chips">
          <?php foreach (WA_REPLY_PLACEHOLDERS as $ph => $what): ?>
          <button type="button" class="st-chip" data-st-ins="{{<?= e($ph) ?>}}" title="<?= e($what) ?>">{{<?= e($ph) ?>}}</button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Website -->
  <div class="st-acc" data-acc="website">
    <?= $accHead('website', 'Website', $sum['website']) ?>
    <div class="st-acc__body">
      <div class="st-f" data-label="Share image">
        <div class="st-f__l">Social sharing image</div>
        <p class="st-f__h">The picture shown when someone shares a link to the website on WhatsApp, Facebook, LinkedIn or X — for the home page and every page without its own. Property pages and some content pages set their own (Website → Properties → Content tab, and Website → Website content → Pages).</p>
        <?php media_picker_field('site_share_image', $share_key, 'Default share image',
              'Landscape works best: it is shown at 1200 × 630, so a tall photo is cropped top and bottom. Apps such as WhatsApp and Facebook keep the old preview for a while after a change.',
              SITE_SHARE_IMAGE_DEFAULT); ?>
      </div>
    </div>
  </div>

  <div class="st-savebar" id="stSavebar" role="region" aria-label="Unsaved changes">
    <span class="st-savebar__n" id="stSaveN">0</span>
    <span class="st-savebar__list" id="stSaveList"></span>
    <button type="button" class="st-savebar__discard" id="stDiscard">Discard</button>
    <button type="submit" class="st-savebar__save">Save changes</button>
  </div>
</form>

<!-- Your account — acts straight away, not part of Save changes -->
<div class="st-acc" data-acc="account">
  <?= $accHead('account', 'Your account', 'Password · export holds · system info') ?>
  <div class="st-acc__body">
    <form method="POST" action="/admin/settings" data-shell-form class="st-own">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">
      <p><b>Change password</b> — saved on its own with this button, not with Save changes. At least 10 characters.</p>
      <div class="st-pw">
        <div class="field">
          <label for="curPw">Current password</label>
          <input type="password" id="curPw" name="current_password" required autocomplete="current-password" data-pw-label="Current password" placeholder="Current password">
        </div>
        <div class="field">
          <label for="newPw">New password</label>
          <input type="password" id="newPw" name="new_password" required minlength="10" autocomplete="new-password" data-pw-label="New password" placeholder="New password">
        </div>
        <div class="field">
          <label for="confirmPw">Confirm new password</label>
          <input type="password" id="confirmPw" name="confirm_password" required data-pw-match="new_password" data-pw-label="New password" autocomplete="new-password" placeholder="Re-enter the new password">
        </div>
      </div>
      <button type="submit" class="btn-primary btn-sm">Change password</button>
    </form>

    <form method="POST" action="/admin/settings" class="st-own">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="export_holds">
      <p><b>Export active holds</b> — a CSV of every pending and confirmed hold, with guest contact details and dates.</p>
      <button type="submit" class="btn-outline btn-sm"><?= admin_icon('download', 14) ?> Download CSV</button>
    </form>

    <div class="st-sys">
      <span>Form mode: <strong><?= e($form_mode) ?></strong></span>
      <span>Notify: <strong><?= e($notify_email ?: '—') ?></strong></span>
      <span>Currency: <strong><?= e($site_currency) ?></strong></span>
      <span>Units: <strong><?= e($unit_assignment) ?></strong></span>
    </div>
  </div>
</div>

<form method="POST" action="/admin/settings" id="stFxSync" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="fx_sync">
</form>

<script>
  (function () {
    var form = document.getElementById('stForm');
    if (!form || form.dataset.stReady) return;
    form.dataset.stReady = '1';
    var bar = document.getElementById('stSavebar'), nEl = document.getElementById('stSaveN'), listEl = document.getElementById('stSaveList');
    var accs = document.querySelectorAll('.st-acc');

    // Open / close groups. The Booking group starts open; a #hash opens its group.
    function setOpen(acc, on) { acc.classList.toggle('is-open', on); acc.querySelector('.st-acc__head').setAttribute('aria-expanded', on ? 'true' : 'false'); }
    accs.forEach(function (acc) {
      setOpen(acc, acc.classList.contains('is-open'));
      acc.querySelector('.st-acc__head').addEventListener('click', function () { setOpen(acc, !acc.classList.contains('is-open')); });
    });
    if (location.hash) {
      var t = document.getElementById(location.hash.slice(1));
      var a = t && t.closest('.st-acc');
      if (a) { setOpen(a, true); setTimeout(function () { t.scrollIntoView({ block: 'start' }); }, 50); }
    }
    <?php if ($error && $posted !== null): ?>accs.forEach(function (acc) { if (acc.dataset.acc !== 'account') setOpen(acc, true); });<?php endif; ?>

    // Change tracking: compare every field with what the page loaded with.
    function snapshot() {
      var s = {};
      form.querySelectorAll('input[name],textarea[name],select[name]').forEach(function (el) {
        if (el.name === 'action' || el.name === 'csrf_token') return;
        s[el.name + (el.type === 'radio' ? '=' + el.value : '')] = (el.type === 'checkbox' || el.type === 'radio') ? el.checked : el.value;
      });
      return s;
    }
    var orig = snapshot();
    function refresh() {
      var now = snapshot(), labels = [], perAcc = {};
      form.querySelectorAll('.st-f').forEach(function (f) { f.classList.remove('is-changed'); });
      Object.keys(now).forEach(function (k) {
        if (now[k] === orig[k]) return;
        var name = k.split('=')[0];
        var el = form.querySelector('[name="' + name.replace(/"/g, '\\"') + '"]');
        var f = el && el.closest('.st-f');
        if (!f || f.classList.contains('is-changed')) return;
        f.classList.add('is-changed');
        labels.push(f.dataset.label);
        var acc = f.closest('.st-acc').dataset.acc; perAcc[acc] = (perAcc[acc] || 0) + 1;
      });
      accs.forEach(function (acc) {
        var b = acc.querySelector('.st-acc__badge'), n = perAcc[acc.dataset.acc] || 0;
        b.hidden = !n; b.textContent = n + ' changed';
      });
      nEl.textContent = labels.length;
      listEl.textContent = labels.length + ' change' + (labels.length === 1 ? '' : 's') + ' — ' + labels.join(', ');
      bar.classList.toggle('is-on', labels.length > 0);
      // Fetching rates reloads the page: say so when something is unsaved.
      var fx = document.getElementById('stFxBtn');
      if (labels.length) fx.setAttribute('data-confirm', 'You have unsaved changes. Fetching rates reloads the page and they will be lost.');
      else fx.removeAttribute('data-confirm');
    }
    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    form.addEventListener('click', function () { setTimeout(refresh, 0); });   // media picker "reset" sets the value without an event
    document.addEventListener('change', function (e) { if (e.target.closest && e.target.closest('#stForm')) refresh(); });

    document.getElementById('stDiscard').addEventListener('click', function () {
      if (window.tsShellGo) window.tsShellGo(location.pathname + location.search); else location.reload();
    });

    // WhatsApp message: language switch + insert a detail at the cursor.
    form.querySelectorAll('[data-st-wa-seg] a').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        form.querySelectorAll('[data-st-wa-seg] a').forEach(function (x) { x.classList.toggle('is-on', x === a); });
        form.querySelectorAll('[data-st-wa]').forEach(function (t) { t.hidden = t.dataset.stWa !== a.dataset.l; });
      });
    });
    form.querySelectorAll('[data-st-ins]').forEach(function (c) {
      c.addEventListener('click', function () {
        var ta = [].filter.call(form.querySelectorAll('[data-st-wa]'), function (t) { return !t.hidden; })[0];
        if (!ta) return;
        var s = ta.selectionStart != null ? ta.selectionStart : ta.value.length, en = ta.selectionEnd != null ? ta.selectionEnd : s;
        ta.value = ta.value.slice(0, s) + c.dataset.stIns + ta.value.slice(en);
        ta.focus(); ta.selectionStart = ta.selectionEnd = s + c.dataset.stIns.length;
        refresh();
      });
    });
    refresh();
  })();
</script>

<?php media_picker_modal(); ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
