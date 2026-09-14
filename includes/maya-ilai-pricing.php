<?php
/**
 * Maya Ilai rate & quote tool — settings persistence.
 *
 * A faithful, DB-persisted port of the standalone Maya Ilai calculator. The
 * editable settings (rates, rules, inventory, group tiers, availability bands)
 * live in the `settings` KV under `maya_ilai_pricing` instead of the browser's
 * localStorage, so the whole team shares one source of truth.
 *
 * STANDALONE by design: this does NOT feed the live rooms/units/rates booking
 * engine — it's an internal quoting tool. Reconciling it into the booking
 * pricing path is a separate, later phase.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

const MAYA_ILAI_VENUE_ID   = 6;               // venues.id for Maya Ilai
const MAYA_ILAI_SETTING_KEY = 'maya_ilai_pricing';

/** Feature guard (the settings KV table is core, but stay defensive). */
function maya_ilai_pricing_supported(): bool {
    static $c = null;
    if ($c !== null) return $c;
    try { return $c = (bool) db_query("SELECT to_regclass('public.settings')")->fetchColumn(); }
    catch (Throwable $e) { return $c = false; }
}

/** The shipped defaults — must mirror the reference tool's DEFAULTS exactly. */
function maya_ilai_pricing_defaults(): array {
    return [
        'rates' => ['double' => 350, 'bunk' => 150, 'studio' => 390, 'living' => 400, 'villa' => 1170],
        'rules' => [
            'standardReduction' => 20, 'singleDiscount' => 15, 'bunkIncluded' => 3, 'bunkMax' => 6,
            'bunkExtra' => 45, 'villaIncluded' => 7, 'villaMax' => 10, 'ecoFee' => 20, 'minNights' => 3,
        ],
        'inventory' => ['villas' => 8, 'studios' => 8, 'doublePerVilla' => 2],
        'groups' => [
            ['guests' => 10, 'discount' => 5],
            ['guests' => 20, 'discount' => 7.5],
            ['guests' => 30, 'discount' => 10],
        ],
        'availability' => [
            ['min' => 6, 'max' => 8, 'adjustment' => -15, 'label' => 'Opening rate'],
            ['min' => 4, 'max' => 5, 'adjustment' => -5,  'label' => 'Light discount'],
            ['min' => 2, 'max' => 3, 'adjustment' => 0,   'label' => 'Reference rate'],
            ['min' => 1, 'max' => 1, 'adjustment' => 15,  'label' => 'Limited availability'],
            ['min' => 0, 'max' => 0, 'adjustment' => 0,   'label' => 'Sold out'],
        ],
    ];
}

/**
 * Deep-merge a stored blob over the defaults so a partial/old saved shape never
 * drops a newer key. Scalars from `extra` win; arrays recurse for associative
 * shapes and replace wholesale for lists (groups / availability).
 */
function maya_ilai_merge(array $base, $extra): array {
    if (!is_array($extra)) return $base;
    foreach ($base as $k => $v) {
        if (is_array($v) && array_is_list($v)) {
            if (isset($extra[$k]) && is_array($extra[$k])) $base[$k] = $extra[$k];   // list → replace
        } elseif (is_array($v)) {
            $base[$k] = maya_ilai_merge($v, $extra[$k] ?? null);                       // assoc → recurse
        } elseif (array_key_exists($k, (array)$extra)) {
            $base[$k] = $extra[$k];
        }
    }
    return $base;
}

/** Read the current settings (defaults merged with the saved blob). */
function maya_ilai_pricing_get(): array {
    $defaults = maya_ilai_pricing_defaults();
    if (!maya_ilai_pricing_supported()) return $defaults;
    $raw = setting(MAYA_ILAI_SETTING_KEY, '');
    if ($raw === '') return $defaults;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? maya_ilai_merge($defaults, $decoded) : $defaults;
}

/**
 * Coerce a posted state into the defaults' shape WITHOUT persisting it: every
 * numeric field becomes a non-negative number, list rows are rebuilt from known
 * keys, and unknown keys are dropped — so a tampered payload can never inject
 * arbitrary structure. Split out from maya_ilai_pricing_save() so the admin
 * tool's live preview can price un-saved edits through the identical coercion a
 * saved config gets; a preview that sanitised differently could show a figure the
 * saved config would never produce.
 */
function maya_ilai_pricing_sanitize(array $state): array {
    $d = maya_ilai_pricing_defaults();
    $num = fn($v) => max(0, (float) $v);

    $clean = $d;
    foreach ($d['rates'] as $k => $_) if (isset($state['rates'][$k])) $clean['rates'][$k] = $num($state['rates'][$k]);
    foreach ($d['rules'] as $k => $_) if (isset($state['rules'][$k])) $clean['rules'][$k] = $num($state['rules'][$k]);
    foreach ($d['inventory'] as $k => $_) if (isset($state['inventory'][$k])) $clean['inventory'][$k] = $num($state['inventory'][$k]);

    if (isset($state['groups']) && is_array($state['groups'])) {
        $clean['groups'] = [];
        foreach ($state['groups'] as $g) {
            if (!is_array($g)) continue;
            $clean['groups'][] = ['guests' => (int) $num($g['guests'] ?? 0), 'discount' => $num($g['discount'] ?? 0)];
        }
        if (!$clean['groups']) $clean['groups'] = $d['groups'];
    }
    if (isset($state['availability']) && is_array($state['availability'])) {
        $clean['availability'] = [];
        foreach ($state['availability'] as $i => $b) {
            if (!is_array($b)) continue;
            // Keep the band's fixed min/max/label from defaults where present (bands are a fixed ladder);
            // only the adjustment is user-editable, matching the reference tool.
            $base = $d['availability'][$i] ?? ['min' => 0, 'max' => 0, 'label' => ''];
            $clean['availability'][] = [
                'min'        => (int)($b['min'] ?? $base['min']),
                'max'        => (int)($b['max'] ?? $base['max']),
                'adjustment' => (float)($b['adjustment'] ?? 0),
                'label'      => (string)($b['label'] ?? $base['label']),
            ];
        }
        if (!$clean['availability']) $clean['availability'] = $d['availability'];
    }

    return $clean;
}

/** Persist a posted state, sanitised against the defaults' shape. */
function maya_ilai_pricing_save(array $state): array {
    $clean = maya_ilai_pricing_sanitize($state);
    set_setting(MAYA_ILAI_SETTING_KEY, json_encode($clean, JSON_UNESCAPED_SLASHES));
    return $clean;
}

/** Nightly rate for a unit type in a season (standard applies the reduction). */
function maya_ilai_rate(array $cfg, string $key, string $season): float {
    $base = (float)($cfg['rates'][$key] ?? 0);
    return $season === 'standard' ? $base * (1 - (float)$cfg['rules']['standardReduction'] / 100) : $base;
}

/** Highest qualifying group discount % for a party size (0 unless min nights met). */
function maya_ilai_group_discount(array $cfg, int $guests, int $nights): float {
    if ($nights < (int)$cfg['rules']['minNights']) return 0.0;
    $best = 0.0;
    foreach ($cfg['groups'] as $g) {
        if ($guests >= (int)$g['guests']) $best = max($best, (float)$g['discount']);
    }
    return $best;
}

/**
 * The named combination products the property sells.
 *
 * These are PRESENTATION ONLY — each is a bag of the primitives the tool already
 * prices, and every surface expands one into `parts` before quoting. There is no
 * combination rate and no second summation: with the shipped rates each of these
 * prices exactly as the sum of its parts (350+400, 350+150, 350+150+400,
 * 350+350+400), which is why they can be offered by name without teaching
 * maya_ilai_quote() anything new.
 *
 * The whole villa is deliberately NOT here. It is $80 cheaper than its parts
 * (2 doubles + bunk + living = 1250 vs 1170) — that whole-villa saving is why it
 * exists as its own primitive with its own rate. Leave it a primitive.
 */
function maya_ilai_combos(): array {
    return [
        ['key' => 'One-Bedroom Suite',
         'parts' => ['double' => 1, 'living' => 1],
         'desc'  => 'Double bedroom with its own living room + kitchen'],
        ['key' => 'Two-Bedroom Family Room',
         'parts' => ['double' => 1, 'bunk' => 1],
         'desc'  => 'Double bedroom + bunk room'],
        ['key' => 'Two-Bedroom Family Suite',
         'parts' => ['double' => 1, 'bunk' => 1, 'living' => 1],
         'desc'  => 'Double bedroom + bunk room, with living room + kitchen'],
        ['key' => 'Two-Bedroom Suite',
         'parts' => ['double' => 2, 'living' => 1],
         'desc'  => 'Two double bedrooms with living room + kitchen'],
    ];
}

/** Nightly price of a combination = the sum of its parts at the live rates. */
function maya_ilai_combo_rate(array $cfg, array $parts, string $season = 'high'): float {
    $sum = 0.0;
    foreach ($parts as $k => $qty) $sum += maya_ilai_rate($cfg, $k, $season) * (int)$qty;
    return $sum;
}

/**
 * Occupancy of a combination, DERIVED from its parts' own rules — never a
 * hardcoded table, so editing bunkIncluded/bunkMax in admin moves the products
 * with it. `min` is one guest per bedroom, which is what maya_ilai_quote()'s
 * own per-room validation demands.
 */
function maya_ilai_combo_occupancy(array $cfg, array $parts): array {
    $d = (int)($parts['double'] ?? 0);
    $b = (int)($parts['bunk'] ?? 0);
    return [
        'min'      => $d + $b,
        'included' => $d * 2 + $b * (int)$cfg['rules']['bunkIncluded'],
        'max'      => $d * 2 + $b * (int)$cfg['rules']['bunkMax'],
    ];
}

/**
 * Is a combination still honest to offer at the live rates?
 *
 * Every combination is a strict subset of a whole villa (2 doubles + bunk +
 * living). If someone edits the rates until a subset costs AT LEAST the whole
 * villa, the product is dominated — a guest would pay the same or more for
 * strictly less — so the surface drops it rather than quote it. The price itself
 * can never drift, because it is summed from the same rates that price the
 * expansion; this guard is only about a combination that has stopped making
 * sense as a product.
 */
function maya_ilai_combo_offerable(array $cfg, array $parts, string $season = 'high'): bool {
    $villa = ['double' => 2, 'bunk' => 1, 'living' => 1];
    $subset = true;
    foreach ($parts as $k => $qty) if ((int)$qty > (int)($villa[$k] ?? 0)) { $subset = false; break; }
    if (!$subset) return true;
    return maya_ilai_combo_rate($cfg, $parts, $season) < maya_ilai_rate($cfg, 'villa', $season);
}

/**
 * Split a combination's guests across its bedrooms the way the tool charges for
 * them: one guest per bedroom first (the tool rejects an empty selected room),
 * then each double filled to 2, then the remainder into the bunk rooms up to
 * bunkMax. The supplement then falls out of maya_ilai_quote()'s own
 * max(0, guestBunk - bunk*bunkIncluded) * bunkExtra, unchanged.
 *
 * The leading one-per-bedroom seed is load-bearing: filling doubles first alone
 * would put a 2-guest Family Room entirely in the double and leave the bunk room
 * at zero, which the tool rejects. At every guest count where the plain rule is
 * valid the two agree.
 */
function maya_ilai_split_guests(array $cfg, array $parts, int $guests): array {
    $d = (int)($parts['double'] ?? 0);
    $b = (int)($parts['bunk'] ?? 0);
    $left = max(0, $guests);

    // NB: plain min() against the running $left — an arrow fn would capture
    // $left by value and hand every step the untouched original.
    $gd = min($d, $left); $left -= $gd;                                    // one per double
    $gb = min($b, $left); $left -= $gb;                                    // one per bunk room
    $fill = min($d * 2 - $gd, $left); $gd += $fill; $left -= $fill;        // doubles to 2
    $gb += min($b * (int)$cfg['rules']['bunkMax'] - $gb, $left);           // remainder into bunks

    return ['double' => $gd, 'bunk' => $gb];
}

/**
 * Expand a selection that names combinations into one of pure primitives.
 *
 * `$sel['combos']` is a map of combination key → ['qty'=>n, 'guests'=>g]; its
 * quantities and split guests are ADDED to any primitives already in $sel. This
 * is the reference expansion the booking configurator's JS mirrors — the server
 * only ever prices primitives, so maya_ilai_quote() needs no knowledge of it.
 */
function maya_ilai_expand_combos(array $sel, ?array $cfg = null): array {
    $cfg = $cfg ?: maya_ilai_pricing_get();
    $combos = $sel['combos'] ?? [];
    unset($sel['combos']);
    if (!is_array($combos) || !$combos) return $sel;

    $byKey = [];
    foreach (maya_ilai_combos() as $c) $byKey[$c['key']] = $c;

    foreach ($combos as $key => $pick) {
        if (!isset($byKey[$key])) continue;
        $qty = max(0, (int)($pick['qty'] ?? 0));
        if (!$qty) continue;
        $parts = $byKey[$key]['parts'];

        // n copies pool into one set of parts — the tool only ever sees totals.
        $pooled = [];
        foreach ($parts as $k => $v) $pooled[$k] = (int)$v * $qty;
        $occ = maya_ilai_combo_occupancy($cfg, $pooled);
        $guests = max($occ['min'], min($occ['max'], (int)($pick['guests'] ?? $occ['included'])));
        $split = maya_ilai_split_guests($cfg, $pooled, $guests);

        $sel['qtyDouble'] = (int)($sel['qtyDouble'] ?? 0) + ($pooled['double'] ?? 0);
        $sel['qtyBunk']   = (int)($sel['qtyBunk']   ?? 0) + ($pooled['bunk']   ?? 0);
        $sel['qtyLiving'] = (int)($sel['qtyLiving'] ?? 0) + ($pooled['living'] ?? 0);
        $sel['guestDouble'] = (int)($sel['guestDouble'] ?? 0) + $split['double'];
        $sel['guestBunk']   = (int)($sel['guestBunk']   ?? 0) + $split['bunk'];

        // Combination context for the living-room allowance. The expanded totals
        // alone cannot distinguish 2× One-Bedroom Suite (two villas, two living
        // rooms) from 2 loose doubles (one villa, one living room) — they are the
        // same primitives — so record how many villas the combinations occupy and
        // which bedrooms came from them.
        $sel['comboUnits']  = (int)($sel['comboUnits']  ?? 0) + $qty;
        $sel['comboDouble'] = (int)($sel['comboDouble'] ?? 0) + ($pooled['double'] ?? 0);
        $sel['comboBunk']   = (int)($sel['comboBunk']   ?? 0) + ($pooled['bunk']   ?? 0);
    }
    return $sel;
}

/**
 * How many living rooms a selection is entitled to.
 *
 * A villa has exactly one living room / kitchen, and you cannot rent one in a
 * villa you have no bedroom in:
 *
 *     allowance = <combination units> + max(ceil(loose doubles / perVilla), loose bunks)
 *
 * Each COMBINATION unit occupies a villa of its own and so lends one allowance —
 * two One-Bedroom Suites are two villas, and two living rooms. LOOSE bedrooms
 * (picked directly, not arriving from a combination's expansion) still pack
 * densest, so two loose doubles are one villa and one living room. That split is
 * the whole point: the two selections have identical primitive totals, so the
 * allowance cannot be computed from totals alone — the caller must say how many
 * of the bedrooms came from combinations.
 *
 * Whole villas are excluded on purpose: a whole villa already includes its
 * living room and is priced accordingly, so it lends nothing to a separate one.
 *
 * $comboUnits defaults to 0, which is exactly right for any caller that has no
 * combinations (the staff tool, a hand-built primitive selection).
 */
function maya_ilai_living_allowance(array $cfg, int $looseDoubles, int $looseBunks, int $comboUnits = 0): int {
    $perVilla = max(1, (int)$cfg['inventory']['doublePerVilla']);
    return max(0, $comboUnits)
         + max((int)ceil(max(0, $looseDoubles) / $perVilla), max(0, $looseBunks));
}

/** Availability band matching a units-available count. */
function maya_ilai_availability_band(array $cfg, int $units): array {
    foreach ($cfg['availability'] as $b) {
        if ($units >= (int)$b['min'] && $units <= (int)$b['max']) return $b;
    }
    return end($cfg['availability']) ?: ['min'=>0,'max'=>0,'adjustment'=>0,'label'=>'Sold out'];
}

/**
 * Quote a Maya Ilai stay — the faithful PHP port of the tool's quote() logic, so
 * the guest booking flow and the staff tool price identically from the same
 * saved config (maya_ilai_pricing_get()). This is the ONE pricing path for Maya
 * Ilai; never add a second calculation.
 *
 * $sel: qtyDouble,qtyBunk,qtyStudio,qtyVilla,qtyLiving,
 *       guestDouble,guestBunk,guestStudio,guestVilla,
 *       nights, season ('high'|'standard'), program ('group'|'availability'|'none'),
 *       availableUnits (for the availability program).
 * Returns a full breakdown incl. 'errors' and 'total'.
 */
function maya_ilai_quote(array $sel, ?array $cfg = null): array {
    $cfg = $cfg ?: maya_ilai_pricing_get();
    $r = $cfg['rules'];
    $n = fn($k) => max(0, (int)($sel[$k] ?? 0));

    $season  = ($sel['season'] ?? 'high') === 'standard' ? 'standard' : 'high';
    $nights  = max(1, (int)($sel['nights'] ?? 1));
    $program = in_array(($sel['program'] ?? 'group'), ['group','availability','none'], true) ? ($sel['program'] ?? 'group') : 'group';

    $q = ['double'=>$n('qtyDouble'),'bunk'=>$n('qtyBunk'),'studio'=>$n('qtyStudio'),'villa'=>$n('qtyVilla'),'living'=>$n('qtyLiving')];
    $g = ['double'=>$n('guestDouble'),'bunk'=>$n('guestBunk'),'studio'=>$n('guestStudio'),'villa'=>$n('guestVilla')];
    $guests   = $g['double'] + $g['bunk'] + $g['studio'] + $g['villa'];
    $capacity = $q['double']*2 + $q['bunk']*(int)$r['bunkMax'] + $q['studio']*2 + $q['villa']*(int)$r['villaMax'];

    $rate = fn($k) => maya_ilai_rate($cfg, $k, $season);
    $singleRooms = max(0, min($q['double'], 2*$q['double'] - $g['double']));
    $doubleBase = ($q['double'] - $singleRooms)*$rate('double') + $singleRooms*$rate('double')*(1 - (float)$r['singleDiscount']/100);
    $base = $doubleBase + $q['bunk']*$rate('bunk') + $q['studio']*$rate('studio') + $q['villa']*$rate('villa') + $q['living']*$rate('living');

    $bunkExtra  = max(0, $g['bunk']  - $q['bunk'] *(int)$r['bunkIncluded'])  * (float)$r['bunkExtra'];
    $villaExtra = max(0, $g['villa'] - $q['villa']*(int)$r['villaIncluded']) * (float)$r['bunkExtra'];
    $supplements = $bunkExtra + $villaExtra;

    $adjustment = 0.0; $adjustmentLabel = 'No adjustment'; $sold = false;
    if ($program === 'group') {
        $adjustment = -maya_ilai_group_discount($cfg, $guests, $nights);
        $adjustmentLabel = $nights < (int)$r['minNights'] ? "Minimum {$r['minNights']} nights not met" : 'Group discount';
    } elseif ($program === 'availability') {
        $band = maya_ilai_availability_band($cfg, max(0, (int)($sel['availableUnits'] ?? 0)));
        $sold = (int)$band['max'] === 0;
        $adjustment = (float)$band['adjustment'];
        $adjustmentLabel = (string)$band['label'];
    }

    $adjustedBase = $base * (1 + $adjustment/100);
    $nightly = $adjustedBase + $supplements;
    $eco = $guests * (float)$r['ecoFee'];
    $total = $sold ? 0.0 : $nightly*$nights + $eco;

    // Validation mirrors the tool.
    $errors = [];
    if ($g['double'] > $q['double']*2 || $g['double'] < $q['double']) $errors[] = 'Double Room guests must be 1–2 per selected room.';
    if ($g['bunk']  > $q['bunk']*(int)$r['bunkMax']  || $g['bunk']  < $q['bunk'])  $errors[] = "Bunk guests must be 1–{$r['bunkMax']} per selected room.";
    if ($g['studio']> $q['studio']*2 || $g['studio']< $q['studio']) $errors[] = 'Studio guests must be 1–2 per selected studio.';
    if ($g['villa'] > $q['villa']*(int)$r['villaMax'] || $g['villa'] < $q['villa']) $errors[] = "Villa guests must be 1–{$r['villaMax']} per selected villa.";
    // A villa has ONE living room, and it only comes with a bedroom in that villa.
    // Distinct from the inventory check below: that one asks "do we have enough
    // villas", this asks "is this living room attached to anything at all". A
    // living room with no bedrooms passes the inventory check happily. Both stand.
    //
    // Bedrooms arriving from a combination each occupy their own villa (see
    // maya_ilai_living_allowance); the rest pack densest. A caller with no
    // combinations passes nothing and gets the loose-only rule.
    $comboUnits = max(0, (int)($sel['comboUnits'] ?? 0));
    $looseD = max(0, $q['double'] - max(0, (int)($sel['comboDouble'] ?? 0)));
    $looseB = max(0, $q['bunk']   - max(0, (int)($sel['comboBunk']   ?? 0)));
    $livingAllowance = maya_ilai_living_allowance($cfg, $looseD, $looseB, $comboUnits);
    if ($q['living'] > $livingAllowance) $errors[] = "A living room comes with a villa bedroom; {$q['living']} selected, only {$livingAllowance} available.";
    $requiredVillas = max((int)ceil($q['double'] / max(1,(int)$cfg['inventory']['doublePerVilla'])), $q['bunk'], $q['living']);
    $physicalVillas = $q['villa'] + $requiredVillas;
    if ($physicalVillas > (int)$cfg['inventory']['villas']) $errors[] = "Needs {$physicalVillas} villas; only {$cfg['inventory']['villas']} available.";
    if ($q['studio'] > (int)$cfg['inventory']['studios']) $errors[] = "Only {$cfg['inventory']['studios']} studios available.";
    if (!$guests) $errors[] = 'Add at least one guest.';
    if ($sold) $errors[] = 'The selected availability band is sold out.';

    return [
        'season'=>$season,'nights'=>$nights,'program'=>$program,'q'=>$q,'g'=>$g,
        'guests'=>$guests,'capacity'=>$capacity,'base'=>round($base,2),'supplements'=>round($supplements,2),
        'adjustment'=>$adjustment,'adjustmentLabel'=>$adjustmentLabel,'nightly'=>round($nightly,2),
        'eco'=>round($eco,2),'total'=>round($total,2),'sold'=>$sold,'errors'=>$errors,
        'livingAllowance'=>$livingAllowance,
        // Breakdown parts. Every figure a surface displays is resolved HERE, so no
        // caller ever multiplies a rate by a count of its own — that is how the
        // staff tool drifted from the guest page in the first place.
        'bunkExtra'=>round($bunkExtra,2),'villaExtra'=>round($villaExtra,2),
        'adjustedBase'=>round($adjustedBase,2),'singleRooms'=>$singleRooms,
        'adjustmentAmount'=>round($base * $adjustment / 100, 2),
        'perGuestNight'=>($guests && !$sold) ? round($total / $guests / $nights, 2) : null,
        'requiredVillas'=>$requiredVillas,'physicalVillas'=>$physicalVillas,
        'lines'=>[
            'double' => round($doubleBase, 2),
            'bunk'   => round($q['bunk']*$rate('bunk') + $bunkExtra, 2),
            'studio' => round($q['studio']*$rate('studio'), 2),
            'villa'  => round($q['villa']*$rate('villa') + $villaExtra, 2),
            'living' => round($q['living']*$rate('living'), 2),
        ],
        'caps'=>[
            'double' => $q['double']*2, 'bunk' => $q['bunk']*(int)$r['bunkMax'],
            'studio' => $q['studio']*2, 'villa' => $q['villa']*(int)$r['villaMax'],
        ],
        'currency'=>'USD',
    ];
}
