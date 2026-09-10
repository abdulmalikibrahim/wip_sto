<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_model extends CI_Model
{
    protected $table = 'users';

    public function __construct()
    {
        parent::__construct();
    }

    public function find_by_username($username)
    {
        return $this->db->get_where($this->table, array('username' => $username))->row_array();
    }

    public function get($id)
    {
        return $this->db->get_where($this->table, array('id' => $id))->row_array();
    }

    public function update_last_login($id)
    {
        $this->db->where('id', $id)->update($this->table, array('last_login' => date('Y-m-d H:i:s')));
    }

    /**
     * DataTables server-side listing.
     */
    public function datatable($request)
    {
        $columns = array('id', 'username', 'full_name', 'role', 'is_active', 'last_login');

        $this->db->from($this->table);

        $search = $request['search']['value'] ?? '';
        if ($search !== '') {
            $this->db->group_start();
            $this->db->like('username', $search);
            $this->db->or_like('full_name', $search);
            $this->db->or_like('role', $search);
            $this->db->group_end();
        }
        $total_filtered = $this->db->count_all_results('', false);

        if (!empty($request['order'])) {
            $order = $request['order'][0];
            $col = $columns[$order['column']] ?? 'id';
            $this->db->order_by($col, $order['dir']);
        } else {
            $this->db->order_by('id', 'desc');
        }

        if ((int) ($request['length'] ?? -1) !== -1) {
            $this->db->limit((int) $request['length'], (int) $request['start']);
        }

        $data = $this->db->get()->result_array();

        return array(
            'data'    => $data,
            'filtered'=> $total_filtered,
            'total'   => $this->db->count_all($this->table),
        );
    }

    public function username_exists($username, $except_id = null)
    {
        $this->db->where('username', $username);
        if ($except_id) {
            $this->db->where('id !=', $except_id);
        }

        return $this->db->count_all_results($this->table) > 0;
    }

    public function create($data)
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $this->db->insert($this->table, $data);

        return $this->db->insert_id();
    }

    public function update($id, $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->db->where('id', $id)->update($this->table, $data);
    }

    public function delete($id)
    {
        return $this->db->where('id', $id)->delete($this->table);
    }

    public function count_all()
    {
        return $this->db->count_all($this->table);
    }
}
