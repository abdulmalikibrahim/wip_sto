<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('User_model');
    }

    public function login()
    {
        // Already logged in? go straight to dashboard.
        if ($this->session->userdata('logged_in')) {
            redirect('dashboard');
        }

        if ($this->input->method() === 'post') {
            $this->form_validation->set_rules('username', 'Username', 'required|trim');
            $this->form_validation->set_rules('password', 'Password', 'required');

            if ($this->form_validation->run() === TRUE) {
                $username = $this->input->post('username', TRUE);
                $password = $this->input->post('password');

                $user = $this->User_model->find_by_username($username);

                if ($user && password_verify($password, $user['password'])) {
                    if ((int) $user['is_active'] === 0) {
                        set_flash('error', 'Your account has been deactivated. Please contact the administrator.');
                        redirect('login');
                    }

                    $this->session->set_userdata(array(
                        'logged_in' => TRUE,
                        'user_id'   => $user['id'],
                        'username'  => $user['username'],
                        'full_name' => $user['full_name'],
                        'role'      => $user['role'],
                    ));
                    $this->User_model->update_last_login($user['id']);

                    redirect('dashboard');
                }

                set_flash('error', 'Invalid username or password.');
                redirect('login');
            }
        }

        $data['title'] = 'Login';
        $this->load->view('auth/login', $data);
    }

    public function logout()
    {
        $this->session->sess_destroy();
        redirect('login');
    }
}
