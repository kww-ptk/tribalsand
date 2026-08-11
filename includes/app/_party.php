<?php /** Party roster. Expects $hold, $ref, $actor. Rendered only for multi-adult bookings. */ ?>
<?php
$__pHid    = (int)$hold['id'];
$__pGuests = checkin_supported() ? fetch_checkin_guests($__pHid) : [];
$__pAdults = array_values(array_filter($__pGuests, fn($g) => empty($g['is_child'])));
$__pKids   = [];
foreach ($__pGuests as $__g) if (!empty($__g['is_child'])) $__pKids[(int)($__g['parent_guest_id'] ?? 0)][] = $__g;
$__pNeed   = max(1, (int)($hold['guest_count'] ?? 1));
$__pCfg    = checkin_config();
// Status chips only mean something when the booking actually requires check-in.
$__pShowStatus = checkin_required($hold);
// Only the lead hands out access to the booking.
$__pIsLead = !empty($actor['is_lead']);
?>
<h2 class="pa-h2">Your party</h2>
<p class="pa-sub"><?= $__pIsLead
    ? 'Everyone travelling on this booking. Share a link with anyone still to check in.'
    : 'Everyone travelling on this booking.' ?></p>

<?php if (!$__pAdults): ?>
<div class="pa-card"><div class="pa-card__body">
  <p class="pa-card__meta" style="display:block">Your party will appear here once check-in starts.</p>
</div></div>
<?php else: ?>
<div class="pa-card"><div class="pa-card__body" style="padding:4px 0">
  <?php foreach ($__pAdults as $__a):
      $__aid  = (int)($__a['id'] ?? 0);
      $__isMe = $__aid > 0 && $__aid === (int)($actor['guest_id'] ?? -1);
      $__ok   = checkin_guest_complete($__a, $__pCfg);
      $__mine = $__pKids[$__aid] ?? [];
  ?>
  <div class="pty-row">
    <div class="pty-row__main">
      <span class="pty-name"><?= e(checkin_guest_label($__a, $__pAdults)) ?></span>
      <?php if (!empty($__a['is_lead'])): ?><span class="pty-tag">Lead</span><?php endif; ?>
      <?php if ($__isMe): ?><span class="pty-tag pty-tag--me">You</span><?php endif; ?>
    </div>
    <?php if ($__pShowStatus): ?>
    <span class="ci-chip <?= $__ok ? 'ci-chip--ok' : '' ?>"><?= $__ok ? 'Checked in &#10003;' : 'Pending' ?></span>
    <?php endif; ?>
  </div>
  <?php if ($__mine): ?>
  <div class="pty-kids">
    <?php foreach ($__mine as $__c): ?><span class="ci-kid" style="padding-right:12px"><?= e((string)($__c['passport_name'] ?? 'Child')) ?></span><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($__pIsLead && $__pShowStatus && !$__ok && $__aid > 0 && ($__link = make_guest_pass_url($__pHid, $__aid)) !== ''): ?>
  <div class="ci-linkrow pty-link">
    <input class="ci-in" readonly value="<?= e($__link) ?>" onclick="this.select()">
    <button type="button" class="pa-btn pa-btn--ghost ci-copy">Copy</button>
  </div>
  <?php endif; ?>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php if (count($__pAdults) < $__pNeed): ?>
<p class="pa-sub" style="margin-top:10px"><?= (int)($__pNeed - count($__pAdults)) ?> more guest<?= ($__pNeed - count($__pAdults)) === 1 ? '' : 's' ?> still to be added<?= $__pIsLead ? ' — add them from your check-in.' : '.' ?></p>
<?php endif; ?>
