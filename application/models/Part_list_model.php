<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Part List: a second BOM-shaped table whose whole purpose is to be
 * checked against `bom` for mismatches — see compare()/compare_summary()
 * below.
 *
 * Unlike Master BOM's own upload (one row per Model+Suffix+Component
 * line), Part List's real-world source is a wide "pivot" spreadsheet: one
 * row per part (Part No/Part Name/Shop), one column per Suffix, and the
 * Qty in the cell where that part is used at that suffix — matching the
 * factory's actual "PARTLIST" master file. Model isn't a column here (a
 * part can list several models as free text, e.g. "D55L&D52B&D74A"); it's
 * resolved per Suffix from Master BOM's own Suffix->Model data instead,
 * since that's the only way a Part List row becomes comparable to a BOM
 * row under the same Model+Suffix+Part Number key (see parse_excel()).
 */
class Part_list_model extends CI_Model
{
    protected $table = 'part_list';

    /** Fixed leading columns of the upload/template Excel file; every column after these is a Suffix. */
    public $required_headers = array('Part No', 'Part Name', 'Shop', 'Model');

    /**
     * Fields compared row-for-row once two sides share the same
     * Model+Suffix+Part Number key. Material/Katashiki/Uom are left out —
     * the pivot upload format never carries them, so comparing them would
     * just flag every single row as "Different" for no useful reason.
     */
    protected $compare_fields = array(
        'qty'                  => 'Qty',
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
        $columns = array('id', 'model', 'suffix', 'component', 'part_number', 'material_description', 'qty', 'uom', 'shop_code');

        $this->db->from($this->table);

        $model_filter = trim((string) ($request['model_filter'] ?? ''));
        if ($model_filter !== '') {
            $this->db->where('model', $model_filter);
        }

        $search = $request['search']['value'] ?? '';
        if ($search !== '') {
            $this->db->group_start();
            $this->db->like('model', $search);
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
     * Stream the upload template: Part No / Part Name / Shop / Model, then
     * one column per Suffix currently defined in Master BOM (so the
     * template always matches whatever suffixes BOM actually has). Falls
     * back to a couple of placeholder suffix columns when BOM is empty.
     */
    public function download_template()
    {
        $suffixes = $this->bom_suffix_columns();
        if (empty($suffixes)) {
            $suffixes = array('MN', 'XX'); // placeholders — nothing in Master BOM yet to base real ones on
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Part List');

        $headers = array_merge($this->required_headers, $suffixes);
        $sheet->fromArray($headers, null, 'A1');
        $lastCol = $this->column_letter(count($headers) - 1);
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F6FEB');

        // Part No holds long codes (e.g. 9004A-11336-00); keep it as text
        // so Excel never collapses a numeric-looking one into scientific notation.
        $sheet->getStyle('A1:A1048576')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $example = array('9004A-11336-00', 'BOLT, WELD', 'WELD3', 'D26A');
        $sheet->setCellValueExplicit('A2', $example[0], DataType::TYPE_STRING);
        $sheet->fromArray(array_slice($example, 1), null, 'B2');
        // One example qty in the first suffix column, so the shape is obvious at a glance.
        $sheet->setCellValue($this->column_letter(count($this->required_headers)) . '2', 1);

        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);
        $sheet->getColumnDimension('C')->setAutoSize(true);
        $sheet->getColumnDimension('D')->setAutoSize(true);
        // Autosizing every suffix column individually is slow once there are
        // hundreds of them (see parse_excel()'s note on the real file's
        // size) — a fixed narrow width reads fine for short 2-3 char codes.
        for ($i = count($this->required_headers); $i < count($headers); $i++) {
            $sheet->getColumnDimension($this->column_letter($i))->setWidth(6);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="template_upload_part_list.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Every distinct Suffix currently in Master BOM, in Model then Suffix
     * order (deduplicated — a Suffix that somehow appears under more than
     * one Model in BOM is only listed once, at its first occurrence).
     */
    protected function bom_suffix_columns()
    {
        $rows = $this->db->distinct()->select('model, suffix')
            ->from('bom')
            ->where('suffix !=', null)
            ->where('suffix !=', '')
            ->order_by('model', 'asc')
            ->order_by('suffix', 'asc')
            ->get()->result_array();

        $seen = array();
        $suffixes = array();
        foreach ($rows as $row) {
            $suffix = trim($row['suffix']);
            if ($suffix === '' || isset($seen[$suffix])) {
                continue;
            }
            $seen[$suffix] = true;
            $suffixes[] = $suffix;
        }

        return $suffixes;
    }

    /**
     * Parse a php.ini shorthand size value (e.g. "512M", "1G", "-1" for
     * unlimited) into a byte count.
     */
    protected function to_bytes($iniValue)
    {
        $iniValue = trim((string) $iniValue);
        if ($iniValue === '' || $iniValue === '-1') {
            return -1;
        }

        $unit = strtolower(substr($iniValue, -1));
        $num = (int) $iniValue;
        switch ($unit) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
            default:  return $num;
        }
    }

    /**
     * 0-based column index -> spreadsheet column letter(s) (0 -> A, 25 ->
     * Z, 26 -> AA, ...) — needed once the Suffix columns run past Z, which
     * they will (the real file has 140+ of them).
     */
    protected function column_letter($index)
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - $mod, 26);
        }

        return $letter;
    }

    /**
     * Stream every Part List record (optionally narrowed to one Model) as
     * an .xlsx download.
     */
    public function export_data($model_filter = '')
    {
        $this->db->select('model, suffix, component, part_number, material_description, qty, uom, shop_code')
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

        $headers = array('No', 'Model', 'Suffix', 'Component', 'Part Number', 'Material Description', 'Qty', 'Uom', 'Shop Code');
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:I1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F6FEB');

        // Component (D) and Part Number (E) hold codes like "9004A-11336-00".
        foreach (array('D', 'E') as $col) {
            $sheet->getStyle("{$col}1:{$col}1048576")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $r = 2;
        foreach ($rows as $i => $row) {
            $sheet->setCellValueExplicit("A{$r}", $i + 1, DataType::TYPE_NUMERIC);
            $sheet->fromArray(array($row['model'], $row['suffix']), null, "B{$r}");
            $sheet->setCellValueExplicit("D{$r}", $row['component'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$r}", $row['part_number'], DataType::TYPE_STRING);
            $sheet->fromArray(array(
                $row['material_description'],
                rtrim(rtrim(number_format((float) $row['qty'], 3, '.', ''), '0'), '.'),
                $row['uom'],
                $row['shop_code'],
            ), null, "F{$r}");
            $r++;
        }

        foreach (range('A', 'I') as $col) {
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
     * Parse an uploaded "pivot" Part List file: Part No / Part Name / Shop
     * / Model, then one column per Suffix (header = the Suffix code, cell
     * = that part's Qty at that suffix, blank = not used there).
     *
     * Each Suffix's Model is resolved from Master BOM's own Suffix->Model
     * data (see resolve_model_for_suffix()) — not from this file's own
     * free-text Model column — since that's the only way the row becomes
     * comparable to BOM under the same Model+Suffix+Part Number key. A
     * suffix BOM doesn't know about yet is still recorded, just with a
     * blank Model (it'll surface as "Only in Part List" on the Compare
     * page, which is the point — it flags something to go check).
     *
     * A cell holding a non-numeric marker (the real file uses "X") is
     * skipped, not guessed at — counted in $skipped_non_numeric instead so
     * the upload result tells you to go check those cells by hand.
     *
     * The same (Part No, Suffix, resolved Model) can legitimately appear
     * more than once — the real master file carries outright duplicate
     * rows for the same part (copy/paste artifacts) that don't always
     * agree with each other. The larger Qty wins ($duplicates_collapsed
     * counts how often this happened), on the assumption that a smaller
     * duplicate is just an incomplete copy, not a genuinely smaller count.
     *
     * @return array{ok:bool, message:string, rows?:array, skipped_non_numeric?:int,
     *     duplicates_collapsed?:int, unresolved_suffixes?:string[], unresolved_cells?:int}
     */
    public function parse_excel($file_path)
    {
        // The real master file (Part No x Suffix, ~5,800 rows x ~140
        // columns) measured at ~470MB peak loading + parsing it — already
        // over 90% of this app's default 512M memory_limit, and it only
        // grows over time as more parts/suffixes are added. Raised here
        // rather than globally, since only this one action needs it.
        $current = $this->to_bytes(ini_get('memory_limit'));
        if ($current !== -1 && $current < 1024 * 1024 * 1024) {
            @ini_set('memory_limit', '1024M');
        }

        try {
            $reader = IOFactory::createReaderForFile($file_path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file_path);
        } catch (\Throwable $e) {
            return array('ok' => false, 'message' => 'Unable to read the Excel file: ' . $e->getMessage());
        }

        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();

        // ---- Header row: fixed 4 columns, then one Suffix per column
        // after that (blank-header columns are just skipped, not errors —
        // the real file has stray blank trailing columns). ----
        $headerCells = array();
        $cellIterator = $sheet->getRowIterator(1, 1)->current()->getCellIterator('A', $highestCol);
        $cellIterator->setIterateOnlyExistingCells(false);
        foreach ($cellIterator as $cell) {
            $headerCells[] = $cell->getValue();
        }

        if (!$this->header_matches($headerCells)) {
            return array(
                'ok' => false,
                'message' => 'Invalid template. Expected the first columns to be: ' . implode(', ', $this->required_headers) . ', followed by one column per Suffix.',
            );
        }

        $prefixCount = count($this->required_headers);
        $suffixColumns = array(); // column index (0-based) => suffix code
        for ($i = $prefixCount; $i < count($headerCells); $i++) {
            $suffix = trim((string) ($headerCells[$i] ?? ''));
            if ($suffix !== '') {
                $suffixColumns[$i] = $suffix;
            }
        }

        if (empty($suffixColumns)) {
            return array('ok' => false, 'message' => 'No Suffix columns found after Part No/Part Name/Shop/Model.');
        }

        $suffix_model_map = $this->bom_suffix_model_map();

        // Keyed by "COMPONENT|SUFFIX|MODEL" (upper-cased) so a genuine
        // duplicate collapses onto the same accumulator entry.
        $acc = array();
        $skipped_non_numeric = 0;
        $duplicates_collapsed = 0;
        $unresolved_suffix_set = array();
        $unresolved_cells = 0;

        foreach ($sheet->getRowIterator(2, $highestRow) as $row) {
            $partNo = trim((string) $sheet->getCell('A' . $row->getRowIndex())->getValue());
            if ($partNo === '') {
                continue; // no Part No -> not a real data row (e.g. leftover formula debris rows)
            }

            $partName = trim((string) $sheet->getCell('B' . $row->getRowIndex())->getValue());
            $shopCode = $this->normalize_shop_code($sheet->getCell('C' . $row->getRowIndex())->getValue());

            foreach ($suffixColumns as $colIndex => $suffix) {
                $colLetter = $this->column_letter($colIndex);
                $value = $sheet->getCell($colLetter . $row->getRowIndex())->getValue();
                if ($value === null || $value === '') {
                    continue;
                }

                if (!is_numeric($value)) {
                    $skipped_non_numeric++;
                    continue;
                }

                $qty = (float) $value;
                $model = $suffix_model_map[strtoupper($suffix)] ?? '';
                if ($model === '') {
                    $unresolved_suffix_set[$suffix] = true;
                    $unresolved_cells++;
                }

                $key = strtoupper($partNo) . '|' . strtoupper($suffix) . '|' . strtoupper($model);
                if (isset($acc[$key])) {
                    $duplicates_collapsed++;
                    if ($qty > $acc[$key]['qty']) {
                        $acc[$key]['qty'] = $qty;
                    }
                    continue;
                }

                $acc[$key] = array(
                    'model'                => $model,
                    'suffix'               => $suffix,
                    'component'            => $partNo,
                    'material_description' => $partName,
                    'qty'                  => $qty,
                    'uom'                  => '',
                    'shop_code'            => $shopCode,
                );
            }
        }

        return array(
            'ok'                    => true,
            'message'               => 'ok',
            'rows'                  => array_values($acc),
            'skipped_non_numeric'   => $skipped_non_numeric,
            'duplicates_collapsed'  => $duplicates_collapsed,
            'unresolved_suffixes'   => array_keys($unresolved_suffix_set),
            'unresolved_cells'      => $unresolved_cells,
        );
    }

    /**
     * Suffix (upper-cased) -> Model, from Master BOM's own data — the
     * lookup parse_excel() uses to give each Part List row a Model
     * comparable to BOM's. A Suffix that names more than one distinct
     * Model in BOM is left unresolved (blank) rather than guessed at.
     */
    protected function bom_suffix_model_map()
    {
        $rows = $this->db->distinct()->select('suffix, model')
            ->from('bom')
            ->where('suffix !=', null)
            ->where('suffix !=', '')
            ->where('model !=', null)
            ->where('model !=', '')
            ->get()->result_array();

        $by_suffix = array();
        foreach ($rows as $row) {
            $suffix = strtoupper(trim($row['suffix']));
            $model = trim($row['model']);
            $by_suffix[$suffix][$model] = true;
        }

        $map = array();
        foreach ($by_suffix as $suffix => $models) {
            if (count($models) === 1) {
                $map[$suffix] = array_key_first($models);
            }
            // count() > 1 -> ambiguous, left out of the map on purpose (resolves to blank)
        }

        return $map;
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
            if ($row['component'] === '' || $row['shop_code'] === '') {
                $skipped++;
                continue;
            }

            $batch[] = array(
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

    /**
     * Checks only the fixed leading columns (Part No/Part Name/Shop/Model)
     * — everything after them is a variable-length list of Suffix columns,
     * not a fixed set to validate.
     */
    protected function header_matches(array $cells)
    {
        $normalize = function ($v) {
            return strtolower(trim((string) $v));
        };

        $expected = array_map($normalize, $this->required_headers);
        $actual = array_map($normalize, array_slice($cells, 0, count($this->required_headers)));

        return $expected === $actual;
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
     * The matching/comparison runs in MySQL (a JOIN, not two PHP
     * hashmaps) — an earlier version pulled the full `bom` (~146k rows)
     * and `part_list` (~180k+ rows once uploaded from the pivot format)
     * into PHP arrays on every call, which is what caused a fatal
     * "Out of memory" on a more memory-constrained server. This way only
     * genuine differences (a small fraction of the total) ever reach PHP.
     *
     * `bom` itself can have more than one row sharing the same
     * Model+Suffix+Part Number (different Material codes on the same
     * part/suffix) — summed here, the same way WIP Calc already treats
     * multiple BOM lines for one part_number as additive usage, rather
     * than silently keeping only one of them.
     *
     * @return array list of rows: status, model, suffix, component, part_number, field, bom_value, part_list_value
     */
    public function build_diff()
    {
        $current = $this->to_bytes(ini_get('memory_limit'));
        if ($current !== -1 && $current < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }

        $this->db->query('DROP TEMPORARY TABLE IF EXISTS tmp_bom_agg');
        $this->db->query('
            CREATE TEMPORARY TABLE tmp_bom_agg (
                model VARCHAR(30), suffix VARCHAR(80), part_number VARCHAR(60),
                component VARCHAR(60), material_description VARCHAR(255),
                qty DECIMAL(14,3), shop_code VARCHAR(100),
                PRIMARY KEY (model, suffix, part_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        ');
        $this->db->query('
            INSERT INTO tmp_bom_agg
            SELECT model, suffix, part_number,
                   MIN(component), MIN(material_description), SUM(qty), MIN(shop_code)
            FROM bom
            GROUP BY model, suffix, part_number
        ');

        $diffs = array();

        $only_bom = $this->db->query('
            SELECT b.model, b.suffix, b.component, b.part_number,
                   b.material_description AS b_desc, b.qty AS b_qty, b.shop_code AS b_shop
            FROM tmp_bom_agg b
            LEFT JOIN part_list p ON b.model = p.model AND b.suffix = p.suffix AND b.part_number = p.part_number
            WHERE p.id IS NULL
        ')->result_array();
        foreach ($only_bom as $row) {
            $diffs[] = array(
                'status'          => 'only_bom',
                'model'           => $row['model'],
                'suffix'          => $row['suffix'],
                'component'       => $row['component'],
                'part_number'     => $row['part_number'],
                'field'           => '(New Part)',
                'bom_value'       => $this->summarize_fields($row['b_desc'], $row['b_qty'], $row['b_shop']),
                'part_list_value' => '-',
            );
        }

        $only_part_list = $this->db->query('
            SELECT p.model, p.suffix, p.component, p.part_number,
                   p.material_description AS p_desc, p.qty AS p_qty, p.shop_code AS p_shop
            FROM part_list p
            LEFT JOIN tmp_bom_agg b ON b.model = p.model AND b.suffix = p.suffix AND b.part_number = p.part_number
            WHERE b.part_number IS NULL
        ')->result_array();
        foreach ($only_part_list as $row) {
            $diffs[] = array(
                'status'          => 'only_part_list',
                'model'           => $row['model'],
                'suffix'          => $row['suffix'],
                'component'       => $row['component'],
                'part_number'     => $row['part_number'],
                'field'           => '(New Part)',
                'bom_value'       => '-',
                'part_list_value' => $this->summarize_fields($row['p_desc'], $row['p_qty'], $row['p_shop']),
            );
        }

        // Present on both sides, but Qty/Shop Code/Material Description
        // disagree — the WHERE clause is a cheap pre-filter to shrink what
        // MySQL sends back; diff_field_value() (trim()-based, case
        // sensitive) still makes the final per-field call in PHP below,
        // so a WHERE clause false-positive (e.g. whitespace-only) just
        // means a candidate row that turns out to have no real diff.
        $mismatch_candidates = $this->db->query("
            SELECT b.model, b.suffix, b.component, b.part_number,
                   b.material_description AS b_desc, b.qty AS b_qty, b.shop_code AS b_shop,
                   p.material_description AS p_desc, p.qty AS p_qty, p.shop_code AS p_shop
            FROM tmp_bom_agg b
            INNER JOIN part_list p ON b.model = p.model AND b.suffix = p.suffix AND b.part_number = p.part_number
            WHERE b.qty <> p.qty
               OR COALESCE(b.shop_code,'') COLLATE utf8mb4_bin <> COALESCE(p.shop_code,'') COLLATE utf8mb4_bin
               OR COALESCE(b.material_description,'') COLLATE utf8mb4_bin <> COALESCE(p.material_description,'') COLLATE utf8mb4_bin
        ")->result_array();

        $field_cols = array(
            'qty'                  => array('b_qty', 'p_qty'),
            'shop_code'            => array('b_shop', 'p_shop'),
            'material_description' => array('b_desc', 'p_desc'),
        );
        foreach ($mismatch_candidates as $row) {
            foreach ($this->compare_fields as $field => $label) {
                list($bCol, $pCol) = $field_cols[$field];
                $bom_value = $this->diff_field_value($field, $row[$bCol]);
                $part_list_value = $this->diff_field_value($field, $row[$pCol]);
                if ($bom_value !== $part_list_value) {
                    $diffs[] = array(
                        'status'          => 'mismatch',
                        'model'           => $row['model'],
                        'suffix'          => $row['suffix'],
                        'component'       => $row['component'],
                        'part_number'     => $row['part_number'],
                        'field'           => $label,
                        'bom_value'       => $bom_value,
                        'part_list_value' => $part_list_value,
                    );
                }
            }
        }

        $this->db->query('DROP TEMPORARY TABLE IF EXISTS tmp_bom_agg');

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

    protected function summarize_fields($description, $qty, $shop_code)
    {
        return sprintf(
            'Material Description: %s | Qty: %s | Shop Code: %s',
            $description,
            $this->diff_field_value('qty', $qty),
            $shop_code
        );
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
        $diffs = $this->filter_diffs_by_status_and_model(
            $diffs,
            $request['status_filter'] ?? '',
            $request['model_filter'] ?? ''
        );
        $total = count($diffs);

        $diffs = $this->filter_diffs_by_search($diffs, $request['search']['value'] ?? '');
        $filtered = count($diffs);

        $start = (int) ($request['start'] ?? 0);
        $length = (int) ($request['length'] ?? -1);
        $page = $length === -1 ? $diffs : array_slice($diffs, $start, $length);

        return array('data' => $page, 'filtered' => $filtered, 'total' => $total);
    }

    /**
     * Stream the Compare page's diff list as an .xlsx download, honoring
     * whichever Status filter / Model filter / search text is currently
     * applied on screen — so "Download Excel" exports exactly what's
     * visible, not the full unfiltered list.
     */
    public function export_compare($status_filter = '', $model_filter = '', $search = '')
    {
        $status_labels = array(
            'only_bom'       => 'Only in Master BOM',
            'only_part_list' => 'Only in Part List',
            'mismatch'       => 'Different',
        );

        $diffs = $this->build_diff();
        $diffs = $this->filter_diffs_by_status_and_model($diffs, $status_filter, $model_filter);
        $diffs = $this->filter_diffs_by_search($diffs, $search);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Compare');

        $headers = array('No', 'Status', 'Model', 'Suffix', 'Component', 'Part Number', 'Field', 'Master BOM Value', 'Part List Value');
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:I1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F6FEB');

        foreach (array('D', 'E') as $col) {
            $sheet->getStyle("{$col}1:{$col}1048576")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $r = 2;
        foreach ($diffs as $i => $d) {
            $sheet->setCellValueExplicit("A{$r}", $i + 1, DataType::TYPE_NUMERIC);
            $sheet->fromArray(array($status_labels[$d['status']] ?? $d['status'], $d['model'], $d['suffix']), null, "B{$r}");
            $sheet->setCellValueExplicit("D{$r}", $d['component'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$r}", $d['part_number'], DataType::TYPE_STRING);
            $sheet->fromArray(array($d['field'], $d['bom_value'], $d['part_list_value']), null, "F{$r}");
            $r++;
        }

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'part_list_compare_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    protected function filter_diffs_by_status_and_model(array $diffs, $status_filter, $model_filter)
    {
        $status_filter = trim((string) $status_filter);
        if ($status_filter !== '') {
            $diffs = array_values(array_filter($diffs, function ($d) use ($status_filter) {
                return $d['status'] === $status_filter;
            }));
        }

        $model_filter = trim((string) $model_filter);
        if ($model_filter !== '') {
            $diffs = array_values(array_filter($diffs, function ($d) use ($model_filter) {
                return $d['model'] === $model_filter;
            }));
        }

        return $diffs;
    }

    protected function filter_diffs_by_search(array $diffs, $search)
    {
        $search = trim((string) $search);
        if ($search === '') {
            return $diffs;
        }

        $needle = mb_strtolower($search);

        return array_values(array_filter($diffs, function ($d) use ($needle) {
            $haystack = mb_strtolower(implode(' ', array(
                $d['model'], $d['suffix'], $d['component'], $d['part_number'],
                $d['field'], $d['bom_value'], $d['part_list_value'],
            )));

            return mb_strpos($haystack, $needle) !== false;
        }));
    }

    protected function diff_field_value($field, $value)
    {
        if ($field === 'qty') {
            return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }
}
