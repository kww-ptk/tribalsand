<?php
declare(strict_types=1);
/**
 * Finance → Reports — the monthly report (design C, Oct 2026), shaped like the
 * reservations team's own monthly report: a headline written from the figures,
 * gross → commission → net → extras → total, a card per property, occupancy day
 * by day, extras and channels, what is already booked ahead, the portfolio table
 * and the team's notes for the period. Prints as the report (Print / PDF).
 *
 * Owner + house manager, scoped by admin_venue_ids() (owner = all; manager = own).
 * All figures come from includes/report-month.php (one pure model, tested by
 * tests/report_month_logic.php). Money is never summed across currencies.
 * Pre-migration-safe: no ledger → an empty state; no channel/commission columns →
 * commission reads 0 and the page says why.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/bookings.php';
require_once __DIR__ . '/../includes/report-month.php';
require_once __DIR__ . '/../includes/pos-support.php';   // pos_supported() — POS takings section
require_login();
require_manager();

$scope = admin_venue_ids();     // null = owner (all); [] = none; [ids] = manager's

$venues = $scope === null
    ? db_query("SELECT id, name FROM venues ORDER BY sort_order ASC, name ASC")->fetchAll()
    : ($scope
        ? db_query("SELECT id, name FROM venues WHERE id IN (" . implode(',', array_map('intval', $scope)) . ")
                    ORDER BY sort_order ASC, name ASC")->fetchAll()
        : []);
$allowedVenueIds = array_map(fn($v) => (int)$v['id'], $venues);

// ── Filters ──────────────────────────────────────────────────────────
// Old links (?range=last_month …) still land on the matching period.
$today  = date('Y-m-d');
$range  = (string)($_GET['range'] ?? 'month');
$anchor = (string)($_GET['p'] ?? '');
$legacy = [
    'this_month' => ['month', date('Y-m')], 'last_month' => ['month', date('Y-m', strtotime('first day of last month'))],
    'this_year'  => ['year',  date('Y-m')], 'last_year'  => ['year',  (date('Y') - 1) . '-12'],
    'last_12m'   => ['12m',   date('Y-m')], 'all'        => ['12m',   date('Y-m')],
];
if (isset($legacy[$range])) { [$range, $legacyAnchor] = $legacy[$range]; if ($anchor === '') $anchor = $legacyAnchor; }
$P = report_period($range, $anchor, $today);
$from = $P['from']; $toIncl = $P['to']; $range = $P['range'];

$fVenue = (int)($_GET['venue'] ?? 0);
if ($fVenue && !in_array($fVenue, $allowedVenueIds, true)) $fVenue = 0;
$reportVenueIds = $fVenue ? [$fVenue] : $scope;
$fSource = '';   // the POS partial reads it; reports no longer filter by source

$qs = fn(array $extra = []) => http_build_query(array_merge(
    ['range' => $range, 'p' => $P['anchor']] + ($fVenue ? ['venue' => $fVenue] : []), $extra));

// ── Team notes (PRG) ─────────────────────────────────────────────────
// Notes for "all properties" are the owner's; a property's notes can be written
// by anyone who can see that property here (owner + its manager).
$canNotes = is_owner() || $fVenue > 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notes') {
    verify_csrf();
    if ($canNotes) {
        $me = current_admin();
        report_notes_save($P['key'], $fVenue, $_POST, (string)($me['name'] ?? $me['email'] ?? ''));
        audit_log('reports.notes', 'report', 0, $P['key'] . ($fVenue ? ' · venue ' . $fVenue : ''));
        $_SESSION['rp_flash'] = 'Notes saved — they print with this report.';
    }
    header('Location: /admin/reports.php?' . $qs());
    exit;
}
$flash = $_SESSION['rp_flash'] ?? null; unset($_SESSION['rp_flash']);

// ── Data ─────────────────────────────────────────────────────────────
$stays   = report_stay_rows($reportVenueIds, $from, $toIncl);
$extras  = report_extras_rows($reportVenueIds, $from, $toIncl);
$rVenues = report_venues($reportVenueIds, array_map(fn($r) => (int)$r['venue_id'], $stays));
$M       = report_model($stays, $extras, $from, $toIncl, $rVenues);
$pc      = $M['primary'];
$T       = $pc !== '' ? $M['currencies'][$pc] : null;

$C = report_model(report_stay_rows($reportVenueIds, $P['cmp_from'], $P['cmp_to']),
                  report_extras_rows($reportVenueIds, $P['cmp_from'], $P['cmp_to']), $P['cmp_from'], $P['cmp_to'], []);
$CT = $pc !== '' ? ($C['currencies'][$pc] ?? null) : null;
$cmpVal = fn(string $k) => $CT ? (float)$CT[$k] : null;

$cancelled = report_cancelled_count($reportVenueIds, $from, $toIncl);
$enquiries = report_enquiry_count($reportVenueIds, $from, $toIncl);
$story     = report_story($M, $P, $cancelled);
$notes     = report_notes_get($P['key'], $fVenue);
$commOn    = bookings_channel_supported();

// Already booked ahead: this month and the five after it (Nairobi today).
$fwdFrom = date('Y-m');
$fwdTo   = date('Y-m-t', strtotime($fwdFrom . '-01 +5 month'));
$forward = $pc !== '' ? report_forward(report_stay_rows($reportVenueIds, $fwdFrom . '-01', $fwdTo), $fwdFrom, 6, $pc) : [];

// ── Point of sale (shop, spa, kite, experiences) ─────────────────────
$posRows = []; $pos = null;
if (pos_supported()) {
    require_once __DIR__ . '/../includes/pos.php';
    $me = current_admin();
    $outletIds = $me ? pos_manageable_outlet_ids($me) : [];
    if ($fVenue && $outletIds) {
        $outletIds = array_map('intval', db_query('SELECT id FROM pos_outlets WHERE venue_id = :v AND id IN (' . implode(',', $outletIds) . ')',
            [':v' => $fVenue])->fetchAll(PDO::FETCH_COLUMN));
    }
    $posRows = pos_report_sales($outletIds, $from, $toIncl);
    $pos = pos_report_summarize($posRows);
}

// ── CSV exports (same window/scope) ──────────────────────────────────
if (($_GET['export'] ?? '') === 'pos_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pos_sales_' . $from . '_to_' . $toIncl . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Outlet', 'Month', 'Currency', 'Number of sales', 'Sales (excl. tips)', 'VAT', 'Net of VAT', 'Tips', 'Of which room-charged'], ',', '"', '');
    foreach ($posRows as $r) {
        $tips = (float)$r['tips']; $vat = (float)$r['vat']; $sales = (float)$r['total'] - $tips;
        fputcsv($out, [$r['outlet'], $r['ym'], $r['currency'], (int)$r['n'], number_format($sales, 2, '.', ''), number_format($vat, 2, '.', ''),
                       number_format($sales - $vat, 2, '.', ''), number_format($tips, 2, '.', ''), number_format((float)$r['room_charged'], 2, '.', '')], ',', '"', '');
    }
    fclose($out);
    exit;
}
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bookings_' . $from . '_to_' . $toIncl . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Property', 'Source', 'Channel / agent', 'Guest', 'Check-in', 'Check-out', 'Nights', 'Nights in period',
                   'Currency', 'Gross', 'Commission', 'Net', 'Gross in period', 'Net in period', 'Reference'], ',', '"', '');
    foreach ($stays as $r) {
        $all = bookings_night_count((string)$r['check_in'], (string)$r['check_out']);
        $in  = bookings_night_overlap((string)$r['check_in'], (string)$r['check_out'], $from, $toIncl);
        $g = (float)$r['gross_amount']; $cm = (float)($r['commission_amount'] ?? 0);
        $sh = $all > 0 ? $in / $all : 0;
        $who = trim((string)($r['agent'] ?? '')); if ($who === '' || $who === '-') $who = (string)($r['channel'] ?? '');
        fputcsv($out, [$r['venue_name'] ?? '', bookings_source_label((string)$r['source']), $who, $r['guest_name'] ?? '',
            $r['check_in'], $r['check_out'], $all, $in, $r['currency'],
            number_format($g, 2, '.', ''), number_format($cm, 2, '.', ''), number_format($g - $cm, 2, '.', ''),
            number_format($g * $sh, 2, '.', ''), number_format(($g - $cm) * $sh, 2, '.', ''), $r['external_ref'] ?? ''], ',', '"', '');
    }
    fclose($out);
    exit;
}

$pageTitle  = 'Reports';
$activeMenu = 'reports';
include __DIR__ . '/_layout.php';

// ── View helpers ─────────────────────────────────────────────────────
$money = fn(float $a, string $c = '') => bookings_money($a, $c !== '' ? $c : $pc);
$short = fn(float $a, string $c = '') => report_money_short($a, $c !== '' ? $c : $pc);
$deltaHtml = function (?array $d): string {
    if (!$d) return '';
    return '<span class="rp-delta rp-delta--' . $d['dir'] . '">' . e($d['label']) . '</span>';
};
$pct = fn(?float $v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
$occColor = function (?float $v): string {
    if ($v === null || $v <= 0) return 'var(--rq0)';
    return $v < 35 ? 'var(--rq1)' : ($v < 60 ? 'var(--rq2)' : ($v < 90 ? 'var(--rq3)' : 'var(--rq4)'));
};
$chanColor = ['direct' => 'var(--rc1)', 'agent' => 'var(--rc2)', 'ota' => 'var(--rc3)', 'website' => 'var(--rc4)'];
$tints = ['#1E5C6B', '#2f6f63', '#b8965a', '#7a5b3b', '#4f6d7a', '#7d776d', '#5b6fc4'];
$headline = preg_replace('/\{\{(.+?)\}\}/', '<em>$1</em>', e($story['headline']));
$days = $M['days'];
$monthly = count($days) > 62;    // long ranges draw occupancy per month, not per day
$monthLabels = [];
if ($monthly) foreach ($days as $i => $d) $monthLabels[substr($d, 0, 7)][] = $i;
$rangeLink = function (string $rk) use ($P, $fVenue): string {
    return '/admin/reports.php?' . http_build_query(['range' => $rk, 'p' => $P['anchor']] + ($fVenue ? ['venue' => $fVenue] : []));
};
$canPrint = $T !== null;
?>
<style>
.rp{container-type:inline-size;container-name:rp;
  --rc1:#00899E;--rc2:#C98A1E;--rc3:#B5523B;--rc4:#5B6FC4;
  --rq0:#F1ECE4;--rq1:#CFE6E8;--rq2:#8CC3CA;--rq3:#3E95A2;--rq4:#0B5D6B;--rgold:#E7C98C;--rline:#efe8e1}
.rp .num{font-variant-numeric:tabular-nums}
.rp-card{background:#fff;border:1px solid var(--rline);border-radius:18px;padding:20px 22px;min-width:0}
.rp-card h3{margin:0;font-size:15px;font-weight:700}
.rp-card .sub{color:var(--muted);font-size:12.5px;margin-top:2px}
.rp-card__hd{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:8px 12px;margin-bottom:14px}
.rp-sec{display:flex;align-items:baseline;justify-content:space-between;gap:6px 12px;flex-wrap:wrap;margin:30px 2px 12px}
.rp-sec h2{margin:0;font-size:18px;letter-spacing:-.01em}
.rp-sec .sub{color:var(--muted);font-size:13px}
.rp-delta{display:inline-flex;align-items:center;font-size:11.5px;font-weight:700;border-radius:999px;padding:2px 8px;white-space:nowrap}
.rp-delta--up{color:#2e7d32;background:#e8f3e9}.rp-delta--down{color:#c0392b;background:#fbeceb}.rp-delta--flat{color:var(--muted);background:#f1ece5}
.rp-seg{display:inline-flex;background:#efe8de;border-radius:10px;padding:3px;gap:2px;max-width:100%;overflow-x:auto;scrollbar-width:none}
.rp-seg::-webkit-scrollbar{display:none}
.rp-seg a,.rp-seg button{border:0;background:transparent;border-radius:8px;padding:6px 11px;font:inherit;font-size:12.5px;font-weight:600;color:var(--muted);white-space:nowrap;text-decoration:none;cursor:pointer}
.rp-seg .on{background:#fff;color:var(--text);box-shadow:0 1px 3px rgba(0,0,0,.08)}
.rp-bar{height:8px;background:#f1ece5;border-radius:4px;overflow:hidden}
.rp-bar span{display:block;height:100%;border-radius:4px}
.rp-legend{display:flex;flex-wrap:wrap;gap:6px 14px;font-size:12px;color:var(--muted)}
.rp-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:-1px}
.rp-empty{color:var(--muted);font-size:13px;padding:6px 0}

/* Header band */
.rp-band{background:linear-gradient(140deg,#0B2129 0%,#1E5C6B 100%);color:#fff;border-radius:22px;padding:22px 26px 24px;position:relative;overflow:hidden}
.rp-band::after{content:"";position:absolute;right:-70px;top:-70px;width:280px;height:280px;border-radius:50%;border:1px solid rgba(255,255,255,.08);box-shadow:0 0 0 46px rgba(255,255,255,.03);pointer-events:none}
.rp-band > *{position:relative;z-index:1}
.rp-ctrl{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.rp-month{display:inline-flex;align-items:center;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.16);border-radius:12px;overflow:hidden}
.rp-month a{color:#fff;padding:8px 10px;display:grid;place-items:center;text-decoration:none}
.rp-month a:hover{background:rgba(255,255,255,.08)}
.rp-month a svg{stroke:#fff;color:#fff;opacity:1}
.rp-month b{padding:0 6px;font-size:15px;white-space:nowrap}
.rp-band .rp-seg{background:rgba(255,255,255,.08)}
.rp-band .rp-seg a{color:#b9cdca}.rp-band .rp-seg .on{background:#fff;color:#102F3A}
.rp-band .eselect{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.18);color:#fff}
.rp-band .eselect option{color:#141412}
.rp-acts{margin-left:auto;display:flex;gap:8px}
.rp-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.1);color:#fff;border-radius:10px;padding:8px 13px;font:inherit;font-size:13px;font-weight:600;white-space:nowrap;text-decoration:none;cursor:pointer}
.rp-btn--pri{background:#fff;color:#102F3A;border-color:#fff}
.rp-headline{font-size:31px;line-height:1.2;font-weight:700;letter-spacing:-.02em;margin:22px 0 8px;max-width:820px;color:#fff}
.rp-headline em{font-style:normal;color:var(--rgold)}
.rp-lede{color:#b9cdca;margin:0 0 20px;max-width:780px;font-size:14.5px}
.rp-kp{display:grid;grid-template-columns:1.35fr repeat(4,minmax(0,1fr));gap:1px;background:rgba(255,255,255,.12);border-radius:16px;overflow:hidden}
.rp-kp > div{background:rgba(11,33,41,.6);padding:15px 16px;min-width:0}
.rp-kp .l{font-size:10.5px;letter-spacing:.11em;text-transform:uppercase;color:#8fb7bb;font-weight:600}
.rp-kp .v{font-size:23px;font-weight:800;margin:3px 0 4px;letter-spacing:-.01em;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rp-kp .rp-total{background:rgba(231,201,140,.14)}
.rp-kp .rp-total .v{font-size:28px;color:var(--rgold)}
.rp-kp .rp-delta--up{background:rgba(159,224,192,.14);color:#9fe0c0}.rp-kp .rp-delta--down{background:rgba(255,160,150,.14);color:#ffb4a8}.rp-kp .rp-delta--flat{background:rgba(255,255,255,.08);color:#b9cdca}
.rp-kp .m{font-size:11.5px;color:#8fb7bb}
.rp-kp2{display:flex;flex-wrap:wrap;gap:8px 22px;margin-top:14px;color:#b9cdca;font-size:13px}
.rp-kp2 b{color:#fff;font-weight:700}
.rp-note{margin-top:12px;font-size:12px;color:#8fb7bb}

/* Property cards */
.rp-props{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}
.rp-pc{background:#fff;border:1px solid var(--rline);border-radius:18px;overflow:hidden;min-width:0;display:flex;flex-direction:column}
.rp-pc__ph{height:76px;position:relative;background-size:cover;background-position:center}
.rp-pc__ph::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.05),rgba(0,0,0,.5))}
.rp-pc__ph b{position:absolute;left:14px;bottom:22px;color:#fff;font-size:15.5px}
.rp-pc__ph small{position:absolute;left:14px;bottom:7px;color:rgba(255,255,255,.88);font-size:11px;letter-spacing:.08em;text-transform:uppercase}
.rp-pc__ph .share{position:absolute;right:12px;top:10px;background:rgba(255,255,255,.92);color:#102F3A;font-size:11px;font-weight:700;border-radius:999px;padding:2px 8px}
.rp-pc__bd{padding:14px 16px 16px;display:flex;flex-direction:column;gap:10px;flex:1}
.rp-pc__tot{display:flex;align-items:baseline;justify-content:space-between;gap:8px;flex-wrap:wrap}
.rp-pc__tot .v{font-size:21px;font-weight:800;white-space:nowrap}
.rp-pc__split{display:flex;height:8px;border-radius:4px;overflow:hidden;gap:2px;background:#f1ece5}
.rp-pc__lines{display:grid;gap:4px;font-size:12.5px}
.rp-pc__lines div{display:flex;justify-content:space-between;gap:8px}
.rp-pc__lines span{color:var(--muted);white-space:nowrap}
.rp-pc__lines b{white-space:nowrap}
.rp-pc__mini{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;font-size:11.5px;color:var(--muted);border-top:1px solid var(--rline);padding-top:10px}
.rp-pc__mini b{display:block;color:var(--text);font-size:14px}
.rp-spark{display:flex;align-items:flex-end;gap:1px;height:30px;margin-top:auto}
.rp-spark i{flex:1;background:var(--rq3);border-radius:2px 2px 0 0;min-height:2px}
.rp-spark i.z{background:var(--rq0)}
.rp-warn{background:#fdf1de;color:#a15c07;border-radius:10px;padding:10px 12px;font-size:12.5px;font-weight:600}
.rp-pc__more{font-size:11.5px;color:var(--muted)}

/* Occupancy grid */
.rp-occ{display:grid;gap:12px}
.rp-orow{display:grid;grid-template-columns:130px minmax(0,1fr) 54px;gap:12px;align-items:center}
.rp-orow .name{font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rp-orow .pct{text-align:right;font-weight:700;font-size:13px}
.rp-cells{display:grid;gap:2px}
.rp-cells i{height:26px;border-radius:4px;cursor:pointer}
.rp-days{display:grid;gap:2px;font-size:10px;color:var(--muted);text-align:center}
.rp-qleg{display:flex;align-items:center;gap:4px;font-size:11px;color:var(--muted)}
.rp-qleg i{width:18px;height:10px;border-radius:3px;display:inline-block}

/* Extras / channels / ahead */
.rp-two{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px}
.rp-hbars{display:grid;gap:12px}
.rp-hbars .top{display:flex;justify-content:space-between;gap:8px;font-size:13px;margin-bottom:5px}
.rp-hbars .top b{font-weight:600}
.rp-hbars .top .num{white-space:nowrap}
.rp-stack{display:flex;height:36px;border-radius:10px;overflow:hidden;gap:2px;margin:4px 0 14px}
.rp-stack div{display:flex;align-items:center;padding:0 10px;color:#fff;font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;cursor:pointer;min-width:6px}
.rp-rank > div{display:grid;grid-template-columns:22px minmax(0,1fr) auto;gap:10px;align-items:center;padding:9px 0;border-bottom:1px solid var(--rline);font-size:13.5px}
.rp-rank > div:last-child{border-bottom:0}
.rp-rank em{font-style:normal;width:22px;height:22px;border-radius:7px;background:#f1ece5;display:grid;place-items:center;font-size:11px;font-weight:700;color:var(--muted)}
.rp-rank .t{font-size:11px;color:var(--muted);display:block}
.rp-fwd{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;align-items:end;height:170px}
.rp-fwd .col{display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end;cursor:pointer;min-width:0}
.rp-fwd .bar{width:min(46px,80%);background:var(--rq3);border-radius:6px 6px 0 0;min-height:3px}
.rp-fwd .v{font-size:11.5px;font-weight:700}
.rp-fwd .m{font-size:11.5px;color:var(--muted)}

/* Table */
.rp-tbl{width:100%;border-collapse:collapse;font-size:13.5px}
.rp-tbl th{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:600;text-align:left;padding:9px 10px;border-bottom:1px solid var(--rline);white-space:nowrap}
.rp-tbl td{padding:11px 10px;border-bottom:1px solid var(--rline)}
.rp-tbl .r{text-align:right;white-space:nowrap}
.rp-tbl tfoot td{font-weight:800;border-top:2px solid var(--rline);border-bottom:0}

/* Notes */
.rp-notes{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.rp-notes ul,.rp-notes ol{margin:8px 0 0;padding-left:18px;font-size:13.5px;display:grid;gap:6px}
.rp-notes__by{font-size:12px;color:var(--muted);margin-top:10px}
.rp-notes__edit summary{cursor:pointer;list-style:none;display:inline-flex;align-items:center;gap:6px;border:1px dashed var(--border);border-radius:8px;padding:6px 12px;font-size:12.5px;color:var(--muted);margin-top:12px}
.rp-notes__edit summary::-webkit-details-marker{display:none}
.rp-notes__form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:12px}
.rp-notes__form label{font-size:12px;font-weight:600;color:var(--muted);display:grid;gap:6px}
.rp-notes__form textarea{min-height:120px;resize:vertical;font:inherit;font-size:13.5px}

/* Existing POS partial */
.rp-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:0 0 8px}
.rp-kpi{background:#fff;border:1px solid var(--rline);border-radius:14px;padding:14px 16px}
.rp-kpi .n{font-size:22px;font-weight:800;color:#102F3A;line-height:1.1}
.rp-kpi .l{font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-top:5px}
.rp-curblock{margin-bottom:22px}
.rp-curblock__cur{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin:0 0 8px}
.rp-grid{display:grid;grid-template-columns:1fr;gap:16px}
.rp-grid>.card{min-width:0}
.rp-grid .table-wrap{overflow-x:auto}

#rpTip{position:fixed;z-index:80;pointer-events:none;background:#0f2228;color:#fff;border-radius:9px;padding:8px 11px;font-size:12px;line-height:1.45;box-shadow:0 8px 24px rgba(0,0,0,.2);opacity:0;transition:opacity .1s;max-width:240px}

@container rp (max-width:1000px){
  .rp-kp{grid-template-columns:repeat(2,minmax(0,1fr))}.rp-kp .rp-total{grid-column:span 2}
  .rp-two{grid-template-columns:minmax(0,1fr)}
  .rp-notes,.rp-notes__form{grid-template-columns:minmax(0,1fr)}
  .rp-kpis{grid-template-columns:1fr 1fr}
}
@container rp (max-width:700px){
  .rp-acts{margin-left:0;width:100%}.rp-acts .rp-btn{flex:1}
  .rp-headline{font-size:24px;margin-top:16px}
  .rp-orow{grid-template-columns:minmax(0,1fr) auto;gap:4px 10px}.rp-orow .rp-cells{grid-column:1/-1;grid-row:2}
  .rp-orow .rp-cells i{height:20px}
  .rp-days{display:none}
}
@container rp (max-width:520px){
  .rp-band{padding:18px 16px;border-radius:18px}
  .rp-ctrl .rp-seg{order:3;width:100%}.rp-ctrl .rp-seg a{flex:1;text-align:center}
  .rp-month{flex:1}.rp-month b{flex:1;text-align:center}
  .rp-ctrl form{width:100%}.rp-ctrl .eselect{width:100%}
  .rp-headline{font-size:21px}.rp-lede{font-size:13.5px}
  .rp-kp .v{font-size:18px}.rp-kp .rp-total .v{font-size:25px}.rp-kp > div{padding:12px 13px}
  .rp-props{grid-template-columns:minmax(0,1fr)}
  .rp-card{padding:16px 15px;border-radius:16px}
  .rp-sec{margin-top:24px}.rp-sec h2{font-size:16.5px}
  .rp-cells{gap:1px}.rp-cells i{border-radius:2px}
  .rp-fwd{gap:6px;height:150px}
  .rp-tbl thead{display:none}
  .rp-tbl,.rp-tbl tbody,.rp-tbl tfoot,.rp-tbl tr,.rp-tbl td{display:block;width:100%}
  .rp-tbl tr{display:grid;grid-template-columns:1fr 1fr;gap:2px 12px;padding:10px 0;border-bottom:1px solid var(--rline)}
  .rp-tbl td{border:0!important;padding:2px 0;text-align:left!important}
  .rp-tbl td:first-child{grid-column:1/-1;font-weight:700;font-size:14.5px}
  .rp-tbl td[data-l]::before{content:attr(data-l);display:block;font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:600}
  .rp-kpis{grid-template-columns:1fr}
}
@media print{
  .sidebar,.sidebar-overlay,.admin-topbar,.rp-ctrl,.rp-notes__edit,.rp-pos,#rpTip{display:none!important}
  .admin-main{margin:0!important;padding:0!important}
  .rp-band,.rp-cells i,.rp-spark i,.rp-stack div,.rp-bar span,.rp-pc__ph{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .rp-card,.rp-pc,.rp-notes > div{break-inside:avoid}
}
</style>

<div class="rp">
<?php if ($flash): ?><div class="alert alert--success is-flash"><?= e($flash) ?></div><?php endif; ?>

<?php if (!bookings_supported()): ?>
  <div class="alert alert--error">The bookings ledger isn&rsquo;t set up on this database yet — run the <code>add_bookings_finance</code> migration.</div>
  <?php include __DIR__ . '/_layout_end.php'; return; ?>
<?php endif; ?>

<!-- ── 1. Header band ── -->
<section class="rp-band" data-help="rp-band">
  <div class="rp-ctrl">
    <div class="rp-month">
      <a href="/admin/reports.php?<?= e($qs(['p' => $P['prev_anchor']])) ?>" aria-label="Previous period"><?= admin_icon('chevron-left', 16) ?></a>
      <b><?= e($P['label']) ?></b>
      <a href="/admin/reports.php?<?= e($qs(['p' => $P['next_anchor']])) ?>" aria-label="Next period"><?= admin_icon('chevron-right', 16) ?></a>
    </div>
    <nav class="rp-seg" aria-label="Report period">
      <?php foreach (REPORT_RANGES as $rk => [$rl]): ?>
      <a href="<?= e($rangeLink($rk)) ?>" class="<?= $range === $rk ? 'on' : '' ?>"><?= e($rl) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php if (count($venues) > 1): ?>
    <form method="GET">
      <input type="hidden" name="range" value="<?= e($range) ?>"><input type="hidden" name="p" value="<?= e($P['anchor']) ?>">
      <select name="venue" class="eselect" onchange="this.form.submit()" aria-label="Property">
        <option value="0">All properties</option>
        <?php foreach ($venues as $v): ?>
        <option value="<?= (int)$v['id'] ?>"<?= $fVenue === (int)$v['id'] ? ' selected' : '' ?>><?= e($v['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
    <div class="rp-acts">
      <?php if ($canPrint): ?><button type="button" class="rp-btn" onclick="window.print()"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg> Print / PDF</button><?php endif; ?>
      <?php if ($stays): ?><a class="rp-btn rp-btn--pri" href="/admin/reports.php?<?= e($qs(['export' => 'csv'])) ?>"><?= admin_icon('download', 15) ?> Export CSV</a><?php endif; ?>
    </div>
  </div>

  <h1 class="rp-headline"><?= $headline ?></h1>
  <p class="rp-lede"><?= e($story['lede']) ?></p>

  <?php if ($T): ?>
  <div class="rp-kp">
    <div class="rp-total"><div class="l">Total revenue</div><div class="v num"><?= e($money($T['total'])) ?></div><?= $deltaHtml(report_delta($T['total'], $cmpVal('total'))) ?></div>
    <div><div class="l">Rooms · gross</div><div class="v num" title="<?= e($money($T['gross'])) ?>"><?= e($short($T['gross'])) ?></div><?= $deltaHtml(report_delta($T['gross'], $cmpVal('gross'))) ?></div>
    <div><div class="l">Commission</div><div class="v num" title="<?= e($money($T['commission'])) ?>"><?= e($short($T['commission'])) ?></div><span class="m"><?= $T['gross'] > 0 ? e(number_format($T['commission'] / $T['gross'] * 100, 1)) . '% of gross' : '' ?></span></div>
    <div><div class="l">Rooms · net</div><div class="v num" title="<?= e($money($T['net'])) ?>"><?= e($short($T['net'])) ?></div><?= $deltaHtml(report_delta($T['net'], $cmpVal('net'))) ?></div>
    <div><div class="l">Extras</div><div class="v num" title="<?= e($money($T['extras'])) ?>"><?= e($short($T['extras'])) ?></div><?= $deltaHtml(report_delta($T['extras'], $cmpVal('extras'))) ?></div>
  </div>
  <div class="rp-kp2">
    <span>Occupancy <b><?= e($pct($M['occupancy']['pct'])) ?></b></span>
    <span>ADR <b><?= e($T['nights'] > 0 ? $money($T['gross'] / $T['nights']) : '—') ?></b></span>
    <span>Nights <b class="num"><?= (int)$T['nights'] ?></b></span>
    <span>Stays <b class="num"><?= (int)$T['stays'] ?></b></span>
    <span>Cancellations <b class="num"><?= (int)$cancelled ?></b></span>
    <?php if ($enquiries !== null): ?><span>Enquiries <b class="num"><?= (int)$enquiries ?></b></span><?php endif; ?>
  </div>
  <p class="rp-note">Changes compare with <?= e(strtolower(REPORT_RANGES[$range][0]) === 'month' ? date('F Y', strtotime($P['cmp_from'])) : 'the period before') ?>. Revenue counts each night of a stay in the period it falls in.
    <?php if (!$commOn): ?> Commission shows 0 until the <code>add_bookings_channel_commission</code> migration runs and the eZee report is imported again.<?php endif; ?></p>
  <?php endif; ?>
</section>

<?php if ($T): ?>
<!-- ── 2. Properties ── -->
<div class="rp-sec"><h2>Properties</h2><span class="sub">Total = net room revenue + extras<?= $monthly ? '' : ' · bars = nightly occupancy' ?></span></div>
<div class="rp-props" data-help="rp-props">
  <?php $ti = 0; foreach ($M['venues'] as $v):
    $vt = $v['cur'][$pc] ?? null; $tint = $tints[$ti++ % count($tints)];
    $share = ($vt && $T['total'] > 0) ? round($vt['total'] / $T['total'] * 100) : 0;
    $ph = $v['photo'] !== '' ? "background-image:url('" . e($v['photo']) . "')" : "background:linear-gradient(135deg,{$tint}99,{$tint})"; ?>
  <div class="rp-pc">
    <div class="rp-pc__ph" style="<?= $ph ?>"><b><?= e($v['name']) ?></b><?php if ($v['town'] !== ''): ?><small><?= e($v['town']) ?></small><?php endif; ?><span class="share"><?= (int)$share ?>%</span></div>
    <div class="rp-pc__bd">
      <?php if (!$vt): ?>
        <div class="rp-pc__tot"><span class="v num"><?= e($money(0)) ?></span></div>
        <div class="rp-warn">No bookings in this period — needs sales attention</div>
      <?php else: ?>
        <div class="rp-pc__tot"><span class="v num"><?= e($money($vt['total'])) ?></span></div>
        <div class="rp-pc__split" data-tip="<?= e('<b>' . e($v['name']) . '</b><br>Rooms (net) ' . e($money($vt['net'])) . '<br>Extras ' . e($money($vt['extras']))) ?>">
          <span style="flex:<?= max(0.0001, $vt['net']) ?>;background:var(--rc1)"></span><span style="flex:<?= max(0.0001, $vt['extras']) ?>;background:var(--rc2)"></span>
        </div>
        <div class="rp-pc__lines">
          <div><span>Rooms · gross</span><b class="num"><?= e($short($vt['gross'])) ?></b></div>
          <div><span>Commission</span><b class="num"><?= $vt['commission'] > 0 ? '− ' . e($short($vt['commission'])) : '—' ?></b></div>
          <div><span>Extras</span><b class="num"><?= $vt['extras'] > 0 ? e($short($vt['extras'])) : '—' ?></b></div>
        </div>
      <?php endif; ?>
      <?php foreach ($v['cur'] as $oc => $ot): if ($oc === $pc) continue; ?>
        <div class="rp-pc__more">Also <?= e(bookings_money($ot['total'], $oc)) ?> in <?= e($oc) ?></div>
      <?php endforeach; ?>
      <div class="rp-pc__mini">
        <div><b class="num"><?= (int)$v['stays'] ?></b>stays</div>
        <div><b class="num"><?= (int)$v['nights'] ?></b>nights</div>
        <div><b class="num"><?= e($pct($v['occ'])) ?></b>occupancy</div>
      </div>
      <?php if (!$monthly && $v['daily']): ?>
      <div class="rp-spark">
        <?php foreach ($v['daily'] as $i => $o): ?><i class="<?= $o > 0 ? '' : 'z' ?>" style="height:<?= max(6, (int)$o) ?>%" data-tip="<?= e('<b>' . e($v['name']) . ' · ' . date('j M', strtotime($days[$i])) . '</b><br>' . round($o) . '% full') ?>"></i><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── 3. Occupancy ── -->
<?php $occVenues = array_filter($M['venues'], fn($v) => $v['daily']); if ($occVenues):
  $cols = $monthly ? count($monthLabels) : count($days); ?>
<div class="rp-sec"><h2>Occupancy, <?= $monthly ? 'month by month' : 'day by day' ?></h2><span class="sub">Share of each property's rooms sold each <?= $monthly ? 'month' : 'night' ?></span></div>
<div class="rp-card" data-help="rp-occupancy">
  <div class="rp-card__hd"><div><h3><?= e($P['label']) ?></h3><div class="sub">Darker = fuller · tap a square for the figure</div></div>
    <div class="rp-qleg">0%<i style="background:var(--rq0)"></i><i style="background:var(--rq1)"></i><i style="background:var(--rq2)"></i><i style="background:var(--rq3)"></i><i style="background:var(--rq4)"></i>100%</div></div>
  <div class="rp-occ">
    <?php foreach ($occVenues as $v): ?>
    <div class="rp-orow"><span class="name"><?= e($v['name']) ?></span>
      <div class="rp-cells" style="grid-template-columns:repeat(<?= (int)$cols ?>,minmax(0,1fr))">
        <?php if ($monthly): foreach ($monthLabels as $ym => $idx):
          $o = round(array_sum(array_map(fn($i) => $v['daily'][$i], $idx)) / count($idx), 1); ?>
          <i style="background:<?= $occColor($o) ?>" data-tip="<?= e('<b>' . e($v['name']) . ' · ' . date('M Y', strtotime($ym . '-01')) . '</b><br>' . $o . '% of rooms sold') ?>"></i>
        <?php endforeach; else: foreach ($v['daily'] as $i => $o): ?>
          <i style="background:<?= $occColor($o) ?>" data-tip="<?= e('<b>' . e($v['name']) . ' · ' . date('j M', strtotime($days[$i])) . '</b><br>' . round($o) . '% of rooms sold') ?>"></i>
        <?php endforeach; endif; ?>
      </div>
      <span class="pct num"><?= e($pct($v['occ'])) ?></span></div>
    <?php endforeach; ?>
    <div class="rp-orow"><span></span><div class="rp-days" style="grid-template-columns:repeat(<?= (int)$cols ?>,minmax(0,1fr))">
      <?php if ($monthly): foreach (array_keys($monthLabels) as $ym): ?><span><?= e(date('M', strtotime($ym . '-01'))) ?></span><?php endforeach;
      else: foreach ($days as $i => $d): $n = (int)date('j', strtotime($d)); ?><span><?= ($n % 5 === 1 || $i === count($days) - 1) ? $n : '' ?></span><?php endforeach; endif; ?>
    </div><span></span></div>
  </div>
</div>
<?php endif; ?>

<!-- ── 4. Extras & channels ── -->
<div class="rp-sec"><h2>Extras &amp; channels</h2><span class="sub"><?= count($M['currencies']) > 1 ? 'Charts show ' . e($pc) . ' — other currencies are in the table' : 'Where the money came from' ?></span></div>
<div class="rp-two">
  <div class="rp-card" data-help="rp-extras">
    <div class="rp-card__hd"><div><h3>Extras · <?= e($money($T['extras'])) ?></h3><div class="sub">Priced guest requests and bill charges for stays arriving in the period</div></div></div>
    <?php $xt = $M['extras_by_type'][$pc] ?? []; $xv = $M['extras_by_venue'][$pc] ?? []; ?>
    <?php if (!$xt): ?>
      <p class="rp-empty">No priced extras on these stays. Extras count once staff confirm a guest request with a price, or add a charge to the bill.</p>
    <?php else: ?>
      <div class="rp-seg" data-rp-switch style="margin-bottom:14px"><button type="button" class="on" data-rp-show="type">By type</button><button type="button" data-rp-show="venue">By property</button></div>
      <div class="rp-hbars" data-rp-pane="type">
        <?php $mx = max($xt); foreach ($xt as $label => $amt): ?>
        <div data-tip="<?= e('<b>' . e($label) . '</b><br>' . e($money($amt))) ?>"><div class="top"><b><?= e($label) ?></b><span class="num"><?= e($money($amt)) ?></span></div><div class="rp-bar"><span style="width:<?= round($amt / $mx * 100, 1) ?>%;background:var(--rc2)"></span></div></div>
        <?php endforeach; ?>
      </div>
      <div class="rp-hbars" data-rp-pane="venue" hidden>
        <?php $mx = max($xv); foreach ($M['venues'] as $vid => $v): $amt = (float)($xv[$vid] ?? 0); if ($amt <= 0) continue; ?>
        <div data-tip="<?= e('<b>' . e($v['name']) . '</b><br>' . e($money($amt))) ?>"><div class="top"><b><?= e($v['name']) ?></b><span class="num"><?= e($money($amt)) ?></span></div><div class="rp-bar"><span style="width:<?= round($amt / $mx * 100, 1) ?>%;background:var(--rc2)"></span></div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="rp-card" data-help="rp-channels">
    <div class="rp-card__hd"><div><h3>Booking channels</h3><div class="sub">Share of gross room revenue</div></div></div>
    <?php $ch = $M['channels'][$pc] ?? []; $chSum = array_sum($ch); ?>
    <?php if ($chSum > 0): ?>
      <div class="rp-stack">
        <?php foreach ($ch as $k => $amt): $sh = $amt / $chSum; ?>
        <div style="flex:<?= $amt ?>;background:<?= $chanColor[$k] ?>" data-tip="<?= e('<b>' . REPORT_CHANNELS[$k] . '</b><br>' . e($money($amt)) . ' · ' . round($sh * 100) . '%') ?>"><?= $sh > .18 ? round($sh * 100) . '%' : '' ?></div>
        <?php endforeach; ?>
      </div>
      <div class="rp-legend" style="margin-bottom:16px">
        <?php foreach ($ch as $k => $amt): ?><span><i style="background:<?= $chanColor[$k] ?>"></i><?= e(REPORT_CHANNELS[$k]) ?> <b class="num" style="color:var(--text)"><?= round($amt / $chSum * 100) ?>%</b></span><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <h3 style="font-size:14px">Top partners</h3>
    <?php $pt = $M['partners'][$pc] ?? []; if (!$pt): ?>
      <p class="rp-empty">No agent or OTA bookings in this period<?= $commOn ? '' : ' — or they were imported before channels were recorded' ?>.</p>
    <?php else: ?>
    <div class="rp-rank">
      <?php foreach ($pt as $i => $p): ?>
      <div><em><?= $i + 1 ?></em><span><b style="font-weight:600"><?= e($p['name']) ?></b><span class="t"><?= e($p['kind']) ?> · <?= (int)$p['stays'] ?> <?= $p['stays'] === 1 ? 'stay' : 'stays' ?></span></span><b class="num"><?= e($short($p['amount'])) ?></b></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($forward && max(array_column($forward, 'nights')) > 0): $fmx = max(array_column($forward, 'nights')); ?>
<!-- ── 5. Looking ahead ── -->
<div class="rp-sec"><h2>Looking ahead</h2><span class="sub">Nights already booked for the coming months</span></div>
<div class="rp-card" data-help="rp-ahead">
  <div class="rp-fwd">
    <?php foreach ($forward as $f): ?>
    <div class="col" data-tip="<?= e('<b>' . date('F Y', strtotime($f['ym'] . '-01')) . '</b><br>' . (int)$f['nights'] . ' nights booked<br>' . e($money($f['gross']))) ?>">
      <span class="v num"><?= (int)$f['nights'] ?></span>
      <div class="bar" style="height:<?= round($f['nights'] / $fmx * 74, 1) ?>%"></div>
      <span class="m"><?= e(date('M', strtotime($f['ym'] . '-01'))) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── 6. Portfolio table ── -->
<div class="rp-sec"><h2>Portfolio table</h2><span class="sub">The same figures as a table</span></div>
<div class="rp-card" style="padding-top:8px;padding-bottom:8px" data-help="rp-table">
  <table class="rp-tbl">
    <thead><tr><th>Property</th><th class="r">Stays</th><th class="r">Nights</th><th class="r">Gross</th><th class="r">Commission</th><th class="r">Net</th><th class="r">Extras</th><th class="r">Total</th></tr></thead>
    <tbody>
      <?php foreach ($M['venues'] as $v): $cs = $v['cur'] ?: [$pc => null]; foreach ($cs as $c => $t): ?>
      <tr>
        <td><?= e($v['name']) ?><?= count($cs) > 1 ? ' · ' . e($c) : '' ?></td>
        <td class="r num" data-l="Stays"><?= (int)$v['stays'] ?></td>
        <td class="r num" data-l="Nights"><?= (int)$v['nights'] ?></td>
        <td class="r num" data-l="Gross"><?= e(bookings_money($t['gross'] ?? 0, $c)) ?></td>
        <td class="r num" data-l="Commission"><?= ($t['commission'] ?? 0) > 0 ? e(bookings_money($t['commission'], $c)) : '—' ?></td>
        <td class="r num" data-l="Net"><?= e(bookings_money($t['net'] ?? 0, $c)) ?></td>
        <td class="r num" data-l="Extras"><?= ($t['extras'] ?? 0) > 0 ? e(bookings_money($t['extras'], $c)) : '—' ?></td>
        <td class="r num" data-l="Total"><?= e(bookings_money($t['total'] ?? 0, $c)) ?></td>
      </tr>
      <?php endforeach; endforeach; ?>
    </tbody>
    <tfoot>
      <?php foreach ($M['currencies'] as $c => $t): ?>
      <tr>
        <td>Total<?= count($M['currencies']) > 1 ? ' · ' . e($c) : '' ?></td>
        <td class="r num" data-l="Stays"><?= (int)$t['stays'] ?></td>
        <td class="r num" data-l="Nights"><?= (int)$t['nights'] ?></td>
        <td class="r num" data-l="Gross"><?= e(bookings_money($t['gross'], $c)) ?></td>
        <td class="r num" data-l="Commission"><?= e(bookings_money($t['commission'], $c)) ?></td>
        <td class="r num" data-l="Net"><?= e(bookings_money($t['net'], $c)) ?></td>
        <td class="r num" data-l="Extras"><?= e(bookings_money($t['extras'], $c)) ?></td>
        <td class="r num" data-l="Total"><?= e(bookings_money($t['total'], $c)) ?></td>
      </tr>
      <?php endforeach; ?>
    </tfoot>
  </table>
</div>
<?php endif; ?>

<!-- ── 7. Notes from reservations ── -->
<?php $hasNotes = $notes['highlights'] || $notes['watch'] || $notes['next']; if ($hasNotes || $canNotes): ?>
<div class="rp-sec" data-help="rp-notes"><h2>Notes from reservations</h2><span class="sub">Written by the team for this period — they print with the report</span></div>
<?php if ($hasNotes): ?>
<div class="rp-notes">
  <?php foreach (['highlights' => ['Highlights', 'ul'], 'watch' => ['Watch', 'ul'], 'next' => ['Next steps', 'ol']] as $nk => [$nl, $tag]): ?>
  <div class="rp-card"><h3><?= e($nl) ?></h3>
    <?php if ($notes[$nk]): ?><<?= $tag ?>><?php foreach ($notes[$nk] as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></<?= $tag ?>>
    <?php else: ?><p class="rp-empty">—</p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php if ($notes['by'] !== ''): ?><div class="rp-notes__by">By <?= e($notes['by']) ?> · <?= e(date('j M Y', strtotime($notes['at']))) ?></div><?php endif; ?>
<?php endif; ?>
<?php if ($canNotes): ?>
<details class="rp-notes__edit"<?= $hasNotes ? '' : ' open' ?>>
  <summary><?= admin_icon('edit', 14) ?> <?= $hasNotes ? 'Edit notes' : 'Write notes for this period' ?></summary>
  <form method="POST" class="rp-card" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_notes">
    <div class="rp-notes__form">
      <label>Highlights<textarea class="inp" name="highlights" placeholder="One point per line"><?= e(implode("\n", $notes['highlights'])) ?></textarea></label>
      <label>Watch<textarea class="inp" name="watch" placeholder="What needs attention"><?= e(implode("\n", $notes['watch'])) ?></textarea></label>
      <label>Next steps<textarea class="inp" name="next" placeholder="The plan for next month"><?= e(implode("\n", $notes['next'])) ?></textarea></label>
    </div>
    <div style="margin-top:12px"><button type="submit" class="btn-sm btn-primary">Save notes</button></div>
  </form>
</details>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/_pos_report.php'; ?>
</div>
<div id="rpTip"></div>

<script>
/* Reports: tap/hover tooltips + the extras switch. Bound once per window (admin
   shell re-runs inline scripts on every swap), looking elements up at event time. */
(function () {
  if (window.__rpBound) return; window.__rpBound = true;
  function tip() { return document.getElementById('rpTip'); }
  function show(e, html) {
    var t = tip(); if (!t) return;
    t.innerHTML = html; t.style.opacity = 1;
    var r = t.getBoundingClientRect(), x = e.clientX + 14, y = e.clientY - r.height - 12;
    if (x + r.width > innerWidth - 8) x = Math.max(8, e.clientX - r.width - 14);
    if (y < 8) y = e.clientY + 18;
    t.style.left = x + 'px'; t.style.top = y + 'px';
  }
  function hide() { var t = tip(); if (t) t.style.opacity = 0; }
  function target(e) { return e.target.closest && e.target.closest('.rp [data-tip]'); }
  document.addEventListener('mousemove', function (e) { var el = target(e); el ? show(e, el.getAttribute('data-tip')) : hide(); });
  document.addEventListener('click', function (e) {
    var sw = e.target.closest && e.target.closest('[data-rp-show]');
    if (sw) {
      var card = sw.closest('.rp-card');
      card.querySelectorAll('[data-rp-show]').forEach(function (b) { b.classList.toggle('on', b === sw); });
      card.querySelectorAll('[data-rp-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-rp-pane') !== sw.getAttribute('data-rp-show'); });
      return;
    }
    var el = target(e); el ? show(e, el.getAttribute('data-tip')) : hide();
  });
  addEventListener('scroll', hide, { passive: true });
})();
</script>

<?php include __DIR__ . '/_layout_end.php'; ?>
