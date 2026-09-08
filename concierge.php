<?php
/**
 * Public guest concierge — a branded chat page where visitors ask about
 * availability, prices, the properties and activities. Posts to
 * /api/concierge.php (read-only tool+RAG engine, Turnstile + rate-limit + CSRF).
 *
 * Quote-only: the concierge never books. When a guest is ready, the answer card
 * links to the property page's "Request to Book" (the existing 24h-hold flow).
 * If no AI key is configured the page shows a graceful fallback instead of a
 * dead chat box (concierge_supported()).
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';        // session_init(), csrf_token()
require_once __DIR__ . '/includes/turnstile.php';   // captcha_site_key()
require_once __DIR__ . '/includes/concierge.php';   // concierge_supported()

session_init();

$supported = concierge_supported();

$page_title = 'Trip Concierge · Tribal Sand Kenya';
$page_desc  = 'Chat with the Tribal Sand concierge to check availability, get prices and plan your coastal Kenya stay across our boutique properties.';
$page_url   = 'https://tribalsand.com/concierge.php';
$page_image = asset_url('images/whitelogo11.png');
$noindex    = true;   // utility chat page — not a crawlable landing page
?>
<?php include __DIR__ . '/includes/head.php'; ?>
<style>
:root{
  --sand:#B8965A;--sand-lt:#D4B07A;--sand-pale:#F2E8D6;
  --teal:#1E5C6B;--teal-d:#102F3A;--teal-m:#2D7A8C;
  --dark:#141412;--off:#FAF8F4;--white:#fff;
  --mid:#6B6050;--light:#A89880;--border:rgba(184,150,90,.16);
}
.cnc-wrap{max-width:760px;margin:0 auto;padding:48px 20px 72px;}
.cnc-head{text-align:center;margin-bottom:28px;}
.cnc-eyebrow{font-family:'Jost',sans-serif;letter-spacing:.22em;text-transform:uppercase;font-size:12px;color:var(--sand);font-weight:600;}
.cnc-head h1{font-family:'Cormorant Garamond',Georgia,serif;font-weight:600;font-size:clamp(30px,5vw,44px);color:var(--teal-d);margin:.25em 0 .15em;}
.cnc-head p{color:var(--mid);font-size:15px;max-width:44ch;margin:0 auto;line-height:1.55;}

.cnc-card{background:var(--white);border:1px solid var(--border);border-radius:18px;box-shadow:0 18px 50px rgba(16,47,58,.10);overflow:hidden;display:flex;flex-direction:column;min-height:480px;}
.cnc-bar{display:flex;align-items:center;gap:10px;padding:14px 18px;background:var(--teal-d);color:#fff;}
.cnc-bar .dot{width:9px;height:9px;border-radius:50%;background:#5FCf8f;box-shadow:0 0 0 3px rgba(95,207,143,.22);}
.cnc-bar b{font-family:'Jost',sans-serif;font-weight:600;font-size:14px;letter-spacing:.02em;}
.cnc-bar .sub{margin-left:auto;font-size:11px;opacity:.7;}

.cnc-scroll{flex:1;overflow-y:auto;padding:20px 18px;display:flex;flex-direction:column;gap:12px;background:linear-gradient(180deg,#fff, #FCFAF6);}
.cnc-empty{margin:auto;text-align:center;color:var(--light);max-width:36ch;}
.cnc-empty h3{font-family:'Cormorant Garamond',serif;color:var(--teal);font-size:22px;margin-bottom:6px;}
.cnc-chips{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:14px;}
.cnc-chip{border:1px solid var(--border);background:#fff;color:var(--teal-d);border-radius:999px;padding:8px 14px;font-size:13px;cursor:pointer;transition:.15s;font-family:'Jost',sans-serif;}
.cnc-chip:hover{background:var(--sand-pale);border-color:var(--sand-lt);}

.cnc-msg{max-width:80%;padding:11px 14px;border-radius:14px;font-size:14.5px;line-height:1.5;white-space:pre-wrap;word-wrap:break-word;}
.cnc-msg--user{align-self:flex-end;background:var(--teal);color:#fff;border-bottom-right-radius:4px;}
.cnc-msg--ai{align-self:flex-start;background:#fff;border:1px solid var(--border);color:var(--dark);border-bottom-left-radius:4px;}
.cnc-msg--err{align-self:flex-start;background:#FDECEC;border:1px solid #F5C2C2;color:#9B2C2C;font-size:13.5px;}
.cnc-typing{align-self:flex-start;color:var(--light);font-size:13px;font-style:italic;padding:4px 2px;}

.cnc-card2{align-self:flex-start;max-width:88%;background:#fff;border:1px solid var(--border);border-radius:14px;padding:12px 14px;box-shadow:0 6px 18px rgba(16,47,58,.06);}
.cnc-card2 .ttl{font-family:'Jost',sans-serif;font-weight:600;color:var(--teal-d);font-size:13.5px;margin-bottom:8px;}
.cnc-card2 table{width:100%;border-collapse:collapse;font-size:13px;}
.cnc-card2 th{text-align:left;color:var(--mid);font-weight:500;padding:3px 8px 3px 0;white-space:nowrap;}
.cnc-card2 td{padding:3px 0;}
.cnc-card2 td.num{text-align:right;font-variant-numeric:tabular-nums;}
.cnc-book{display:inline-block;margin-top:10px;background:var(--sand);color:#fff;text-decoration:none;font-family:'Jost',sans-serif;font-weight:600;font-size:13px;padding:8px 14px;border-radius:999px;transition:.15s;}
.cnc-book:hover{background:var(--teal);}

.cnc-foot{border-top:1px solid var(--border);padding:12px;background:#fff;}
.cnc-cf{padding:0 6px 10px;}
.cnc-composer{display:flex;gap:10px;align-items:flex-end;}
.cnc-composer textarea{flex:1;resize:none;border:1px solid var(--border);border-radius:12px;padding:11px 13px;font-family:'Jost',sans-serif;font-size:14.5px;line-height:1.4;max-height:140px;color:var(--dark);background:#FCFAF6;}
.cnc-composer textarea:focus{outline:none;border-color:var(--sand-lt);background:#fff;}
.cnc-send{flex:none;width:44px;height:44px;border:none;border-radius:12px;background:var(--teal-d);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.15s;}
.cnc-send:hover{background:var(--teal);}
.cnc-send:disabled{opacity:.5;cursor:default;}
.cnc-send.is-loading svg{animation:cncspin 1s linear infinite;}
@keyframes cncspin{to{transform:rotate(360deg);}}
.cnc-note{font-size:11px;color:var(--light);text-align:center;margin-top:14px;line-height:1.5;}
.cnc-hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;}
</style>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<main class="cnc-wrap">
  <div class="cnc-head">
    <div class="cnc-eyebrow">Plan Your Stay</div>
    <h1>Tribal Sand Concierge</h1>
    <p>Ask about availability, prices, the villas and things to do — and I’ll help you plan your coastal escape.</p>
  </div>

  <?php if (!$supported): ?>
    <div class="cnc-card" style="min-height:auto">
      <div class="cnc-bar"><span class="dot" style="background:#D4B07A;box-shadow:none"></span><b>Concierge</b></div>
      <div style="padding:32px 22px;text-align:center;color:var(--mid)">
        Our live concierge is taking a short break. In the meantime, please use the
        <a href="/contact.php" style="color:var(--teal);font-weight:600">contact form</a> or browse our
        <a href="/" style="color:var(--teal);font-weight:600">properties</a> — we’d love to help you plan.
      </div>
    </div>
  <?php else: ?>
    <div class="cnc-card">
      <div class="cnc-bar">
        <span class="dot"></span><b>Tribal Sand Concierge</b>
        <span class="sub">Quotes &amp; info · we never book without you</span>
      </div>

      <div class="cnc-scroll" id="cncChat"
           data-endpoint="/api/concierge.php"
           data-csrf="<?= e(csrf_token()) ?>">
        <div class="cnc-empty" id="cncEmpty">
          <h3>Karibu 👋</h3>
          <p>How can I help you plan your stay? Try one of these:</p>
          <div class="cnc-chips" id="cncSuggest">
            <button type="button" class="cnc-chip">What’s available at Zuri next weekend for 2?</button>
            <button type="button" class="cnc-chip">What are the villas like?</button>
            <button type="button" class="cnc-chip">What activities are there near Watamu?</button>
            <button type="button" class="cnc-chip">Price for a 3-night stay in March?</button>
          </div>
        </div>
      </div>

      <div class="cnc-foot">
        <?php if (captcha_site_key() !== ''): ?>
          <div class="cnc-cf" id="cncCf">
            <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>"></div>
          </div>
        <?php endif; ?>
        <form class="cnc-composer" id="cncForm" autocomplete="off">
          <span class="cnc-hp" aria-hidden="true">
            <label>Leave this empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
          </span>
          <textarea id="cncInput" rows="1" placeholder="Ask about dates, prices, the villas, activities…" maxlength="1000"></textarea>
          <button type="submit" class="cnc-send" id="cncSend" aria-label="Send">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/></svg>
          </button>
        </form>
      </div>
    </div>
    <p class="cnc-note">The concierge gives live quotes and information but cannot make a booking. To reserve, use the <strong>Request to Book</strong> button on any property page — it places a free 24-hour hold.</p>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php if ($supported): ?>
<script src="/js/concierge.js?v=<?= @filemtime(__DIR__ . '/js/concierge.js') ?>"></script>
<?php endif; ?>
</body>
</html>
