<?php
/**
 * Admin: restaurant service hours.
 *
 * Gated at require_manager() — owner or manager. This is a deliberate departure
 * from the other settings screens (which use require_owner()): changing dinner
 * hours is daily ops, not pricing configuration. See the design spec.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/restaurant.php';
require_login();
require_manager();

$pageTitle  = 'Service Hours';
$activeMenu = 'restaurant_hours';

$slug  = 'zuri';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Array-valued POST params must never reach foreach()/(string) casts
    // unguarded — a scalar handed to foreach(), or an array handed to (string),
    // both emit a warning above the <!DOCTYPE> with display_errors on in
    // production (same hazard admin/restaurant.php guards against for GET).
    $daysIn = is_array($_POST['days'] ?? null) ? $_POST['days'] : [];
    $days = [];
    foreach ($daysIn as $d) {
        if (is_numeric($d) && (int)$d >= 0 && (int)$d <= 6) $days[] = (int)$d;
    }

    $fromIn  = is_string($_POST['from']  ?? null) ? $_POST['from']  : '';
    $toIn    = is_string($_POST['to']    ?? null) ? $_POST['to']    : '';
    $inboxIn = is_string($_POST['inbox'] ?? null) ? $_POST['inbox'] : '';

    // Same 'HH:MM' rule restaurant_normalise_hours() enforces internally. Checked
    // here, on the RAW submitted values, so an inverted or malformed window can
    // be REJECTED with a clear message. Checking after normalise_hours() would be
    // useless: it already silently falls both fields back to the defaults
    // whenever from >= to (so the app is never left closed forever), which means
    // a post-normalise comparison can never observe an inverted pair.
    $isTime = static fn($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1;

    if (!$isTime($fromIn) || !$isTime($toIn)) {
        $flash = ['type' => 'error', 'msg' => 'Please enter valid opening and closing times.'];
    } elseif ($fromIn >= $toIn) {
        $flash = ['type' => 'error', 'msg' => 'Closing time must be later than opening time.'];
    } else {
        $cfg = restaurant_normalise_hours([
            'days' => $days,
            'from' => $fromIn,
            'to'   => $toIn,
            'step' => (int)($_POST['step'] ?? 30),
        ]);

        db_query(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value',
            [':k' => 'restaurant_hours_' . $slug, ':v' => json_encode($cfg)]
        );
        db_query(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value',
            [':k' => 'restaurant_inbox_' . $slug, ':v' => trim($inboxIn)]
        );
        audit_log('restaurant_hours_update', 'venue', 0, $slug);
        $flash = ['type' => 'success', 'msg' => 'Service hours saved.'];
    }
}

$cfg   = restaurant_hours($slug);
$inbox = setting('restaurant_inbox_' . $slug, '');
$names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

require __DIR__ . '/_layout.php';
?>

<?php if ($flash): ?>
<div class="alert alert--<?= $flash['type'] === 'error' ? 'error' : 'success' ?> is-flash"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><span class="card__title">Zuri — Service Hours</span></div>
  <div class="card__body" style="padding:20px 24px">
    <?php
      // Preview the last seating. end() takes a reference, so the slot list must
      // land in a variable first. Use a date we know the venue is open on.
      $__previewDay  = $cfg['days'][0] ?? 1;
      $__previewDate = date('Y-m-d', strtotime('sunday +' . $__previewDay . ' days'));
      $__previewSlots = restaurant_slots_for($__previewDate, $cfg);
      $__lastSeating  = $__previewSlots ? end($__previewSlots) : '—';
    ?>
    <p>Guests can book from the opening time up to (but not including) the closing time.
       At <?= e($cfg['from']) ?>–<?= e($cfg['to']) ?> in <?= e($cfg['step']) ?>-minute steps,
       the last seating is <?= e($__lastSeating) ?>.</p>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <div class="filter-field">
        <span>Open on</span>
        <div style="display:flex;flex-wrap:wrap;gap:.8rem;margin-top:.4rem">
          <?php foreach ($names as $i => $n): ?>
          <label style="display:flex;align-items:center;gap:.3rem">
            <input type="checkbox" name="days[]" value="<?= $i ?>"<?= in_array($i, $cfg['days'], true) ? ' checked' : '' ?>>
            <?= e($n) ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="filter-row">
        <div class="filter-field"><label for="from">Opens</label><input type="time" id="from" name="from" value="<?= e($cfg['from']) ?>" step="60" required></div>
        <div class="filter-field"><label for="to">Closes</label><input type="time" id="to" name="to" value="<?= e($cfg['to']) ?>" step="60" required></div>
        <div class="filter-field">
          <label for="step">Slot length</label>
          <select id="step" name="step">
            <?php foreach ([15, 30, 60] as $m): ?>
            <option value="<?= $m ?>"<?= $cfg['step'] === $m ? ' selected' : '' ?>><?= $m ?> minutes</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="filter-field" style="max-width:420px">
        <label for="inbox">Booking alerts go to</label>
        <input type="email" id="inbox" name="inbox" value="<?= e($inbox) ?>" placeholder="restaurant@tribalsand.com">
      </div>

      <button type="submit" class="btn-primary">Save hours</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/_layout_end.php'; ?>
