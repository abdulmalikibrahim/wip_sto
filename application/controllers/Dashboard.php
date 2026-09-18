<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Dashboard extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Bom_model');
        // Same scope as the Master BOM page, so the Dashboard's BOM total
        // matches what a scoped User sees there.
        if ($this->is_operator()) {
            $this->Bom_model->set_shop_scope($this->auth_user['shop_codes']);
        }
    }

    public function index()
    {
        $data['title'] = 'Dashboard';
        $data['total_bom'] = $this->Bom_model->count_all();

        if ($this->auth_user['role'] === 'admin') {
            $this->load->model('User_model');
            $data['total_users'] = $this->User_model->count_all();
        } else {
            $data['total_users'] = null;
        }

        $data['recent_uploads'] = $this->db
            ->order_by('id', 'desc')
            ->limit(5)
            ->get('bom_upload_log')
            ->result_array();

        // "How WIP Summary is made" guide: each Summary card and the unit
        // shops it counts on, straight from the model so it can't drift.
        $this->load->model('Wip_calc_model');
        $data['summary_stages'] = $this->Wip_calc_model->summary_stages();
        $data['summary_labels'] = $this->Wip_calc_model->summary_labels();
        $data['page_js'] = 'assets/js/dashboard.js';

        $this->render('dashboard/index', $data, 'dashboard');
    }
}
