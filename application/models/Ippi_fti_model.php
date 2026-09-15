<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'models/Wip_calc_model.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * "WIP Calc. IPI" / "WIP Calc. FTI" — welding parts (an uploaded list per
 * type and plant) counted against that type's own WIP WOS list (WOS IPI /
 * WOS FTI) instead of Welding's, each part with its own cutoff VIN, kept
 * here rather than in wip_calc_cutoff. Standalone: WIP Calc KAP 1/2 and
 * WIP Summary don't use it. Call set_type() first (ipi|fti).
 *
 * Usage per Model+Suffix comes from the part's Welding rows (Shop Code
 * WELD3 / WELD4) in the chosen basis, each suffix once at its largest Qty.
 * Gross = every unit in the type's WOS list; Net = those from the cutoff
 * VIN on; with no cutoff VIN yet Net and Cutoff are 0, and a stale one
 * (VIN no longer in the list) counts every unit — the same rules as
 * Wip_calc_model::calc().
 *
 * Extends Wip_calc_model for its helpers (qty_once(), basis_select(),
 * column_format() …); everything IPI/FTI-specific is named apart from it.
 */
class Ippi_fti_model extends Wip_calc_model
{
    protected $list_table = 'ippi_fti';

    /** WOS units live in wip_data as shop 'wos'; each list is told apart by its shopcode. */
    protected $unit_shop = 'wos';

    /** Current list: 'ipi' or 'fti', and its shopcode in wip_data (config wos_types). */
    protected $type = 'ipi';
    protected $wos_code = 'WOS IPI';

    /** @var array<string,array> per-(line|boundary) unit-count cache for the current list */
    protected $wos_counts_cache = array();

    /** Upload/download layout, one header row; columns are matched by name on upload. */
    public $list_headers = array('No', 'Part No', 'Part Name', 'Plant', 'Cutoff VIN');

    /** Plant cell (upper-cased, spaces removed) => KAP line key (wip_data.source). */
    public $plants = array('KAP1' => 'kap1', 'KAP2' => 'kap2');

    /** Which list to work on: 'ipi' (WOS IPI) or 'fti' (WOS FTI). False if unknown. */
    public function set_type($type)
    {
        $types = (array) $this->config->item('wos_types');
        if (!isset($types[$type])) {
            return false;
        }

        $this->type = $type;
        $this->wos_code = $types[$type];
        $this->wos_counts_cache = array();

        return true;
    }

    /** "IPI" / "FTI". */
    public function type_label()
    {
        return strtoupper($this->type);
    }

    public function wos_code()
    {
        return $this->wos_code;
    }

    /** Whether the ippi_fti table (with its wos_type column) is in place. */
    public function table_ready()
    {
        return $this->db->table_exists($this->list_table) && $this->db->field_exists('wos_type', $this->list_table);
    }

    public function line_label($source)
    {
        return $source === 'kap2' ? 'KAP 2' : 'KAP 1';
    }

    /** The current list's parts, optionally for one plant. */
    public function parts($source = null)
    {
        $this->db->where('wos_type', $this->type);
        if ($source !== null) {
            $this->db->where('plant', $source);
        }

        return $this->db->order_by('plant', 'asc')->order_by('part_number', 'asc')->get($this->list_table)->result_array();
    }

    /** True only if a row of the current list was actually removed. */
    public function delete_part($id)
    {
        $this->db->where(array('id' => (int) $id, 'wos_type' => $this->type))->delete($this->list_table);

        return $this->db->affected_rows() > 0;
    }

    /** Limit the next wip_data query to one line's units of the current WOS list. */
    protected function where_wos($source)
    {
        $this->db->where(array('source' => $source, 'shop' => $this->unit_shop, 'shopcode' => $this->wos_code));
    }

    /** Number of units in a line's current WOS list. */
    public function wos_total($source)
    {
        $this->where_wos($source);

        return (int) $this->db->count_all_results('wip_data');
    }

    /**
     * Where a VIN sits in a line's current WOS list: its wip_data id and its
     * Sequence (1 = oldest, as on the WIP WOS page) — null if not cached.
     *
     * @return array{id:int, seq:int}|null
     */
    public function wos_position($source, $vin)
    {
        $this->where_wos($source);
        $found = $this->db->select('id')->where('vin', $vin)->get('wip_data')->row_array();
        if (!$found) {
            return null;
        }

        $this->where_wos($source);
        $seq = (int) $this->db->where('id <=', (int) $found['id'])->count_all_results('wip_data');

        return array('id' => (int) $found['id'], 'seq' => $seq);
    }

    /**
     * (model|suffix) -> unit count in a line's current WOS list, from
     * $boundary (a wip_data id) on, or every unit if it's null. Memoized.
     */
    protected function wos_counts($source, $boundary)
    {
        $cache_key = $source . '|' . ($boundary === null ? 'ALL' : $boundary);
        if (isset($this->wos_counts_cache[$cache_key])) {
            return $this->wos_counts_cache[$cache_key];
        }

        $this->where_wos($source);
        $this->db->select('modelcode, sfx');
        if ($boundary !== null) {
            $this->db->where('id >=', $boundary);
        }

        $freq = array();
        foreach ($this->db->get('wip_data')->result_array() as $row) {
            $key = $this->match_key($row['modelcode'], $row['sfx']);
            $freq[$key] = ($freq[$key] ?? 0) + 1;
        }

        return $this->wos_counts_cache[$cache_key] = $freq;
    }

    /**
     * The chosen basis' Welding rows (Shop Code names this line's WELD code)
     * for the given part numbers.
     */
    protected function welding_rows($source, array $part_numbers, $basis)
    {
        $weld_code = $this->config->item('wip_calc_shop_codes')[$source]['weld'] ?? null;
        if ($weld_code === null || empty($part_numbers)) {
            return array();
        }

        $this->db->select($this->basis_select($basis, array(
            'material', 'part_number', 'material_description', 'model', 'suffix', 'shop_code', 'qty',
        )), false)->from($this->basis_table($basis));
        $this->db->where_in('part_number', $part_numbers);
        $this->db->group_start();
        foreach (array_unique(array($weld_code, strtoupper($weld_code), strtolower($weld_code))) as $variant) {
            $this->db->or_where('FIND_IN_SET(' . $this->db->escape($variant) . ', shop_code) >', 0);
        }
        $this->db->group_end();

        return $this->db->order_by('model', 'asc')->order_by('suffix', 'asc')->get()->result_array();
    }

    /**
     * Cutoff state of one listed part: status none|used|stale, the WOS
     * wip_data id to count from, and its Sequence.
     */
    protected function cutoff_state($source, array $part)
    {
        if (empty($part['cutoff_vin'])) {
            return array('status' => 'none', 'boundary' => null, 'position' => null);
        }

        $pos = $this->wos_position($source, $part['cutoff_vin']);

        return $pos
            ? array('status' => 'used', 'boundary' => $pos['id'], 'position' => $pos['seq'])
            : array('status' => 'stale', 'boundary' => null, 'position' => null);
    }

    /**
     * Gross / Cutoff / Net per listed part of one KAP line.
     *
     * @return array{ok:bool, message:string, summary:array, data:array}
     */
    public function calc_parts($source, $basis = 'bom')
    {
        $this->counts_cache = array(); // qty_once() memo
        $this->wos_counts_cache = array();

        $list = $this->parts($source);
        $wos_units = $this->wos_total($source);

        $by_part = array();
        foreach ($this->welding_rows($source, array_column($list, 'part_number'), $basis) as $row) {
            $by_part[strtoupper(trim($row['part_number']))][] = $row;
        }

        $data = array();
        $sum = array('gross' => 0.0, 'cutoff' => 0.0, 'net' => 0.0);
        $with_cutoff = 0;
        $stale = 0;

        foreach ($list as $part) {
            $part_key = strtoupper(trim($part['part_number']));
            $state = $this->cutoff_state($source, $part);
            if ($state['status'] === 'used') {
                $with_cutoff++;
            } elseif ($state['status'] === 'stale') {
                $stale++;
            }

            $gross = 0.0;
            $net = 0.0;
            $description = '';
            $rows = $by_part[$part_key] ?? array();
            foreach ($rows as $row) {
                if ($description === '') {
                    $description = (string) $row['material_description'];
                }
                $key = $this->match_key($row['model'], $row['suffix']);
                $qty = $this->qty_once($this->type . '|' . $part_key . '|' . $key, (float) $row['qty']);
                if ($qty == 0.0) {
                    continue; // this suffix is already counted for this part
                }
                $gross += ($this->wos_counts($source, null)[$key] ?? 0) * $qty;
                if ($state['status'] !== 'none') {
                    $net += ($this->wos_counts($source, $state['boundary'])[$key] ?? 0) * $qty;
                }
            }
            // Gross = Cutoff + Net only with a cutoff VIN; without one, both are 0.
            $cutoff = $state['status'] === 'used' ? $gross - $net : 0.0;

            $sum['gross'] += $gross;
            $sum['cutoff'] += $cutoff;
            $sum['net'] += $net;

            $data[] = array(
                'id'              => (int) $part['id'],
                'part_no'         => $part['part_no'],
                'part_number'     => $part['part_number'],
                'part_name'       => ((string) $part['part_name'] !== '') ? $part['part_name'] : $description,
                'welding_rows'    => count($rows),
                'cutoff_vin'      => $part['cutoff_vin'],
                'cutoff_status'   => $state['status'],
                'cutoff_position' => $state['position'],
                'gross'           => $this->trim_qty($gross),
                'cutoff'          => $this->trim_qty($cutoff),
                'net'             => $this->trim_qty($net),
            );
        }

        return array(
            'ok'      => true,
            'message' => 'ok',
            'summary' => array(
                'parts'       => count($list),
                'with_cutoff' => $with_cutoff,
                'stale'       => $stale,
                'wos_units'   => $wos_units,
                'gross'       => $this->trim_qty($sum['gross']),
                'cutoff'      => $this->trim_qty($sum['cutoff']),
                'net'         => $this->trim_qty($sum['net']),
            ),
            'data'    => $data,
        );
    }

    /**
     * Suffix-level breakdown of one listed part's Net — powers the Formula
     * modal: every Welding suffix it's defined for (once, at its largest
     * Qty), how many WOS units match in total and from the cutoff VIN on.
     */
    public function part_suffixes($source, $part_number_raw, $basis = 'bom')
    {
        $part = $this->db->where(array('wos_type' => $this->type, 'plant' => $source, 'part_number' => trim((string) $part_number_raw)))
            ->get($this->list_table)->row_array();
        if (!$part) {
            return array('ok' => false, 'message' => 'Part not in the ' . $this->type_label() . ' list for ' . $this->line_label($source) . '.');
        }

        $state = $this->cutoff_state($source, $part);
        $wos_units = $this->wos_total($source);

        $by_suffix = array();
        $description = '';
        foreach ($this->welding_rows($source, array($part['part_number']), $basis) as $row) {
            if ($description === '') {
                $description = (string) $row['material_description'];
            }
            $key = $this->match_key($row['model'], $row['suffix']);
            if (!isset($by_suffix[$key]) || (float) $row['qty'] > (float) $by_suffix[$key]['qty']) {
                $by_suffix[$key] = $row;
            }
        }

        $gross_counts = $this->wos_counts($source, null);
        $net_counts = $state['status'] === 'none' ? array() : $this->wos_counts($source, $state['boundary']);

        $suffixes = array();
        $total = 0.0;
        foreach ($by_suffix as $key => $row) {
            $qty = (float) $row['qty'];
            $net_units = $net_counts[$key] ?? 0;
            $subtotal = $net_units * $qty;
            $total += $subtotal;
            $suffixes[] = array(
                'model'       => $row['model'],
                'suffix'      => $row['suffix'],
                'qty'         => $this->trim_qty($qty),
                'gross_units' => $gross_counts[$key] ?? 0,
                'net_units'   => $net_units,
                'subtotal'    => $this->trim_qty($subtotal),
            );
        }

        return array(
            'ok'          => true,
            'part_number' => $part['part_number'],
            'part_name'   => ((string) $part['part_name'] !== '') ? $part['part_name'] : $description,
            'weld_code'   => $this->config->item('wip_calc_shop_codes')[$source]['weld'] ?? '',
            'wos_code'    => $this->wos_code,
            'cutoff'      => array(
                'vin'      => $part['cutoff_vin'],
                'status'   => $state['status'],
                'position' => $state['position'],
                'total'    => $wos_units,
            ),
            'suffixes'    => $suffixes,
            'total'       => $this->trim_qty($total),
        );
    }

    /**
     * Set (or, with a blank VIN, clear) one listed part's cutoff VIN. The VIN
     * must be in that plant's current WOS list.
     *
     * @return array{ok:bool, message:string}
     */
    public function set_part_cutoff($id, $vin_raw, $user_id)
    {
        $part = $this->db->where(array('id' => (int) $id, 'wos_type' => $this->type))->get($this->list_table)->row_array();
        if (!$part) {
            return array('ok' => false, 'message' => 'Part not found.');
        }

        $vin = strtoupper(trim((string) $vin_raw));
        if ($vin !== '' && !$this->wos_position($part['plant'], $vin)) {
            return array(
                'ok'      => false,
                'message' => 'VIN not found in the ' . $this->wos_code . ' data for ' . $this->line_label($part['plant']) . '. Upload that line\'s ' . $this->wos_code . ' data first.',
            );
        }

        $this->db->where('id', $part['id'])->update($this->list_table, array(
            'cutoff_vin' => $vin === '' ? null : $vin,
            'user_id'    => $user_id,
            'updated_at' => date('Y-m-d H:i:s'),
        ));

        return array(
            'ok'      => true,
            'message' => $vin === ''
                ? "Cutoff VIN cleared for {$part['part_number']} — its Net is 0 until one is set."
                : "Cutoff VIN {$vin} set for {$part['part_number']}.",
        );
    }

    /** Header-only upload template. */
    public function list_template()
    {
        $this->stream_list(array(), 'template_upload_calc_' . $this->type . '.xlsx');
    }

    /** The current list (both plants) in the upload layout, so it can be edited and uploaded back. */
    public function list_export()
    {
        $this->stream_list($this->parts(), 'calc_' . $this->type . '_parts_' . date('Ymd_His') . '.xlsx');
    }

    protected function stream_list(array $rows, $filename)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Part ' . $this->type_label());

        $sheet->fromArray($this->list_headers, null, 'A1');
        $sheet->getStyle('A1:E1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:E1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F6FEB');
        $sheet->getStyle('A1:E1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B1:B1048576')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $r = 2;
        foreach ($rows as $i => $row) {
            $sheet->setCellValue("A{$r}", $i + 1);
            $sheet->setCellValueExplicit("B{$r}", $row['part_no'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$r}", (string) $row['part_name'], DataType::TYPE_STRING);
            $sheet->setCellValue("D{$r}", strtoupper($row['plant']));
            $sheet->setCellValueExplicit("E{$r}", (string) $row['cutoff_vin'], DataType::TYPE_STRING);
            $r++;
        }

        foreach (array('A' => 6, 'B' => 18, 'C' => 36, 'D' => 8, 'E' => 22) as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->freezePane('A2');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Parse an uploaded part list. Columns are found by header name (row 1):
     * "Part No" and "Plant" are required, "Part Name" and "Cutoff VIN"
     * optional. The same Plant + Part No twice keeps the last row.
     *
     * @return array{ok:bool, message:string, rows?:array, skipped_plant?:int, skipped_formula?:int, duplicates?:int}
     */
    public function parse_list($file_path)
    {
        try {
            $reader = IOFactory::createReaderForFile($file_path);
            $reader->setReadDataOnly(true);
            if (method_exists($reader, 'setParseHuge')) {
                $reader->setParseHuge(true); // see Part_list_model::parse_excel()
            }
            $spreadsheet = $reader->load($file_path);
        } catch (\Throwable $e) {
            return array('ok' => false, 'message' => 'Unable to read the Excel file: ' . $e->getMessage());
        }

        $sheet = $spreadsheet->getSheet(0);
        $cells = $sheet->rangeToArray('A1:' . $sheet->getHighestDataColumn() . $sheet->getHighestDataRow(), null, false, false, false);

        $part_col = null;
        $plant_col = null;
        $name_col = null;
        $vin_col = null;
        foreach ($cells[0] as $i => $label) {
            $name = strtolower(preg_replace('/\s+/', ' ', str_replace('.', '', trim((string) $label))));
            if ($part_col === null && in_array($name, array('part no', 'part number'), true)) {
                $part_col = $i;
            } elseif ($plant_col === null && $name === 'plant') {
                $plant_col = $i;
            } elseif ($name_col === null && $name === 'part name') {
                $name_col = $i;
            } elseif ($vin_col === null && in_array($name, array('cutoff vin', 'cut off vin', 'vin'), true)) {
                $vin_col = $i;
            }
        }

        if ($part_col === null || $plant_col === null) {
            return array('ok' => false, 'message' => 'Invalid template. Row 1 must have: ' . implode(', ', $this->list_headers) . '.');
        }

        $acc = array();
        $skipped_plant = 0;
        $skipped_formula = 0;
        $duplicates = 0;

        for ($r = 1; $r < count($cells); $r++) {
            $line = $cells[$r];
            $part_no = trim((string) ($line[$part_col] ?? ''));
            if ($part_no === '') {
                continue;
            }
            $vin = $vin_col === null ? '' : strtoupper(trim((string) ($line[$vin_col] ?? '')));

            // A formula pulling from another workbook comes back as its text, not a value.
            if ($part_no[0] === '=' || ($vin !== '' && $vin[0] === '=')) {
                $skipped_formula++;
                continue;
            }

            $plant_raw = strtoupper(preg_replace('/\s+/', '', (string) ($line[$plant_col] ?? '')));
            if (!isset($this->plants[$plant_raw])) {
                $skipped_plant++;
                continue;
            }
            $plant = $this->plants[$plant_raw];

            $part_number = strip_trailing_dash00($part_no);
            $key = $plant . '|' . strtoupper($part_number);
            if (isset($acc[$key])) {
                $duplicates++;
            }
            $acc[$key] = array(
                'plant'       => $plant,
                'part_no'     => $part_no,
                'part_number' => $part_number,
                'part_name'   => $name_col === null ? '' : trim((string) ($line[$name_col] ?? '')),
                'cutoff_vin'  => $vin,
            );
        }

        if (empty($acc) && $skipped_plant === 0 && $skipped_formula === 0) {
            return array('ok' => false, 'message' => 'No data rows found in the uploaded file.');
        }

        return array(
            'ok'              => true,
            'message'         => 'ok',
            'rows'            => array_values($acc),
            'skipped_plant'   => $skipped_plant,
            'skipped_formula' => $skipped_formula,
            'duplicates'      => $duplicates,
        );
    }

    /**
     * Insert new parts into the current list, refresh existing ones (type +
     * plant + part_number). A Cutoff VIN cell is applied only if that VIN is
     * in the plant's current WOS list; a blank one leaves the part's cutoff
     * VIN as it is.
     *
     * @return array{inserted:int, updated:int, vin_set:int, vin_not_found:int}
     */
    public function upsert_list(array $rows, $user_id)
    {
        $inserted = 0;
        $updated = 0;
        $vin_set = 0;
        $vin_not_found = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $data = array(
                'part_no'    => $row['part_no'],
                'user_id'    => $user_id,
                'updated_at' => $now,
            );
            if ($row['part_name'] !== '') {
                $data['part_name'] = $row['part_name'];
            }
            if ($row['cutoff_vin'] !== '') {
                if ($this->wos_position($row['plant'], $row['cutoff_vin'])) {
                    $data['cutoff_vin'] = $row['cutoff_vin'];
                    $vin_set++;
                } else {
                    $vin_not_found++;
                }
            }

            $existing = $this->db->select('id')
                ->where(array('wos_type' => $this->type, 'plant' => $row['plant'], 'part_number' => $row['part_number']))
                ->get($this->list_table)->row_array();

            if ($existing) {
                $this->db->where('id', $existing['id'])->update($this->list_table, $data);
                $updated++;
            } else {
                $this->db->insert($this->list_table, array_merge($data, array(
                    'wos_type'    => $this->type,
                    'plant'       => $row['plant'],
                    'part_number' => $row['part_number'],
                    'created_at'  => $now,
                )));
                $inserted++;
            }
        }

        return array('inserted' => $inserted, 'updated' => $updated, 'vin_set' => $vin_set, 'vin_not_found' => $vin_not_found);
    }

    /** Empty the current list only (the other type's list is untouched). */
    public function truncate_list()
    {
        $this->db->where('wos_type', $this->type)->delete($this->list_table);
    }

    /** Stream one line's calculation as a styled .xlsx report. */
    public function export_calc($source, $basis = 'bom', $hide_zero = false)
    {
        $result = $this->calc_parts($source, $basis);
        $rows = $result['data'];
        if ($hide_zero) {
            $rows = array_values(array_filter($rows, function ($row) {
                return (float) $row['net'] !== 0.0 || (float) $row['gross'] !== 0.0;
            }));
        }

        $headers = array('No', 'Part No', 'Part Name', 'Cutoff VIN', 'Unit (' . $this->wos_code . ')', 'Gross', 'Cutoff', 'Net');
        $lastCol = 'H';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('WIP Calc ' . $this->type_label());

        $sheet->setCellValue('A1', 'WIP CALC ' . $this->type_label() . ' - ' . $this->line_label($source));
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 'Unit dari WIP ' . $this->wos_code . ' (' . $result['summary']['wos_units'] . ' unit)  |  Basis: '
            . $this->basis_label($basis) . '  |  Generated: ' . date('d F Y H:i'));
        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('8B93A1');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $headerRow = 4;
        $firstDataRow = 5;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F6FEB');
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $r = $firstDataRow;
        $totals = array('gross' => 0.0, 'cutoff' => 0.0, 'net' => 0.0);
        foreach ($rows as $i => $row) {
            if ($row['cutoff_status'] === 'used') {
                $unit = 'unit ' . $row['cutoff_position'] . ' of ' . $result['summary']['wos_units'];
            } elseif ($row['cutoff_status'] === 'stale') {
                $unit = 'stale — not in ' . $this->wos_code . ', all units';
            } else {
                $unit = 'no cutoff — Net 0';
            }
            $sheet->setCellValue("A{$r}", $i + 1);
            $sheet->setCellValueExplicit("B{$r}", $row['part_no'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$r}", (string) $row['part_name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$r}", (string) $row['cutoff_vin'], DataType::TYPE_STRING);
            $sheet->setCellValue("E{$r}", $unit);
            $sheet->fromArray(array((float) $row['gross'], (float) $row['cutoff'], (float) $row['net']), null, "F{$r}", true);
            foreach ($totals as $k => $v) {
                $totals[$k] += (float) $row[$k];
            }
            $r++;
        }

        $sheet->setCellValue("A{$r}", 'GRAND TOTAL');
        $sheet->mergeCells("A{$r}:E{$r}");
        $sheet->fromArray(array(
            (float) $this->trim_qty($totals['gross']),
            (float) $this->trim_qty($totals['cutoff']),
            (float) $this->trim_qty($totals['net']),
        ), null, "F{$r}", true);
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E4E9F0');

        foreach (array('F' => 'gross', 'G' => 'cutoff', 'H' => 'net') as $col => $k) {
            $values = array_column($rows, $k);
            $values[] = $totals[$k];
            $sheet->getStyle("{$col}{$firstDataRow}:{$col}{$r}")->getNumberFormat()->setFormatCode($this->column_format($values));
        }
        $sheet->getStyle("A{$headerRow}:A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("F{$firstDataRow}:H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("F{$firstDataRow}:G{$r}")->getFont()->getColor()->setRGB('8B93A1');
        $sheet->getStyle("H{$firstDataRow}:H{$r}")->getFont()->setBold(true);

        if ($r > $firstDataRow) {
            $this->zebra_stripe($sheet, "A{$firstDataRow}:{$lastCol}" . ($r - 1));
        }
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$r}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C9CFD8');
        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_DOUBLE);

        foreach (array('A' => 6, 'B' => 18, 'C' => 36, 'D' => 22, 'E' => 26, 'F' => 10, 'G' => 10, 'H' => 10) as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->freezePane("A{$firstDataRow}");

        $filename = 'wip_calc_' . $this->type . '_' . $source . '_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }
}
