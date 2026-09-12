<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class Bom_model extends CI_Model
{
    protected $table = 'bom';

    /** Required header columns of the upload/template Excel file, in order. */
    public $required_headers = array(
        'Material', 'Katashiki', 'Model', 'Suffix', 'Component', 'Material Description', 'Qty', 'Uom', 'Shop Code',
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

        // Hard filter from the clickable Model cards on the BOM page (not the
        // free-text search box) — combined with it via AND, not replacing it.
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
     * the Master BOM page. Rows with no Model set are left out of the
     * cards (they'd have nothing to click on) but are still counted in
     * the overall "Total BOM Records" stat.
     */
    public function model_summary()
    {
        return $this->db->select('model, COUNT(*) AS total')
            ->from($this->table)
            ->where('model !=', null) // CI3 idiom: value NULL + "!=" operator on the key -> "model IS NOT NULL"
            ->where('model !=', '')
            ->group_by('model')
            ->order_by('model', 'asc')
            ->get()->result_array();
    }

    /**
     * Stream the upload template (single sheet, 9 required columns) straight to the browser.
     */
    public function download_template()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('BOM');

        $sheet->fromArray($this->required_headers, null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:I1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F6FEB');

        // Material holds long numeric codes (e.g. 11103102000000); format the whole
        // column as text so Excel never collapses it into scientific notation.
        // (Must be the full "A1:A1048576" column range so PhpSpreadsheet takes the
        // column-style fast path instead of materializing a million cell objects.)
        $sheet->getStyle('A1:A1048576')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        // A couple of example rows to guide the user (matching the real BOM format).
        // Shop Code accepts more than one shop, comma-separated (e.g. "WELD3,ASSY3,TOSO3").
        $examples = array(
            array('11103102000000', 'A251LA-GMXF', 'D26A', 'MN', '09101-BZ030-00', 'TOOL SET, STD L/JACK', 1, 'PC', 'WELD3,ASSY3,TOSO3'),
            array('11103102000000', 'A251LA-GMXF', 'D74A', 'MN', '11293-BZ840-00', 'LABEL, TUNE-UP SPECIFICATION INFORMATION', 1, 'PC', 'WELD3,ASSY3'),
        );

        $row = 2;
        foreach ($examples as $example) {
            // Written explicitly as a string so it stays full-width text, not scientific notation.
            $sheet->setCellValueExplicit("A{$row}", $example[0], DataType::TYPE_STRING);
            $sheet->fromArray(array_slice($example, 1), null, "B{$row}");
            $row++;
        }

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="template_upload_bom.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Stream every BOM record (optionally narrowed to one Model) as an
     * .xlsx download — the "Download" button, as opposed to the blank
     * upload template from download_template().
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
        $sheet->setTitle('BOM');

        $headers = array('No', 'Material', 'Katashiki', 'Model', 'Suffix', 'Component', 'Part Number', 'Material Description', 'Qty', 'Uom', 'Shop Code');
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:K1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:K1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F6FEB');

        // Material/Component/Part Number hold long numeric-looking codes; keep them as text.
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

        $filename = 'bom_data_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Parse an uploaded Excel file and validate its header row.
     *
     * @return array{ok:bool, message:string, rows?:array}
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
                    // Shop Code may list more than one shop, comma-separated
                    // (e.g. "WELD3,ASSY3,TOSO3"); normalize away stray spaces.
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
        $this->db->insert('bom_upload_log', $data);
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

    /**
     * Collapse a Shop Code cell like "WELD3, ASSY3 ,TOSO3" down to
     * "WELD3,ASSY3,TOSO3" — trims each comma-separated item and drops empty
     * ones, so downstream matching (e.g. WIP Calc's FIND_IN_SET lookups)
     * can rely on a plain, space-free comma list.
     */
    protected function normalize_shop_code($raw)
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $raw)), function ($p) {
            return $p !== '';
        });

        return implode(',', $parts);
    }
}
