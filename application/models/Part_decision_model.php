<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Keputusan per part per KAP line, diisi dari menu "Summary Tanpa Cutoff":
 * part-nya tetap dihitung, atau tidak dihitung beserta alasannya (mis. "belum
 * implementasi").
 *
 * 'counted' hanya penanda triase — perhitungannya tidak berubah. 'excluded'
 * membuat part itu dihitung 0 di WIP Calc dan WIP Summary untuk plant tersebut,
 * sama seperti part Juklak, dan alasannya ikut ditampilkan (lihat
 * Wip_calc_model::excluded_parts()).
 */
class Part_decision_model extends CI_Model
{
    protected $table = 'wip_part_decision';

    /** Plant key => label. */
    public $plants = array('kap1' => 'KAP 1', 'kap2' => 'KAP 2');

    /** Pilihan keputusan yang diterima. */
    public $decisions = array('counted', 'excluded');

    /** Whether database/migrations/2026_09_16_create_wip_part_decision.sql has been run. */
    public function table_ready()
    {
        return $this->db->table_exists($this->table);
    }

    /**
     * Keputusan satu line, di-index per part number (upper-case), untuk
     * ditempelkan ke daftar Tanpa Cutoff.
     *
     * @return array<string,array>
     */
    public function map($plant)
    {
        if (!$this->table_ready()) {
            return array();
        }

        $rows = $this->db->where('plant', $plant)->get($this->table)->result_array();

        $map = array();
        foreach ($rows as $row) {
            $part = strtoupper(trim((string) $row['part_number']));
            if ($part !== '') {
                $map[$part] = $row;
            }
        }

        return $map;
    }

    /**
     * Simpan keputusan satu part. Identitasnya (plant, part_number), jadi
     * menyimpan part yang sama lagi memperbarui keputusan sebelumnya.
     *
     * @return array{ok:bool, message:string}
     */
    public function save($plant, $part_no_raw, $decision, $reason, $user_id)
    {
        if (!$this->table_ready()) {
            return array('ok' => false, 'message' => 'Tabel wip_part_decision belum ada — jalankan migration-nya dulu.');
        }
        if (!isset($this->plants[$plant])) {
            return array('ok' => false, 'message' => 'Plant harus KAP 1 atau KAP 2.');
        }
        if (!in_array($decision, $this->decisions, true)) {
            return array('ok' => false, 'message' => 'Keputusan harus "dihitung" atau "tidak dihitung".');
        }

        $part_no = trim((string) $part_no_raw);
        if ($part_no === '') {
            return array('ok' => false, 'message' => 'Part Number wajib diisi.');
        }

        $reason = trim((string) $reason);
        if ($decision === 'excluded' && $reason === '') {
            return array('ok' => false, 'message' => 'Alasan wajib diisi kalau part-nya tidak dihitung.');
        }

        $part_number = strip_trailing_dash00($part_no);
        $now = date('Y-m-d H:i:s');
        $data = array(
            'part_no'     => $part_no,
            'part_number' => $part_number,
            'decision'    => $decision,
            'reason'      => $reason,
            'user_id'     => $user_id,
            'updated_at'  => $now,
        );

        $existing = $this->db->select('id')
            ->where(array('plant' => $plant, 'part_number' => $part_number))
            ->get($this->table)->row_array();

        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->update($this->table, $data);
        } else {
            $this->db->insert($this->table, array_merge($data, array('plant' => $plant, 'created_at' => $now)));
        }

        return array(
            'ok'      => true,
            'message' => $decision === 'excluded'
                ? 'Part ditandai tidak dihitung — angkanya jadi 0 di WIP Calc & Summary.'
                : 'Part ditandai tetap dihitung.',
        );
    }

    /** Hapus keputusan sebuah part, mengembalikannya ke perhitungan normal. */
    public function clear($plant, $part_no_raw)
    {
        if (!$this->table_ready()) {
            return false;
        }

        return $this->db
            ->where(array('plant' => $plant, 'part_number' => strip_trailing_dash00(trim((string) $part_no_raw))))
            ->delete($this->table);
    }
}
