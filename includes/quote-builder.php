<?php
declare(strict_types=1);
/**
 * Quote Builder — prices a staff quote across every property, plus activities,
 * transfers and custom lines. Used by admin/quote-builder.php and the enquiry
 * pop-up (admin/submission-view.php) through api/quote-builder.php.
 *
 * READ-ONLY: nothing is saved, no hold is placed, nothing is sent. The output is
 * text / a printable page that staff send through the normal reply flow.
 *
 * ONE pricing path: room figures come from room_stay_quotes() (the same
 * summation room_stay_quote() — the booking widget's quote — runs), availability
 * from count_available_units() / find_available_unit(). Activities are priced
 * from tours.price_amount (USD), transfers from service_options (site currency).
 * A client-sent price is used only for custom lines and explicitly edited
 * catalogue prices — staff input by definition.
 *
 * Money: each line keeps its own currency; qb_totals() converts every line to
 * the quote currency with rc_convert() and the quote says when it did.
 * Discount % applies to accommodation only (extras are never discounted).
 *
 * Depends on includes/db.php; requires rates.php, rates-compare.php, services.php.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rates.php';
require_once __DIR__ . '/rates-compare.php';
require_once __DIR__ . '/services.php';

const QB_CURRENCIES = ['KES', 'USD'];
const QB_BASES      = ['stay', 'night', 'person'];

/** pdo_pgsql booleans arrive as bool or 't'/'f'. */
function qb_bool($v): bool {
    return $v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true';
}

/** An extra's line amount for its basis (unknown basis = per stay). */
function qb_extra_amount(float $unit, int $qty, string $basis, int $nights): float {
    $qty = max(0, $qty);
    $amt = $basis === 'night' ? $unit * max(0, $nights) * $qty : $unit * $qty;
    return round($amt, 2);
}

/**
 * Sum room lines and extra lines into the quote currency. Lines are
 * ['amt' => float, 'cur' => 'KES'|'USD'|…] (null entries are skipped).
 * A line whose currency has no rate is NOT summed (never counted as 0 silently):
 * its currency is listed in 'missing' so the caller can say so.
 */
function qb_totals(array $roomLines, array $extraLines, float $discountPct, string $cur, array $rates): array {
    $converted = false;
    $missing   = [];
    $sum = function (array $lines) use ($cur, $rates, &$converted, &$missing): float {
        $t = 0.0;
        foreach ($lines as $l) {
            if ($l === null) continue;
            $from = strtoupper((string)$l['cur']);
            if ($from !== $cur) $converted = true;
            $v = rc_convert((float)$l['amt'], $from, $cur, $rates);
            if ($v === null) { $missing[$from] = true; continue; }
            $t += $v;
        }
        return round($t, 2);
    };
    $acc  = $sum($roomLines);
    $ext  = $sum($extraLines);
    $pct  = max(0.0, min(100.0, $discountPct));
    $disc = round($acc * $pct / 100, 2);
    return [
        'accommodation' => $acc, 'discount' => $disc, 'discount_pct' => $pct, 'extras' => $ext,
        'total' => round($acc - $disc + $ext, 2), 'converted' => $converted, 'missing' => array_keys($missing),
    ];
}

/**
 * Warnings/infos for the summary panel. $picked rows: name, qty, guests,
 * capacity (already × qty), free (int|null), free_exact (false for Maya Ilai
 * composite products, where free means "at least one"). $unpricedExtras = labels.
 * @return list<array{type: string, text: string}>
 */
function qb_notices(array $picked, int $party, bool $okDates, array $unpricedExtras): array {
    $out = [];
    if (!$okDates) $out[] = ['type' => 'warn', 'text' => 'Choose check-in and check-out dates.'];
    $guests = 0; $cap = 0;
    foreach ($picked as $p) {
        $guests += (int)$p['guests'];
        $cap    += (int)$p['capacity'];
        if ((int)$p['capacity'] > 0 && (int)$p['guests'] > (int)$p['capacity']) {
            $out[] = ['type' => 'warn', 'text' => "{$p['name']}: {$p['guests']} guests, sleeps {$p['capacity']}."];
        }
        if ($okDates && $p['free'] !== null) {
            if (empty($p['free_exact'])) {
                if ((int)$p['free'] === 0) $out[] = ['type' => 'warn', 'text' => "{$p['name']}: not free for these dates."];
            } elseif ((int)$p['qty'] > (int)$p['free']) {
                $out[] = ['type' => 'warn', 'text' => (int)$p['free'] === 0
                    ? "{$p['name']}: not free for these dates."
                    : "{$p['name']}: only {$p['free']} free for these dates."];
            }
        }
    }
    if ($picked && $party > 0 && $cap > 0 && $cap < $party) {
        $out[] = ['type' => 'warn', 'text' => "The rooms chosen sleep {$cap}; the party is {$party}."];
    }
    if ($picked && $party > 0 && $guests !== $party) {
        $out[] = ['type' => 'info', 'text' => "Guests allocated ({$guests}) differ from the party ({$party})."];
    }
    foreach ($unpricedExtras as $label) $out[] = ['type' => 'warn', 'text' => "{$label}: add a price."];
    return $out;
}

/** "Converted at 1 USD = 129 KES on 29 Sep 2026." */
function qb_fx_note(array $rates, string $cur, string $today): string {
    $kes = (float)($rates['KES'] ?? 0);
    return 'Converted at 1 USD = ' . rc_trimz(number_format($kes, 2, '.', '')) . ' KES on '
         . date('j M Y', strtotime($today)) . '.';
}

/** Money for the quote text, in the quote currency ("KES 48,360", "$375"). */
function qb_fmt(float $amt, string $cur): string {
    return rc_money_text($amt, $cur);
}

/**
 * The copy-and-paste quote. $q: name, check_in, check_out, nights, adults,
 * children, currency, today, rooms[name,qty,mix,amt], extras[label,qty,amt],
 * discount_pct, discount_note, discount, total, fx_note (string|null).
 * Amounts are already in $q['currency'].
 */
function qb_quote_text(array $q): string {
    $c = $q['currency'];
    $party = (int)$q['adults'] . ' adult' . ((int)$q['adults'] === 1 ? '' : 's');
    if ((int)$q['children'] > 0) $party .= ', ' . (int)$q['children'] . ' child' . ((int)$q['children'] === 1 ? '' : 'ren');
    $lines = [];
    $lines[] = 'Tribal Sand — quote' . (trim((string)$q['name']) !== '' ? ' for ' . trim((string)$q['name']) : '');
    if ((int)$q['nights'] > 0) {
        $lines[] = date('j M Y', strtotime($q['check_in'])) . ' → ' . date('j M Y', strtotime($q['check_out']))
                 . ' · ' . (int)$q['nights'] . ' night' . ((int)$q['nights'] === 1 ? '' : 's') . ' · ' . $party;
    }
    if ($q['rooms']) {
        $lines[] = '';
        $lines[] = 'Accommodation';
        foreach ($q['rooms'] as $r) {
            $lines[] = '• ' . $r['name'] . ' × ' . (int)$r['qty'] . ($r['mix'] !== '' ? ' (' . $r['mix'] . ')' : '')
                     . ': ' . qb_fmt((float)$r['amt'], $c);
        }
        if ((float)$q['discount'] > 0) {
            $lines[] = 'Discount ' . rc_trimz(number_format((float)$q['discount_pct'], 2, '.', '')) . '%'
                     . (trim((string)$q['discount_note']) !== '' ? ' (' . trim((string)$q['discount_note']) . ')' : '')
                     . ': −' . qb_fmt((float)$q['discount'], $c);
        }
    }
    if ($q['extras']) {
        $lines[] = '';
        $lines[] = 'Extras';
        foreach ($q['extras'] as $x) {
            $lines[] = '• ' . $x['label'] . ' × ' . (int)$x['qty'] . ': ' . qb_fmt((float)$x['amt'], $c);
        }
    }
    $lines[] = '';
    $lines[] = 'Total: ' . qb_fmt((float)$q['total'], $c);
    if (!empty($q['fx_note'])) $lines[] = $q['fx_note'];
    $lines[] = 'Prices valid on ' . date('j M Y', strtotime($q['today'])) . '; subject to availability until booked.';
    return implode("\n", $lines);
}
