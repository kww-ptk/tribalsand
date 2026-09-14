<?php
declare(strict_types=1);
/**
 * Gantt lane assignment — pure geometry, no I/O, no DB.
 *
 * The availability calendar positions a block from its dates alone: `left` and
 * `width`, with `top`/`bottom` fixed in CSS. That is fine while a unit holds one
 * booking at a time, which is true at every property except Maya Ilai — there a
 * single villa deliberately holds several unrelated bookings (a Double Room, a
 * Bunk Room and a One-Bed Suite can all be sold in villa 3 for the same nights).
 * Two concurrent blocks then produce two divs with byte-identical geometry and
 * opaque backgrounds, and the later one paints the earlier one out of existence.
 * A block fully contained inside another disappears the same way.
 *
 * So a unit's blocks are split into LANES and the row height is divided between
 * them. A unit whose blocks never overlap gets exactly one lane and MUST render
 * byte-identically to before — that is what keeps every other property unchanged,
 * and gantt_lane_metrics(1) reproduces the existing CSS numbers exactly.
 *
 * Test: php tests/maya_ilai_inventory.php
 */

/** .gantt-cells height, in px. Mirrors the CSS — change both together. */
const GANTT_ROW_H = 36;
/** .gantt-block top/bottom inset, in px. Mirrors the CSS. */
const GANTT_ROW_PAD = 4;
/** Vertical gap between two lanes, in px. */
const GANTT_LANE_GAP = 2;
/**
 * Thinnest a lane may get before the row grows instead.
 *
 * Splitting 28px four ways would leave 5px slivers — technically visible, but
 * unreadable and easy to mistake for a border. Below this the row grows; a Maya
 * Ilai villa row being taller than a studio row is honest, it really does hold
 * four bookings.
 */
const GANTT_LANE_MIN_H = 10;

/**
 * Assign each span to a lane so that no two spans in one lane overlap.
 *
 * $spans: [['key' => mixed, 'start' => int, 'end' => int], …] — half-open day
 * indexes [start, end), the same convention as availability_blocks.date_to (the
 * checkout morning, not the last night). Back-to-back stays in one lane: one
 * guest's checkout morning is the next guest's arrival, which is not an overlap.
 *
 * Standard first-fit interval packing: spans are sorted by start, and each is
 * placed in the first lane whose last span has already ended. The lane count is
 * therefore the maximum number of spans live at any one moment — the minimum
 * number of rows needed to show them all.
 *
 * The sort is fully deterministic (start, then end, then key as a string). Row
 * order out of the DB is `ORDER BY date_from ASC` with no tiebreaker, so without
 * that the same calendar could redraw differently between page loads.
 *
 * Returns ['lanes' => [key => lane index], 'count' => number of lanes].
 */
function gantt_lane_assign(array $spans): array {
    if (!$spans) return ['lanes' => [], 'count' => 0];

    usort($spans, static function (array $a, array $b): int {
        return ((int)$a['start'] <=> (int)$b['start'])
            ?: ((int)$a['end'] <=> (int)$b['end'])
            ?: strcmp((string)$a['key'], (string)$b['key']);
    });

    $laneEnds = [];   // lane index => the end of the last span placed in it
    $lanes    = [];
    foreach ($spans as $s) {
        $start = (int)$s['start'];
        $end   = (int)$s['end'];
        $put   = null;
        foreach ($laneEnds as $i => $lastEnd) {
            if ($lastEnd <= $start) { $put = $i; break; }
        }
        if ($put === null) { $put = count($laneEnds); }
        $laneEnds[$put]   = $end;
        $lanes[$s['key']] = $put;
    }
    return ['lanes' => $lanes, 'count' => count($laneEnds)];
}

/**
 * Pixel geometry for a row split into $laneCount lanes.
 *
 * ONE LANE MUST REPRODUCE THE EXISTING CSS EXACTLY: a 36px row with the bar
 * inset 4px top and bottom, i.e. 28px tall. Every property other than Maya Ilai
 * has one lane, so this is what guarantees they render unchanged — and the
 * renderer additionally emits no lane styles at all in that case, so their
 * markup is byte-identical rather than merely equivalent.
 *
 * Two and three lanes still fit inside the existing 36px row. Only at four does
 * the row grow, rather than drawing 5px slivers.
 *
 * Returns ['lane_h', 'row_h', 'gap', 'pad'] in px.
 */
function gantt_lane_metrics(int $laneCount): array {
    $laneCount = max(1, $laneCount);
    $inner = GANTT_ROW_H - 2 * GANTT_ROW_PAD;                       // 28
    $avail = $inner - ($laneCount - 1) * GANTT_LANE_GAP;
    $laneH = (int) floor($avail / $laneCount);
    if ($laneH < GANTT_LANE_MIN_H) $laneH = GANTT_LANE_MIN_H;

    // 1 lane -> 28px in a 36px row (today's numbers, exactly). 2 lanes -> 13px
    // each, still exactly 36. From 3 up, GANTT_LANE_MIN_H wins and the row grows.
    // By construction top(last) + lane_h == row_h - pad for every count, so the
    // bars always fill the row and the 4px bottom inset is preserved.
    $rowH = 2 * GANTT_ROW_PAD + $laneCount * $laneH + ($laneCount - 1) * GANTT_LANE_GAP;
    return ['lane_h' => $laneH, 'row_h' => $rowH,
            'gap' => GANTT_LANE_GAP, 'pad' => GANTT_ROW_PAD];
}

/** Top offset in px of lane $lane, given gantt_lane_metrics() output. */
function gantt_lane_top(int $lane, array $metrics): int {
    return (int)$metrics['pad'] + $lane * ((int)$metrics['lane_h'] + (int)$metrics['gap']);
}
