<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Part List: a second BOM-shaped table whose whole purpose is to be
 * checked against `bom` for mismatches — see compare()/compare_summary()
 * below.
 *
 * Unlike Master BOM's own upload (one row per Model+Suffix+Component
 * line), Part List's real-world source is a wide "pivot" spreadsheet
 * matching the factory's actual "PARTLIST" master file, with a two-row
 * header: row 1 has Part No/Part Name/Shop/Model (each merged down into
 * row 2) followed by one merged cell per Model spanning that model's
 * Suffix columns; row 2 has the actual Suffix code under each of those
 * columns. Data starts at row 3: one row per part, with the Qty in the
 * cell where that part is used at that suffix. A suffix column's Model
 * comes entirely from which merged group it falls under in row 1 — never
 * looked up or guessed from Master BOM, and not read from the per-row
 * "Model" column either (that column is just the file's own free-text
 * summary of every model the part appears in, e.g. "D55L&D52B&D74A" —
 * ignored for resolution, since row 1's grouping already says exactly
 * which single model each suffix belongs to; see parse_excel()).
 */
class Part_list_model extends CI_Model
{
    protected $table = 'part_list';

    /** Fixed leading columns of the upload/template Excel file; every column after these is a Suffix. */
    public $required_headers = array('Part No', 'Part Name', 'Shop', 'Model');

    /**
     * Fields compared row-for-row once two sides share the same
     * Model+Suffix+Part Number key. Only Qty: a part is "Different" when
     * its Qty disagrees, nothing else. Part name (Material Description),
     * Shop Code, Material/Katashiki/Uom are deliberately ignored — they
     * are allowed to differ between Master BOM and Part List.
     */
    protected $compare_fields = array(
        'qty' => 'Qty',
    );

    /**
     * Shop codes every Part List *read* is limited to — set for a scoped
     * User account (see Part_list::__construct), empty = no limit. A row
     * matches when any code in its comma-separated shop_code list is one
     * of these, e.g. scope [WELD3] keeps "WELD3" and "WELD3,ASSY3".
     *
     * Applied by every read below (listing, counts, model cards, both
     * exports). Writes are admin-only and never scoped.
     *
     * @var string[]
     */
    protected $shop_scope = array();

    public function __construct()
    {
        parent::__construct();
    }

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
        $this->db->where($this->shop_scope_sql(), null, false);
    }

    /**
     * The shop scope as a raw SQL condition, for the hand-written queries
     * that bypass the query builder. Always a complete parenthesized
     * expression; '1=1' when there is no scope.
     */
    protected function shop_scope_sql()
    {
        if (empty($this->shop_scope)) {
            return '1=1';
        }
        $parts = array();
        foreach ($this->shop_scope as $code) {
            $parts[] = 'FIND_IN_SET(' . $this->db->escape($code) . ', shop_code) > 0';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * DataTables server-side listing.
     */
    /** Columns the Excel-style header filters may act on (see Column_filter). */
    public $filter_columns = array('model', 'suffix', 'component', 'part_number', 'material_description', 'qty', 'uom', 'shop_code');

    /**
     * Start a query on part_list with every filter the table view applies:
     * shop scope, Model card, search box, and the header filters — except
     * $except_column's own (a column's dropdown list must not filter
     * itself). Shared by datatable() and distinct_values(), so a scoped
     * User's dropdown can only ever list values from its own shops.
     */
    protected function apply_list_filters($request, $except_column = null)
    {
        $this->db->from($this->table);
        $this->apply_shop_scope();

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
        $columns = array('id', 'model', 'suffix', 'component', 'part_number', 'material_description', 'qty', 'uom', 'shop_code');

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
     * Tambah satu baris Part List dari tombol "Tambah Manual" / "Copy".
     * Aturannya sama dengan update(), termasuk penjagaan supaya tidak bentrok
     * dengan baris lain pada Model + Suffix + Part Number + Shop Code.
     *
     * @return array{ok:bool, message:string, id?:int}
     */
    public function create(array $data)
    {
        $clean = $this->clean_row($data);
        if (isset($clean['error'])) {
            return array('ok' => false, 'message' => $clean['error']);
        }
        if ($this->row_clash($clean['row'], null)) {
            return array('ok' => false, 'message' => 'Sudah ada baris dengan Model + Suffix + Part Number + Shop Code yang sama.');
        }

        $this->db->insert($this->table, array_merge($clean['row'], array('created_at' => date('Y-m-d H:i:s'))));

        return array('ok' => true, 'message' => 'Baris Part List ditambahkan.', 'id' => (int) $this->db->insert_id());
    }

    /**
     * Validasi + normalisasi satu baris Part List dari form (dipakai create()
     * dan update()).
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

    /** Baris lain dengan kunci unik yang sama (Model+Suffix+Part Number+Shop Code). */
    protected function row_clash(array $row, $ignore_id = null)
    {
        $this->db->select('id')->where(array(
            'model'       => $row['model'],
            'suffix'      => $row['suffix'],
            'part_number' => $row['part_number'],
            'shop_code'   => $row['shop_code'],
        ));
        if ($ignore_id !== null) {
            $this->db->where('id !=', (int) $ignore_id);
        }

        return (bool) $this->db->get($this->table)->row_array();
    }

    /**
     * Edit satu baris Part List dari tombol Edit di tabel. Part Number yang
     * dikosongkan diambil dari Component (akhiran "-00" dibuang) dan Shop Code
     * dinormalkan, sama seperti waktu upload. Satu baris tetap unik pada
     * Model + Suffix + Part Number + Shop Code, jadi edit yang membuatnya
     * bentrok dengan baris lain ditolak di sini (bukan dilempar sebagai error
     * duplicate key dari database).
     *
     * @return array{ok:bool, message:string}
     */
    public function update($id, array $data)
    {
        $row = $this->get($id);
        if (!$row) {
            return array('ok' => false, 'message' => 'Baris Part List tidak ditemukan.');
        }

        $clean = $this->clean_row($data);
        if (isset($clean['error'])) {
            return array('ok' => false, 'message' => $clean['error']);
        }
        if ($this->row_clash($clean['row'], $id)) {
            return array('ok' => false, 'message' => 'Sudah ada baris lain dengan Model + Suffix + Part Number + Shop Code yang sama.');
        }

        $this->db->where('id', (int) $id)->update($this->table, $clean['row']);

        return array('ok' => true, 'message' => 'Baris Part List diperbarui.');
    }

    public function delete($id)
    {
        return $this->db->where('id', $id)->delete($this->table);
    }

    /**
     * Delete several rows at once (the "Delete Selected" bulk action on
     * the Part List page's checkbox column).
     *
     * @param int[] $ids
     * @return int number of rows actually deleted
     */
    public function delete_many(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return 0;
        }

        $this->db->where_in('id', $ids)->delete($this->table);

        return $this->db->affected_rows();
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
     * the Part List page.
     */
    public function model_summary()
    {
        $this->db->select('model, COUNT(*) AS total')
            ->from($this->table)
            ->where('model !=', null)
            ->where('model !=', '');
        $this->apply_shop_scope();

        return $this->db->group_by('model')
            ->order_by('model', 'asc')
            ->get()->result_array();
    }

    /**
     * Stream the upload template: two header rows — Part No/Part
     * Name/Shop/Model (merged down both rows) followed by one merged
     * cell per Model (row 1) spanning that model's Suffix columns (row
     * 2), built from whatever Model+Suffix combinations Master BOM
     * currently has. Falls back to one placeholder model/suffix pair
     * when BOM is empty.
     */
    public function download_template()
    {
        $groups = $this->bom_suffix_groups();
        if (empty($groups)) {
            $groups = array(array('model' => 'MODEL', 'suffixes' => array('MN', 'XX'))); // nothing in Master BOM yet to base real ones on
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Part List');

        foreach (array_combine(range('A', 'D'), $this->required_headers) as $col => $label) {
            $sheet->setCellValue("{$col}1", $label);
            $sheet->mergeCells("{$col}1:{$col}2");
        }

        $colIndex = count($this->required_headers); // 0-based, first Suffix column
        foreach ($groups as $group) {
            $startCol = $this->column_letter($colIndex);
            $sheet->setCellValue("{$startCol}1", $group['model']);
            foreach ($group['suffixes'] as $suffix) {
                $sheet->setCellValue($this->column_letter($colIndex) . '2', $suffix);
                $colIndex++;
            }
            $endCol = $this->column_letter($colIndex - 1);
            if ($endCol !== $startCol) {
                $sheet->mergeCells("{$startCol}1:{$endCol}1");
            }
        }
        $lastCol = $this->column_letter($colIndex - 1);

        $sheet->getStyle("A1:{$lastCol}2")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$lastCol}2")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F6FEB');
        $sheet->getStyle("A1:{$lastCol}2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Part No holds long codes (e.g. 9004A-11336-00); keep it as text
        // so Excel never collapses a numeric-looking one into scientific notation.
        $sheet->getStyle('A1:A1048576')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        // Example data row, now at row 3 (rows 1-2 are the header).
        $sheet->setCellValueExplicit('A3', '9004A-11336-00', DataType::TYPE_STRING);
        $sheet->fromArray(array('BOLT, WELD', 'WELD3'), null, 'B3');
        // One example qty in the first suffix column, so the shape is obvious at a glance.
        $sheet->setCellValue($this->column_letter(count($this->required_headers)) . '3', 1);

        foreach (range('A', 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        // Autosizing every suffix column individually is slow once there are
        // hundreds of them (see parse_excel()'s note on the real file's
        // size) — a fixed narrow width reads fine for short 2-3 char codes.
        for ($i = count($this->required_headers); $i < $colIndex; $i++) {
            $sheet->getColumnDimension($this->column_letter($i))->setWidth(6);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="template_upload_part_list.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Master BOM's own Model+Suffix combinations, grouped by Model (in
     * Model then Suffix order) — one group per Model, each holding that
     * Model's distinct Suffixes. A Suffix that somehow appears under more
     * than one Model in BOM is only listed once, under its first Model.
     */
    protected function bom_suffix_groups()
    {
        $rows = $this->db->distinct()->select('model, suffix')
            ->from('bom')
            ->where('suffix !=', null)
            ->where('suffix !=', '')
            ->where('model !=', null)
            ->where('model !=', '')
            ->order_by('model', 'asc')
            ->order_by('suffix', 'asc')
            ->get()->result_array();

        $seenSuffix = array();
        $byModel = array();
        foreach ($rows as $row) {
            $model = trim($row['model']);
            $suffix = trim($row['suffix']);
            if ($suffix === '' || isset($seenSuffix[$suffix])) {
                continue;
            }
            $seenSuffix[$suffix] = true;
            $byModel[$model][] = $suffix;
        }

        $groups = array();
        foreach ($byModel as $model => $suffixes) {
            $groups[] = array('model' => $model, 'suffixes' => $suffixes);
        }

        return $groups;
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
     * Kolom Suffix untuk unduhan: tiap pasangan (Model, Suffix) yang benar-benar
     * ada di part_list, dikelompokkan per Model — bukan diambil dari Master BOM
     * seperti download_template(), supaya tidak ada Qty yang kehilangan
     * kolomnya. Satu suffix yang dipakai dua Model tetap dapat kolom sendiri di
     * masing-masing grup, persis seperti yang dibaca parse_excel().
     *
     * @return array<int,array{model:string, suffixes:string[]}>
     */
    protected function part_list_suffix_groups($model_filter = '')
    {
        $this->db->distinct()->select('model, suffix')
            ->from($this->table)
            ->where('model !=', null)
            ->where('model !=', '')
            ->where('suffix !=', null)
            ->where('suffix !=', '')
            ->order_by('model', 'asc')
            ->order_by('suffix', 'asc');

        $model_filter = trim((string) $model_filter);
        if ($model_filter !== '') {
            $this->db->where('model', $model_filter);
        }
        $this->apply_shop_scope();

        $by_model = array();
        foreach ($this->db->get()->result_array() as $row) {
            $by_model[trim((string) $row['model'])][] = trim((string) $row['suffix']);
        }

        $groups = array();
        foreach ($by_model as $model => $suffixes) {
            $groups[] = array('model' => (string) $model, 'suffixes' => $suffixes);
        }

        return $groups;
    }

    /**
     * Posisi kolom tiap (Model, Suffix) di layout pivot: "MODEL|SUFFIX" (huruf
     * besar) => indeks kolom 0-based, dihitung setelah kolom depan (Part No,
     * Part Name, Shop, Model).
     */
    protected function pivot_column_index(array $groups)
    {
        $index = array();
        $col = count($this->required_headers);
        foreach ($groups as $group) {
            foreach ($group['suffixes'] as $suffix) {
                $index[strtoupper($group['model'] . '|' . $suffix)] = $col;
                $col++;
            }
        }

        return $index;
    }

    /**
     * Baca part_list terurut lalu susun kembali jadi baris pivot — satu baris
     * per (Part No, Shop), dengan Qty di kolom (Model, Suffix) masing-masing.
     * Tiap baris dikirim lewat $emit begitu selesai, bukan dikumpulkan dulu,
     * jadi memorinya tetap rata walau datanya ratusan ribu baris.
     *
     * $emit menerima: array lead (Part No, Part Name, Shop), map Model yang
     * dipakai baris itu, dan map indeks kolom => Qty.
     */
    protected function each_pivot_row($model_filter, array $index, $emit)
    {
        $sql = 'SELECT component, part_number, material_description, shop_code, model, suffix, qty FROM '
            . $this->db->protect_identifiers($this->table);

        $sql .= ' WHERE ' . $this->shop_scope_sql();
        $model_filter = trim((string) $model_filter);
        if ($model_filter !== '') {
            $sql .= ' AND model = ' . $this->db->escape($model_filter);
        }
        // Urutan inilah yang bikin baris pivot bisa disusun sambil jalan:
        // semua baris untuk satu Part No + Shop pasti berurutan.
        $sql .= ' ORDER BY component ASC, shop_code ASC, model ASC, suffix ASC';

        $query = $this->db->query($sql);

        $key = null;
        $lead = array('', '', '');
        $models = array();
        $cells = array();

        while ($row = $query->unbuffered_row('array')) {
            $part_no = trim((string) $row['component']);
            if ($part_no === '') {
                $part_no = trim((string) $row['part_number']);
            }
            $shop = (string) $row['shop_code'];
            $row_key = strtoupper($part_no . '|' . $shop);

            if ($row_key !== $key) {
                if ($key !== null) {
                    $emit($lead, $models, $cells);
                }
                $key = $row_key;
                $lead = array($part_no, trim((string) $row['material_description']), $shop);
                $models = array();
                $cells = array();
            } elseif ($lead[1] === '') {
                $lead[1] = trim((string) $row['material_description']);
            }

            $model = trim((string) $row['model']);
            if ($model !== '') {
                $models[$model] = true;
            }

            $pos = $index[strtoupper($model . '|' . trim((string) $row['suffix']))] ?? null;
            if ($pos !== null) {
                $cells[$pos] = rtrim(rtrim(number_format((float) $row['qty'], 3, '.', ''), '0'), '.');
            }
        }

        if ($key !== null) {
            $emit($lead, $models, $cells);
        }

        $query->free_result();
    }

    /**
     * Unduh Part List sebagai CSV dengan layout yang sama dengan template
     * upload: Part No, Part Name, Shop, Model, lalu satu kolom per Suffix yang
     * dikelompokkan per Model (nama Model ditulis di kolom pertama grupnya,
     * meniru sel gabungan di template — parse_excel() meneruskannya ke kanan).
     * Hasilnya bisa diedit lalu diupload balik.
     *
     * Ditulis langsung ke php://output baris demi baris, jadi ratusan ribu
     * baris pun tetap ringan dan unduhannya langsung mulai.
     */
    public function export_csv($model_filter = '')
    {
        $groups = $this->part_list_suffix_groups($model_filter);
        $index = $this->pivot_column_index($groups);
        $width = count($this->required_headers) + count($index);

        $model_filter = trim((string) $model_filter);
        $filename = 'part_list_'
            . ($model_filter !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $model_filter) . '_' : '')
            . date('Ymd_His') . '.csv';

        // Buang buffer output CI dulu, supaya tiap baris benar-benar terkirim
        // ke browser dan tidak ditumpuk sampai selesai.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('X-Accel-Buffering: no'); // jangan di-buffer proxy/nginx

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");  // BOM, supaya Excel membaca UTF-8 dengan benar
        fwrite($out, "sep=,\r\n");     // petunjuk pemisah kolom untuk Excel

        // Baris 1: kolom depan, lalu nama Model di kolom pertama tiap grup.
        // Baris 2: kode Suffix di bawah masing-masing kolomnya.
        $row1 = $this->required_headers;
        $row2 = array_fill(0, count($this->required_headers), '');
        foreach ($groups as $group) {
            foreach ($group['suffixes'] as $i => $suffix) {
                $row1[] = $i === 0 ? $group['model'] : '';
                $row2[] = $suffix;
            }
        }
        fputcsv($out, $row1);
        fputcsv($out, $row2);

        $written = 0;
        $this->each_pivot_row($model_filter, $index, function ($lead, $models, $cells) use ($out, $width, &$written) {
            $line = array_fill(0, $width, '');
            $line[0] = $lead[0];
            $line[1] = $lead[1];
            $line[2] = $lead[2];
            $line[3] = implode('&', array_keys($models)); // ringkasan Model, seperti di file aslinya
            foreach ($cells as $pos => $qty) {
                $line[$pos] = $qty;
            }
            fputcsv($out, $line);

            $written++;
            if (($written % 1000) === 0) {
                flush();
            }
        });

        fclose($out);
        exit;
    }

    /**
     * Unduhan yang sama dalam bentuk .xlsx — layout dan isinya persis seperti
     * export_csv(), plus sel gabungan per Model dan Part No dikunci sebagai
     * teks. Hanya sel yang benar-benar terisi yang ditulis, jadi jauh lebih
     * ringan daripada versi memanjang yang lama (satu baris per Model+Suffix).
     */
    public function export_data($model_filter = '')
    {
        $current = $this->to_bytes(ini_get('memory_limit'));
        if ($current !== -1 && $current < 1024 * 1024 * 1024) {
            @ini_set('memory_limit', '1024M');
        }

        $groups = $this->part_list_suffix_groups($model_filter);
        $index = $this->pivot_column_index($groups);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Part List');

        foreach (array_combine(range('A', 'D'), $this->required_headers) as $col => $label) {
            $sheet->setCellValue("{$col}1", $label);
            $sheet->mergeCells("{$col}1:{$col}2");
        }

        $colIndex = count($this->required_headers);
        foreach ($groups as $group) {
            $startCol = $this->column_letter($colIndex);
            $sheet->setCellValue("{$startCol}1", $group['model']);
            foreach ($group['suffixes'] as $suffix) {
                $sheet->setCellValueExplicit($this->column_letter($colIndex) . '2', $suffix, DataType::TYPE_STRING);
                $colIndex++;
            }
            $endCol = $this->column_letter($colIndex - 1);
            if ($endCol !== $startCol) {
                $sheet->mergeCells("{$startCol}1:{$endCol}1");
            }
        }
        $lastCol = $this->column_letter(max($colIndex - 1, count($this->required_headers) - 1));

        $sheet->getStyle("A1:{$lastCol}2")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$lastCol}2")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F6FEB');
        $sheet->getStyle("A1:{$lastCol}2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Part No memuat kode seperti 9004A-11336-00 — dikunci sebagai teks
        // supaya Excel tidak mengubahnya jadi notasi ilmiah.
        $sheet->getStyle('A1:A1048576')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $r = 3; // baris 1-2 header
        $this->each_pivot_row($model_filter, $index, function ($lead, $models, $cells) use ($sheet, &$r) {
            $sheet->setCellValueExplicit('A' . $r, $lead[0], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B' . $r, $lead[1], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C' . $r, $lead[2], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('D' . $r, implode('&', array_keys($models)), DataType::TYPE_STRING);
            foreach ($cells as $pos => $qty) {
                $sheet->setCellValue($this->column_letter($pos) . $r, (float) $qty);
            }
            $r++;
        });

        foreach (range('A', 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        // Autosize tiap kolom suffix terlalu lambat kalau jumlahnya ratusan
        // (lihat download_template()) — lebar tetap sudah cukup untuk kode 2-3 huruf.
        for ($i = count($this->required_headers); $i < $colIndex; $i++) {
            $sheet->getColumnDimension($this->column_letter($i))->setWidth(6);
        }
        $sheet->freezePane('E3');

        $model_filter = trim((string) $model_filter);
        $filename = 'part_list_'
            . ($model_filter !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $model_filter) . '_' : '')
            . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Parse an uploaded "pivot" Part List file with its two-row header:
     * row 1 has Part No/Part Name/Shop/Model, then one merged cell per
     * Model spanning that model's Suffix columns; row 2 has the actual
     * Suffix code under each of those columns. Data starts at row 3: one
     * row per part, with the Qty in the cell where that part is used at
     * that suffix (blank = not used there). A cell holding 0 is treated
     * the same as blank — a 0 qty means the part isn't actually used at
     * that suffix, so no row is created for it.
     *
     * A suffix column's Model comes entirely from row 1's merged grouping
     * — never looked up or guessed from Master BOM, and not read from the
     * per-row "Model" column either (that's just the file's own free-text
     * summary of every model the part appears in, e.g. "D55L&D52B&D74A",
     * kept in the file for reference but not used to resolve anything
     * here — row 1's grouping already says exactly which single model
     * each suffix belongs to).
     *
     * A cell holding a non-numeric marker (the real file uses "X") is
     * skipped, not guessed at — counted in $skipped_non_numeric instead so
     * the upload result tells you to go check those cells by hand.
     *
     * The same (Part No, Suffix, resolved Model, Shop) can legitimately appear
     * more than once — the real master file carries outright duplicate
     * rows for the same part (copy/paste artifacts) that don't always
     * agree with each other. The larger Qty wins ($duplicates_collapsed
     * counts how often this happened), on the assumption that a smaller
     * duplicate is just an incomplete copy, not a genuinely smaller count.
     * Shop is part of that identity: the same part at the same suffix can
     * be used in ASSY3 with one Qty and in ASSY4 with another, and those are
     * two real rows (KAP1 counts one, KAP2 the other) — never a duplicate.
     *
     * @return array{ok:bool, message:string, rows?:array, skipped_non_numeric?:int,
     *     duplicates_collapsed?:int, blank_model_suffixes?:string[], blank_model_cells?:int}
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
            // Without this, a large single-line sheet XML (the real
            // pivot file's is ~20MB with ~140 columns) can trip libxml's
            // default "huge input" guard — silently returning an EMPTY
            // sheet with no error/exception, which then fails header
            // validation for a completely unrelated-looking reason.
            // Reproduced locally; whether it bites depends on the
            // server's libxml build, which is why this only failed on
            // some machines and not others for the exact same file.
            if (method_exists($reader, 'setParseHuge')) {
                $reader->setParseHuge(true);
            }
            $spreadsheet = $reader->load($file_path);
        } catch (\Throwable $e) {
            return array('ok' => false, 'message' => 'Unable to read the Excel file: ' . $e->getMessage());
        }

        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();

        // ---- Header: two rows. Row 1 = Part No/Part Name/Shop/Model
        // (merged down into row 2) then one merged cell per Model spanning
        // that model's Suffix columns; row 2 = the Suffix code under each
        // of those columns. A Suffix column's Model is whichever row-1
        // value was last seen at or before it — i.e. forward-filled across
        // the merge, which works whether or not the merge metadata itself
        // is intact, since a merged cell only ever stores its value in the
        // top-left cell either way. ----
        $row1Cells = array();
        $it1 = $sheet->getRowIterator(1, 1)->current()->getCellIterator('A', $highestCol);
        $it1->setIterateOnlyExistingCells(false);
        foreach ($it1 as $cell) {
            $row1Cells[] = $cell->getValue();
        }

        if (!$this->header_matches($row1Cells)) {
            // A real pivot file (Part No/Part Name/Shop/Model + many Suffix
            // columns) that comes back with only 1 row / column A detected
            // isn't a bad template — the sheet failed to load at all
            // (observed under memory pressure on a large ~20MB single-line
            // sheet XML: PhpSpreadsheet/libxml can silently hand back an
            // empty sheet instead of throwing). Tell the user to retry
            // rather than pointing them at their (likely fine) file.
            if ($highestRow <= 1 && $highestCol === 'A') {
                return array(
                    'ok' => false,
                    'message' => 'The Excel file appears to have loaded empty (this can happen under heavy server load on a large file). Please try uploading again; if it keeps happening, ask an admin to check server memory.',
                );
            }

            return array(
                'ok' => false,
                'message' => 'Invalid template. Expected the first row to start with: ' . implode(', ', $this->required_headers) . ', followed by one merged cell per Model spanning that Model\'s Suffix columns.',
            );
        }

        $row2Cells = array();
        $it2 = $sheet->getRowIterator(2, 2)->current()->getCellIterator('A', $highestCol);
        $it2->setIterateOnlyExistingCells(false);
        foreach ($it2 as $cell) {
            $row2Cells[] = $cell->getValue();
        }

        $prefixCount = count($this->required_headers);
        $suffixColumns = array(); // column index (0-based) => ['suffix' => ..., 'model' => ...]
        $currentModel = '';
        for ($i = $prefixCount; $i < count($row1Cells); $i++) {
            $modelCell = trim((string) ($row1Cells[$i] ?? ''));
            if ($modelCell !== '') {
                $currentModel = $modelCell; // start of a new Model's merged group
            }

            $suffix = trim((string) ($row2Cells[$i] ?? ''));
            if ($suffix !== '') {
                $suffixColumns[$i] = array('suffix' => $suffix, 'model' => $currentModel);
            }
        }

        if (empty($suffixColumns)) {
            return array('ok' => false, 'message' => 'No Suffix columns found in row 2 after Part No/Part Name/Shop/Model.');
        }

        // Keyed by "PART_NUMBER|SUFFIX|MODEL|SHOP" (upper-cased) so a genuine
        // duplicate collapses onto the same accumulator entry, while the same
        // part used in two shops (ASSY3 vs ASSY4) stays two rows — matching
        // the table's (model, suffix, part_number, shop_code) unique key.
        $acc = array();
        $skipped_non_numeric = 0;
        $duplicates_collapsed = 0;
        $unresolved_suffix_set = array();
        $unresolved_cells = 0;

        // Rows 1-2 are the header; data starts at row 3.
        foreach ($sheet->getRowIterator(3, $highestRow) as $row) {
            $partNo = $this->normalize_part_no($sheet->getCell('A' . $row->getRowIndex())->getValue());
            if ($partNo === '') {
                continue; // no Part No -> not a real data row (e.g. leftover formula debris rows)
            }

            $partName = trim((string) $sheet->getCell('B' . $row->getRowIndex())->getValue());
            $shopCode = $this->normalize_shop_code($sheet->getCell('C' . $row->getRowIndex())->getValue());

            foreach ($suffixColumns as $colIndex => $colInfo) {
                $suffix = $colInfo['suffix'];
                $model = $colInfo['model'];

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
                if ($qty == 0.0) {
                    continue; // a 0 qty means the part isn't actually used at this suffix — treat like blank
                }

                if ($model === '') {
                    $unresolved_suffix_set[$suffix] = true;
                    $unresolved_cells++;
                }

                $key = strtoupper(strip_trailing_dash00($partNo)) . '|' . strtoupper($suffix) . '|' . strtoupper($model) . '|' . strtoupper($shopCode);
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
            'blank_model_suffixes'  => array_keys($unresolved_suffix_set),
            'blank_model_cells'     => $unresolved_cells,
        );
    }

    /**
     * Insert parsed rows into the database in chunks, computing part_number for each.
     *
     * @return array{inserted:int, skipped:int}
     */
    /**
     * Write uploaded rows, refreshing any row that already exists instead of
     * adding a second copy of it.
     *
     * A row's identity is (model, suffix, part_number, shop_code) — the
     * `uniq_part_list_row` key. shop_code belongs in that key because the
     * same part+model+suffix legitimately appears in different shops with
     * different quantities, so only a row matching on all four is the same
     * row. Everything else (description, qty, uom, component) is refreshed
     * from the file, so re-uploading a corrected sheet updates in place.
     *
     * This is what makes "expand" mode safe to run twice, and to run over
     * the KAP1 and KAP2 sheets whose contents overlap: before it, each
     * upload appended duplicates, and WIP Calc on the Part List basis then
     * counted a duplicated part's usage once per copy.
     *
     * $mode 'append' uses the same identity but leaves a row that is already
     * there untouched (only new rows are added); 'upsert' — and 'replace',
     * which starts from an empty table — refreshes it.
     *
     * @return array{inserted:int, updated:int, unchanged:int, skipped:int}
     */
    public function insert_rows(array $rows, $mode = 'upsert')
    {
        $refresh = $mode !== 'append';
        $skipped = 0;
        $written = 0;
        $now = date('Y-m-d H:i:s');
        $batch = array();

        $before = $this->count_all();

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
                $this->upsert_batch($batch, $refresh);
                $written += count($batch);
                $batch = array();
            }
        }

        if (!empty($batch)) {
            $this->upsert_batch($batch, $refresh);
            $written += count($batch);
        }

        // A row that was already there leaves the table size unchanged, so the
        // growth is the insert count and the rest already existed (updated, or
        // left as-is in append mode) — cheaper than asking about every row.
        $inserted = max(0, $this->count_all() - $before);
        $existing = max(0, $written - $inserted);

        return array(
            'inserted'  => $inserted,
            'updated'   => $refresh ? $existing : 0,
            'unchanged' => $refresh ? 0 : $existing,
            'skipped'   => $skipped,
        );
    }

    /**
     * One multi-row INSERT ... ON DUPLICATE KEY UPDATE. CI3's insert_batch()
     * can't express the upsert, so the statement is built here — values still
     * go through the query builder's escaping, never string interpolation.
     *
     * `created_at` is deliberately left alone on update, so a refreshed row
     * keeps the date it first arrived.
     */
    protected function upsert_batch(array $batch, $refresh = true)
    {
        if (empty($batch)) {
            return;
        }

        $columns = array_keys($batch[0]);
        $tuples = array();
        foreach ($batch as $row) {
            $values = array();
            foreach ($columns as $column) {
                $values[] = $this->db->escape($row[$column]);
            }
            $tuples[] = '(' . implode(',', $values) . ')';
        }

        // Append: a row already present stays exactly as it is ("id = id" is a no-op update).
        $updates = array('`id` = `id`');
        if ($refresh) {
            $updates = array();
            foreach (array('component', 'material_description', 'qty', 'uom', 'updated_at') as $column) {
                $updates[] = "`{$column}` = VALUES(`{$column}`)";
            }
        }

        $this->db->query(
            'INSERT INTO `' . $this->table . '` (`' . implode('`,`', $columns) . '`) VALUES '
            . implode(',', $tuples)
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
        );
    }

    public function log_upload($data)
    {
        // 'upsert' needs 2026_09_15_part_list_upload_log_upsert_mode.sql; until it
        // has run the enum rejects it, so log such an upload as 'append' instead.
        if (($data['mode'] ?? '') === 'upsert' && !$this->log_accepts_upsert()) {
            $data['mode'] = 'append';
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $this->db->insert('part_list_upload_log', $data);
    }

    protected function log_accepts_upsert()
    {
        $column = $this->db->query("SHOW COLUMNS FROM `part_list_upload_log` LIKE 'mode'")->row_array();

        return $column && strpos($column['Type'], "'upsert'") !== false;
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

    /**
     * Part No dari file upload: kode warna "-C0" di akhir disamakan dengan
     * "-00", mis. "12345-BZ123-C0" -> "12345-BZ123-00". Dilakukan sebelum
     * kunci duplikat dibentuk, jadi baris -C0 dan -00 untuk Suffix + Model +
     * Shop yang sama dianggap satu baris (Qty terbesar yang dipakai).
     */
    protected function normalize_part_no($raw)
    {
        $part_no = trim((string) $raw);
        if (strtoupper(substr($part_no, -3)) === '-C0') {
            return substr($part_no, 0, -3) . '-00';
        }

        return $part_no;
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

        // Present on both sides (same Model + Suffix + Part Number), but
        // Qty disagrees. Name / Shop Code differences are ignored on
        // purpose (see $compare_fields). The WHERE clause is a cheap
        // pre-filter; diff_field_value() still makes the final call in PHP,
        // so e.g. 1 vs 1.000 is never reported.
        $mismatch_candidates = $this->db->query("
            SELECT b.model, b.suffix, b.component, b.part_number,
                   b.qty AS b_qty, p.qty AS p_qty
            FROM tmp_bom_agg b
            INNER JOIN part_list p ON b.model = p.model AND b.suffix = p.suffix AND b.part_number = p.part_number
            WHERE b.qty <> p.qty
        ")->result_array();

        $field_cols = array(
            'qty' => array('b_qty', 'p_qty'),
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

        foreach (array('D', 'E', 'F') as $col) {
            $sheet->getStyle("{$col}1:{$col}1048576")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $r = 2;
        foreach ($diffs as $i => $d) {
            $sheet->setCellValueExplicit("A{$r}", $i + 1, DataType::TYPE_NUMERIC);
            $sheet->fromArray(array($status_labels[$d['status']] ?? $d['status'], $d['model']), null, "B{$r}");
            $sheet->setCellValueExplicit("D{$r}", $d['suffix'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$r}", $d['component'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("F{$r}", $d['part_number'], DataType::TYPE_STRING);
            $sheet->fromArray(array($d['field'], $d['bom_value'], $d['part_list_value']), null, "G{$r}");
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
