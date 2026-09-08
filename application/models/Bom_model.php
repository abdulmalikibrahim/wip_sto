<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use OpenSpout\Reader\XLSX\Reader as XlsxReader;

class Bom_model extends CI_Model
{
    protected $table = 'bom';

    /** Required header columns of the upload/template Excel file, in order. */
    public $required_headers = array(
        'Material', 'Suffix', 'Component', 'Material Description', 'Qty', 'Uom', 'Shop Code',
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
        $columns = array('id', 'material', 'suffix', 'component', 'part_number', 'material_description', 'qty', 'uom', 'shop_code');

        $this->db->from($this->table);

        $search = $request['search']['value'] ?? '';
        if ($search !== '') {
            $this->db->group_start();
            $this->db->like('material', $search);
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
     * Stream the upload template (single sheet, 7 required columns) straight to the browser.
     */
    public function download_template()
    {
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToBrowser('template_upload_bom.xlsx');

        $headerStyle = (new \OpenSpout\Common\Entity\Style\Style())
            ->setFontBold()
            ->setBackgroundColor('1F6FEB')
            ->setFontColor('FFFFFF');

        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($this->required_headers, $headerStyle));

        // A couple of example rows to guide the user (matching the real BOM format).
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(
            array('11103102000000', 'MN', '09101-BZ030-00', 'TOOL SET, STD L/JACK', 1, 'PC', 'WELD')
        ));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(
            array('11103102000000', 'MN', '11293-BZ840-00', 'LABEL, TUNE-UP SPECIFICATION INFORMATION', 1, 'PC', 'TOSO')
        ));

        $writer->close();
    }

    /**
     * Parse an uploaded Excel file and validate its header row.
     *
     * @return array{ok:bool, message:string, rows?:array}
     */
    public function parse_excel($file_path)
    {
        $reader = new XlsxReader();

        try {
            $reader->open($file_path);
        } catch (\Throwable $e) {
            return array('ok' => false, 'message' => 'Unable to read the Excel file: ' . $e->getMessage());
        }

        $rows = array();
        $header_checked = false;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $rowIndex => $row) {
                $cells = $row->toArray();

                if ($rowIndex === 1) {
                    $header_checked = true;
                    if (!$this->header_matches($cells)) {
                        $reader->close();

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
                    'suffix'               => trim((string) ($cells[1] ?? '')),
                    'component'            => trim((string) ($cells[2] ?? '')),
                    'material_description' => trim((string) ($cells[3] ?? '')),
                    'qty'                  => is_numeric($cells[4] ?? null) ? (float) $cells[4] : 0,
                    'uom'                  => trim((string) ($cells[5] ?? '')),
                    'shop_code'            => trim((string) ($cells[6] ?? '')),
                );
            }
        }

        $reader->close();

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
            if ($row['material'] === '' && $row['component'] === '') {
                $skipped++;
                continue;
            }

            $batch[] = array(
                'material'             => $row['material'],
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
}
