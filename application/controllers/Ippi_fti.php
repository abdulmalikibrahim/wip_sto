<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * "WIP Calc. IPI" / "WIP Calc. FTI" — each an uploaded list of welding parts
 * counted against its own WIP WOS list (WOS IPI / WOS FTI) with its own
 * cutoff VINs (see Ippi_fti_model). Every action takes the list type
 * (ipi|fti) from the route. Standalone: WIP Calc KAP 1/2 and WIP Summary
 * aren't affected.
 */
class Ippi_fti extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Ippi_fti_model');
    }

    public function index($type = 'ipi')
    {
        $this->use_type($type);

        $data['title'] = 'WIP Calc. ' . $this->Ippi_fti_model->type_label();
        $data['type_label'] = $this->Ippi_fti_model->type_label();
        $data['wos_code'] = $this->Ippi_fti_model->wos_code();
        $data['base'] = 'wip/calc-' . $type;
        $data['wos_url'] = 'wip/wos-' . $type;
        $data['table_ready'] = $this->Ippi_fti_model->table_ready();
        $data['lines'] = array('kap1' => 'KAP 1', 'kap2' => 'KAP 2');
        $data['page_js'] = 'assets/js/ippi_fti.js';
        $this->render('ippi_fti/index', $data, 'calc_' . $type);
    }

    public function data($type)
    {
        $this->use_type($type);

        if (!$this->Ippi_fti_model->table_ready()) {
            $this->json(array('status' => 'error', 'message' => $this->not_ready_message(), 'data' => array()));

            return;
        }

        $result = $this->Ippi_fti_model->calc_parts($this->line(), $this->basis());

        $rows = array();
        foreach ($result['data'] as $i => $r) {
            $rows[] = array_merge(array('no' => $i + 1), $r);
        }

        $this->json(array(
            'status'  => $result['ok'] ? 'success' : 'error',
            'message' => $result['message'],
            'summary' => $result['summary'],
            'data'    => $rows,
        ));
    }

    /** Suffix breakdown of one part's Net (AJAX — the Formula modal). */
    public function breakdown($type)
    {
        $this->use_type($type);
        $this->json($this->Ippi_fti_model->part_suffixes(
            $this->line(),
            (string) $this->input->get('part_number'),
            $this->basis()
        ));
    }

    public function export($type)
    {
        $this->use_type($type);
        $this->Ippi_fti_model->export_calc($this->line(), $this->basis(), $this->input->get('hide_zero') === '1');
    }

    public function template($type)
    {
        $this->use_type($type);
        $this->Ippi_fti_model->list_template();
    }

    public function export_list($type)
    {
        $this->use_type($type);
        $this->Ippi_fti_model->list_export();
    }

    public function upload($type)
    {
        $this->require_admin();
        $this->use_type($type);
        $back = 'wip/calc-' . $type;
        $label = $this->Ippi_fti_model->type_label();

        if (!$this->Ippi_fti_model->table_ready()) {
            set_flash('error', $this->not_ready_message());
            redirect($back);
        }

        if (empty($_FILES['ippi_fti_file']['name'])) {
            set_flash('error', 'Please choose an Excel file to upload.');
            redirect($back);
        }

        $config['upload_path']   = sys_get_temp_dir();
        $config['allowed_types'] = 'xlsx';
        $config['max_size']      = 20480; // 20MB
        $config['file_name']     = 'calc_' . $type . '_upload_' . time() . '_' . uniqid();

        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('ippi_fti_file')) {
            set_flash('error', 'Upload failed: ' . strip_tags($this->upload->display_errors()));
            redirect($back);
        }

        $uploaded = $this->upload->data();
        $mode = $this->input->post('mode') === 'replace' ? 'replace' : 'append';

        $parsed = $this->Ippi_fti_model->parse_list($uploaded['full_path']);
        @unlink($uploaded['full_path']);

        if (!$parsed['ok']) {
            set_flash('error', $parsed['message']);
            redirect($back);
        }

        if ($mode === 'replace') {
            $this->Ippi_fti_model->truncate_list();
        }

        $result = $this->Ippi_fti_model->upsert_list($parsed['rows'], $this->auth_user['id']);
        $wos_code = $this->Ippi_fti_model->wos_code();

        $notes = array();
        if ($result['vin_not_found'] > 0) {
            $notes[] = "{$result['vin_not_found']} Cutoff VIN(s) not found in that line's {$wos_code} data were not applied — upload the {$wos_code} data first.";
        }
        if ($parsed['skipped_plant'] > 0) {
            $notes[] = "{$parsed['skipped_plant']} row(s) skipped: Plant must be KAP1 or KAP2.";
        }
        if ($parsed['skipped_formula'] > 0) {
            $notes[] = "{$parsed['skipped_formula']} row(s) skipped: a cell still held a formula — paste as Values only and re-upload.";
        }
        if ($parsed['duplicates'] > 0) {
            $notes[] = "{$parsed['duplicates']} duplicate Plant + Part No row(s) — the last one in the file was kept.";
        }

        $message = "{$label} part list uploaded: {$result['inserted']} added, {$result['updated']} updated, {$result['vin_set']} cutoff VIN(s) set.";
        if ($notes) {
            $message .= ' ' . implode(' ', $notes);
        }

        set_flash($notes ? 'warning' : 'success', $message);
        redirect($back);
    }

    /** Set or clear one part's cutoff VIN (AJAX). */
    public function cutoff($type)
    {
        $this->require_admin();
        $this->use_type($type);

        $result = $this->Ippi_fti_model->set_part_cutoff(
            (int) $this->input->post('id'),
            (string) $this->input->post('vin'),
            $this->auth_user['id']
        );

        $this->json(array('status' => $result['ok'] ? 'success' : 'error', 'message' => $result['message']));
    }

    /** Remove one part from the list (AJAX). */
    public function delete($type, $id)
    {
        $this->require_admin();
        $this->use_type($type);

        $ok = $this->Ippi_fti_model->delete_part($id);
        $this->json(array('status' => $ok ? 'success' : 'error'));
    }

    /** Point the model at the list from the route (ipi|fti); anything else is a 404. */
    protected function use_type($type)
    {
        if (!$this->Ippi_fti_model->set_type((string) $type)) {
            show_404();
        }
    }

    protected function not_ready_message()
    {
        return 'The ippi_fti table is not ready — run database/migrations/2026_09_15_create_ippi_fti.sql and 2026_09_15_ippi_fti_split_ipi_fti.sql first.';
    }

    /** KAP line in scope: ?line=kap1|kap2 (default KAP 1). */
    protected function line()
    {
        return $this->input->get('line') === 'kap2' ? 'kap2' : 'kap1';
    }

    protected function basis()
    {
        return $this->input->get('basis') === 'part_list' ? 'part_list' : 'bom';
    }

    protected function json(array $payload)
    {
        $this->output->set_content_type('application/json')->set_output(json_encode($payload));
    }
}
