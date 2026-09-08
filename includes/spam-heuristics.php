<?php
/**
 * TRIBAL SAND · Spam heuristics
 * ------------------------------------------------------------------
 * Content checks for public forms, applied AFTER the honeypot and Turnstile.
 * Those two stop cheap automation; these catch submissions that solved the
 * captcha but whose fields are machine-generated.
 *
 * The bar for adding a rule here: it must reject observed spam and be one a
 * real guest cannot plausibly trip. A rejected enquiry is a lost booking, so
 * every rule is deliberately narrow and the caller returns a normal field
 * error the guest could act on, never a silent drop.
 */
declare(strict_types=1);

/**
 * True when a name field looks like a generated identifier rather than a name.
 *
 * Observed contact-page spam used names such as:
 *   SupWYGQjIGReLzlHVZsXTO   tFdmmfPkMIXjLhTCbFq   SHdXjGDufISzVYcchiZLv
 *
 * All three are one long unbroken token mixing many upper and lower case
 * letters — the signature of a random string generator. Three conditions must
 * hold together, and each one exists to protect a real name:
 *
 *   - no whitespace       — "Mary Jane Watson" is never a single token
 *   - at least 12 chars   — keeps ordinary single-word names out
 *   - >= 4 uppercase AND >= 4 lowercase letters within the token
 *
 * That last pairing is what makes it safe. A long single-token real name has
 * few capitals ("Christopherson", "Vandenberghe", "McDonald"), and a guest
 * shouting their name in caps ("MARIAGONZALEZ") has no lowercase at all — so
 * neither can satisfy both halves. A generated token satisfies both easily.
 */
function spam_looks_like_random_token(string $value): bool
{
    $v = trim($value);

    if ($v === '' || preg_match('/\s/u', $v)) return false;   // any space => not a single token
    if (mb_strlen($v) < 12)                   return false;   // short names stay well clear

    $upper = preg_match_all('/\p{Lu}/u', $v);
    $lower = preg_match_all('/\p{Ll}/u', $v);

    return $upper >= 4 && $lower >= 4;
}
