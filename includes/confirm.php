<?php
/**
 * The reusable confirmation for serious admin actions (delete, reset, …).
 *
 * Client: the ONE styled dialog in admin/_layout_end.php (styledConfirm) reads
 * data-confirm / data-confirm-title / data-confirm-label / data-confirm-type off
 * a submit button. With data-confirm-type the person must type the word before
 * the red button unlocks, and the typed word is posted as `confirm_text`.
 *
 * Server: typed_confirmation_ok() re-checks that word, so a crafted POST, a
 * double-click or a page with JS off can never skip the confirmation.
 *
 *   <button type="submit" class="btn-danger btn-sm"
 *     <?= danger_confirm_attrs('This stops the email for good.', 'Delete this email?', 'Delete', 'DELETE') ?>>Delete</button>
 *   …
 *   if (!typed_confirmation_ok('DELETE')) { refuse }
 */
declare(strict_types=1);

/** The data-confirm-* attributes for a submit button. $typeWord '' = plain confirm. */
function danger_confirm_attrs(string $message, string $title = '', string $label = '', string $typeWord = ''): string {
    $esc = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $a = 'data-confirm="' . $esc($message) . '"';
    if ($title !== '')    $a .= ' data-confirm-title="' . $esc($title) . '"';
    if ($label !== '')    $a .= ' data-confirm-label="' . $esc($label) . '"';
    if ($typeWord !== '') $a .= ' data-confirm-type="' . $esc($typeWord) . '"';
    return $a;
}

/** Did the person type the confirmation word? Exact match (case-sensitive), surrounding spaces ignored. */
function typed_confirmation_ok(string $word, ?array $post = null): bool {
    $post ??= $_POST;
    return $word !== '' && hash_equals($word, trim((string)($post['confirm_text'] ?? '')));
}
