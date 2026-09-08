<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Dashboard extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Bom_model');
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

        $this->render('dashboard/index', $data, 'dashboard');
    }
}
