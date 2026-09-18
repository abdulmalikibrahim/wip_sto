<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Excel-style header filters for server-side DataTables (see
 * assets/js/excel_filter.js). The browser sends every column's filter as
 * one JSON string in `col_filters`:
 *
 *   {"model": {"mode": "in",     "values": ["D26A", "D52B"]},
 *    "uom":   {"mode": "not_in", "values": ["", "SET"]},
 *    "part_number": {"mode": "like", "text": "BZ0"}}
 *
 *   in     — keep rows whose value is one of `values`  (ticked items)
 *   not_in — drop rows whose value is one of `values`  (unticked items)
 *   like   — keep rows whose value contains `text`     (Search box + OK
 *            when the list was too long to show every match)
 *
 * The empty string '' stands for Excel's "(Blanks)": NULL or ''.
 *
 * Column names are only ever taken from the caller's whitelist, and
 * values always go through the query builder's escaping.
 */
class Column_filter
{
    /** Most values one filter may carry — a runaway request is cut here. */
    const MAX_VALUES = 5000;

    /** Most distinct values the dropdown list is sent at once. */
    const LIST_LIMIT = 1000;

    /**
     * Decode and validate the `col_filters` request value.
     *
     * @param mixed    $raw     JSON string from the request
     * @param string[] $allowed column names that may be filtered
     * @return array   column => {mode, values[], text}
     */
    public function parse($raw, array $allowed)
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return array();
        }

        $filters = array();
        foreach ($decoded as $column => $f) {
            if (!in_array($column, $allowed, true) || !is_array($f)) {
                continue;
            }
            $mode = $f['mode'] ?? '';

            if ($mode === 'like') {
                $text = trim((string) ($f['text'] ?? ''));
                if ($text !== '') {
                    $filters[$column] = array('mode' => 'like', 'values' => array(), 'text' => mb_substr($text, 0, 255));
                }
                continue;
            }

            if ($mode !== 'in' && $mode !== 'not_in') {
                continue;
            }
            $values = array();
            foreach (array_slice((array) ($f['values'] ?? array()), 0, self::MAX_VALUES) as $v) {
                if (is_scalar($v) || $v === null) {
                    $values[] = mb_substr((string) $v, 0, 255);
                }
            }
            $values = array_values(array_unique($values));

            // "in" with nothing ticked would hide every row; the UI never
            // sends it (OK is disabled), so treat it as no filter at all.
            if ($mode === 'in' && empty($values)) {
                continue;
            }
            if ($mode === 'not_in' && empty($values)) {
                continue; // nothing unticked = no filter
            }
            $filters[$column] = array('mode' => $mode, 'values' => $values, 'text' => '');
        }

        return $filters;
    }

    /**
     * Add every filter (except $except_column's own) to the query being
     * built on $db. $except_column is for the dropdown list: like Excel, a
     * column's list shows the values left by the OTHER columns' filters.
     */
    public function apply($db, array $filters, $except_column = null)
    {
        foreach ($filters as $column => $f) {
            if ($column === $except_column) {
                continue;
            }
            $col = $db->protect_identifiers($column);

            if ($f['mode'] === 'like') {
                $db->like($column, $f['text']);
                continue;
            }

            $has_blank = in_array('', $f['values'], true);
            $values = array_values(array_filter($f['values'], function ($v) {
                return $v !== '';
            }));

            if ($f['mode'] === 'in') {
                $db->group_start();
                if ($values) {
                    $db->or_where_in($column, $values);
                }
                if ($has_blank) {
                    $db->or_where("({$col} IS NULL OR {$col} = '')", null, false);
                }
                $db->group_end();
                continue;
            }

            // not_in. Plain `col NOT IN (...)` would also drop NULL rows (a
            // NULL comparison is never true), so keep blanks explicitly
            // unless (Blanks) itself was unticked.
            if ($has_blank) {
                $db->where("({$col} IS NOT NULL AND {$col} <> '')", null, false);
                if ($values) {
                    $db->where_not_in($column, $values);
                }
            } elseif ($values) {
                $db->group_start();
                $db->or_where("({$col} IS NULL OR {$col} = '')", null, false);
                $db->or_where_not_in($column, $values);
                $db->group_end();
            }
        }
    }

    /**
     * Distinct values of $column for the dropdown list, from the query
     * already started on $db (FROM + the page's other filters). NULL and
     * '' come back together as '' — the "(Blanks)" item.
     *
     * @param string $search the dropdown's own Search box
     * @return array{values:string[], truncated:bool}
     */
    public function distinct($db, $column, $search = '')
    {
        $col = $db->protect_identifiers($column);
        $search = trim((string) $search);
        if ($search !== '') {
            $db->like($column, $search);
        }

        // Order by the column's own type (MIN keeps ONLY_FULL_GROUP_BY
        // happy): a DECIMAL like qty then sorts 2 before 10, not "10" < "2"
        // as the COALESCE'd string would.
        $rows = $db->select("COALESCE({$col}, '') AS v", false)
            ->group_by('v')
            ->order_by("MIN({$col})", 'asc', false)
            ->limit(self::LIST_LIMIT + 1)
            ->get()->result_array();

        $truncated = count($rows) > self::LIST_LIMIT;
        $values = array();
        foreach (array_slice($rows, 0, self::LIST_LIMIT) as $row) {
            $values[] = (string) $row['v'];
        }

        return array('values' => $values, 'truncated' => $truncated);
    }
}
