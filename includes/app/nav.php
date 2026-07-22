<?php /** Bottom tab bar. Expects $ref, $view. */ ?>
<?php
$__u = '/booking.php?ref=' . urlencode($ref);
$__svg = fn(string $paths) => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
$__tabs = [
  'home'       => ['Home',       $__svg('<path d="M3 10.5 12 4l9 6.5"/><path d="M5 9.5V20h14V9.5"/>')],
  'activities' => ['Activities', $__svg('<circle cx="12" cy="12" r="8.5"/><path d="M15.5 8.5 13 13l-4.5 2.5L11 11z"/>')],
  'concierge'  => ['Concierge',  $__svg('<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6z"/><path d="M10 20a2 2 0 0 0 4 0"/>')],
  'stay'       => ['Stay',       $__svg('<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5"/><path d="M12 8h.01"/>')],
  'manage'     => ['Booking',    $__svg('<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M4 9h16"/><path d="M8 3v4M16 3v4"/>')],
];
?>
<nav class="pa-nav">
  <?php foreach ($__tabs as $__k => $__t): ?>
  <a class="pa-nav__item <?= $view === $__k ? 'is-active' : '' ?>" href="<?= e($__u) ?>&amp;view=<?= e($__k) ?>">
    <span class="pa-nav__ico"><?= $__t[1] ?></span><?= e($__t[0]) ?>
  </a>
  <?php endforeach; ?>
</nav>
