<?php
/**
 * Server-side pagination + search toolkit for admin list pages.
 *
 * State lives in the query string (GET) so links are shareable and coexist with
 * existing filter dropdowns. Enhanced (no full reload) by admin-table.js, and
 * degrades to plain GET reloads with no JS.
 *
 * Typical page flow:
 *   $pg = paginate_params();                       // page / per / q / offset / ajax
 *   $sw = search_where(['a.name','a.email'], $pg['q'], $params);
 *   $where = $sw ? "WHERE $sw" : '';
 *   $total = (int) db_query("SELECT COUNT(*) FROM t $where", $params)->fetchColumn();
 *   $meta  = paginate_meta($total, $pg['page'], $pg['per']);
 *   $rows  = db_query("SELECT ... $where ORDER BY ... LIMIT {$meta['per']} OFFSET {$meta['offset']}", $params)->fetchAll();
 *
 * Render with includes/admin-pagination.php (dt_toolbar / dt_pager).
 */
declare(strict_types=1);

if (!function_exists('paginate_params')) {
    /**
     * Read + validate page / per / q / ajax from $_GET.
     *
     * @param int $default_per One of the allowed page sizes (10 / 25 / 50).
     * @return array{page:int,per:int,q:string,offset:int,ajax:bool}
     */
    function paginate_params(int $default_per = 10): array
    {
        $allowed = [10, 25, 50];
        $per = (int)($_GET['per'] ?? $default_per);
        if (!in_array($per, $allowed, true)) $per = $default_per;

        $page = max(1, (int)($_GET['page'] ?? 1));
        $q    = trim((string)($_GET['q'] ?? ''));

        return [
            'page'   => $page,
            'per'    => $per,
            'q'      => $q,
            'offset' => ($page - 1) * $per,
            'ajax'   => (($_GET['ajax'] ?? '') === '1'),
        ];
    }
}

if (!function_exists('search_where')) {
    /**
     * Build a "(colA ILIKE :qs0 OR colB ILIKE :qs1 ...)" fragment for a search
     * term, binding each placeholder into $params. PDO emulation is OFF in this
     * project, so a named placeholder cannot be reused — each column gets its own.
     * Returns '' (and binds nothing) when $q is empty.
     *
     * @param string[] $cols   Column expressions. MUST be developer-controlled SQL,
     *                          never user input (they are inlined verbatim).
     * @param string   $q      Raw search term.
     * @param array    $params Bind array, appended to in place.
     * @param string   $prefix Placeholder base — pass a unique value if a single
     *                          query calls this more than once.
     * @return string The parenthesised fragment (no leading AND/WHERE), or ''.
     */
    function search_where(array $cols, string $q, array &$params, string $prefix = 'qs'): string
    {
        $q = trim($q);
        if ($q === '' || !$cols) return '';

        $like  = '%' . $q . '%';
        $parts = [];
        foreach (array_values($cols) as $i => $col) {
            $ph = ':' . $prefix . $i;
            $parts[]      = "$col ILIKE $ph";
            $params[$ph]  = $like;
        }
        return '(' . implode(' OR ', $parts) . ')';
    }
}

if (!function_exists('paginate_meta')) {
    /**
     * Compute pager display metadata. Clamps $page into range so a stale
     * ?page=999 falls back to the last real page instead of an empty result.
     *
     * @return array{page:int,per:int,pages:int,from:int,to:int,total:int,offset:int}
     */
    function paginate_meta(int $total, int $page, int $per): array
    {
        $total = max(0, $total);
        $per   = max(1, $per);
        $pages = max(1, (int)ceil($total / $per));
        $page  = min(max(1, $page), $pages);
        $offset = ($page - 1) * $per;
        $from   = $total === 0 ? 0 : $offset + 1;
        $to     = min($offset + $per, $total);
        return compact('page', 'per', 'pages', 'from', 'to', 'total', 'offset');
    }
}
