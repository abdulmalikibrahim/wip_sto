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

    /**
     * These read only from the database cache — nothing here ever calls
     * the live WIP server. Use kap1_getwip()/kap2_getwip() for that.
     */
    public function kap1_data($shop)
    {
        $this->load->model('Wip_data_model');
        $this->respond($this->Wip_data_model->get_shop('kap1', $shop));
    }

    public function kap2_data($shop)
    {
        $this->load->model('Wip_data_model');
        $this->respond($this->Wip_data_model->get_shop('kap2', $shop));
    }

    public function kap1_export($shop)
    {
        $this->load->model('Wip_data_model');
        $result = $this->Wip_data_model->get_shop('kap1', $shop);
        $this->export($result, 'kap1', $shop);
    }

    public function kap2_export($shop)
    {
        $this->load->model('Wip_data_model');
        $result = $this->Wip_data_model->get_shop('kap2', $shop);
        $this->export($result, 'kap2', $shop);
    }

    /**
     * Clear the cached Master WIP data for one shop (AJAX only — the
     * per-shop "Clear" button next to each shop tab). Only that shop's
     * cached rows are removed; the other shops and the live WIP server are
     * untouched. WIP Calc totals for this shop fall back to 0 (no cached
     * units) until fresh data is pulled or uploaded again.
     */
    public function kap1_clear($shop)
    {
        $this->handle_clear_shop('kap1', $shop);
    }

    public function kap2_clear($shop)
    {
        $this->handle_clear_shop('kap2', $shop);
    }

    protected function handle_clear_shop($source, $shop)
    {
        $this->require_admin();

        $this->load->model('Wip_data_model');
        $shop_labels = $this->config->item('wip_shop_labels');
        if (!isset($shop_labels[$shop])) {
            $this->output->set_content_type('application/json')->set_output(json_encode(array(
                'status'  => 'error',
                'message' => 'Unknown Shop.',
            )));
            return;
        }

        $cleared = $this->Wip_data_model->clear_shop($source, $shop);
        $label = $shop_labels[$shop];

        $message = $cleared > 0
            ? "Cleared {$cleared} cached {$label} row(s). Pull or upload fresh data to bring it back."
            : "No cached {$label} data to clear.";

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(array(
                'status'  => 'success',
                'message' => $message,
                'cleared' => $cleared,
            )));
    }

    /**
     * "Get Data WIP": pull fresh data from the live WIP server for one shop,
     * replace that shop's cached rows in the database, then respond with the
     * refreshed cache. On failure the existing cached data is left untouched.
     */
    public function kap1_getwip($shop)
    {
        $this->fetch_and_cache('kap1', $shop, 'Wip_kap1_model');
    }

    public function kap2_getwip($shop)
    {
        $this->fetch_and_cache('kap2', $shop, 'Wip_kap2_model');
    }

    protected function fetch_and_cache($source, $shop, $live_model_name)
    {
        $this->load->model($live_model_name);
        $this->load->model('Wip_data_model');

        $live = $this->$live_model_name->get_shop($shop);

        $this->Wip_data_model->log(array(
            'source'     => $source,
            'shop'       => $shop,
            'origin'     => 'server',
            'mode'       => 'replace',
            'total_rows' => count($live['data']),
            'status'     => $live['ok'] ? 'success' : 'failed',
            'message'    => $live['message'],
        ));

        if (!$live['ok']) {
            $this->respond(array('ok' => false, 'message' => $live['message'], 'data' => array()));

            return;
        }

        $this->Wip_data_model->replace_shop($source, $shop, $live['data']);
        $this->respond($this->Wip_data_model->get_shop($source, $shop));
    }

    /**
     * "WIP Calc": Master BOM (grouped by part_number) with a computed
     * Welding/Toso/Assy usage column, based on the cached wip_data plus
     * each shop's cutoff VIN (see Wip_calc_model).
     */
    public function kap1_calc()
    {
        $data['title'] = 'WIP Calc - KAP 1';
        $data['source'] = 'wip/kap1';
        $data['shops'] = $this->config->item('wip_shop_labels');
        $data['shop_codes'] = $this->config->item('wip_calc_shop_codes')['kap1'] ?? array();
        $data['page_js'] = 'assets/js/wip_calc.js';
        $this->render('wip/calc', $data, 'wip_calc_kap1');
    }

    public function kap1_calc_data()
    {
        $this->load->model('Wip_calc_model');
        $this->respond_calc($this->Wip_calc_model->calc('kap1', $this->calc_basis()));
    }

    public function kap1_calc_template()
    {
        $this->load->model('Wip_calc_model');
        $this->Wip_calc_model->download_template('kap1', (string) $this->input->get('shop'), $this->calc_basis());
    }

    public function kap1_calc_export()
    {
        $this->load->model('Wip_calc_model');
        $hide_zero = $this->input->get('hide_zero') === '1';
        $shop_filter = (string) $this->input->get('shop_filter');
        $this->Wip_calc_model->export('kap1', 'WIP Calc - KAP 1', $hide_zero, $shop_filter, $this->calc_basis());
    }

    /**
     * "WIP Calc Detail": row-level breakdown behind the KAP1 Calc totals —
     * which cutoff VIN and how many matching WIP units produced each row's
     * contribution (see Wip_calc_model::calc_detail()).
     */
    public function kap1_calc_detail()
    {
        $data['title'] = 'WIP Calc Detail - KAP 1';
        $data['source'] = 'wip/kap1';
        $data['page_js'] = 'assets/js/wip_calc_detail.js';
        $this->render('wip/calc_detail', $data, 'wip_calc_kap1');
    }

    public function kap1_calc_detail_data()
    {
        $this->load->model('Wip_calc_model');
        $this->respond_calc_detail($this->Wip_calc_model->calc_detail('kap1', $this->calc_basis()));
    }

    public function kap1_calc_detail_export()
    {
        $this->load->model('Wip_calc_model');
        $this->Wip_calc_model->export_detail('kap1', 'WIP Calc Detail - KAP 1', $this->calc_basis());
    }

    /**
     * Full suffix breakdown for one part_number + shop (AJAX only — powers
     * the "Formula Detail" modal on the Detail page).
     */
    public function kap1_calc_detail_breakdown()
    {
        $this->load->model('Wip_calc_model');
        $result = $this->Wip_calc_model->part_breakdown(
            'kap1',
            (string) $this->input->get('shop_code'),
            (string) $this->input->get('part_number'),
            $this->calc_basis()
        );
        $this->output->set_content_type('application/json')->set_output(json_encode($result));
    }

    /**
     * The actual cached WIP units behind one suffix row's "Matching Units"
     * count in the Formula Detail modal (AJAX only) — lets a user spot-check
     * a count against their own VIN list, e.g. to catch a unit cached twice.
     */
    public function kap1_calc_detail_breakdown_vins()
    {
        $this->load->model('Wip_calc_model');
        $result = $this->Wip_calc_model->part_breakdown_vins(
            'kap1',
            (string) $this->input->get('shop_code'),
            (string) $this->input->get('part_number'),
            (string) $this->input->get('model'),
            (string) $this->input->get('suffix')
        );
        $this->output->set_content_type('application/json')->set_output(json_encode($result));
    }

    /**
     * Which table WIP Calc works from — the Master BOM (default) or the
     * Part List. Both describe the same model/suffix/qty usage, so either
     * can be the basis; the UI toggles it and passes ?basis= along.
     */
    protected function calc_basis()
    {
        return $this->input->get('basis') === 'part_list' ? 'part_list' : 'bom';
    }

    protected function respond_calc_detail(array $result)
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

    public function kap1_calc_upload()
    {
        $this->handle_calc_upload('kap1');
    }

    /**
     * Set the cutoff VIN for a single part, entered by hand (AJAX only —
     * see the "Add Cutoff VIN" modal on the WIP Calc page).
     */
    public function kap1_calc_cutoff_set()
    {
        $this->handle_calc_cutoff_set('kap1');
    }

    /**
     * Clear every cutoff VIN for one shop (Weld/Toso/Assy) at once (AJAX
     * only — the per-shop "Clear" button on the WIP Calc page).
     */
    public function kap1_calc_cutoff_clear()
    {
        $this->handle_calc_cutoff_clear('kap1');
    }

    /**
     * Set the same cutoff VIN for every part in one shop at once (AJAX only
     * — the "Apply to ALL parts in this shop" option in the "Add Cutoff
     * VIN" modal).
     */
    public function kap1_calc_cutoff_set_shop()
    {
        $this->handle_calc_cutoff_set_shop('kap1');
    }

    /**
     * "WIP Calc" / "WIP Calc Detail" — KAP2 mirror of the kap1_calc_* methods
     * above. Same views/JS (both are already parameterized by $source), same
     * Wip_calc_model methods, just called with 'kap2'.
     */
    public function kap2_calc()
    {
        $data['title'] = 'WIP Calc - KAP 2';
        $data['source'] = 'wip/kap2';
        $data['shops'] = $this->config->item('wip_shop_labels');
        $data['shop_codes'] = $this->config->item('wip_calc_shop_codes')['kap2'] ?? array();
        $data['page_js'] = 'assets/js/wip_calc.js';
        $this->render('wip/calc', $data, 'wip_calc_kap2');
    }

    public function kap2_calc_data()
    {
        $this->load->model('Wip_calc_model');
        $this->respond_calc($this->Wip_calc_model->calc('kap2', $this->calc_basis()));
    }

    public function kap2_calc_template()
    {
        $this->load->model('Wip_calc_model');
        $this->Wip_calc_model->download_template('kap2', (string) $this->input->get('shop'), $this->calc_basis());
    }

    public function kap2_calc_export()
    {
        $this->load->model('Wip_calc_model');
        $hide_zero = $this->input->get('hide_zero') === '1';
        $shop_filter = (string) $this->input->get('shop_filter');
        $this->Wip_calc_model->export('kap2', 'WIP Calc - KAP 2', $hide_zero, $shop_filter, $this->calc_basis());
    }

    public function kap2_calc_detail()
    {
        $data['title'] = 'WIP Calc Detail - KAP 2';
        $data['source'] = 'wip/kap2';
        $data['page_js'] = 'assets/js/wip_calc_detail.js';
        $this->render('wip/calc_detail', $data, 'wip_calc_kap2');
    }

    public function kap2_calc_detail_data()
    {
        $this->load->model('Wip_calc_model');
        $this->respond_calc_detail($this->Wip_calc_model->calc_detail('kap2', $this->calc_basis()));
    }

    public function kap2_calc_detail_export()
    {
        $this->load->model('Wip_calc_model');
        $this->Wip_calc_model->export_detail('kap2', 'WIP Calc Detail - KAP 2', $this->calc_basis());
    }

    public function kap2_calc_detail_breakdown()
    {
        $this->load->model('Wip_calc_model');
        $result = $this->Wip_calc_model->part_breakdown(
            'kap2',
            (string) $this->input->get('shop_code'),
            (string) $this->input->get('part_number'),
            $this->calc_basis()
        );
        $this->output->set_content_type('application/json')->set_output(json_encode($result));
    }

    public function kap2_calc_detail_breakdown_vins()
    {
        $this->load->model('Wip_calc_model');
        $result = $this->Wip_calc_model->part_breakdown_vins(
            'kap2',
            (string) $this->input->get('shop_code'),
            (string) $this->input->get('part_number'),
            (string) $this->input->get('model'),
            (string) $this->input->get('suffix')
        );
        $this->output->set_content_type('application/json')->set_output(json_encode($result));
    }

    public function kap2_calc_upload()
    {
        $this->handle_calc_upload('kap2');
    }

    public function kap2_calc_cutoff_set()
    {
        $this->handle_calc_cutoff_set('kap2');
    }

    public function kap2_calc_cutoff_clear()
    {
        $this->handle_calc_cutoff_clear('kap2');
    }

    public function kap2_calc_cutoff_set_shop()
    {
        $this->handle_calc_cutoff_set_shop('kap2');
    }

    /**
     * "WIP Calc. KAP 1 & 2" — KAP1's and KAP2's calc() lists shown together
     * (a plain union, not a re-aggregation), tagged with a Source column so
     * it's clear which line each row came from. Read-only: cutoff VINs are
     * still managed from the individual KAP1/KAP2 Calc pages.
     */
    public function calc_combined()
    {
        $data['title'] = 'WIP Calc - KAP 1 & 2';
        $data['shops'] = $this->config->item('wip_shop_labels');
        $data['page_js'] = 'assets/js/wip_calc_combined.js';
        $this->render('wip/calc_combined', $data, 'wip_calc_combined');
    }

    public function calc_combined_data()
    {
        $this->load->model('Wip_calc_model');
        $result = $this->Wip_calc_model->calc_combined($this->calc_basis());

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

    public function calc_combined_export()
    {
        $this->load->model('Wip_calc_model');
        $hide_zero = $this->input->get('hide_zero') === '1';
        $this->Wip_calc_model->export_combined('WIP Calc - KAP 1 & 2', $hide_zero, $this->calc_basis());
    }

    protected function handle_calc_cutoff_set($source)
    {
        $this->require_admin();

        $this->load->model('Wip_calc_model');
        $result = $this->Wip_calc_model->set_cutoff(
            $source,
            (string) $this->input->post('shop_code'),
            (string) $this->input->post('part_number'),
            (string) $this->input->post('vin'),
            $this->auth_user['id']
        );

        if ($result['ok']) {
            $this->Wip_calc_model->log(array(
                'source'       => $source,
                'file_name'    => 'Manual entry: ' . $result['part_number'] . ' (' . $result['shop_code'] . ')',
                'total_rows'   => 1,
                'applied_rows' => 1,
                'skipped_rows' => 0,
                'status'       => 'success',
                'message'      => $result['message'],
                'user_id'      => $this->auth_user['id'],
            ));
        }

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(array(
                'status'  => $result['ok'] ? 'success' : 'error',
                'message' => $result['message'],
            )));
    }

    /**
     * Clear all cutoff VINs for one shop within a KAP line (AJAX only —
     * see the "Clear" button on each shop card on the WIP Calc page).
     */
    protected function handle_calc_cutoff_clear($source)
    {
        $this->require_admin();

        $this->load->model('Wip_calc_model');
        $shop = (string) $this->input->post('shop');
        $result = $this->Wip_calc_model->clear_shop_cutoffs($source, $shop);

        if ($result['ok'] && $result['cleared'] > 0) {
            $this->Wip_calc_model->log(array(
                'source'       => $source,
                'file_name'    => 'Clear cutoff: ' . ($result['shop_code'] ?? $shop),
                'total_rows'   => $result['cleared'],
                'applied_rows' => $result['cleared'],
                'skipped_rows' => 0,
                'status'       => 'success',
                'message'      => $result['message'],
                'user_id'      => $this->auth_user['id'],
            ));
        }

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(array(
                'status'  => $result['ok'] ? 'success' : 'error',
                'message' => $result['message'],
            )));
    }

    /**
     * Set the same cutoff VIN for every part in one shop within a KAP line
     * (AJAX only — the "Apply to ALL parts in this shop" option in the
     * "Add Cutoff VIN" modal).
     */
    protected function handle_calc_cutoff_set_shop($source)
    {
        $this->require_admin();

        $this->load->model('Wip_calc_model');
        $shop = (string) $this->input->post('shop');
        $vin = (string) $this->input->post('vin');
        $result = $this->Wip_calc_model->set_shop_cutoff($source, $shop, $vin, $this->auth_user['id'], $this->calc_basis());

        if ($result['ok']) {
            $this->Wip_calc_model->log(array(
                'source'       => $source,
                'file_name'    => 'Manual entry (whole shop): ' . ($result['shop_code'] ?? $shop) . ' = ' . $vin,
                'total_rows'   => $result['applied'] ?? 0,
                'applied_rows' => $result['applied'] ?? 0,
                'skipped_rows' => 0,
                'status'       => 'success',
                'message'      => $result['message'],
                'user_id'      => $this->auth_user['id'],
            ));
        }

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(array(
                'status'  => $result['ok'] ? 'success' : 'error',
                'message' => $result['message'],
            )));
    }

    protected function handle_calc_upload($source)
    {
        $this->require_admin();

        if (empty($_FILES['cutoff_file']['name'])) {
            set_flash('error', 'Please choose an Excel file to upload.');
            redirect('wip/' . $source . '/calc');
        }

        $config['upload_path']   = sys_get_temp_dir();
        $config['allowed_types'] = 'xlsx';
        $config['max_size']      = 20480; // 20MB
        $config['file_name']     = 'wip_calc_upload_' . time() . '_' . uniqid();

        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('cutoff_file')) {
            set_flash('error', 'Upload failed: ' . strip_tags($this->upload->display_errors()));
            redirect('wip/' . $source . '/calc');
        }

        $uploaded = $this->upload->data();

        $this->load->model('Wip_calc_model');
        $parsed = $this->Wip_calc_model->parse_excel($uploaded['full_path']);

        if (!$parsed['ok']) {
            @unlink($uploaded['full_path']);
            $this->Wip_calc_model->log(array(
                'source'    => $source,
                'file_name' => $uploaded['client_name'],
                'status'    => 'failed',
                'message'   => $parsed['message'],
                'user_id'   => $this->auth_user['id'],
            ));
            set_flash('error', $parsed['message']);
            redirect('wip/' . $source . '/calc');
        }

        $result = $this->Wip_calc_model->upsert_cutoffs($source, $parsed['rows'], $this->auth_user['id']);
        @unlink($uploaded['full_path']);

        $formula_cells = $parsed['formula_cells'] ?? 0;
        $skipped_total = $parsed['skipped'] + $result['not_found'] + $result['wrong_source'] + $formula_cells;

        $message = "Applied cutoff VIN for {$result['applied']} part(s)";
        if ($skipped_total > 0) {
            $message .= ", skipped {$skipped_total} row(s)";
            $reasons = array();
            if ($formula_cells > 0) {
                $reasons[] = "{$formula_cells} VIN cell still had a formula (e.g. VLOOKUP) instead of a plain value — copy/paste as Values only, then re-upload";
            }
            if ($result['not_found'] > 0) {
                $reasons[] = "{$result['not_found']} VIN not found in the cached WIP data";
            }
            if ($result['wrong_source'] > 0) {
                $reasons[] = "{$result['wrong_source']} Shop not part of this KAP line";
            }
            if ($reasons) {
                $message .= ' (' . implode(', ', $reasons) . ')';
            }
        }
        $message .= '.';

        $this->Wip_calc_model->log(array(
            'source'       => $source,
            'file_name'    => $uploaded['client_name'],
            'total_rows'   => count($parsed['rows']),
            'applied_rows' => $result['applied'],
            'skipped_rows' => $skipped_total,
            'status'       => 'success',
            'message'      => $message,
            'user_id'      => $this->auth_user['id'],
        ));

        set_flash('success', 'Cutoff VIN uploaded: ' . $message);
        redirect('wip/' . $source . '/calc');
    }

    protected function respond_calc(array $result)
    {
        $rows = array();
        foreach ($result['data'] as $i => $r) {
            $rows[] = array_merge(array('no' => $i + 1), $r);
        }

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(array(
                'status'         => $result['ok'] ? 'success' : 'error',
                'message'        => $result['message'],
                'cutoff_summary' => $result['cutoff_summary'],
                'data'           => $rows,
            )));
    }

    /**
     * Download the Excel upload template.
     */
    public function kap1_template()
    {
        $this->load->model('Wip_data_model');
        $this->Wip_data_model->download_template('kap1');
    }

    public function kap2_template()
    {
        $this->load->model('Wip_data_model');
        $this->Wip_data_model->download_template('kap2');
    }

    /**
     * Handle the Excel upload (append or replace existing cached data) —
     * the fallback path for when the WIP server is unreachable.
     */
    public function kap1_upload()
    {
        $this->handle_upload('kap1');
    }

    public function kap2_upload()
    {
        $this->handle_upload('kap2');
    }

    protected function handle_upload($source)
    {
        $this->require_admin();

        if (empty($_FILES['wip_file']['name'])) {
            set_flash('error', 'Please choose an Excel file to upload.');
            redirect('wip/' . $source);
        }

        $config['upload_path']   = sys_get_temp_dir();
        $config['allowed_types'] = 'xlsx';
        $config['max_size']      = 20480; // 20MB
        $config['file_name']     = 'wip_upload_' . time() . '_' . uniqid();

        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('wip_file')) {
            set_flash('error', 'Upload failed: ' . strip_tags($this->upload->display_errors()));
            redirect('wip/' . $source);
        }

        $uploaded = $this->upload->data();
        $mode = $this->input->post('mode') === 'replace' ? 'replace' : 'append';

        $this->load->model('Wip_data_model');
        $parsed = $this->Wip_data_model->parse_excel($uploaded['full_path']);

        if (!$parsed['ok']) {
            @unlink($uploaded['full_path']);
            $this->Wip_data_model->log(array(
                'source'    => $source,
                'origin'    => 'upload',
                'file_name' => $uploaded['client_name'],
                'mode'      => $mode,
                'status'    => 'failed',
                'message'   => $parsed['message'],
                'user_id'   => $this->auth_user['id'],
            ));
            set_flash('error', $parsed['message']);
            redirect('wip/' . $source);
        }

        if ($mode === 'replace') {
            $this->Wip_data_model->truncate_source($source);
        }

        $result = $this->Wip_data_model->insert_rows($source, $parsed['rows']);
        @unlink($uploaded['full_path']);

        $message = "Inserted {$result['inserted']} rows";
        if ($parsed['skipped'] > 0) {
            $message .= ", skipped {$parsed['skipped']} row(s) with an unrecognized Shop Code";
        }
        $message .= '.';

        $this->Wip_data_model->log(array(
            'source'     => $source,
            'origin'     => 'upload',
            'file_name'  => $uploaded['client_name'],
            'mode'       => $mode,
            'total_rows' => count($parsed['rows']),
            'status'     => 'success',
            'message'    => $message,
            'user_id'    => $this->auth_user['id'],
        ));

        set_flash('success', 'WIP data uploaded: ' . $message);
        redirect('wip/' . $source);
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

        $headers = array('No', 'Sequence', 'VIN', 'Suffix', 'Katashiki', 'Model', 'Shop Code');

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
                $row['seq'],
                $row['vin'],
                $row['sfx'],
                $row['katashiki'],
                $row['modelcode'],
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
                'status'     => $result['ok'] ? 'success' : 'error',
                'message'    => $result['message'],
                'data'       => $rows,
                'updated_at' => $result['updated_at'] ?? null,
            )));
    }
}
