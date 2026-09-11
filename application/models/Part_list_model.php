<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Part List: a second BOM-shaped table (same 9-column upload template as
 * Master BOM) whose whole purpose is to be checked against `bom` for
 * mismatches — see compare()/compare_summary() below.
 */
class Part_list_model extends CI_Model
{
    protected $table = 'part_list';

    /** Required header columns of the upload/template Excel file, in order. Same as Master BOM. */
    public $required_headers = array(
        'Material', 'Katashiki', 'Model', 'Suffix', 'Component', 'Material Description', 'Qty', 'Uom', 'Shop Code',
    );

    /** Fields compared row-for-row once two sides share the same Model+Suffix+Part Number key. */
    protected $compare_fields = array(
        'material'             => 'Material',
        'katashiki'            => 'Katashiki',
        'qty'                  => 'Qty',
        'uom'                  => 'Uom',
        'shop_code'            => 'Shop Code',
        'material_description' => 'Material Description',
    );

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * DataTables server-side listing.
     */
    public function datatable($request)
    {
        $columns = array('id', 'material', 'katashiki', 'model', 'suffix', 'component', 'part_number', 'material_description', 'qty', 'uom', 'shop_code');

        $this->db->from($this->table);

        $model_filter = trim((string) ($request['model_filter'] ?? ''));
        if ($model_filter !== '') {
            $this->db->where('model', $model_filter);
        }

        $search = $request['search']['value'] ?? '';
        if ($search !== '') {
            $this->db->group_start();
            $this->db->like('material', $search);
            $this->db->or_like('katashiki', $search);
            $this->db->or_like('model', $search);
            $this->db->or_like('suffix', $search);
            $this->db->or_like('component', $search);
            $this->db->or_like('part_number', $search);
            $this->db->or_like('material_description', $search);
            $this->db->or_like('shop_code', $search);
            $this->db->group_end();
        }
        $total_filtered = $this->db->count_all_results('', false);

        if (!empty($request['order'])) {
            $order = $request['order'][0];
            $col = $columns[$order['column']] ?? 'id';
            $this->db->order_by($col, $order['dir']);
        } else {
            $this->db->order_by('id', 'desc');
        }

        if ((int) ($request['length'] ?? -1) !== -1) {
            $this->db->limit((int) $request['length'], (int) $request['start']);
        }

        $data = $this->db->get()->result_array();

        return array(
            'data'     => $data,
            'filtered' => $total_filtered,
            'total'    => $this->db->count_all($this->table),
        );
    }

    public function get($id)
    {
        return $this->db->get_where($this->table, array('id' => $id))->row_array();
    }

    public function delete($id)
    {
        return $this->db->where('id', $id)->delete($this->table);
    }

    public function truncate()
    {
        $this->db->truncate($this->table);
    }

    public function count_all()
    {
        return $this->db->count_all($this->table);
    }

    /**
     * Part count per Model, for the clickable "filter by model" cards on
     * the Part List page.
     */
    public function model_summary()
    {
        return $this->db->select('model, COUNT(*) AS total')
            ->from($this->table)
            ->where('model !=', null)
            ->where('model !=', '')
            ->group_by('model')
            ->order_by('model', 'asc')
            ->get()->result_array();
    }

    /**
     * Stream the upload template. Identical layout to Master BOM's template
     * (same required columns) so the same source file can be reused.
     */
    public function download_template()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Part List');

        $sheet->fromArray($this->required_headers, null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:I1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F6FEB');

        $sheet->getStyle('A1:A1048576')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $examples = array(
            array('11103102000000', 'A251LA-GMXF', 'D26A', 'MN', '09101-BZ030-00', 'TOOL SET, STD L/JACK', 1, 'PC', 'WELD3,ASSY3,TOSO3'),
            array('11103102000000', 'A251LA-GMXF', 'D74A', 'MN', '11293-BZ840-00', 'LABEL, TUNE-UP SPECIFICATION INFORMATION', 1, 'PC', 'WELD3,ASSY3'),
        );

        $row = 2;
        foreach ($examples as $example) {
            $sheet->setCellValueExplicit("A{$row}", $example[0], DataType::TYPE_STRING);
            $sheet->fromArray(array_slice($example, 1), null, "B{$row}");
            $row++;
        }

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="template_upload_part_list.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Stream every Part List record (optionally narrowed to one Model) as
     * an .xlsx download.
     */
    public function export_data($model_filter = '')
    {
        $this->db->select('material, katashiki, model, suffix, component, part_number, material_description, qty, uom, shop_code')
            ->from($this->table)
            ->order_by('model', 'asc')
            ->order_by('suffix', 'asc')
            ->order_by('component', 'asc');

        $model_filter = trim((string) $model_filter);
        if ($model_filter !== '') {
            $this->db->where('model', $model_filter);
        }

        $rows = $this->db->get()->result_array();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Part List');

        $headers = array('No', 'Material', 'Katashiki', 'Model', 'Suffix', 'Component', 'Part Number', 'Material Description', 'Qty', 'Uom', 'Shop Code');
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:K1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:K1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F6FEB');

        foreach (array('A', 'F', 'G') as $col) {
            $sheet->getStyle("{$col}1:{$col}1048576")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $r = 2;
        foreach ($rows as $i => $row) {
            $sheet->setCellValueExplicit("A{$r}", $i + 1, DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("B{$r}", $row['material'], DataType::TYPE_STRING);
            $sheet->fromArray(array($row['katashiki'], $row['model'], $row['suffix']), null, "C{$r}");
            $sheet->setCellValueExplicit("F{$r}", $row['component'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("G{$r}", $row['part_number'], DataType::TYPE_STRING);
            $sheet->fromArray(array(
                $row['material_description'],
                rtrim(rtrim(number_format((float) $row['qty'], 3, '.', ''), '0'), '.'),
                $row['uom'],
                $row['shop_code'],
            ), null, "H{$r}");
            $r++;
        }

        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'part_list_data_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Parse an uploaded Excel file and validate its header row. Identical
     * rules to Master BOM's upload.
     *
     * @return array{ok:bool, message:string, rows?:array}
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
        $header_checked = false;

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            foreach ($worksheet->getRowIterator() as $row) {
                $rowIndex = $row->getRowIndex();

                $cellIterator = $row->getCellIterator('A', 'I');
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

                $rows[] = array(
                    'material'             => trim((string) ($cells[0] ?? '')),
                    'katashiki'            => trim((string) ($cells[1] ?? '')),
                    'model'                => trim((string) ($cells[2] ?? '')),
                    'suffix'               => trim((string) ($cells[3] ?? '')),
                    'component'            => trim((string) ($cells[4] ?? '')),
                    'material_description' => trim((string) ($cells[5] ?? '')),
                    'qty'                  => is_numeric($cells[6] ?? null) ? (float) $cells[6] : 0,
                    'uom'                  => trim((string) ($cells[7] ?? '')),
                    'shop_code'            => $this->normalize_shop_code($cells[8] ?? ''),
                );
            }

            break; // only the first sheet is used for uploads
        }

        if (!$header_checked) {
            return array('ok' => false, 'message' => 'The uploaded file is empty.');
        }

        return array('ok' => true, 'message' => 'ok', 'rows' => $rows);
    }

    /**
     * Insert parsed rows into the database in chunks, computing part_number for each.
     *
     * @return array{inserted:int, skipped:int}
     */
    public function insert_rows(array $rows)
    {
        $inserted = 0;
        $skipped = 0;
        $now = date('Y-m-d H:i:s');
        $batch = array();

        foreach ($rows as $row) {
            if (($row['material'] === '' && $row['component'] === '') || $row['shop_code'] === '') {
                $skipped++;
                continue;
            }

            $batch[] = array(
                'material'             => $row['material'],
                'katashiki'            => $row['katashiki'],
                'model'                => $row['model'],
                'suffix'               => $row['suffix'],
                'component'            => $row['component'],
                'part_number'          => strip_trailing_dash00($row['component']),
                'material_description' => $row['material_description'],
                'qty'                  => $row['qty'],
                'uom'                  => $row['uom'],
                'shop_code'            => $row['shop_code'],
                'created_at'           => $now,
                'updated_at'           => $now,
            );

            if (count($batch) >= 500) {
                $this->db->insert_batch($this->table, $batch);
                $inserted += count($batch);
                $batch = array();
            }
        }

        if (!empty($batch)) {
            $this->db->insert_batch($this->table, $batch);
            $inserted += count($batch);
        }

        return array('inserted' => $inserted, 'skipped' => $skipped);
    }

    public function log_upload($data)
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $this->db->insert('part_list_upload_log', $data);
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

    protected function normalize_shop_code($raw)
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $raw)), function ($p) {
            return $p !== '';
        });

        return implode(',', $parts);
    }

    // ------------------------------------------------------------------
    // Master BOM <-> Part List comparison
    // ------------------------------------------------------------------

    /**
     * Build the full list of differences between `bom` and `part_list`,
     * keyed by Model + Suffix + Part Number. Every row is one concrete
     * difference: a part only present on one side, or one field that
     * disagrees between the two sides for a part present on both.
     *
     * @return array list of rows: status, model, suffix, component, part_number, field, bom_value, part_list_value
     */
    public function build_diff()
    {
        $cols = 'model, katashiki, material, suffix, component, part_number, material_description, qty, uom, shop_code';

        $bom_rows = $this->db->select($cols)->from('bom')->get()->result_array();
        $part_list_rows = $this->db->select($cols)->from($this->table)->get()->result_array();

        $bom_by_key = array();
        foreach ($bom_rows as $row) {
            $bom_by_key[$this->diff_key($row)] = $row;
        }

        $part_list_by_key = array();
        foreach ($part_list_rows as $row) {
            $part_list_by_key[$this->diff_key($row)] = $row;
        }

        $diffs = array();

        foreach ($bom_by_key as $key => $bom_row) {
            if (!isset($part_list_by_key[$key])) {
                $diffs[] = $this->diff_row('only_bom', $bom_row, null, '(New Part)', $this->summarize_row($bom_row), '-');
                continue;
            }

            $part_list_row = $part_list_by_key[$key];
            foreach ($this->compare_fields as $field => $label) {
                $bom_value = $this->diff_field_value($field, $bom_row[$field]);
                $part_list_value = $this->diff_field_value($field, $part_list_row[$field]);
                if ($bom_value !== $part_list_value) {
                    $diffs[] = $this->diff_row('mismatch', $bom_row, $part_list_row, $label, $bom_value, $part_list_value);
                }
            }
        }

        foreach ($part_list_by_key as $key => $part_list_row) {
            if (!isset($bom_by_key[$key])) {
                $diffs[] = $this->diff_row('only_part_list', null, $part_list_row, '(New Part)', '-', $this->summarize_row($part_list_row));
            }
        }

        usort($diffs, function ($a, $b) {
            foreach (array('model', 'suffix', 'part_number', 'field') as $key) {
                $cmp = strcmp((string) $a[$key], (string) $b[$key]);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return 0;
        });

        return $diffs;
    }

    /**
     * Summary counts for the stat cards: how many parts only exist in
     * Master BOM, only in Part List, have mismatched fields, or match
     * exactly on both sides.
     */
    public function compare_summary()
    {
        $diffs = $this->build_diff();

        $summary = array('only_bom' => 0, 'only_part_list' => 0, 'mismatch_parts' => 0, 'matching' => 0);
        $mismatched_keys = array();

        foreach ($diffs as $d) {
            if ($d['status'] === 'only_bom') {
                $summary['only_bom']++;
            } elseif ($d['status'] === 'only_part_list') {
                $summary['only_part_list']++;
            } else {
                $mismatched_keys[$d['model'] . '|' . $d['suffix'] . '|' . $d['part_number']] = true;
            }
        }
        $summary['mismatch_parts'] = count($mismatched_keys);

        $bom_total = $this->db->count_all('bom');
        $part_list_total = $this->count_all();
        $summary['bom_total'] = $bom_total;
        $summary['part_list_total'] = $part_list_total;
        $summary['matching'] = max(0, $part_list_total - $summary['only_part_list'] - $summary['mismatch_parts']);

        return $summary;
    }

    /**
     * Paginate/search/filter the diff list, DataTables-server-side style.
     */
    public function compare_datatable($request)
    {
        $diffs = $this->build_diff();

        $status_filter = trim((string) ($request['status_filter'] ?? ''));
        if ($status_filter !== '') {
            $diffs = array_values(array_filter($diffs, function ($d) use ($status_filter) {
                return $d['status'] === $status_filter;
            }));
        }

        $model_filter = trim((string) ($request['model_filter'] ?? ''));
        if ($model_filter !== '') {
            $diffs = array_values(array_filter($diffs, function ($d) use ($model_filter) {
                return $d['model'] === $model_filter;
            }));
        }

        $total = count($diffs);

        $search = trim((string) ($request['search']['value'] ?? ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $diffs = array_values(array_filter($diffs, function ($d) use ($needle) {
                $haystack = mb_strtolower(implode(' ', array(
                    $d['model'], $d['suffix'], $d['component'], $d['part_number'],
                    $d['field'], $d['bom_value'], $d['part_list_value'],
                )));

                return mb_strpos($haystack, $needle) !== false;
            }));
        }

        $filtered = count($diffs);

        $start = (int) ($request['start'] ?? 0);
        $length = (int) ($request['length'] ?? -1);
        $page = $length === -1 ? $diffs : array_slice($diffs, $start, $length);

        return array('data' => $page, 'filtered' => $filtered, 'total' => $total);
    }

    protected function diff_key(array $row)
    {
        return $row['model'] . '|' . $row['suffix'] . '|' . $row['part_number'];
    }

    protected function diff_field_value($field, $value)
    {
        if ($field === 'qty') {
            return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }

    protected function summarize_row(array $row)
    {
        return sprintf(
            'Material: %s | Katashiki: %s | Qty: %s | Uom: %s | Shop Code: %s',
            $row['material'],
            $row['katashiki'],
            $this->diff_field_value('qty', $row['qty']),
            $row['uom'],
            $row['shop_code']
        );
    }

    protected function diff_row($status, $bom_row, $part_list_row, $field, $bom_value, $part_list_value)
    {
        $source = $bom_row ?: $part_list_row;

        return array(
            'status'           => $status,
            'model'            => $source['model'],
            'suffix'           => $source['suffix'],
            'component'        => $source['component'],
            'part_number'      => $source['part_number'],
            'field'            => $field,
            'bom_value'        => $bom_value,
            'part_list_value'  => $part_list_value,
        );
    }
}
