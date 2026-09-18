<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Akun extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_admin();
        $this->load->model('User_model');
    }

    public function index()
    {
        $data['title'] = 'Akun';
        // Drives the Plant / Shop Code pickers, so the form can never offer a
        // shop that does not exist in wip_calc_shop_codes.
        $data['shop_code_map'] = $this->shop_code_map();
        $data['page_js'] = 'assets/js/akun.js';
        $this->render('akun/index', $data, 'akun');
    }

    public function data()
    {
        $request = $this->input->post() ?: $this->input->get();
        $result = $this->User_model->datatable($request);

        $rows = array();
        $start = (int) ($request['start'] ?? 0);
        foreach ($result['data'] as $i => $r) {
            $rows[] = array(
                'no'         => $start + $i + 1,
                'id'         => $r['id'],
                'username'   => $r['username'],
                'full_name'  => $r['full_name'],
                'role'       => $r['role'],
                'plant'      => $r['plant'],
                'shop_codes' => $r['shop_codes'],
                'is_active'  => (int) $r['is_active'],
                'last_login' => $r['last_login'],
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

    public function create()
    {
        $this->form_validation->set_rules('username', 'Username', 'required|trim|min_length[3]|max_length[50]');
        $this->form_validation->set_rules('full_name', 'Full Name', 'required|trim');
        $this->form_validation->set_rules('password', 'Password', 'required|min_length[6]');
        $this->form_validation->set_rules('role', 'Role', 'required|in_list[admin,viewer,user,editor]');

        if ($this->form_validation->run() === FALSE) {
            $this->json_error(strip_tags(validation_errors()));

            return;
        }

        $username = $this->input->post('username', TRUE);
        if ($this->User_model->username_exists($username)) {
            $this->json_error('Username already exists.');

            return;
        }

        $scope = $this->resolve_scope($this->input->post('role'));
        if (isset($scope['error'])) {
            $this->json_error($scope['error']);

            return;
        }

        $id = $this->User_model->create(array_merge(array(
            'username'  => $username,
            'password'  => password_hash($this->input->post('password'), PASSWORD_DEFAULT),
            'full_name' => $this->input->post('full_name', TRUE),
            'role'      => $this->input->post('role'),
            'is_active' => $this->input->post('is_active') ? 1 : 0,
        ), $scope));

        $this->json_success('Account created.', array('id' => $id));
    }

    public function update($id)
    {
        $this->form_validation->set_rules('full_name', 'Full Name', 'required|trim');
        $this->form_validation->set_rules('role', 'Role', 'required|in_list[admin,viewer,user,editor]');

        if ($this->form_validation->run() === FALSE) {
            $this->json_error(strip_tags(validation_errors()));

            return;
        }

        $user = $this->User_model->get($id);
        if (!$user) {
            $this->json_error('Account not found.');

            return;
        }

        $scope = $this->resolve_scope($this->input->post('role'));
        if (isset($scope['error'])) {
            $this->json_error($scope['error']);

            return;
        }

        // Demoting yourself out of admin would lock you out of this very page.
        if ((int) $id === (int) $this->auth_user['id'] && $this->input->post('role') !== 'admin') {
            $this->json_error('You cannot change your own role away from Admin.');

            return;
        }

        $data = array_merge(array(
            'full_name' => $this->input->post('full_name', TRUE),
            'role'      => $this->input->post('role'),
            'is_active' => $this->input->post('is_active') ? 1 : 0,
        ), $scope);

        $password = $this->input->post('password');
        if (!empty($password)) {
            if (strlen($password) < 6) {
                $this->json_error('Password must be at least 6 characters.');

                return;
            }
            $data['password'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $this->User_model->update($id, $data);
        $this->json_success('Account updated.');
    }

    public function delete($id)
    {
        if ((int) $id === (int) $this->auth_user['id']) {
            $this->json_error('You cannot delete your own account.');

            return;
        }

        $this->User_model->delete($id);
        $this->json_success('Account deleted.');
    }

    /**
     * Build the plant/shop_codes pair to store for the submitted role.
     *
     * Only role 'user' (the scoped operator) carries a scope; every other
     * role (admin, viewer, editor) is stored with it cleared, so a demoted account can
     * never keep stale shop access. Every submitted shop code is checked
     * against the chosen plant, so the form cannot grant e.g. WELD4 to a
     * KAP 1 account even if the POST is hand-crafted.
     *
     * @return array {plant, shop_codes} to merge into the row, or {error}
     */
    protected function resolve_scope($role)
    {
        if ($role !== 'user') {
            return array('plant' => null, 'shop_codes' => '');
        }

        $plant = (string) $this->input->post('plant');
        $valid = $this->shop_code_map();
        if (!isset($valid[$plant])) {
            return array('error' => 'Choose a Plant (KAP 1 or KAP 2) for a User account.');
        }

        $allowed = array_map('strtoupper', array_values($valid[$plant]));
        $posted = $this->input->post('shop_codes');
        $codes = array();
        foreach ((array) $posted as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code === '') {
                continue;
            }
            if (!in_array($code, $allowed, true)) {
                return array('error' => $code . ' is not a shop of ' . strtoupper($plant) . '.');
            }
            $codes[] = $code;
        }
        $codes = array_values(array_unique($codes));

        if (empty($codes)) {
            return array('error' => 'Choose at least one Shop Code for a User account.');
        }

        return array('plant' => $plant, 'shop_codes' => implode(',', $codes));
    }

    protected function json_success($message, $extra = array())
    {
        $this->output->set_content_type('application/json')->set_output(json_encode(
            array_merge(array('status' => 'success', 'message' => $message), $extra)
        ));
    }

    protected function json_error($message)
    {
        $this->output->set_content_type('application/json')->set_output(json_encode(
            array('status' => 'error', 'message' => $message)
        ));
    }
}
