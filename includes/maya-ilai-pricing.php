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
 * Persist a posted state. Sanitised against the defaults' shape: every numeric
 * field is coerced to a non-negative number, list rows are rebuilt from known
 * keys, and unknown keys are dropped — so a tampered payload can never inject
 * arbitrary structure into the KV.
 */
function maya_ilai_pricing_save(array $state): array {
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

    set_setting(MAYA_ILAI_SETTING_KEY, json_encode($clean, JSON_UNESCAPED_SLASHES));
    return $clean;
}
