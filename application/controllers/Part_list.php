<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Part_list extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Part_list_model');
    }

    public function index()
    {
        $data['title'] = 'Part List';
        $data['total_part_list'] = $this->Part_list_model->count_all();
        $data['model_summary'] = $this->Part_list_model->model_summary();
        $data['compare_summary'] = $this->Part_list_model->compare_summary();
        $data['page_js'] = 'assets/js/part_list.js';
        $this->render('part_list/index', $data, 'part_list');
    }

    /**
     * DataTables server-side source for the Part List's own data table.
     */
    public function data()
    {
        $request = $this->input->post() ?: $this->input->get();
        $result = $this->Part_list_model->datatable($request);

        $rows = array();
        $start = (int) ($request['start'] ?? 0);
        foreach ($result['data'] as $i => $r) {
            $rows[] = array(
                'no'                   => $start + $i + 1,
                'id'                   => $r['id'],
                'model'                => $r['model'],
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
     * DataTables server-side source for the Master BOM vs Part List diff table.
     */
    public function compare_data()
    {
        $request = $this->input->post() ?: $this->input->get();
        $result = $this->Part_list_model->compare_datatable($request);

        $status_labels = array(
            'only_bom'       => 'Only in Master BOM',
            'only_part_list' => 'Only in Part List',
            'mismatch'       => 'Different',
        );

        $rows = array();
        $start = (int) ($request['start'] ?? 0);
        foreach ($result['data'] as $i => $d) {
            $rows[] = array(
                'no'               => $start + $i + 1,
                'status'           => $d['status'],
                'status_label'     => $status_labels[$d['status']] ?? $d['status'],
                'model'            => $d['model'],
                'suffix'           => $d['suffix'],
                'component'        => $d['component'],
                'part_number'      => $d['part_number'],
                'field'            => $d['field'],
                'bom_value'        => $d['bom_value'],
                'part_list_value'  => $d['part_list_value'],
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
     * Download the Excel upload template (the pivot layout — see
     * Part_list_model::download_template()).
     */
    public function template()
    {
        $this->Part_list_model->download_template();
    }

    /**
     * Download the actual Part List data currently in the table.
     */
    public function export()
    {
        $this->Part_list_model->export_data($this->input->get('model_filter'));
    }

    /**
     * Download the Compare (Master BOM vs Part List) diff list as .xlsx,
     * honoring whichever Status filter / search text is applied on screen.
     */
    public function compare_export()
    {
        $this->Part_list_model->export_compare(
            (string) $this->input->get('status_filter'),
            (string) $this->input->get('model_filter'),
            (string) $this->input->get('search')
        );
    }

    /**
     * Handle the Excel upload (append or replace existing data).
     */
    public function upload()
    {
        $this->require_admin();

        if (empty($_FILES['part_list_file']['name'])) {
            set_flash('error', 'Please choose an Excel file to upload.');
            redirect('part-list');
        }

        $config['upload_path']   = sys_get_temp_dir();
        $config['allowed_types'] = 'xlsx';
        $config['max_size']      = 20480; // 20MB
        $config['file_name']     = 'part_list_upload_' . time() . '_' . uniqid();

        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('part_list_file')) {
            set_flash('error', 'Upload failed: ' . strip_tags($this->upload->display_errors()));
            redirect('part-list');
        }

        $uploaded = $this->upload->data();
        $mode = $this->input->post('mode') === 'replace' ? 'replace' : 'append';

        $parsed = $this->Part_list_model->parse_excel($uploaded['full_path']);

        if (!$parsed['ok']) {
            @unlink($uploaded['full_path']);
            $this->Part_list_model->log_upload(array(
                'file_name' => $uploaded['client_name'],
                'mode'      => $mode,
                'status'    => 'failed',
                'message'   => $parsed['message'],
                'user_id'   => $this->auth_user['id'],
            ));
            set_flash('error', $parsed['message']);
            redirect('part-list');
        }

        if ($mode === 'replace') {
            $this->Part_list_model->truncate();
        }

        $result = $this->Part_list_model->insert_rows($parsed['rows']);
        @unlink($uploaded['full_path']);

        // Extra notes from parsing the pivot format — non-numeric cells
        // (e.g. "X") skipped, duplicate Part No+Suffix+Model rows
        // collapsed to their larger Qty, and rows whose Model column was
        // itself blank in the uploaded file.
        $notes = array();
        if (!empty($parsed['skipped_non_numeric'])) {
            $notes[] = "{$parsed['skipped_non_numeric']} cell(s) had a non-numeric value (e.g. \"X\") and were skipped — please check those by hand.";
        }
        if (!empty($parsed['duplicates_collapsed'])) {
            $notes[] = "{$parsed['duplicates_collapsed']} duplicate row(s) for the same Part No + Suffix were collapsed, keeping the larger Qty.";
        }
        if (!empty($parsed['blank_model_suffixes'])) {
            $count = count($parsed['blank_model_suffixes']);
            $sample = implode(', ', array_slice($parsed['blank_model_suffixes'], 0, 10));
            $more = $count > 10 ? ', ...' : '';
            $notes[] = "{$parsed['blank_model_cells']} cell(s) across {$count} Suffix column(s) had a blank Model in the uploaded file: {$sample}{$more}.";
        }

        $message = "Part List uploaded: {$result['inserted']} rows inserted, {$result['skipped']} skipped.";
        if ($notes) {
            $message .= ' ' . implode(' ', $notes);
        }

        $this->Part_list_model->log_upload(array(
            'file_name'     => $uploaded['client_name'],
            'mode'          => $mode,
            'total_rows'    => count($parsed['rows']),
            'inserted_rows' => $result['inserted'],
            'skipped_rows'  => $result['skipped'],
            'status'        => 'success',
            'message'       => $message,
            'user_id'       => $this->auth_user['id'],
        ));

        set_flash($notes ? 'warning' : 'success', $message);
        redirect('part-list');
    }

    public function delete($id)
    {
        $this->require_admin();

        if ($this->input->is_ajax_request()) {
            $ok = $this->Part_list_model->delete($id);
            $this->output->set_content_type('application/json')->set_output(json_encode(array(
                'status' => $ok ? 'success' : 'error',
            )));

            return;
        }

        $this->Part_list_model->delete($id);
        set_flash('success', 'Part List entry deleted.');
        redirect('part-list');
    }

    /**
     * Delete several rows at once (AJAX only — the "Delete Selected"
     * button next to the table's checkbox column).
     */
    public function delete_bulk()
    {
        $this->require_admin();

        $ids = $this->input->post('ids');
        $deleted = is_array($ids) ? $this->Part_list_model->delete_many($ids) : 0;

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(array(
                'status'  => 'success',
                'deleted' => $deleted,
            )));
    }
}
