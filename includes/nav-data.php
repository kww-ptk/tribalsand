<?php
/**
 * Admin-editable site navigation (mega menu) — read model + renderers.
 *
 * Data model (migration: db/migrations/add_nav_menu.sql):
 *   nav_items  → top-level buttons        (label, layout, auto_source, sort_order, is_published)
 *   nav_groups → columns/sections         (label, sort_order)
 *   nav_links  → rows                      (label, href, sublabel, image_key, tag, role, cta_note, target_blank)
 *
 * Everything here is pre-migration-safe: nav_supported() caches a cheap existence
 * check, and fetch_nav_tree() returns [] on any error. includes/header.php renders
 * the hardcoded fallback nav whenever the tree is empty, so a missing migration or
 * an empty table never produces a blank menu.
 *
 * Restaurants is represented as a locked placeholder item (auto_source='restaurants')
 * so it keeps its slot in the ordered bar; the renderer splices in the existing
 * published-menus markup passed by header.php. The builder never edits it.
 */
require_once __DIR__ . '/db.php';

/** True when the nav tables exist. Cached per request. */
function nav_supported(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_query("SELECT 1 FROM nav_items LIMIT 1");
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/**
 * Published nav tree, ordered. Shape:
 *   [ ['id','label','layout','auto_source','groups'=>[
 *        ['id','label','links'=>[ ['id','label','href','sublabel','image_key',
 *                                  'tag','role','cta_note','target_blank'], … ]], … ]], … ]
 * Returns [] when unsupported or on error.
 */
function fetch_nav_tree(bool $published_only = true): array {
    if (!nav_supported()) return [];
    try {
        $pubItem = $published_only ? 'WHERE is_published = TRUE' : '';
        $items = db_query("SELECT * FROM nav_items {$pubItem} ORDER BY sort_order, id")->fetchAll();
        if (!$items) return [];

        $groups = db_query("SELECT * FROM nav_groups ORDER BY sort_order, id")->fetchAll();
        $pubLink = $published_only ? 'WHERE is_published = TRUE' : '';
        $links = db_query("SELECT * FROM nav_links {$pubLink} ORDER BY sort_order, id")->fetchAll();

        $linksByGroup = [];
        foreach ($links as $l) { $linksByGroup[(int) $l['nav_group_id']][] = $l; }

        $groupsByItem = [];
        foreach ($groups as $g) {
            $g['links'] = $linksByGroup[(int) $g['id']] ?? [];
            $groupsByItem[(int) $g['nav_item_id']][] = $g;
        }

        foreach ($items as &$it) {
            $it['groups'] = $groupsByItem[(int) $it['id']] ?? [];
        }
        unset($it);
        return $items;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Resolve a nav thumbnail key to a URL.
 *   - empty            → ''
 *   - http(s)://…      → as-is
 *   - images/…         → asset_url()   (legacy seeded static paths)
 *   - anything else    → storage_url() (admin-uploaded keys, S3/relative)
 */
function nav_img_url(?string $key): string {
    $key = trim((string) $key);
    if ($key === '') return '';
    if (str_starts_with($key, 'http')) return $key;
    if (str_starts_with($key, 'images/')) return asset_url($key);
    return storage_url($key);
}

/** The "· Now Open" / "— Soon" chip for a link tag, or ''. */
function nav_tag_html(string $tag): string {
    return match ($tag) {
        'open' => ' <span class="ts-tag ts-tag-open">· Now Open</span>',
        'soon' => ' <span class="ts-tag ts-tag-soon">— Soon</span>',
        default => '',
    };
}

/** One desktop link row, dispatched by role. */
function nav_link_desktop(array $l): string {
    $role = $l['role'] ?? 'row';
    $href = $l['href'] ?: '#';
    $tgt  = !empty($l['target_blank']) ? ' target="_blank" rel="noopener"' : '';

    if ($role === 'cta_button') {
        return '<div class="ts-drop-cta">'
             . ($l['cta_note'] ? '<span class="ts-drop-cta__note">' . e($l['cta_note']) . '</span>' : '')
             . '<a href="' . e($href) . '"' . $tgt . ' class="ts-drop-cta__btn">' . e($l['label']) . '</a>'
             . '</div>';
    }
    if ($role === 'footer_link') {
        return '<a href="' . e($href) . '"' . $tgt . ' style="font-size:.62rem;letter-spacing:.12em;color:rgba(184,150,90,.6);padding:.55rem 1.2rem;display:block;transition:color .2s;" onmouseover="this.style.color=\'#D4B07A\'" onmouseout="this.style.color=\'rgba(184,150,90,.6)\'">' . e($l['label']) . '</a>';
    }

    $tag = nav_tag_html($l['tag'] ?? '');
    $img = nav_img_url($l['image_key'] ?? '');
    if ($img !== '') {
        // Thumbnail row (property-style).
        return '<a href="' . e($href) . '"' . $tgt . ' class="ts-prop-row">'
             . '<img src="' . e($img) . '" alt="' . e($l['label']) . '">'
             . '<div><div class="ts-prop-name">' . e($l['label']) . $tag . '</div>'
             . ($l['sublabel'] ? '<div class="ts-prop-loc">' . e($l['sublabel']) . '</div>' : '')
             . '</div></a>';
    }
    // Plain text link (simple dropdowns).
    return '<a href="' . e($href) . '"' . $tgt . '>' . e($l['label']) . $tag . '</a>';
}

/** Render one group's links; footer_link/cta_button rows are pinned in a footer block. */
function nav_group_body(array $group): string {
    $out = '';
    $footer = '';
    if (!empty($group['label'])) $out .= '<span class="ts-drop-lbl">' . e($group['label']) . '</span>';
    foreach ($group['links'] as $l) {
        $role = $l['role'] ?? 'row';
        if ($role === 'footer_link' || $role === 'cta_button') {
            $footer .= nav_link_desktop($l);
        } else {
            $out .= nav_link_desktop($l);
        }
    }
    if ($footer !== '') {
        $out .= '<div class="ts-drop-col-footer"><div class="ts-drop-div"></div>' . $footer . '</div>';
    }
    return $out;
}

/**
 * Full desktop top-nav (<div class="ts-item">…) for the tree.
 * $restaurantsHtml is the ready-made markup for the auto 'restaurants' item.
 */
function nav_desktop_html(array $tree, string $restaurantsHtml = ''): string {
    $chev = '<svg viewBox="0 0 10 6" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 1l4 4 4-4"/></svg>';
    $html = '';
    foreach ($tree as $item) {
        if (($item['auto_source'] ?? '') === 'restaurants') { $html .= $restaurantsHtml; continue; }

        $layout = $item['layout'] ?? 'simple';
        $dropCls = $layout === 'wide3' ? 'ts-drop wide'
                 : ($layout === 'wide2' ? 'ts-drop wide-2' : 'ts-drop');

        $inner = '';
        if ($layout === 'simple') {
            // Groups stack vertically, separated by a divider.
            $parts = [];
            foreach ($item['groups'] as $g) {
                $body = '';
                if (!empty($g['label'])) $body .= '<span class="ts-drop-lbl">' . e($g['label']) . '</span>';
                foreach ($g['links'] as $l) $body .= nav_link_desktop($l);
                if ($body !== '') $parts[] = $body;
            }
            $inner = implode('<div class="ts-drop-div"></div>', $parts);
        } else {
            // Groups are side-by-side columns.
            foreach ($item['groups'] as $g) {
                $inner .= '<div class="ts-drop-col">' . nav_group_body($g) . '</div>';
            }
        }

        $html .= '<div class="ts-item">'
               . '<button class="ts-link">' . e($item['label']) . ' ' . $chev . '</button>'
               . '<div class="' . $dropCls . '">' . $inner . '</div>'
               . '</div>';
    }
    return $html;
}

/** Mobile drawer sections for the tree. $restaurantsHtml is the auto item's drawer markup. */
function nav_drawer_html(array $tree, string $restaurantsHtml = ''): string {
    $html = '';
    foreach ($tree as $item) {
        if (($item['auto_source'] ?? '') === 'restaurants') { $html .= $restaurantsHtml; continue; }

        $rows = '';
        foreach ($item['groups'] as $g) {
            foreach ($g['links'] as $l) {
                $role = $l['role'] ?? 'row';
                if ($role === 'cta_button') continue;   // CTAs live in the drawer footer, not the link list
                $href = $l['href'] ?: '#';
                $tgt  = !empty($l['target_blank']) ? ' target="_blank" rel="noopener"' : '';
                $img  = nav_img_url($l['image_key'] ?? '');
                $tag  = nav_tag_html($l['tag'] ?? '');
                if ($img !== '') {
                    $rows .= '<a href="' . e($href) . '"' . $tgt . ' class="ts-mob-prop">'
                           . '<img src="' . e($img) . '" alt="' . e($l['label']) . '">'
                           . '<div><div class="ts-mob-prop-name">' . e($l['label']) . '</div>'
                           . ($l['sublabel'] ? '<div class="ts-mob-prop-loc">' . e($l['sublabel']) . '</div>' : '')
                           . '</div></a>';
                } else {
                    $rows .= '<a href="' . e($href) . '"' . $tgt . ' class="ts-mob-link">' . e($l['label']) . $tag . ' <span class="ts-mob-arr">→</span></a>';
                }
            }
        }
        if ($rows !== '') {
            $html .= '<div><span class="ts-mob-lbl">' . e($item['label']) . '</span>' . $rows . '</div>';
        }
    }
    return $html;
}
