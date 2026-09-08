<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Data source for WIP KAP 2 (Web_AndonPCD / AndonWIPProd).
 * See docs/get_data_wip_kap2.md for the raw endpoint documentation.
 *
 * Note: the reference capture included a `jwtCookie` session cookie. If the
 * live endpoint enforces authentication, set `kap2_cookie` in
 * application/config/wip_api.php to a valid "Cookie" header value.
 */
class Wip_kap2_model extends CI_Model
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
        $groups = $this->config->item('kap2_groups');
        if (!isset($groups[$shop])) {
            return array('ok' => false, 'message' => 'Unknown shop.', 'data' => array());
        }

        $shopcode = strtoupper($this->config->item('wip_shop_labels')[$shop] ?? $shop);
        $data = array();

        foreach ($groups[$shop] as $section) {
            $res = $this->call($section);
            if (!$res['ok']) {
                return array('ok' => false, 'message' => $res['message'], 'data' => array());
            }

            foreach ($res['records'] as $rec) {
                $data[] = $this->normalize($rec, $shopcode);
            }
        }

        return array('ok' => true, 'message' => 'ok', 'data' => $data);
    }

    public function get_all()
    {
        $all = array();
        foreach (array_keys($this->config->item('kap2_groups')) as $shop) {
            $res = $this->get_shop($shop);
            if ($res['ok']) {
                $all = array_merge($all, $res['data']);
            }
        }

        return $all;
    }

    /**
     * POST a JSON payload to the KAP2 endpoint and decode the "Contents" list.
     */
    protected function call(array $section)
    {
        $url = $this->config->item('kap2_url');
        $timeout = (int) $this->config->item('kap2_timeout');
        $cookie = $this->config->item('kap2_cookie');

        $payload = array_merge(array(
            'SectionCode' => '',
            'TPCode'      => '',
            'ModelCode'   => '',
            'ModelName'   => '',
            'UserID'      => $this->config->item('kap2_user_id'),
        ), $section);

        $headers = array('Content-Type: application/json; charset=UTF-8', 'X-Requested-With: XMLHttpRequest');
        if (!empty($cookie)) {
            $headers[] = 'Cookie: ' . $cookie;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            // The endpoint's .NET action expects a JSON array wrapping the payload object.
            CURLOPT_POSTFIELDS     => json_encode(array($payload)),
            CURLOPT_HTTPHEADER     => $headers,
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
            return array('ok' => false, 'message' => "KAP2 connection failed: {$error}", 'records' => array());
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return array('ok' => false, 'message' => 'KAP2 returned an invalid response.', 'records' => array());
        }

        if (isset($decoded['Message']) && strtolower($decoded['Message']) !== 'success') {
            return array('ok' => false, 'message' => 'KAP2: ' . $decoded['Message'], 'records' => array());
        }

        return array('ok' => true, 'message' => 'ok', 'records' => $decoded['Contents'] ?? array());
    }

    protected function normalize(array $rec, $shopcode)
    {
        return array(
            'vin'        => trim((string) ($rec['VIN'] ?? '')),
            'sfx'        => trim((string) ($rec['Suffix'] ?? '')),
            'katashiki'  => trim((string) ($rec['Ktsk'] ?? '')),
            'modelcode'  => trim((string) ($rec['Model'] ?? '')),
            'colorcode'  => trim((string) ($rec['ColorCode'] ?? '')),
            'colorname'  => trim((string) ($rec['Color'] ?? '')),
            'wipname'    => trim((string) ($rec['LastScan'] ?? '')),
            'scandate'   => trim((string) ($rec['ScanDate'] ?? '')),
            'shopcode'   => $shopcode,
        );
    }
}
