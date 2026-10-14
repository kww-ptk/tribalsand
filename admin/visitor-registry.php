<?php
/**
 * Gate → Visitors: registered visitors this account covers. Search, a side drawer
 * with details / ID photo / group / visits / signed record, and Remove (owner +
 * manager, typed DELETE): the pass stops working and the ID photo is deleted;
 * the signed record and the gate log stay.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/checkin.php';   // waiver_version(), checkin_id_type_label()
require_once __DIR__ . '/../includes/confirm.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/admin-pagination.php';
require_once __DIR__ . '/../includes/visitor-store.php';
require_login();
require_gate();

$pageTitle  = 'Visitors';
$activeMenu = 'gate';
$venueIds   = admin_venue_ids();                       // null = owner (all)
$me         = (int)($_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'remove'
        && (is_owner() || is_manager())
        && visitor_in_scope($id, $venueIds, $me)
        && typed_confirmation_ok('DELETE')
        && visitor_remove($id)) {
        $_SESSION['hold_flash'] = ['type' => 'success', 'msg' => 'Visitor removed. Their pass no longer works.'];
    } else {
        $_SESSION['hold_flash'] = ['type' => 'error', 'msg' => 'Nothing was removed.'];
    }
    header('Location: /admin/visitor-registry.php');
    exit;
}

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

// ── Side drawer: one visitor (the list opens ?id=N&drawer=1) ──
if (!empty($_GET['drawer'])) {
    $id = (int)($_GET['id'] ?? 0);
    $v  = ($id > 0 && visitor_in_scope($id, $venueIds, $me)) ? visitor_find($id) : null;
    if (!$v) { http_response_code(404); exit('Not found'); }
    $g    = visitor_group($id);
    $hist = visitor_visit_history($id, $venueIds);
    $cur  = visitor_waiver_current($v, waiver_version(visitor_waiver_text()));
    ?>
    <div class="adrawer__head">
      <h2><?= e((string)$v['full_name']) ?></h2>
      <div class="badges">
        <?= $cur ? '<span class="badge badge--green">Signed</span>'
                 : (visitor_signed($v) ? '<span class="badge badge--orange">Terms changed — must sign again</span>' : '<span class="badge badge--grey">Not signed</span>') ?>
      </div>
    </div>
    <div class="adrawer__body">
      <div class="adrawer__sec">
        <dl class="adrawer__kv">
          <dt><?= e(checkin_id_type_label((string)$v['id_type'])) ?></dt><dd><?= e((string)(($v['id_number'] ?? '') ?: '—')) ?></dd>
          <dt>Nationality</dt><dd><?= e((string)(($v['nationality'] ?? '') ?: '—')) ?></dd>
          <dt>Phone</dt><dd><?= e((string)(($v['phone'] ?? '') ?: '—')) ?></dd>
          <dt>Email</dt><dd><?= e((string)(($v['email'] ?? '') ?: '—')) ?></dd>
          <dt>Registered</dt><dd><?= e(date('j M Y H:i', strtotime((string)$v['created_at']))) ?> · <?= e((string)$v['created_via']) ?></dd>
          <?php if (visitor_signed($v)): ?>
          <dt>Signed record</dt><dd><a href="/record.php?vid=<?= $id ?>" target="_blank" rel="noopener" data-no-shell><?= e((string)$v['waiver_reference']) ?></a></dd>
          <?php endif; ?>
        </dl>
      </div>
      <?php if (!empty($v['id_photo_key'])): ?>
      <div class="adrawer__sec"><h3>ID photo</h3>
        <img src="/admin/visitor-file.php?id=<?= $id ?>" alt="ID photo of <?= e((string)$v['full_name']) ?>" style="max-width:100%;border-radius:8px">
      </div>
      <?php endif; ?>
      <?php if ($g['adults'] || $g['children']): ?>
      <div class="adrawer__sec"><h3>Group</h3><ul style="margin:0;padding-left:18px">
        <?php foreach ($g['adults'] as $a): ?><li><?= e((string)$a['full_name']) ?> — <?= visitor_signed($a) ? 'signed' : 'not signed' ?></li><?php endforeach; ?>
        <?php foreach ($g['children'] as $c): ?><li><?= e((string)$c['full_name']) ?> (child, <?= (int)$c['age'] ?>)</li><?php endforeach; ?>
      </ul></div>
      <?php endif; ?>
      <div class="adrawer__sec"><h3>Visits</h3>
        <?php if (!$hist): ?><p class="text-muted" style="margin:0">No visits yet.</p>
        <?php else: ?><ul style="margin:0;padding-left:18px">
          <?php foreach ($hist as $h): ?><li><?= e(date('j M Y H:i', strtotime((string)$h['time_in']))) ?> · <?= e((string)(($h['visiting'] ?? '') ?: ($h['venue_name'] ?? ''))) ?> · <?= (int)$h['people'] ?> <?= (int)$h['people'] === 1 ? 'person' : 'people' ?><?= $h['time_out'] ? '' : ' · on site' ?></li><?php endforeach; ?>
        </ul><?php endif; ?>
      </div>
    </div>
    <?php if (is_owner() || is_manager()): ?>
    <div class="adrawer__foot">
      <form method="POST" action="/admin/visitor-registry.php" style="margin:0"><?= csrf_field() ?>
        <input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn-danger btn-sm" <?= danger_confirm_attrs('Remove ' . (string)$v['full_name'] . '? Their pass stops working and their ID photo is deleted. The signed record stays.', 'Remove visitor', 'Remove', 'DELETE') ?>>Remove visitor</button>
      </form>
    </div>
    <?php endif;
    exit;
}

$pg   = paginate_params(25);
$list = visitor_registry_list($venueIds, $me, $pg['q'], $pg['per'], 0);
$meta = paginate_meta($list['total'], $pg['page'], $pg['per']);
$list = visitor_registry_list($venueIds, $me, $pg['q'], $meta['per'], $meta['offset']);
$ver  = waiver_version(visitor_waiver_text());

ob_start(); ?>
<div class="card"><div class="card__body" style="padding:0">
  <?php if (!$list['rows']): dt_empty($pg['q'] !== '' ? 'No visitors match your search.' : 'No registered visitors yet.'); else: ?>
  <div class="table-wrap"><table class="data-table" data-help="vr-list">
    <thead><tr><th>Visitor</th><th>Phone</th><th>Last place</th><th>Last visit</th><th>Waiver</th></tr></thead>
    <tbody>
    <?php foreach ($list['rows'] as $r): $dUrl = '/admin/visitor-registry.php?id=' . (int)$r['id']; ?>
      <tr data-drawer="<?= e($dUrl) ?>" data-drawer-key="visitor-<?= (int)$r['id'] ?>" style="cursor:pointer">
        <td><a href="<?= e($dUrl) ?>" data-drawer="<?= e($dUrl) ?>" data-drawer-key="visitor-<?= (int)$r['id'] ?>"><strong><?= e((string)$r['full_name']) ?></strong></a></td>
        <td><?= e((string)(($r['phone'] ?? '') ?: '—')) ?></td>
        <td><?= e((string)(($r['last_place'] ?? '') ?: '—')) ?></td>
        <td class="text-muted"><?= !empty($r['last_visit']) ? e(date('j M Y', strtotime((string)$r['last_visit']))) : '—' ?></td>
        <td><?= visitor_waiver_current($r, $ver) ? '<span class="badge badge--green">Signed</span>' : (visitor_signed($r) ? '<span class="badge badge--orange">Out of date</span>' : '<span class="badge badge--grey">Not signed</span>') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; dt_pager($meta); ?>
</div></div>
<?php $dtBody = ob_get_clean();
if ($pg['ajax']) { echo $dtBody; exit; }
include __DIR__ . '/_layout.php';
?>
<div class="page-header"><h1>Visitors</h1></div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? '') ?></div><?php endif; ?>
<?php if (!visitor_reg_supported()): ?>
<div class="alert alert--error">Run add_visitor_registration.sql (Settings → Your account → Migrations) to turn on visitor registration.</div>
<?php else:
  dt_toolbar(['per' => $meta['per'], 'placeholder' => 'Search name, phone, email or reference']); ?>
<div class="dt-body" data-dt-body><?= $dtBody ?></div>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
