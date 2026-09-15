<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Setting (admin only) — the STO activity date. Past that date "Get Data
 * WIP" is locked (see Setting_model::getwip_locked() and Wip::fetch_and_cache()).
 */
class Setting extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_admin();
        $this->load->model('Setting_model');
    }

    public function index()
    {
        $data['title'] = 'Setting';
        $data['table_ready'] = $this->Setting_model->table_ready();
        $data['sto'] = $this->Setting_model->sto_status();
        $this->render('setting/index', $data, 'setting');
    }

    public function save()
    {
        if (!$this->Setting_model->table_ready()) {
            set_flash('error', 'The app_setting table does not exist yet — run database/migrations/2026_09_15_create_app_setting.sql first.');
            redirect('setting');
        }

        $date = $this->input->post('action') === 'clear' ? '' : trim((string) $this->input->post('sto_date'));
        if ($date !== '' && !Setting_model::valid_date($date)) {
            set_flash('error', 'Tanggal STO tidak valid.');
            redirect('setting');
        }

        $this->Setting_model->set('sto_date', $date === '' ? null : $date, $this->auth_user['id']);

        set_flash('success', $date === ''
            ? 'Tanggal STO dihapus. Get Data WIP bisa dipakai kapan saja.'
            : 'Tanggal STO disimpan: ' . date('d M Y', strtotime($date)) . '. Get Data WIP dikunci mulai hari berikutnya.');
        redirect('setting');
    }
}
