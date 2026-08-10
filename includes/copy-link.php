<?php
/**
 * Shared "booking code + copy guest-portal-link" control (redesign #10 / #9).
 *
 * One render used by Holds, Submission-view and the Booking → Details tab so the
 * control looks identical everywhere: the access code on its own line, and the
 * portal URL on the next row with an icon copy button + tooltip.
 *
 * Clipboard + "Copied!" feedback is handled by admin/assets/admin-copy.js
 * (delegated on `.copy-link`), so this works inside AJAX-swapped table bodies too.
 *
 * Requires e() (includes/db.php) and admin_icon() (includes/icons.php).
 */
declare(strict_types=1);

if (!function_exists('copy_link_control')) {
    /**
     * @param string $code Guest access / booking code (may be empty).
     * @param string $link Guest portal URL (may be empty — control hides if so).
     */
    function copy_link_control(string $code, string $link): void
    {
        $code = trim($code);
        $link = trim($link);
        ?>
        <div class="copyctl">
          <?php if ($code !== ''): ?>
          <div class="copyctl__code">Booking code: <strong><?= e($code) ?></strong></div>
          <?php endif; ?>
          <?php if ($link !== ''): ?>
          <div class="copyctl__row">
            <span class="copyctl__url" data-tip="<?= e($link) ?>"><?= e($link) ?></span>
            <button type="button" class="btn-icon copyctl__btn copy-link" data-link="<?= e($link) ?>" data-copy-icon
                    data-tip="Copy guest portal link" aria-label="Copy guest portal link"><?= admin_icon('copy') ?></button>
          </div>
          <?php endif; ?>
        </div>
        <?php
    }
}
