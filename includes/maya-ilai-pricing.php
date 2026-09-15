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
require_once __DIR__ . '/maya-ilai-inventory.php';   // mi_can_place_products() — the live-availability oracle

const MAYA_ILAI_VENUE_ID   = 6;               // venues.id for Maya Ilai
const MAYA_ILAI_SETTING_KEY = 'maya_ilai_pricing';

/**
 * How many photographs one offer card may carry.
 *
 * The cap is about the RESPONSE, not the design: a room the owner has given
 * forty photographs must not push forty URLs through a payload that is built on
 * a guest keystroke and re-sent on every change of party or dates. Six is more
 * than a card is ever swiped through and is a handful of hundred bytes.
 */
const MAYA_ILAI_PHOTO_MAX = 6;

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

/* ───────────────────────── Configuration search ─────────────────────────────
 *
 * "How many of us are there?" → the handful of real configurations that sleep
 * that party, cheapest first. Everything below GENERATES candidate selections
 * and hands each one to maya_ilai_quote(), which stays the single authority on
 * whether a selection is bookable and what it costs. Nothing here re-implements
 * villa packing, the living-room allowance, inventory or pricing — this file has
 * already been bitten twice by a second copy of a rule, and a suggestion the
 * guest cannot actually book is worse than no suggestion at all.
 */

/**
 * The products the search may offer, with the occupancy maya_ilai_quote()
 * enforces for each. Four primitives plus the named combinations, all derived
 * from the live config so an admin rate/rule edit moves them.
 *
 * `min` is the fewest guests the product can hold (the quote rejects a selected
 * room with nobody in it), `included` the guests the rate already covers, `max`
 * the hard ceiling. Combination rows carry their parts so the selection can be
 * posted as a combination — which is what keeps `comboUnits` (and therefore the
 * living-room allowance) correct.
 */
function maya_ilai_products(?array $cfg = null): array {
    $cfg = $cfg ?: maya_ilai_pricing_get();
    $r = $cfg['rules'];
    $out = [
        ['key'=>'Three-Bedroom Villa', 'parts'=>['villa'=>1],
         'min'=>1, 'included'=>(int)$r['villaIncluded'], 'max'=>(int)$r['villaMax'], 'combo'=>false,
         'desc'=>'The whole villa — three bedrooms, living room + kitchen'],
        // NO bare bunk room. It is not a product a guest may select: it exists
        // only INSIDE the Two-Bedroom Family Room, the Two-Bedroom Family Suite
        // and the Three-Bedroom Villa, each of which contains one. It remains a
        // PRIMITIVE — those combinations expand into it and maya_ilai_quote()
        // prices it exactly as it always did (rate, extra-guest supplement,
        // villa packing all untouched). What is gone is only the ability to
        // pick one on its own. The staff tool still quotes one; that is a
        // different question and nobody has asked it.
        ['key'=>'Studio', 'parts'=>['studio'=>1],
         'min'=>1, 'included'=>2, 'max'=>2, 'combo'=>false,
         'desc'=>'Private studio'],
        ['key'=>'Double Room', 'parts'=>['double'=>1],
         'min'=>1, 'included'=>2, 'max'=>2, 'combo'=>false,
         'desc'=>'Villa double bedroom'],
    ];
    foreach (maya_ilai_combos() as $c) {
        if (!maya_ilai_combo_offerable($cfg, $c['parts'])) continue;   // dominated → never offer it
        $occ = maya_ilai_combo_occupancy($cfg, $c['parts']);
        $out[] = ['key'=>$c['key'], 'parts'=>$c['parts'],
                  'min'=>$occ['min'], 'included'=>$occ['included'], 'max'=>$occ['max'],
                  'combo'=>true, 'desc'=>$c['desc']];
    }
    return $out;
}

/** The largest party the compound can physically sleep, from the live inventory. */
function maya_ilai_max_party(?array $cfg = null): int {
    $cfg = $cfg ?: maya_ilai_pricing_get();
    return (int)$cfg['inventory']['villas'] * (int)$cfg['rules']['villaMax']
         + (int)$cfg['inventory']['studios'] * 2;
}

/**
 * Spread a party across the products of one candidate.
 *
 * Same shape as maya_ilai_split_guests() one level up: seed every unit with its
 * minimum (a selected room with nobody in it is rejected by the quote), then
 * fill each toward the guests its rate already INCLUDES, and only then spill
 * into the paid extras up to each unit's max. The within-a-combination split
 * across its own bedrooms is not repeated here — maya_ilai_expand_combos()
 * still does that with maya_ilai_split_guests(), so there is one copy of it.
 *
 * Returns product index → guests, or null when the party cannot fit this
 * candidate at all (too few to fill a room each, or too many for the beds).
 *
 * @param array<int,array{product:array,qty:int}> $picks
 * @return array<int,int>|null
 */
function maya_ilai_allocate_guests(array $picks, int $guests): ?array {
    $lo = []; $inc = []; $hi = []; $sumLo = 0; $sumHi = 0;
    foreach ($picks as $i => $pick) {
        $qty = max(0, (int)$pick['qty']);
        $lo[$i]  = (int)$pick['product']['min'] * $qty;
        $inc[$i] = (int)$pick['product']['included'] * $qty;
        $hi[$i]  = (int)$pick['product']['max'] * $qty;
        $sumLo += $lo[$i]; $sumHi += $hi[$i];
    }
    if ($guests < $sumLo || $guests > $sumHi) return null;

    $out  = $lo;
    $left = $guests - $sumLo;
    foreach ($out as $i => $_) {                       // fill toward the included count
        if ($left <= 0) break;
        $take = min(max(0, min($inc[$i], $hi[$i]) - $out[$i]), $left);
        $out[$i] += $take; $left -= $take;
    }
    foreach ($out as $i => $_) {                       // then into the paid extras
        if ($left <= 0) break;
        $take = min($hi[$i] - $out[$i], $left);
        $out[$i] += $take; $left -= $take;
    }
    return $left === 0 ? $out : null;
}

/**
 * Turn a candidate (products + quantities + an allocation) into a selection
 * maya_ilai_quote() understands. Combination rows stay expressed as
 * combinations so maya_ilai_expand_combos() records `comboUnits` — without it
 * the living-room allowance is computed against the wrong villa count.
 *
 * @param array<int,array{product:array,qty:int}> $picks
 * @param array<int,int> $alloc
 */
function maya_ilai_picks_to_sel(array $picks, array $alloc, int $nights, ?array $cfg = null): array {
    $cfg = $cfg ?: maya_ilai_pricing_get();
    $sel = ['nights' => max(1, $nights), 'season' => 'high', 'program' => 'group'];
    $primitive = ['villa'=>'Villa', 'studio'=>'Studio', 'bunk'=>'Bunk', 'double'=>'Double'];

    foreach ($picks as $i => $pick) {
        $p = $pick['product']; $qty = (int)$pick['qty']; $g = (int)($alloc[$i] ?? 0);
        if ($p['combo']) {
            $sel['combos'][$p['key']] = ['qty' => $qty, 'guests' => $g];
            continue;
        }
        $k = array_key_first($p['parts']);
        $suffix = $primitive[$k] ?? null;
        if ($suffix === null) continue;
        $sel['qty' . $suffix]   = (int)($sel['qty' . $suffix]   ?? 0) + $qty;
        $sel['guest' . $suffix] = (int)($sel['guest' . $suffix] ?? 0) + $g;
    }
    return maya_ilai_expand_combos($sel, $cfg);
}

/** "2× Studio + Private Bunk Room" — the offer's name, from its products. */
function maya_ilai_picks_label(array $picks): string {
    $bits = [];
    foreach ($picks as $pick) {
        $qty = (int)$pick['qty'];
        $bits[] = ($qty > 1 ? $qty . '× ' : '') . $pick['product']['key'];
    }
    return implode(' + ', $bits);
}

/**
 * The guests a configuration's rates already cover — the sum of its units'
 * included counts, so a Family Room (5) beside a Studio (2) covers 7.
 */
function maya_ilai_offer_included(array $offer): int {
    $n = 0;
    foreach ($offer['units'] as $u) $n += (int)($u['included'] ?? 0);
    return $n;
}

/**
 * May this configuration be offered to a party of $guests at all?
 *
 * Never quote a party for people who are not coming. A Private Bunk Room
 * includes 3 and a Two-Bedroom Family Suite includes 5; putting either in front
 * of a couple prices beds they did not ask for. So a configuration is offered
 * only when the guests its rates already cover do not EXCEED the party — summed
 * across its units, which is why "Family Room + Studio" (5 + 2) is right for a
 * party of seven and "2× Bunk Room" (3 + 3) is right for it too.
 *
 * The floor of 2 is for the solo traveller: the smallest thing the property
 * sells includes two, so without it a party of one would be shown nothing.
 *
 * ── THE ONE LINE TO CHANGE FOR A WHOLE-PROPERTY UPSELL ──────────────────────
 * This rule also hides the Three-Bedroom Villa (includes 7) from a couple, who
 * must use "Build it yourself" to buy the whole villa. If the owner decides a
 * whole-property product should always be offered as an upsell, append ONE
 * disjunct to the return below and nothing else changes anywhere:
 *
 *     || (int)($offer['quote']['q']['villa'] ?? 0) > 0
 */
function maya_ilai_offer_fits_party(array $offer, int $guests): bool {
    return maya_ilai_offer_included($offer) <= max(2, $guests);
}

/*
 * There was a maya_ilai_offer_is_bunk_only() here, keeping a bare bunk pile out
 * of the lead slot. It is gone because the bare bunk room is no longer a
 * product a guest can select at all (see maya_ilai_products), so no generated
 * configuration can be one — the predicate could never fire again. If the
 * standalone bunk room is ever reinstated, that rule has to come back with it:
 * it is in commit 919cbb6, along with the evidence that without it parties
 * 3, 4, 5, 6, 11 and 12 were fronted by a bunk pile.
 */

/**
 * What the property is actually offering you, in one clause.
 *
 * Split out from the badge so the LEAD's sentence is true of the rooms in it: a
 * whole villa, a bedroom with its own living room and kitchen, a studio and a
 * bedroom inside a shared villa are four different promises, and "one space,
 * all yours" is only honest about some of them. Composed (not duplicated) into
 * the merged lead-and-cheapest badge below.
 */
function maya_ilai_lead_note(array $offer, int $units): string {
    $q = $offer['quote']['q'] ?? [];
    $g = (int)$offer['quote']['guests'];
    if ($units !== 1) return 'The fewest separate rooms for a party of ' . $g;
    if ((int)($q['villa'] ?? 0) > 0)  return 'The whole villa, all yours';
    if ((int)($q['living'] ?? 0) > 0) {
        return ((int)($q['double'] ?? 0) + (int)($q['bunk'] ?? 0)) === 1
            ? 'A bedroom with a living room and kitchen of your own'
            : 'Bedrooms with a living room and kitchen of your own';
    }
    if ((int)($q['studio'] ?? 0) > 0 && !(int)($q['double'] ?? 0) && !(int)($q['bunk'] ?? 0)) {
        return 'A studio to yourselves';
    }
    return 'One space, all yours';
}

/**
 * The badge a suggestion wears, and the sentence under it.
 *
 * Two suggestions in a set are called out: the LEAD (what the property would
 * put you in — the fewest whole products) and the CHEAPEST. When the ranking
 * leads with a whole stay, the cheapest one is usually a pile of rooms, and the
 * guest has to be able to SEE the trade they would be taking, not just the
 * smaller number. So the note says what makes it cheap — bunk beds, rooms split
 * apart — rather than only that it is cheap.
 *
 * Derived from the offer itself, never guessed by the surface: a set of rooms
 * with no bunks reads differently from one with them, and the same function
 * decides it for every surface.
 *
 * @return array{badge:string,tag:string,why:string}
 */
function maya_ilai_offer_badge(array $offer, bool $isLead, bool $isCheapest): array {
    $units = 0;
    foreach ($offer['units'] as $u) $units += (int)$u['qty'];
    $g     = (int)$offer['quote']['guests'];
    $one   = $units === 1;

    if ($isLead && $isCheapest) {
        // Nothing is being traded away here — the wholest stay is also the
        // cheapest — so the lead's own promise carries, with the price added.
        return ['badge' => 'both', 'tag' => 'Our pick · lowest price',
                'why'   => maya_ilai_lead_note($offer, $units) . ' — and the lowest price we have.'];
    }
    if ($isLead) {
        return ['badge' => 'pick', 'tag' => 'Our pick',
                'why'   => maya_ilai_lead_note($offer, $units) . '.'];
    }
    if ($isCheapest) {
        // The trade is the SPLIT, not the bunk beds. It used to name them,
        // because the cheapest stay was then a pile of bare bunk rooms and the
        // beds were what set it apart. A guest can no longer select a bare bunk
        // room, so every stay that has one has it inside a family product — the
        // lead card included — and naming them here would single out a fact
        // that is equally true of the row above. The card's own "what's
        // included" list still says "Double bedroom + bunk room".
        $why = $one
            ? 'The cheapest stay that sleeps ' . $g . '.'
            : 'Split across ' . $units . ' separate rooms — cheaper, but not one space to yourselves.';
        return ['badge' => 'cheapest', 'tag' => 'Lowest price', 'why' => $why];
    }
    return ['badge' => '', 'tag' => '', 'why' => ''];
}

/* ──────────────────────── The photograph on an offer card ───────────────────
 *
 * The offer cards show a photograph of the configuration beside its details.
 * Nothing here is hardcoded to a file: the photo comes from `room_images`,
 * which is exactly what Admin → Rooms → <room> → Images already writes, so the
 * cards improve as the owner uploads and no code changes when they do.
 *
 * A card with no photo is a CARD, not an error. Every lookup below fails soft —
 * a missing table, a missing room, a missing image, an unreachable DB — and the
 * card renders the single-column layout it had before. A quote must never fail
 * because a photograph could not be found.
 */

/**
 * Product key → `rooms.slug`.
 *
 * The pricing tool's products are named strings; the photo library is keyed by
 * room. This is the one place the two vocabularies meet, and it is a map rather
 * than a derivation because the room slugs are the owner's, not ours.
 *
 * 'Private Bunk Room' is listed but UNREACHABLE today: the bare bunk room is not
 * a product a guest can select (see maya_ilai_products), so no generated
 * configuration ever carries it as a unit and it can never be a dominant
 * product. It appears here only INSIDE the Family Room, the Family Suite and
 * the whole Villa, each of which has a room and a slug of its own. The entry is
 * kept so that reinstating the standalone bunk room needs no change here.
 */
function maya_ilai_room_slugs(): array {
    return [
        'Three-Bedroom Villa'      => 'maya-ilai-villa',
        'Studio'                   => 'maya-ilai-studio',
        'Double Room'              => 'maya-ilai-double',
        'One-Bedroom Suite'        => 'maya-ilai-one-bed-suite',
        'Two-Bedroom Family Room'  => 'maya-ilai-family-room',
        'Two-Bedroom Family Suite' => 'maya-ilai-family-suite',
        'Two-Bedroom Suite'        => 'maya-ilai-two-bed-suite',
        'Private Bunk Room'        => 'maya-ilai-bunk-room',
    ];
}

/**
 * Nightly rate of a product by name, summed from its parts at the live rates.
 *
 * This is NOT a second pricing path: it resolves nothing a guest is charged. It
 * is the tie-breaker for "which of these rooms is the one worth photographing",
 * and it reads the same rates maya_ilai_quote() does so the answer moves with
 * an admin rate edit. Unknown key → 0, which simply loses every tie.
 */
function maya_ilai_product_rate(string $key, ?array $cfg = null): float {
    $cfg = $cfg ?: maya_ilai_pricing_get();
    foreach (maya_ilai_products($cfg) as $p) {
        if ($p['key'] === $key) return maya_ilai_combo_rate($cfg, $p['parts']);
    }
    return 0.0;
}

/**
 * The product a configuration is OF — the one whose photograph should front it.
 *
 * The rule: the product contributing the MOST UNITS, ties broken by the higher
 * nightly rate, and (for determinism only, since two products never share both)
 * by name. So "Villa + Studio" is one unit each and the villa's $1170 beats the
 * studio's $390 — the card shows the villa; "2× Double Room" is one product at
 * two units and shows the double.
 *
 * Units carry what the guest was offered, so a configuration built from a
 * combination resolves to the combination's OWN room (the Family Suite has a
 * room and photos of its own) rather than to the bedrooms it expands into.
 *
 * @return string|null the product key, or null for an offer with no units.
 */
function maya_ilai_dominant_product(array $units, ?array $cfg = null): ?string {
    $cfg  = $cfg ?: maya_ilai_pricing_get();
    $best = null; $bestQty = -1; $bestRate = -1.0;
    foreach ($units as $u) {
        $key = trim((string)($u['key'] ?? ''));
        $qty = max(0, (int)($u['qty'] ?? 0));
        if ($key === '' || $qty < 1) continue;
        $rate = maya_ilai_product_rate($key, $cfg);
        if ($qty > $bestQty
            || ($qty === $bestQty && ($rate > $bestRate
                || ($rate === $bestRate && $key < (string)$best)))) {
            $best = $key; $bestQty = $qty; $bestRate = $rate;
        }
    }
    return $best;
}

/**
 * Every photograph the offer cards can possibly need, in ONE query.
 *
 * maya_ilai_suggest() runs on a guest keystroke, so the images cannot be looked
 * up per offer per render. The candidate set is FIXED (the eight Maya Ilai
 * rooms plus the venue's own photographs), so the whole library is fetched once
 * and memoised for the request — the cost is one query whether the search
 * returns one offer or eight, and whether it is called once or twenty times.
 * That ONE query is load-bearing; tests assert it.
 *
 * Each room carries ALL of its images, hero first and then by sort order —
 * the set the card sliders through, not just the one it used to show. The
 * per-room list is capped at MAYA_ILAI_PHOTO_MAX in SQL rather than in PHP, so
 * a forty-photograph room does not travel over the wire to be thrown away.
 *
 * Rooms are not filtered by `is_published`: the pricing tool sells these
 * products regardless of whether the room has a public listing page, so an
 * unpublished room should still contribute its photographs.
 *
 * Returns ['rooms' => slug => row[], 'venue' => row[]]; on ANY failure both are
 * empty and the cards simply carry no photo.
 *
 * @param bool $reload drop the memo (tests seed rows inside a transaction).
 */
function maya_ilai_photo_index(bool $reload = false): array {
    static $idx = null;
    if ($idx !== null && !$reload) return $idx;

    $idx   = ['rooms' => [], 'venue' => []];
    $slugs = array_values(maya_ilai_room_slugs());
    $ph = []; $params = [];
    foreach ($slugs as $i => $s) { $ph[] = ":s{$i}"; $params[":s{$i}"] = $s; }
    $params[':vid'] = MAYA_ILAI_VENUE_ID;
    // Two placeholders for one value: PDO's native (unemulated) prepares do not
    // let a named parameter appear twice.
    $params[':cap1'] = MAYA_ILAI_PHOTO_MAX;
    $params[':cap2'] = MAYA_ILAI_PHOTO_MAX;

    // One statement, two sources: every image of each room (hero first, then by
    // sort order), and the venue's own images as the last-resort fallback. The
    // venue rows are tagged with an empty slug so the two are told apart, and
    // `rn` carries the order through the UNION so PHP never has to re-sort.
    $sql = "WITH room_pick AS (
                SELECT r.slug AS slug,
                       i.filename AS filename,
                       COALESCE(i.alt_text, '') AS alt_text,
                       COALESCE(r.name, '') AS name,
                       ROW_NUMBER() OVER (PARTITION BY r.slug
                                          ORDER BY COALESCE(i.is_hero, FALSE) DESC,
                                                   i.sort_order ASC, i.id ASC) AS rn
                FROM rooms r
                JOIN room_images i ON i.room_id = r.id
                WHERE r.slug IN (" . implode(',', $ph) . ")
            ), venue_pick AS (
                SELECT ''::text AS slug, v.filename,
                       COALESCE(v.alt_text, '') AS alt_text, ''::text AS name,
                       ROW_NUMBER() OVER (ORDER BY COALESCE(v.is_hero, FALSE) DESC,
                                                   v.sort_order ASC, v.id ASC) AS rn
                FROM venue_images v
                WHERE v.venue_id = :vid
            )
            SELECT slug, filename, alt_text, name, rn FROM room_pick  WHERE rn <= :cap1
            UNION ALL
            SELECT slug, filename, alt_text, name, rn FROM venue_pick WHERE rn <= :cap2
            ORDER BY slug ASC, rn ASC";

    try {
        $GLOBALS['__mib_photo_queries'] = (int)($GLOBALS['__mib_photo_queries'] ?? 0) + 1;
        foreach (db_query($sql, $params)->fetchAll() as $row) {
            if (($row['slug'] ?? '') === '') $idx['venue'][] = $row;
            else                             $idx['rooms'][$row['slug']][] = $row;
        }
    } catch (Throwable $e) {
        // No table, no DB, no schema — no photographs. Never a broken quote.
        $idx = ['rooms' => [], 'venue' => []];
    }
    return $idx;
}

/** How many times the photo index has actually hit the DB this request. */
function maya_ilai_photo_query_count(): int {
    return (int)($GLOBALS['__mib_photo_queries'] ?? 0);
}

/**
 * The photographs for one offer: a list of ['url','alt','source'], possibly
 * empty. Element 0 is the one the card shows first, and is exactly the single
 * photograph this used to resolve.
 *
 * Resolution order, and nothing else:
 *   1. the dominant product's room — ALL of its images, hero first, then by
 *      sort order, capped at MAYA_ILAI_PHOTO_MAX
 *   2. the venue's own images, same order, same cap
 *   3. [] — and the card renders single-column, exactly as it did before
 *
 * It is one set or the other, never a blend: a card that reaches the venue
 * fallback is showing the property, and interleaving a room photograph into
 * that run would make the slider claim to show a room it does not show. Whether
 * a room exists at all is not an error either — six of the eight products come
 * from a migration that is not on production yet, so they simply fall back.
 *
 * The alt text is honest about which branch happened. A room's photo is
 * labelled with the room (its own alt text, else the room's name, else the
 * product); the VENUE fallback is labelled with the venue's alt text and never
 * with the product name — that photo is the property, and captioning a general
 * compound shot "Two-Bedroom Family Suite" would claim to show a room it does
 * not show.
 *
 * ── TO DROP THE VENUE FALLBACK ────────────────────────────────────────────
 * Delete the `$idx['venue']` branch. Cards then carry photos only once the
 * owner has uploaded some for that product, and show none until then.
 */
function maya_ilai_offer_photos(array $offer, ?array $cfg = null): array {
    $key  = maya_ilai_dominant_product($offer['units'] ?? [], $cfg);
    $idx  = maya_ilai_photo_index();
    $slug = $key !== null ? (maya_ilai_room_slugs()[$key] ?? null) : null;

    // A row whose file resolves to nothing is dropped rather than shown as a
    // blank frame — the same instinct as the browser-side error handler.
    $build = function (array $rows, callable $alt, string $source): array {
        $out = [];
        foreach ($rows as $r) {
            $url = storage_url((string)$r['filename']);
            if ($url === '') continue;
            $out[] = ['url' => $url, 'alt' => $alt($r), 'source' => $source];
            if (count($out) >= MAYA_ILAI_PHOTO_MAX) break;
        }
        return $out;
    };

    if ($slug !== null && !empty($idx['rooms'][$slug])) {
        $photos = $build($idx['rooms'][$slug],
            fn(array $r) => $r['alt_text'] !== '' ? $r['alt_text']
                          : ($r['name'] !== '' ? $r['name'] : (string)$key),
            'room');
        if ($photos) return $photos;
    }
    if (!empty($idx['venue'])) {
        return $build($idx['venue'],
            fn(array $r) => $r['alt_text'] !== '' ? $r['alt_text'] : 'Maya Ilai',
            'venue');
    }
    return [];
}

/**
 * The live inventory a candidate configuration would consume, in the vocabulary
 * mi_can_place_products() speaks: a list of villa-consuming product room-slugs
 * (one entry per unit) plus a studio count.
 *
 * This is the one place the pricing tool's product NAMES meet the composite
 * inventory's room SLUGS — maya_ilai_room_slugs() already owns that map, and each
 * villa-consuming slug it yields is a key of mi_product_map(). The studio is the
 * only product that is not a villa slice, so it is split out to be counted. A
 * product with no known slug is skipped (it cannot be placed, so a candidate
 * carrying one is dropped by the caller).
 *
 * @param array<int,array{product:array,qty:int}> $picks
 * @return array{villaSlugs:string[],studios:int}
 */
function maya_ilai_offer_demand(array $picks): array {
    $slugs = maya_ilai_room_slugs();          // product key => rooms.slug
    $villaSlugs = []; $studios = 0;
    foreach ($picks as $pick) {
        $key = (string)($pick['product']['key'] ?? '');
        $qty = max(0, (int)($pick['qty'] ?? 0));
        if ($qty < 1) continue;
        $slug = $slugs[$key] ?? null;
        if ($slug === null) continue;
        if ($slug === 'maya-ilai-studio') { $studios += $qty; continue; }
        for ($i = 0; $i < $qty; $i++) $villaSlugs[] = $slug;
    }
    return ['villaSlugs' => $villaSlugs, 'studios' => $studios];
}

/**
 * Configurations that sleep $guests, whole stays first.
 *
 * The search is a bounded depth-first enumeration of product multisets. Three
 * things keep it small enough to run on a keystroke:
 *
 *   1. A branch is RECORDED and abandoned the moment its capacity covers the
 *      party — adding a ninth bed to a stay that already sleeps everyone only
 *      makes it dearer, and the quote would rank it last anyway.
 *   2. Quantities are capped by what the inventory can possibly allow (8 villas,
 *      8 studios, two doubles per villa), and the running villa load is capped
 *      at the villa count, so branches the quote would reject die early.
 *   3. Total units are capped at ceil(guests/2)+1 (never more than villas +
 *      studios) — every product sleeps at least two, so no cheaper arrangement
 *      lives beyond that depth.
 *
 * Every surviving candidate is then QUOTED, and anything with errors is thrown
 * away. maya_ilai_quote() is both the feasibility oracle and the pricer, so a
 * suggestion is bookable by construction. The ranking below only ever REORDERS
 * that set — it can never introduce a stay the quote has not already accepted.
 *
 * $live, when given, is a live-availability snapshot (mi_live_availability()):
 * ['villaStates'=>…, 'freeStudios'=>int, 'reserved'=>int]. Every candidate is
 * then checked against the REAL calendar with mi_can_place_products() and dropped
 * if it cannot be booked for the dates — BEFORE ranking, de-duplication and the
 * limit, so the lead/cheapest are chosen only among genuinely bookable stays.
 * Omit it (null) to search the static inventory as before (its prior behaviour,
 * and the shape the pre-migration path and the unit tests still rely on).
 *
 * @return array<int,array{sel:array,quote:array,label:string,units:array,badge:string,tag:string,why:string}>
 */
function maya_ilai_suggest(int $guests, int $nights, ?array $cfg = null, int $limit = 5, ?array $live = null): array {
    $cfg    = $cfg ?: maya_ilai_pricing_get();
    $guests = max(1, $guests);
    $nights = max(1, $nights);
    $limit  = max(1, $limit);
    if ($guests > maya_ilai_max_party($cfg)) return [];

    $products = maya_ilai_products($cfg);
    if (!$products) return [];
    // Big sleepers first: a branch dies as soon as it covers the party, so
    // covering fast is what keeps the tree shallow.
    usort($products, fn($a, $b) => [$b['max'], $a['key']] <=> [$a['max'], $b['key']]);

    $villas   = max(0, (int)$cfg['inventory']['villas']);
    $studios  = max(0, (int)$cfg['inventory']['studios']);
    $perVilla = max(1, (int)$cfg['inventory']['doublePerVilla']);

    // Per-product quantity ceiling, and how much of a villa one unit consumes.
    // A studio is not in a villa; everything else is (a combination unit is a
    // villa of its own, which is exactly what the living-room allowance says).
    $cap = []; $villaWeight = [];
    foreach ($products as $i => $p) {
        if (isset($p['parts']['studio']))     { $cap[$i] = $studios;            $villaWeight[$i] = 0.0; }
        elseif (isset($p['parts']['villa']))  { $cap[$i] = $villas;             $villaWeight[$i] = 1.0; }
        elseif ($p['combo'])                  { $cap[$i] = $villas;             $villaWeight[$i] = 1.0; }
        elseif (isset($p['parts']['bunk']))   { $cap[$i] = $villas;             $villaWeight[$i] = 1.0; }
        else                                  { $cap[$i] = $villas * $perVilla; $villaWeight[$i] = 1.0 / $perVilla; }
    }

    $maxUnits = min(max(1, $villas + $studios), max(2, (int)ceil($guests / 2) + 1));
    $count    = count($products);

    $candidates = [];
    $dfs = function (int $i, array $qty, int $units, int $capacity, int $minSum, float $villaLoad)
            use (&$dfs, $products, $cap, $villaWeight, $guests, $maxUnits, $villas, $count, &$candidates): void {
        if ($capacity >= $guests) {                         // covered — record, never extend
            if ($minSum <= $guests) $candidates[] = $qty;
            return;
        }
        if ($i >= $count || $units >= $maxUnits) return;
        $next = ($qty[$i] ?? 0) + 1;
        if ($next <= $cap[$i] && $villaLoad + $villaWeight[$i] <= $villas + 1e-9) {
            $more = $qty; $more[$i] = $next;
            $dfs($i, $more, $units + 1, $capacity + (int)$products[$i]['max'],
                 $minSum + (int)$products[$i]['min'], $villaLoad + $villaWeight[$i]);
        }
        $dfs($i + 1, $qty, $units, $capacity, $minSum, $villaLoad);
    };
    $dfs(0, [], 0, 0, 0, 0.0);

    // Quote every candidate; the quote decides what is real. De-duplication
    // happens HERE rather than over a finished list: the same primitives at the
    // same price are ONE offer however they were assembled ("Two-Bedroom Family
    // Room" and "Double Room + Private Bunk Room" are the same rooms at the same
    // money), and keeping only the best spelling of each as we go is what stops
    // a full-compound party from holding thousands of quotes in memory at once.
    // The survivor is the one expressed as the fewest whole products — that is
    // the one that reads like a stay rather than a parts list.
    $best = [];
    foreach ($candidates as $qty) {
        $picks = [];
        foreach ($qty as $i => $n) if ($n > 0) $picks[] = ['product' => $products[$i], 'qty' => (int)$n];
        if (!$picks) continue;
        $alloc = maya_ilai_allocate_guests($picks, $guests);
        if ($alloc === null) continue;

        $sel   = maya_ilai_picks_to_sel($picks, $alloc, $nights, $cfg);
        $quote = maya_ilai_quote($sel, $cfg);
        if ($quote['errors']) continue;                      // not bookable → not an offer
        if ((int)$quote['guests'] !== $guests) continue;      // paranoia: the party must be seated

        // Live calendar check. A configuration the static rules accept may still
        // be unbookable for these dates (a villa already holds a booking, its one
        // living room is gone). mi_can_place_products() replays the real
        // allocation against the live occupancy snapshot, so a stay we offer can
        // actually be held. Skipped entirely when no snapshot was supplied.
        if ($live !== null) {
            $demand = maya_ilai_offer_demand($picks);
            if (!mi_can_place_products(
                    $live['villaStates'] ?? [],
                    $demand['villaSlugs'],
                    $demand['studios'],
                    (int)($live['freeStudios'] ?? 0),
                    (int)($live['reserved'] ?? 0))) {
                continue;                                    // not bookable for these dates
            }
        }

        $units = [];
        foreach ($picks as $i => $pick) {
            $units[] = [
                'key'    => $pick['product']['key'],
                'qty'    => (int)$pick['qty'],
                'guests' => (int)($alloc[$i] ?? 0),
                'desc'     => $pick['product']['desc'],
                'max'      => (int)$pick['product']['max'] * (int)$pick['qty'],
                'included' => (int)$pick['product']['included'] * (int)$pick['qty'],
            ];
        }
        $offer = [
            'sel'   => $sel,
            'quote' => $quote,
            'label' => maya_ilai_picks_label($picks),
            'units' => $units,
            // ranking / de-duplication scratch
            '_units' => array_sum(array_column($units, 'qty')),
            '_combo' => (int)array_sum(array_map(fn($p) => $p['product']['combo'] ? (int)$p['qty'] : 0, $picks)),
        ];

        $q   = $quote['q'];
        $key = implode('/', [$q['double'], $q['bunk'], $q['studio'], $q['villa'], $q['living'],
                             number_format((float)$quote['total'], 2, '.', '')]);
        $cur = $best[$key] ?? null;
        if ($cur === null
            || [$offer['_units'], -$offer['_combo'], $offer['label']]
             < [$cur['_units'],   -$cur['_combo'],   $cur['label']]) {
            $best[$key] = $offer;
        }
    }
    $offers = array_values($best);

    // Drop anything that would quote the party for people who are not coming.
    // This runs AFTER de-duplication safely: the included count is a function of
    // the primitives alone (a double covers 2, a bunk room 3, a studio 2, a
    // villa 7), so two spellings of the same rooms always agree on it and the
    // survivor can never be the one that does not fit.
    //
    // An oversized suggestion still beats a blank page: if nothing fits this
    // party, the constraint is relaxed for it rather than returning nothing.
    // (Measured: no party from 1 to the compound maximum needs this.)
    $fitting = array_values(array_filter($offers, fn($o) => maya_ilai_offer_fits_party($o, $guests)));
    if ($fitting) $offers = $fitting;

    // WHOLE STAYS FIRST, then price. A stay expressed as one product — a villa,
    // a family suite, a single studio — outranks the pile of rooms that sleeps
    // the same party, because fewer units means more private and more complete,
    // and that is what the property sells. Leading a family of seven with "2×
    // Private Bunk Room" sells the place as a hostel.
    //
    // Price is the SECOND sort, not the first, and then the snuggest fit — a
    // 7-guest party should not be shown a 20-bed arrangement above one that fits.
    usort($offers, fn($a, $b) =>
        [$a['_units'], (float)$a['quote']['total'], (int)$a['quote']['capacity'] - $guests, $a['label']]
        <=> [$b['_units'], (float)$b['quote']['total'], (int)$b['quote']['capacity'] - $guests, $b['label']]);

    // …but the genuinely cheapest stay is never hidden. A guest looking for the
    // cheapest way to sleep their party must find it without going back a step,
    // so it is GUARANTEED into the returned set — swapped in over the last row
    // when the wholeness sort pushed it past the limit — and pulled up to slot
    // two. Slot one stays the property's own pick; slot two is the first place
    // a skimming eye lands, so the cheapest is impossible to miss without
    // leading with it. Buried at slot five would read as hiding it.
    //
    // Ties keep the earliest (best-ranked) spelling, so the cheapest is also the
    // wholest arrangement at that price.
    if (!$offers) return [];
    $cheapAt = 0;
    foreach ($offers as $i => $o) {
        if ((float)$o['quote']['total'] < (float)$offers[$cheapAt]['quote']['total'] - 0.005) $cheapAt = $i;
    }

    // Work in indices into the ranked list, so guaranteeing the cheapest into a
    // set of $limit stays honest about which row it displaced. The lead needs no
    // guarantee of its own: it is simply the best-ranked stay, which is index 0
    // and is only ever absent when a limit of one went to the cheapest instead.
    $take = range(0, min($limit, count($offers)) - 1);
    if (!in_array($cheapAt, $take, true)) { array_pop($take); $take[] = $cheapAt; }

    // Slot one is the pick, slot two the cheapest (when they are not the same
    // row); everything else keeps its ranked order behind them.
    $order = [];
    if (in_array(0, $take, true)) $order[] = 0;
    if ($cheapAt !== 0 && in_array($cheapAt, $take, true)) $order[] = $cheapAt;
    foreach ($take as $i) if (!in_array($i, $order, true)) $order[] = $i;

    // The photographs are resolved HERE, server-side, for the offers that are
    // actually returned — the surface renders what it is handed rather than
    // guessing which room a configuration is of. The whole library is one
    // memoised query (maya_ilai_photo_index), so this loop adds no per-offer
    // cost, and `photos` is an empty list whenever there is nothing to show.
    $out = [];
    foreach ($order as $slot => $i) {
        $o = $offers[$i];
        unset($o['_units'], $o['_combo']);
        $out[$slot] = $o
            + ['photos' => maya_ilai_offer_photos($o, $cfg)]
            + maya_ilai_offer_badge($o, $slot === 0 && $i === 0, $i === $cheapAt);
    }
    return $out;
}
