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
            'check'          => '<path d="M20 6 9 17l-5-5"/>',
            'check-check'    => '<path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/>',
            'x'              => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'edit'           => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
            'message'        => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'play'           => '<polygon points="6 3 20 12 6 21 6 3"/>',
            'rotate'         => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
            'ban'            => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
            'trash'          => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
            'logout'         => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
            // ── Added for admin UI modernization (search, pagination, forms) ──
            'search'         => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'chevron-left'   => '<path d="m15 18-6-6 6-6"/>',
            'chevron-right'  => '<path d="m9 18 6-6-6-6"/>',
            'chevron-down'   => '<path d="m6 9 6 6 6-6"/>',
            'chevrons-left'  => '<path d="m11 17-5-5 5-5"/><path d="m18 17-5-5 5-5"/>',
            'chevrons-right' => '<path d="m13 17 5-5-5-5"/><path d="m6 17 5-5-5-5"/>',
            'plus'           => '<path d="M5 12h14"/><path d="M12 5v14"/>',
            'minus'          => '<path d="M5 12h14"/>',
            'filter'         => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
            'calendar'       => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/>',
            'arrow-left'     => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
            'arrow-right'    => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
            'download'       => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
            'grip'           => '<circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/>',
            // ── Added for admin UI cleanup (empty states, photo cells, view actions) ──
            'inbox'          => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'image'          => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
            'eye'            => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
            'external-link'  => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
            'home'           => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            'settings'       => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
            'phone'          => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        ];
        $p = $paths[$name] ?? '';
        return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" '
             . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
             . $p . '</svg>';
    }
}

if (!function_exists('admin_action_btn')) {
    /**
     * Emit a single icon-only action button for a table "Actions" column.
     *
     * Cuts the boilerplate repeated in every list page. Icon-only buttons MUST
     * carry a title + aria-label (both default to $label), so this enforces it.
     *
     *   echo admin_action_btn('edit', 'Edit', ['href' => '/admin/room-edit.php?id=3']);
     *   echo admin_action_btn('trash', 'Delete', [
     *       'variant' => 'danger', 'type' => 'submit',
     *       'name' => 'do', 'value' => 'delete', 'confirm' => 'Delete this room?',
     *   ]);
     *
     * @param string $icon   Icon name for admin_icon().
     * @param string $label  Accessible label (title + aria-label). Human, e.g. "Edit room".
     * @param array  $opts   href, variant (primary|outline|danger), type (button|submit),
     *                        name, value, confirm, class (extra classes), attrs (raw extra HTML).
     */
    function admin_action_btn(string $icon, string $label, array $opts = []): string
    {
        $variant = $opts['variant'] ?? '';
        $cls = 'btn-icon' . ($variant ? ' btn-icon--' . $variant : '');
        if (!empty($opts['class'])) $cls .= ' ' . $opts['class'];

        $common = ' class="' . e($cls) . '"'
                . ' title="' . e($label) . '"'
                . ' aria-label="' . e($label) . '"';
        if (!empty($opts['confirm'])) $common .= ' data-confirm="' . e($opts['confirm']) . '"';
        if (!empty($opts['attrs']))   $common .= ' ' . $opts['attrs'];

        $glyph = admin_icon($icon);

        if (!empty($opts['href'])) {
            return '<a href="' . e($opts['href']) . '"' . $common . '>' . $glyph . '</a>';
        }

        $type = $opts['type'] ?? 'submit';
        $nv = '';
        if (!empty($opts['name']))  $nv .= ' name="' . e($opts['name']) . '"';
        if (isset($opts['value']))  $nv .= ' value="' . e((string)$opts['value']) . '"';
        return '<button type="' . e($type) . '"' . $nv . $common . '>' . $glyph . '</button>';
    }
}
