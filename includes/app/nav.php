<?php /** Bottom tab bar. Expects $ref, $view. */ ?>
<?php
$__u = '/booking.php?ref=' . urlencode($ref);
$__tabs = [
  'home'       => ['Home',      '&#9432;'],
  'activities' => ['Activities', '&#9788;'],
  'concierge'  => ['Concierge', '&#128276;'],
  'stay'       => ['Stay',      '&#8505;'],
  'manage'     => ['Booking',   '&#128197;'],
];
?>
<nav class="pa-nav">
  <?php foreach ($__tabs as $__k => $__t): ?>
  <a class="pa-nav__item <?= $view === $__k ? 'is-active' : '' ?>" href="<?= e($__u) ?>&view=<?= e($__k) ?>">
    <span class="pa-nav__ico" aria-hidden="true"><?= $__t[1] ?></span><?= e($__t[0]) ?>
  </a>
  <?php endforeach; ?>
</nav>
