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
    /** Columns the Excel-style header filters may act on (see Column_filter). */
    public $filter_columns = array('material', 'katashiki', 'model', 'suffix', 'component', 'part_number', 'material_description', 'qty', 'uom', 'shop_code');

    /**
     * Shop codes every Master BOM *read* is limited to — set for a scoped
     * User account (see Bom::__construct), empty = no limit. A row matches
     * when any code in its comma-separated shop_code is one of these, e.g.
     * scope [TOSO3] keeps "TOSO3" and "WELD3,TOSO3". Same rule as
     * Part_list_model's scope. Writes are admin-only and never scoped.
     *
     * @var string[]
     */
    protected $shop_scope = array();

    public function set_shop_scope(array $shop_codes)
    {
        $this->shop_scope = array_values(array_filter(array_map(function ($c) {
            return strtoupper(trim((string) $c));
        }, $shop_codes), 'strlen'));
    }

    /** Add the shop scope to the query builder chain being built. */
    protected function apply_shop_scope()
    {
        if (empty($this->shop_scope)) {
            return;
        }
        $parts = array();
        foreach ($this->shop_scope as $code) {
            $parts[] = 'FIND_IN_SET(' . $this->db->escape($code) . ', shop_code) > 0';
        }
        $this->db->where('(' . implode(' OR ', $parts) . ')', null, false);
    }

    /**
     * Start a query on bom with every filter the table view applies: Model
     * card, search box, and the header filters — except $except_column's
     * own, which the dropdown list of that column must not apply to itself.
     * Shared by datatable() and distinct_values() so the list always
     * matches what the table would show.
     */
    protected function apply_list_filters($request, $except_column = null)
    {
        $this->db->from($this->table);
        $this->apply_shop_scope();

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

        $this->load->library('column_filter');
        $this->column_filter->apply(
            $this->db,
            $this->column_filter->parse($request['col_filters'] ?? '', $this->filter_columns),
            $except_column
        );
    }

    /**
     * Values for one column's header filter dropdown.
     *
     * @return array{values:string[], truncated:bool}|null null = column not filterable
     */
    public function distinct_values($request, $column, $search = '')
    {
        if (!in_array($column, $this->filter_columns, true)) {
            return null;
        }
        $this->apply_list_filters($request, $column);

        return $this->column_filter->distinct($this->db, $column, $search);
    }

    public function datatable($request)
    {
        $columns = array('id', 'material', 'katashiki', 'model', 'suffix', 'component', 'part_number', 'material_description', 'qty', 'uom', 'shop_code');

        $this->apply_list_filters($request);
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
            'total'    => $this->count_all(),
        );
    }

    public function get($id)
    {
        return $this->db->get_where($this->table, array('id' => $id))->row_array();
    }

    /**
     * Tambah satu baris BOM dari tombol "Tambah Manual" / "Copy" di tabel.
     * Aturan isinya sama dengan update(): Part Number kosong diambil dari
     * Component, Shop Code dinormalkan.
     *
     * @return array{ok:bool, message:string, id?:int}
     */
    public function create(array $data)
    {
        $clean = $this->clean_row($data);
        if (isset($clean['error'])) {
            return array('ok' => false, 'message' => $clean['error']);
        }

        $this->db->insert($this->table, array_merge($clean['row'], array('created_at' => date('Y-m-d H:i:s'))));

        return array('ok' => true, 'message' => 'Baris BOM ditambahkan.', 'id' => (int) $this->db->insert_id());
    }

    /**
     * Validasi + normalisasi satu baris BOM dari form (dipakai create() dan
     * update()): Part Number kosong diambil dari Component (akhiran "-00"
     * dibuang) dan Shop Code jadi daftar koma tanpa spasi — sama seperti
     * waktu upload, supaya FIND_IN_SET di WIP Calc tetap cocok.
     *
     * @return array{row?:array, error?:string}
     */
    protected function clean_row(array $data)
    {
        $component = trim((string) ($data['component'] ?? ''));
        $part_number = trim((string) ($data['part_number'] ?? ''));
        if ($part_number === '') {
            $part_number = $component;
        }
        if ($part_number === '') {
            return array('error' => 'Component atau Part Number harus diisi.');
        }

        $qty = trim((string) ($data['qty'] ?? ''));
        if ($qty !== '' && !is_numeric($qty)) {
            return array('error' => 'Qty harus berupa angka.');
        }

        return array('row' => array(
            'material'             => trim((string) ($data['material'] ?? '')),
            'katashiki'            => trim((string) ($data['katashiki'] ?? '')),
            'model'                => trim((string) ($data['model'] ?? '')),
            'suffix'               => trim((string) ($data['suffix'] ?? '')),
            'component'            => $component,
            'part_number'          => strip_trailing_dash00($part_number),
            'material_description' => trim((string) ($data['material_description'] ?? '')),
            'qty'                  => $qty === '' ? 0 : (float) $qty,
            'uom'                  => trim((string) ($data['uom'] ?? '')),
            // Huruf besar, sesuai tampilan kolomnya di form dan data hasil upload.
            'shop_code'            => strtoupper($this->normalize_shop_code($data['shop_code'] ?? '')),
            'updated_at'           => date('Y-m-d H:i:s'),
        ));
    }

    /**
     * Edit satu baris BOM dari tombol Edit di tabel. Part Number yang
     * dikosongkan diambil dari Component (akhiran "-00" dibuang) dan Shop Code
     * dinormalkan jadi daftar koma tanpa spasi — sama persis seperti waktu
     * upload, supaya pencarian FIND_IN_SET di WIP Calc tetap cocok.
     *
     * @return array{ok:bool, message:string}
     */
    public function update($id, array $data)
    {
        $row = $this->get($id);
        if (!$row) {
            return array('ok' => false, 'message' => 'Baris BOM tidak ditemukan.');
        }

        $clean = $this->clean_row($data);
        if (isset($clean['error'])) {
            return array('ok' => false, 'message' => $clean['error']);
        }

        $this->db->where('id', (int) $id)->update($this->table, $clean['row']);

        return array('ok' => true, 'message' => 'Baris BOM diperbarui.');
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
        $this->db->from($this->table);
        $this->apply_shop_scope();

        return $this->db->count_all_results();
    }

    /**
     * Part count per Model, for the clickable "filter by model" cards on
     * the Master BOM page. Rows with no Model set are left out of the
     * cards (they'd have nothing to click on) but are still counted in
     * the overall "Total BOM Records" stat.
     */
    public function model_summary()
    {
        $this->db->select('model, COUNT(*) AS total')
            ->from($this->table)
            ->where('model !=', null) // CI3 idiom: value NULL + "!=" operator on the key -> "model IS NOT NULL"
            ->where('model !=', '');
        $this->apply_shop_scope();

        return $this->db->group_by('model')
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
        $this->apply_shop_scope();

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
