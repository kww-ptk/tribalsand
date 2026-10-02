<?php
/**
 * "Access by role" tab of admin/staff.php (owner only — the page is require_owner()).
 * Pick a role, see every admin section with its switch: the default comes from the
 * code (admin_nav_flags()), a changed switch is marked and can be put back. Saved
 * by the access_save / access_reset actions in admin/staff.php. See includes/access.php.
 *
 * Expects: $accessRoles (key => label), $accessRole (selected key).
 */
$__env      = access_env();
$__sections = access_sections();
$__defaults = access_defaults($accessRole, $__env);
$__matrix   = access_matrix();
$__mine     = $__matrix[$accessRole] ?? [];
$__groups   = [];
foreach ($__sections as $k => $s) $__groups[$s['group']][$k] = $s;
?>
<style>
.ax-intro{margin:0 0 16px;font-size:13px;color:var(--muted);max-width:820px;line-height:1.6}
.ax-roles{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 18px}
.ax-roles a{padding:7px 14px;border:1px solid var(--border);border-radius:99px;font-size:13px;font-weight:600;color:var(--text,#1a2730);text-decoration:none;background:var(--white,#fff)}
.ax-roles a.is-on{background:var(--brand,#1E5C6B);border-color:var(--brand,#1E5C6B);color:#fff}
.ax-roles a .ax-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#e07b39;margin-left:6px;vertical-align:1px}
.ax-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,420px),1fr));gap:14px}
.ax-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid var(--border)}
.ax-row:last-child{border-bottom:0}
.ax-name{font-size:13.5px;font-weight:600}
.ax-sub{font-size:11.5px;color:var(--muted);margin-top:2px}
.ax-changed{display:inline-block;font-size:10.5px;font-weight:700;color:#b45309;background:#fef3c7;border-radius:99px;padding:1px 7px;margin-left:6px}
.ax-lock{font-size:11.5px;color:var(--muted);white-space:nowrap;display:inline-flex;align-items:center;gap:5px}
.ax-foot{position:sticky;bottom:0;background:var(--bg,#f6f4f0);padding:12px 0;margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;border-top:1px solid var(--border)}
</style>

<p class="ax-intro">Choose which sections each kind of account can open. Every switch starts at what the system gives that role today. Switching a section <strong>off</strong> removes it from their menu and blocks its pages; switching it <strong>on</strong> adds it, still limited to the properties assigned to the person, and anything inside that page reserved for the owner stays owner-only. The owner always has everything. Changed switches are marked.</p>

<nav class="ax-roles" aria-label="Role">
  <?php foreach ($accessRoles as $rk => $rl): ?>
  <a href="/admin/staff.php?tab=access&amp;role=<?= e(urlencode($rk)) ?>" data-shell-link class="<?= $rk === $accessRole ? 'is-on' : '' ?>"><?= e($rl) ?><?php if (!empty($__matrix[$rk])): ?><span class="ax-dot" title="Changed from the default"></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>

<form method="POST" action="/admin/staff.php?tab=access&amp;role=<?= e(urlencode($accessRole)) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="access_save">
  <input type="hidden" name="access_role" value="<?= e($accessRole) ?>">
  <div class="ax-grid">
    <?php foreach ($__groups as $gName => $gSections): ?>
    <div class="card">
      <div class="card__head"><span class="card__title"><?= e($gName) ?></span></div>
      <div class="card__body" style="padding:4px 18px">
        <?php foreach ($gSections as $k => $s):
              $def = (bool)($__defaults[$k] ?? false);
              $cfg = access_configurable($s, $accessRole);
              $on  = access_resolve($__matrix, $accessRole, $s, $def);
              $changed = $cfg && array_key_exists($k, $__mine); ?>
        <div class="ax-row">
          <div>
            <div class="ax-name"><?= e($s['label']) ?><?php if ($changed): ?><span class="ax-changed">Changed</span><?php endif; ?></div>
            <div class="ax-sub"><?php
              if ($s['kind'] === 'owner') echo 'Owner only';
              elseif ($s['kind'] === 'auto') echo e($s['note']);
              elseif ($s['kind'] === 'managers' && !$cfg) echo 'Managers only — it shows nothing to other roles';
              else echo 'Default: ' . ($def ? 'on' : 'off');
            ?></div>
          </div>
          <?php if ($cfg): ?>
          <label class="toggle" data-tip="<?= $on ? 'Can open this' : 'Cannot open this' ?>">
            <input type="hidden" name="sec[<?= e($k) ?>]" value="0">
            <input type="checkbox" name="sec[<?= e($k) ?>]" value="1" <?= $on ? 'checked' : '' ?> aria-label="<?= e($s['label']) ?>">
            <span class="toggle-slider"></span>
          </label>
          <?php else: ?>
          <span class="ax-lock"><?= admin_icon('lock', 13) ?> <?= $on ? 'On' : 'Off' ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="ax-foot">
    <button type="submit" class="btn-primary"><?= admin_icon('check', 15) ?> Save access for <?= e($accessRoles[$accessRole] ?? $accessRole) ?></button>
    <?php if ($__mine): ?>
    <button type="submit" name="action" value="access_reset" class="btn-outline" data-confirm="Put every section for <?= e($accessRoles[$accessRole] ?? $accessRole) ?> back to the default?">Reset to defaults</button>
    <?php endif; ?>
    <span class="text-muted" style="font-size:12px">Takes effect on their next page load.</span>
  </div>
</form>
