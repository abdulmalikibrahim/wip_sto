<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Juklak — which Part No is represented by which Main Part No on each KAP
 * line; a part whose main is a different part counts as 0 in WIP Calc and
 * WIP Summary (see Juklak_model / Wip_calc_model::juklak_replaced()).
 */
class Juklak extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Juklak_model');
    }

    public function index()
    {
        $data['title'] = 'Juklak';
        $data['table_ready'] = $this->Juklak_model->table_ready();
        $data['page_js'] = 'assets/js/juklak.js';
        $this->render('juklak/index', $data, 'juklak');
    }

    public function data()
    {
        $rows = array();
        if ($this->Juklak_model->table_ready()) {
            foreach ($this->Juklak_model->all() as $i => $r) {
                $qty = array();
                foreach ($r['suffix_qty'] as $suffix => $q) {
                    $qty[] = $suffix . ': ' . rtrim(rtrim(number_format((float) $q, 3, '.', ''), '0'), '.');
                }

                $rows[] = array(
                    'no'           => $i + 1,
                    'id'           => (int) $r['id'],
                    'plant'        => strtoupper($r['plant']),
                    'part_no'      => $r['part_no'],
                    'part_name'    => (string) $r['part_name'],
                    'main_part_no' => $r['main_part_no'],
                    'is_main'      => $r['is_main'],
                    'suffix_qty'   => implode(', ', $qty),
                );
            }
        }

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(array('status' => 'success', 'data' => $rows)));
    }

    public function template()
    {
        $this->Juklak_model->download_template();
    }

    public function export()
    {
        $this->Juklak_model->export_data();
    }

    public function upload()
    {
        $this->require_admin();

        if (!$this->Juklak_model->table_ready()) {
            set_flash('error', 'The juklak table does not exist yet — run database/migrations/2026_09_15_create_juklak.sql first.');
            redirect('juklak');
        }

        if (empty($_FILES['juklak_file']['name'])) {
            set_flash('error', 'Please choose an Excel file to upload.');
            redirect('juklak');
        }

        $config['upload_path']   = sys_get_temp_dir();
        $config['allowed_types'] = 'xlsx';
        $config['max_size']      = 20480; // 20MB
        $config['file_name']     = 'juklak_upload_' . time() . '_' . uniqid();

        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('juklak_file')) {
            set_flash('error', 'Upload failed: ' . strip_tags($this->upload->display_errors()));
            redirect('juklak');
        }

        $uploaded = $this->upload->data();
        $mode = $this->input->post('mode') === 'replace' ? 'replace' : 'append';

        $parsed = $this->Juklak_model->parse_excel($uploaded['full_path']);
        @unlink($uploaded['full_path']);

        if (!$parsed['ok']) {
            set_flash('error', $parsed['message']);
            redirect('juklak');
        }

        if ($mode === 'replace') {
            $this->Juklak_model->truncate();
        }

        $result = $this->Juklak_model->upsert_rows($parsed['rows'], $this->auth_user['id']);

        $notes = array();
        if ($parsed['skipped_plant'] > 0) {
            $notes[] = "{$parsed['skipped_plant']} row(s) skipped: Plant must be KAP1 or KAP2.";
        }
        if ($parsed['skipped_formula'] > 0) {
            $notes[] = "{$parsed['skipped_formula']} row(s) skipped: Part No / Main Part No still held a formula — paste as Values only and re-upload.";
        }
        if ($parsed['non_numeric'] > 0) {
            $notes[] = "{$parsed['non_numeric']} Qty cell(s) were not numbers and were ignored.";
        }
        if ($parsed['duplicates'] > 0) {
            $notes[] = "{$parsed['duplicates']} duplicate Plant + Part No row(s) — the last one in the file was kept.";
        }

        $message = "Juklak uploaded: {$result['inserted']} added, {$result['updated']} updated.";
        if ($notes) {
            $message .= ' ' . implode(' ', $notes);
        }

        set_flash($notes ? 'warning' : 'success', $message);
        redirect('juklak');
    }

    /** Delete one Juklak row (AJAX only). */
    public function delete($id)
    {
        $this->require_admin();

        $ok = $this->Juklak_model->delete($id);
        $this->output->set_content_type('application/json')->set_output(json_encode(array(
            'status' => $ok ? 'success' : 'error',
        )));
    }
}
