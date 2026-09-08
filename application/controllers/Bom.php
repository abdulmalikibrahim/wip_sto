<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Bom extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Bom_model');
    }

    public function index()
    {
        $data['title'] = 'Master BOM';
        $data['total_bom'] = $this->Bom_model->count_all();
        $data['page_js'] = 'assets/js/bom.js';
        $this->render('bom/index', $data, 'bom');
    }

    /**
     * DataTables server-side source.
     */
    public function data()
    {
        $request = $this->input->post() ?: $this->input->get();
        $result = $this->Bom_model->datatable($request);

        $rows = array();
        $start = (int) ($request['start'] ?? 0);
        foreach ($result['data'] as $i => $r) {
            $rows[] = array(
                'no'                   => $start + $i + 1,
                'id'                   => $r['id'],
                'material'             => $r['material'],
                'katashiki'            => $r['model'],
                'suffix'               => $r['suffix'],
                'component'            => $r['component'],
                'part_number'          => $r['part_number'],
                'material_description' => $r['material_description'],
                'qty'                  => rtrim(rtrim(number_format((float) $r['qty'], 3, '.', ''), '0'), '.'),
                'uom'                  => $r['uom'],
                'shop_code'            => $r['shop_code'],
            );
        }

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(array(
                'draw'            => (int) ($request['draw'] ?? 1),
                'recordsTotal'    => $result['total'],
                'recordsFiltered' => $result['filtered'],
                'data'            => $rows,
            )));
    }

    /**
     * Download the Excel upload template.
     */
    public function template()
    {
        $this->Bom_model->download_template();
    }

    /**
     * Handle the Excel upload (append or replace existing data).
     */
    public function upload()
    {
        $this->require_admin();

        if (empty($_FILES['bom_file']['name'])) {
            set_flash('error', 'Please choose an Excel file to upload.');
            redirect('bom');
        }

        $config['upload_path']   = sys_get_temp_dir();
        $config['allowed_types'] = 'xlsx';
        $config['max_size']      = 20480; // 20MB
        $config['file_name']     = 'bom_upload_' . time() . '_' . uniqid();

        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('bom_file')) {
            set_flash('error', 'Upload failed: ' . strip_tags($this->upload->display_errors()));
            redirect('bom');
        }

        $uploaded = $this->upload->data();
        $mode = $this->input->post('mode') === 'replace' ? 'replace' : 'append';

        $parsed = $this->Bom_model->parse_excel($uploaded['full_path']);

        if (!$parsed['ok']) {
            @unlink($uploaded['full_path']);
            $this->Bom_model->log_upload(array(
                'file_name' => $uploaded['client_name'],
                'mode'      => $mode,
                'status'    => 'failed',
                'message'   => $parsed['message'],
                'user_id'   => $this->auth_user['id'],
            ));
            set_flash('error', $parsed['message']);
            redirect('bom');
        }

        if ($mode === 'replace') {
            $this->Bom_model->truncate();
        }

        $result = $this->Bom_model->insert_rows($parsed['rows']);
        @unlink($uploaded['full_path']);

        $this->Bom_model->log_upload(array(
            'file_name'     => $uploaded['client_name'],
            'mode'          => $mode,
            'total_rows'    => count($parsed['rows']),
            'inserted_rows' => $result['inserted'],
            'skipped_rows'  => $result['skipped'],
            'status'        => 'success',
            'message'       => 'Uploaded successfully.',
            'user_id'       => $this->auth_user['id'],
        ));

        set_flash('success', "BOM uploaded: {$result['inserted']} rows inserted, {$result['skipped']} skipped.");
        redirect('bom');
    }

    public function delete($id)
    {
        $this->require_admin();

        if ($this->input->is_ajax_request()) {
            $ok = $this->Bom_model->delete($id);
            $this->output->set_content_type('application/json')->set_output(json_encode(array(
                'status' => $ok ? 'success' : 'error',
            )));

            return;
        }

        $this->Bom_model->delete($id);
        set_flash('success', 'BOM entry deleted.');
        redirect('bom');
    }
}
