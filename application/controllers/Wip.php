<?php
defined('BASEPATH') OR exit('No direct script access allowed');

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
