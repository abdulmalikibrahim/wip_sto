<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------------
| This file lets you re-map URI requests to specific controller functions.
|
| Typically there is a one-to-one relationship between a URL string
| and its corresponding controller class/method. The segments in a
| URL normally follow this pattern:
|
|	example.com/class/method/id/
|
| In some instances, however, you may want to remap this relationship
| so that a different class/function is called than the one
| corresponding to the URL.
|
| Please see the user guide for complete details:
|
|	https://codeigniter.com/userguide3/general/routing.html
|
| -------------------------------------------------------------------------
| RESERVED ROUTES
| -------------------------------------------------------------------------
|
| There are three reserved routes:
|
|	$route['default_controller'] = 'welcome';
|
| This route indicates which controller class should be loaded if the
| URI contains no data. In the above example, the "welcome" class
| would be loaded.
|
|	$route['404_override'] = 'errors/page_missing';
|
| This route will tell the Router which controller/method to use if those
| provided in the URL cannot be matched to a valid route.
|
|	$route['translate_uri_dashes'] = FALSE;
|
| This is not exactly a route, but allows you to automatically route
| controller and method names that contain dashes. '-' isn't a valid
| class or method name character, so it requires translation.
| When you set this option to TRUE, it will replace ALL dashes in the
| controller and method URI segments.
|
| Examples:	my-controller/index	-> my_controller/index
|		my-controller/my-method	-> my_controller/my_method
*/
$route['default_controller'] = 'dashboard';
$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;

// Auth
$route['login'] = 'auth/login';
$route['logout'] = 'auth/logout';

// Master BOM
$route['bom'] = 'bom/index';
$route['bom/data'] = 'bom/data';
$route['bom/template'] = 'bom/template';
$route['bom/export'] = 'bom/export';
$route['bom/upload'] = 'bom/upload';
$route['bom/create'] = 'bom/create';
$route['bom/update/(:num)'] = 'bom/update/$1';
$route['bom/delete/(:num)'] = 'bom/delete/$1';

// Part List (compared against Master BOM)
$route['part-list'] = 'part_list/index';
$route['part-list/data'] = 'part_list/data';
$route['part-list/compare/data'] = 'part_list/compare_data';
$route['part-list/compare/export'] = 'part_list/compare_export';
$route['part-list/template'] = 'part_list/template';
$route['part-list/export'] = 'part_list/export';
$route['part-list/export-csv'] = 'part_list/export_csv';
$route['part-list/upload'] = 'part_list/upload';
$route['part-list/create'] = 'part_list/create';
$route['part-list/update/(:num)'] = 'part_list/update/$1';
$route['part-list/delete/(:num)'] = 'part_list/delete/$1';
$route['part-list/delete-bulk'] = 'part_list/delete_bulk';

// Juklak (Part No -> Main Part No; non-main parts count as 0 in WIP Calc/Summary)
$route['juklak'] = 'juklak/index';
$route['juklak/data'] = 'juklak/data';
$route['juklak/template'] = 'juklak/template';
$route['juklak/export'] = 'juklak/export';
$route['juklak/upload'] = 'juklak/upload';
$route['juklak/create'] = 'juklak/create';
$route['juklak/suffixes'] = 'juklak/suffixes';
$route['juklak/update/(:num)'] = 'juklak/update/$1';
$route['juklak/delete/(:num)'] = 'juklak/delete/$1';

// Part Special (per line: shop mana saja yang dihitung untuk sebuah part number)
$route['special-part'] = 'special_part/index';
$route['special-part/data'] = 'special_part/data';
$route['special-part/save'] = 'special_part/save';
$route['special-part/suggest'] = 'special_part/suggest';
$route['special-part/delete/(:num)'] = 'special_part/delete/$1';

// WIP WOS IPI / FTI (upload-only WIP stage before Welding; one list each per KAP line)
$route['wip/wos-(ipi|fti)'] = 'wip/wos/$1';
$route['wip/wos-(ipi|fti)/data/(:any)'] = 'wip/wos_data/$1/$2';
$route['wip/wos-(ipi|fti)/export/(:any)'] = 'wip/wos_export/$1/$2';
$route['wip/wos-(ipi|fti)/clear/(:any)'] = 'wip/wos_clear/$1/$2';
$route['wip/wos-(ipi|fti)/template'] = 'wip/wos_template/$1';
$route['wip/wos-(ipi|fti)/upload'] = 'wip/wos_upload/$1';

// WIP Calc. IPI / FTI (uploaded welding parts counted against WOS IPI / WOS FTI, own cutoff VIN; standalone)
$route['wip/calc-(ipi|fti)'] = 'ippi_fti/index/$1';
$route['wip/calc-(ipi|fti)/data'] = 'ippi_fti/data/$1';
$route['wip/calc-(ipi|fti)/breakdown'] = 'ippi_fti/breakdown/$1';
$route['wip/calc-(ipi|fti)/export'] = 'ippi_fti/export/$1';
$route['wip/calc-(ipi|fti)/template'] = 'ippi_fti/template/$1';
$route['wip/calc-(ipi|fti)/list'] = 'ippi_fti/export_list/$1';
$route['wip/calc-(ipi|fti)/upload'] = 'ippi_fti/upload/$1';
$route['wip/calc-(ipi|fti)/cutoff'] = 'ippi_fti/cutoff/$1';
$route['wip/calc-(ipi|fti)/delete/(:num)'] = 'ippi_fti/delete/$1/$2';

// Master WIP
$route['wip/kap1'] = 'wip/kap1';
$route['wip/kap1/data/(:any)'] = 'wip/kap1_data/$1';
$route['wip/kap1/export/(:any)'] = 'wip/kap1_export/$1';
$route['wip/kap1/getwip/(:any)'] = 'wip/kap1_getwip/$1';
$route['wip/kap1/clear/(:any)'] = 'wip/kap1_clear/$1';
$route['wip/kap1/template'] = 'wip/kap1_template';
$route['wip/kap1/upload'] = 'wip/kap1_upload';
$route['wip/kap1/calc'] = 'wip/kap1_calc';
$route['wip/kap1/calc/data'] = 'wip/kap1_calc_data';
$route['wip/kap1/calc/template'] = 'wip/kap1_calc_template';
$route['wip/kap1/calc/export'] = 'wip/kap1_calc_export';
$route['wip/kap1/calc/detail'] = 'wip/kap1_calc_detail';
$route['wip/kap1/calc/detail/export'] = 'wip/kap1_calc_detail_export';
$route['wip/kap1/calc/detail/breakdown'] = 'wip/kap1_calc_detail_breakdown';
$route['wip/kap1/calc/detail/breakdown/vins'] = 'wip/kap1_calc_detail_breakdown_vins';
$route['wip/kap1/calc/upload'] = 'wip/kap1_calc_upload';
$route['wip/kap1/calc/cutoff'] = 'wip/kap1_calc_cutoff_set';
$route['wip/kap1/calc/cutoff/clear'] = 'wip/kap1_calc_cutoff_clear';
$route['wip/kap1/calc/cutoff/shop'] = 'wip/kap1_calc_cutoff_set_shop';
$route['wip/kap2'] = 'wip/kap2';
$route['wip/kap2/data/(:any)'] = 'wip/kap2_data/$1';
$route['wip/kap2/export/(:any)'] = 'wip/kap2_export/$1';
$route['wip/kap2/getwip/(:any)'] = 'wip/kap2_getwip/$1';
$route['wip/kap2/clear/(:any)'] = 'wip/kap2_clear/$1';
$route['wip/kap2/template'] = 'wip/kap2_template';
$route['wip/kap2/upload'] = 'wip/kap2_upload';
$route['wip/kap2/calc'] = 'wip/kap2_calc';
$route['wip/kap2/calc/data'] = 'wip/kap2_calc_data';
$route['wip/kap2/calc/template'] = 'wip/kap2_calc_template';
$route['wip/kap2/calc/export'] = 'wip/kap2_calc_export';
$route['wip/kap2/calc/detail'] = 'wip/kap2_calc_detail';
$route['wip/kap2/calc/detail/export'] = 'wip/kap2_calc_detail_export';
$route['wip/kap2/calc/detail/breakdown'] = 'wip/kap2_calc_detail_breakdown';
$route['wip/kap2/calc/detail/breakdown/vins'] = 'wip/kap2_calc_detail_breakdown_vins';
$route['wip/kap2/calc/upload'] = 'wip/kap2_calc_upload';
$route['wip/kap2/calc/cutoff'] = 'wip/kap2_calc_cutoff_set';
$route['wip/kap2/calc/cutoff/clear'] = 'wip/kap2_calc_cutoff_clear';
$route['wip/kap2/calc/cutoff/shop'] = 'wip/kap2_calc_cutoff_set_shop';
$route['wip/summary'] = 'wip/summary';
$route['wip/summary/data'] = 'wip/summary_data';
$route['wip/summary/export'] = 'wip/summary_export';
$route['wip/summary/missing-cutoff'] = 'wip/summary_missing';
$route['wip/summary/missing-cutoff/data'] = 'wip/summary_missing_data';
$route['wip/summary/missing-cutoff/detail'] = 'wip/summary_missing_detail';
$route['wip/summary/missing-cutoff/export'] = 'wip/summary_missing_export';
$route['wip/summary/missing-cutoff/decide'] = 'wip/summary_missing_decide';
$route['wip/summary/missing-cutoff/decide/clear'] = 'wip/summary_missing_decide_clear';

// Akun (CRUD)
$route['akun'] = 'akun/index';
$route['akun/data'] = 'akun/data';
$route['akun/create'] = 'akun/create';
$route['akun/update/(:num)'] = 'akun/update/$1';
$route['akun/delete/(:num)'] = 'akun/delete/$1';

// Setting (STO activity date — locks "Get Data WIP" once it has passed)
$route['setting'] = 'setting/index';
$route['setting/save'] = 'setting/save';
$route['translate_uri_dashes'] = FALSE;
