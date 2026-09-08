<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class Wip extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->config->load('wip_api');
    }

    public function kap1()
    {
        $data['title'] = 'Master WIP - KAP 1';
        $data['source'] = 'wip/kap1';
        $data['shops'] = $this->config->item('wip_shop_labels');
        $data['page_js'] = 'assets/js/wip.js';
        $this->render('wip/index', $data, 'wip_kap1');
    }

    public function kap2()
    {
        $data['title'] = 'Master WIP - KAP 2';
        $data['source'] = 'wip/kap2';
        $data['shops'] = $this->config->item('wip_shop_labels');
        $data['page_js'] = 'assets/js/wip.js';
        $this->render('wip/index', $data, 'wip_kap2');
    }

    public function kap1_data($shop)
    {
        $this->load->model('Wip_kap1_model');
        $this->respond($this->Wip_kap1_model->get_shop($shop));
    }

    public function kap2_data($shop)
    {
        $this->load->model('Wip_kap2_model');
        $this->respond($this->Wip_kap2_model->get_shop($shop));
    }

    public function kap1_export($shop)
    {
        $this->load->model('Wip_kap1_model');
        $result = $this->Wip_kap1_model->get_shop($shop);
        $this->export($result, 'kap1', $shop);
    }

    public function kap2_export($shop)
    {
        $this->load->model('Wip_kap2_model');
        $result = $this->Wip_kap2_model->get_shop($shop);
        $this->export($result, 'kap2', $shop);
    }

    /**
     * Stream the given WIP result set as an .xlsx download.
     */
    protected function export(array $result, $source, $shop)
    {
        if (!$result['ok']) {
            show_error($result['message'], 502, 'WIP data unavailable');

            return;
        }

        $headers = array('No', 'VIN', 'Suffix', 'Model', 'Color Code', 'Color Desc', 'Last Scan', 'Scan Date', 'Shop Code');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $shopLabel = $this->config->item('wip_shop_labels')[$shop] ?? $shop;
        $sheet->setTitle(substr($shopLabel, 0, 31));

        $sheet->fromArray($headers, null, 'A1');
        $lastCol = chr(ord('A') + count($headers) - 1);
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F6FEB');

        $r = 2;
        foreach ($result['data'] as $i => $row) {
            $sheet->fromArray(array(
                $i + 1,
                $row['vin'],
                $row['sfx'],
                $row['modelcode'],
                $row['colorcode'],
                $row['colorname'],
                $row['wipname'],
                $row['scandate'],
                $row['shopcode'],
            ), null, "A{$r}");
            $r++;
        }

        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'wip_' . $source . '_' . $shop . '_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    protected function respond(array $result)
    {
        $rows = array();
        foreach ($result['data'] as $i => $r) {
            $rows[] = array_merge(array('no' => $i + 1), $r);
        }

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(array(
                'status'  => $result['ok'] ? 'success' : 'error',
                'message' => $result['message'],
                'data'    => $rows,
            )));
    }
}
