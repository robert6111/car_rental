<?php
/*
 * Shared pagination for list pages.
 *
 *   $pg = cr_page();                          // reads ?page= and ?per_page=
 *   $total = ... SELECT COUNT(*) with the same WHERE ...;
 *   $pg = cr_page_total($pg, $total);         // clamps the page number
 *   ... "LIMIT {$pg['limit']} OFFSET {$pg['offset']}" ...
 *   echo cr_pagination($pg);                  // links that keep the other filters
 */

const CR_PAGE_SIZES = [25, 50, 100, 200];

function cr_page(?int $defaultPerPage = null): array {
    $default = $defaultPerPage ?? (int)cr_setting('rows_per_page');
    if (!in_array($default, CR_PAGE_SIZES, true)) $default = 25;
    $perPage = (int)($_GET['per_page'] ?? $default);
    if (!in_array($perPage, CR_PAGE_SIZES, true)) $perPage = $default;
    $page = max(1, (int)($_GET['page'] ?? 1));
    return ['page' => $page, 'per_page' => $perPage, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => null, 'pages' => 1];
}

function cr_page_total(array $pg, int $total): array {
    $pg['total'] = max(0, $total);
    $pg['pages'] = max(1, (int)ceil($pg['total'] / $pg['per_page']));
    if ($pg['page'] > $pg['pages']) $pg['page'] = $pg['pages'];
    $pg['offset'] = ($pg['page'] - 1) * $pg['per_page'];
    return $pg;
}

/** Runs a COUNT(*) query (prepared when $types is given) and returns the clamped page info. */
function cr_page_count(mysqli $conn, array $pg, string $countSql, string $types = '', array $params = []): array {
    $total = 0;
    if ($types !== '') {
        $stmt = $conn->prepare($countSql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) $total = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
            $stmt->close();
        }
    } else {
        $res = $conn->query($countSql);
        if ($res) $total = (int)($res->fetch_row()[0] ?? 0);
    }
    return cr_page_total($pg, $total);
}

/** SQL fragment: " LIMIT n OFFSET m" (integers only). */
function cr_page_sql(array $pg): string {
    return ' LIMIT ' . (int)$pg['limit'] . ' OFFSET ' . (int)$pg['offset'];
}

function cr_page_url(int $page, ?int $perPage = null): string {
    $query = $_GET;
    unset($query['_token']);
    $query['page'] = $page;
    if ($perPage !== null) $query['per_page'] = $perPage;
    if ((int)$query['page'] === 1) unset($query['page']);
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
    $qs = http_build_query($query);
    return basename($path) . ($qs !== '' ? '?' . $qs : '');
}

/** Pager HTML: "Showing 26-50 of 312", page links and a rows-per-page choice. */
function cr_pagination(array $pg): string {
    $total = (int)($pg['total'] ?? 0);
    if ($total === 0) return '';
    $page = (int)$pg['page'];
    $pages = (int)$pg['pages'];
    $from = $pg['offset'] + 1;
    $to = min($total, $pg['offset'] + $pg['per_page']);

    $html = '<nav class="cr-pager" aria-label="Pages"><span class="cr-pager-info">Showing ' . cr_number($from, 0) . '&ndash;' . cr_number($to, 0) . ' of ' . cr_number($total, 0) . '</span>';
    if ($pages > 1) {
        $html .= '<span class="cr-pager-links">';
        $html .= $page > 1 ? '<a href="' . h(cr_page_url($page - 1)) . '" rel="prev">&lsaquo; Prev</a>' : '<span class="disabled">&lsaquo; Prev</span>';
        $shown = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], fn($p) => $p >= 1 && $p <= $pages));
        sort($shown);
        $last = 0;
        foreach ($shown as $p) {
            if ($last && $p > $last + 1) $html .= '<span class="gap">&hellip;</span>';
            $html .= $p === $page ? '<span class="current" aria-current="page">' . $p . '</span>' : '<a href="' . h(cr_page_url($p)) . '">' . $p . '</a>';
            $last = $p;
        }
        $html .= $page < $pages ? '<a href="' . h(cr_page_url($page + 1)) . '" rel="next">Next &rsaquo;</a>' : '<span class="disabled">Next &rsaquo;</span>';
        $html .= '</span>';
    }
    $html .= '<span class="cr-pager-size">Rows: ';
    foreach (CR_PAGE_SIZES as $size) {
        $html .= $size === (int)$pg['per_page'] ? '<strong>' . $size . '</strong> ' : '<a href="' . h(cr_page_url(1, $size)) . '">' . $size . '</a> ';
    }
    return $html . '</span></nav>';
}
