<?php /**
 * Home — tabbed in-page sections (#16): Your stay / What's on / Calendar / Requests.
 * The bottom tab bar (Home / Activities / Messages) is unchanged; these in-page
 * tabs split the long Home scroll into instant, client-swapped sections. The
 * status-header (booking summary) stays pinned above the tabs as the anchor.
 * Expects $hold, $ref, $status, and (for the cancel card) $can_cancel,
 * $cancel_blocked_reason.
 */ ?>
<?php
$__venue = isset($hold['venue_id']) && $hold['venue_id'] !== null ? (int)$hold['venue_id'] : null;
try { $__homeBoard = fetch_guest_board($__venue); } catch (Throwable $e) { $__homeBoard = []; }

// "What's on" only appears when there are board posts, so no tab is ever empty:
// the others always have content (stay details/soon, calendar days, request tiles).
$__sectabs = ['stay' => 'Your stay'];
// Party only when there is a party — a solo booking gets no empty tab.
$__hasParty = max(1, (int)($hold['guest_count'] ?? 1)) > 1;
if ($__hasParty) $__sectabs['party'] = 'Party';
if ($__homeBoard) $__sectabs['whatson'] = "What's on";
$__sectabs['calendar'] = 'Calendar';
$__sectabs['requests'] = 'Requests';
?>
<div class="pa-sectabs" role="tablist" aria-label="Home sections">
  <?php $__firstSec = true; foreach ($__sectabs as $__sk => $__sl): ?>
  <button type="button" class="pa-sectab <?= $__firstSec ? 'is-active' : '' ?>" role="tab"
          data-sectab="<?= e($__sk) ?>" aria-selected="<?= $__firstSec ? 'true' : 'false' ?>"><?= e($__sl) ?></button>
  <?php $__firstSec = false; endforeach; ?>
</div>

<section class="pa-sec is-active" data-sec="stay" role="tabpanel">
  <?php include __DIR__ . '/_stay_essentials.php'; ?>
  <?php if ($can_cancel): ?>
  <div class="pa-card" style="padding:16px">
    <p style="margin:0 0 6px;font-weight:700">Need to cancel?</p>
    <p style="margin:0 0 20px;font-size:14px;color:var(--pa-muted);line-height:1.65">If your plans have changed you can cancel now. The dates will be freed and you will receive a cancellation confirmation by email.</p>
    <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this booking? This cannot be undone.')">
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="ref" value="<?= e($ref) ?>">
      <button type="submit" class="pa-btn pa-btn--danger">Cancel My Booking</button>
    </form>
  </div>
  <?php elseif ($cancel_blocked_reason): ?>
  <div class="pa-card" style="padding:16px">
    <p style="margin:0 0 6px;font-weight:700">Need to cancel?</p>
    <p style="margin:0;font-size:14px;color:var(--pa-muted)"><?= e($cancel_blocked_reason) ?></p>
  </div>
  <?php endif; ?>
</section>

<?php if ($__hasParty): ?>
<section class="pa-sec" data-sec="party" role="tabpanel">
  <?php include __DIR__ . '/_party.php'; ?>
</section>
<?php endif; ?>

<?php if (isset($__sectabs['whatson'])): ?>
<section class="pa-sec" data-sec="whatson" role="tabpanel">
  <?php include __DIR__ . '/_greeting_board.php'; ?>
</section>
<?php endif; ?>

<section class="pa-sec" data-sec="calendar" role="tabpanel">
  <?php include __DIR__ . '/_trip.php'; ?>
</section>

<section class="pa-sec" data-sec="requests" role="tabpanel">
  <?php include __DIR__ . '/_services.php'; ?>
</section>

<script>
(function(){
  var tabs = document.querySelectorAll('.pa-sectab[data-sectab]');
  var secs = document.querySelectorAll('.pa-sec[data-sec]');
  if (!tabs.length) return;
  var KEY = 'pa_home_sec';
  function show(name){
    var found = false;
    secs.forEach(function(s){ var on = s.getAttribute('data-sec') === name; s.classList.toggle('is-active', on); if (on) found = true; });
    if (!found) return false;
    tabs.forEach(function(t){ var on = t.getAttribute('data-sectab') === name; t.classList.toggle('is-active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
    return true;
  }
  tabs.forEach(function(t){
    t.addEventListener('click', function(){
      var name = t.getAttribute('data-sectab');
      if (show(name)) { try { sessionStorage.setItem(KEY, name); } catch (e) {} }
    });
  });
  // Restore the last-viewed section within this browser session.
  try { var saved = sessionStorage.getItem(KEY); if (saved) show(saved); } catch (e) {}
})();
</script>
