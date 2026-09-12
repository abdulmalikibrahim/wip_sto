<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Data source for WIP KAP 1 (sv-web-kap / pisweb2.0).
 * See docs/get_data_wip_kap1.md for the raw endpoint documentation.
 */
class Wip_kap1_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->config->load('wip_api');
    }

    /**
     * Fetch and normalize one shop's WIP data (weld|toso|assy).
     *
     * @return array{ok:bool, message:string, data:array}
     */
    public function get_shop($shop)
    {
        $groups = $this->config->item('kap1_groups');
        if (!isset($groups[$shop])) {
            return array('ok' => false, 'message' => 'Unknown shop.', 'data' => array());
        }

        $shopcode = strtoupper($this->config->item('wip_shop_labels')[$shop] ?? $shop);
        $data = array();

        foreach ($groups[$shop] as $payload) {
            $res = $this->call($payload);
            if (!$res['ok']) {
                return array('ok' => false, 'message' => $res['message'], 'data' => array());
            }

            foreach ($res['records'] as $rec) {
                $data[] = $this->normalize($rec, $shopcode);
            }
        }

        return array('ok' => true, 'message' => 'ok', 'data' => $data);
    }

    /**
     * Fetch all shops at once (used by dashboard summary).
     */
    public function get_all()
    {
        $all = array();
        foreach (array_keys($this->config->item('kap1_groups')) as $shop) {
            $res = $this->get_shop($shop);
            if ($res['ok']) {
                $all = array_merge($all, $res['data']);
            }
        }

        return $all;
    }

    /**
     * POST to the KAP1 endpoint and decode the JSON response into a flat list of records.
     */
    protected function call(array $payload)
    {
        $url = $this->config->item('kap1_url');
        $timeout = (int) $this->config->item('kap1_timeout');

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            return array('ok' => false, 'message' => "KAP1 connection failed: {$error}", 'records' => array());
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return array('ok' => false, 'message' => 'KAP1 returned an invalid response.', 'records' => array());
        }

        // The endpoint may return either a single object or a list of objects.
        if (isset($decoded['vin'])) {
            $records = array($decoded);
        } else {
            $records = is_array($decoded) ? $decoded : array();
        }

        return array('ok' => true, 'message' => 'ok', 'records' => $records);
    }

    protected function normalize(array $rec, $shopcode)
    {
        return array(
            'vin'        => trim((string) ($rec['vin'] ?? '')),
            'sfx'        => trim((string) ($rec['sfx'] ?? '')),
            'katashiki'  => trim((string) ($rec['ktsk'] ?? '')),
            'modelcode'  => trim((string) ($rec['modelcode'] ?? '')),
            'shopcode'   => $shopcode,
        );
    }
}
