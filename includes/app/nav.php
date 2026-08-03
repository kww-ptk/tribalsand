<?php /** Bottom tab bar. Expects $ref, $view, $hold. */ ?>
<?php
$__u = '/booking.php?ref=' . urlencode($ref);
$__svg = fn(string $paths) => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
$__unread = 0;
try { $__unread = count_unread_guest((int)$hold['id']); } catch (Throwable $e) { $__unread = 0; }
$__tabs = [
  'home'       => ['Home',       $__svg('<path d="M3 10.5 12 4l9 6.5"/><path d="M5 9.5V20h14V9.5"/>')],
  'activities' => ['Activities', $__svg('<circle cx="12" cy="12" r="8.5"/><path d="M15.5 8.5 13 13l-4.5 2.5L11 11z"/>')],
  'messages'   => ['Messages',   $__svg('<path d="M4 5h16v11H8l-4 4z"/>')],
];
?>
<nav class="pa-nav">
  <?php foreach ($__tabs as $__k => $__t): ?>
  <a class="pa-nav__item <?= $view === $__k ? 'is-active' : '' ?>" href="<?= e($__u) ?>&amp;view=<?= e($__k) ?>">
    <span class="pa-nav__ico" style="position:relative;display:inline-block">
      <?= $__t[1] ?>
      <?php if ($__k === 'messages' && $__unread > 0): ?><span class="pa-nav__badge"><?= (int)$__unread ?></span><?php endif; ?>
    </span><?= e($__t[0]) ?>
  </a>
  <?php endforeach; ?>
</nav>
