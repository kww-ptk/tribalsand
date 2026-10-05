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

$pageTitle  = 'AI assistant';
$activeMenu = 'assistant';
include __DIR__ . '/_layout.php';
?>

<style>
/* Design A (Oct 2026): a side panel (recent questions + things to ask) beside
   one chat panel whose composer is docked at the bottom. */
.aiq{display:grid;grid-template-columns:minmax(230px,280px) minmax(0,1fr);gap:14px;height:calc(100vh - 150px);min-height:540px}
.aiq-side{background:#fff;border:1px solid var(--border,#e7ded7);border-radius:14px;padding:14px;display:flex;flex-direction:column;gap:16px;min-height:0;overflow-y:auto}
.aiq-side h4{margin:0;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.aiq-recent{display:flex;flex-direction:column;gap:2px}
.aiq-recent button{display:flex;align-items:center;gap:8px;width:100%;border:0;background:transparent;text-align:left;padding:7px 8px;border-radius:8px;font:inherit;font-size:12.5px;color:var(--text);cursor:pointer;min-width:0}
.aiq-recent button span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.aiq-recent button svg{flex:none;color:var(--muted)}
.aiq-recent button:hover{background:#f4efe9}
.aiq-recent__none{font-size:12.5px;color:var(--muted);margin:0}
.aiq-prompts{display:flex;flex-direction:column;gap:6px}
.aiq-prompt{border:1px solid var(--border,#e7ded7);border-radius:10px;padding:8px 10px;font:inherit;font-size:12.5px;background:#fcfaf7;text-align:left;cursor:pointer;color:var(--text)}
.aiq-prompt:hover{border-color:var(--brand)}
.aiq-prompt small{display:block;color:var(--muted);font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;margin-bottom:1px}
.aiq-main{display:flex;flex-direction:column;min-height:0;min-width:0;background:#fff;border:1px solid var(--border,#e7ded7);border-radius:14px;overflow:hidden}
.aiq-head{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border,#e7ded7);flex-wrap:wrap}
.aiq-head h1{margin:0;font-size:16px}
.aiq-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;border-radius:999px;padding:3px 9px;background:#e8f1f3;color:var(--brand)}
.aiq-head .sp{flex:1}
.aiq-scroll{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;padding:22px 20px 18px;display:flex;flex-direction:column;gap:12px;background:linear-gradient(#fff,#fdfbf7);scrollbar-width:thin;scrollbar-color:#d3c7ba transparent}
.aiq-msg{max-width:min(80%,640px);min-width:0;padding:10px 14px;border-radius:16px;font-size:14px;line-height:1.5;white-space:pre-wrap;overflow-wrap:anywhere}
.aiq-msg--user{align-self:flex-end;background:var(--brand);color:#fff;border-bottom-right-radius:5px}
.aiq-row{display:flex;gap:9px;align-items:flex-start;align-self:flex-start;max-width:100%;min-width:0}
.aiq-av{width:28px;height:28px;border-radius:8px;background:linear-gradient(135deg,var(--brand),var(--accent));color:#fff;display:grid;place-items:center;flex:none;margin-top:2px}
.aiq-msg--ai{background:#f4efe9;color:#102F3A;border-bottom-left-radius:5px;max-width:640px}
.aiq-msg--err{background:#fdecea;color:#8a1c13;border:1px solid #f5c6c1}
.aiq-typing{display:inline-flex;gap:5px;align-items:center;padding:13px 15px;background:#f4efe9;border-radius:16px;border-bottom-left-radius:5px}
.aiq-typing span{width:7px;height:7px;border-radius:50%;background:#8fa9b1;display:inline-block;animation:aiq-bounce 1.2s infinite ease-in-out both}
.aiq-typing span:nth-child(2){animation-delay:.16s}
.aiq-typing span:nth-child(3){animation-delay:.32s}
@keyframes aiq-bounce{0%,80%,100%{transform:scale(.55);opacity:.4}40%{transform:scale(1);opacity:1}}
@media (prefers-reduced-motion:reduce){.aiq-typing span{animation:none;opacity:.7}}
.aiq-empty{margin:auto;max-width:460px;display:flex;flex-direction:column;gap:10px;align-items:center;text-align:center;color:var(--muted)}
.aiq-empty[hidden]{display:none}
.aiq-empty strong{color:var(--text);font-size:15px}
.aiq-card{margin-left:37px;align-self:flex-start;width:min(600px,calc(100% - 37px));min-width:0;background:#fff;border:1px solid var(--border,#e7ded7);border-radius:12px;overflow:hidden}
.aiq-card table{border-collapse:collapse;font-size:13px;width:100%;table-layout:fixed}
.aiq-card th,.aiq-card td{text-align:left;padding:7px 12px;border-top:1px solid #f0eae3;overflow-wrap:anywhere}
.aiq-card th{font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:700}
.aiq-card td.num,.aiq-card th.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.aiq-card__ttl{display:flex;align-items:center;gap:8px;font-weight:700;color:#102F3A;font-size:12.5px;padding:9px 12px;background:#f7f3ed}
.aiq-card__ttl + .aiq-card__ttl{background:#fff;padding-top:12px}
.aiq-card__ft{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:9px 12px;border-top:1px solid #f0eae3}
.aiq-composer{border-top:1px solid var(--border,#e7ded7);padding:10px 12px;background:#fbf9f6;display:flex;flex-direction:column;gap:8px}
.aiq-follow{display:flex;gap:6px;flex-wrap:wrap}
.aiq-follow[hidden]{display:none}
.aiq-follow button{border:1px solid var(--border,#e7ded7);background:#fff;border-radius:999px;padding:3px 10px;font:inherit;font-size:11.5px;color:var(--brand);cursor:pointer}
.aiq-follow button:hover{border-color:var(--brand)}
.aiq-inputrow{display:flex;gap:8px;align-items:flex-end}
.aiq-inputrow textarea{flex:1 1 auto;resize:none;min-height:44px;max-height:140px;overflow-y:hidden;line-height:1.4}
.aiq-inputrow textarea:disabled{opacity:.55;cursor:not-allowed}
.aiq-inputrow button{flex:0 0 auto;height:44px;width:44px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:12px}
@media (max-width:900px){
  .aiq{grid-template-columns:minmax(0,1fr);height:auto}
  .aiq-main{height:calc(100vh - 150px);min-height:480px;order:-1}
  .aiq-side{overflow:visible}
}
</style>

<?php if (!$configured): ?>
<div class="page-header"><h1>AI assistant</h1></div>
<div class="card"><div class="card__body card__body--pad">
  <p style="margin:0 0 6px;font-weight:600">The assistant isn’t set up on this environment yet.</p>
  <p style="margin:0;color:var(--muted);font-size:14px">Set <code>AI_API_KEY</code> (and optionally <code>AI_PROVIDER</code> / <code>AI_MODEL</code>) in the environment to enable it. It only ever reads availability and prices — it can’t book anything.</p>
</div></div>
<?php else: ?>
<div class="aiq">
  <aside class="aiq-side" aria-label="Recent and suggested questions">
    <button type="button" class="btn-primary btn-sm" id="aiqClear" style="justify-content:center"><?= admin_icon('plus', 15) ?> New question</button>
    <div style="display:flex;flex-direction:column;gap:6px">
      <h4>Recent</h4>
      <div class="aiq-recent" id="aiqRecent"><p class="aiq-recent__none">Your questions show up here.</p></div>
    </div>
    <div style="display:flex;flex-direction:column;gap:6px">
      <h4>Try asking</h4>
      <div class="aiq-prompts" id="aiqSuggest">
        <button type="button" class="aiq-prompt" data-ask="What’s free this weekend for 4 guests?"><small>Availability</small>What’s free this weekend for 4 guests?</button>
        <button type="button" class="aiq-prompt" data-ask="Anything available in December for 6?"><small>Availability</small>Anything available in December for 6?</button>
        <button type="button" class="aiq-prompt" data-ask="What’s the total for Zuri, 3 nights from next Friday for 2?"><small>Price</small>Total for Zuri, 3 nights from next Friday for 2?</button>
        <button type="button" class="aiq-prompt" data-ask="Which properties do we have, and how many guests does each sleep?"><small>Property info</small>Which properties do we have?</button>
        <button type="button" class="aiq-prompt" data-ask="What time is check-in and check-out?"><small>Property info</small>What time is check-in and check-out?</button>
      </div>
    </div>
  </aside>

  <section class="aiq-main" aria-label="Conversation">
    <div class="aiq-head">
      <h1>AI assistant</h1>
      <span class="aiq-badge" data-tip="Answers come from the live calendar and rates — the same figures the booking page shows. It can quote but never books or holds."><?= admin_icon('check', 12) ?> Live calendar &amp; rates · never books</span>
    </div>
    <div class="aiq-scroll" id="aiqChat" data-endpoint="/api/assistant.php" data-csrf="<?= e(csrf_token()) ?>">
      <div class="aiq-empty" id="aiqEmpty">
        <span class="aiq-av" style="width:40px;height:40px;border-radius:12px"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 11l-6.1 2.2L12 19l-1.9-5.8L4 11l6.1-2.2Z"/></svg></span>
        <strong>Ask about availability, prices or a property</strong>
        <span>Type a question in plain English, or pick one from the left.</span>
      </div>
    </div>
    <form class="aiq-composer" id="aiqForm" autocomplete="off">
      <div class="aiq-follow" id="aiqFollow" hidden>
        <button type="button" data-ask="Which of those is the cheapest?">Cheapest option</button>
        <button type="button" data-ask="Same dates, but add one child.">Add a child</button>
        <button type="button" data-ask="What about the weekend after?">The weekend after</button>
        <button type="button" data-ask="Only show the whole-property options.">Whole property only</button>
      </div>
      <div class="aiq-inputrow">
        <textarea class="inp inp--area" id="aiqInput" name="message" placeholder="e.g. Is Zuri free 12–15 Oct for 2? What’s the total?" rows="1" maxlength="1000"></textarea>
        <button type="submit" class="btn-icon btn-icon--primary" id="aiqSend" aria-label="Ask" data-tip="Ask (Enter)">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4Z"/></svg>
        </button>
      </div>
    </form>
  </section>
</div>
<?php endif; ?>
<?php if ($configured): ?>
<script defer src="/admin/assets/admin-assistant.js?v=<?= @filemtime(__DIR__ . '/assets/admin-assistant.js') ?: '1' ?>"></script>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
