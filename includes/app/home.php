<?php /** Home. Expects $hold, $ref, $status. */ ?>
<?php $__u = '/booking.php?ref=' . urlencode($ref); $__active = in_array($status ?? '', ['pending','confirmed'], true);
try { $__feat = array_slice(fetch_portal_activities(), 0, 3); } catch (Throwable $e) { $__feat = []; } ?>
<?php $__first = trim((string)$hold['guest_name']); $__first = $__first !== '' ? explode(' ', $__first)[0] : 'guest'; ?>
<div style="font-family:'Cormorant Garamond',serif;font-size:24px;margin:4px 0 12px">Karibu, <?= e($__first) ?></div>

<?php if ($__active): ?>
<a class="pa-tile pa-tile--hero" href="<?= e($__u) ?>&amp;view=concierge">
  <span aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg></span>
  <span><span class="pa-tile__t">Concierge</span><span class="pa-tile__s">Towels, housekeeping, anything you need</span></span>
  <span style="margin-left:auto">&rarr;</span>
</a>
<?php endif; ?>

<?php if ($__feat): ?>
<div style="display:flex;justify-content:space-between;align-items:baseline;margin:16px 0 8px">
  <div class="pa-h2" style="font-size:18px">Experiences</div>
  <a href="<?= e($__u) ?>&amp;view=activities" style="font-size:13px;color:var(--pa-teal,#1E5C6B);text-decoration:none">See all &rarr;</a>
</div>
<?php foreach ($__feat as $a): $mediaClass='pa-media pa-media--'.preg_replace('/[^a-z]/','',strtolower((string)$a['category'])); $img=trim((string)($a['hero']??'')); ?>
<a class="pa-card" style="display:block;text-decoration:none;color:inherit" href="<?= e($__u) ?>&amp;view=activities">
  <div class="<?= e($mediaClass) ?>" style="height:96px;<?= $img!==''?'background-image:url(\''.e(storage_url($img)).'\')':'' ?>"></div>
  <div class="pa-card__body"><p class="pa-card__title"><?= e($a['name']) ?></p><?php if(!empty($a['duration'])): ?><div class="pa-card__meta"><span><?= e($a['duration']) ?></span></div><?php endif; ?></div>
</a>
<?php endforeach; endif; ?>
