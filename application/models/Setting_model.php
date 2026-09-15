<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * App-wide settings, one key/value row each in `app_setting` (see
 * database/migrations/2026_09_15_create_app_setting.sql). Right now that's
 * the STO activity date: once today is past it, "Get Data WIP" is locked so
 * the WIP snapshot taken for the stock opname stays as it was.
 */
class Setting_model extends CI_Model
{
    protected $table = 'app_setting';

    /** Plant local time — the STO date is a calendar date there, whatever the server clock says. */
    const TIMEZONE = 'Asia/Jakarta';

    public function table_ready()
    {
        return $this->db->table_exists($this->table);
    }

    public function get($key, $default = null)
    {
        if (!$this->table_ready()) {
            return $default;
        }

        $row = $this->db->select('svalue')->get_where($this->table, array('skey' => $key))->row_array();

        return ($row && $row['svalue'] !== null) ? $row['svalue'] : $default;
    }

    public function set($key, $value, $user_id)
    {
        return $this->db->replace($this->table, array(
            'skey'       => $key,
            'svalue'     => $value,
            'updated_by' => $user_id,
            'updated_at' => $this->now()->format('Y-m-d H:i:s'),
        ));
    }

    /** A real calendar date in Y-m-d form (rejects e.g. 2026-02-30). */
    public static function valid_date($value)
    {
        $value = (string) $value;
        $date = DateTime::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** The STO date as 'Y-m-d', or null when none is set (= no lock). */
    public function sto_date()
    {
        $value = (string) $this->get('sto_date', '');

        return self::valid_date($value) ? $value : null;
    }

    public function today()
    {
        return $this->now()->format('Y-m-d');
    }

    /** Locked once today is past the STO date; the STO day itself is still open. */
    public function getwip_locked()
    {
        $date = $this->sto_date();

        return $date !== null && $this->today() > $date;
    }

    /**
     * Everything the pages show about the STO date.
     *
     * @return array{date:?string, date_label:?string, today:string, today_label:string, locked:bool, days_left:?int}
     */
    public function sto_status()
    {
        $date = $this->sto_date();
        $today = $this->today();

        $days_left = null;
        if ($date !== null) {
            $days_left = (int) (new DateTime($today))->diff(new DateTime($date))->format('%r%a');
        }

        return array(
            'date'        => $date,
            'date_label'  => $date !== null ? date('d M Y', strtotime($date)) : null,
            'today'       => $today,
            'today_label' => date('d M Y', strtotime($today)),
            'locked'      => $date !== null && $today > $date,
            'days_left'   => $days_left,
        );
    }

    public function lock_message()
    {
        $status = $this->sto_status();

        return 'Get Data WIP dikunci: tanggal STO (' . $status['date_label'] . ') sudah lewat. Ubah di menu Setting.';
    }

    protected function now()
    {
        return new DateTime('now', new DateTimeZone(self::TIMEZONE));
    }
}
