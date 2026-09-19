<?php
declare(strict_types=1);
/**
 * Admin: Travel-agency partners (owner-only). Curate the "Our Partners" logo
 * ticker on /for-agents.php — add a partner with their logo + website, publish
 * or hide, reorder, delete. Public visitors register interest through the
 * "Become Our Partner" form on that page; the owner approves and adds them here.
 *
 * Logo upload mirrors the venue gallery (GD resize + storage_put), but keeps PNG
 * alpha so transparent logos stay transparent. All mutations are PRG + CSRF.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/partners.php';
require_once __DIR__ . '/../includes/icons.php';
require_login();
require_owner();

$pageTitle  = 'Partners';
$activeMenu = 'partners';

$supported = partners_supported();

// partner_store_logo() lives in includes/partners.php — the public "Become Our
// Partner" form stores logos through the same validated, GD re-encoded path.

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $flash  = ['type' => 'success', 'msg' => ''];
    try {
        if ($action === 'add') {
            $name    = trim((string)($_POST['name'] ?? ''));
            $website = partner_website_href($_POST['website_url'] ?? '');
            if ($name === '') {
                $flash = ['type' => 'error', 'msg' => 'Give the partner a name.'];
            } else {
                $logo = partner_store_logo($_FILES['logo'] ?? [], $err);
                if ($logo === false) {
                    $flash = ['type' => 'error', 'msg' => $err ?? 'Could not save the logo.'];
                } else {
                    $ord = (int) db_query('SELECT COALESCE(MAX(sort_order),0)+1 FROM agency_partners')->fetchColumn();
                    db_query(
                        'INSERT INTO agency_partners (name, website_url, logo_key, sort_order) VALUES (:n,:w,:l,:o)',
                        [':n' => $name, ':w' => $website, ':l' => $logo, ':o' => $ord]
                    );
                    audit_log('partner.create', 'partner', (int)db()->lastInsertId(), $name);
                    $flash['msg'] = "Partner “{$name}” added.";
                }
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $p  = fetch_partner($id);
            if (!$p) { $flash = ['type' => 'error', 'msg' => 'Partner not found.']; }
            else {
                $name    = trim((string)($_POST['name'] ?? '')) ?: $p['name'];
                $website = partner_website_href($_POST['website_url'] ?? '');
                $logo    = partner_store_logo($_FILES['logo'] ?? [], $err);
                if ($logo === false) {
                    $flash = ['type' => 'error', 'msg' => $err ?? 'Could not save the logo.'];
                } else {
                    if ($logo !== '' && !empty($p['logo_key'])) storage_delete($p['logo_key']);
                    $newLogo = $logo !== '' ? $logo : $p['logo_key'];
                    db_query(
                        'UPDATE agency_partners SET name=:n, website_url=:w, logo_key=:l WHERE id=:id',
                        [':n' => $name, ':w' => $website, ':l' => $newLogo, ':id' => $id]
                    );
                    audit_log('partner.update', 'partner', $id, $name);
                    $flash['msg'] = 'Partner updated.';
                }
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            db_query('UPDATE agency_partners SET is_published = NOT is_published WHERE id = :id', [':id' => $id]);
            $flash['msg'] = 'Visibility changed.';
        } elseif ($action === 'move') {
            // Swap sort_order with the neighbour in the given direction.
            $id  = (int)($_POST['id'] ?? 0);
            $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
            $cur = fetch_partner($id);
            if ($cur) {
                $op  = $dir === 'up' ? '<' : '>';
                $ord = $dir === 'up' ? 'DESC' : 'ASC';
                $nb  = db_query(
                    "SELECT id, sort_order FROM agency_partners WHERE sort_order {$op} :o ORDER BY sort_order {$ord}, id {$ord} LIMIT 1",
                    [':o' => (int)$cur['sort_order']]
                )->fetch();
                if ($nb) {
                    db_query('UPDATE agency_partners SET sort_order=:o WHERE id=:id', [':o' => (int)$nb['sort_order'], ':id' => $id]);
                    db_query('UPDATE agency_partners SET sort_order=:o WHERE id=:id', [':o' => (int)$cur['sort_order'], ':id' => (int)$nb['id']]);
                }
            }
            $flash['msg'] = 'Order updated.';
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $p  = fetch_partner($id);
            if ($p) {
                if (!empty($p['logo_key'])) storage_delete($p['logo_key']);
                db_query('DELETE FROM agency_partners WHERE id = :id', [':id' => $id]);
                audit_log('partner.delete', 'partner', $id, (string)$p['name']);
            }
            $flash['msg'] = 'Partner removed.';
        }
    } catch (PDOException $e) {
        // uq_agency_partners_name — one row per agency, so the self-registration
        // path can upsert instead of stacking duplicates.
        $flash = ($e->getCode() === '23505')
            ? ['type' => 'error', 'msg' => 'There is already a partner with that name.']
            : ['type' => 'error', 'msg' => 'Could not complete that action.'];
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => 'Could not complete that action.'];
    }
    $_SESSION['hold_flash'] = $flash;
    header('Location: /admin/partners.php');
    exit;
}

$flash = null;
if (!empty($_SESSION['hold_flash'])) { $flash = $_SESSION['hold_flash']; unset($_SESSION['hold_flash']); }

$partners = fetch_all_partners();
$pending  = count(array_filter($partners, 'partner_is_pending'));

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <div>
    <h1>Partners</h1>
    <p class="text-muted" style="margin:4px 0 0;font-size:13px">Travel-agency partner logos shown in the “Our Partners” ticker on <a href="/for-agents.php" target="_blank">For Agents</a>. Each published logo links to the partner’s website. Agencies that register themselves land here <strong>hidden</strong> — publishing one is the approval.</p>
  </div>
</div>

<?php if ($pending): ?>
<div class="alert alert--info"><?= (int)$pending ?> agenc<?= $pending === 1 ? 'y has' : 'ies have' ?> registered and <?= $pending === 1 ? 'is' : 'are' ?> waiting for review. Check the logo and website, then publish to show <?= $pending === 1 ? 'it' : 'them' ?> on the site.</div>
<?php endif; ?>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? '') ?></div><?php endif; ?>

<?php if (!$supported): ?>
<div class="card"><div class="card__body"><p class="text-muted" style="margin:0">Partners aren’t enabled yet. Run the <code>add_agency_partners.sql</code> migration.</p></div></div>
<?php else: ?>

<div class="card" style="margin-bottom:16px">
  <div class="card__head"><span class="card__title">Add a partner</span></div>
  <div class="card__body card__body--pad">
    <form method="POST" action="/admin/partners.php" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end">
        <div class="field"><label for="pName">Agency name</label><input id="pName" name="name" class="inp" required placeholder="e.g. Safari Travel Co." style="width:100%"></div>
        <div class="field"><label for="pWeb">Website URL</label><input id="pWeb" name="website_url" class="inp" placeholder="https://agency.com" style="width:100%"></div>
        <div class="field"><label for="pLogo">Logo (PNG/JPG/WebP, max 2MB)</label><input id="pLogo" type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="inp" required style="width:100%"></div>
      </div>
      <button type="submit" class="btn-primary btn-sm" style="margin-top:10px">Add partner</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__head"><span class="card__title">Partners</span></div>
  <div class="card__body" style="padding:0">
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Logo</th><th>Name</th><th>Website</th><th>Status</th><th style="width:230px">Actions</th></tr></thead>
      <tbody>
        <?php if (!$partners): ?>
        <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--muted)">No partners yet.</td></tr>
        <?php else: foreach ($partners as $p): $id = (int)$p['id']; $logo = partner_logo_url($p['logo_key']); $href = partner_website_href($p['website_url']); $isPending = partner_is_pending($p); ?>
        <tr>
          <td><?php if ($logo): ?><img src="<?= e($logo) ?>" alt="<?= e($p['name']) ?>" style="max-height:38px;max-width:120px;object-fit:contain;background:#f4f0e8;padding:4px;border-radius:4px"><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
          <td>
            <form method="POST" action="/admin/partners.php" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:6px;max-width:340px">
              <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>">
              <input name="name" class="inp" value="<?= e($p['name']) ?>" style="width:100%">
              <input name="website_url" class="inp" value="<?= e($p['website_url']) ?>" placeholder="https://…" style="width:100%">
              <label class="text-muted" style="font-size:12px">Replace logo <input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
              <button class="btn-icon btn-icon--outline" title="Save changes" aria-label="Save changes" style="align-self:flex-start"><?= admin_icon('check') ?> Save</button>
            </form>
            <?php if (!empty($p['contact_email'])): ?><span class="text-muted" style="font-size:12px">Registered by <?= e($p['contact_email']) ?></span><?php endif; ?>
          </td>
          <td><?php if ($href): ?><a href="<?= e($href) ?>" target="_blank" rel="noopener" style="font-size:13px"><?= e($p['website_url']) ?></a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
          <td>
            <?php if ($isPending): ?><span class="badge badge--orange">Pending review</span>
            <?php else: ?><span class="badge <?= $p['is_published'] ? 'badge--green' : 'badge--grey' ?>"><?= $p['is_published'] ? 'Published' : 'Hidden' ?></span><?php endif; ?>
            <?php if (!empty($p['submission_id'])): ?><br><a href="/admin/submission-view.php?id=<?= (int)$p['submission_id'] ?>" class="text-muted" style="font-size:12px">View enquiry</a><?php endif; ?>
          </td>
          <td>
            <div class="row-actions" style="gap:6px">
              <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="dir" value="up"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn-icon btn-icon--outline" title="Move up" aria-label="Move up">↑</button></form>
              <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="dir" value="down"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn-icon btn-icon--outline" title="Move down" aria-label="Move down">↓</button></form>
              <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn-icon <?= $isPending ? 'btn-icon--primary' : 'btn-icon--outline' ?>" title="<?= $p['is_published'] ? 'Hide' : ($isPending ? 'Approve & publish' : 'Publish') ?>" aria-label="Toggle visibility"><?= admin_icon($p['is_published'] ? 'x' : 'check') ?></button></form>
              <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn-icon btn-icon--danger" title="Delete" aria-label="Delete" data-confirm="Delete <?= e($p['name']) ?>?"><?= admin_icon('trash') ?></button></form>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_layout_end.php'; ?>
