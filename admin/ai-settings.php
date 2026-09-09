<?php
declare(strict_types=1);
/**
 * Admin → AI Assistant settings (owner-only, Phase 5).
 *
 * Lets the owner tune HOW the AI writes — tone/voice per audience, freeform
 * business facts, and how enquiry-thread drafts read — without touching code and
 * WITHOUT any power to weaken the safety rules. Everything here is stored in the
 * plain `settings` KV (set_setting) and composed into the system prompt as an
 * *appended* block: the hard rules (one pricing path, Nairobi dates, never
 * invent a price/availability/booking, read-only) stay hardcoded and last in
 * includes/assistant-tools.php, so an edit here can only shape style and add
 * facts. See assistant_system_prompt() / assistant_draft_instructions().
 *
 * Site-wide config → owner-only, like admin/settings.php and admin/nav-menu.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/assistant-tools.php';
require_login();
require_owner();

$configured = ai_assistant_supported();
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'save_ai') {
        // Bounded lengths keep a runaway paste from bloating every prompt/token bill.
        $persona_staff = trim((string)($_POST['ai_persona_staff']    ?? ''));
        $persona_guest = trim((string)($_POST['ai_persona_guest']    ?? ''));
        $knowledge     = trim((string)($_POST['ai_extra_knowledge']  ?? ''));
        $draft         = trim((string)($_POST['ai_draft_instructions'] ?? ''));

        $limits = [
            'Staff tone'          => [$persona_staff, 2000],
            'Guest tone'          => [$persona_guest, 2000],
            'Business notes'      => [$knowledge,     6000],
            'Draft instructions'  => [$draft,         2000],
        ];
        foreach ($limits as $label => [$val, $max]) {
            if (mb_strlen($val) > $max) { $error = "$label is too long (max $max characters)."; break; }
        }

        if ($error === '') {
            set_setting('ai_persona_staff',      $persona_staff);
            set_setting('ai_persona_guest',      $persona_guest);
            set_setting('ai_extra_knowledge',    $knowledge);
            set_setting('ai_draft_instructions', $draft);
            audit_log('ai_settings.save');
            $success = 'AI assistant settings saved.';
        }
    }
}

$persona_staff = ai_editable_setting('ai_persona_staff');
$persona_guest = ai_editable_setting('ai_persona_guest');
$knowledge     = ai_editable_setting('ai_extra_knowledge');
$draft         = ai_editable_setting('ai_draft_instructions');

$pageTitle  = 'AI Assistant';
$activeMenu = 'ai_settings';
include __DIR__ . '/_layout.php';
?>

<div class="page-header">
  <h1>AI Assistant settings</h1>
</div>

<?php if ($success): ?><div class="alert alert--success is-flash"><?= e($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert--error is-flash"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="border-left:3px solid #1E5C6B">
  <div class="card__body" style="padding:16px 20px">
    <p style="margin:0;font-size:13.5px;color:var(--muted)">
      These notes guide the assistant's <strong>tone</strong> and add <strong>business facts</strong> it can draw on — for the staff assistant, the public concierge, and AI-drafted enquiry replies.
      <strong>Prices, availability and booking rules are always enforced by the system</strong> and cannot be changed here: the assistant only ever quotes live figures from the calendar and rates, and never books or holds.
    </p>
  </div>
</div>

<?php if (!$configured): ?>
<div class="alert alert--warning" style="margin-bottom:16px">
  The assistant isn’t enabled on this environment yet (no <code>AI_API_KEY</code> set), so these settings won’t take effect until it is. You can still prepare them here — they’ll apply once a key is configured.
</div>
<?php endif; ?>

<form method="POST" action="/admin/ai-settings.php" data-shell-form>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_ai">

  <div class="card">
    <div class="card__head"><span class="card__title">Tone &amp; voice</span></div>
    <div class="card__body" style="padding:20px">
      <div class="field">
        <label for="ai_persona_staff">Staff assistant tone</label>
        <textarea id="ai_persona_staff" name="ai_persona_staff" rows="4" class="inp inp--area" style="width:100%;min-height:96px"
          placeholder="e.g. Brisk and practical. Lead with the numbers reception needs; skip the sales pitch."><?= e($persona_staff) ?></textarea>
        <span class="field-hint">How the in-admin availability assistant should write for front-desk and management staff.</span>
      </div>
      <div class="field">
        <label for="ai_persona_guest">Guest concierge tone</label>
        <textarea id="ai_persona_guest" name="ai_persona_guest" rows="4" class="inp inp--area" style="width:100%;min-height:96px"
          placeholder="e.g. Warm, unhurried and a little poetic about the coast. First person plural (“we”, “our villas”). Always end by inviting the next step."><?= e($persona_guest) ?></textarea>
        <span class="field-hint">How the public website concierge should greet and write to prospective guests.</span>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Business knowledge</span></div>
    <div class="card__body" style="padding:20px">
      <div class="field">
        <label for="ai_extra_knowledge">Facts the assistant may use</label>
        <textarea id="ai_extra_knowledge" name="ai_extra_knowledge" rows="8" class="inp inp--area" style="width:100%;min-height:160px"
          placeholder="e.g. All stays include breakfast and airport transfers within 30km. Minimum stay is 2 nights over peak season. We are plastic-free and solar-powered. Children under 3 stay free."><?= e($knowledge) ?></textarea>
        <span class="field-hint">Policies, inclusions, and selling points the assistant can mention. It will <strong>never</strong> quote a price or an availability from these notes — those always come from the live tools.</span>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><span class="card__title">Enquiry-reply drafts</span></div>
    <div class="card__body" style="padding:20px">
      <div class="field">
        <label for="ai_draft_instructions">How “Draft with AI” replies should read</label>
        <textarea id="ai_draft_instructions" name="ai_draft_instructions" rows="5" class="inp inp--area" style="width:100%;min-height:120px"
          placeholder="e.g. Open with the guest’s first name. Offer the best-fit option, then one alternative. Mention the 24-hour hold. Sign off as “The Tribal Sand team”."><?= e($draft) ?></textarea>
        <span class="field-hint">Guides the drafts staff generate from an enquiry thread (they always review and edit before sending — nothing is ever sent automatically).</span>
      </div>
    </div>
  </div>

  <div style="display:flex;align-items:center;gap:14px;margin:4px 0 24px">
    <button type="submit" class="btn-primary">Save AI settings</button>
    <span class="field-hint" style="margin:0">Applies to the staff assistant, the guest concierge, and AI reply drafts.</span>
  </div>
</form>

<div class="card">
  <div class="card__head"><span class="card__title">Provider (read-only)</span></div>
  <div class="card__body" style="padding:20px">
    <p style="font-size:13px;color:var(--muted);margin:0 0 12px">Set in the environment, not editable here.</p>
    <div class="detail-grid">
      <div>
        <div class="detail-item__label">Status</div>
        <div class="detail-item__value">
          <span class="badge <?= $configured ? 'badge--green' : 'badge--orange' ?>"><?= $configured ? 'enabled' : 'not configured' ?></span>
        </div>
      </div>
      <div>
        <div class="detail-item__label">Provider</div>
        <div class="detail-item__value"><?= e(ai_provider()) ?></div>
      </div>
      <div>
        <div class="detail-item__label">Model</div>
        <div class="detail-item__value"><?= e(ai_model()) ?></div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/_layout_end.php'; ?>
