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
        $this->form_validation->set_rules('role', 'Role', 'required|in_list[admin,user]');

        if ($this->form_validation->run() === FALSE) {
            $this->json_error(strip_tags(validation_errors()));

            return;
        }

        $username = $this->input->post('username', TRUE);
        if ($this->User_model->username_exists($username)) {
            $this->json_error('Username already exists.');

            return;
        }

        $id = $this->User_model->create(array(
            'username'  => $username,
            'password'  => password_hash($this->input->post('password'), PASSWORD_DEFAULT),
            'full_name' => $this->input->post('full_name', TRUE),
            'role'      => $this->input->post('role'),
            'is_active' => $this->input->post('is_active') ? 1 : 0,
        ));

        $this->json_success('Account created.', array('id' => $id));
    }

    public function update($id)
    {
        $this->form_validation->set_rules('full_name', 'Full Name', 'required|trim');
        $this->form_validation->set_rules('role', 'Role', 'required|in_list[admin,user]');

        if ($this->form_validation->run() === FALSE) {
            $this->json_error(strip_tags(validation_errors()));

            return;
        }

        $user = $this->User_model->get($id);
        if (!$user) {
            $this->json_error('Account not found.');

            return;
        }

        $data = array(
            'full_name' => $this->input->post('full_name', TRUE),
            'role'      => $this->input->post('role'),
            'is_active' => $this->input->post('is_active') ? 1 : 0,
        );

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
