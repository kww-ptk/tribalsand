<?php /** Bottom tab bar. Expects $ref, $view, $hold. */ ?>
<?php
$__u = '/booking.php?ref=' . urlencode($ref);
$__svg = fn(string $paths) => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
$__unread = 0;
try { $__unread = count_unread_guest((int)$hold['id']); } catch (Throwable $e) { $__unread = 0; }
$__tabs = [
  'home'       => ['Home',       $__svg('<path d="M3 10.5 12 4l9 6.5"/><path d="M5 9.5V20h14V9.5"/>')],
  'calendar'   => ['My trip',    $__svg('<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 9.5h17"/><path d="M8 3.5v3"/><path d="M16 3.5v3"/>')],
  'extras'     => ['Extras',     $__svg('<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 16l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z"/>')],
  'messages'   => ['Messages',   $__svg('<path d="M4 5h16v11H8l-4 4z"/>')],
];
if (share_reservation_on($hold)) {
  $__tabs['bill'] = ['Bill', $__svg('<path d="M6 2h9l3 3v17l-3-2-3 2-3-2-3 2V2z"/><path d="M9 8h6M9 12h6"/>')];
}
$__tabs['settings'] = ['Settings', $__svg('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>')];
?>
<nav class="pa-nav">
  <?php foreach ($__tabs as $__k => $__t): ?>
  <a class="pa-nav__item <?= $view === $__k ? 'is-active' : '' ?>" href="<?= e($__u) ?>&amp;view=<?= e($__k) ?>">
    <span class="pa-nav__ico" style="position:relative;display:inline-block">
      <?= $__t[1] ?>
      <?php if ($__k === 'messages' && $__unread > 0): ?><span class="pa-nav__badge"><?= (int)$__unread ?></span><?php endif; ?>
    </span><span class="pa-nav__label"><?= e($__t[0]) ?></span>
  </a>
  <?php endforeach; ?>
</nav>
