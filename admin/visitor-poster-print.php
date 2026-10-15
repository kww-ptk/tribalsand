<?php
/** A4 poster for one place: "Register before you enter" + QR → /register?place=<id>. Owner. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/visitor-store.php';
require_login();
require_owner();
$p = visitor_place((int)($_GET['place'] ?? 0));
if (!$p) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Place not found.'); }
$url = site_url('/register?place=' . (int)$p['id']);
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Poster · <?= e((string)$p['name']) ?></title>
<style>
  @page{size:A4;margin:0} body{margin:0;font-family:Georgia,serif;color:#102F3A;text-align:center}
  .pg{width:210mm;height:297mm;box-sizing:border-box;padding:24mm 18mm;display:flex;flex-direction:column;align-items:center;justify-content:space-between}
  h1{font-size:44px;margin:0} .sub{font:20px/1.4 -apple-system,Segoe UI,sans-serif;color:#444;max-width:150mm}
  .qr{width:120mm;height:120mm} .qr svg{width:100%;height:100%} .url{font:18px -apple-system,sans-serif;letter-spacing:.5px}
  .brand{letter-spacing:6px;font-weight:700} .bar{position:fixed;top:10px;right:10px} @media print{.bar{display:none}}
</style></head><body>
<div class="bar"><button onclick="window.print()">Print</button></div>
<div class="pg">
  <div class="brand">TRIBAL SAND</div>
  <div><h1>Register before you enter</h1><p class="sub">Everyone visiting <?= e((string)$p['name']) ?> registers first. Scan with your phone camera — it takes two minutes.</p></div>
  <div class="qr" data-qr="<?= e($url) ?>"></div>
  <div class="url"><?= e(preg_replace('~^https?://~', '', site_url('/register'))) ?></div>
</div>
<script src="/js/vendor/qrcode.js"></script>
<script>(function(){var el=document.querySelector('[data-qr]');var q=qrcode(0,'M');q.addData(el.getAttribute('data-qr'));q.make();el.innerHTML=q.createSvgTag({scalable:true,margin:2});})();</script>
</body></html>
