<?php
/**
 * Admin: Companies (owner-only) — the legal companies behind each property and
 * outlet: KRA PIN, VAT / eTIMS status, where money lands, invoice numbering.
 * Accounting P1 — spec docs/superpowers/specs/2026-09-27-accounting-layer-design.md.
 * Logic: includes/companies.php. Editing one company: admin/company-edit.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/companies.php';
require_once __DIR__ . '/../includes/admin-pagination.php';   // dt_empty()
require_login();
require_owner();   // legal entities, tax ids and bank accounts are owner business

$pageTitle  = 'Companies';
$activeMenu = 'companies';
$supported  = companies_supported();
$self       = '/admin/companies.php';

$flash = $_SESSION['co_flash'] ?? null; unset($_SESSION['co_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supported) {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'company_add') {
        [$d, $errors] = company_clean(['name' => $_POST['name'] ?? '', 'code' => $_POST['code'] ?? '']);
        if ($errors) {
            $_SESSION['co_flash'] = ['type' => 'error', 'msg' => implode(' ', $errors)];
            header('Location: ' . $self); exit;
        }
        try {
            $id = company_save(null, $d);
            audit_log('company.create', 'company', $id, $d['name']);
            $_SESSION['coe_flash'] = ['type' => 'success', 'msg' => "{$d['name']} added — fill in its KRA PIN, accounts and what it owns."];   // shown on the edit page
            header('Location: /admin/company-edit.php?id=' . $id); exit;
        } catch (CompanyRefusal $e) {
            $_SESSION['co_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
            header('Location: ' . $self); exit;
        }
    }
    header('Location: ' . $self); exit;
}

$companies = $supported ? company_fetch_all() : [];
$gaps      = $supported ? company_ownership_gaps() : ['venues' => [], 'outlets' => [], 'locations' => []];
$gapCount  = count($gaps['venues']) + count($gaps['outlets']) + count($gaps['locations']);

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1>Companies</h1>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$supported): ?>
  <div class="alert alert--info">Run the <code>add_companies.sql</code> migration (Admin → Migrations) to set up companies.</div>
<?php else: ?>

<p class="text-muted co-intro">Each property belongs to a legal company, and the shared shop, spa, kite school and experiences belong to their own company. Set each company's KRA PIN, VAT status, bank and M-Pesa accounts and invoice numbering here. <strong>Nothing is invoiced yet</strong> — this only records who owns what, ready for invoices, QuickBooks and eTIMS in the next phases.</p>

<?php if ($companies && $gapCount): ?>
<div class="card co-gaps">
  <div class="card__head"><span class="card__title">Still to assign</span><span class="badge badge--orange"><?= $gapCount ?></span></div>
  <div class="card__body co-gaps__body">
    <?php foreach (['venues' => 'Properties', 'outlets' => 'POS outlets', 'locations' => 'Shared stock'] as $k => $lbl): if (!$gaps[$k]) continue; ?>
    <div class="co-gaps__row">
      <span class="co-gaps__lbl"><?= e($lbl) ?></span>
      <span class="co-gaps__items"><?php foreach ($gaps[$k] as $g): ?><span class="badge badge--grey"><?= e($g['name']) ?></span> <?php endforeach; ?></span>
    </div>
    <?php endforeach; ?>
    <p class="text-muted co-hint">Open a company → <strong>Owns</strong> to tick them. An outlet at a property follows that property's company unless you set its own.</p>
  </div>
</div>
<?php endif; ?>

<?php if ($companies): ?>
<div class="card">
  <div class="table-wrap">
  <table class="data-table co-table">
    <thead><tr><th>Company</th><th>KRA PIN</th><th>Tax</th><th>Owns</th><th>Accounts</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($companies as $c): $owns = [];
        if ((int)$c['venue_count'])    $owns[] = (int)$c['venue_count'] . ' ' . ((int)$c['venue_count'] === 1 ? 'property' : 'properties');
        if ((int)$c['outlet_count'])   $owns[] = (int)$c['outlet_count'] . ' ' . ((int)$c['outlet_count'] === 1 ? 'outlet' : 'outlets');
        if ((int)$c['location_count']) $owns[] = (int)$c['location_count'] . ' stock ' . ((int)$c['location_count'] === 1 ? 'place' : 'places'); ?>
      <tr>
        <td>
          <a href="/admin/company-edit.php?id=<?= (int)$c['id'] ?>" class="co-name"><?= e($c['name']) ?></a>
          <span class="badge badge--grey co-code"><?= e($c['code']) ?></span>
          <?php if (!$c['is_active']): ?><span class="badge badge--orange">Switched off</span><?php endif; ?>
          <?php if ($c['legal_name'] !== ''): ?><div class="text-muted co-sub"><?= e($c['legal_name']) ?></div><?php endif; ?>
        </td>
        <td><?= $c['kra_pin'] !== '' ? '<code>' . e($c['kra_pin']) . '</code>' : '<span class="badge badge--orange">Not set</span>' ?></td>
        <td>
          <?= $c['vat_registered'] ? '<span class="badge badge--green">VAT</span>' : '<span class="badge badge--grey">No VAT</span>' ?>
          <?= $c['etims_enabled'] ? '<span class="badge badge--blue">eTIMS</span>' : '' ?>
        </td>
        <td><?= $owns ? e(implode(' · ', $owns)) : '<span class="text-muted">Nothing yet</span>' ?></td>
        <td><?= (int)$c['account_count'] ?: '<span class="text-muted">None</span>' ?></td>
        <td class="co-act"><a href="/admin/company-edit.php?id=<?= (int)$c['id'] ?>" class="btn-icon" data-tip="Edit" aria-label="Edit <?= e($c['name']) ?>"><?= admin_icon('edit', 16) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php else: ?>
  <?php dt_empty('No companies yet — add the first one below.', 'inbox'); ?>
<?php endif; ?>

<div class="card" style="margin-top:18px">
  <div class="card__head"><span class="card__title">Add a company</span></div>
  <div class="card__body" style="padding:16px 20px">
    <form method="POST" action="<?= $self ?>" class="ws-addform co-add">
      <?= csrf_field() ?><input type="hidden" name="action" value="company_add">
      <label class="wsf wsf--grow"><span>Name</span><input name="name" class="inp" maxlength="120" placeholder="e.g. Zuri Watamu Ltd" required></label>
      <label class="wsf"><span>Short code <span class="text-muted">(optional)</span></span><input name="code" class="inp co-codeinp" maxlength="6" placeholder="e.g. ZUR"></label>
      <button type="submit" class="btn-primary btn-sm"><?= admin_icon('plus', 15) ?> Add company</button>
    </form>
    <p class="text-muted co-hint">The short code starts each invoice number (ZUR-INV-000001). Left blank, it is made from the name.</p>
  </div>
</div>

<style>
.co-intro{margin:-6px 0 18px;font-size:13px;max-width:860px}
.co-gaps{margin-bottom:18px;border-left:3px solid #e65100}
.co-gaps .card__head{justify-content:flex-start;gap:10px}
.co-gaps__body{padding:14px 20px}
.co-gaps__row{display:flex;gap:12px;align-items:baseline;margin-bottom:8px;flex-wrap:wrap}
.co-gaps__lbl{font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);min-width:110px}
.co-gaps__items{display:flex;flex-wrap:wrap;gap:6px}
.co-hint{font-size:12.5px;margin:8px 0 0}
.co-name{font-weight:600}
.co-code{margin-left:6px;font-family:ui-monospace,monospace}
.co-sub{font-size:12px;margin-top:2px}
.co-act{text-align:right;width:1%}
.co-table .badge{margin-right:4px}
.co-codeinp{text-transform:uppercase;width:110px}
@media (max-width:640px){
  .co-add{flex-direction:column;align-items:stretch}
  .co-add .wsf,.co-codeinp{width:100%}
}
</style>
<?php endif; ?>
<?php include __DIR__ . '/_layout_end.php'; ?>
