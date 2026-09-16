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
 * Juklak (petunjuk pelaksanaan): which Part No is represented by which Main
 * Part No on each KAP line. When the two differ, the part is already covered
 * by its main part, so WIP Calc / WIP Summary count it as 0 for that plant
 * (see Wip_calc_model::juklak_replaced()) — e.g. 42600-BY540 and 42600-BY530
 * are both listed for D52B/7J, and only the main 42600-BY530 is counted.
 *
 * The file has one header row: No, Part No, Part Name, Main Part No, one
 * column per Suffix (holding that suffix's Qty), Plant (KAP1/KAP2). The
 * per-suffix Qty is stored as reference only (suffix_qty, JSON) — it feeds
 * no calculation.
 */
class Juklak_model extends CI_Model
{
    protected $table = 'juklak';

    /** Leading columns before the Suffix columns, in file order. */
    protected $lead_headers = array('No', 'Part No', 'Part Name', 'Main Part No');

    /** Plant cell (upper-cased, spaces removed) => KAP line key, as used by wip_data.source. */
    public $plants = array('KAP1' => 'kap1', 'KAP2' => 'kap2');

    /** Whether the migration (database/migrations/2026_09_15_create_juklak.sql) has been run. */
    public function table_ready()
    {
        return $this->db->table_exists($this->table);
    }

    /**
     * Every Juklak row, suffix_qty decoded, plus is_main (Part No is its own
     * Main Part No, i.e. still counted).
     */
    public function all()
    {
        $rows = $this->db
            ->order_by('plant', 'asc')
            ->order_by('main_part_number', 'asc')
            ->order_by('part_number', 'asc')
            ->get($this->table)->result_array();

        foreach ($rows as &$row) {
            $row['suffix_qty'] = $this->decode_qty($row['suffix_qty']);
            $row['is_main'] = strcasecmp(trim($row['part_number']), trim($row['main_part_number'])) === 0;
        }
        unset($row);

        return $rows;
    }

    /** Daftar kode Suffix yang dikenal Master BOM / Part List, untuk saran di form. */
    public function suffixes()
    {
        return $this->suffix_columns();
    }

    /**
     * Ubah pasangan array Suffix[] / Qty[] dari form jadi map {SUFFIX: qty}.
     * Baris dengan Suffix atau Qty kosong diabaikan, dan Qty 0 tidak disimpan —
     * sama seperti aturan waktu upload.
     *
     * @return array|false false kalau ada Qty yang bukan angka
     */
    public function parse_suffix_qty($suffixes, $qtys)
    {
        $map = array();
        foreach ((array) $suffixes as $i => $suffix) {
            $suffix = strtoupper(trim((string) $suffix));
            $qty = trim((string) (is_array($qtys) && isset($qtys[$i]) ? $qtys[$i] : ''));
            if ($suffix === '' || $qty === '') {
                continue;
            }
            if (!is_numeric($qty)) {
                return false;
            }
            if ((float) $qty != 0.0) {
                $map[$suffix] = (float) $qty;
            }
        }

        return $map;
    }

    /**
     * Tambah satu baris Juklak dari tombol "Tambah Manual" / "Copy".
     * Identitasnya (plant, part_number), jadi pasangan yang sudah ada ditolak.
     *
     * @return array{ok:bool, message:string}
     */
    public function create($plant, $part_no_raw, $part_name, $main_no_raw, $suffixes, $qtys, $user_id)
    {
        if (!$this->table_ready()) {
            return array('ok' => false, 'message' => 'Tabel juklak belum ada.');
        }
        if (!in_array($plant, $this->plants, true)) {
            return array('ok' => false, 'message' => 'Plant harus KAP1 atau KAP2.');
        }

        $part_no = trim((string) $part_no_raw);
        if ($part_no === '') {
            return array('ok' => false, 'message' => 'Part No wajib diisi.');
        }

        $main_no = trim((string) $main_no_raw);
        if ($main_no === '') {
            $main_no = $part_no;
        }
        $part_number = strip_trailing_dash00($part_no);

        $qty_map = $this->parse_suffix_qty($suffixes, $qtys);
        if ($qty_map === false) {
            return array('ok' => false, 'message' => 'Qty per Suffix harus berupa angka.');
        }

        $existing = $this->db->select('id')
            ->where(array('plant' => $plant, 'part_number' => $part_number))
            ->get($this->table)->row_array();
        if ($existing) {
            return array('ok' => false, 'message' => 'Plant + Part No ini sudah ada di Juklak — pakai tombol Edit pada barisnya.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->insert($this->table, array(
            'plant'            => $plant,
            'part_no'          => $part_no,
            'part_name'        => trim((string) $part_name),
            'part_number'      => $part_number,
            'main_part_no'     => $main_no,
            'main_part_number' => strip_trailing_dash00($main_no),
            'suffix_qty'       => json_encode($qty_map, JSON_FORCE_OBJECT),
            'user_id'          => $user_id,
            'created_at'       => $now,
            'updated_at'       => $now,
        ));

        return array('ok' => true, 'message' => 'Baris Juklak ditambahkan.');
    }

    /**
     * Edit satu baris Juklak dari tombol Edit di tabel. Identitas baris tetap
     * (plant, part_number) — mengubahnya jadi pasangan yang sudah dipakai baris
     * lain ditolak, bukan bikin duplikat.
     *
     * $suffixes null berarti Qty per Suffix tidak ikut diubah (mis. dipanggil
     * dari form lama); array kosong berarti semuanya dikosongkan.
     *
     * @return array{ok:bool, message:string}
     */
    public function update($id, $plant, $part_no_raw, $part_name, $main_no_raw, $suffixes = null, $qtys = null)
    {
        if (!$this->table_ready()) {
            return array('ok' => false, 'message' => 'Tabel juklak belum ada.');
        }

        $row = $this->db->where('id', (int) $id)->get($this->table)->row_array();
        if (!$row) {
            return array('ok' => false, 'message' => 'Baris Juklak tidak ditemukan.');
        }
        if (!in_array($plant, $this->plants, true)) {
            return array('ok' => false, 'message' => 'Plant harus KAP1 atau KAP2.');
        }

        $part_no = trim((string) $part_no_raw);
        if ($part_no === '') {
            return array('ok' => false, 'message' => 'Part No wajib diisi.');
        }

        $main_no = trim((string) $main_no_raw);
        if ($main_no === '') {
            $main_no = $part_no; // kosong = part-nya main sendiri, sama seperti upload
        }
        $part_number = strip_trailing_dash00($part_no);

        $clash = $this->db->select('id')
            ->where(array('plant' => $plant, 'part_number' => $part_number))
            ->where('id !=', (int) $id)
            ->get($this->table)->row_array();
        if ($clash) {
            return array('ok' => false, 'message' => 'Plant + Part No ini sudah dipakai baris Juklak lain.');
        }

        $data = array(
            'plant'            => $plant,
            'part_no'          => $part_no,
            'part_name'        => trim((string) $part_name),
            'part_number'      => $part_number,
            'main_part_no'     => $main_no,
            'main_part_number' => strip_trailing_dash00($main_no),
            'updated_at'       => date('Y-m-d H:i:s'),
        );

        if ($suffixes !== null) {
            $qty_map = $this->parse_suffix_qty($suffixes, $qtys);
            if ($qty_map === false) {
                return array('ok' => false, 'message' => 'Qty per Suffix harus berupa angka.');
            }
            $data['suffix_qty'] = json_encode($qty_map, JSON_FORCE_OBJECT);
        }

        $this->db->where('id', (int) $id)->update($this->table, $data);

        return array('ok' => true, 'message' => 'Baris Juklak diperbarui.');
    }

    public function delete($id)
    {
        return $this->db->where('id', (int) $id)->delete($this->table);
    }

    public function truncate()
    {
        $this->db->truncate($this->table);
    }

    /** Header-only upload template, one Qty column per Suffix known to Master BOM / Part List. */
    public function download_template()
    {
        $this->stream_sheet(array(), $this->suffix_columns(), 'template_upload_juklak.xlsx');
    }

    /** Current Juklak data in the upload layout, so it can be edited and uploaded back. */
    public function export_data()
    {
        $rows = $this->all();

        $extra = array();
        foreach ($rows as $row) {
            $extra = array_merge($extra, array_keys($row['suffix_qty']));
        }

        $this->stream_sheet($rows, $this->suffix_columns($extra), 'juklak_data_' . date('Ymd_His') . '.xlsx');
    }

    /**
     * Suffix codes for the Qty columns: every suffix Master BOM or Part List
     * uses (in Model, then Suffix order — suffix codes are unique across
     * models), plus any $extra ones only the stored Juklak data has.
     */
    protected function suffix_columns(array $extra = array())
    {
        $rows = $this->db->query("
            SELECT model, suffix FROM bom WHERE suffix IS NOT NULL AND suffix <> ''
            UNION
            SELECT model, suffix FROM part_list WHERE suffix IS NOT NULL AND suffix <> ''
            ORDER BY model, suffix
        ")->result_array();

        $suffixes = array();
        foreach (array_merge(array_column($rows, 'suffix'), $extra) as $suffix) {
            $suffix = strtoupper(trim((string) $suffix));
            if ($suffix !== '') {
                $suffixes[$suffix] = true;
            }
        }

        return array_map('strval', array_keys($suffixes));
    }

    protected function stream_sheet(array $rows, array $suffixes, $filename)
    {
        $headers = array_merge($this->lead_headers, $suffixes, array('Plant'));
        $firstSuffix = count($this->lead_headers); // 0-based column index
        $lastCol = $this->column_letter(count($headers) - 1);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Juklak');

        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F6FEB');
        $sheet->getStyle("A1:{$lastCol}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Part numbers like 42600-BY540-00 stay text, so Excel never reformats them.
        foreach (array('B', 'D') as $col) {
            $sheet->getStyle("{$col}1:{$col}1048576")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $r = 2;
        foreach ($rows as $i => $row) {
            $sheet->setCellValue("A{$r}", $i + 1);
            $sheet->setCellValueExplicit("B{$r}", $row['part_no'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$r}", (string) $row['part_name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$r}", $row['main_part_no'], DataType::TYPE_STRING);
            foreach ($suffixes as $j => $suffix) {
                if (isset($row['suffix_qty'][$suffix])) {
                    $sheet->setCellValue($this->column_letter($firstSuffix + $j) . $r, (float) $row['suffix_qty'][$suffix]);
                }
            }
            $sheet->setCellValue("{$lastCol}{$r}", strtoupper($row['plant']));
            $r++;
        }

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(30);
        $sheet->getColumnDimension('D')->setWidth(18);
        for ($j = 0; $j < count($suffixes); $j++) {
            $sheet->getColumnDimension($this->column_letter($firstSuffix + $j))->setWidth(6);
        }
        $sheet->getColumnDimension($lastCol)->setWidth(8);
        $sheet->freezePane($this->column_letter($firstSuffix) . '2');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Parse an uploaded Juklak file. Columns are found by their header name
     * (row 1), so their order doesn't matter: "Part No", "Main Part No" and
     * "Plant" are required, "Part Name" is optional, "No" is ignored, and
     * every other non-blank header is a Suffix whose cells hold its Qty.
     *
     * A blank Main Part No means the part is its own main. The same Plant +
     * Part No twice keeps the last row.
     *
     * @return array{ok:bool, message:string, rows?:array, skipped_plant?:int,
     *     skipped_formula?:int, non_numeric?:int, duplicates?:int}
     */
    public function parse_excel($file_path)
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

        $col = array('part_no' => null, 'main_part_no' => null, 'plant' => null);
        $part_name_col = null;
        $suffix_cols = array(); // column index => suffix code
        foreach ($cells[0] as $i => $label) {
            $name = strtolower(preg_replace('/\s+/', ' ', str_replace('.', '', trim((string) $label))));
            if ($name === '' || $name === 'no') {
                continue;
            } elseif (in_array($name, array('part no', 'part number'), true)) {
                $col['part_no'] = $i;
            } elseif ($name === 'part name') {
                $part_name_col = $i;
            } elseif (in_array($name, array('main part no', 'main part number', 'main part'), true)) {
                $col['main_part_no'] = $i;
            } elseif ($name === 'plant') {
                $col['plant'] = $i;
            } else {
                $suffix_cols[$i] = strtoupper(trim((string) $label));
            }
        }

        if (in_array(null, $col, true)) {
            return array('ok' => false, 'message' => 'Invalid template. Row 1 must have: No, Part No, Part Name, Main Part No, one column per Suffix, Plant.');
        }

        $acc = array();
        $skipped_plant = 0;
        $skipped_formula = 0;
        $non_numeric = 0;
        $duplicates = 0;

        for ($r = 1; $r < count($cells); $r++) {
            $line = $cells[$r];
            $part_no = trim((string) ($line[$col['part_no']] ?? ''));
            if ($part_no === '') {
                continue;
            }
            $main_no = trim((string) ($line[$col['main_part_no']] ?? ''));

            // A formula pulling from another workbook comes back as its text, not a part number.
            if ($part_no[0] === '=' || ($main_no !== '' && $main_no[0] === '=')) {
                $skipped_formula++;
                continue;
            }
            if ($main_no === '') {
                $main_no = $part_no;
            }

            $plant_raw = strtoupper(preg_replace('/\s+/', '', (string) ($line[$col['plant']] ?? '')));
            if (!isset($this->plants[$plant_raw])) {
                $skipped_plant++;
                continue;
            }
            $plant = $this->plants[$plant_raw];

            $qty = array();
            foreach ($suffix_cols as $i => $suffix) {
                $value = $line[$i] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if (!is_numeric($value)) {
                    $non_numeric++;
                    continue;
                }
                if ((float) $value != 0.0) {
                    $qty[$suffix] = (float) $value;
                }
            }

            $part_number = strip_trailing_dash00($part_no);
            $key = $plant . '|' . strtoupper($part_number);
            if (isset($acc[$key])) {
                $duplicates++;
            }
            $acc[$key] = array(
                'plant'            => $plant,
                'part_no'          => $part_no,
                'part_name'        => $part_name_col === null ? '' : trim((string) ($line[$part_name_col] ?? '')),
                'part_number'      => $part_number,
                'main_part_no'     => $main_no,
                'main_part_number' => strip_trailing_dash00($main_no),
                'suffix_qty'       => $qty,
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
            'non_numeric'     => $non_numeric,
            'duplicates'      => $duplicates,
        );
    }

    /**
     * Insert new rows, refresh existing ones in place — a row's identity is
     * (plant, part_number), the uniq_juklak_part key.
     *
     * @return array{inserted:int, updated:int}
     */
    public function upsert_rows(array $rows, $user_id)
    {
        $inserted = 0;
        $updated = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $data = array(
                'part_no'          => $row['part_no'],
                'part_name'        => $row['part_name'],
                'main_part_no'     => $row['main_part_no'],
                'main_part_number' => $row['main_part_number'],
                'suffix_qty'       => json_encode($row['suffix_qty'], JSON_FORCE_OBJECT),
                'user_id'          => $user_id,
                'updated_at'       => $now,
            );

            $existing = $this->db->select('id')
                ->where(array('plant' => $row['plant'], 'part_number' => $row['part_number']))
                ->get($this->table)->row_array();

            if ($existing) {
                $this->db->where('id', $existing['id'])->update($this->table, $data);
                $updated++;
            } else {
                $this->db->insert($this->table, array_merge($data, array(
                    'plant'       => $row['plant'],
                    'part_number' => $row['part_number'],
                    'created_at'  => $now,
                )));
                $inserted++;
            }
        }

        return array('inserted' => $inserted, 'updated' => $updated);
    }

    protected function decode_qty($json)
    {
        $qty = json_decode((string) $json, true);

        return is_array($qty) ? $qty : array();
    }

    /** 0-based column index -> column letter(s): 0 -> A, 26 -> AA (the suffix columns run well past Z). */
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
}
