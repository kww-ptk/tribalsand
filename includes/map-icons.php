<?php
declare(strict_types=1);
/**
 * Plain line icons for the interactive site map.
 *
 * These replace the old single-letter badges (`<span class="map-ico">PK</span>`),
 * which were ambiguous — the same letter meant different things in different
 * categories ("S" was both Sport and the Energy & Water Hub, "M" was both the
 * All filter and Staff House). Icons are keyed by MEANING, not by letter.
 *
 * Deliberately not emoji: emoji render differently per OS/browser, can't inherit
 * the dot colour, and look inconsistent against the map. These are inline SVG
 * strokes using currentColor, so they take the colour of whatever contains them.
 */

/** The icon path set. 24×24 viewBox, stroke-only, no fills. */
function map_icon_paths(): array {
    return [
        // Filter / category icons
        'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'bed'       => '<path d="M3 18v-6h18v6"/><path d="M3 12V6"/><path d="M21 12v-1a2 2 0 0 0-2-2h-7v3"/><circle cx="7" cy="9.5" r="1.8"/><path d="M3 18v2M21 18v2"/>',
        'waves'     => '<path d="M2 7.5c1.9 0 1.9 2 3.8 2s1.9-2 3.8-2 1.9 2 3.8 2 1.9-2 3.8-2 1.9 2 3.8 2"/><path d="M2 12.5c1.9 0 1.9 2 3.8 2s1.9-2 3.8-2 1.9 2 3.8 2 1.9-2 3.8-2 1.9 2 3.8 2"/><path d="M2 17.5c1.9 0 1.9 2 3.8 2s1.9-2 3.8-2 1.9 2 3.8 2 1.9-2 3.8-2 1.9 2 3.8 2"/>',
        'dumbbell'  => '<path d="M6.5 5.5v13"/><path d="M3.5 8.5v7"/><path d="M17.5 5.5v13"/><path d="M20.5 8.5v7"/><path d="M6.5 12h11"/>',
        'utensils'  => '<path d="M5 2v7a3 3 0 0 0 6 0V2"/><path d="M8 9v13"/><path d="M18 2c-1.6 1.6-2.2 3.6-2.2 6 0 1.9.7 3.1 2.2 3.9V22"/>',
        'building'  => '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 7.5h1.5M13.5 7.5H15M9 12h1.5M13.5 12H15"/><path d="M10 21v-4.5h4V21"/>',
        'cog'       => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v2.8M12 18.7v2.8M4.9 4.9l2 2M17.1 17.1l2 2M2.5 12h2.8M18.7 12h2.8M4.9 19.1l2-2M17.1 6.9l2-2"/>',
        // Specific place icons
        'cup'       => '<path d="M4 8h13v5.5A4.5 4.5 0 0 1 12.5 18h-4A4.5 4.5 0 0 1 4 13.5z"/><path d="M17 9.5h1.8a2.4 2.4 0 0 1 0 4.8H17"/><path d="M7 3v2M11 3v2M15 3v2"/><path d="M3 21h16"/>',
        'glass'     => '<path d="M4 4h16l-8 8.5z"/><path d="M12 12.5V20"/><path d="M8 20h8"/>',
        'car'       => '<path d="M4.5 13.5l1.4-4.6A2 2 0 0 1 7.8 7.5h8.4a2 2 0 0 1 1.9 1.4l1.4 4.6"/><rect x="3" y="13.5" width="18" height="4.5" rx="1.4"/><circle cx="7.3" cy="15.8" r="1"/><circle cx="16.7" cy="15.8" r="1"/><path d="M6 18v1.8M18 18v1.8"/>',
        'door'      => '<path d="M5 21V4a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v17"/><path d="M2.5 21h19"/><circle cx="13" cy="12.5" r="1"/>',
        'bell'      => '<path d="M4 17.5h16"/><path d="M6.5 17.5a5.5 5.5 0 0 1 11 0"/><path d="M12 6.5V5"/><circle cx="12" cy="3.8" r="1.2"/><path d="M3 21h18"/>',
        'briefcase' => '<rect x="2.5" y="7" width="19" height="13" rx="2"/><path d="M8.5 7V5.2A2 2 0 0 1 10.5 3.2h3a2 2 0 0 1 2 2V7"/><path d="M2.5 12.5h19"/>',
        'shirt'     => '<path d="M8.5 3L12 5l3.5-2 5 3.5-2.8 3.2V21H6.3V9.7L3.5 6.5z"/>',
        'bolt'      => '<path d="M13.5 2.5L4.5 14h7l-1 7.5L20 10h-7z"/>',
        'shield'    => '<path d="M12 2.5l8 3v6c0 5-3.4 9.3-8 11-4.6-1.7-8-6-8-11v-6z"/>',
        'home'      => '<path d="M3 10.5L12 3l9 7.5"/><path d="M5.2 9v12h13.6V9"/><path d="M10 21v-5.5h4V21"/>',
    ];
}

/**
 * Inline SVG for one icon. Returns '' for an unknown name rather than throwing —
 * a missing icon should never take a page down.
 */
function map_icon(string $name, float $size = 15, string $class = 'map-ico'): string {
    $paths = map_icon_paths();
    if (!isset($paths[$name])) return '';
    return '<svg class="' . e($class) . '" width="' . $size . '" height="' . $size . '"'
         . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'
         . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . $paths[$name] . '</svg>';
}
