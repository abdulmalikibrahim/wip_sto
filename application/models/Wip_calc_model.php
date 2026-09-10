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
     * Sum BOM part usage (grouped by part_number) per shop, each part's
     * contribution counted from its own cutoff VIN (or every cached unit,
     * for parts with no cutoff set yet) up to the newest unit.
     *
     * @return array{ok:bool, message:string, cutoff_summary:array, data:array}
     */
    public function calc($source)
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

        // BOM rows whose Shop Code lists (comma-separated, e.g. "WELD3,ASSY3,TOSO3")
        // include at least one of this line's shop codes. FIND_IN_SET matches a
        // single row's shop_code against a comma list — not string equality — so a
        // part shared across shops is only stored once but counted for each shop it
        // names. Matched case-insensitively, since upload data isn't normalized to upper.
        $this->db->select('material, part_number, material_description, uom, model, suffix, shop_code, qty')->from('bom');
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

            $part_key = strtoupper(trim($part_number));
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

                $qty = (float) $row['qty'];
                $key = $this->match_key($row['model'], $row['suffix']);

                // Net: units from the cutoff VIN onward (or every unit if no
                // cutoff is set yet — $boundary is null either way).
                $counts = $this->get_counts($source, $shop, $boundary);
                $unit_count = $counts[$key] ?? 0;
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

            $total = 0.0;
            $total_gross = 0.0;
            foreach (array_keys($shop_codes) as $s) {
                $total += (float) $row[$s];
                $total_gross += (float) $row[$s . '_gross'];
            }
            foreach (array_keys($shop_codes) as $s) {
                $net = (float) $row[$s];
                $gross = (float) $row[$s . '_gross'];
                // Gross/Cutoff/Net breakdown behind each shop's main figure
                // (Gross = every cached unit; Net = what's shown above, i.e.
                // units from the cutoff VIN on; Cutoff = the older units that
                // boundary excluded — Gross always equals Cutoff + Net).
                $row[$s . '_net']    = $this->trim_qty($net);
                $row[$s . '_gross']  = $this->trim_qty($gross);
                $row[$s . '_cutoff'] = $this->trim_qty($gross - $net);
                $row[$s] = $this->trim_qty($net);
            }
            $row['total']        = $this->trim_qty($total);
            $row['total_net']    = $row['total'];
            $row['total_gross']  = $this->trim_qty($total_gross);
            $row['total_cutoff'] = $this->trim_qty($total_gross - $total);
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
     * Row-level breakdown behind calc(): one row per (BOM line, shop it
     * names) — the "show your work" view, listing exactly which cutoff VIN
     * and how many matching WIP units produced that row's contribution.
     *
     * @return array{ok:bool, message:string, data:array}
     */
    public function calc_detail($source)
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

        $this->db->select('material, part_number, component, material_description, uom, model, suffix, shop_code, qty')->from('bom');
        $this->db->group_start();
        foreach ($shop_codes as $shop_code) {
            foreach (array_unique(array($shop_code, strtoupper($shop_code), strtolower($shop_code))) as $variant) {
                $this->db->or_where('FIND_IN_SET(' . $this->db->escape($variant) . ', shop_code) >', 0);
            }
        }
        $this->db->group_end();
        $bom_rows = $this->db->order_by('part_number', 'asc')->order_by('id', 'asc')->get()->result_array();

        $data = array();

        foreach ($bom_rows as $row) {
            $part_number = $row['part_number'] !== '' ? $row['part_number'] : $row['material'];
            if ($part_number === null || $part_number === '') {
                continue;
            }

            $part_key = strtoupper(trim($part_number));
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

                if ($cutoff !== null) {
                    $found = $this->db->select('id')
                        ->where(array('source' => $source, 'shop' => $shop, 'vin' => $cutoff['vin']))
                        ->get('wip_data')->row_array();
                    $cutoff_vin = $cutoff['vin'];
                    $cutoff_status = $found ? 'used' : 'stale';
                    if ($found) {
                        $boundary = (int) $found['id'];
                    }
                }

                $counts = $this->get_counts($source, $shop, $boundary);
                $key = $this->match_key($row['model'], $row['suffix']);
                $unit_count = $counts[$key] ?? 0;
                if ($unit_count === 0) {
                    // Nothing to show your work for — this BOM line's Model+Suffix
                    // doesn't match any cached WIP unit, so it contributes 0 either
                    // way. With a BOM this large, keeping these would blow up the
                    // detail list (and the export) to hundreds of thousands of
                    // all-zero rows that aren't part of "how we got this number".
                    continue;
                }

                $qty = (float) $row['qty'];
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
     * Stream the row-level calc_detail() breakdown as a formatted .xlsx
     * report, same visual style as export() plus a Cutoff VIN / Formula
     * trail per row and a single Grand Total row.
     */
    public function export_detail($source, $title)
    {
        $result = $this->calc_detail($source);
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
            $vinDisplay = $row['cutoff_vin']
                ? $row['cutoff_vin'] . ($row['cutoff_status'] === 'stale' ? ' (stale — not found, totaled instead)' : '')
                : 'Total (no cutoff set)';

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
    public function export($source, $title, $hide_zero = false)
    {
        $result = $this->calc($source);
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

        $lastCol = chr(ord('A') + 4 + count($value_keys) + count($vin_keys) - 1);
        $firstShopCol = chr(ord('A') + 4); // after No, Part Number, Material Description, Shop Code
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

        foreach (array('A' => 'No', 'B' => 'Part Number', 'C' => 'Material Description', 'D' => 'Shop Code') as $col => $label) {
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
                array($i + 1, $row['part_number'], $row['material_description'], $row['shop_code']),
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
        $sheet->mergeCells("A{$r}:D{$r}");
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
     * Stream the cutoff upload template (Part Number, VIN, Shop) straight to the browser.
     */
    public function download_template($source)
    {
        $shop_codes = $this->config->item('wip_calc_shop_codes')[$source] ?? array();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cutoff VIN Template');

        $sheet->fromArray($this->required_headers, null, 'A1');
        $sheet->getStyle('A1:C1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:C1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F6FEB');

        // One example row per shop code, to show the expected format.
        $r = 2;
        foreach ($shop_codes as $shop_code) {
            $sheet->fromArray(array('', '', strtoupper($shop_code)), null, "A{$r}");
            $r++;
        }

        foreach (range('A', 'C') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'template_upload_wip_calc_cutoff_' . $source . '.xlsx';

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
            $spreadsheet = $reader->load($file_path);
        } catch (\Throwable $e) {
            return array('ok' => false, 'message' => 'Unable to read the Excel file: ' . $e->getMessage());
        }

        $rows = array();
        $skipped = 0;
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

        if (empty($rows) && $skipped === 0) {
            return array('ok' => false, 'message' => 'No data rows found in the uploaded file.');
        }

        return array('ok' => true, 'message' => 'ok', 'rows' => $rows, 'skipped' => $skipped);
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
