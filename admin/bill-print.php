<?php
/** Printable room bill for a booking. Owner + property-scoped staff. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking.php';
require_login();

$holdId = (int)($_GET['hold'] ?? 0);
$hold = $holdId ? db_query(
    "SELECT h.*, u.name AS unit_name, r.name AS room_name, v.name AS venue_name
     FROM holds h JOIN units u ON u.id=h.unit_id JOIN rooms r ON r.id=u.room_id
     LEFT JOIN venues v ON v.id=r.venue_id WHERE h.id=:id", [':id'=>$holdId]
)->fetch() : null;
if (!$hold) { http_response_code(404); exit('Booking not found.'); }
if (is_staff() && !staff_can_hold($holdId)) { http_response_code(403); exit('Not your property.'); }

$cur   = setting('site_currency', 'USD');
$lines = array_filter(fetch_bill_lines($holdId), fn($l) => isset($l['price_amount']) && $l['price_amount'] !== null && (float)$l['price_amount'] > 0);
$items = fetch_bill_items($holdId);
$total = bill_total($holdId);
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Bill · <?= e($hold['guest_name'] ?: 'Guest') ?></title>
<style>
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#1b2a2f;max-width:640px;margin:24px auto;padding:0 20px}
  h1{font-size:22px;margin:0 0 4px}
  .muted{color:#6b7280;font-size:13px}
  h2{font-size:15px;margin:22px 0 0}
  table{width:100%;border-collapse:collapse;margin:14px 0}
  th,td{text-align:left;padding:8px 6px;border-bottom:1px solid #e5e7eb;font-size:14px}
  th.amt,td.amt{text-align:right;white-space:nowrap}
  .total{display:flex;justify-content:space-between;font-weight:700;font-size:17px;padding:12px 6px;border-top:2px solid #1b2a2f}
  .printbtn{margin:16px 0;padding:10px 18px;background:#102F3A;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:14px}
  @media print{.printbtn{display:none}body{margin:0}}
</style></head><body>
<button class="printbtn" onclick="window.print()">Print / Save PDF</button>
<h1><?= e(trim(((string)($hold['venue_name'] ?? '')) . ' · ' . ((string)($hold['room_name'] ?? '')), ' ·')) ?></h1>
<div class="muted"><?= e($hold['guest_name'] ?: 'Guest') ?> · <?= e(date('j M Y', strtotime((string)$hold['check_in']))) ?> – <?= e(date('j M Y', strtotime((string)$hold['check_out']))) ?><?php if (!empty($hold['access_code'])): ?> · <?= e($hold['access_code']) ?><?php endif; ?></div>

<h2>Extra charges</h2>
<?php if (!$lines && !$items): ?>
<p class="muted">No extra charges.</p>
<?php else: ?>
<table>
  <thead><tr><th>Item</th><th>Date</th><th class="amt">Amount</th></tr></thead>
  <tbody>
    <?php foreach ($lines as $l): ?>
    <tr>
      <td><?= e(addon_label($l)) ?><?php if (($l['kind'] ?? '') === 'tour' && !empty($l['pax'])): ?> (<?= (int)$l['pax'] ?> pax)<?php endif; ?></td>
      <td><?= !empty($l['scheduled_for']) ? e(date('j M Y', strtotime((string)$l['scheduled_for']))) : '' ?></td>
      <td class="amt"><?= e(format_price((float)$l['price_amount'], $cur)) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php foreach ($items as $it): ?>
    <tr><td><?= e($it['label']) ?></td><td></td><td class="amt"><?= e(format_price((float)$it['amount'], $cur)) ?></td></tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<div class="total"><span>Total</span><span><?= e(format_price($total, $cur)) ?></span></div>
</body></html>
