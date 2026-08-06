<?php
/**
 * Tiny inline-SVG icon set (Lucide paths) for the admin UI.
 *
 * This project has no build step / npm, so `lucide-react` can't run — these are
 * the same Lucide glyphs inlined as SVG. Use in icon-only action buttons:
 *
 *   <button class="btn-icon btn-icon--danger" title="Delete" aria-label="Delete">
 *     <?= admin_icon('trash') ?>
 *   </button>
 *
 * Icon-only buttons MUST carry a title + aria-label for accessibility.
 */
declare(strict_types=1);

if (!function_exists('admin_icon')) {
    function admin_icon(string $name, int $size = 16): string
    {
        static $paths = [
            'check'       => '<path d="M20 6 9 17l-5-5"/>',
            'check-check' => '<path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/>',
            'x'           => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'edit'        => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
            'message'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'play'        => '<polygon points="6 3 20 12 6 21 6 3"/>',
            'rotate'      => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
            'ban'         => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
            'trash'       => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
            'logout'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        ];
        $p = $paths[$name] ?? '';
        return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" '
             . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
             . $p . '</svg>';
    }
}
