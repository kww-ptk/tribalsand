<?php
declare(strict_types=1);
/**
 * Minimal shared chrome for the travel-agent portal. Deliberately standalone —
 * agents never load the admin CSS/JS. The including page sets $agentPageTitle
 * and (optionally) $agentActive before including this.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/agent.php';
$__agent = agent_current();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($agentPageTitle ?? 'Trade Portal') ?> — Tribal Sand</title>
<link rel="stylesheet" href="/css/datepicker.css?v=<?= @filemtime(__DIR__ . '/../css/datepicker.css') ?: '1' ?>">
<script defer src="/js/datepicker.js?v=<?= @filemtime(__DIR__ . '/../js/datepicker.js') ?: '1' ?>"></script>
<style>
  :root{--ink:#2b2b2b;--mut:#6b6050;--line:#e5e0d6;--sand:#faf6ee;--teal:#168e86;--teal-d:#0f6f68}
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--ink);background:var(--sand);line-height:1.5}
  a{color:var(--teal-d)}
  .ap-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 20px;background:#fff;border-bottom:1px solid var(--line)}
  .ap-brand{font-weight:800;letter-spacing:.01em}
  .ap-brand span{display:inline-block;margin-left:6px;padding:2px 8px;border-radius:999px;background:var(--teal);color:#fff;font-size:.7rem;font-weight:700;vertical-align:middle;text-transform:uppercase;letter-spacing:.04em}
  .ap-user{font-size:.85rem;color:var(--mut)}
  .ap-main{max-width:1000px;margin:0 auto;padding:22px 20px 40px}
  .ap-foot{max-width:1000px;margin:0 auto;padding:18px 20px 40px;color:var(--mut);font-size:.78rem}
  h1{font-size:1.5rem;margin:.2rem 0 .3rem}
  .ap-sub{color:var(--mut);margin:0 0 18px;font-size:.9rem}
  .ap-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:18px;margin:0 0 18px}
  .ap-card h2{font-size:1.05rem;margin:0 0 4px}
  .ap-card .ap-cardsub{color:var(--mut);font-size:.82rem;margin:0 0 12px}
  table{border-collapse:collapse;width:100%;font-size:.9rem}
  th,td{padding:9px 10px;border-bottom:1px solid var(--line);text-align:right;font-variant-numeric:tabular-nums}
  th:first-child,td:first-child{text-align:left}
  thead th{background:#2b2b2b;color:#fff;font-size:.72rem;letter-spacing:.02em;text-transform:uppercase}
  .ap-net{color:var(--teal-d);font-weight:700}
  .ap-was{color:var(--mut);text-decoration:line-through;font-size:.82rem}
  .ap-badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#eef7ee;color:#2f6b36;font-size:.72rem;font-weight:700}
  .field{display:grid;gap:6px;margin:0 0 14px}
  .field label{font-size:.8rem;font-weight:700;color:var(--mut)}
  .field input{border:1px solid var(--line);border-radius:9px;padding:10px 12px;font-size:1rem}
  .btn{display:inline-block;border:0;border-radius:9px;padding:11px 16px;background:var(--teal);color:#fff;font-weight:700;cursor:pointer;font-size:1rem;width:100%}
  .btn:hover{background:var(--teal-d)}
  .alert{border-radius:9px;padding:10px 12px;margin:0 0 14px;font-size:.88rem}
  .alert-error{background:#fbeaea;border:1px solid #f0cccc;color:#8a2b2b}
  .ap-login{max-width:380px;margin:8vh auto 0}
  .ap-nav{display:flex;gap:4px;flex-wrap:wrap}
  .ap-nav a{padding:6px 10px;border-radius:8px;color:var(--mut);text-decoration:none;font-size:.9rem;font-weight:600}
  .ap-nav a:hover{background:var(--sand)}
  .ap-nav a.is-active{background:var(--teal);color:#fff}
  .ap-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:end}
  .ap-form .field{margin:0}
  .field select,.field textarea{border:1px solid var(--line);border-radius:9px;padding:10px 12px;font:inherit;font-size:1rem;background:#fff;width:100%}
  .field .dp-btn{border-radius:9px;padding:10px 12px;font-size:1rem;border-color:var(--line);color:var(--mut)}
  .field .dp-btn--active{color:var(--ink)}
  .btn--auto{width:auto}
  .ap-btn{display:inline-block;text-decoration:none;border-radius:9px;padding:8px 12px;background:var(--teal);color:#fff;font-weight:700;font-size:.88rem;margin-top:6px}
  .ap-btn:hover{background:var(--teal-d)}
  .ap-btn--ghost{background:#fff;color:var(--teal-d);border:1px solid var(--teal)}
  .ap-btn--ghost:hover{background:var(--sand)}
  .ap-sec{font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:#b8965a;font-weight:700;margin:14px 0 6px}
  .ap-opts{display:grid;gap:10px}
  .ap-opt{display:flex;justify-content:space-between;gap:14px;align-items:center;border:1px solid var(--line);border-radius:10px;padding:12px 14px;flex-wrap:wrap}
  .ap-opt--entire{border-color:#b8965a;background:#fcf9f3}
  .ap-opt__name{font-weight:700}
  .ap-opt__meta{color:var(--mut);font-size:.82rem;margin-top:2px}
  .ap-opt__price{text-align:right}
  .ap-opt__price b{display:block;color:var(--teal-d);font-size:1.15rem}
  .ap-chip{display:inline-block;background:var(--sand);border:1px solid var(--line);border-radius:999px;padding:3px 9px;font-size:.78rem;margin:4px 4px 0 0}
  .ap-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px 18px;margin:0 0 16px}
  .ap-summary small{display:block;color:var(--mut);font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px}
  .ap-summary span{font-weight:600}
  .ap-note{color:var(--mut);font-size:.85rem}
  .ap-status{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.72rem;font-weight:700}
  .ap-status--pending{background:#fff4e5;color:#9a5b00}
  .ap-status--confirmed{background:#eef7ee;color:#2f6b36}
  .ap-status--expired,.ap-status--cancelled{background:#f1ece5;color:#6b6050}
  .ap-status--sent{background:#eef5f7;color:#0f6f68}
  .alert-success{background:#eef7ee;border:1px solid #cfe6cf;color:#2f6b36}
  table th.ap-left,table td.ap-left{text-align:left}
</style>
</head>
<body>
<header class="ap-head">
  <div class="ap-brand">Tribal Sand <span>Trade</span></div>
  <?php if ($__agent): ?>
  <nav class="ap-nav" aria-label="Trade portal">
    <a href="/agent/availability.php" class="<?= ($agentActive ?? '') === 'availability' ? 'is-active' : '' ?>">Availability</a>
    <a href="/agent/rates.php" class="<?= ($agentActive ?? '') === 'rates' ? 'is-active' : '' ?>">Rates</a>
    <a href="/agent/requests.php" class="<?= ($agentActive ?? '') === 'requests' ? 'is-active' : '' ?>">Your requests</a>
  </nav>
  <div class="ap-user"><?= e($__agent['name']) ?><?= trim((string)$__agent['agency']) !== '' ? ' · ' . e($__agent['agency']) : '' ?> · <a href="/agent/logout.php">Sign out</a></div>
  <?php endif; ?>
</header>
<main class="ap-main">
