<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Database-backed store for Master WIP rows (KAP1 & KAP2).
 *
 * Rows land here either via "Get Data WIP" (a live pull from the WIP
 * server, see Wip_kap1_model / Wip_kap2_model) or via an Excel upload
 * used as a fallback when the WIP server is down. The wip/kap1 and
 * wip/kap2 pages always read from this table, never straight from the
 * live server.
 */
class Wip_data_model extends CI_Model
{
    protected $table = 'wip_data';

    /** Required header columns of the upload/template Excel file, in order. */
    public $required_headers = array(
        'VIN', 'Suffix', 'Katashiki', 'Model', 'Color Code', 'Color Desc', 'Last Scan', 'Scan Date', 'Shop Code',
    );

    public function __construct()
    {
        parent::__construct();
        $this->config->load('wip_api');
    }

    /**
     * Read one shop's cached WIP rows from the database.
     *
     * @return array{ok:bool, message:string, data:array, updated_at:?string}
     */
    public function get_shop($source, $shop)
    {
        $rows = $this->db
            ->select('vin,sfx,katashiki,modelcode,colorcode,colorname,wipname,scandate,shopcode,updated_at')
            ->where('source', $source)
            ->where('shop', $shop)
            ->order_by('id', 'desc')
            ->get($this->table)
            ->result_array();

        $updated_at = null;
        foreach ($rows as &$r) {
            if ($updated_at === null || $r['updated_at'] > $updated_at) {
                $updated_at = $r['updated_at'];
            }
            unset($r['updated_at']);
        }
        unset($r);

        return array('ok' => true, 'message' => 'ok', 'data' => $rows, 'updated_at' => $updated_at);
    }

    /**
     * Replace one shop's cached rows with a freshly pulled set (used by "Get Data WIP").
     * Runs inside a transaction so a failed insert never leaves the shop empty.
     *
     * @param array $rows normalized rows (vin, sfx, katashiki, modelcode, colorcode, colorname, wipname, scandate, shopcode)
     */
    public function replace_shop($source, $shop, array $rows)
    {
        $this->db->trans_start();

        $this->db->where('source', $source)->where('shop', $shop)->delete($this->table);

        $now = date('Y-m-d H:i:s');
        $batch = array();
        foreach ($rows as $row) {
            $batch[] = array_merge($row, array(
                'source'     => $source,
                'shop'       => $shop,
                'created_at' => $now,
                'updated_at' => $now,
            ));

            if (count($batch) >= 500) {
                $this->db->insert_batch($this->table, $batch);
                $batch = array();
            }
        }
        if (!empty($batch)) {
            $this->db->insert_batch($this->table, $batch);
        }

        $this->db->trans_complete();

        return $this->db->trans_status();
    }

    /**
     * Insert Excel-uploaded rows (each already carries a resolved 'shop' key).
     *
     * @return array{inserted:int}
     */
    public function insert_rows($source, array $rows)
    {
        $now = date('Y-m-d H:i:s');
        $inserted = 0;
        $batch = array();

        foreach ($rows as $row) {
            $batch[] = array(
                'source'    => $source,
                'shop'      => $row['shop'],
                'vin'       => $row['vin'],
                'sfx'       => $row['sfx'],
                'katashiki' => $row['katashiki'],
                'modelcode' => $row['modelcode'],
                'colorcode' => $row['colorcode'],
                'colorname' => $row['colorname'],
                'wipname'   => $row['wipname'],
                'scandate'  => $row['scandate'],
                'shopcode'  => $row['shopcode'],
                'created_at' => $now,
                'updated_at' => $now,
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

        return array('inserted' => $inserted);
    }

    /**
     * Clear every cached row for a source (all shops) — used by upload "replace" mode.
     */
    public function truncate_source($source)
    {
        $this->db->where('source', $source)->delete($this->table);
    }

    /**
     * Clear the cached WIP rows for just one shop within a source — the
     * per-shop "Clear" button on the Master WIP page. Unlike truncate_source()
     * this leaves the other shops' cached data untouched. WIP Calc cutoffs
     * are not affected here; they simply fall back to Gross totals (no
     * cached units at all) for this shop until fresh data is pulled/uploaded.
     *
     * @return int number of rows deleted
     */
    public function clear_shop($source, $shop)
    {
        $cleared = (int) $this->db->where('source', $source)->where('shop', $shop)->count_all_results($this->table);
        if ($cleared > 0) {
            $this->db->where('source', $source)->where('shop', $shop)->delete($this->table);
        }

        return $cleared;
    }

    public function log($data)
    {
        $data = array_merge(array(
            'shop'       => null,
            'file_name'  => null,
            'total_rows' => 0,
            'user_id'    => null,
        ), $data);
        $data['created_at'] = date('Y-m-d H:i:s');
        $this->db->insert('wip_data_log', $data);
    }

    /**
     * Stream the upload template (9 required columns) straight to the browser.
     */
    public function download_template($source)
    {
        $labels = $this->config->item('wip_shop_labels');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('WIP Template');

        $sheet->fromArray($this->required_headers, null, 'A1');
        $lastCol = chr(ord('A') + count($this->required_headers) - 1);
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F6FEB');

        // Example rows to guide the user; Shop Code must match one of the
        // configured shop labels (or its key) below.
        $examples = array(
            array('MHFXX00G000123456', '', 'ABC1234-XYZ', 'D26A', '040', 'WHITE', 'ASSY LINE 1', date('Y-m-d H:i:s'), strtoupper($labels['assy'] ?? 'ASSY')),
            array('MHFXX00G000123457', 'A', 'DEF5678-XYZ', 'D74A', '218', 'BLACK', 'WELD LINE 2', date('Y-m-d H:i:s'), strtoupper($labels['weld'] ?? 'WELD')),
        );
        $r = 2;
        foreach ($examples as $example) {
            $sheet->fromArray($example, null, "A{$r}");
            $r++;
        }

        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'template_upload_wip_' . $source . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * Parse an uploaded Excel file, validate its header row and resolve each
     * row's Shop Code back to a shop key (weld|toso|assy).
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

        $shop_map = array();
        foreach ($this->config->item('wip_shop_labels') as $key => $label) {
            $shop_map[strtoupper($label)] = $key;
            $shop_map[strtoupper($key)] = $key;
        }

        $rows = array();
        $skipped = 0;
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

                $shopCodeRaw = trim((string) ($cells[8] ?? ''));
                $shopKey = $shop_map[strtoupper($shopCodeRaw)] ?? null;
                if ($shopKey === null) {
                    $skipped++;
                    continue;
                }

                $rows[] = array(
                    'shop'      => $shopKey,
                    'vin'       => trim((string) ($cells[0] ?? '')),
                    'sfx'       => trim((string) ($cells[1] ?? '')),
                    'katashiki' => trim((string) ($cells[2] ?? '')),
                    'modelcode' => trim((string) ($cells[3] ?? '')),
                    'colorcode' => trim((string) ($cells[4] ?? '')),
                    'colorname' => trim((string) ($cells[5] ?? '')),
                    'wipname'   => trim((string) ($cells[6] ?? '')),
                    'scandate'  => trim((string) ($cells[7] ?? '')),
                    'shopcode'  => strtoupper($shopCodeRaw),
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
