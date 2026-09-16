<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * "Part Special" — per KAP line, which shops a part number is counted in
 * (see Special_part_model). The rule replaces the Shop Code from Master BOM /
 * Part List in WIP Calc and WIP Summary.
 */
class Special_part extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Special_part_model');
    }

    public function index()
    {
        $data['title'] = 'Part Special';
        $data['table_ready'] = $this->Special_part_model->table_ready();
        $data['plants'] = $this->Special_part_model->plants;
        $data['shop_labels'] = $this->Special_part_model->shop_labels();
        $data['shop_keys'] = $this->Special_part_model->shop_keys('kap1');
        $data['shop_codes'] = array(
            'kap1' => $this->Special_part_model->shop_codes('kap1'),
            'kap2' => $this->Special_part_model->shop_codes('kap2'),
        );
        $data['page_js'] = 'assets/js/special_part.js';
        $this->render('special_part/index', $data, 'special_part');
    }

    public function data()
    {
        $labels = $this->Special_part_model->shop_labels();
        $plants = $this->Special_part_model->plants;

        $rows = array();
        foreach ($this->Special_part_model->all() as $i => $r) {
            $shop_list = $r['shop_list'];
            $start = $shop_list ? $shop_list[0] : null;

            $rows[] = array(
                'no'          => $i + 1,
                'id'          => (int) $r['id'],
                'plant'       => $plants[$r['plant']] ?? strtoupper($r['plant']),
                'plant_key'   => $r['plant'],
                'part_no'     => $r['part_no'],
                'part_number' => $r['part_number'],
                'shops'       => $shop_list,
                'shop_labels' => array_map(function ($s) use ($labels) {
                    return $labels[$s] ?? strtoupper($s);
                }, $shop_list),
                'start'       => $start,
                'start_label' => $start === null ? '' : ($labels[$start] ?? strtoupper($start)),
                'note'        => (string) $r['note'],
            );
        }

        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode(array('status' => 'success', 'data' => $rows)));
    }

    public function save()
    {
        $this->require_admin();

        $result = $this->Special_part_model->save(
            (int) $this->input->post('id'),
            (string) $this->input->post('plant'),
            (string) $this->input->post('part_number'),
            (array) $this->input->post('shops'),
            (string) $this->input->post('note'),
            $this->auth_user['id']
        );

        $this->output->set_content_type('application/json')->set_output(json_encode(array(
            'status'  => $result['ok'] ? 'success' : 'error',
            'message' => $result['message'],
        )));
    }

    public function delete($id)
    {
        $this->require_admin();

        $ok = $this->Special_part_model->delete($id);
        $this->output->set_content_type('application/json')->set_output(json_encode(array(
            'status' => $ok ? 'success' : 'error',
        )));
    }

    /** Part number suggestions for the form (AJAX). */
    public function suggest()
    {
        $this->output->set_content_type('application/json')->set_output(json_encode(array(
            'status' => 'success',
            'data'   => $this->Special_part_model->suggest((string) $this->input->get('q')),
        )));
    }
}
