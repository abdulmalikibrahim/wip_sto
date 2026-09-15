<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * "WIP Calc" — sums Master BOM part usage per shop (Welding/Toso/Assy)
 * against the cached Master WIP data (Wip_data_model), stopping at each
 * *part's own* cutoff VIN.
 *
 * Different parts are installed at different points along the Toso/Assy
 * lines (e.g. a steering wheel post vs. a door trim post), so a single
 * shop-wide cutoff isn't enough — each (shop_code, part_number) pair gets
 * its own cutoff VIN, uploaded via parse_excel()/upsert_cutoffs() and
 * stored in `wip_calc_cutoff`. Only the VIN string is kept — its row id
 * in `wip_data` is re-resolved live on every calc() call, since "Get Data
 * WIP" replaces (and re-ids) all rows for a shop.
 */
class Wip_calc_model extends CI_Model
{
    protected $table = 'wip_calc_cutoff';
    protected $log_table = 'wip_calc_cutoff_log';

    /** Required header columns of the cutoff upload/template Excel file, in order. */
    public $required_headers = array('Part Number', 'VIN', 'Shop');

    /** @var array<string,array> per-(shop|boundary) unit-count cache within one calc() call */
    protected $counts_cache = array();

    public function __construct()
    {
        parent::__construct();
        $this->config->load('wip_api');
    }

    /**
     * Which table WIP Calc reads part usage from — the Master BOM, or the
     * Part List. Both carry the same model/suffix/shop_code/qty shape, so
     * they're interchangeable as the calculation's basis; see
     * docs/guidance proses hitung wip.pdf ("bisa di buat dari BOM bisa juga
     * via part list").
     *
     * Whitelisted rather than passed through, since the result reaches
     * from() directly.
     */
    protected function basis_table($basis)
    {
        return $basis === 'part_list' ? 'part_list' : 'bom';
    }

    /**
     * SELECT list for the chosen basis. part_list has no `material` column,
     * so it's selected as a literal empty string there — every row handed
     * to the rest of the calculation then has the same shape either way,
     * and no caller has to care which basis it came from.
     *
     * @param array $columns plain column names, as they exist on `bom`
     */
    protected function basis_select($basis, array $columns)
    {
        if ($this->basis_table($basis) === 'part_list') {
            $columns = array_map(function ($column) {
                return $column === 'material' ? "'' AS material" : $column;
            }, $columns);
        }

        return implode(', ', $columns);
    }

    /** Human label for the basis, for report titles and the UI. */
    public function basis_label($basis)
    {
        return $this->basis_table($basis) === 'part_list' ? 'Part List' : 'Master BOM';
    }

    /** @var array<string,array> source => juklak_replaced() result, memoized per request */
    protected $juklak_cache = array();

    /**
     * Parts the Juklak says are represented by a different (main) part on
     * this KAP line: each counts as 0 in every WIP Calc / Summary figure,
     * since the main part already covers those units — e.g. 42600-BY540 and
     * 42600-BY530 are both listed for D52B/7J, and only the main one is
     * counted. Keyed by upper-cased part_number, valued by the main part.
     * Empty when the juklak table hasn't been created yet.
     *
     * @return array<string,string>
     */
    public function juklak_replaced($source)
    {
        if (isset($this->juklak_cache[$source])) {
            return $this->juklak_cache[$source];
        }

        $map = array();
        if ($this->db->table_exists('juklak')) {
            $rows = $this->db->select('part_number, main_part_number')
                ->where('plant', $source)
                ->get('juklak')->result_array();
            foreach ($rows as $row) {
                $part = strtoupper(trim($row['part_number']));
                $main = trim($row['main_part_number']);
                if ($part !== '' && $main !== '' && $part !== strtoupper($main)) {
                    $map[$part] = $main;
                }
            }
        }

        return $this->juklak_cache[$source] = $map;
    }

    /**
     * Sum BOM part usage (grouped by part_number) per shop, each part's
     * contribution counted from its own cutoff VIN (or every cached unit,
     * for parts with no cutoff set yet) up to the newest unit.
     *
     * @return array{ok:bool, message:string, cutoff_summary:array, data:array}
     */
    public function calc($source, $basis = 'bom')
    {
        $shop_codes = $this->config->item('wip_calc_shop_codes')[$source] ?? array();
        if (empty($shop_codes)) {
            return array('ok' => false, 'message' => 'Unknown WIP Calc source.', 'cutoff_summary' => array(), 'data' => array());
        }

        $this->counts_cache = array();

        // Cutoff VINs for this line's shop codes, indexed by "SHOPCODE|PART_NUMBER".
        $cutoffs = $this->db
            ->where_in('shop_code', array_values($shop_codes))
            ->get($this->table)->result_array();
        $cutoff_index = array();
        foreach ($cutoffs as $row) {
            $key = strtoupper(trim($row['shop_code'])) . '|' . strtoupper(trim($row['part_number']));
            $cutoff_index[$key] = $row;
        }

        // Basis rows (BOM or Part List) whose Shop Code lists (comma-separated,
        // e.g. "WELD3,ASSY3,TOSO3") include at least one of this line's shop codes.
        // FIND_IN_SET matches a single row's shop_code against a comma list — not
        // string equality — so a part shared across shops is only stored once but
        // counted for each shop it names. Matched case-insensitively, since upload
        // data isn't normalized to upper.
        $this->db->select($this->basis_select($basis, array(
            'material', 'part_number', 'material_description', 'uom', 'model', 'suffix', 'shop_code', 'qty',
        )), false)->from($this->basis_table($basis));
        $this->db->group_start();
        foreach ($shop_codes as $shop_code) {
            foreach (array_unique(array($shop_code, strtoupper($shop_code), strtolower($shop_code))) as $variant) {
                $this->db->or_where('FIND_IN_SET(' . $this->db->escape($variant) . ', shop_code) >', 0);
            }
        }
        $this->db->group_end();
        $bom_rows = $this->db->order_by('part_number', 'asc')->order_by('id', 'asc')->get()->result_array();

        $acc = array();
        $parts_with_cutoff = array_fill_keys(array_keys($shop_codes), 0);
        $parts_total = array_fill_keys(array_keys($shop_codes), 0);
        $stale_count = array_fill_keys(array_keys($shop_codes), 0);
        $seen_part_per_shop = array(); // "shop|part_number" already counted toward parts_total/with_cutoff

        foreach ($bom_rows as $row) {
            $part_number = $row['part_number'] !== '' ? $row['part_number'] : $row['material'];
            if ($part_number === null || $part_number === '') {
                continue;
            }

            if (!isset($acc[$part_number])) {
                $acc[$part_number] = array(
                    'part_number'          => $part_number,
                    'material'             => $row['material'],
                    'material_description' => $row['material_description'],
                    'uom'                  => $row['uom'],
                    'shop_code_set'        => array(), // collapsed into 'shop_code' below, once all rows are seen
                    'model_set'            => array(), // Models the part is used in -> 'models' / 'model_count' below
                    'juklak_main'          => null,
                );
                foreach (array_keys($shop_codes) as $s) {
                    $acc[$part_number][$s] = 0.0;
                    $acc[$part_number][$s . '_gross'] = 0.0;
                    $acc[$part_number][$s . '_vin'] = null;
                }
            }

            // A part_number can be made of several BOM rows (different
            // model/suffix/shop_code combos); collect every distinct raw
            // Shop Code value seen for it, verbatim from the BOM data.
            $acc[$part_number]['shop_code_set'][$row['shop_code']] = true;

            $model = strtoupper(trim((string) $row['model']));
            if ($model !== '') {
                $acc[$part_number]['model_set'][$model] = true;
            }

            $part_key = strtoupper(trim($part_number));

            // Juklak: represented by its main part on this line — listed, but counted as 0.
            $juklak_main = $this->juklak_replaced($source)[$part_key] ?? null;
            if ($juklak_main !== null) {
                $acc[$part_number]['juklak_main'] = $juklak_main;
                continue;
            }

            // A row's Shop Code cell can name more than one shop; credit every
            // configured shop it lists instead of assuming a single shop per row.
            $row_shops = array_map('strtoupper', array_filter(array_map('trim', explode(',', (string) $row['shop_code']))));

            foreach ($shop_codes as $shop => $shop_code) {
                $shop_code_norm = strtoupper($shop_code);
                if (!in_array($shop_code_norm, $row_shops, true)) {
                    continue;
                }

                $seen_key = $shop . '|' . $part_key;
                if (!isset($seen_part_per_shop[$seen_key])) {
                    $seen_part_per_shop[$seen_key] = true;
                    $parts_total[$shop]++;
                }

                $cutoff = $cutoff_index[$shop_code_norm . '|' . $part_key] ?? null;
                $boundary = null;
                if ($cutoff !== null) {
                    $found = $this->db->select('id')
                        ->where(array('source' => $source, 'shop' => $shop, 'vin' => $cutoff['vin']))
                        ->get('wip_data')->row_array();

                    if ($found) {
                        $boundary = (int) $found['id'];
                        $acc[$part_number][$shop . '_vin'] = $cutoff['vin'];
                        if (!isset($seen_part_per_shop[$seen_key . '#counted'])) {
                            $seen_part_per_shop[$seen_key . '#counted'] = true;
                            $parts_with_cutoff[$shop]++;
                        }
                    } else {
                        $stale_count[$shop]++;
                    }
                }

                $key = $this->match_key($row['model'], $row['suffix']);
                $qty = $this->qty_once($shop . '|' . $part_key . '|' . $key, (float) $row['qty']);
                if ($qty == 0.0) {
                    continue; // this suffix is already counted for this part in this shop
                }

                // Net: the cutoff VIN and every unit after it. No cutoff set
                // yet -> 0, nothing is counted until one is; a stale cutoff
                // (VIN no longer cached, $boundary null) still counts every unit.
                $unit_count = 0;
                if ($cutoff !== null) {
                    $counts = $this->get_counts($source, $shop, $boundary);
                    $unit_count = $counts[$key] ?? 0;
                }
                if ($unit_count > 0) {
                    $acc[$part_number][$shop] += $unit_count * $qty;
                }

                // Gross: every cached unit, ignoring the cutoff — always the
                // "$boundary = null" bucket, memoized so this is a cache hit
                // whenever no cutoff is set (same bucket as Net above).
                $gross_counts = $this->get_counts($source, $shop, null);
                $gross_unit_count = $gross_counts[$key] ?? 0;
                if ($gross_unit_count > 0) {
                    $acc[$part_number][$shop . '_gross'] += $gross_unit_count * $qty;
                }
            }
        }

        $data = array_values($acc);
        foreach ($data as &$row) {
            $row['shop_code'] = implode(', ', array_keys($row['shop_code_set']));
            unset($row['shop_code_set']);

            $models = array_map('strval', array_keys($row['model_set']));
            natcasesort($models);
            $row['models'] = implode(', ', $models);
            $row['model_count'] = count($models);
            unset($row['model_set']);

            $total = 0.0;
            $total_gross = 0.0;
            foreach (array_keys($shop_codes) as $s) {
                $total += (float) $row[$s];
                $total_gross += (float) $row[$s . '_gross'];
            }
            $total_cutoff = 0.0;
            foreach (array_keys($shop_codes) as $s) {
                $net = (float) $row[$s];
                $gross = (float) $row[$s . '_gross'];
                // Gross = every cached unit. With a cutoff VIN, Net = units
                // from that VIN on and Cutoff = the older units it excluded
                // (Gross = Cutoff + Net). With no cutoff VIN yet, Net and
                // Cutoff are both 0 — only Gross shows what's there. (A stale
                // cutoff counts every unit, so its Cutoff is 0 either way.)
                $cutoff = $row[$s . '_vin'] !== null ? $gross - $net : 0.0;
                $total_cutoff += $cutoff;
                $row[$s . '_net']    = $this->trim_qty($net);
                $row[$s . '_gross']  = $this->trim_qty($gross);
                $row[$s . '_cutoff'] = $this->trim_qty($cutoff);
                $row[$s] = $this->trim_qty($net);
            }
            $row['total']        = $this->trim_qty($total);
            $row['total_net']    = $row['total'];
            $row['total_gross']  = $this->trim_qty($total_gross);
            $row['total_cutoff'] = $this->trim_qty($total_cutoff);
        }
        unset($row);

        $cutoff_summary = array();
        foreach (array_keys($shop_codes) as $s) {
            $cutoff_summary[$s] = array(
                'with_cutoff' => $parts_with_cutoff[$s],
                'total_parts' => $parts_total[$s],
                'stale'       => $stale_count[$s],
            );
        }

        return array('ok' => true, 'message' => 'ok', 'cutoff_summary' => $cutoff_summary, 'data' => $data);
    }

    /**
     * Union two BOM Shop Code strings (each possibly a comma list already)
     * into one deduplicated, comma-separated list — used when summary()
     * folds several rows for the same part into one.
     */
    protected function merge_shop_codes($a, $b)
    {
        $codes = array_filter(array_map('trim', array_merge(
            explode(',', (string) $a),
            explode(',', (string) $b)
        )));

        return implode(', ', array_unique($codes));
    }

    /**
     * Row-level breakdown behind calc(): one row per (BOM line, shop it
     * names) — the "show your work" view, listing exactly which cutoff VIN
     * and how many matching WIP units produced that row's contribution.
     *
     * @return array{ok:bool, message:string, data:array}
     */
    public function calc_detail($source, $basis = 'bom')
    {
        $shop_codes = $this->config->item('wip_calc_shop_codes')[$source] ?? array();
        if (empty($shop_codes)) {
            return array('ok' => false, 'message' => 'Unknown WIP Calc source.', 'data' => array());
        }

        $this->counts_cache = array();
        $shop_labels = $this->config->item('wip_shop_labels');

        $cutoffs = $this->db
            ->where_in('shop_code', array_values($shop_codes))
            ->get($this->table)->result_array();
        $cutoff_index = array();
        foreach ($cutoffs as $row) {
            $key = strtoupper(trim($row['shop_code'])) . '|' . strtoupper(trim($row['part_number']));
            $cutoff_index[$key] = $row;
        }

        $this->db->select($this->basis_select($basis, array(
            'material', 'part_number', 'component', 'material_description', 'uom', 'model', 'suffix', 'shop_code', 'qty',
        )), false)->from($this->basis_table($basis));
        $this->db->group_start();
        foreach ($shop_codes as $shop_code) {
            foreach (array_unique(array($shop_code, strtoupper($shop_code), strtolower($shop_code))) as $variant) {
                $this->db->or_where('FIND_IN_SET(' . $this->db->escape($variant) . ', shop_code) >', 0);
            }
        }
        $this->db->group_end();
        $bom_rows = $this->db->order_by('part_number', 'asc')->order_by('id', 'asc')->get()->result_array();

        $data = array();
        $total_wip_cache = array(); // shop -> total cached WIP units, regardless of model/suffix

        foreach ($bom_rows as $row) {
            $part_number = $row['part_number'] !== '' ? $row['part_number'] : $row['material'];
            if ($part_number === null || $part_number === '') {
                continue;
            }

            $part_key = strtoupper(trim($part_number));
            if (isset($this->juklak_replaced($source)[$part_key])) {
                continue; // Juklak: represented by its main part — contributes 0, so nothing to show
            }
            $row_shops = array_map('strtoupper', array_filter(array_map('trim', explode(',', (string) $row['shop_code']))));

            foreach ($shop_codes as $shop => $shop_code) {
                $shop_code_norm = strtoupper($shop_code);
                if (!in_array($shop_code_norm, $row_shops, true)) {
                    continue;
                }

                $cutoff = $cutoff_index[$shop_code_norm . '|' . $part_key] ?? null;
                $boundary = null;
                $cutoff_vin = null;
                $cutoff_status = 'none'; // none|used|stale
                $cutoff_position = null; // this VIN's "No" among ALL of the shop's cached units (see below)
                $total_wip = null;

                if ($cutoff !== null) {
                    $found = $this->db->select('id')
                        ->where(array('source' => $source, 'shop' => $shop, 'vin' => $cutoff['vin']))
                        ->get('wip_data')->row_array();
                    $cutoff_vin = $cutoff['vin'];
                    $cutoff_status = $found ? 'used' : 'stale';

                    if (!isset($total_wip_cache[$shop])) {
                        $total_wip_cache[$shop] = $this->db
                            ->where(array('source' => $source, 'shop' => $shop))
                            ->count_all_results('wip_data');
                    }
                    $total_wip = $total_wip_cache[$shop];

                    if ($found) {
                        $boundary = (int) $found['id'];
                        // Position among every unit of this shop (not just ones
                        // matching this row's model/suffix) — "unit 131 of 200".
                        // Counted in list order (oldest id first) so it matches
                        // the "No" column of the WIP data list the cutoff was
                        // picked from; get_counts() then sums this row and every
                        // one after it, i.e. No 131..200 in that example.
                        $cutoff_position = (int) $this->db
                            ->where(array('source' => $source, 'shop' => $shop))
                            ->where('id <=', $boundary)
                            ->count_all_results('wip_data');
                    }
                }

                // No cutoff VIN set yet -> Net 0, same as calc(), so the row drops out below.
                $counts = $cutoff !== null ? $this->get_counts($source, $shop, $boundary) : array();
                $key = $this->match_key($row['model'], $row['suffix']);
                $unit_count = $counts[$key] ?? 0;
                // A suffix already counted for this part in this shop adds nothing, as in calc().
                $qty = $unit_count === 0 ? 0.0 : $this->qty_once($shop . '|' . $part_key . '|' . $key, (float) $row['qty']);
                if ($qty == 0.0) {
                    // Nothing to show your work for — this BOM line's Model+Suffix
                    // doesn't match any cached WIP unit, so it contributes 0 either
                    // way. With a BOM this large, keeping these would blow up the
                    // detail list (and the export) to hundreds of thousands of
                    // all-zero rows that aren't part of "how we got this number".
                    continue;
                }

                $qty_str = $this->trim_qty($qty);
                $subtotal_str = $this->trim_qty($unit_count * $qty);

                $data[] = array(
                    'part_number'          => $part_number,
                    'component'            => $row['component'],
                    'material'             => $row['material'],
                    'material_description' => $row['material_description'],
                    'model'                => $row['model'],
                    'suffix'               => $row['suffix'],
                    'uom'                  => $row['uom'],
                    'shop_label'           => $shop_labels[$shop] ?? strtoupper($shop),
                    'shop_code'            => $shop_code,
                    'cutoff_vin'           => $cutoff_vin,
                    'cutoff_status'        => $cutoff_status,
                    'cutoff_position'      => $cutoff_position,
                    'total_wip'            => $total_wip,
                    'unit_count'           => $unit_count,
                    'qty'                  => $qty_str,
                    // Spells out the suffix inline so the formula is self-explanatory
                    // without having to cross-reference the Suffix column: unit_count
                    // is how many cached WIP units match THIS row's Model+Suffix only
                    // (BOM has a separate row per suffix, so this never mixes suffixes).
                    'formula'              => $unit_count . ' unit (suffix ' . $row['suffix'] . ') × ' . $qty_str,
                    'subtotal'             => $subtotal_str,
                );
            }
        }

        return array('ok' => true, 'message' => 'ok', 'data' => $data);
    }

    /**
     * Full suffix-level breakdown for one part_number within one shop — every
     * suffix BOM defines for it (even ones with 0 matching WIP units, unlike
     * calc_detail()'s filtered list), plus where this part's shared cutoff
     * VIN sits among ALL of that shop's cached units. Powers the "Formula
     * Detail" modal on the Detail page.
     *
     * @return array{ok:bool, message?:string, part_number?:string, material_description?:string,
     *     shop_label?:string, shop_code?:string, cutoff?:array|null, total_wip?:int, suffixes?:array, grand_subtotal?:string}
     */
    public function part_breakdown($source, $shop_code_raw, $part_number_raw, $basis = 'bom')
    {
        $resolved = $this->resolve_shop_code($shop_code_raw);
        if ($resolved === null || $resolved['source'] !== $source) {
            return array('ok' => false, 'message' => 'Unknown Shop for this KAP line.');
        }
        $shop = $resolved['shop'];
        $shop_code = $resolved['shop_code'];

        $part_number = trim((string) $part_number_raw);
        if ($part_number === '') {
            return array('ok' => false, 'message' => 'Part Number is required.');
        }

        $total_wip = (int) $this->db
            ->where(array('source' => $source, 'shop' => $shop))
            ->count_all_results('wip_data');

        $cutoff = $this->db
            ->where(array('shop_code' => $shop_code, 'part_number' => $part_number))
            ->get($this->table)->row_array();

        $boundary = null;
        $cutoff_info = null;
        if ($cutoff) {
            $found = $this->db->select('id')
                ->where(array('source' => $source, 'shop' => $shop, 'vin' => $cutoff['vin']))
                ->get('wip_data')->row_array();

            if ($found) {
                $boundary = (int) $found['id'];
                // Same "No"-aligned position as calc_detail() — see the note there.
                $position = (int) $this->db
                    ->where(array('source' => $source, 'shop' => $shop))
                    ->where('id <=', $boundary)
                    ->count_all_results('wip_data');
                $cutoff_info = array('vin' => $cutoff['vin'], 'status' => 'used', 'position' => $position, 'total' => $total_wip);
            } else {
                $cutoff_info = array('vin' => $cutoff['vin'], 'status' => 'stale', 'position' => null, 'total' => $total_wip);
            }
        }

        // Every basis line for this part_number (or, for parts with a blank
        // part_number, its material code) in this shop — every suffix it's
        // defined for, regardless of whether any WIP unit currently matches.
        $this->db->select($this->basis_select($basis, array(
            'material', 'material_description', 'model', 'suffix', 'qty',
        )), false)->from($this->basis_table($basis));
        $this->db->group_start();
        $this->db->where('part_number', $part_number);
        if ($this->basis_table($basis) === 'bom') {
            // Only BOM carries a `material` column to fall back on.
            $this->db->or_group_start()->where('part_number', '')->where('material', $part_number)->group_end();
        }
        $this->db->group_end();
        $this->db->group_start();
        foreach (array_unique(array($shop_code, strtoupper($shop_code), strtolower($shop_code))) as $variant) {
            $this->db->or_where('FIND_IN_SET(' . $this->db->escape($variant) . ', shop_code) >', 0);
        }
        $this->db->group_end();
        $bom_rows = $this->db->order_by('model', 'asc')->order_by('suffix', 'asc')->get()->result_array();

        if (empty($bom_rows)) {
            return array('ok' => false, 'message' => 'No ' . $this->basis_label($basis) . ' lines found for this part in this shop.');
        }

        $material_description = $bom_rows[0]['material_description'];

        // One line per Model+Suffix: a part listed twice for the same suffix
        // in this shop is counted once, at its largest Qty — as in calc().
        $by_suffix = array();
        foreach ($bom_rows as $row) {
            $key = $this->match_key($row['model'], $row['suffix']);
            if (!isset($by_suffix[$key]) || (float) $row['qty'] > (float) $by_suffix[$key]['qty']) {
                $by_suffix[$key] = $row;
            }
        }

        $suffixes = array();
        $grand_subtotal = 0.0;
        $counts = $this->get_counts($source, $shop, $boundary);

        foreach ($by_suffix as $key => $row) {
            $unit_count = $counts[$key] ?? 0;
            $qty = (float) $row['qty'];
            $subtotal = $unit_count * $qty;
            $grand_subtotal += $subtotal;

            $suffixes[] = array(
                'model'      => $row['model'],
                'suffix'     => $row['suffix'],
                'material'   => $row['material'],
                'qty'        => $this->trim_qty($qty),
                'unit_count' => $unit_count,
                'subtotal'   => $this->trim_qty($subtotal),
            );
        }

        // Juklak (represented by its main part on this line) or no cutoff VIN
        // set yet -> counted as 0, same as the list; the unit counts stay
        // visible for reference. A stale cutoff still counts every unit.
        $juklak_main = $this->juklak_replaced($source)[strtoupper($part_number)] ?? null;
        if ($juklak_main !== null || !$cutoff) {
            foreach ($suffixes as &$suffix_row) {
                $suffix_row['subtotal'] = '0';
            }
            unset($suffix_row);
            $grand_subtotal = 0.0;
        }

        return array(
            'ok'                   => true,
            'juklak_main'          => $juklak_main,
            'part_number'          => $part_number,
            'material_description' => $material_description,
            'shop_label'           => $this->config->item('wip_shop_labels')[$shop] ?? strtoupper($shop),
            'shop_code'            => $shop_code,
            'cutoff'               => $cutoff_info,
            'total_wip'            => $total_wip,
            'suffixes'             => $suffixes,
            'grand_subtotal'       => $this->trim_qty($grand_subtotal),
        );
    }

    /**
     * A part listed more than once for the same Model+Suffix in one shop —
     * colour variants collapsed into one part number (87915-BZ510-A0/-F0…),
     * duplicate BOM lines, or a Part List row under both "ASSY3" and
     * "ASSY3,ASSY4" — is still one part per unit, so each suffix counts
     * once, at its largest Qty. Returns the Qty this row adds for $key: its
     * own Qty the first time, only the difference if a later duplicate is
     * larger, 0 otherwise.
     *
     * Remembered in counts_cache, so it resets with it: at the start of
     * calc() / calc_detail(), and per KAP line in summary().
     */
    protected function qty_once($key, $qty)
    {
        $already = $this->counts_cache['#qty_once'][$key] ?? 0.0;
        if ($qty <= $already) {
            return 0.0;
        }
        $this->counts_cache['#qty_once'][$key] = $qty;

        return $qty - $already;
    }

    /**
     * The actual cached wip_data rows behind one (part_number, shop, model,
     * suffix) suffix row's "Matching Units" count in part_breakdown() —
     * powers a "show me the VINs" drill-down so a count that looks off
     * against a hand-counted list (e.g. Excel) can be checked unit by unit.
     * Uses the part's own cutoff VIN as the boundary, same as part_breakdown().
     *
     * @return array{ok:bool, message?:string, part_number?:string, model?:string,
     *     suffix?:string, shop_label?:string, unit_count?:int, vins?:array}
     */
    public function part_breakdown_vins($source, $shop_code_raw, $part_number_raw, $model, $suffix)
    {
        $resolved = $this->resolve_shop_code($shop_code_raw);
        if ($resolved === null || $resolved['source'] !== $source) {
            return array('ok' => false, 'message' => 'Unknown Shop for this KAP line.');
        }
        $shop = $resolved['shop'];
        $shop_code = $resolved['shop_code'];

        $part_number = trim((string) $part_number_raw);
        if ($part_number === '') {
            return array('ok' => false, 'message' => 'Part Number is required.');
        }

        $cutoff = $this->db
            ->where(array('shop_code' => $shop_code, 'part_number' => $part_number))
            ->get($this->table)->row_array();

        $boundary = null;
        if ($cutoff) {
            $found = $this->db->select('id')
                ->where(array('source' => $source, 'shop' => $shop, 'vin' => $cutoff['vin']))
                ->get('wip_data')->row_array();
            if ($found) {
                $boundary = (int) $found['id'];
            }
        }

        $this->db->select('id, vin, sfx, katashiki, modelcode')
            ->where(array('source' => $source, 'shop' => $shop));
        if ($boundary !== null) {
            $this->db->where('id >=', $boundary);
        }
        $wip_rows = $this->db->order_by('id', 'asc')->get('wip_data')->result_array();

        // id -> SEQUENCE for the whole shop, so each listed row can show the
        // same number the Master WIP list does. Built from the full ordered
        // id list rather than arithmetic on the ids, so gaps (from deleted
        // rows) can't shift it.
        $seq_of = array();
        $all_ids = $this->db->select('id')
            ->where(array('source' => $source, 'shop' => $shop))
            ->order_by('id', 'asc')->get('wip_data')->result_array();
        foreach ($all_ids as $i => $r) {
            $seq_of[(int) $r['id']] = $i + 1;
        }

        $key = $this->match_key($model, $suffix);
        $matches = array();
        foreach ($wip_rows as $row) {
            if ($this->match_key($row['modelcode'], $row['sfx']) === $key) {
                $row['seq'] = $seq_of[(int) $row['id']] ?? null;
                $matches[] = $row;
            }
        }

        // Flag VINs cached more than once for this shop — the usual reason a
        // count here reads higher than a hand-counted list, since the same
        // physical unit then gets counted twice.
        $vin_counts = array_count_values(array_map(function ($r) {
            return $r['vin'];
        }, $matches));
        foreach ($matches as &$m) {
            $m['duplicate'] = ($vin_counts[$m['vin']] ?? 0) > 1;
        }
        unset($m);

        return array(
            'ok'          => true,
            'part_number' => $part_number,
            'model'       => $model,
            'suffix'      => $suffix,
            'shop_label'  => $this->config->item('wip_shop_labels')[$shop] ?? strtoupper($shop),
            'unit_count'  => count($matches),
            'vins'        => $matches,
        );
    }

    /**
     * Stream the row-level calc_detail() breakdown as a formatted .xlsx
     * report, same visual style as export() plus a Cutoff VIN / Formula
     * trail per row and a single Grand Total row.
     */
    public function export_detail($source, $title, $basis = 'bom')
    {
        $result = $this->calc_detail($source, $basis);
        if (!$result['ok']) {
            show_error($result['message'], 502, 'WIP Calc unavailable');

            return;
        }

        $headers = array('No', 'Part Number', 'Component', 'Material', 'Material Description', 'Model', 'Suffix', 'Shop', 'Cutoff VIN', 'Unit Count', 'Qty', 'Subtotal');
        $lastCol = chr(ord('A') + count($headers) - 1);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('WIP Calc Detail');

        $sheet->setCellValue('A1', strtoupper($title));
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 'Generated: ' . date('d F Y H:i'));
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('8B93A1');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $headerRow = 4;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F6FEB');
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($headerRow)->setRowHeight(22);

        // Component (C) and Material (D) hold long codes; keep them as text
        // so Excel never collapses them into scientific notation.
        $sheet->getStyle('C1:D1048576')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $r = $headerRow + 1;
        $grandTotal = 0.0;

        foreach ($result['data'] as $i => $row) {
            if (!$row['cutoff_vin']) {
                $vinDisplay = 'Total (no cutoff set)';
            } elseif ($row['cutoff_status'] === 'stale') {
                $vinDisplay = $row['cutoff_vin'] . ' (stale — not found, totaled instead)';
            } else {
                // Where this cutoff VIN sits among ALL of the shop's cached
                // units (not just ones matching this row's own model/suffix).
                $vinDisplay = $row['cutoff_vin'] . " (unit {$row['cutoff_position']} of {$row['total_wip']})";
            }

            $rowData = array(
                $i + 1,
                $row['part_number'],
                $row['component'],
                $row['material'],
                $row['material_description'],
                $row['model'],
                $row['suffix'],
                $row['shop_label'],
                $vinDisplay,
                (float) $row['unit_count'],
                (float) $row['qty'],
                (float) $row['subtotal'],
            );
            $sheet->fromArray($rowData, null, "A{$r}", true);
            $sheet->setCellValueExplicit("C{$r}", $row['component'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$r}", $row['material'], DataType::TYPE_STRING);

            $grandTotal += (float) $row['subtotal'];
            $r++;
        }

        $grandTotal = (float) $this->trim_qty($grandTotal);

        $sheet->setCellValue("A{$r}", 'GRAND TOTAL');
        $sheet->mergeCells("A{$r}:K{$r}");
        $sheet->setCellValue("L{$r}", $grandTotal);
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E4E9F0');

        // ---- Number formats — one call per column, not per cell (see column_format()) ----
        $sheet->getStyle("J" . ($headerRow + 1) . ":J{$r}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("K" . ($headerRow + 1) . ":K{$r}")->getNumberFormat()->setFormatCode($this->column_format(array_column($result['data'], 'qty')));
        $totalValues = array_column($result['data'], 'subtotal');
        $totalValues[] = $grandTotal;
        $sheet->getStyle("L" . ($headerRow + 1) . ":L{$r}")->getNumberFormat()->setFormatCode($this->column_format($totalValues));

        $sheet->getStyle("A{$headerRow}:A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("J" . ($headerRow + 1) . ":{$lastCol}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // ---- Zebra stripe (one conditional-format rule, not a per-row fill) ----
        $this->zebra_stripe($sheet, "A" . ($headerRow + 1) . ":{$lastCol}" . ($r - 1));

        $sheet->getStyle("A{$headerRow}:{$lastCol}{$r}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C9CFD8');
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_DOUBLE);

        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('E')->setAutoSize(false)->setWidth(38);

        $sheet->freezePane("A" . ($headerRow + 1));
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);

        $filename = 'wip_calc_detail_' . $source . '_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Stream the WIP Calc list as a formatted .xlsx "report" — title block,
     * styled header, zebra-striped rows, a Grand Total row, print setup
     * (landscape, header repeated on every page) — straight to the browser.
     */
    public function export($source, $title, $hide_zero = false, $shop_filter = '', $basis = 'bom')
    {
        $result = $this->calc($source, $basis);
        if (!$result['ok']) {
            show_error($result['message'], 502, 'WIP Calc unavailable');

            return;
        }

        if ($hide_zero) {
            // A part's Total is the sum of its (non-negative) shop columns, so
            // dropping Total == 0 rows never changes the Grand Total below.
            $result['data'] = array_values(array_filter($result['data'], function ($row) {
                return (float) $row['total'] !== 0.0;
            }));
        }

        $shop_codes = $this->config->item('wip_calc_shop_codes')[$source] ?? array();
        $shop_labels = $this->config->item('wip_shop_labels');
        $shop_keys = array_keys($shop_codes);

        // Same shop-card filter as the on-screen table (a BOM Shop Code
        // substring match, not a column filter) — so the download matches
        // whatever's currently shown when a shop card is active.
        if ($shop_filter !== '' && isset($shop_codes[$shop_filter])) {
            $needle = strtoupper($shop_codes[$shop_filter]);
            $result['data'] = array_values(array_filter($result['data'], function ($row) use ($needle) {
                return strpos(strtoupper($row['shop_code']), $needle) !== false;
            }));
        }

        // Every shop, plus the Total column, gets a Gross/Cutoff breakdown —
        // unlike the on-screen table (where it's behind a toggle), the
        // export always includes it, grouped under a merged shop-name
        // header (Welding: Gross | Cutoff | Net). Net isn't a separate data
        // column — it's just the shop's own main value, relabeled.
        $value_keys = array(); // ordered list of data keys, one per numeric column
        $groups = array(); // [label, key_prefix][] — one per 3-column group (shops, then Total)
        foreach ($shop_keys as $k) {
            $groups[] = array($shop_labels[$k] ?? strtoupper($k), $k);
            foreach (array('_gross', '_cutoff', '') as $suffix) {
                $value_keys[] = $k . $suffix;
            }
        }
        $groups[] = array('Total', 'total');
        foreach (array('total_gross', 'total_cutoff', 'total') as $k) {
            $value_keys[] = $k;
        }
        $vin_keys = array_map(function ($k) {
            return $k . '_vin';
        }, $shop_keys);

        $lastCol = chr(ord('A') + 5 + count($value_keys) + count($vin_keys) - 1);
        $firstShopCol = chr(ord('A') + 5); // after No, Part Number, Material Description, Shop Code, Model
        $lastNumericCol = chr(ord($firstShopCol) + count($value_keys) - 1); // last column before the VIN block

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('WIP Calc');

        // ---- Title block ----
        $sheet->setCellValue('A1', strtoupper($title));
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 'Generated: ' . date('d F Y H:i'));
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('8B93A1');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // ---- Header rows: row 4 = group headers (shop name merged across
        // its Qty/Gross/Cutoff/Net columns), row 5 = the sub-labels. The
        // leading and VIN columns span both rows instead (no sub-label). ----
        $headerRow = 4;
        $subHeaderRow = 5;
        $firstDataRow = 6;

        foreach (array('A' => 'No', 'B' => 'Part Number', 'C' => 'Material Description', 'D' => 'Shop Code', 'E' => 'Model') as $col => $label) {
            $sheet->setCellValue("{$col}{$headerRow}", $label);
            $sheet->mergeCells("{$col}{$headerRow}:{$col}{$subHeaderRow}");
        }

        $col = $firstShopCol;
        foreach ($groups as $group) {
            list($label, ) = $group;
            $endCol = chr(ord($col) + 2);
            $sheet->setCellValue("{$col}{$headerRow}", $label);
            $sheet->mergeCells("{$col}{$headerRow}:{$endCol}{$headerRow}");
            $sheet->fromArray(array('Gross', 'Cutoff', 'Net'), null, "{$col}{$subHeaderRow}");
            $col = chr(ord($endCol) + 1);
        }
        foreach ($shop_keys as $k) {
            $sheet->setCellValue("{$col}{$headerRow}", ($shop_labels[$k] ?? strtoupper($k)) . ' Cutoff VIN');
            $sheet->mergeCells("{$col}{$headerRow}:{$col}{$subHeaderRow}");
            $col++;
        }

        $sheet->getStyle("A{$headerRow}:{$lastCol}{$subHeaderRow}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$subHeaderRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F6FEB');
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$subHeaderRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($headerRow)->setRowHeight(20);
        $sheet->getRowDimension($subHeaderRow)->setRowHeight(20);
        // The sub-label row reads lighter, since it's secondary to the group name above it.
        $sheet->getStyle("{$firstShopCol}{$subHeaderRow}:{$lastNumericCol}{$subHeaderRow}")->getFont()->setSize(9)->setBold(false);

        // ---- Data rows ----
        $r = $firstDataRow;
        $totals = array_fill_keys($value_keys, 0.0);

        foreach ($result['data'] as $i => $row) {
            $rowData = array_merge(
                array($i + 1, $row['part_number'], $row['material_description'], $row['shop_code'], $row['models']),
                array_map(function ($k) use ($row) {
                    return (float) $row[$k];
                }, $value_keys),
                array_map(function ($k) use ($row) {
                    return $row[$k]; // null -> left blank by fromArray's strict-null handling
                }, $vin_keys)
            );
            // strictNullComparison: without it, fromArray()'s loose "== null"
            // check treats numeric 0 as null and leaves the cell blank.
            $sheet->fromArray($rowData, null, "A{$r}", true);

            foreach ($value_keys as $k) {
                $totals[$k] += (float) $row[$k];
            }

            $r++;
        }

        // ---- Grand total row ----
        // Re-trimmed the same way as the on-screen totals, so a run of float
        // additions that lands a hair off a whole number (e.g. 11.9999999998)
        // doesn't get treated as "has a fraction" by qty_format() below.
        foreach ($value_keys as $k) {
            $totals[$k] = (float) $this->trim_qty($totals[$k]);
        }

        $sheet->setCellValue("A{$r}", 'GRAND TOTAL');
        $sheet->mergeCells("A{$r}:E{$r}");
        $col = $firstShopCol;
        foreach ($value_keys as $k) {
            $sheet->setCellValue("{$col}{$r}", $totals[$k]);
            $col++;
        }
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E4E9F0');

        // ---- Number formats — one call per column (values + Grand Total
        // together), not per cell; see column_format(). ----
        $col = $firstShopCol;
        foreach ($value_keys as $k) {
            $values = array_column($result['data'], $k);
            $values[] = $totals[$k];
            $sheet->getStyle("{$col}{$firstDataRow}:{$col}{$r}")->getNumberFormat()->setFormatCode($this->column_format($values));
            $col++;
        }

        // ---- Alignment ----
        $sheet->getStyle("A{$headerRow}:A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        // The Net/Gross/Cutoff columns are centered — the VIN columns past
        // $lastNumericCol stay left-aligned (default), since they're text.
        $sheet->getStyle("{$firstShopCol}{$firstDataRow}:{$lastNumericCol}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // ---- Dim the Gross/Cutoff columns slightly so the main
        // Welding/Toso/Assy/Total (Net) figure still reads as the primary value ----
        $col = $firstShopCol;
        foreach ($groups as $group) {
            $sheet->getStyle("{$col}{$firstDataRow}:" . chr(ord($col) + 1) . $r)->getFont()->getColor()->setRGB('8B93A1');
            $col = chr(ord($col) + 3); // Gross, Cutoff, Net -> next group
        }

        // ---- Zebra stripe (one conditional-format rule, not a per-row fill) ----
        $this->zebra_stripe($sheet, "A{$firstDataRow}:{$lastCol}" . ($r - 1));

        // ---- Grid, then the group dividers and the Grand Total's double
        // top rule (both must come after the grid pass, or its thin border
        // overwrites them) ----
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$r}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C9CFD8');

        // A colored left border marks where one group (Welding | Toso |
        // Assy | Total) ends and the next begins — same accent used for the
        // on-screen table's .col-group-start divider.
        $col = $firstShopCol;
        foreach ($groups as $group) {
            $sheet->getStyle("{$col}{$headerRow}:{$col}{$r}")->getBorders()->getLeft()
                ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('4F8CFF');
            $col = chr(ord($col) + 3);
        }

        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_DOUBLE);

        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('C')->setAutoSize(false)->setWidth(38);

        $sheet->freezePane("A{$firstDataRow}");

        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerRow, $subHeaderRow);

        $filename = 'wip_calc_' . $source . '_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * WIP Summary cards: card => the unit shops whose units that card counts
     * its parts on. A card counts its parts on every unit from its own shop to
     * the end of the line, since each later unit already carries the part:
     *   IPI     -> WOS IPI, Welding, Toso, Assy   (parts of the WIP Calc IPI list)
     *   FTI     -> WOS FTI, Welding, Toso, Assy   (parts of the WIP Calc FTI list)
     *   Welding -> Welding, Toso, Assy            (the other welding parts)
     *   Toso    -> Toso, Assy
     *   Assy    -> Assy
     * 'wos_ipi' / 'wos_fti' are the two WIP WOS lists (wip_data shop 'wos' with
     * shopcode WOS IPI / WOS FTI, config wos_types); the combined 'wos' shop
     * (WOS3 / WOS4) stays a WIP Calc column only.
     *
     * @return array<string,string[]>
     */
    public function summary_stages()
    {
        $line = array_values(array_diff(
            array_keys($this->config->item('wip_calc_shop_codes')['kap1'] ?? array()),
            array('wos')
        ));

        $stages = array();
        foreach (array_keys((array) $this->config->item('wos_types')) as $type) {
            $stages[$type] = array_merge(array('wos_' . $type), $line);
        }
        foreach ($line as $i => $shop) {
            $stages[$shop] = array_slice($line, $i);
        }

        return $stages;
    }

    /** Labels for every summary card and unit shop: the shop labels plus IPI, FTI, WOS IPI, WOS FTI. */
    public function summary_labels()
    {
        $labels = (array) $this->config->item('wip_shop_labels');
        foreach ((array) $this->config->item('wos_types') as $type => $code) {
            $labels[$type] = strtoupper($type);
            $labels['wos_' . $type] = $code;
        }

        return $labels;
    }

    /** The WIP WOS list a summary unit shop names ('wos_ipi' -> 'WOS IPI'), or null for a regular shop. */
    protected function wos_list_code($unit_shop)
    {
        $types = (array) $this->config->item('wos_types');
        $type = strpos($unit_shop, 'wos_') === 0 ? substr($unit_shop, 4) : null;

        return ($type !== null && isset($types[$type])) ? $types[$type] : null;
    }

    /** get_counts() for a summary unit shop — including the WIP WOS IPI / FTI lists. */
    protected function unit_counts($source, $unit_shop, $boundary)
    {
        $code = $this->wos_list_code($unit_shop);
        if ($code === null) {
            return $this->get_counts($source, $unit_shop, $boundary);
        }

        $cache_key = $unit_shop . '|' . ($boundary === null ? 'ALL' : $boundary);
        if (isset($this->counts_cache[$cache_key])) {
            return $this->counts_cache[$cache_key];
        }

        $this->db->select('modelcode, sfx')->where(array('source' => $source, 'shop' => 'wos', 'shopcode' => $code));
        if ($boundary !== null) {
            $this->db->where('id >=', $boundary);
        }
        $freq = array();
        foreach ($this->db->get('wip_data')->result_array() as $row) {
            $key = $this->match_key($row['modelcode'], $row['sfx']);
            $freq[$key] = ($freq[$key] ?? 0) + 1;
        }

        return $this->counts_cache[$cache_key] = $freq;
    }

    /** wip_data id of a VIN in a summary unit shop's list, or null if it isn't cached there. */
    protected function unit_vin_id($source, $unit_shop, $vin)
    {
        $code = $this->wos_list_code($unit_shop);
        $this->db->select('id')->where(array('source' => $source, 'vin' => $vin));
        if ($code === null) {
            $this->db->where('shop', $unit_shop);
        } else {
            $this->db->where(array('shop' => 'wos', 'shopcode' => $code));
        }
        $found = $this->db->get('wip_data')->row_array();

        return $found ? (int) $found['id'] : null;
    }

    /**
     * "WIP Summary" — one card per shop, each listing only that shop's own
     * parts (basis rows whose Shop Code names it for the KAP line) and
     * counting them on the units from that shop onward (summary_stages()):
     *   - units in the card's own shop -> Net, exactly as calc(): from the
     *     part's cutoff VIN in that shop; 0 while none is set; every unit
     *     if the cutoff is stale (no longer cached);
     *   - units in the card's other shops -> every unit there (they already
     *     carry the part).
     * A part listed in several shops (e.g. "WELD3,ASSY3,TOSO3") shows on each
     * of those cards, counted that card's way. Each suffix counts once per
     * part per card per unit shop, at its largest Qty (qty_once()).
     *
     * Every row carries, per card: in_<card> (listed there), <card>__<unit
     * shop> for each unit shop, sum_<card> (that card's value for the part)
     * and <card>_vin (the cutoff VIN used; "KAP1: …, KAP2: …" when both lines
     * are in scope). With $scope 'all', KAP1 and KAP2 are summed per
     * part_number.
     *
     * $with_state (for missing_cutoff(), one line at a time) also adds per
     * card: <card>_vin_state ('none' / 'stale' / 'ok'), <card>_vin_raw (the
     * cutoff VIN as set, even when stale) and <card>_own_gross (every unit
     * in the card's own shop, i.e. what Net would be from the oldest unit).
     *
     * @return array{ok:bool, message:string, stages:array, totals:array, parts:array, data:array}
     */
    public function summary($scope = 'all', $basis = 'bom', $with_state = false)
    {
        $sources = in_array($scope, array('kap1', 'kap2'), true) ? array($scope) : array('kap1', 'kap2');
        $stages = $this->summary_stages();

        $acc = array();
        foreach ($sources as $source) {
            $shop_codes = $this->config->item('wip_calc_shop_codes')[$source] ?? array();
            if (empty($shop_codes)) {
                return array('ok' => false, 'message' => 'Unknown WIP Calc source.', 'stages' => $stages, 'totals' => array(), 'parts' => array(), 'data' => array());
            }

            $this->counts_cache = array(); // keyed by shop, not source — reset per line (qty_once() with it)

            $cutoff_index = array();
            $cutoffs = $this->db
                ->where_in('shop_code', array_values($shop_codes))
                ->get($this->table)->result_array();
            foreach ($cutoffs as $row) {
                $cutoff_index[strtoupper(trim($row['shop_code'])) . '|' . strtoupper(trim($row['part_number']))] = $row;
            }
            $boundary_of = array(); // "UNIT SHOP|VIN" -> wip_data id (null = stale), resolved once per line

            // The WIP Calc IPI / FTI part lists of this line: type => [PART => its cutoff VIN in that WOS list].
            $list_parts = array();
            if ($this->db->table_exists('ippi_fti') && $this->db->field_exists('wos_type', 'ippi_fti')) {
                $listed = $this->db->select('wos_type, part_number, cutoff_vin')->where('plant', $source)->get('ippi_fti')->result_array();
                foreach ($listed as $p) {
                    $list_parts[$p['wos_type']][strtoupper(trim($p['part_number']))] = $p['cutoff_vin'];
                }
            }

            // Same basis-row selection as calc().
            $this->db->select($this->basis_select($basis, array(
                'material', 'part_number', 'material_description', 'uom', 'model', 'suffix', 'shop_code', 'qty',
            )), false)->from($this->basis_table($basis));
            $this->db->group_start();
            foreach ($shop_codes as $shop_code) {
                foreach (array_unique(array($shop_code, strtoupper($shop_code), strtolower($shop_code))) as $variant) {
                    $this->db->or_where('FIND_IN_SET(' . $this->db->escape($variant) . ', shop_code) >', 0);
                }
            }
            $this->db->group_end();
            $bom_rows = $this->db->get()->result_array();

            foreach ($bom_rows as $row) {
                $part_number = $row['part_number'] !== '' ? $row['part_number'] : $row['material'];
                if ($part_number === null || $part_number === '') {
                    continue;
                }
                $part_key = strtoupper(trim($part_number));

                // The cards this row belongs to: the shops its Shop Code names on
                // this line — except that a welding row of a part on the IPI / FTI
                // list goes to that list's card instead of Welding.
                $row_shops = array_map('strtoupper', array_filter(array_map('trim', explode(',', (string) $row['shop_code']))));
                $cards = array();
                foreach ($shop_codes as $shop => $shop_code) {
                    if (!isset($stages[$shop]) || !in_array(strtoupper($shop_code), $row_shops, true)) {
                        continue;
                    }
                    if ($shop === 'weld') {
                        $lists = array();
                        foreach (array_keys($list_parts) as $type) {
                            if (isset($stages[$type]) && array_key_exists($part_key, $list_parts[$type])) {
                                $lists[] = $type;
                            }
                        }
                        if ($lists) {
                            $cards = array_merge($cards, $lists);
                            continue;
                        }
                    }
                    $cards[] = $shop;
                }
                if (empty($cards)) {
                    continue;
                }

                if (!isset($acc[$part_number])) {
                    $acc[$part_number] = array(
                        'part_number'          => $part_number,
                        'material_description' => $row['material_description'],
                        'uom'                  => $row['uom'],
                        'shop_code'            => $row['shop_code'],
                        'source_set'           => array(),
                        'juklak_main'          => null,
                        'vin_set'              => array(), // card => [KAP line => cutoff VIN used there]
                        'vin_state'            => array(), // card => none / stale / ok ($with_state only)
                        'vin_raw'              => array(), // card => cutoff VIN as set ($with_state only)
                    );
                    foreach ($stages as $card => $units) {
                        $acc[$part_number]['in_' . $card] = false;
                        $acc[$part_number][$card . '_own_gross'] = 0.0;
                        foreach ($units as $unit_shop) {
                            $acc[$part_number][$card . '__' . $unit_shop] = 0.0;
                        }
                    }
                } else {
                    $acc[$part_number]['shop_code'] = $this->merge_shop_codes($acc[$part_number]['shop_code'], $row['shop_code']);
                    foreach (array('material_description', 'uom') as $field) {
                        if ((string) $acc[$part_number][$field] === '') {
                            $acc[$part_number][$field] = $row[$field];
                        }
                    }
                }
                $acc[$part_number]['source_set'][strtoupper($source)] = true;
                foreach ($cards as $card) {
                    $acc[$part_number]['in_' . $card] = true;
                }

                // Juklak: represented by its main part on this line — listed, but counted as 0 here.
                $juklak_main = $this->juklak_replaced($source)[$part_key] ?? null;
                if ($juklak_main !== null) {
                    $acc[$part_number]['juklak_main'] = $juklak_main;
                    continue;
                }

                $qty = (float) $row['qty'];
                $key = $this->match_key($row['model'], $row['suffix']);

                foreach ($cards as $card) {
                    // The card's own unit shop (Welding, Toso, Assy — or WOS IPI / FTI)
                    // is counted Net from the part's cutoff VIN there: Welding / Toso /
                    // Assy cutoffs come from wip_calc_cutoff, IPI / FTI ones from their
                    // part list (the same VIN WIP Calc IPI / FTI uses).
                    $own = $stages[$card][0];
                    if (isset($list_parts[$card])) {
                        $vin = $list_parts[$card][$part_key] ?? null;
                    } else {
                        $vin = $cutoff_index[strtoupper($shop_codes[$card]) . '|' . $part_key]['vin'] ?? null;
                    }
                    $vin = ($vin === null || $vin === '') ? null : $vin;
                    $vin_key = null;
                    if ($vin !== null) {
                        $vin_key = $own . '|' . strtoupper($vin);
                        if (!array_key_exists($vin_key, $boundary_of)) {
                            $boundary_of[$vin_key] = $this->unit_vin_id($source, $own, $vin);
                        }
                        if ($boundary_of[$vin_key] !== null) { // same as calc(): only a VIN still cached is shown
                            $acc[$part_number]['vin_set'][$card][strtoupper($source)] = $vin;
                        }
                    }

                    if ($with_state) {
                        $acc[$part_number]['vin_state'][$card] = $vin === null ? 'none' : ($boundary_of[$vin_key] === null ? 'stale' : 'ok');
                        $acc[$part_number]['vin_raw'][$card] = $vin;
                        $all_units = $this->unit_counts($source, $own, null);
                        $gross_add = $this->qty_once('gross|' . $card . '|' . $part_key . '|' . $key, $qty);
                        $acc[$part_number][$card . '_own_gross'] += ($all_units[$key] ?? 0) * $gross_add;
                    }

                    foreach ($stages[$card] as $unit_shop) {
                        if ($unit_shop === $own) {
                            if ($vin === null) {
                                continue; // no cutoff VIN set yet -> Net 0 in the card's own unit shop
                            }
                            $counts = $this->unit_counts($source, $unit_shop, $boundary_of[$vin_key]);
                        } elseif (isset($shop_codes[$unit_shop])) {
                            $counts = $this->unit_counts($source, $unit_shop, null); // every unit there carries the part
                        } else {
                            continue;
                        }
                        $add = $this->qty_once($card . '|' . $unit_shop . '|' . $part_key . '|' . $key, $qty);
                        $acc[$part_number][$card . '__' . $unit_shop] += ($counts[$key] ?? 0) * $add;
                    }
                }
            }
        }

        uksort($acc, 'strnatcasecmp');

        $totals = array();
        $parts = array();
        foreach ($stages as $card => $units) {
            $totals['sum_' . $card] = 0.0;
            $parts[$card] = 0;
            foreach ($units as $unit_shop) {
                $totals[$card . '__' . $unit_shop] = 0.0;
            }
        }

        $data = array();
        foreach ($acc as $row) {
            $sources_seen = array_keys($row['source_set']);
            $row['source'] = count($sources_seen) > 1 ? 'Both' : $sources_seen[0];
            unset($row['source_set']);

            foreach ($stages as $card => $units) {
                // Cutoff VIN used in the card's own shop; labelled per line when both are in scope.
                $vins = $row['vin_set'][$card] ?? array();
                if (count($vins) > 1) {
                    $labelled = array();
                    foreach ($vins as $line => $vin) {
                        $labelled[] = $line . ': ' . $vin;
                    }
                    $row[$card . '_vin'] = implode(', ', $labelled);
                } else {
                    $row[$card . '_vin'] = $vins ? reset($vins) : null;
                }

                $sum = 0.0;
                foreach ($units as $unit_shop) {
                    $value = $row[$card . '__' . $unit_shop];
                    $sum += $value;
                    $totals[$card . '__' . $unit_shop] += $value;
                    $row[$card . '__' . $unit_shop] = $this->trim_qty($value);
                }
                $totals['sum_' . $card] += $sum;
                if ($row['in_' . $card] && (float) $this->trim_qty($sum) !== 0.0) {
                    $parts[$card]++;
                }
                $row['sum_' . $card] = $this->trim_qty($sum);

                if ($with_state) {
                    $row[$card . '_vin_state'] = $row['vin_state'][$card] ?? null;
                    $row[$card . '_vin_raw'] = $row['vin_raw'][$card] ?? null;
                    $row[$card . '_own_gross'] = $this->trim_qty($row[$card . '_own_gross']);
                } else {
                    unset($row[$card . '_own_gross']);
                }
            }
            unset($row['vin_set'], $row['vin_state'], $row['vin_raw']);

            // "Total" card: every card's value for this part added up — no card filter.
            $row_total = 0.0;
            foreach (array_keys($stages) as $card) {
                $row_total += (float) $row['sum_' . $card];
            }
            $totals['sum_total'] = ($totals['sum_total'] ?? 0.0) + $row_total;
            if ((float) $this->trim_qty($row_total) !== 0.0) {
                $parts['total'] = ($parts['total'] ?? 0) + 1;
            }
            $row['sum_total'] = $this->trim_qty($row_total);

            $data[] = $row;
        }
        $totals += array('sum_total' => 0.0); // no rows at all
        $parts += array('total' => 0);

        foreach ($totals as $k => $v) {
            $totals[$k] = $this->trim_qty($v);
        }

        return array('ok' => true, 'message' => 'ok', 'stages' => $stages, 'totals' => $totals, 'parts' => $parts, 'data' => $data);
    }

    /** Human label for a WIP Summary scope. */
    public function summary_scope_label($scope)
    {
        if ($scope === 'kap1') {
            return 'KAP 1';
        }

        return $scope === 'kap2' ? 'KAP 2' : 'KAP 1 & 2';
    }

    /**
     * "Summary Tanpa Cutoff VIN" — the WIP Summary rows whose card value is
     * non-zero while the card's own shop has no usable cutoff VIN: none set
     * (own shop Net 0, so the whole value comes from the later shops — e.g.
     * a TOSO3 part with no Toso cutoff still getting 77 from Assy units), or
     * a stale VIN (no longer cached, so every own-shop unit is counted).
     * One row per KAP line × card × part; Juklak parts (counted 0) are left
     * out. Value keys: own_gross (every own-shop unit), own_counted (what the
     * card counts there), v_weld / v_toso / v_assy (the later shops; null when
     * the card doesn't count that shop) and summary (the card's value).
     *
     * @return array{ok:bool, message:string, stages:array, data:array}
     */
    public function missing_cutoff($scope = 'all', $basis = 'bom')
    {
        $sources = in_array($scope, array('kap1', 'kap2'), true) ? array($scope) : array('kap1', 'kap2');
        $stages = $this->summary_stages();
        $labels = $this->summary_labels();
        $card_order = array_flip(array_keys($stages));

        $data = array();
        foreach ($sources as $source) {
            // One line at a time, so each row's cutoff state belongs to exactly one line.
            $result = $this->summary($source, $basis, true);
            if (!$result['ok']) {
                return array('ok' => false, 'message' => $result['message'], 'stages' => $stages, 'data' => array());
            }

            foreach ($result['data'] as $row) {
                if ($row['juklak_main'] !== null) {
                    continue;
                }
                foreach ($stages as $card => $units) {
                    $state = $row[$card . '_vin_state'];
                    if (!$row['in_' . $card] || $state === null || $state === 'ok' || (float) $row['sum_' . $card] <= 0) {
                        continue;
                    }

                    $own = $units[0];
                    $item = array(
                        'source'               => strtoupper($source),
                        'card'                 => $card,
                        'card_label'           => $labels[$card] ?? strtoupper($card),
                        'own_shop'             => $own,
                        'own_label'            => $labels[$own] ?? strtoupper($own),
                        'part_number'          => $row['part_number'],
                        'material_description' => $row['material_description'],
                        'uom'                  => $row['uom'],
                        'shop_code'            => $row['shop_code'],
                        'vin_state'            => $state,
                        'cutoff_vin'           => $row[$card . '_vin_raw'],
                        'own_gross'            => $row[$card . '_own_gross'],
                        'own_counted'          => $row[$card . '__' . $own],
                    );
                    foreach (array('weld', 'toso', 'assy') as $shop) {
                        $item['v_' . $shop] = ($shop !== $own && in_array($shop, $units, true)) ? $row[$card . '__' . $shop] : null;
                    }
                    $item['summary'] = $row['sum_' . $card];
                    $data[] = $item;
                }
            }
        }

        usort($data, function ($a, $b) use ($card_order) {
            return strcmp($a['source'], $b['source'])
                ?: ($card_order[$a['card']] - $card_order[$b['card']])
                ?: strnatcasecmp($a['part_number'], $b['part_number']);
        });

        return array('ok' => true, 'message' => 'ok', 'stages' => $stages, 'data' => $data);
    }

    /** Human label for a card's cutoff state in missing_cutoff(). */
    public function cutoff_state_label($state)
    {
        if ($state === 'stale') {
            return 'Cutoff VIN stale (tidak ada di data WIP)';
        }

        return $state === 'none' ? 'Belum ada cutoff VIN' : 'Ada cutoff VIN';
    }

    /**
     * Per Model / Suffix breakdown of one missing_cutoff() row (AJAX, the
     * "Detail" modal): for every unit shop the card counts, how many cached
     * units match (all) and how many of them the card counts (counted — 0 in
     * the own shop while no cutoff is set), each × the suffix's largest Qty
     * (as qty_once()), so the Summary value can be traced shop by shop.
     *
     * @return array
     */
    public function missing_cutoff_detail($source, $card, $part_number_raw, $basis = 'bom')
    {
        $stages = $this->summary_stages();
        $shop_codes = $this->config->item('wip_calc_shop_codes')[$source] ?? array();
        $part_number = trim((string) $part_number_raw);
        if (empty($shop_codes) || !isset($stages[$card]) || $part_number === '') {
            return array('ok' => false, 'message' => 'Unknown line, card or part number.');
        }

        $labels = $this->summary_labels();
        $part_key = strtoupper($part_number);
        $units = $stages[$card];
        $own = $units[0];
        $is_list = isset(((array) $this->config->item('wos_types'))[$card]);
        // IPI / FTI cards hold the part's Welding rows (see summary()).
        $code = strtoupper($shop_codes[$is_list ? 'weld' : $card]);

        $juklak_main = $this->juklak_replaced($source)[$part_key] ?? null;
        if ($juklak_main !== null) {
            return array('ok' => false, 'message' => 'Part ini Juklak (diwakili main part ' . $juklak_main . '), jadi dihitung 0.');
        }

        // The cutoff VIN in the card's own shop — the same lookup as summary().
        $vin = null;
        if ($is_list) {
            if ($this->db->table_exists('ippi_fti') && $this->db->field_exists('wos_type', 'ippi_fti')) {
                $found = $this->db->select('cutoff_vin')
                    ->where(array('plant' => $source, 'wos_type' => $card, 'part_number' => $part_number))
                    ->get('ippi_fti')->row_array();
                $vin = $found['cutoff_vin'] ?? null;
            }
        } else {
            $found = $this->db->select('vin')
                ->where(array('shop_code' => $code, 'part_number' => $part_number))
                ->get($this->table)->row_array();
            $vin = $found['vin'] ?? null;
        }
        $vin = ($vin === null || $vin === '') ? null : $vin;
        $boundary = $vin !== null ? $this->unit_vin_id($source, $own, $vin) : null;
        $state = $vin === null ? 'none' : ($boundary === null ? 'stale' : 'ok');

        $this->db->select($this->basis_select($basis, array(
            'material', 'part_number', 'material_description', 'model', 'suffix', 'shop_code', 'qty',
        )), false)->from($this->basis_table($basis));
        if ($this->basis_table($basis) === 'bom') {
            // A BOM row with a blank part_number is keyed by its material (as in summary()).
            $this->db->group_start()
                ->where('part_number', $part_number)
                ->or_group_start()->where('part_number', '')->where('material', $part_number)->group_end()
                ->group_end();
        } else {
            $this->db->where('part_number', $part_number);
        }

        $suffixes = array();
        $description = '';
        foreach ($this->db->get()->result_array() as $row) {
            $row_shops = array_map('strtoupper', array_filter(array_map('trim', explode(',', (string) $row['shop_code']))));
            if (!in_array($code, $row_shops, true)) {
                continue;
            }
            if ($description === '') {
                $description = (string) $row['material_description'];
            }
            $key = $this->match_key($row['model'], $row['suffix']);
            $qty = (float) $row['qty'];
            if (!isset($suffixes[$key])) {
                $suffixes[$key] = array('model' => trim((string) $row['model']), 'suffix' => trim((string) $row['suffix']), 'qty' => $qty);
            } else {
                $suffixes[$key]['qty'] = max($suffixes[$key]['qty'], $qty); // each suffix once, at its largest Qty
            }
        }
        if (empty($suffixes)) {
            return array('ok' => false, 'message' => 'Part ini tidak punya baris ' . $this->basis_label($basis) . ' dengan Shop Code ' . $code . '.');
        }
        uasort($suffixes, function ($a, $b) {
            return strnatcasecmp($a['model'], $b['model']) ?: strnatcasecmp($a['suffix'], $b['suffix']);
        });

        $unit_totals = array_fill_keys($units, array('all' => 0.0, 'counted' => 0.0));
        $grand = 0.0;
        $out = array();
        foreach ($suffixes as $key => $s) {
            $cells = array();
            $subtotal = 0.0;
            foreach ($units as $unit_shop) {
                $all = $this->unit_counts($source, $unit_shop, null)[$key] ?? 0;
                if ($unit_shop !== $own || $state === 'stale') {
                    $counted = $all; // later shops (and a stale own shop) count every unit
                } elseif ($state === 'none') {
                    $counted = 0;
                } else {
                    $counted = $this->unit_counts($source, $unit_shop, $boundary)[$key] ?? 0;
                }
                $cells[$unit_shop] = array('all' => $all, 'counted' => $counted);
                $subtotal += $counted * $s['qty'];
                $unit_totals[$unit_shop]['all'] += $all * $s['qty'];
                $unit_totals[$unit_shop]['counted'] += $counted * $s['qty'];
            }
            $grand += $subtotal;
            $out[] = array(
                'model'    => $s['model'],
                'suffix'   => $s['suffix'],
                'qty'      => $this->trim_qty($s['qty']),
                'units'    => $cells,
                'subtotal' => $this->trim_qty($subtotal),
            );
        }
        foreach ($unit_totals as $unit_shop => $t) {
            $unit_totals[$unit_shop] = array('all' => $this->trim_qty($t['all']), 'counted' => $this->trim_qty($t['counted']));
        }

        return array(
            'ok'                   => true,
            'source'               => $source,
            'card'                 => $card,
            'card_label'           => $labels[$card] ?? strtoupper($card),
            'is_list'              => $is_list,
            'own_shop'             => $own,
            'part_number'          => $part_number,
            'material_description' => $description,
            'shop_code'            => $code,
            'vin_state'            => $state,
            'vin_state_label'      => $this->cutoff_state_label($state),
            'cutoff_vin'           => $vin,
            'units'                => $units,
            'suffixes'             => $out,
            'unit_totals'          => $unit_totals,
            'grand_total'          => $this->trim_qty($grand),
        );
    }

    /**
     * missing_cutoff() as a styled .xlsx — the same rows the page shows for
     * the selected card ('' = all cards) and cutoff status ('' = both).
     */
    public function export_missing_cutoff($scope, $card = '', $status = '', $basis = 'bom')
    {
        $result = $this->missing_cutoff($scope, $basis);
        if (!$result['ok']) {
            show_error($result['message'], 502, 'WIP Summary unavailable');

            return;
        }

        $card = isset($result['stages'][$card]) ? $card : '';
        $status = in_array($status, array('none', 'stale'), true) ? $status : '';
        $rows = array_values(array_filter($result['data'], function ($row) use ($card, $status) {
            return ($card === '' || $row['card'] === $card) && ($status === '' || $row['vin_state'] === $status);
        }));

        $labels = $this->summary_labels();
        $headers = array(
            'No', 'Line', 'Card', 'Part Number', 'Material Description', 'UOM', 'Shop Code', 'Status Cutoff', 'Cutoff VIN', 'Shop Sendiri',
            'Gross Shop Sendiri', 'Terhitung Shop Sendiri', 'Welding', 'Toso', 'Assy', 'Summary',
        );
        $value_keys = array('own_gross', 'own_counted', 'v_weld', 'v_toso', 'v_assy', 'summary');
        $lastCol = 'P';
        $firstValueCol = 'K';
        $leadLastCol = 'J';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Tanpa Cutoff VIN');

        $title = 'Summary Tanpa Cutoff VIN - ' . $this->summary_scope_label($scope)
            . ($card !== '' ? ' - Card ' . ($labels[$card] ?? strtoupper($card)) : '');
        $sheet->setCellValue('A1', strtoupper($title));
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 'Part yang Summary-nya terisi padahal shop sendiri belum punya cutoff VIN'
            . ($status !== '' ? ' (' . $this->cutoff_state_label($status) . ')' : '')
            . '  |  Basis: ' . $this->basis_label($basis) . '  |  Generated: ' . date('d F Y H:i'));
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('8B93A1');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $headerRow = 4;
        $firstDataRow = 5;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F6FEB');
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($headerRow)->setRowHeight(30);

        $r = $firstDataRow;
        $totals = array_fill_keys($value_keys, 0.0);
        foreach ($rows as $i => $row) {
            $lead_values = array(
                $i + 1, $row['source'], $row['card_label'], $row['part_number'], $row['material_description'], $row['uom'],
                $row['shop_code'], $this->cutoff_state_label($row['vin_state']), $row['cutoff_vin'], $row['own_label'],
            );
            // null (a shop the card doesn't count) stays blank; strictNullComparison keeps a numeric 0.
            $sheet->fromArray(array_merge($lead_values, array_map(function ($k) use ($row) {
                return $row[$k] === null ? null : (float) $row[$k];
            }, $value_keys)), null, "A{$r}", true);

            foreach ($value_keys as $k) {
                $totals[$k] += (float) $row[$k];
            }
            $r++;
        }

        $sheet->setCellValue("A{$r}", 'GRAND TOTAL');
        $sheet->mergeCells("A{$r}:{$leadLastCol}{$r}");
        $col = $firstValueCol;
        foreach ($value_keys as $k) {
            $totals[$k] = (float) $this->trim_qty($totals[$k]);
            $sheet->setCellValue("{$col}{$r}", $totals[$k]);
            $col++;
        }
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E4E9F0');

        $col = $firstValueCol;
        foreach ($value_keys as $k) {
            $values = array_column($rows, $k);
            $values[] = $totals[$k];
            $sheet->getStyle("{$col}{$firstDataRow}:{$col}{$r}")->getNumberFormat()->setFormatCode($this->column_format($values));
            $col++;
        }

        $sheet->getStyle("A{$headerRow}:C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("F{$firstDataRow}:F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("J{$firstDataRow}:{$lastCol}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("{$lastCol}{$firstDataRow}:{$lastCol}{$r}")->getFont()->setBold(true);

        if ($r > $firstDataRow) {
            $this->zebra_stripe($sheet, "A{$firstDataRow}:{$lastCol}" . ($r - 1));
        }

        $sheet->getStyle("A{$headerRow}:{$lastCol}{$r}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C9CFD8');
        $sheet->getStyle("{$lastCol}{$headerRow}:{$lastCol}{$r}")->getBorders()->getLeft()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('4F8CFF');
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_DOUBLE);

        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('E')->setAutoSize(false)->setWidth(38);

        $sheet->freezePane("A{$firstDataRow}");
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);

        $filename = 'wip_summary_tanpa_cutoff_' . ($card !== '' ? $card . '_' : '') . $scope . '_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Stream one WIP Summary card ($stage) as a styled .xlsx report — the
     * same rows and columns the on-screen table shows for that card: only
     * the card's own parts, its cutoff VIN, one column per unit shop it
     * counts on, then the card's value, plus a Grand Total row. $stage
     * 'total' is the Total card: every part, each card's value, then their sum.
     */
    public function export_summary($scope, $stage, $hide_zero = false, $basis = 'bom')
    {
        $result = $this->summary($scope, $basis);
        if (!$result['ok']) {
            show_error($result['message'], 502, 'WIP Summary unavailable');

            return;
        }

        $is_total = $stage === 'total';
        if (!$is_total && !isset($result['stages'][$stage])) {
            $stage = isset($result['stages']['weld']) ? 'weld' : key($result['stages']); // unknown/blank -> Welding
        }
        $sum_key = 'sum_' . $stage;

        // A card's own parts only, as on screen; the Total card takes every part.
        $result['data'] = array_values(array_filter($result['data'], function ($row) use ($is_total, $stage, $sum_key, $hide_zero) {
            return ($is_total || $row['in_' . $stage]) && (!$hide_zero || (float) $row[$sum_key] !== 0.0);
        }));

        $shop_labels = $this->summary_labels();
        $label_of = function ($s) use ($shop_labels) {
            return $s === 'total' ? 'Total' : ($shop_labels[$s] ?? strtoupper($s));
        };
        $stage_label = $label_of($stage);
        $cards = array_keys($result['stages']);

        $lead = array('No', 'Part Number', 'Material Description', 'UOM', 'Shop Code', 'Source');
        if ($is_total) {
            $value_keys = array_merge(array_map(function ($card) {
                return 'sum_' . $card;
            }, $cards), array('sum_total'));
            $value_headers = array_merge(array_map(function ($card) use ($label_of) {
                return 'Summary ' . $label_of($card);
            }, $cards), array('Total'));
            $subtitle = 'Jumlah semua card: ' . implode(' + ', array_map($label_of, $cards)) . ', semua part';
        } else {
            $units = $result['stages'][$stage];
            $lead[] = $stage_label . ' Cutoff VIN';
            $value_keys = array_merge(array_map(function ($unit_shop) use ($stage) {
                return $stage . '__' . $unit_shop;
            }, $units), array($sum_key));
            $value_headers = array_merge(array_map($label_of, $units), array('Summary ' . $stage_label));
            $subtitle = 'Part ' . $stage_label . ' saja, dihitung di unit ' . implode(' + ', array_map($label_of, $units));
        }

        $headers = array_merge($lead, $value_headers);
        $lastCol = chr(ord('A') + count($headers) - 1);
        $firstShopCol = chr(ord('A') + count($lead)); // first numeric column
        $leadLastCol = chr(ord($firstShopCol) - 1);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary ' . $stage_label);

        $sheet->setCellValue('A1', strtoupper('WIP Summary ' . $stage_label . ' - ' . $this->summary_scope_label($scope)));
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', $subtitle . '  |  Basis: ' . $this->basis_label($basis) . '  |  Generated: ' . date('d F Y H:i'));
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('8B93A1');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $headerRow = 4;
        $firstDataRow = 5;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F6FEB');
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($headerRow)->setRowHeight(22);

        $r = $firstDataRow;
        $totals = array_fill_keys($value_keys, 0.0);

        foreach ($result['data'] as $i => $row) {
            $lead_values = array($i + 1, $row['part_number'], $row['material_description'], $row['uom'], $row['shop_code'], $row['source']);
            if (!$is_total) {
                $lead_values[] = $row[$stage . '_vin'];
            }
            $rowData = array_merge($lead_values, array_map(function ($k) use ($row) {
                return (float) $row[$k];
            }, $value_keys));
            // strictNullComparison, so a numeric 0 isn't left blank (see export()).
            $sheet->fromArray($rowData, null, "A{$r}", true);

            foreach ($value_keys as $k) {
                $totals[$k] += (float) $row[$k];
            }
            $r++;
        }

        foreach ($value_keys as $k) {
            $totals[$k] = (float) $this->trim_qty($totals[$k]);
        }

        $sheet->setCellValue("A{$r}", 'GRAND TOTAL');
        $sheet->mergeCells("A{$r}:{$leadLastCol}{$r}");
        $col = $firstShopCol;
        foreach ($value_keys as $k) {
            $sheet->setCellValue("{$col}{$r}", $totals[$k]);
            $col++;
        }
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E4E9F0');

        $col = $firstShopCol;
        foreach ($value_keys as $k) {
            $values = array_column($result['data'], $k);
            $values[] = $totals[$k];
            $sheet->getStyle("{$col}{$firstDataRow}:{$col}{$r}")->getNumberFormat()->setFormatCode($this->column_format($values));
            $col++;
        }

        $sheet->getStyle("A{$headerRow}:A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D{$firstDataRow}:D{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("F{$firstDataRow}:{$lastCol}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("{$lastCol}{$firstDataRow}:{$lastCol}{$r}")->getFont()->setBold(true);

        if ($r > $firstDataRow) {
            $this->zebra_stripe($sheet, "A{$firstDataRow}:{$lastCol}" . ($r - 1));
        }

        $sheet->getStyle("A{$headerRow}:{$lastCol}{$r}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C9CFD8');
        // Same accent divider as the on-screen .col-group-start, marking the summary column.
        $sheet->getStyle("{$lastCol}{$headerRow}:{$lastCol}{$r}")->getBorders()->getLeft()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('4F8CFF');
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_DOUBLE);

        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('C')->setAutoSize(false)->setWidth(38);

        $sheet->freezePane("A{$firstDataRow}");
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);

        $filename = 'wip_summary_' . $stage . '_' . $scope . '_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * (model|suffix) -> unit count within a shop, for units from $boundary
     * (a wip_data id) up to the newest, or every cached unit if $boundary
     * is null. Memoized per call since many parts share the same boundary
     * (most commonly "no cutoff set yet").
     */
    protected function get_counts($source, $shop, $boundary)
    {
        $cache_key = $shop . '|' . ($boundary === null ? 'ALL' : $boundary);
        if (isset($this->counts_cache[$cache_key])) {
            return $this->counts_cache[$cache_key];
        }

        $this->db->select('modelcode, sfx')
            ->where('source', $source)
            ->where('shop', $shop);
        if ($boundary !== null) {
            $this->db->where('id >=', $boundary);
        }
        $wip_rows = $this->db->get('wip_data')->result_array();

        $freq = array();
        foreach ($wip_rows as $row) {
            $key = $this->match_key($row['modelcode'], $row['sfx']);
            $freq[$key] = ($freq[$key] ?? 0) + 1;
        }

        $this->counts_cache[$cache_key] = $freq;

        return $freq;
    }

    protected function trim_qty($value)
    {
        $trimmed = rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * Excel number format for a quantity cell — only enables decimal digits
     * when the value actually has a fractional part. A format like
     * "#,##0.###" applied to a clean whole number renders a dangling,
     * digit-less decimal separator (e.g. "12,") in some viewers/locales, so
     * whole numbers get the plain "#,##0" mask instead.
     */
    protected function qty_format($value)
    {
        return strpos((string) $value, '.') !== false ? '#,##0.###' : '#,##0';
    }

    /**
     * Same idea as qty_format(), but for a whole column at once: styling
     * cell-by-cell (getStyle() per row) is what blew the memory limit on a
     * multi-thousand-row export (see export_detail()), so number formats
     * are applied once per column range instead. If every value in the
     * column is a whole number the plain mask is used for all of it;
     * otherwise the decimal-capable mask is used for all of it (a handful
     * of whole-number cells in an otherwise-fractional column may then show
     * a dangling separator in some viewers — the tradeoff for one style
     * call per column instead of one per cell).
     */
    protected function column_format(array $values)
    {
        // Three sections (positive;negative;zero) — zero reads as a dash,
        // same as the on-screen table, instead of a grid full of "0"s.
        foreach ($values as $value) {
            if (strpos((string) $value, '.') !== false) {
                return '#,##0.###;-#,##0.###;"-"';
            }
        }

        return '#,##0;-#,##0;"-"';
    }

    /**
     * Zebra-stripe a data range via a conditional formatting rule (one style
     * call for the whole range) instead of looping and calling getStyle()
     * per row — that loop is what exhausted memory_limit on a large export.
     */
    protected function zebra_stripe(Worksheet $sheet, $range)
    {
        $conditional = new Conditional();
        $conditional->setConditionType(Conditional::CONDITION_EXPRESSION);
        $conditional->addCondition('MOD(ROW(),2)=0');
        $conditional->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F4F7');

        $sheet->getStyle($range)->setConditionalStyles(array($conditional));
    }

    protected function match_key($model, $suffix)
    {
        return strtoupper(trim((string) $model)) . '|' . strtoupper(trim((string) $suffix));
    }

    /**
     * Apply parsed cutoff rows: a VIN is only accepted if it currently
     * exists in the cached wip_data for the row's resolved (source, shop),
     * and only for shop codes that belong to $source. Each (shop_code,
     * part_number) pair keeps at most one cutoff row, replaced on every
     * successful upload.
     *
     * @return array{applied:int, not_found:int, wrong_source:int}
     */
    public function upsert_cutoffs($source, array $rows, $user_id)
    {
        $applied = 0;
        $not_found = 0;
        $wrong_source = 0;

        foreach ($rows as $row) {
            if ($row['source'] !== $source) {
                $wrong_source++;
                continue;
            }

            if (!$this->wip_row_exists($source, $row['shop'], $row['vin'])) {
                $not_found++;
                continue;
            }

            $this->upsert_one($row['shop_code'], $row['part_number'], $row['vin'], $user_id);
            $applied++;
        }

        return array('applied' => $applied, 'not_found' => $not_found, 'wrong_source' => $wrong_source);
    }

    /**
     * Delete every cutoff VIN set for one shop (weld|toso|assy) within a
     * KAP line — used by the per-shop "Clear" button on the WIP Calc page.
     * Only the cutoffs are removed; the cached Master WIP data itself
     * (wip_data) is untouched, so the shop's totals simply fall back to
     * Gross (every cached unit) for every part until new cutoffs are set.
     *
     * @return array{ok:bool, message:string, shop_code?:string, cleared:int}
     */
    public function clear_shop_cutoffs($source, $shop)
    {
        $shop_codes = $this->config->item('wip_calc_shop_codes')[$source] ?? array();
        if (!isset($shop_codes[$shop])) {
            return array('ok' => false, 'message' => 'Unknown Shop for this KAP line.', 'cleared' => 0);
        }
        $shop_code = $shop_codes[$shop];

        $cleared = (int) $this->db->where('shop_code', $shop_code)->count_all_results($this->table);
        if ($cleared === 0) {
            return array(
                'ok'        => true,
                'message'   => "No cutoff VINs were set for {$shop_code}; nothing to clear.",
                'shop_code' => $shop_code,
                'cleared'   => 0,
            );
        }

        $this->db->where('shop_code', $shop_code)->delete($this->table);

        return array(
            'ok'        => true,
            'message'   => "Cleared {$cleared} cutoff VIN(s) for {$shop_code}. That shop's totals now show Gross (every cached unit) until new cutoffs are set.",
            'shop_code' => $shop_code,
            'cleared'   => $cleared,
        );
    }

    /**
     * Set (or replace) the cutoff VIN for a single part, entered by hand
     * (as opposed to the bulk Excel upload). $shop_raw is matched the same
     * way as the upload's Shop column (label or shop_code), but only shop
     * codes belonging to $source are accepted.
     *
     * @return array{ok:bool, message:string, shop_code?:string, part_number?:string, vin?:string}
     */
    public function set_cutoff($source, $shop_raw, $part_number_raw, $vin_raw, $user_id)
    {
        $part_number = strip_trailing_dash00(trim((string) $part_number_raw));
        $vin = trim((string) $vin_raw);
        $resolved = $this->resolve_shop_code($shop_raw);

        if ($part_number === '') {
            return array('ok' => false, 'message' => 'Part Number is required.');
        }
        if ($vin === '') {
            return array('ok' => false, 'message' => 'VIN is required.');
        }
        if ($resolved === null || $resolved['source'] !== $source) {
            return array('ok' => false, 'message' => 'Unknown Shop for this KAP line.');
        }
        if (!$this->wip_row_exists($source, $resolved['shop'], $vin)) {
            return array('ok' => false, 'message' => "VIN not found in the cached Master WIP data for {$resolved['shop_code']}. Pull or upload that shop's WIP data first.");
        }

        $this->upsert_one($resolved['shop_code'], $part_number, $vin, $user_id);

        return array(
            'ok'          => true,
            'message'     => "Cutoff VIN set for {$part_number} ({$resolved['shop_code']}).",
            'shop_code'   => $resolved['shop_code'],
            'part_number' => $part_number,
            'vin'         => $vin,
        );
    }

    /**
     * Set (or replace) the same cutoff VIN for EVERY part_number that uses
     * one shop within a KAP line, in one go — the simpler alternative to
     * set_cutoff() when the same cutoff genuinely applies line-wide (e.g.
     * "everything from this VIN on is Welding WIP") instead of per part.
     *
     * @return array{ok:bool, message:string, shop_code?:string, vin?:string, applied?:int}
     */
    public function set_shop_cutoff($source, $shop, $vin_raw, $user_id, $basis = 'bom')
    {
        $shop_codes = $this->config->item('wip_calc_shop_codes')[$source] ?? array();
        if (!isset($shop_codes[$shop])) {
            return array('ok' => false, 'message' => 'Unknown Shop for this KAP line.');
        }
        $shop_code = $shop_codes[$shop];

        $vin = trim((string) $vin_raw);
        if ($vin === '') {
            return array('ok' => false, 'message' => 'VIN is required.');
        }
        if (!$this->wip_row_exists($source, $shop, $vin)) {
            return array('ok' => false, 'message' => "VIN not found in the cached Master WIP data for {$shop_code}. Pull or upload that shop's WIP data first.");
        }

        $part_numbers = $this->bom_part_numbers_for_shop($shop_code, $basis);
        if (empty($part_numbers)) {
            return array('ok' => false, 'message' => "No BOM parts found for {$shop_code}.");
        }

        foreach ($part_numbers as $part_number) {
            $this->upsert_one($shop_code, $part_number, $vin, $user_id);
        }

        return array(
            'ok'        => true,
            'message'   => 'Cutoff VIN ' . $vin . ' applied to all ' . count($part_numbers) . " part(s) in {$shop_code}.",
            'shop_code' => $shop_code,
            'vin'       => $vin,
            'applied'   => count($part_numbers),
        );
    }

    /** Distinct part numbers (BOM part_number, falling back to material) whose Shop Code lists $shop_code. */
    protected function bom_part_numbers_for_shop($shop_code, $basis = 'bom')
    {
        $this->db->select($this->basis_select($basis, array('part_number', 'material')), false)
            ->from($this->basis_table($basis));
        $this->db->group_start();
        foreach (array_unique(array($shop_code, strtoupper($shop_code), strtolower($shop_code))) as $variant) {
            $this->db->or_where('FIND_IN_SET(' . $this->db->escape($variant) . ', shop_code) >', 0);
        }
        $this->db->group_end();
        $rows = $this->db->get()->result_array();

        $parts = array();
        foreach ($rows as $row) {
            $part_number = $row['part_number'] !== '' ? $row['part_number'] : $row['material'];
            $part_number = trim((string) $part_number);
            if ($part_number !== '') {
                $parts[$part_number] = true;
            }
        }

        return array_keys($parts);
    }

    protected function wip_row_exists($source, $shop, $vin)
    {
        return (bool) $this->db->select('id')
            ->where(array('source' => $source, 'shop' => $shop, 'vin' => $vin))
            ->get('wip_data')->row_array();
    }

    protected function upsert_one($shop_code, $part_number, $vin, $user_id)
    {
        $now = date('Y-m-d H:i:s');

        $existing = $this->db->select('id')
            ->where(array('shop_code' => $shop_code, 'part_number' => $part_number))
            ->get($this->table)->row_array();

        if ($existing) {
            $this->db->where('id', $existing['id'])->update($this->table, array(
                'vin'        => $vin,
                'user_id'    => $user_id,
                'updated_at' => $now,
            ));
        } else {
            $this->db->insert($this->table, array(
                'shop_code'   => $shop_code,
                'part_number' => $part_number,
                'vin'         => $vin,
                'user_id'     => $user_id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ));
        }
    }

    /**
     * Resolve a Shop value (as typed in the upload's Shop column, or picked
     * from the set-cutoff form) — label or shop_code, any KAP line — back
     * to its (source, shop, shop_code).
     */
    protected function resolve_shop_code($raw)
    {
        static $map = null;
        if ($map === null) {
            $map = array();
            foreach ($this->config->item('wip_calc_shop_codes') as $src => $shops) {
                foreach ($shops as $shop => $shop_code) {
                    $map[strtoupper($shop_code)] = array('source' => $src, 'shop' => $shop, 'shop_code' => $shop_code);
                }
            }
        }

        return $map[strtoupper(trim((string) $raw))] ?? null;
    }

    public function log($data)
    {
        $data = array_merge(array(
            'file_name'    => null,
            'total_rows'   => 0,
            'applied_rows' => 0,
            'skipped_rows' => 0,
            'user_id'      => null,
        ), $data);
        $data['created_at'] = date('Y-m-d H:i:s');
        $this->db->insert($this->log_table, $data);
    }

    /**
     * Stream the cutoff upload template (Part Number, VIN, Shop) straight to
     * the browser. With no $shop, this is the old generic template — one
     * blank example row per shop code. With a $shop (weld|toso|assy), it's
     * pre-filled instead: one row per part_number the BOM already lists
     * under that shop, Shop Code filled in, VIN left blank — so the user
     * only has to type in the VIN column and re-upload.
     */
    public function download_template($source, $shop = '', $basis = 'bom')
    {
        $shop_codes = $this->config->item('wip_calc_shop_codes')[$source] ?? array();
        $shop = trim((string) $shop);
        if ($shop !== '' && !isset($shop_codes[$shop])) {
            show_error('Unknown Shop for this KAP line.', 400);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cutoff VIN Template');

        $sheet->fromArray($this->required_headers, null, 'A1');
        $sheet->getStyle('A1:C1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:C1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F6FEB');

        $r = 2;
        if ($shop !== '') {
            $shop_code = $shop_codes[$shop];
            $part_numbers = $this->bom_part_numbers_for_shop($shop_code, $basis);
            sort($part_numbers, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($part_numbers as $part_number) {
                $sheet->fromArray(array($part_number, '', strtoupper($shop_code)), null, "A{$r}");
                $r++;
            }
            $filename = 'template_upload_wip_calc_cutoff_' . $source . '_' . $shop . '.xlsx';
        } else {
            // One example row per shop code, to show the expected format.
            foreach ($shop_codes as $shop_code) {
                $sheet->fromArray(array('', '', strtoupper($shop_code)), null, "A{$r}");
                $r++;
            }
            $filename = 'template_upload_wip_calc_cutoff_' . $source . '.xlsx';
        }

        foreach (range('A', 'C') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Parse an uploaded cutoff Excel file, validate its header row and
     * resolve each row's Shop value back to a (source, shop, shop_code).
     *
     * @return array{ok:bool, message:string, rows?:array, skipped?:int}
     */
    public function parse_excel($file_path)
    {
        try {
            $reader = IOFactory::createReaderForFile($file_path);
            $reader->setReadDataOnly(true);
            // Without this, a large single-line sheet XML can trip
            // libxml's default "huge input" guard — silently returning an
            // EMPTY sheet with no error, which then fails header
            // validation for a completely unrelated-looking reason. Hits
            // some servers and not others depending on the libxml build.
            if (method_exists($reader, 'setParseHuge')) {
                $reader->setParseHuge(true);
            }
            $spreadsheet = $reader->load($file_path);
        } catch (\Throwable $e) {
            return array('ok' => false, 'message' => 'Unable to read the Excel file: ' . $e->getMessage());
        }

        $rows = array();
        $skipped = 0;
        $formula_cells = 0;
        $header_checked = false;

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            foreach ($worksheet->getRowIterator() as $row) {
                $rowIndex = $row->getRowIndex();

                $cellIterator = $row->getCellIterator('A', 'C');
                $cellIterator->setIterateOnlyExistingCells(false);
                $cells = array();
                foreach ($cellIterator as $cell) {
                    $cells[] = $cell->getValue();
                }

                if ($rowIndex === 1) {
                    $header_checked = true;
                    if (!$this->header_matches($cells)) {
                        return array(
                            'ok' => false,
                            'message' => 'Invalid template. Expected columns: ' . implode(', ', $this->required_headers),
                        );
                    }
                    continue;
                }

                if ($this->is_blank_row($cells)) {
                    continue;
                }

                $part_number = strip_trailing_dash00(trim((string) ($cells[0] ?? '')));
                $vin = trim((string) ($cells[1] ?? ''));
                $shopRaw = trim((string) ($cells[2] ?? ''));
                $resolved = $this->resolve_shop_code($shopRaw);

                // A cell can come back as its literal formula text (e.g.
                // "=VLOOKUP(...)") instead of a value — happens when the
                // formula references another, unopened workbook (the
                // "[1]SheetName" style ref) so Excel never cached a result
                // for it. Flagged separately from a blank/unresolved cell
                // so the upload result actually says what's wrong, instead
                // of a confusing wall of "VIN not found in WIP data".
                if ($vin !== '' && $vin[0] === '=') {
                    $formula_cells++;
                    continue;
                }

                if ($part_number === '' || $vin === '' || $resolved === null) {
                    $skipped++;
                    continue;
                }

                $rows[] = array(
                    'part_number' => $part_number,
                    'vin'         => $vin,
                    'shop_code'   => $resolved['shop_code'],
                    'source'      => $resolved['source'],
                    'shop'        => $resolved['shop'],
                );
            }

            break; // only the first sheet is used for uploads
        }

        if (!$header_checked) {
            return array('ok' => false, 'message' => 'The uploaded file is empty.');
        }

        if (empty($rows) && $skipped === 0 && $formula_cells === 0) {
            return array('ok' => false, 'message' => 'No data rows found in the uploaded file.');
        }

        return array(
            'ok'            => true,
            'message'       => 'ok',
            'rows'          => $rows,
            'skipped'       => $skipped,
            'formula_cells' => $formula_cells,
        );
    }

    protected function header_matches(array $cells)
    {
        $normalize = function ($v) {
            return strtolower(trim((string) $v));
        };

        $expected = array_map($normalize, $this->required_headers);
        $actual = array_map($normalize, array_slice($cells, 0, count($this->required_headers)));

        return $expected === $actual;
    }

    protected function is_blank_row(array $cells)
    {
        foreach ($cells as $c) {
            if (trim((string) $c) !== '') {
                return false;
            }
        }

        return true;
    }
}
