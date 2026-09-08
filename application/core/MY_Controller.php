<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Base controller for every page that requires an authenticated user.
 * Public pages (login) extend CI_Controller directly.
 */
class MY_Controller extends CI_Controller
{
    /** @var array current logged in user row (without password) */
    protected $auth_user = null;

    public function __construct()
    {
        parent::__construct();

        if (!$this->session->userdata('logged_in')) {
            if ($this->input->is_ajax_request()) {
                $this->output
                    ->set_status_header(401)
                    ->set_content_type('application/json')
                    ->set_output(json_encode(array('status' => 'error', 'message' => 'Session expired, please login again.')));
                exit;
            }
            redirect('login');
        }

        $this->auth_user = array(
            'id'        => $this->session->userdata('user_id'),
            'username'  => $this->session->userdata('username'),
            'full_name' => $this->session->userdata('full_name'),
            'role'      => $this->session->userdata('role'),
        );

        $this->data['auth_user'] = $this->auth_user;
    }

    /**
     * Restrict an action to admin only. Call at the top of a controller method.
     */
    protected function require_admin()
    {
        if ($this->auth_user['role'] !== 'admin') {
            if ($this->input->is_ajax_request()) {
                $this->output
                    ->set_status_header(403)
                    ->set_content_type('application/json')
                    ->set_output(json_encode(array('status' => 'error', 'message' => 'Forbidden.')));
                exit;
            }
            show_error('You do not have permission to access this page.', 403, 'Forbidden');
        }
    }

    /**
     * Render a page inside the main dark-mode layout (header + sidebar + footer).
     *
     * @param string $view       path of the view inside application/views
     * @param array  $data       data passed to the view
     * @param string $active     active sidebar menu key, used to highlight the menu
     */
    protected function render($view, $data = array(), $active = '')
    {
        $data = array_merge($this->data, $data);
        $data['active_menu'] = $active;
        $data['flash'] = get_flash();

        $this->load->view('layout/header', $data);
        $this->load->view('layout/sidebar', $data);
        $this->load->view($view, $data);
        $this->load->view('layout/footer', $data);
    }
}
