<?php
/**
 * Admin: one company (owner-only) — Details (KRA PIN, VAT, eTIMS, invoicing
 * settings, logo), Money accounts (bank / M-Pesa / cash / card), Numbering
 * (gapless series per document type) and Owns (properties, outlets, shared stock).
 * All forms PRG + CSRF. Logic + rules: includes/companies.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/companies.php';
require_once __DIR__ . '/../includes/storage.php';
require_login();
require_owner();

$activeMenu = 'companies';
$id      = (int)($_GET['id'] ?? $_POST['company_id'] ?? 0);
$company = companies_supported() ? company_fetch($id) : false;
if (!$company) { header('Location: /admin/companies.php'); exit; }

$tabs = ['details' => 'Details', 'accounts' => 'Money accounts', 'numbering' => 'Numbering', 'owns' => 'Owns'];
$self = '/admin/company-edit.php?id=' . $id;

function coe_flash(string $type, string $msg): void { $_SESSION['coe_flash'] = ['type' => $type, 'msg' => $msg]; }
function coe_back(string $tab, string $anchor = ''): never {
    global $self; header('Location: ' . $self . '&tab=' . rawurlencode($tab) . ($anchor !== '' ? '#' . $anchor : '')); exit;
}

$flash = $_SESSION['coe_flash'] ?? null; unset($_SESSION['coe_flash']);
$old   = $_SESSION['coe_old'] ?? null;   unset($_SESSION['coe_old']);   // re-fill a refused Details form

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'save_details') {
            [$d, $errors] = company_clean($_POST);
            if ($errors) {
                $_SESSION['coe_old'] = ['data' => $_POST, 'errors' => $errors];
                coe_flash('error', 'Check the highlighted fields.');
                coe_back('details');
            }
            company_save($id, $d);
            audit_log('company.update', 'company', $id, $d['name']);
            coe_flash('success', "{$d['name']} saved.");
            coe_back('details');
        }

        if ($act === 'logo_upload') {
            $f = $_FILES['logo'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { coe_flash('error', 'Choose an image to upload.'); coe_back('details'); }
            if ($f['size'] > 2 * 1024 * 1024) { coe_flash('error', 'The logo is too large (max 2 MB).'); coe_back('details'); }
            $mime = (string) mime_content_type($f['tmp_name']);
            $src = match ($mime) {
                'image/png'  => @imagecreatefrompng($f['tmp_name']),
                'image/jpeg' => @imagecreatefromjpeg($f['tmp_name']),
                'image/webp' => @imagecreatefromwebp($f['tmp_name']),
                default      => false,
            };
            if (!$src) { coe_flash('error', 'Use a PNG, JPG or WEBP image.'); coe_back('details'); }
            // Keep transparency and cap the width: the logo prints on invoices.
            $w = imagesx($src); $h = imagesy($src);
            if ($w > 600) {
                $nh = (int) round($h * 600 / $w);
                $dst = imagecreatetruecolor(600, $nh);
                imagealphablending($dst, false); imagesavealpha($dst, true);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, 600, $nh, $w, $h);
                imagedestroy($src); $src = $dst;
            }
            imagesavealpha($src, true);
            $name = seo_filename((string)$f['name'], 'company-logo', 'png');
            $tmp  = sys_get_temp_dir() . '/' . $name;
            imagepng($src, $tmp, 6); imagedestroy($src);
            $key = storage_put($tmp, $name, 'image/png', 'companies'); @unlink($tmp);
            if ($key === false) { coe_flash('error', 'The logo could not be stored — try again.'); coe_back('details'); }
            company_set_logo($id, $key);
            audit_log('company.logo', 'company', $id, (string)$key);
            coe_flash('success', 'Logo updated.');
            coe_back('details');
        }
        if ($act === 'logo_remove') {
            company_set_logo($id, null);
            coe_flash('success', 'Logo removed.');
            coe_back('details');
        }

        if ($act === 'company_delete') {
            $out = company_delete_or_deactivate($id);
            audit_log('company.' . ($out === 'deleted' ? 'delete' : 'deactivate'), 'company', $id, (string)$company['name']);
            if ($out === 'deleted') {
                $_SESSION['co_flash'] = ['type' => 'success', 'msg' => "{$company['name']} deleted."];
                header('Location: /admin/companies.php'); exit;
            }
            coe_flash('info', "{$company['name']} owns properties, outlets or stock, or has used invoice numbers — so it was switched off instead of deleted.");
            coe_back('details');
        }

        if ($act === 'account_save') {
            $aid = (int)($_POST['account_id'] ?? 0) ?: null;
            [$a, $err] = company_account_clean($_POST);
            if ($err !== null) { coe_flash('error', $err); coe_back('accounts', $aid ? 'acc-' . $aid : 'acc-new'); }
            $aid = company_account_save($id, $aid, $a);
            audit_log('company.account_save', 'company', $id, $a['label']);
            coe_flash('success', "{$a['label']} saved.");
            coe_back('accounts', 'acc-' . $aid);
        }
        if ($act === 'account_delete') {
            $out = company_account_delete($id, (int)($_POST['account_id'] ?? 0));
            audit_log('company.account_delete', 'company', $id, (string)($_POST['account_id'] ?? ''));
            coe_flash($out === 'deleted' ? 'success' : 'info', $out === 'deleted' ? 'Account removed.' : 'That account has payments against it, so it was switched off instead.');
            coe_back('accounts');
        }

        if ($act === 'sequence_save') {
            $type = (string)($_POST['doc_type'] ?? '');
            company_sequence_save($id, $type, (string)($_POST['prefix'] ?? ''), (int)($_POST['next_no'] ?? 0));
            audit_log('company.sequence_save', 'company', $id, $type);
            coe_flash('success', (ACCT_DOC_TYPES[$type] ?? 'Document') . ' numbering saved.');
            coe_back('numbering');
        }

        if ($act === 'save_ownership') {
            $msg = [];
            foreach (['venues' => 'properties', 'outlets' => 'outlets', 'locations' => 'stock places'] as $kind => $lbl) {
                if (!isset($_POST['has_' . $kind])) continue;   // section not shown (its table isn't installed)
                [$added, $released] = company_assign($id, $kind, (array)($_POST[$kind] ?? []));
                if ($added)    $msg[] = "{$added} {$lbl} added";
                if ($released) $msg[] = "{$released} {$lbl} released";
            }
            audit_log('company.ownership', 'company', $id, implode('; ', $msg));
            coe_flash('success', $msg ? ucfirst(implode(', ', $msg)) . '.' : 'Nothing changed.');
            coe_back('owns');
        }
    } catch (CompanyRefusal $e) {
        coe_flash('error', $e->getMessage());
        coe_back(match ($act) { 'account_save', 'account_delete' => 'accounts', 'sequence_save' => 'numbering', 'save_ownership' => 'owns', default => 'details' });
    }
    coe_back('details');
}

$tab       = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'details';
$pageTitle = $company['name'] . ' — Company';
// A refused save keeps what was typed — including toggles that were switched off (absent from POST).
$form      = $old ? array_merge($company, ['vat_registered' => false, 'etims_enabled' => false, 'is_active' => false], $old['data']) : $company;
$errs      = $old['errors'] ?? [];
$accounts  = company_accounts($id);
$sequences = company_sequences($id);
$logoUrl   = company_logo_url($company['logo_key'] ?? null);

// Owns tab: every row, who has it now.
$allCos   = [];
foreach (company_fetch_all() as $c) $allCos[(int)$c['id']] = $c['name'];
$venues   = db_query('SELECT id, name, company_id FROM venues ORDER BY sort_order, name')->fetchAll();
$venueCo  = []; foreach ($venues as $v) $venueCo[(int)$v['id']] = $v['company_id'] ? (int)$v['company_id'] : null;
$outlets  = companies_outlets_supported()
    ? db_query('SELECT o.id, o.name, o.company_id, o.venue_id, o.is_active, v.name AS venue_name FROM pos_outlets o LEFT JOIN venues v ON v.id = o.venue_id ORDER BY o.sort_order, o.name')->fetchAll()
    : [];
$places   = company_shared_locations();

$fieldErr = fn(string $k): string => isset($errs[$k]) ? '<span class="field-hint co-err">' . e($errs[$k]) . '</span>' : '';
$chk      = fn($v): string => ($v === true || $v === 't' || $v === '1' || $v === 1) ? 'checked' : '';

include __DIR__ . '/_layout.php';
?>
<div class="page-header">
  <h1><a href="/admin/companies.php" class="co-back" data-tip="All companies" aria-label="All companies"><?= admin_icon('arrow-left', 18) ?></a> <?= e($company['name']) ?>
    <span class="badge badge--grey co-code"><?= e($company['code']) ?></span>
    <?php if (!$company['is_active']): ?><span class="badge badge--orange">Switched off</span><?php endif; ?></h1>
</div>
<?php if ($flash): ?><div class="alert alert--<?= e($flash['type']) ?> is-flash"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="tabs co-tabs" role="tablist">
  <?php foreach ($tabs as $k => $lbl): ?>
  <a class="tab-btn <?= $tab === $k ? 'is-active' : '' ?>" href="<?= e($self . '&tab=' . $k) ?>" data-tab="<?= e($k) ?>" role="tab"><?= e($lbl) ?>
    <?php if ($k === 'accounts' && $accounts): ?><span class="tab-btn__count"><?= count(array_filter($accounts, fn($a) => $a['is_active'])) ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</div>

<!-- ── Details ── -->
<div class="tab-panel <?= $tab === 'details' ? 'is-active' : '' ?>" id="tab-details">
  <div class="card">
    <div class="card__body co-body">
      <form method="POST" action="<?= e($self) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_details"><input type="hidden" name="company_id" value="<?= $id ?>">

        <div class="form-row">
          <div class="field"><label>Name <span class="text-muted">(what staff call it)</span></label>
            <input name="name" maxlength="120" value="<?= e($form['name']) ?>" required class="<?= isset($errs['name']) ? 'is-invalid' : '' ?>"><?= $fieldErr('name') ?></div>
          <div class="field"><label>Short code <span class="text-muted">(starts invoice numbers)</span></label>
            <input name="code" maxlength="6" value="<?= e($form['code']) ?>" class="co-upper <?= isset($errs['code']) ? 'is-invalid' : '' ?>" required><?= $fieldErr('code') ?></div>
        </div>
        <div class="form-row">
          <div class="field"><label>Registered name <span class="text-muted">(as on the KRA certificate)</span></label>
            <input name="legal_name" maxlength="200" value="<?= e($form['legal_name']) ?>" placeholder="e.g. Zuri Watamu Limited" class="<?= isset($errs['legal_name']) ? 'is-invalid' : '' ?>"><?= $fieldErr('legal_name') ?></div>
          <div class="field"><label>KRA PIN</label>
            <input name="kra_pin" maxlength="14" value="<?= e($form['kra_pin']) ?>" placeholder="e.g. P051234567X" class="co-upper co-mono <?= isset($errs['kra_pin']) ? 'is-invalid' : '' ?>" autocomplete="off"><?= $fieldErr('kra_pin') ?: '<span class="field-hint">A or P, nine digits, then a letter.</span>' ?></div>
        </div>

        <div class="co-toggles">
          <label class="togglerow"><span class="toggle"><input type="checkbox" name="vat_registered" value="1" <?= $chk($form['vat_registered'] ?? false) ?>><span class="toggle-slider"></span></span><span>Registered for VAT <span class="text-muted">(charges 16% VAT on its invoices)</span></span></label>
          <label class="togglerow"><span class="toggle"><input type="checkbox" name="etims_enabled" value="1" <?= $chk($form['etims_enabled'] ?? false) ?>><span class="toggle-slider"></span></span><span>Uses KRA eTIMS <span class="text-muted">(invoices and receipts get signed by KRA once eTIMS is connected)</span></span></label>
          <label class="togglerow"><span class="toggle"><input type="checkbox" name="is_active" value="1" <?= $chk($form['is_active'] ?? true) ?>><span class="toggle-slider"></span></span><span>Active</span></label>
        </div>

        <div class="form-row">
          <div class="field"><label>When invoices are issued</label>
            <select name="invoice_timing"><?php foreach (COMPANY_INVOICE_TIMING as $k => $lbl): ?><option value="<?= e($k) ?>" <?= ($form['invoice_timing'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select>
            <?= $fieldErr('invoice_timing') ?: '<span class="field-hint">Your accountant decides. Takes effect when invoicing goes live.</span>' ?></div>
          <div class="field"><label>Room charges to another company's guests</label>
            <select name="room_charge_mode"><?php foreach (COMPANY_ROOM_CHARGE_MODES as $k => $lbl): ?><option value="<?= e($k) ?>" <?= ($form['room_charge_mode'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select>
            <?= $fieldErr('room_charge_mode') ?: '<span class="field-hint">Matters for the company that runs the shared shop / spa / kite school.</span>' ?></div>
        </div>

        <div class="form-row">
          <div class="field"><label>Accounts email</label>
            <input name="email" type="email" maxlength="160" value="<?= e($form['email']) ?>" placeholder="accounts@…" class="<?= isset($errs['email']) ? 'is-invalid' : '' ?>"><?= $fieldErr('email') ?></div>
          <div class="field"><label>Phone</label>
            <input name="phone" maxlength="40" value="<?= e($form['phone']) ?>" placeholder="+254 …"><?= $fieldErr('phone') ?></div>
        </div>
        <div class="field"><label>Address <span class="text-muted">(printed on invoices)</span></label>
          <textarea name="address" rows="3" maxlength="1000" placeholder="P.O. Box …, Watamu"><?= e($form['address']) ?></textarea><?= $fieldErr('address') ?></div>

        <div class="co-meta">
          <span><strong>Home currency:</strong> <?= e($company['home_currency']) ?></span>
          <span><strong>Invoicing:</strong> <?= $company['accounting_starts_on'] ? 'live from ' . e(date('j M Y', strtotime((string)$company['accounting_starts_on']))) : 'not live yet — nothing is invoiced' ?></span>
        </div>

        <button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Save company</button>
      </form>
    </div>
  </div>

  <div class="card co-card">
    <div class="card__head"><span class="card__title">Logo</span><span class="text-muted co-small">Printed on invoices · PNG, JPG or WEBP up to 2 MB</span></div>
    <div class="card__body co-body co-logo">
      <?php if ($logoUrl !== ''): ?><img src="<?= e($logoUrl) ?>" alt="<?= e($company['name']) ?> logo" class="co-logo__img"><?php else: ?><div class="co-logo__none">No logo</div><?php endif; ?>
      <form method="POST" action="<?= e($self) ?>" enctype="multipart/form-data" class="co-logo__form">
        <?= csrf_field() ?><input type="hidden" name="action" value="logo_upload"><input type="hidden" name="company_id" value="<?= $id ?>">
        <label class="filefield">
          <span class="btn-outline btn-sm"><?= admin_icon('image', 14) ?> <?= $logoUrl !== '' ? 'Replace' : 'Upload logo' ?></span>
          <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" data-co-autosubmit>
        </label>
      </form>
      <?php if ($logoUrl !== ''): ?>
      <form method="POST" action="<?= e($self) ?>" style="margin:0">
        <?= csrf_field() ?><input type="hidden" name="action" value="logo_remove"><input type="hidden" name="company_id" value="<?= $id ?>">
        <button type="submit" class="btn-icon btn-icon--danger" data-confirm="Remove the logo?" data-tip="Remove logo" aria-label="Remove logo"><?= admin_icon('trash', 15) ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="co-danger">
    <form method="POST" action="<?= e($self) ?>" style="margin:0">
      <?= csrf_field() ?><input type="hidden" name="action" value="company_delete"><input type="hidden" name="company_id" value="<?= $id ?>">
      <button type="submit" class="btn-outline btn-sm co-delbtn" data-confirm="Delete <?= e($company['name']) ?>? A company that owns anything or has used invoice numbers is switched off instead."><?= admin_icon('trash', 14) ?> Delete company</button>
    </form>
  </div>
</div>

<!-- ── Money accounts ── -->
<div class="tab-panel <?= $tab === 'accounts' ? 'is-active' : '' ?>" id="tab-accounts">
  <p class="text-muted co-intro">Where this company's money lands. Each currency has one <strong>default</strong> account; when payments are recorded (next phase) reception picks one of these, and it must belong to the invoice's company.</p>
  <?php
  $accountForm = function (?array $a) use ($id, $self): void {
      $isNew = $a === null;
      $a ??= ['id' => 0, 'label' => '', 'kind' => 'bank', 'currency' => COMPANY_HOME_CURRENCY, 'bank_name' => '', 'branch' => '', 'account_number' => '', 'swift_code' => '', 'is_default' => false, 'is_active' => true];
      $on = fn($v) => ($v === true || $v === 't' || $v === 1 || $v === '1');
      ?>
      <form method="POST" action="<?= e($self) ?>" class="co-accform" data-kind="<?= e($a['kind']) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="account_save"><input type="hidden" name="company_id" value="<?= $id ?>">
        <?php if (!$isNew): ?><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>"><?php endif; ?>
        <div class="co-accgrid">
          <div class="field"><label>Name</label><input name="label" maxlength="120" value="<?= e($a['label']) ?>" placeholder="e.g. Equity KES current" required></div>
          <div class="field"><label>Kind</label>
            <select name="kind" data-co-kind><?php foreach (COMPANY_ACCOUNT_KINDS as $k => $lbl): ?><option value="<?= e($k) ?>" <?= $a['kind'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Currency</label>
            <select name="currency"><?php foreach (TS_CURRENCIES as $c => $meta): ?><option value="<?= e($c) ?>" <?= strtoupper((string)$a['currency']) === $c ? 'selected' : '' ?>><?= e($c . ' — ' . $meta['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field" data-co-show="bank"><label>Bank</label><input name="bank_name" maxlength="120" value="<?= e($a['bank_name']) ?>" placeholder="e.g. Equity Bank"></div>
          <div class="field" data-co-show="bank"><label>Branch</label><input name="branch" maxlength="120" value="<?= e($a['branch']) ?>" placeholder="e.g. Malindi"></div>
          <div class="field" data-co-show="bank mpesa_till mpesa_paybill card_merchant"><label data-co-numlabel>Account number</label>
            <input name="account_number" maxlength="40" value="<?= e($a['account_number']) ?>" class="co-mono" autocomplete="off"></div>
          <div class="field" data-co-show="bank"><label>SWIFT <span class="text-muted">(optional)</span></label><input name="swift_code" maxlength="11" value="<?= e($a['swift_code']) ?>" class="co-upper co-mono"></div>
        </div>
        <div class="co-accfoot">
          <label class="togglerow"><span class="toggle"><input type="checkbox" name="is_default" value="1" <?= $on($a['is_default']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Default for its currency</span></label>
          <label class="togglerow"><span class="toggle"><input type="checkbox" name="is_active" value="1" <?= $on($a['is_active']) ? 'checked' : '' ?>><span class="toggle-slider"></span></span><span>Active</span></label>
          <button type="submit" class="btn-primary btn-sm"><?= admin_icon($isNew ? 'plus' : 'check', 15) ?> <?= $isNew ? 'Add account' : 'Save account' ?></button>
        </div>
      </form>
      <?php
  };
  ?>
  <div class="co-acclist">
  <?php foreach ($accounts as $a): $aid = (int)$a['id'];
      $num = $a['account_number'] !== '' ? $a['account_number'] : ''; ?>
    <details class="card co-acc" id="acc-<?= $aid ?>">
      <summary class="co-acc__head">
        <span class="co-acc__title"><?= e($a['label']) ?></span>
        <span class="co-acc__meta">
          <span class="badge badge--grey"><?= e(COMPANY_ACCOUNT_KINDS[$a['kind']] ?? $a['kind']) ?></span>
          <span class="badge badge--teal"><?= e($a['currency']) ?></span>
          <?php if ($a['is_default']): ?><span class="badge badge--green">Default</span><?php endif; ?>
          <?php if (!$a['is_active']): ?><span class="badge badge--orange">Closed</span><?php endif; ?>
          <?php if ($num !== ''): ?><span class="text-muted co-mono"><?= e(trim($a['bank_name'] . ' ' . $num)) ?></span><?php endif; ?>
        </span>
        <svg class="co-acc__chev" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
      </summary>
      <div class="co-acc__body">
        <?php $accountForm($a); ?>
        <form method="POST" action="<?= e($self) ?>" class="co-acc__del">
          <?= csrf_field() ?><input type="hidden" name="action" value="account_delete"><input type="hidden" name="company_id" value="<?= $id ?>"><input type="hidden" name="account_id" value="<?= $aid ?>">
          <button type="submit" class="btn-icon btn-icon--danger" data-confirm="Remove <?= e($a['label']) ?>?" data-tip="Remove account" aria-label="Remove account"><?= admin_icon('trash', 15) ?></button>
        </form>
      </div>
    </details>
  <?php endforeach; ?>
  </div>
  <?php if (!$accounts): ?><p class="text-muted co-small" style="margin:0 0 12px">No accounts yet.</p><?php endif; ?>
  <div class="card co-card" id="acc-new">
    <div class="card__head"><span class="card__title">Add an account</span></div>
    <div class="card__body co-body"><?php $accountForm(null); ?></div>
  </div>
</div>

<!-- ── Numbering ── -->
<div class="tab-panel <?= $tab === 'numbering' ? 'is-active' : '' ?>" id="tab-numbering">
  <p class="text-muted co-intro">Every document this company issues gets the next number in its series — no gaps, no repeats, as KRA requires. You can change a series' prefix and starting number (e.g. to carry on from your spreadsheet) <strong>until its first number is used</strong>; after that it is locked.</p>
  <div class="card">
    <div class="table-wrap co-seqwrap">
    <table class="data-table co-seq">
      <thead><tr><th>Document</th><th>Prefix</th><th>Next number</th><th>Next document</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($sequences as $type => $s): $locked = $s['last_issued_at'] !== null; $fid = 'seq-' . $type; ?>
        <tr>
          <td><strong><?= e(ACCT_DOC_TYPES[$type]) ?></strong><?php if ($locked): ?> <span class="badge badge--grey">Locked</span><?php endif; ?></td>
          <td><input form="<?= $fid ?>" name="prefix" class="inp co-upper co-mono co-seqin" maxlength="20" value="<?= e($s['prefix']) ?>" <?= $locked ? 'disabled' : '' ?> aria-label="<?= e(ACCT_DOC_TYPES[$type]) ?> prefix" data-co-prefix></td>
          <td><input form="<?= $fid ?>" name="next_no" type="number" class="inp inp--num no-spin co-seqnum" min="1" max="<?= ACCT_NUMBER_MAX ?>" value="<?= (int)$s['next_no'] ?>" <?= $locked ? 'disabled' : '' ?> aria-label="<?= e(ACCT_DOC_TYPES[$type]) ?> next number" data-co-next></td>
          <td><code data-co-preview><?= e(acct_format_number((string)$s['prefix'], (int)$s['next_no'])) ?></code></td>
          <td class="co-act">
            <?php if (!$locked): ?>
            <form method="POST" action="<?= e($self) ?>" id="<?= $fid ?>" style="margin:0">
              <?= csrf_field() ?><input type="hidden" name="action" value="sequence_save"><input type="hidden" name="company_id" value="<?= $id ?>"><input type="hidden" name="doc_type" value="<?= e($type) ?>">
              <button type="submit" class="btn-icon btn-icon--primary" data-tip="Save" aria-label="Save <?= e(ACCT_DOC_TYPES[$type]) ?> numbering"><?= admin_icon('check', 15) ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <p class="text-muted co-small" style="margin-top:10px">A POS receipt keeps its till number (POS-SHOP-1042); the tax document carries this company number beside it.</p>
</div>

<!-- ── Owns ── -->
<div class="tab-panel <?= $tab === 'owns' ? 'is-active' : '' ?>" id="tab-owns">
  <p class="text-muted co-intro">Tick what belongs to <?= e($company['name']) ?>. Ticking something that belongs to another company moves it here; unticking leaves it without a company.</p>
  <form method="POST" action="<?= e($self) ?>" class="card co-card">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_ownership"><input type="hidden" name="company_id" value="<?= $id ?>">
    <div class="card__body co-body">
      <input type="hidden" name="has_venues" value="1">
      <div class="co-sub">Properties</div>
      <div class="co-chips">
        <?php foreach ($venues as $v): $cur = $v['company_id'] ? (int)$v['company_id'] : null; ?>
        <label class="optchip"><input type="checkbox" name="venues[]" value="<?= (int)$v['id'] ?>" <?= $cur === $id ? 'checked' : '' ?>><?= e($v['name']) ?><?php if ($cur && $cur !== $id): ?> <span class="co-chipnote">· <?= e($allCos[$cur] ?? '') ?></span><?php endif; ?></label>
        <?php endforeach; ?>
      </div>

      <?php if (companies_outlets_supported()): ?>
      <input type="hidden" name="has_outlets" value="1">
      <div class="co-sub">POS outlets</div>
      <?php if (!$outlets): ?><p class="text-muted co-small">No POS outlets yet.</p><?php endif; ?>
      <div class="co-chips">
        <?php foreach ($outlets as $o): $cur = $o['company_id'] ? (int)$o['company_id'] : null;
            $inherit = !$cur && $o['venue_id'] ? ($venueCo[(int)$o['venue_id']] ?? null) : null; ?>
        <label class="optchip"><input type="checkbox" name="outlets[]" value="<?= (int)$o['id'] ?>" <?= $cur === $id ? 'checked' : '' ?>><?= e($o['name']) ?><?php
          if ($cur && $cur !== $id): ?> <span class="co-chipnote">· <?= e($allCos[$cur] ?? '') ?></span><?php
          elseif (!$cur && $inherit): ?> <span class="co-chipnote">· via <?= e($o['venue_name']) ?> (<?= e($allCos[$inherit] ?? '') ?>)</span><?php
          elseif (!$cur && !$o['venue_id']): ?> <span class="co-chipnote">· shared</span><?php endif; ?></label>
        <?php endforeach; ?>
      </div>
      <p class="text-muted co-small">An outlet at a property belongs to that property's company unless you tick it here. The shared shop, spa, kite school and experiences desk usually belong to the services company.</p>
      <?php endif; ?>

      <?php if (companies_locations_supported()): ?>
      <input type="hidden" name="has_locations" value="1">
      <div class="co-sub">Shared stock</div>
      <?php if (!$places): ?><p class="text-muted co-small">No shared stock places yet — Main stock appears once inventory is used.</p><?php endif; ?>
      <div class="co-chips">
        <?php foreach ($places as $p): $cur = $p['company_id'] ? (int)$p['company_id'] : null; ?>
        <label class="optchip"><input type="checkbox" name="locations[]" value="<?= (int)$p['id'] ?>" <?= $cur === $id ? 'checked' : '' ?>><?= e($p['name']) ?><?php if ($cur && $cur !== $id): ?> <span class="co-chipnote">· <?= e($allCos[$cur] ?? '') ?></span><?php endif; ?></label>
        <?php endforeach; ?>
      </div>
      <p class="text-muted co-small">Stock at a property, in one of its areas or with its staff belongs to that property's company; an outlet's shelf belongs to the outlet's company. Only places shared by everyone (like Main stock) need a company here.</p>
      <?php endif; ?>

      <div class="co-actions"><button type="submit" class="btn-primary btn-sm"><?= admin_icon('check', 15) ?> Save what it owns</button></div>
    </div>
  </form>
</div>

<style>
.co-back{display:inline-flex;vertical-align:middle;color:var(--muted);margin-right:4px}
.co-back:hover{color:var(--brand)}
.co-code{font-family:ui-monospace,monospace;vertical-align:middle;margin-left:4px}
.co-tabs{flex-wrap:wrap;overflow-x:auto}
.co-body{padding:20px}
/* A plain 1fr track won't shrink below a long option label — the page would scroll sideways on a phone. */
.co-body .form-row{grid-template-columns:repeat(2,minmax(0,1fr))}
.co-body .field{min-width:0}
.co-body .eselect{min-width:0 !important;max-width:100%}
.co-body .eselect__label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0}
.co-card{margin-top:16px}
.co-card .card__head{flex-wrap:wrap;gap:6px 12px}
.co-intro{margin:-4px 0 16px;font-size:13px;max-width:860px}
.co-small{font-size:12.5px}
.co-upper{text-transform:uppercase}
.co-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.co-err{color:var(--red)}
.co-toggles{display:flex;flex-direction:column;gap:10px;margin:4px 0 18px}
.co-meta{display:flex;flex-wrap:wrap;gap:6px 24px;font-size:13px;color:var(--muted);margin:4px 0 18px;padding:10px 12px;background:#f9fafb;border-radius:8px}
.co-logo{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.co-logo__img{max-height:64px;max-width:220px;object-fit:contain;border:1px solid var(--border);border-radius:8px;padding:6px;background:#fff}
.co-logo__none{width:120px;height:64px;border:1.5px dashed var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--muted)}
.co-logo__form{margin:0}
.co-danger{margin-top:16px;display:flex;justify-content:flex-end}
.co-delbtn{border-color:var(--red);color:var(--red)}
.co-delbtn:hover{background:var(--red);color:#fff}
.co-acclist{display:grid;gap:10px;margin-bottom:12px}
.co-acc{overflow:hidden}
.co-acc__head{display:flex;align-items:center;gap:12px;padding:14px 18px;cursor:pointer;list-style:none}
.co-acc__head::-webkit-details-marker{display:none}
.co-acc__title{font-weight:600;font-size:14.5px}
.co-acc__meta{display:flex;flex-wrap:wrap;align-items:center;gap:6px;font-size:12.5px;flex:1;min-width:0}
.co-acc__chev{color:var(--muted);transition:transform .15s;flex:none}
.co-acc[open] .co-acc__chev{transform:rotate(180deg)}
.co-acc__body{border-top:1px solid var(--border);padding:18px;position:relative}
.co-acc__del{position:absolute;right:18px;bottom:18px;margin:0}
.co-accgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:0 16px}
.co-accfoot{display:flex;flex-wrap:wrap;align-items:center;gap:12px 20px}
.co-accform [data-co-show][hidden]{display:none}
.co-seqwrap .data-table{min-width:620px}
.co-seqin{width:140px}
.co-seqnum{width:120px}
.co-act{text-align:right;width:1%}
.co-sub{font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);margin:18px 0 8px}
.co-sub:first-of-type{margin-top:0}
.co-chips{display:flex;flex-wrap:wrap;gap:8px}
.co-chipnote{opacity:.7;font-size:11.5px}
.co-chips + .co-small{margin:8px 0 0}
.co-actions{margin-top:20px}
@media (max-width:640px){
  .co-body{padding:14px}
  .co-body .form-row{grid-template-columns:minmax(0,1fr)}
  .co-acc__head{flex-wrap:wrap;padding:12px 14px;gap:8px}
  .co-acc__meta{flex-basis:100%;order:3}
  .co-acc__body{padding:14px 14px 60px}
  .co-acc__del{right:14px;bottom:14px}
  .co-tabs .tab-btn{padding:10px 12px}
}
</style>
<script>
(function(){
  // Account form: show only the fields that apply to the chosen kind, and name the number field.
  var NUM = {bank:'Account number', mpesa_till:'Till number', mpesa_paybill:'Paybill number', card_merchant:'Merchant id'};
  document.querySelectorAll('.co-accform').forEach(function(form){
    var sel = form.querySelector('[data-co-kind]');
    function sync(){
      var k = sel.value;
      form.querySelectorAll('[data-co-show]').forEach(function(f){ f.hidden = f.dataset.coShow.split(' ').indexOf(k) < 0; });
      var lbl = form.querySelector('[data-co-numlabel]'); if (lbl) lbl.textContent = NUM[k] || 'Number';
    }
    sel.addEventListener('change', sync); sync();
  });
  // Numbering: live preview of the next document number.
  document.querySelectorAll('.co-seq tbody tr').forEach(function(tr){
    var p = tr.querySelector('[data-co-prefix]'), n = tr.querySelector('[data-co-next]'), out = tr.querySelector('[data-co-preview]');
    if (!p || !n || !out) return;
    function sync(){ var v = Math.max(1, parseInt(n.value, 10) || 1); out.textContent = p.value.toUpperCase() + String(v).padStart(<?= ACCT_NUMBER_PAD ?>, '0'); }
    p.addEventListener('input', sync); n.addEventListener('input', sync);
  });
  // Logo: upload as soon as a file is chosen.
  document.querySelectorAll('[data-co-autosubmit]').forEach(function(i){ i.addEventListener('change', function(){ if (i.files.length) i.form.submit(); }); });
  // Tabs switch in place (they are also real links, so no-JS and reloads land on the right one).
  document.querySelectorAll('.co-tabs .tab-btn').forEach(function(a){
    a.addEventListener('click', function(e){
      e.preventDefault();
      document.querySelectorAll('.co-tabs .tab-btn').forEach(function(b){ b.classList.toggle('is-active', b === a); });
      document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.toggle('is-active', p.id === 'tab-' + a.dataset.tab); });
      try { history.replaceState(null, '', a.href); } catch (err) {}
    });
  });
  // Open the account a save just touched.
  if (location.hash && /^#acc-\d+$/.test(location.hash)) { var d = document.querySelector(location.hash); if (d && d.tagName === 'DETAILS') d.open = true; }
})();
</script>
<?php include __DIR__ . '/_layout_end.php'; ?>
