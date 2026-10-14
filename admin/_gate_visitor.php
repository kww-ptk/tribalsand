<?php
/**
 * The Gate page card for a scanned visitor pass. Expects $vpCard (visitor_gate_card()),
 * $vpTok, $vpPlaces (places the guard may pick when none is chosen for today).
 * Same look as the booking-pass card (.gt-pass). Out of scope: only the verdict (which
 * names the place) is shown — no name, ID, photo, phone or group.
 */
$v    = $vpCard['visitor']; $vd = $vpCard['verdict']; $vis = $vpCard['visit'];
$show = $vpCard['in_scope'];
$lvl  = in_array($vd['level'], ['ok', 'warn', 'stop'], true) ? $vd['level'] : 'warn';
// Not signed / terms changed: the only way to sign again is their own pass, so offer it on this tablet.
$resignUrl = '';
if ($show && $lvl === 'stop' && empty($v['removed_at']) && !visitor_waiver_current($v, waiver_version(visitor_waiver_text()))) {
    $resignUrl = visitor_pass_url((int)$v['id']);
}
?>
<div class="gt-pass gt-pass--<?= e($lvl) ?>" id="gtVisitorPass">
  <div class="gt-pass__verdict"><?= $lvl === 'stop' ? '✕ ' : ($lvl === 'warn' ? '! ' : '✓ ') ?><?= e($vd['msg']) ?></div>
  <?php if ($show): ?>
  <p class="gt-pass__name"><?= e((string)$v['full_name']) ?></p>
  <div class="text-muted">Visitor pass · <?= (int)$vpCard['people'] ?> <?= (int)$vpCard['people'] === 1 ? 'person' : 'people' ?></div>
  <dl class="gt-pass__grid">
    <?php if ($vis): ?><div><dt>Going to</dt><dd><?= e((string)$vis['place_name']) ?></dd></div><?php endif; ?>
    <div><dt><?= e(checkin_id_type_label((string)$v['id_type'])) ?></dt><dd><?= e((string)($v['id_number'] ?: '—')) ?><?= !empty($v['nationality']) ? ' · ' . e((string)$v['nationality']) : '' ?></dd></div>
    <div><dt>Phone</dt><dd><?= e((string)($v['phone'] ?: '—')) ?></dd></div>
    <div><dt>People</dt><dd><?= (int)$vpCard['people'] ?></dd></div>
  </dl>
  <?php if (!empty($v['id_photo_key'])): ?><img src="/admin/visitor-file.php?id=<?= (int)$v['id'] ?>" alt="ID photo" style="max-width:260px;border-radius:8px;margin:4px 0 8px;display:block"><?php endif; ?>
  <?php if ($vpCard['adults'] || $vpCard['children']): ?>
  <div class="text-muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.04em">Group</div>
  <ul class="gt-pass__names">
    <?php foreach ($vpCard['adults'] as $a): ?><li><?= e($a['name']) ?> — <?= $a['current'] ? 'signed ✓' : '<strong>not signed</strong>' ?></li><?php endforeach; ?>
    <?php foreach ($vpCard['children'] as $c): ?><li><?= e((string)$c['full_name']) ?> (child, <?= (int)$c['age'] ?>)</li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($vpCard['on_site']): ?>
  <p class="text-muted" style="margin:10px 0 0">On site since <?= e(date('D j M H:i', strtotime((string)$vpCard['on_site']['time_in']))) ?> — sign them out in the log below.</p>
  <?php endif; ?>
  <div class="gt-pass__act">
    <?php if (!$vpCard['on_site'] && $lvl !== 'stop'): ?>
    <form method="POST">
      <?= csrf_field() ?><input type="hidden" name="action" value="visitor_in"><input type="hidden" name="vp" value="<?= e($vpTok) ?>">
      <?php if (!$vis): ?>
      <select name="place_id" class="inp" required><option value="">Where are they going?</option>
        <?php foreach ($vpPlaces as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e((string)$p['name']) ?></option><?php endforeach; ?></select>
      <?php endif; ?>
      <button class="btn-primary"><?= admin_icon('check', 15) ?> Let in</button>
    </form>
    <?php endif; ?>
    <?php if ($resignUrl !== ''): ?>
    <a class="btn-outline btn-sm" href="<?= e($resignUrl) ?>" data-no-shell>Open their pass on this tablet</a>
    <?php endif; ?>
    <a class="btn-outline btn-sm" href="/admin/gate.php">Close</a>
  </div>
  <?php else: ?>
  <div class="gt-pass__act"><a class="btn-outline btn-sm" href="/admin/gate.php">Close</a></div>
  <?php endif; ?>
</div>
