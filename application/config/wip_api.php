<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------
| WIP data source configuration (KAP1 & KAP2)
| -------------------------------------------------------------------
| These are the internal endpoints documented in docs/get_data_wip_kap1.md
| and docs/get_data_wip_kap2.md. Adjust host/credentials per environment.
*/

// ---------------------------------------------------------------
// KAP 1 (sv-web-kap) — POST form-encoded { wip1, wip2 }
// ---------------------------------------------------------------
$config['kap1_url']     = 'http://sv-web-kap/pis/pisweb2.0/pis/wip/query_main2.php';
$config['kap1_timeout'] = 15;

// Each shop is built from one or more payload calls whose results are merged.
$config['kap1_groups'] = array(
    'weld' => array(
        array('wip1' => '01010', 'wip2' => '01030'),
    ),
    'toso' => array(
        array('wip1' => '01040', 'wip2' => '02160'),
        array('wip1' => '02170'), // PBS (Painting Buffer Stock)
    ),
    'assy' => array(
        array('wip1' => '03010'),
        array('wip1' => '03020', 'wip2' => '04020'), // RU (Running Unit)
    ),
);

// ---------------------------------------------------------------
// KAP 2 (Web_AndonPCD) — POST JSON { SectionCode, TPCode, ModelCode, ModelName, UserID }
// ---------------------------------------------------------------
$config['kap2_url']     = 'http://10.59.225.35/Web_AndonPCD/AndonWIPProd/GetData/';
$config['kap2_timeout'] = 15;
$config['kap2_user_id'] = 'userANDON';

// Optional raw "Cookie" header (e.g. "jwtCookie=...; UserLogin=...") if the
// endpoint requires the authenticated session used by the Andon web app.
// Leave empty if the endpoint accepts plain JSON without a session cookie.
// NOTE: the real credential is kept out of version control — see
// application/config/wip_api_local.php (gitignored). Copy
// wip_api_local.example.php to get started.
$config['kap2_cookie'] = '';

$config['kap2_groups'] = array(
    'weld' => array(
        array('SectionCode' => 'W'),
    ),
    'toso' => array(
        array('SectionCode' => 'T'),
        array('SectionCode' => 'PBS'),
    ),
    'assy' => array(
        array('SectionCode' => 'A'),
        array('SectionCode' => 'RU'),
    ),
);

// Human readable labels for each shop key, used across the WIP views, in
// line order. 'wos' is the extra WIP stage before Welding — upload only (the
// "WIP WOS" menu), so it has no kap1_groups/kap2_groups entry and doesn't
// show on the Master WIP KAP 1 / KAP 2 pages.
$config['wip_shop_labels'] = array(
    'wos'  => 'WOS',
    'weld' => 'Welding',
    'toso' => 'Toso',
    'assy' => 'Assy',
);

// ---------------------------------------------------------------
// WIP Calc — maps each WIP shop key to the BOM `shop_code` that carries
// its part usage, per KAP line. Used by Wip_calc_model to filter/sum the
// Master BOM against the cached WIP data for that line. The order is the
// line order (WIP Summary carries parts downstream along it).
// ---------------------------------------------------------------
$config['wip_calc_shop_codes'] = array(
    'kap1' => array('wos' => 'WOS3', 'weld' => 'WELD3', 'toso' => 'TOSO3', 'assy' => 'ASSY3'),
    'kap2' => array('wos' => 'WOS4', 'weld' => 'WELD4', 'toso' => 'TOSO4', 'assy' => 'ASSY4'),
);

// WIP WOS is kept as two upload-only lists per KAP line — WOS IPI and WOS FTI.
// Both are stored in wip_data as shop 'wos' (so WIP Calc / WIP Summary count
// them together as WOS), told apart by their shopcode. Each is also the unit
// list of its own "WIP Calc. IPI" / "WIP Calc. FTI" menu. Key => shopcode.
$config['wos_types'] = array(
    'ipi'  => 'WOS IPI',
    'fti' => 'WOS FTI',
);

// Local, untracked overrides (real cookies/credentials for this environment).
// Copy wip_api_local.example.php -> wip_api_local.php and fill in the real
// values; this file is gitignored so credentials never reach version control.
if (is_file(__DIR__ . '/wip_api_local.php')) {
    require __DIR__ . '/wip_api_local.php';
}
