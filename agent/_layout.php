<?php
declare(strict_types=1);
/**
 * Shared chrome for the travel-agent portal. It now rides on the SAME design
 * system as /admin: admin.css (Inter font, tokens, the card/inp/eselect/button/
 * data-table/badge components), the styled-select enhancer (admin-select.js) and
 * the datepicker. No native selects/inputs/buttons — every control is a house
 * component. The portal keeps its own slim top bar (it has no admin sidebar).
 * The including page sets $agentPageTitle and (optionally) $agentActive.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agent.php';
require_once __DIR__ . '/../includes/icons.php';   // admin_icon()
$__agent = agent_current();
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
<script defer src="/js/datepicker.js?v=<?= @filemtime(__DIR__ . '/../js/datepicker.js') ?: '1' ?>"></script>
<style>
  /* Portal chrome only — everything else is an admin component. */
  .tp-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;justify-content:space-between;
    padding:12px 24px;background:var(--white);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
  .tp-brand{display:flex;align-items:center;gap:8px;font-weight:700;font-size:16px;color:var(--text);letter-spacing:.01em}
  .tp-nav{display:flex;gap:4px;flex-wrap:wrap}
  .tp-nav a{padding:7px 12px;border-radius:8px;color:var(--muted);text-decoration:none;font-size:14px;font-weight:600;transition:background .15s,color .15s}
  .tp-nav a:hover{background:var(--bg)}
  .tp-nav a.is-active{background:var(--brand);color:#fff}
  .tp-user{font-size:13px;color:var(--muted)}
  .tp-user a{color:var(--brand);text-decoration:none;font-weight:600}
  .tp-main{max-width:1080px;margin:0 auto;padding:24px}
  .tp-foot{max-width:1080px;margin:0 auto;padding:8px 24px 40px;color:var(--muted);font-size:12.5px}
  .tp-main h1{font-size:20px;font-weight:600;margin:.1rem 0 .35rem}
  .tp-main h2{font-size:16px;font-weight:600;margin:0 0 4px}
  .tp-sub{color:var(--muted);font-size:14px;margin:0 0 18px;line-height:1.55}
  .tp-cardsub{color:var(--muted);font-size:13px;margin:0 0 14px}
  /* Search/filters row → responsive grid of house fields. */
  .tp-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;align-items:end}
  .tp-form .field{margin:0}
  .tp-form .dp-btn{width:100%}
  /* Availability option rows — a house-styled list (tokens, not bespoke colours). */
  .tp-opts{display:grid;gap:10px}
  .tp-opt{display:flex;justify-content:space-between;gap:14px;align-items:center;
    border:1px solid var(--border);border-radius:var(--radius);padding:12px 14px;flex-wrap:wrap;background:var(--white)}
  .tp-opt--entire{border-color:var(--accent);background:#fdfaf3}
  .tp-opt__body{display:flex;gap:12px;align-items:center;min-width:0}
  .tp-opt__thumb{flex:0 0 auto;width:74px;height:56px;border-radius:8px;object-fit:cover;background:var(--bg);border:1px solid var(--border)}
  .tp-opt__thumb--ph{display:flex;align-items:center;justify-content:center;color:#c9beac;font-size:1.1rem}
  .tp-opt__name{font-weight:600;color:var(--text)}
  .tp-opt__meta{color:var(--muted);font-size:12.5px;margin-top:2px}
  .tp-opt__price{text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:6px}
  .tp-opt__price b{color:var(--brand);font-size:17px;line-height:1}
  .tp-was{color:var(--muted);text-decoration:line-through;font-size:12.5px}
  .tp-sec{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--accent);font-weight:700;margin:16px 0 8px}
  .tp-chip{display:inline-flex;align-items:center;gap:6px;background:var(--bg);border:1px solid var(--border);
    border-radius:999px;padding:3px 10px 3px 4px;font-size:12.5px;margin:4px 4px 0 0;color:var(--text)}
  .tp-chip__thumb{width:26px;height:26px;border-radius:50%;object-fit:cover;background:#fff;flex:0 0 auto}
  .tp-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px 20px;margin:0 0 16px}
  .tp-summary small{display:block;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px}
  .tp-summary span{font-weight:600;color:var(--text)}
  .tp-net{color:var(--brand);font-weight:700}
  .tp-note{color:var(--muted);font-size:13px}
  /* Login */
  .tp-login{max-width:400px;margin:8vh auto 0;padding:0 20px}
  /* Conversation thread */
  .tp-thread{display:flex;flex-direction:column;gap:10px;margin:0 0 16px;max-height:440px;overflow-y:auto}
  .tp-bubble{max-width:82%;border:1px solid var(--border);border-radius:12px;padding:10px 12px}
  .tp-bubble--them{background:#eef5f7;border-color:#cfe0e6;align-self:flex-start;border-bottom-left-radius:3px}
  .tp-bubble--me{background:var(--bg);align-self:flex-end;border-bottom-right-radius:3px}
  .tp-bubble__head{font-size:11.5px;color:var(--muted);margin-bottom:4px}
  .tp-bubble__body{font-size:14px;line-height:1.5;color:var(--text);white-space:pre-wrap;word-wrap:break-word}
  @media (max-width:560px){
    .tp-head{padding:10px 16px}
    .tp-main{padding:18px 16px}
    .tp-opt{flex-direction:column;align-items:stretch}
    .tp-opt__price{text-align:left;align-items:stretch}
    .tp-bubble{max-width:100%}
  }
</style>
</head>
<body>
<header class="tp-head">
  <div class="tp-brand">Tribal Sand <span class="badge badge--teal">Trade</span></div>
  <?php if ($__agent): ?>
  <nav class="tp-nav" aria-label="Trade portal">
    <a href="/agent/availability.php" class="<?= ($agentActive ?? '') === 'availability' ? 'is-active' : '' ?>">Availability</a>
    <a href="/agent/rates.php" class="<?= ($agentActive ?? '') === 'rates' ? 'is-active' : '' ?>">Rates</a>
    <a href="/agent/requests.php" class="<?= ($agentActive ?? '') === 'requests' ? 'is-active' : '' ?>">Your requests</a>
  </nav>
  <div class="tp-user"><?= e($__agent['name']) ?><?= trim((string)$__agent['agency']) !== '' ? ' · ' . e($__agent['agency']) : '' ?> · <a href="/agent/logout.php">Sign out</a></div>
  <?php endif; ?>
</header>
<main class="tp-main">
