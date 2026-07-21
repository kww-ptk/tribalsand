<?php /** Home dashboard. Expects $hold, $ref, $status. */ ?>
<?php $__u = '/booking.php?ref=' . urlencode($ref); ?>
<div class="bk-tiles" style="display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:8px">
  <a href="<?= e($__u) ?>&view=concierge" style="text-decoration:none;background:var(--teal-d,#102F3A);color:#fff;border-radius:12px;padding:18px 20px;display:flex;align-items:center;gap:14px">
    <span style="font-size:24px">&#128276;</span>
    <span><span style="display:block;font-weight:700;font-size:16px">Concierge</span><span style="display:block;font-size:13px;opacity:.85">Towels, housekeeping, anything you need</span></span>
    <span style="margin-left:auto">&rarr;</span>
  </a>
  <a href="<?= e($__u) ?>&view=stay" style="text-decoration:none;background:#fff;border:1px solid #e5e0d6;border-radius:12px;padding:18px 20px;display:flex;align-items:center;gap:14px;color:#1a1a1a">
    <span style="font-size:24px">&#8505;</span>
    <span><span style="display:block;font-weight:700;font-size:16px">Stay info</span><span style="display:block;font-size:13px;color:#6b7280">Wi-Fi, check-out, area guide</span></span>
    <span style="margin-left:auto;color:#9ca3af">&rarr;</span>
  </a>
  <a href="<?= e($__u) ?>&view=manage" style="text-decoration:none;background:#fff;border:1px solid #e5e0d6;border-radius:12px;padding:14px 20px;display:flex;align-items:center;gap:14px;color:#1a1a1a">
    <span style="font-size:20px">&#9998;</span>
    <span style="font-weight:600;font-size:14px">Manage booking</span>
    <span style="margin-left:auto;color:#9ca3af;font-size:13px">Add tours &middot; changes &middot; cancel</span>
  </a>
</div>
