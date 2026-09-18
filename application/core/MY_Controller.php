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
            $this->unauthenticated('Session expired, please login again.');
        }

        // Re-read the account on every request rather than trusting what the
        // session cached at login: a role, shop scope or deactivation changed
        // on the Akun page then takes effect on the very next click, and no
        // session can keep permissions its account no longer has.
        $this->load->model('User_model');
        $user = $this->User_model->get($this->session->userdata('user_id'));
        if (!$user || (int) $user['is_active'] === 0) {
            $this->session->sess_destroy();
            $this->unauthenticated('Your account is no longer active. Please contact the administrator.');
        }

        $this->auth_user = array(
            'id'         => $user['id'],
            'username'   => $user['username'],
            'full_name'  => $user['full_name'],
            'role'       => $user['role'],
            'plant'      => $user['plant'] ?? null,
            'shop_codes' => $this->normalize_shop_codes($user['shop_codes'] ?? ''),
        );

        $this->data['auth_user'] = $this->auth_user;
        $this->data['can_edit_master'] = $this->can_edit_master();

        // An Editor may open Master BOM, Part List and Juklak only. Checked
        // here, before any controller runs, so every other page and AJAX
        // endpoint is refused — including ones added later.
        if ($this->is_editor()) {
            $class = strtolower($this->router->fetch_class());
            if (!in_array($class, $this->editor_controllers, true)) {
                if ($class === 'dashboard' && !$this->input->is_ajax_request()) {
                    redirect('bom'); // the landing page after login
                }
                $this->deny('Your account can only open Master BOM, Part List and Juklak.');
            }
        }
    }

    /** Controllers (router class, lowercase) an Editor may open. */
    protected $editor_controllers = array('bom', 'part_list', 'juklak');

    // -----------------------------------------------------------------
    // Roles
    //
    //   admin  — everything
    //   viewer — read-only, every page, no writes at all
    //   user   — scoped operator: cutoff VIN writes, but only inside its
    //            own plant (kap1|kap2) and its own shop codes
    //   editor — Master BOM, Part List and Juklak only, full edit there
    //
    // Every guard below answers with 403 the same way, so an AJAX caller
    // always gets JSON and a page load always gets the error page.
    // -----------------------------------------------------------------

    protected function is_admin()
    {
        return $this->auth_user['role'] === 'admin';
    }

    /** A scoped operator (role 'user') — NOT an admin. */
    protected function is_operator()
    {
        return $this->auth_user['role'] === 'user';
    }

    /** An Editor: Master BOM, Part List and Juklak only, with full edit rights there. */
    protected function is_editor()
    {
        return $this->auth_user['role'] === 'editor';
    }

    /** May add / edit / delete / upload Master BOM, Part List and Juklak data. */
    protected function can_edit_master()
    {
        return $this->is_admin() || $this->is_editor();
    }

    /** 403 unless this account may edit Master BOM, Part List and Juklak data. */
    protected function require_master_editor()
    {
        if (!$this->can_edit_master()) {
            $this->deny('You do not have permission to change this data.');
        }
    }

    /** Split a stored "WELD3,TOSO3" list into an uppercase array. */
    protected function normalize_shop_codes($raw)
    {
        $codes = array();
        foreach (explode(',', strtoupper((string) $raw)) as $code) {
            $code = trim($code);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /** Send 401 (AJAX) or bounce to the login page, and stop. */
    protected function unauthenticated($message)
    {
        if ($this->input->is_ajax_request()) {
            $this->output
                ->set_status_header(401)
                ->set_content_type('application/json')
                ->set_output(json_encode(array('status' => 'error', 'message' => $message)));
            $this->output->_display();
            exit;
        }
        redirect('login');
    }

    /**
     * Send 403 and stop. JSON for AJAX, error page otherwise.
     *
     * set_output() only buffers — CI echoes it from _display() *after* the
     * controller returns, which `exit` skips. So flush it explicitly, or
     * the client gets the status code with an empty body and no message.
     */
    protected function deny($message = 'Forbidden.')
    {
        if ($this->input->is_ajax_request()) {
            $this->output
                ->set_status_header(403)
                ->set_content_type('application/json')
                ->set_output(json_encode(array('status' => 'error', 'message' => $message)));
            $this->output->_display();
            exit;
        }
        show_error($message, 403, 'Forbidden');
    }

    /**
     * Restrict an action to admin only. Call at the top of a controller method.
     */
    protected function require_admin()
    {
        if (!$this->is_admin()) {
            $this->deny('You do not have permission to access this page.');
        }
    }

    /**
     * Restrict an action to accounts that may write cutoff VINs — admin, or
     * a scoped operator. Viewers are refused. This only settles *whether*
     * the account writes; require_plant()/require_shop_code() below settle
     * *where*, and a scoped operator needs all three.
     */
    protected function require_operator()
    {
        if (!$this->is_admin() && !$this->is_operator()) {
            $this->deny('Your account is read-only.');
        }
    }

    /**
     * wip_calc_shop_codes lives in the wip_api config, which is loaded per
     * controller rather than autoloaded — so load it on demand here. These
     * guards run from controllers that never touch WIP otherwise (Akun,
     * Part List), and CI skips a config file it has already loaded.
     */
    protected function shop_code_map()
    {
        $this->config->load('wip_api', FALSE, TRUE);

        return (array) $this->config->item('wip_calc_shop_codes');
    }

    /** Shop codes this account may write to. Admin => every code, all lines. */
    protected function allowed_shop_codes()
    {
        if ($this->is_admin()) {
            $all = array();
            foreach ($this->shop_code_map() as $shops) {
                foreach ($shops as $shop_code) {
                    $all[] = strtoupper($shop_code);
                }
            }

            return array_values(array_unique($all));
        }

        return $this->auth_user['shop_codes'];
    }

    /** True if this account may write to $shop_code (e.g. "WELD3"). */
    protected function can_use_shop_code($shop_code)
    {
        return in_array(strtoupper(trim((string) $shop_code)), $this->allowed_shop_codes(), true);
    }

    /** 403 unless this account may write to $shop_code. */
    protected function require_shop_code($shop_code)
    {
        if (!$this->can_use_shop_code($shop_code)) {
            $this->deny('Your account is not assigned to shop ' . strtoupper(trim((string) $shop_code)) . '.');
        }
    }

    /** True if this account may act on the given KAP line ('kap1'|'kap2'). */
    protected function can_use_plant($source)
    {
        return $this->is_admin() || $this->auth_user['plant'] === $source;
    }

    /** 403 unless this account belongs to the given KAP line. */
    protected function require_plant($source)
    {
        if (!$this->can_use_plant($source)) {
            $this->deny('Your account is not assigned to ' . strtoupper((string) $source) . '.');
        }
    }

    /**
     * The shop keys (weld|toso|assy|wos) of $source this account may write
     * to, as a lookup [key => true] the views use to gate per-shop buttons.
     */
    protected function writable_shops($source)
    {
        $shops = array();
        if (!$this->is_admin() && !$this->is_operator()) {
            return $shops; // viewer writes nothing
        }
        if (!$this->can_use_plant($source)) {
            return $shops;
        }
        foreach ((array) ($this->shop_code_map()[$source] ?? array()) as $key => $shop_code) {
            if ($this->can_use_shop_code($shop_code)) {
                $shops[$key] = true;
            }
        }

        return $shops;
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
