<?php if (!isset($hold) || !$hold) return; ?>
<?php
$__bg = ['pending'=>'#fef3c7','confirmed'=>'#dcfce7','cancelled'=>'#fee2e2','expired'=>'#f3f4f6'][$status] ?? '#f3f4f6';
$__fg = ['pending'=>'#92400e','confirmed'=>'#166534','cancelled'=>'#991b1b','expired'=>'#6b7280'][$status] ?? '#6b7280';
$__nights = (int)((strtotime($hold['check_out']) - strtotime($hold['check_in'])) / 86400);
?>
<div class="pa-status">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
    <div style="font-family:'Cormorant Garamond',serif;font-size:20px"><?= e($hold['room_name']) ?></div>
    <span class="pa-badge" style="background:<?= $__bg ?>;color:<?= $__fg ?>"><?= e(ucfirst($status)) ?></span>
  </div>
  <dl style="margin:0">
    <div class="pa-status__row"><dt>Check-in</dt><dd><?= e(date('D, j M Y', strtotime($hold['check_in']))) ?></dd></div>
    <div class="pa-status__row"><dt>Check-out</dt><dd><?= e(date('D, j M Y', strtotime($hold['check_out']))) ?> · <?= e((string)$__nights) ?> nights</dd></div>
    <?php if (!empty($hold['access_code'])): ?><div class="pa-status__row"><dt>Booking code</dt><dd style="font-family:monospace;letter-spacing:1px"><?= e($hold['access_code']) ?></dd></div><?php endif; ?>
    <?php if ($status === 'pending' && !empty($hold['expires_at'])): ?><div class="pa-status__row"><dt>Hold expires</dt><dd id="bkCountdown" style="color:#b45309"></dd></div><?php endif; ?>
  </dl>
</div>
