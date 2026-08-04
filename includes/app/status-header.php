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
    <?php if ($status === 'pending' && !empty($hold['expires_at'])):
      // Server-rendered fallback; the inline countdown script replaces it live.
      $__left = strtotime($hold['expires_at']) - time();
      $__fallback = $__left > 0 ? sprintf('%dh %02dm', intdiv($__left, 3600), intdiv($__left % 3600, 60)) : 'Expiring soon';
    ?><div class="pa-status__row"><dt>Hold expires</dt><dd id="bkCountdown" style="color:#b45309"><?= e($__fallback) ?></dd></div><?php endif; ?>
  </dl>
  <?php
    $__stay  = fetch_venue_stay(isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null);
    $__maps  = venue_maps_link($__stay);
    $__vname = trim((string)($hold['venue_name'] ?? ''));
    $__addr  = $__stay['address'];                       // may be blank
    $__showAddr = $__addr !== '' && $__addr !== $__vname; // avoid echoing the name twice
  ?>
  <?php if ($__maps !== '' && ($__vname !== '' || $__addr !== '')): ?>
  <a class="pa-maps" href="<?= e($__maps) ?>" target="_blank" rel="noopener">
    <span class="pa-maps__pin" aria-hidden="true">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-6-5.2-6-10a6 6 0 1 1 12 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/></svg>
    </span>
    <span class="pa-maps__text">
      <?php if ($__vname !== ''): ?><b><?= e($__vname) ?></b><?php if ($__showAddr): ?><br><?php endif; ?><?php endif; ?>
      <?php if ($__showAddr): ?><?= e($__addr) ?><?php elseif ($__vname === ''): ?><?= e($__addr) ?><?php endif; ?>
      <span class="pa-maps__go">Open in Google Maps →</span>
    </span>
  </a>
  <?php endif; ?>
</div>
<?php
$__note = [
  // 'confirmed' intentionally omitted — the status badge at the top already says it.
  'pending'   => 'Awaiting confirmation — our team will confirm within 24 hours.',
  'expired'   => 'This hold expired.',
  'cancelled' => 'This booking was cancelled.',
][$status] ?? '';
?>
<?php if ($__note): ?>
<div style="background:<?= $__bg ?>;color:<?= $__fg ?>;border-radius:12px;padding:12px 14px;font-size:13px;line-height:1.6;margin-bottom:12px">
  <?= e($__note) ?><?php if (in_array($status, ['expired','cancelled'], true)): ?> <a href="/properties" style="color:inherit;font-weight:600;text-decoration:underline">Browse our properties</a><?php endif; ?>
</div>
<?php endif; ?>
