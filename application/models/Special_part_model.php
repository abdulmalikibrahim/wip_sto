<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * "Part Special" — per KAP line, the shops a part number is counted in, set by
 * hand instead of being derived from the Shop Code in Master BOM / Part List.
 *
 * A rule lists the shops in line order (wos, weld, toso, assy). The first shop
 * is where the part is installed and is counted Net from that shop's cutoff VIN
 * (0 while none is set, every unit if it is stale); the shops after it count
 * every unit, since those already carry the part; shops left out count 0.
 *
 * e.g. 12345-BZ123 on KAP 1 with "weld,toso": Welding from its cutoff VIN, Toso
 * all units, Assy 0. Read back by Wip_calc_model::special_shops(), which applies
 * it in WIP Calc and WIP Summary.
 */
class Special_part_model extends CI_Model
{
    protected $table = 'wip_special_part';

    /** Plant cell (as posted) => KAP line key, as used by wip_data.source. */
    public $plants = array('kap1' => 'KAP 1', 'kap2' => 'KAP 2');

    public function __construct()
    {
        parent::__construct();
        $this->config->load('wip_api');
    }

    /** Whether database/migrations/2026_09_16_create_wip_special_part.sql has been run. */
    public function table_ready()
    {
        return $this->db->table_exists($this->table);
    }

    /** A line's shop keys in line order: wos -> weld -> toso -> assy. */
    public function shop_keys($plant = 'kap1')
    {
        return array_keys((array) ($this->config->item('wip_calc_shop_codes')[$plant] ?? array()));
    }

    public function shop_labels()
    {
        return (array) $this->config->item('wip_shop_labels');
    }

    /** Shop codes per line, so the UI can show "Welding (WELD3)". */
    public function shop_codes($plant = 'kap1')
    {
        return (array) ($this->config->item('wip_calc_shop_codes')[$plant] ?? array());
    }

    /**
     * The shop keys of a rule, validated and put back in line order — the
     * order is what decides which shop uses its cutoff VIN (the first one).
     */
    public function normalize_shops($plant, $shops)
    {
        $wanted = array_filter(array_map('trim', is_array($shops) ? $shops : explode(',', (string) $shops)));

        return array_values(array_intersect($this->shop_keys($plant), $wanted));
    }

    /** Every rule, newest line first, with its shop list decoded. */
    public function all()
    {
        if (!$this->table_ready()) {
            return array();
        }

        $rows = $this->db
            ->order_by('plant', 'asc')
            ->order_by('part_number', 'asc')
            ->get($this->table)->result_array();

        foreach ($rows as &$row) {
            $row['shop_list'] = $this->normalize_shops($row['plant'], $row['shops']);
        }
        unset($row);

        return $rows;
    }

    public function find($id)
    {
        if (!$this->table_ready()) {
            return null;
        }

        return $this->db->where('id', (int) $id)->get($this->table)->row_array() ?: null;
    }

    /**
     * Add or update one rule. A rule's identity is (plant, part_number), so
     * saving the same part for the same line again refreshes it instead of
     * creating a second row.
     *
     * @return array{ok:bool, message:string}
     */
    public function save($id, $plant, $part_no_raw, $shops, $note, $user_id)
    {
        if (!$this->table_ready()) {
            return array('ok' => false, 'message' => 'Tabel wip_special_part belum ada — jalankan migration-nya dulu.');
        }
        if (!isset($this->plants[$plant])) {
            return array('ok' => false, 'message' => 'Plant harus KAP 1 atau KAP 2.');
        }

        $part_no = trim((string) $part_no_raw);
        if ($part_no === '') {
            return array('ok' => false, 'message' => 'Part Number wajib diisi.');
        }

        $shop_list = $this->normalize_shops($plant, $shops);
        if (empty($shop_list)) {
            return array('ok' => false, 'message' => 'Pilih minimal satu shop yang dihitung.');
        }

        $part_number = strip_trailing_dash00($part_no);
        $now = date('Y-m-d H:i:s');
        $data = array(
            'part_no'     => $part_no,
            'part_number' => $part_number,
            'shops'       => implode(',', $shop_list),
            'note'        => trim((string) $note),
            'user_id'     => $user_id,
            'updated_at'  => $now,
        );

        $existing = $this->db->select('id')
            ->where(array('plant' => $plant, 'part_number' => $part_number))
            ->get($this->table)->row_array();

        if ((int) $id > 0) {
            if ($existing && (int) $existing['id'] !== (int) $id) {
                return array('ok' => false, 'message' => 'Part number ini sudah punya aturan di plant tersebut.');
            }
            $this->db->where('id', (int) $id)->update($this->table, array_merge($data, array('plant' => $plant)));

            return array('ok' => true, 'message' => 'Aturan part special diperbarui.');
        }

        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->update($this->table, $data);

            return array('ok' => true, 'message' => 'Part ini sudah punya aturan — aturannya diperbarui.');
        }

        $this->db->insert($this->table, array_merge($data, array('plant' => $plant, 'created_at' => $now)));

        return array('ok' => true, 'message' => 'Aturan part special ditambahkan.');
    }

    public function delete($id)
    {
        return $this->table_ready() ? $this->db->where('id', (int) $id)->delete($this->table) : false;
    }

    /**
     * Part numbers matching $term, for the form's datalist. Needs 3+ characters
     * — a shorter term would scan both master tables for nothing useful.
     */
    public function suggest($term, $limit = 20)
    {
        $term = trim((string) $term);
        if (strlen($term) < 3) {
            return array();
        }

        $like = $this->db->escape_like_str($term);
        $rows = $this->db->query(
            "SELECT part_number FROM (
                SELECT DISTINCT part_number FROM bom WHERE part_number LIKE '%{$like}%' LIMIT " . (int) $limit . "
                UNION
                SELECT DISTINCT part_number FROM part_list WHERE part_number LIKE '%{$like}%' LIMIT " . (int) $limit . "
            ) t WHERE part_number IS NOT NULL AND part_number <> '' ORDER BY part_number LIMIT " . (int) $limit
        )->result_array();

        return array_column($rows, 'part_number');
    }
}
