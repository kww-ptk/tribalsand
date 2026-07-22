<?php /** Activities view. Expects $hold, $ref, $status. */ ?>
<?php
$__acts = fetch_portal_activities();
$__cats = fetch_tour_categories();
$__active = in_array($status ?? '', ['pending','confirmed'], true);
?>
<h2 class="pa-h2">Experiences</h2>
<p class="pa-sub">Browse and request activities — our team confirms availability and pricing.</p>

<?php if ($__cats): ?>
<div class="pa-chips" id="paCatChips">
  <button type="button" class="pa-chip is-active" data-cat="all" aria-pressed="true">All</button>
  <?php foreach ($__cats as $c): ?>
  <button type="button" class="pa-chip" data-cat="<?= e($c['key']) ?>" aria-pressed="false"><?= e($c['label']) ?></button>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$__acts): ?>
  <p class="pa-sub">Experiences will appear here soon.</p>
<?php else: foreach ($__acts as $a):
    $img = trim((string)($a['hero'] ?? ''));
    $mediaClass = 'pa-media pa-media--' . preg_replace('/[^a-z]/','',strtolower((string)$a['category']));
    $style = $img !== '' ? 'background-image:url(\'' . e(storage_url($img)) . '\')' : '';
?>
  <div class="pa-card" data-cat="<?= e($a['category']) ?>">
    <div class="<?= e($mediaClass) ?>" style="<?= $style ?>">
      <?php if (!empty($a['tag_label'])): ?><span class="pa-media__tag"><?= e($a['tag_label']) ?></span><?php endif; ?>
    </div>
    <div class="pa-card__body">
      <p class="pa-card__title"><?= e($a['name']) ?></p>
      <div class="pa-card__meta">
        <?php if (!empty($a['duration'])): ?><span><?= e($a['duration']) ?></span><?php endif; ?>
        <?php if (!empty($a['short_desc'])): ?><span style="flex-basis:100%;margin-top:4px;color:var(--pa-muted)"><?= e($a['short_desc']) ?></span><?php endif; ?>
      </div>
      <?php if ($__active): ?>
      <form data-bm action="/api/booking-addon.php" style="margin-top:12px">
        <input type="hidden" name="ref" value="<?= e($ref) ?>">
        <input type="hidden" name="kind" value="tour">
        <input type="hidden" name="tour_slug" value="<?= e($a['slug']) ?>">
        <div class="cf-turnstile" data-sitekey="<?= e(captcha_site_key()) ?>" style="margin:0 0 8px"></div>
        <button type="submit" class="pa-btn pa-btn--primary">Request this activity</button>
        <p class="bm-status" aria-live="polite" style="margin:8px 0 0;font-size:13px"></p>
      </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; endif; ?>

<script>
(function(){
  var chips=document.querySelectorAll('#paCatChips .pa-chip');
  var cards=document.querySelectorAll('.pa-card[data-cat]');
  chips.forEach(function(ch){ch.addEventListener('click',function(){
    chips.forEach(function(x){x.classList.remove('is-active');x.setAttribute('aria-pressed','false');}); ch.classList.add('is-active'); ch.setAttribute('aria-pressed','true');
    var cat=ch.getAttribute('data-cat');
    cards.forEach(function(cd){ cd.style.display=(cat==='all'||cd.getAttribute('data-cat')===cat)?'':'none'; });
  });});
})();
</script>
