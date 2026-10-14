<?php
/**
 * Support — the guide library (design B "Reader", Oct 2026). The list of guides on
 * the left, the chosen guide open on the right page by page, with Download PDF,
 * Version history and (owner) New version / Edit / Remove. The owner imports PDFs
 * from "Import a guide"; a guide shows only to the roles it is for. Guides meant
 * for guests or travel agents get a share link (/guide).
 *
 * Everyone may open it (ACCESS_AUTOMATIC). Logic: includes/support-docs.php ·
 * files: admin/support-file.php · test: php tests/support_docs_logic.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/support-docs.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/confirm.php';
require_login();

$isOwner = is_owner();
$viewer  = support_viewer_key();
$ready   = support_docs_supported();
$meId    = (int)($_SESSION['admin_id'] ?? 0);

function support_back(string $msg, string $type, int $docId = 0): never {
    $_SESSION['support_flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: /admin/support.php' . ($docId ? '?doc=' . $docId : '')); exit;
}
function support_meta_from_post(): array {
    return ['title' => (string)($_POST['title'] ?? ''), 'subtitle' => (string)($_POST['subtitle'] ?? ''),
            'summary' => (string)($_POST['summary'] ?? ''), 'audience' => (array)($_POST['audience'] ?? [])];
}

// ── Owner actions ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$isOwner) support_back('Only the owner can change the guides.', 'error');
    if (!$ready) support_back('Run the add_support_docs migration first (Settings → Your account → Migrations).', 'error');
    $action = (string)($_POST['action'] ?? '');
    $docId  = (int)($_POST['doc_id'] ?? 0);

    if ($action === 'import') {
        $asVersion = ($_POST['kind'] ?? 'new') === 'version' && $docId > 0;
        $r = support_import($_FILES['pdf'] ?? [], $asVersion ? $docId : null, support_meta_from_post(), (string)($_POST['note'] ?? ''), $meId);
        if (!$r['ok']) support_back($r['error'], 'error', $asVersion ? $docId : 0);
        audit_log($asVersion ? 'support.version' : 'support.import', 'support_doc', $r['doc_id'], 'v' . $r['version']);
        $d = support_doc_get($r['doc_id']);
        support_back($asVersion ? ($d['title'] . ' is now version ' . $r['version'] . '. The old version is in Version history.')
                                : ($d['title'] . ' imported — ' . support_audience_label($d['audience']) . ' can read it.'), 'success', $r['doc_id']);
    }
    if ($action === 'edit') {
        if (!support_doc_update($docId, support_meta_from_post())) support_back('Give the guide a title.', 'error', $docId);
        audit_log('support.edit', 'support_doc', $docId, '');
        support_back('Guide details saved.', 'success', $docId);
    }
    if ($action === 'remove') {
        support_doc_set_active($docId, false);
        audit_log('support.remove', 'support_doc', $docId, '');
        support_back('Guide removed. You can put it back from Removed guides.', 'success');
    }
    if ($action === 'restore') {
        support_doc_set_active($docId, true);
        audit_log('support.restore', 'support_doc', $docId, '');
        support_back('Guide restored.', 'success', $docId);
    }
    support_back('Nothing to do.', 'error');
}

$flash = $_SESSION['support_flash'] ?? null;
unset($_SESSION['support_flash']);

$docs    = $ready ? support_docs_list($viewer) : [];
$removed = ($ready && $isOwner) ? support_docs_list('owner', true) : [];
$want    = (int)($_GET['doc'] ?? 0);
$cur     = null;
foreach (array_merge($docs, $removed) as $d) if ((int)$d['id'] === $want) { $cur = $d; break; }
$cur   ??= $docs[0] ?? null;
$versions = $cur ? support_doc_versions((int)$cur['id']) : [];
// An older version picked in Version history (?v=) — else the current one.
$shown = $versions[0] ?? null;
foreach ($versions as $v) if ((int)$v['id'] === (int)($_GET['v'] ?? 0)) { $shown = $v; break; }
$isOld   = $shown && $versions && (int)$shown['id'] !== (int)$versions[0]['id'];
$shareUrl = ($cur && ($isOwner || is_manager() || is_reception())) ? support_share_url($cur) : '';
$groups  = ['team' => [], 'guests' => []];
foreach ($docs as $d) $groups[support_is_shareable($d['audience']) ? 'guests' : 'team'][] = $d;
$fileUrl = fn(array $v, bool $dl = false) => '/admin/support-file.php?v=' . (int)$v['id'] . ($dl ? '&download=1' : '');

$pageTitle  = 'Support';
$activeMenu = 'support';
include __DIR__ . '/_layout.php';

/** Small cover drawn from the guide's own title + subtitle. */
$cover = function (array $d, bool $small = true): string {
    return '<span class="sp-cover' . ($small ? ' sp-cover--sm' : '') . '" aria-hidden="true"><b>' . e((string)$d['title']) . '</b>'
         . (trim((string)($d['subtitle'] ?? '')) !== '' ? '<i>' . e((string)$d['subtitle']) . '</i>' : '') . '</span>';
};
$audienceChips = function (array $on): string {
    $h = '';
    foreach (SUPPORT_AUDIENCES as $k => $label) {
        $h .= '<label class="optchip"><input type="checkbox" name="audience[]" value="' . e($k) . '"' . (in_array($k, $on, true) ? ' checked' : '') . '> ' . e($label) . '</label>';
    }
    return $h;
};
?>
<style>
.sp-head { display:flex; flex-wrap:wrap; align-items:flex-end; gap:6px 16px; margin:0 0 14px; }
.sp-head h1 { margin:0; font-size:22px; }
.sp-head p { margin:2px 0 0; color:var(--muted); font-size:13.5px; }
.sp { display:grid; grid-template-columns:300px minmax(0,1fr); background:var(--white); border:1px solid var(--border); border-radius:14px; overflow:hidden; min-height:640px; }
.sp-list { border-right:1px solid var(--border); padding:14px; display:flex; flex-direction:column; gap:6px; min-width:0; }
.sp-drop { display:flex; align-items:center; justify-content:center; gap:8px; border:1.5px dashed #cbbfb4; border-radius:10px; padding:11px; font-size:13px; font-weight:600; color:var(--muted); background:var(--bg); cursor:pointer; width:100%; }
.sp-drop:hover { border-color:var(--brand); color:var(--brand); }
.sp-search { position:relative; margin:4px 0 2px; }
.sp-search .inp { width:100%; padding-left:34px; box-sizing:border-box; }
.sp-search svg { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--muted); }
.sp-g { font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); margin:12px 4px 2px; }
.sp-item { display:flex; gap:10px; align-items:center; padding:8px; border-radius:10px; border:1.5px solid transparent; text-decoration:none; color:inherit; }
.sp-item:hover { background:var(--bg); }
.sp-item.is-on { border-color:var(--brand); background:#e6f0f1; }
.sp-item b { display:block; font-size:13px; }
.sp-item small { font-size:11.5px; color:var(--muted); }
.sp-item .sp-new { color:#b45309; font-weight:600; }
.sp-cover { background:#12313b; color:#fff; border-radius:5px; padding:12px 10px; display:flex; flex-direction:column; justify-content:center; gap:3px; aspect-ratio:4/3; overflow:hidden; }
.sp-cover b { font-family:Georgia,'Times New Roman',serif; font-weight:600; font-size:15px; line-height:1.1; }
.sp-cover i { font-family:Georgia,'Times New Roman',serif; color:var(--accent); font-size:13px; line-height:1.15; }
.sp-cover--sm { width:56px; flex:none; padding:5px; gap:1px; }
.sp-cover--sm b { font-size:7.5px; } .sp-cover--sm i { font-size:6.5px; }
.sp-view { display:flex; flex-direction:column; min-width:0; background:#ece7dd; }
.sp-bar { background:var(--white); border-bottom:1px solid var(--border); padding:14px 18px; display:flex; flex-wrap:wrap; gap:10px 12px; align-items:center; }
.sp-bar h2 { margin:0; font-size:17px; }
.sp-bar .sp-meta { font-size:12.5px; color:var(--muted); }
.sp-bar .sp-sp { flex:1; }
.sp-acts { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.sp-old { background:#fdf3e4; color:#b45309; border-bottom:1px solid #f3dfbf; padding:8px 18px; font-size:13px; }
.sp-old a { color:inherit; font-weight:600; }
/* Guide viewer, design B "Chapters" (admin/assets/support-reader.js) */
.sp-rd { flex:1; display:flex; flex-direction:column; min-width:0; min-height:0; background:#f3efe7; }
/* The open guide's chapters, under its item in the guide list */
.sp-rd-toc { margin:2px 0 8px 12px; padding:6px 0 6px 10px; border-left:2px solid #e6f0f1; display:flex; flex-direction:column; }
.sp-rd__k { font-size:11px; letter-spacing:.1em; text-transform:uppercase; color:var(--muted); margin:2px 8px 6px; }
.sp-rd__toc { overflow:auto; max-height:52vh; display:flex; flex-direction:column; gap:1px; }
.sp-rd__toc button { display:flex; gap:8px; align-items:baseline; width:100%; text-align:left; border:0; background:none; padding:7px 8px; border-radius:8px; font-size:13px; color:var(--text); cursor:pointer; line-height:1.35; }
.sp-rd__toc button:hover { background:var(--bg); }
.sp-rd__toc button.is-on { background:#e6f0f1; color:var(--brand); font-weight:600; }
.sp-rd__num { color:var(--muted); font-size:12px; min-width:16px; font-variant-numeric:tabular-nums; }
.sp-rd__t { flex:1; min-width:0; }
.sp-rd__toc em { font-style:normal; font-size:11.5px; color:var(--muted); white-space:nowrap; }
.sp-rd__done { height:6px; border-radius:999px; background:var(--bg); margin:12px 8px 4px; overflow:hidden; flex:none; }
.sp-rd__done i { display:block; height:100%; width:0; background:var(--brand); transition:width .25s; }
.sp-rd__donetxt { font-size:12px; color:var(--muted); margin:0 8px; }
.sp-rd__main { display:flex; flex-direction:column; min-width:0; min-height:0; }
.sp-rd__crumbs { display:flex; align-items:center; gap:10px; padding:9px 16px; background:var(--white); border-bottom:1px solid var(--border); font-size:13px; color:var(--muted); min-height:46px; }
.sp-rd__crumbs b { color:var(--text); font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; }
.sp-rd__crumbs .sp-sp { flex:1; }
.sp-rd__pn { white-space:nowrap; font-variant-numeric:tabular-nums; }
.sp-rd__tools { display:inline-flex; align-items:center; gap:2px; border:1px solid var(--border); border-radius:9px; padding:2px; flex:none; }
.sp-rd__tool { width:30px; height:30px; border:0; border-radius:7px; background:none; color:var(--brand); display:inline-grid; place-items:center; cursor:pointer; text-decoration:none; }
.sp-rd__tool:hover { background:var(--bg); }
.sp-rd__zl { font-size:12px; min-width:40px; text-align:center; color:var(--text); font-variant-numeric:tabular-nums; }
.sp-rd__contents { display:none; }
.sp-rd__body { position:relative; flex:1; min-height:0; display:grid; grid-template-columns:56px minmax(0,1fr) 56px; }
.sp-rd__stage { overflow:auto; height:max(460px, calc(100vh - 300px)); padding:18px 0 24px; text-align:center; }
.sp-rd__page { display:inline-block; text-align:left; }
.sp-rd__canvas { display:block; background:#fff; border-radius:4px; box-shadow:0 14px 34px rgba(16,47,58,.14); animation:spRdFade .22s ease; }
.sp-rd__canvas.is-turn-next { animation:spRdNext .26s ease; } .sp-rd__canvas.is-turn-prev { animation:spRdPrev .26s ease; }
@keyframes spRdFade { from { opacity:0; } to { opacity:1; } }
@keyframes spRdNext { from { opacity:0; transform:translateX(20px); } to { opacity:1; transform:none; } }
@keyframes spRdPrev { from { opacity:0; transform:translateX(-20px); } to { opacity:1; transform:none; } }
.sp-rd__arrow { align-self:center; justify-self:center; width:42px; height:42px; border-radius:999px; border:1.5px solid var(--border); background:var(--white); color:var(--brand); display:grid; place-items:center; cursor:pointer; }
.sp-rd__arrow:hover { border-color:var(--brand); }
.sp-rd__arrow[disabled], .sp-rd__btm button[disabled] { opacity:.35; cursor:default; }
.sp-rd__loading { position:absolute; inset:0; display:grid; place-items:center; color:var(--muted); font-size:13.5px; pointer-events:none; }
.sp-rd__loading[hidden] { display:none; }
.sp-rd__btm { display:none; }
.sp-rd__fallback { width:100%; min-height:560px; height:calc(100vh - 260px); border:0; background:#fff; grid-column:1 / -1; }
.sp-rd.is-fallback > :not(.sp-rd__fallback) { display:none; }
/* Narrow viewer (phones, small windows): chapters fold into "Contents", the page fills the width,
   Previous / Next sit in a bar under the page so they never cover text; swipe also turns. */
.sp-rd.is-narrow .sp-rd__contents { display:inline-flex; align-items:center; gap:6px; border:1px solid var(--border); background:var(--white); color:var(--brand); border-radius:9px; padding:6px 10px; font-weight:600; font-size:12.5px; cursor:pointer; flex:none; }
.sp-rd.is-narrow .sp-rd__crumbs { flex-wrap:wrap; }
.sp-rd.is-narrow .sp-rd__body { grid-template-columns:minmax(0,1fr); }
.sp-rd.is-narrow .sp-rd__arrow { display:none; }
.sp-rd.is-narrow .sp-rd__stage { height:auto; max-height:none; padding:8px 8px 4px; }
.sp-rd.is-narrow .sp-rd__btm { display:flex; gap:8px; padding:8px 8px 12px; }
.sp-rd__btm button { flex:1; display:inline-flex; align-items:center; justify-content:center; gap:6px; border:1.5px solid var(--border); background:var(--white); color:var(--brand); border-radius:10px; padding:10px; font-weight:600; font-size:14px; cursor:pointer; }
.sp-hist { position:relative; }
.sp-hist summary { list-style:none; }
.sp-hist summary::-webkit-details-marker { display:none; }
.sp-hist__menu { position:absolute; right:0; top:calc(100% + 6px); z-index:30; background:var(--white); border:1px solid var(--border); border-radius:12px; box-shadow:0 14px 34px rgba(0,0,0,.14); width:320px; max-width:80vw; padding:6px; }
.sp-v { display:flex; gap:10px; align-items:center; padding:8px; border-radius:8px; }
.sp-v:hover { background:var(--bg); }
.sp-v div { flex:1; min-width:0; }
.sp-v b { font-size:13px; } .sp-v small { display:block; color:var(--muted); font-size:11.5px; }
.sp-empty { margin:auto; text-align:center; padding:40px 20px; color:var(--muted); max-width:420px; }
.sp-empty b { display:block; color:var(--text); font-size:16px; margin-bottom:6px; }
.sp-sheet { position:fixed; top:0; right:0; bottom:0; width:min(520px,100%); z-index:950; background:var(--white); box-shadow:-20px 0 50px rgba(0,0,0,.18); display:flex; flex-direction:column; border-radius:0; margin:0; }
.sp-sheet .card__body { overflow:auto; flex:1; display:flex; flex-direction:column; gap:14px; padding:18px 22px; }
.sp-sheet .field label { display:block; font-size:13px; font-weight:600; margin-bottom:4px; }
.sp-sheet .field .inp { width:100%; box-sizing:border-box; }
.sp-sheet .hint { font-size:12px; color:var(--muted); margin-top:4px; }
.sp-chips { display:flex; flex-wrap:wrap; gap:6px; }
.sp-chips .optchip { padding:6px 12px; font-size:12.5px; }
.sp-sheet__foot { padding:14px 22px; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:10px; }
.sp-removed { margin-top:16px; }
@media (max-width:900px) {
  .sp { grid-template-columns:1fr; }
  .sp-list { border-right:0; border-bottom:1px solid var(--border); max-height:320px; overflow:auto; }
  .sp-bar { padding:12px 14px; }
  .sp-acts { width:100%; }
  .sp-acts .btn-primary { margin-left:auto; }
}
</style>

<div class="sp-head">
  <div><h1>Support</h1><p>Guides for every part of the system — read them here or download the PDF.</p></div>
</div>

<?php if ($flash): ?><div class="alert alert--<?= e($flash['type'] ?? 'success') ?> is-flash"><?= e($flash['msg'] ?? '') ?></div><?php endif; ?>

<?php if (!$ready): ?>
<div class="card"><div class="card__body"><p class="text-muted" style="margin:0">The guide library isn't set up yet — the owner needs to run the <code>add_support_docs.sql</code> migration (Settings → Your account → Migrations).</p></div></div>
<?php else: ?>

<div class="sp">
  <aside class="sp-list" data-help="support-list">
    <?php if ($isOwner): ?>
    <button type="button" class="sp-drop" data-sp-open="spImport" data-help="support-import"><?= admin_icon('upload', 15) ?> Import a guide (PDF)</button>
    <?php endif; ?>
    <?php if ($docs): ?>
    <div class="sp-search"><?= admin_icon('search', 15) ?><input type="search" class="inp" placeholder="Search guides" data-sp-search aria-label="Search guides"></div>
    <?php endif; ?>
    <?php foreach (['team' => 'For your team', 'guests' => 'For guests & partners'] as $gk => $gl): if (!$groups[$gk]) continue; ?>
    <div class="sp-g" data-sp-group><?= e($gl) ?></div>
    <?php foreach ($groups[$gk] as $d): $new = strtotime((string)$d['version_at']) > time() - 7 * 86400; ?>
    <a class="sp-item<?= $cur && (int)$cur['id'] === (int)$d['id'] ? ' is-on' : '' ?>" href="/admin/support.php?doc=<?= (int)$d['id'] ?>" data-sp-item="<?= e(mb_strtolower($d['title'] . ' ' . $d['subtitle'] . ' ' . $d['summary'])) ?>">
      <?= $cover($d) ?>
      <span><b><?= e((string)$d['title']) ?></b><small><?= e(trim((string)$d['subtitle']) !== '' ? $d['subtitle'] . ' · ' : '') ?>v<?= (int)$d['version_no'] ?><?php if ($new): ?> · <span class="sp-new">updated</span><?php endif; ?></small></span>
    </a>
    <?php if ($cur && (int)$cur['id'] === (int)$d['id'] && $shown): ?>
    <div class="sp-rd-toc" data-rd-tocwrap>
      <p class="sp-rd__k">In this guide</p>
      <div class="sp-rd__toc" data-rd-toc></div>
      <div class="sp-rd__done"><i data-rd-done></i></div>
      <div class="sp-rd__donetxt" data-rd-donetxt></div>
    </div>
    <?php endif; ?>
    <?php endforeach; endforeach; ?>
    <?php if (!$docs): ?><p class="text-muted" style="font-size:13px;margin:10px 4px">No guides yet.</p><?php endif; ?>
  </aside>

  <section class="sp-view">
    <?php if ($cur && $shown): ?>
    <div class="sp-bar">
      <div>
        <h2><?= e((string)$cur['title']) ?><?= trim((string)$cur['subtitle']) !== '' ? ' — ' . e((string)$cur['subtitle']) : '' ?></h2>
        <div class="sp-meta">Version <?= (int)$shown['version_no'] ?> · updated <?= e(date('j M Y', strtotime((string)$shown['uploaded_at']))) ?><?= $shown['pages'] ? ' · ' . (int)$shown['pages'] . ' pages' : '' ?> · <?= e(support_format_size((int)$shown['size_bytes'])) ?><?php if ($isOwner || is_manager()): ?> · for <?= e(support_audience_label($cur['audience'])) ?><?php endif; ?><?php if (empty($cur['is_active'])): ?> · <strong>removed</strong><?php endif; ?></div>
      </div>
      <span class="sp-sp"></span>
      <div class="sp-acts">
        <details class="sp-hist" data-help="support-history">
          <summary class="btn-outline btn-sm"><?= admin_icon('clock', 14) ?> Version history</summary>
          <div class="sp-hist__menu">
            <?php foreach ($versions as $i => $v): ?>
            <div class="sp-v">
              <div><b>Version <?= (int)$v['version_no'] ?><?= $i === 0 ? ' · current' : '' ?></b><small><?= e(date('j M Y', strtotime((string)$v['uploaded_at']))) ?><?= trim((string)$v['note']) !== '' ? ' — ' . e((string)$v['note']) : '' ?><?= $isOwner && !empty($v['uploader_name']) ? ' · ' . e((string)$v['uploader_name']) : '' ?></small></div>
              <?php if ($i > 0): ?><a class="btn-icon btn-icon--outline" href="/admin/support.php?doc=<?= (int)$cur['id'] ?>&amp;v=<?= (int)$v['id'] ?>" title="Read version <?= (int)$v['version_no'] ?>"><?= admin_icon('eye', 14) ?></a><?php endif; ?>
              <a class="btn-icon btn-icon--outline" href="<?= e($fileUrl($v, true)) ?>" download title="Download version <?= (int)$v['version_no'] ?>"><?= admin_icon('download', 14) ?></a>
            </div>
            <?php endforeach; ?>
          </div>
        </details>
        <?php if ($shareUrl !== ''): ?>
        <button type="button" class="btn-outline btn-sm" data-sp-copy="<?= e($shareUrl) ?>" title="A link guests or travel agents can open without signing in"><?= admin_icon('link', 14) ?> Copy share link</button>
        <?php endif; ?>
        <?php if ($isOwner): ?>
        <button type="button" class="btn-outline btn-sm" data-sp-open="spImport" data-sp-version="<?= (int)$cur['id'] ?>"><?= admin_icon('upload', 14) ?> New version</button>
        <button type="button" class="btn-outline btn-sm" data-sp-open="spEdit"><?= admin_icon('edit', 14) ?> Edit</button>
        <?php endif; ?>
        <a class="btn-primary btn-sm" href="<?= e($fileUrl($shown, true)) ?>" download data-help="support-download"><?= admin_icon('download', 14) ?> Download PDF</a>
      </div>
    </div>
    <?php if ($isOld): ?><div class="sp-old">You're reading version <?= (int)$shown['version_no'] ?>, an older version. <a href="/admin/support.php?doc=<?= (int)$cur['id'] ?>">Open the current version</a></div><?php endif; ?>
    <div class="sp-rd" data-reader data-src="<?= e($fileUrl($shown)) ?>" data-help="support-viewer">
      <div class="sp-rd__main">
        <div class="sp-rd__crumbs">
          <button type="button" class="sp-rd__contents" data-rd-contents><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg> Contents</button>
          <b data-rd-crumb></b>
          <span class="sp-sp"></span>
          <span class="sp-rd__pn" data-rd-pn></span>
          <span class="sp-rd__tools">
            <button type="button" class="sp-rd__tool" data-rd-zoom="-1" aria-label="Zoom out"><?= admin_icon('minus', 14) ?></button>
            <span class="sp-rd__zl" data-rd-zl>100%</span>
            <button type="button" class="sp-rd__tool" data-rd-zoom="1" aria-label="Zoom in"><?= admin_icon('plus', 14) ?></button>
            <button type="button" class="sp-rd__tool" data-rd-fit aria-label="Fit to width" title="Fit to width"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16M7 9l-3 3 3 3M17 9l3 3-3 3"/></svg></button>
            <a class="sp-rd__tool" href="<?= e($fileUrl($shown)) ?>" target="_blank" rel="noopener" title="Open the PDF in a new tab (to print)" aria-label="Open the PDF in a new tab"><?= admin_icon('external-link', 14) ?></a>
          </span>
        </div>
        <div class="sp-rd__body">
          <button type="button" class="sp-rd__arrow" data-rd-prev aria-label="Previous page"><?= admin_icon('chevron-left', 20) ?></button>
          <div class="sp-rd__stage" data-rd-stage><div class="sp-rd__page" data-rd-page></div></div>
          <button type="button" class="sp-rd__arrow" data-rd-next aria-label="Next page"><?= admin_icon('chevron-right', 20) ?></button>
          <div class="sp-rd__loading" data-rd-loading>Opening the guide…</div>
        </div>
        <div class="sp-rd__btm">
          <button type="button" data-rd-prev><?= admin_icon('chevron-left', 16) ?> Previous</button>
          <button type="button" data-rd-next>Next <?= admin_icon('chevron-right', 16) ?></button>
        </div>
      </div>
      <iframe class="sp-rd__fallback" data-rd-fallback data-src="<?= e($fileUrl($shown)) ?>#view=FitH" title="<?= e((string)$cur['title']) ?>" hidden></iframe>
    </div>
    <?php else: ?>
    <div class="sp-empty">
      <?php if ($isOwner): ?>
      <b>Import your first guide</b>Press <strong>Import a guide (PDF)</strong>, choose the PDF and tick who it's for. Staff then find it here to read or download.
      <?php else: ?>
      <b>No guides for you yet</b>When the owner adds a guide for your role, it appears here.
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </section>
</div>

<?php if ($isOwner && $removed): ?>
<div class="card sp-removed">
  <div class="card__head"><span class="card__title">Removed guides (<?= count($removed) ?>)</span></div>
  <div class="card__body" style="padding:0">
    <?php foreach ($removed as $d): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px 16px;border-top:1px solid var(--border)">
      <?= $cover($d) ?><div style="flex:1;min-width:0"><b><?= e((string)$d['title']) ?></b> <span class="text-muted" style="font-size:12.5px">v<?= (int)$d['version_no'] ?></span></div>
      <form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>"><button class="btn-outline btn-sm"><?= admin_icon('rotate', 14) ?> Put back</button></form>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($isOwner): ?>
<!-- Import a guide / a new version -->
<form class="card sp-sheet" id="spImport" data-inline-form method="POST" enctype="multipart/form-data" style="display:none">
  <div class="card__head"><span class="card__title" data-sp-import-title>Import a guide</span></div>
  <div class="card__body">
    <?= csrf_field() ?><input type="hidden" name="action" value="import">
    <div class="field">
      <label>PDF file</label>
      <div class="filefield">
        <label class="btn-outline btn-sm" style="cursor:pointer"><?= admin_icon('upload', 15) ?> Choose PDF<input type="file" name="pdf" accept="application/pdf,.pdf" required data-sp-file></label>
        <span class="filefield__name" data-sp-file-name>No file chosen</span>
      </div>
      <div class="hint">PDF only, up to 15 MB.</div>
    </div>
    <div class="field">
      <label>This is</label>
      <div class="sp-chips">
        <label class="optchip"><input type="radio" name="kind" value="new" checked data-sp-kind> A new guide</label>
        <?php if ($docs || $removed): ?><label class="optchip"><input type="radio" name="kind" value="version" data-sp-kind> A new version of…</label><?php endif; ?>
      </div>
      <div class="sp-chips" data-sp-of hidden style="margin-top:8px">
        <?php foreach (array_merge($docs, $removed) as $d): ?>
        <label class="optchip"><input type="radio" name="doc_id" value="<?= (int)$d['id'] ?>"> <?= e((string)$d['title']) ?> · v<?= (int)$d['version_no'] ?></label>
        <?php endforeach; ?>
      </div>
      <div class="hint" data-sp-kind-hint>It appears in the library for the people you tick below.</div>
    </div>
    <div data-sp-newonly>
      <div class="field"><label>Title</label><input class="inp" name="title" maxlength="120" placeholder="e.g. Reception Guide" data-sp-title><div class="hint">Filled in from the file name — change it if you like.</div></div>
      <div class="field" style="margin-top:14px"><label>Subtitle <span class="text-muted" style="font-weight:400">(optional)</span></label><input class="inp" name="subtitle" maxlength="120" placeholder="e.g. The Booking Desk"></div>
      <div class="field" style="margin-top:14px"><label>One-line summary <span class="text-muted" style="font-weight:400">(optional)</span></label><input class="inp" name="summary" maxlength="300" placeholder="What the guide covers"></div>
      <div class="field" style="margin-top:14px"><label>Who should see it</label>
        <div class="sp-chips"><?= $audienceChips(['manager']) ?></div>
        <div class="hint">You always see every guide. Guests and Travel agents get a share link to send them; everyone else finds it here when they sign in.</div>
      </div>
    </div>
    <div class="field"><label>What changed <span class="text-muted" style="font-weight:400">(optional)</span></label><input class="inp" name="note" maxlength="300" placeholder="e.g. Added the gate pass steps"><div class="hint">Shown in Version history.</div></div>
  </div>
  <div class="sp-sheet__foot"><button type="button" class="btn-outline btn-sm" data-sp-close>Cancel</button><button class="btn-primary btn-sm"><?= admin_icon('upload', 14) ?> Import</button></div>
</form>

<?php if ($cur): ?>
<!-- Edit the shown guide's details -->
<form class="card sp-sheet" id="spEdit" data-inline-form method="POST" style="display:none">
  <div class="card__head"><span class="card__title">Edit guide</span></div>
  <div class="card__body">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="doc_id" value="<?= (int)$cur['id'] ?>">
    <div class="field"><label>Title</label><input class="inp" name="title" maxlength="120" required value="<?= e((string)$cur['title']) ?>"></div>
    <div class="field"><label>Subtitle</label><input class="inp" name="subtitle" maxlength="120" value="<?= e((string)$cur['subtitle']) ?>"></div>
    <div class="field"><label>One-line summary</label><input class="inp" name="summary" maxlength="300" value="<?= e((string)$cur['summary']) ?>"></div>
    <div class="field"><label>Who should see it</label><div class="sp-chips"><?= $audienceChips($cur['audience']) ?></div></div>
    <div class="hint">To change the PDF itself, use <strong>New version</strong>.</div>
  </div>
  <div class="sp-sheet__foot" style="justify-content:space-between">
    <button type="submit" form="spRemove" class="btn-outline btn-sm" style="color:var(--red)" <?= danger_confirm_attrs('The guide disappears for everyone. You can put it back from Removed guides.', 'Remove this guide?', 'Remove') ?>><?= admin_icon('trash', 14) ?> Remove guide</button>
    <span style="display:flex;gap:10px"><button type="button" class="btn-outline btn-sm" data-sp-close>Cancel</button><button class="btn-primary btn-sm">Save</button></span>
  </div>
</form>
<form id="spRemove" method="POST" style="display:none"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="doc_id" value="<?= (int)$cur['id'] ?>"></form>
<?php endif; ?>
<?php endif; /* owner */ ?>
<?php endif; /* ready */ ?>

<script>
(function () {
  var root = document.querySelector('.sp');
  if (!root || root.dataset.spBound) return;
  root.dataset.spBound = '1';

  // Search the list (title, subtitle, summary).
  var q = document.querySelector('[data-sp-search]');
  if (q) q.addEventListener('input', function () {
    var t = q.value.trim().toLowerCase();
    document.querySelectorAll('[data-sp-item]').forEach(function (a) { a.hidden = t !== '' && a.getAttribute('data-sp-item').indexOf(t) < 0; });
  });

  // Side sheets (import / edit).
  function sheet(id, show) { var s = document.getElementById(id); if (s) s.style.display = show ? 'flex' : 'none'; return s; }
  function kind(k) {
    var imp = document.getElementById('spImport'); if (!imp) return;
    var r = imp.querySelector('[data-sp-kind][value="' + k + '"]'); if (r) r.checked = true;
    var ver = k === 'version';
    imp.querySelector('[data-sp-of]').hidden = !ver;
    imp.querySelector('[data-sp-newonly]').hidden = ver;
    imp.querySelector('[data-sp-title]').required = !ver;
    imp.querySelector('[data-sp-kind-hint]').textContent = ver
      ? 'Replaces the guide everyone sees; the old version stays in Version history.'
      : 'It appears in the library for the people you tick below.';
    imp.querySelector('[data-sp-import-title]').textContent = ver ? 'Import a new version' : 'Import a guide';
  }
  document.querySelectorAll('[data-sp-open]').forEach(function (b) {
    b.addEventListener('click', function () {
      var s = sheet(b.getAttribute('data-sp-open'), true);
      var of = b.getAttribute('data-sp-version');
      if (s && s.id === 'spImport') {
        kind(of ? 'version' : 'new');
        if (of) { var o = s.querySelector('input[name="doc_id"][value="' + of + '"]'); if (o) o.checked = true; }
      }
    });
  });
  document.querySelectorAll('[data-sp-close]').forEach(function (b) { b.addEventListener('click', function () { b.closest('.sp-sheet').style.display = 'none'; }); });
  document.querySelectorAll('[data-sp-kind]').forEach(function (r) { r.addEventListener('change', function () { kind(r.value); }); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') document.querySelectorAll('.sp-sheet').forEach(function (s) { s.style.display = 'none'; }); });

  // Chosen file: show its name and guess a title (same rule as support_title_from_filename()).
  var f = document.querySelector('[data-sp-file]');
  if (f) f.addEventListener('change', function () {
    var file = f.files && f.files[0];
    document.querySelector('[data-sp-file-name]').textContent = file ? file.name : 'No file chosen';
    var t = document.querySelector('[data-sp-title]');
    if (file && t && !t.value) {
      var base = file.name.replace(/\.[^.]+$/, '').replace(/\((?:v|version)?\s*\d+\)|\bv\d+\b|\bfinal\b/gi, ' ').replace(/^tribal[\s_-]*sand[\s_-]*/i, '').replace(/[\s_-]+/g, ' ').trim();
      t.value = base.split(' ').map(function (w) { return /^[A-Z0-9]{2,}$/.test(w) ? w : w.charAt(0).toUpperCase() + w.slice(1); }).join(' ') || 'Guide';
    }
  });
  var imp = document.getElementById('spImport');
  if (imp) imp.addEventListener('submit', function (e) {
    if (imp.querySelector('[data-sp-kind][value="version"]:checked') && !imp.querySelector('input[name="doc_id"]:checked')) {
      e.preventDefault(); if (window.tsToast) window.tsToast('Pick which guide this is a new version of.', 'error');
    }
  });

  // Copy the share link.
  var c = document.querySelector('[data-sp-copy]');
  if (c) c.addEventListener('click', function () {
    var url = c.getAttribute('data-sp-copy');
    (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(function () {
      if (window.tsToast) window.tsToast('Share link copied — anyone with it can open this guide.');
    }, function () { if (window.tsToast) window.tsToast('Copy this link: ' + url); });
  });

  // Close Version history when clicking elsewhere.
  document.addEventListener('click', function (e) {
    document.querySelectorAll('.sp-hist[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });
})();
</script>

<script src="/admin/assets/support-reader.js?v=<?= @filemtime(__DIR__ . '/assets/support-reader.js') ?: 1 ?>"></script>

<?php include __DIR__ . '/_layout_end.php'; ?>
