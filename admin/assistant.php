<?php
declare(strict_types=1);
/**
 * AI availability & price assistant — admin chat panel.
 *
 * Staff ask in plain English ("anything free at Zuri next weekend for 4?") and
 * get a live answer sourced from the same helpers the booking widget uses. The
 * heavy lifting is in api/assistant.php + includes/{ai,assistant-tools}.php;
 * this page is just the (no-native-chrome) chat UI. Read-only: it quotes, it
 * never books.
 *
 * Audience: owner, manager, reception, front-desk staff (require_frontdesk()).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai.php';
require_login();
require_frontdesk();

$configured = ai_assistant_supported();

$pageTitle  = 'Assistant';
$activeMenu = 'assistant';
include __DIR__ . '/_layout.php';
?>

<style>
.aiq{width:100%}
.aiq-intro{color:var(--muted);font-size:14px;margin:2px 0 16px}
/* One panel: messages scroll on top, the composer is docked inside at the bottom. */
.aiq-panel{display:flex;flex-direction:column;height:min(68vh,560px);background:#fff;border:1px solid var(--border,#e7ded7);border-radius:14px;overflow:hidden}
.aiq-scroll{flex:1;min-height:0;overflow-y:auto;padding:18px;display:flex;flex-direction:column;gap:12px}
.aiq-msg{max-width:88%;padding:10px 14px;border-radius:14px;font-size:14px;line-height:1.5;white-space:pre-wrap;word-wrap:break-word}
.aiq-msg--user{align-self:flex-end;background:#1E5C6B;color:#fff;border-bottom-right-radius:4px}
.aiq-msg--ai{align-self:flex-start;background:#f4efe9;color:#102F3A;border-bottom-left-radius:4px}
.aiq-msg--err{align-self:flex-start;background:#fdecea;color:#8a1c13;border:1px solid #f5c6c1}
.aiq-typing{align-self:flex-start;color:var(--muted);font-size:13px;font-style:italic}
.aiq-empty{margin:auto;max-width:480px;display:flex;flex-direction:column;gap:14px;align-items:center;text-align:center}
.aiq-empty[hidden]{display:none}   /* author display:flex would otherwise beat the UA [hidden] rule */
.aiq-empty p{color:var(--muted);font-size:14px;margin:0}
.aiq-bar{display:flex;justify-content:flex-end;margin:0 0 8px}
.aiq-card{align-self:flex-start;max-width:88%;background:#fff;border:1px solid var(--border,#e7ded7);border-radius:12px;padding:2px 0;overflow:hidden}
.aiq-card table{border-collapse:collapse;font-size:13px;width:100%}
.aiq-card th,.aiq-card td{text-align:left;padding:6px 12px;border-bottom:1px solid #f0eae3;white-space:nowrap}
.aiq-card th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:700}
.aiq-card tr:last-child td{border-bottom:none}
.aiq-card td.num{text-align:right;font-variant-numeric:tabular-nums}
.aiq-card__ttl{font-weight:700;color:#102F3A;font-size:13px;padding:9px 12px 4px}
.aiq-composer{display:flex;gap:10px;align-items:flex-end;padding:12px;border-top:1px solid var(--border,#e7ded7);background:#fbf9f6}
.aiq-composer textarea{flex:1 1 auto;resize:none;min-height:44px;max-height:140px;overflow-y:hidden;line-height:1.4}
.aiq-composer button{flex:0 0 auto;height:44px;width:44px;padding:0;display:inline-flex;align-items:center;justify-content:center}
.aiq-suggest{display:flex;flex-wrap:wrap;gap:8px;justify-content:center}
.aiq-chip{background:#f4efe9;border:1px solid var(--border,#e7ded7);border-radius:20px;padding:6px 12px;font-size:12.5px;color:#1E5C6B;cursor:pointer}
.aiq-chip:hover{background:#eee7de;border-color:#d8cec3}
</style>

<div class="page-header">
  <h1>Availability assistant</h1>
</div>

<div class="aiq">
<?php if (!$configured): ?>
  <div class="card"><div class="card__body card__body--pad">
    <p style="margin:0 0 6px;font-weight:600">The assistant isn’t set up on this environment yet.</p>
    <p style="margin:0;color:var(--muted);font-size:14px">Set <code>AI_API_KEY</code> (and optionally <code>AI_PROVIDER</code> / <code>AI_MODEL</code>) in the environment to enable it. It only ever reads availability and prices — it can’t book anything.</p>
  </div></div>
<?php else: ?>
  <p class="aiq-intro">Ask about availability and prices in plain English. Answers come straight from the live calendar and rates — the same figures the booking page shows. The assistant can quote, but never books or holds.</p>

  <div class="aiq-bar">
    <button type="button" class="btn-outline btn-sm" id="aiqClear" hidden>Clear conversation</button>
  </div>

  <div class="aiq-panel">
    <div class="aiq-scroll" id="aiqChat" data-endpoint="/api/assistant.php" data-csrf="<?= e(csrf_token()) ?>">
      <div class="aiq-empty" id="aiqEmpty">
        <p>Ask about availability and prices, or try one of these:</p>
        <div class="aiq-suggest" id="aiqSuggest">
          <button type="button" class="aiq-chip">What’s free this weekend for 4 guests?</button>
          <button type="button" class="aiq-chip">Anything available in December for 6?</button>
          <button type="button" class="aiq-chip">Which properties do we have?</button>
        </div>
      </div>
    </div>

    <form class="aiq-composer" id="aiqForm" autocomplete="off">
      <textarea class="inp inp--area" id="aiqInput" name="message" placeholder="e.g. Is Zuri free 12–15 Oct for 2? What’s the total?" rows="1" maxlength="1000"></textarea>
      <button type="submit" class="btn-icon btn-icon--primary" id="aiqSend" aria-label="Ask" title="Ask">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4Z"/></svg>
      </button>
    </form>
  </div>
<?php endif; ?>
</div>

<?php if ($configured): ?>
<script defer src="/admin/assets/admin-assistant.js?v=<?= @filemtime(__DIR__ . '/assets/admin-assistant.js') ?: '1' ?>"></script>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
