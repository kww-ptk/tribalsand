<?php
declare(strict_types=1);
/**
 * Shared chrome for the travel-agent portal — design A "Clean light" (owner's
 * pick, Oct 2026; mockups docs/design/agent-portal-designs.html): a white top bar
 * (brand + TRADE tag, icon tabs, the agent's name and initials), one rounded
 * search bar, photo cards for results, card tables for rates and requests.
 * It still rides on the admin design system (admin.css tokens + components,
 * the styled select, the datepicker, the password field) — no native controls.
 * The including page sets $agentPageTitle, optionally $agentActive, and
 * $agentBare = true for a full-bleed page with no top bar / footer (sign in).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agent.php';
require_once __DIR__ . '/../includes/icons.php';   // admin_icon()
$__agent = agent_current();
$__bare  = !empty($agentBare);
/** Two-letter initials for the avatar ("AlysaTest Travel" → "AT"). */
$__initials = '';
if ($__agent) {
    $src = trim((string)($__agent['agency'] ?? '')) !== '' ? (string)$__agent['agency'] : (string)$__agent['name'];
    foreach (preg_split('/\s+/u', trim($src)) ?: [] as $w) {
        if ($w !== '' && mb_strlen($__initials) < 2) $__initials .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    if ($__initials === '') $__initials = 'TS';
}
$__tabs = [
    'availability' => ['Availability', '/agent/availability.php', '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>'],
    'rates'        => ['Rates', '/agent/rates.php', '<path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'],
    'requests'     => ['Your requests', '/agent/requests.php', '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($agentPageTitle ?? 'Trade Portal') ?> — Tribal Sand</title>
<link rel="stylesheet" href="/admin/assets/admin.css?v=<?= @filemtime(__DIR__ . '/../admin/assets/admin.css') ?: '1' ?>">
<link rel="stylesheet" href="/css/datepicker.css?v=<?= @filemtime(__DIR__ . '/../css/datepicker.css') ?: '1' ?>">
<script defer src="/admin/assets/admin-select.js?v=<?= @filemtime(__DIR__ . '/../admin/assets/admin-select.js') ?: '1' ?>"></script>
<script defer src="/admin/assets/admin-tip.js?v=<?= @filemtime(__DIR__ . '/../admin/assets/admin-tip.js') ?: '1' ?>"></script>
<script defer src="/admin/assets/admin-password.js?v=<?= @filemtime(__DIR__ . '/../admin/assets/admin-password.js') ?: '1' ?>"></script>
<script defer src="/js/datepicker.js?v=<?= @filemtime(__DIR__ . '/../js/datepicker.js') ?: '1' ?>"></script>
<style>
  /* ── Trade portal · design A "Clean light" ── */
  :root{--tp-ink:#14211F;--tp-mid:#5D6B6A;--tp-muted:#8A9593;--tp-line:#E6E9E8;--tp-teal:#1E5C6B;--tp-deep:#102F3A;--tp-soft:#eef4f3;
        --tp-ok:#15803d;--tp-ok-bg:#e8f5ec;--tp-warn:#b45309;--tp-warn-bg:#fdf3e4;--tp-info:#1d4ed8;--tp-info-bg:#e8eefc;--tp-off:#7c8786;--tp-off-bg:#f0f2f2}
  body{background:#fff;color:var(--tp-ink)}
  svg.tp-i{width:17px;height:17px;flex:none;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

  /* Top bar */
  .tp-head{display:flex;align-items:center;gap:24px;padding:14px 32px;background:#fff;border-bottom:1px solid var(--tp-line);position:sticky;top:0;z-index:50}
  .tp-brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:17px;letter-spacing:-.01em;color:var(--tp-ink);text-decoration:none}
  .tp-brand em{font-style:normal;font-size:10px;font-weight:700;letter-spacing:.14em;color:var(--tp-teal);background:#e3f0ef;border-radius:6px;padding:3px 7px}
  .tp-nav{display:flex;gap:4px;margin-left:8px}
  .tp-nav a{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:9px;color:var(--tp-mid);text-decoration:none;font-size:14px;font-weight:500;transition:background .15s,color .15s}
  .tp-nav a:hover{background:#f5f7f6;color:var(--tp-ink)}
  .tp-nav a.is-active{background:var(--tp-soft);color:var(--tp-deep);font-weight:600}
  .tp-user{margin-left:auto;display:flex;align-items:center;gap:12px;font-size:14px;color:var(--tp-mid)}
  .tp-user__name{text-align:right;line-height:1.25}
  .tp-user__name b{display:block;color:var(--tp-ink);font-weight:600}
  .tp-user__name a{color:var(--tp-teal);font-size:12.5px;font-weight:600;text-decoration:none}
  .tp-av{width:36px;height:36px;border-radius:50%;background:var(--tp-teal);color:#fff;display:grid;place-items:center;font-weight:600;font-size:13px;flex:none}

  .tp-main{max-width:1180px;margin:0 auto;padding:30px 32px 24px}
  .tp-foot{max-width:1180px;margin:0 auto;padding:10px 32px 40px;color:var(--tp-muted);font-size:12.5px}
  .tp-main h1{font-size:26px;font-weight:700;letter-spacing:-.02em;margin:0 0 4px}
  .tp-main h2{font-size:16px;font-weight:600;margin:0 0 4px}
  .tp-sub{color:var(--tp-mid);font-size:14.5px;margin:0 0 22px;line-height:1.55}
  .tp-sub a{color:var(--tp-teal);font-weight:600}
  .tp-cardsub{color:var(--tp-mid);font-size:13px;margin:0 0 14px}
  .tp-note{color:var(--tp-mid);font-size:13px}
  .tp-net{color:var(--tp-deep);font-weight:700}
  .tp-was{color:var(--tp-muted);text-decoration:line-through;font-size:12.5px}
  .tp-main .card{border-radius:16px;border-color:var(--tp-line);box-shadow:none}

  /* One rounded search bar */
  .tp-search{display:grid;grid-template-columns:1.1fr 1.1fr .7fr .7fr 1.2fr auto;border:1px solid var(--tp-line);border-radius:16px;box-shadow:0 8px 30px rgba(16,47,58,.07);background:#fff}
  .tp-search .field{margin:0;padding:10px 16px;border-right:1px solid var(--tp-line);min-width:0}
  .tp-search .field:nth-last-child(2){border-right:0}
  .tp-search label{display:block;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--tp-muted);margin-bottom:2px}
  .tp-search .dp-btn,.tp-search .inp{width:100%;border:0;padding:4px 0;background:transparent;box-shadow:none;font-size:15px;font-weight:500;color:var(--tp-ink);min-height:0}
  .tp-search .dp-btn:focus-visible,.tp-search .inp:focus{outline:2px solid var(--tp-teal);outline-offset:2px;border-radius:4px}
  .tp-search .eselect,.tp-search .eselect__btn{border:0!important;box-shadow:none!important;background:transparent!important;padding-left:0!important;font-size:15px;font-weight:500}
  .tp-search__go{margin:8px;border:0;border-radius:11px;background:var(--tp-deep);color:#fff;padding:0 22px;font-weight:600;font-size:14.5px;display:inline-flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;min-height:48px}
  .tp-search__go:hover{background:var(--tp-teal)}
  /* Stepper-free number fields: no browser spinners */
  .tp-search input[type=number]{-moz-appearance:textfield;appearance:textfield}
  .tp-search input[type=number]::-webkit-outer-spin-button,.tp-search input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}

  /* Filter chips + result meta */
  .tp-chips{display:flex;gap:8px;margin:20px 0 4px;flex-wrap:wrap}
  .tp-fchip{border:1px solid var(--tp-line);border-radius:999px;padding:6px 14px;background:#fff;color:var(--tp-mid);font-weight:500;font-size:13.5px;cursor:pointer;font-family:inherit}
  .tp-fchip:hover{border-color:#c9d3d1;color:var(--tp-ink)}
  .tp-fchip.is-on{background:var(--tp-deep);color:#fff;border-color:var(--tp-deep)}
  .tp-meta{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;color:var(--tp-mid);margin:18px 0 14px;font-size:14px}
  .tp-meta b{color:var(--tp-ink)}

  /* Result cards */
  .tp-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
  .tp-card{border:1px solid var(--tp-line);border-radius:16px;overflow:hidden;background:#fff;display:flex;flex-direction:column;transition:box-shadow .15s,transform .15s}
  .tp-card:hover{box-shadow:0 12px 30px rgba(16,47,58,.09);transform:translateY(-1px)}
  .tp-card__img{height:170px;background:linear-gradient(135deg,#bcd7cf,#1E5C6B);position:relative}
  .tp-card__img img{width:100%;height:100%;object-fit:cover;display:block}
  .tp-card__where{position:absolute;left:10px;bottom:10px;background:rgba(16,47,58,.72);color:#fff;font-size:11px;font-weight:600;letter-spacing:.06em;border-radius:999px;padding:3px 10px}
  .tp-card__b{padding:14px 16px 16px;display:flex;flex-direction:column;gap:5px;flex:1}
  .tp-tag{font-size:11.5px;font-weight:600;border-radius:6px;padding:2px 8px;align-self:flex-start;color:var(--tp-ok);background:var(--tp-ok-bg)}
  .tp-tag--combo{color:var(--tp-info);background:var(--tp-info-bg)}
  .tp-tag--whole{color:#8a6a2f;background:#f8f0e1}
  .tp-card__t{font-weight:600;font-size:15.5px;line-height:1.3}
  .tp-card__l{color:var(--tp-muted);font-size:13px}
  .tp-card__rooms{display:flex;flex-wrap:wrap;gap:5px;margin-top:2px}
  .tp-card__rooms span{font-size:12px;border:1px solid var(--tp-line);border-radius:7px;padding:2px 7px;color:var(--tp-mid)}
  .tp-card__row{display:flex;justify-content:space-between;align-items:flex-end;gap:10px;margin-top:auto;padding-top:12px}
  .tp-price{font-size:18px;font-weight:700;color:var(--tp-deep);line-height:1.15}
  .tp-price small{font-size:12px;font-weight:500;color:var(--tp-muted)}
  .tp-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:10px;background:var(--tp-teal);color:#fff;padding:9px 16px;font-weight:600;font-size:14px;text-decoration:none;white-space:nowrap;cursor:pointer}
  .tp-btn:hover{background:var(--tp-deep);color:#fff}
  .tp-btn--ghost{background:var(--tp-soft);color:var(--tp-deep)}
  .tp-btn--ghost:hover{background:#dfecea;color:var(--tp-deep)}
  .tp-empty{border:1px dashed #d5dcda;border-radius:16px;padding:26px;text-align:center;color:var(--tp-mid)}
  .tp-empty b{display:block;color:var(--tp-ink);font-size:16px;margin-bottom:4px}
  .tp-nofit{margin-top:18px;color:var(--tp-muted);font-size:13px}
  .tp-card[hidden],.tp-empty[hidden]{display:none}

  /* Card tables (rates, requests) */
  .tp-block{border:1px solid var(--tp-line);border-radius:16px;overflow:hidden;margin-bottom:16px;background:#fff}
  .tp-block__h{display:flex;align-items:center;gap:14px;padding:14px 18px;flex-wrap:wrap}
  .tp-block__img{width:56px;height:56px;border-radius:12px;flex:none;object-fit:cover;background:linear-gradient(135deg,#e8d3ad,#b8965a)}
  .tp-block__t{font-weight:600;font-size:16px}
  .tp-block__l{color:var(--tp-muted);font-size:13px}
  .tp-disc{margin-left:auto;font-weight:600;color:var(--tp-ok);background:var(--tp-ok-bg);border-radius:8px;padding:5px 10px;font-size:13px}
  .tp-disc--none{color:var(--tp-mid);background:#f3f5f4}
  .tp-table{width:100%;border-collapse:collapse;font-size:14px}
  .tp-table th{text-align:left;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--tp-muted);font-weight:600;padding:10px 18px;background:#fafbfb;border-top:1px solid var(--tp-line)}
  .tp-table td{padding:13px 18px;border-top:1px solid var(--tp-line);vertical-align:top}
  .tp-table .r{text-align:right}
  .tp-table tr:hover td{background:#fcfdfd}
  .tp-wrap{overflow-x:auto}
  .tp-wholetag{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#8a6a2f;background:#f8f0e1;border-radius:5px;padding:2px 6px;margin-left:6px;white-space:nowrap}

  /* Stats + status pills */
  .tp-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px}
  .tp-stat{border:1px solid var(--tp-line);border-radius:14px;padding:14px 16px}
  .tp-stat b{display:block;font-size:24px;letter-spacing:-.02em;line-height:1.2}
  .tp-stat span{color:var(--tp-muted);font-size:13px}
  .tp-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600;white-space:nowrap}
  .tp-pill::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
  .tp-pill--sent{color:var(--tp-info);background:var(--tp-info-bg)}
  .tp-pill--pending{color:var(--tp-warn);background:var(--tp-warn-bg)}
  .tp-pill--confirmed{color:var(--tp-ok);background:var(--tp-ok-bg)}
  .tp-pill--expired,.tp-pill--cancelled{color:var(--tp-off);background:var(--tp-off-bg)}
  .tp-links a{color:var(--tp-teal);font-weight:600;text-decoration:none}
  .tp-links a:hover{text-decoration:underline}

  /* Kept for the request + conversation pages */
  .tp-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;align-items:end}
  .tp-form .field{margin:0}
  .tp-form .dp-btn{width:100%}
  .tp-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px 20px;margin:0 0 16px}
  .tp-summary small{display:block;color:var(--tp-muted);font-size:11px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px}
  .tp-summary span{font-weight:600;color:var(--tp-ink)}
  .tp-thread{display:flex;flex-direction:column;gap:10px;margin:0 0 16px;max-height:440px;overflow-y:auto}
  .tp-bubble{max-width:82%;border:1px solid var(--tp-line);border-radius:12px;padding:10px 12px}
  .tp-bubble--them{background:#eef5f7;border-color:#cfe0e6;align-self:flex-start;border-bottom-left-radius:3px}
  .tp-bubble--me{background:#f5f7f6;align-self:flex-end;border-bottom-right-radius:3px}
  .tp-bubble__head{font-size:11.5px;color:var(--tp-muted);margin-bottom:4px}
  .tp-bubble__body{font-size:14px;line-height:1.5;color:var(--tp-ink);white-space:pre-wrap;word-wrap:break-word}

  /* Sign in (split screen) */
  .tp-login{display:grid;grid-template-columns:1fr 1fr;min-height:100vh}
  .tp-login__art{background:linear-gradient(160deg,#1E5C6B,#0B2129);color:#fff;padding:48px 56px;display:flex;flex-direction:column;justify-content:space-between;gap:40px}
  .tp-login__art h2{font-size:36px;letter-spacing:-.02em;line-height:1.15;margin:0;font-weight:700;max-width:460px}
  .tp-login__art ul{list-style:none;padding:0;margin:26px 0 0;display:grid;gap:12px;color:#cfe3e0;font-size:15px}
  .tp-login__art li{display:flex;gap:10px;align-items:center}
  .tp-login__art .tp-brand{color:#fff}.tp-login__art .tp-brand em{background:rgba(255,255,255,.15);color:#fff}
  .tp-login__fine{font-size:12.5px;color:#9fbcb8}
  .tp-login__form{display:flex;align-items:center;justify-content:center;padding:40px 24px}
  .tp-login__box{width:100%;max-width:380px}
  .tp-login__box h1{font-size:28px;font-weight:700;letter-spacing:-.02em;margin:0 0 4px}
  .tp-login__box .field label{font-weight:600}
  .tp-login__box .inp{width:100%;padding:12px 14px;border-radius:10px;font-size:15px}
  .tp-login__box .tp-btn{width:100%;padding:13px;font-size:15px;margin-top:4px}
  .tp-login__help{text-align:center;color:var(--tp-mid);font-size:13px;margin-top:18px}
  .tp-login__help a{color:var(--tp-teal);font-weight:600}

  @media (max-width:1000px){
    .tp-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .tp-search{grid-template-columns:1fr 1fr 1fr}
    .tp-search .field{border-bottom:1px solid var(--tp-line)}
    .tp-search .field:nth-child(3){border-right:0}
    .tp-search__go{grid-column:1/-1}
    .tp-user__name{display:none}
  }
  @media (max-width:720px){
    .tp-head{padding:10px 16px;gap:10px;flex-wrap:wrap}
    .tp-nav{order:3;width:100%;margin:0;overflow-x:auto}
    .tp-nav a{padding:7px 10px;font-size:13.5px}
    .tp-main{padding:20px 16px}
    .tp-foot{padding:8px 16px 32px}
    .tp-main h1{font-size:22px}
    .tp-grid{grid-template-columns:1fr}
    .tp-search{grid-template-columns:1fr 1fr}
    .tp-search .field{border-right:0}
    .tp-search .field:nth-child(odd){border-right:1px solid var(--tp-line)}
    .tp-search .field:nth-child(5){grid-column:1/-1;border-right:0}
    .tp-stats{grid-template-columns:1fr 1fr}
    .tp-login{grid-template-columns:1fr}
    .tp-login__art{padding:28px 20px;gap:20px}
    .tp-login__art h2{font-size:24px}
    .tp-login__art ul,.tp-login__fine{display:none}
    .tp-bubble{max-width:100%}
  }
</style>
</head>
<body>
<?php if (!$__bare): ?>
<header class="tp-head">
  <a class="tp-brand" href="<?= $__agent ? '/agent/availability.php' : '/agent/login.php' ?>">Tribal Sand <em>TRADE</em></a>
  <?php if ($__agent): ?>
  <nav class="tp-nav" aria-label="Trade portal">
    <?php foreach ($__tabs as $key => [$label, $href, $path]): $on = ($agentActive ?? '') === $key; ?>
    <a href="<?= $href ?>" class="<?= $on ? 'is-active' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><svg class="tp-i" viewBox="0 0 24 24" aria-hidden="true"><?= $path ?></svg><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="tp-user">
    <div class="tp-user__name"><b><?= e($__agent['name']) ?></b><?php if (trim((string)$__agent['agency']) !== ''): ?><?= e($__agent['agency']) ?> · <?php endif; ?><a href="/agent/logout.php">Sign out</a></div>
    <div class="tp-av" aria-hidden="true"><?= e($__initials) ?></div>
  </div>
  <?php endif; ?>
</header>
<main class="tp-main">
<?php endif; ?>
