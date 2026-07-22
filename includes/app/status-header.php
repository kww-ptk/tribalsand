<?php /** Shared booking status header. Expects $hold, $ref, $status. */ if (!isset($hold) || !$hold) return; ?>
    <!-- ── Booking summary card ── -->
    <div class="bk-card">

      <!-- Header -->
      <div class="bk-card__header">
        <div>
          <p class="bk-card__ref-label">Booking Reference</p>
          <p class="bk-card__ref"><?= e($ref) ?></p>
        </div>
        <span class="bk-badge" style="background:<?= e($badge_bg) ?>;color:<?= e($badge_color) ?>">
          <?= e($badge_text) ?>
        </span>
      </div>

      <!-- Details table -->
      <div class="bk-card__body">
        <table class="bk-table">
          <tr>
            <td>Guest</td>
            <td><?= e($hold['guest_name']) ?></td>
          </tr>
          <?php if (!empty($hold['access_code'])): ?>
          <tr>
            <td>Booking code</td>
            <td style="font-family:monospace;letter-spacing:.08em"><?= e($hold['access_code']) ?></td>
          </tr>
          <?php endif; ?>
          <tr>
            <td>Property</td>
            <td>
              <?= e($hold['room_name']) ?>
              <?php if ($hold['unit_name'] && $hold['unit_name'] !== 'Unit A'): ?>
              <span style="font-size:12px;color:#9ca3af;margin-left:6px">&middot; <?= e($hold['unit_name']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <td>Check-in</td>
            <td><?= date('d M Y', strtotime($hold['check_in'])) ?></td>
          </tr>
          <tr>
            <td>Check-out</td>
            <td><?= date('d M Y', strtotime($hold['check_out'])) ?></td>
          </tr>
          <?php
            $nights = (strtotime($hold['check_out']) - strtotime($hold['check_in'])) / 86400;
          ?>
          <tr>
            <td>Duration</td>
            <td><?= (int)$nights ?> night<?= $nights !== 1 ? 's' : '' ?></td>
          </tr>

          <?php if ($status === 'pending' && $hold['expires_at']): ?>
          <tr>
            <td>Hold expires</td>
            <td class="bk-expires" id="bkCountdown">
              <?php
                $diff = strtotime($hold['expires_at']) - time();
                echo $diff > 0
                  ? gmdate('H\h i\m', $diff)
                  : 'Expiring soon';
              ?>
            </td>
          </tr>
          <?php elseif ($status === 'confirmed' && $hold['confirmed_at']): ?>
          <tr>
            <td>Confirmed</td>
            <td class="bk-confirmed-on"><?= date('d M Y', strtotime($hold['confirmed_at'])) ?></td>
          </tr>
          <?php endif; ?>
        </table>
      </div>

      <!-- Status notice -->
      <?php if ($status === 'pending'): ?>
      <div class="bk-notice bk-notice--pending">
        <strong>Awaiting confirmation</strong> — Our team will confirm or decline your hold within 24 hours. You will receive an email either way.
      </div>
      <?php elseif ($status === 'confirmed'): ?>
      <div class="bk-notice bk-notice--confirmed">
        <strong>Your booking is confirmed!</strong> Our team will be in touch with arrival details and further instructions. We look forward to welcoming you.
      </div>
      <?php elseif ($status === 'expired'): ?>
      <div class="bk-notice bk-notice--expired">
        This hold was not confirmed within the 24-hour window and has expired. Please <a href="/properties" style="color:#1E5C6B">browse our properties</a> to make a new request.
      </div>
      <?php elseif ($status === 'cancelled'): ?>
      <div class="bk-notice bk-notice--cancelled">
        This booking has been cancelled. <a href="/properties" style="color:#1E5C6B">Browse our properties</a> if you would like to make a new request.
      </div>
      <?php endif; ?>

    </div><!-- /bk-card -->
