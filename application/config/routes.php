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
$route['bom/delete/(:num)'] = 'bom/delete/$1';

// Part List (compared against Master BOM)
$route['part-list'] = 'part_list/index';
$route['part-list/data'] = 'part_list/data';
$route['part-list/compare/data'] = 'part_list/compare_data';
$route['part-list/compare/export'] = 'part_list/compare_export';
$route['part-list/template'] = 'part_list/template';
$route['part-list/export'] = 'part_list/export';
$route['part-list/upload'] = 'part_list/upload';
$route['part-list/delete/(:num)'] = 'part_list/delete/$1';
$route['part-list/delete-bulk'] = 'part_list/delete_bulk';

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
$route['wip/kap1/calc/detail/data'] = 'wip/kap1_calc_detail_data';
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
$route['wip/kap2/calc/detail/data'] = 'wip/kap2_calc_detail_data';
$route['wip/kap2/calc/detail/export'] = 'wip/kap2_calc_detail_export';
$route['wip/kap2/calc/detail/breakdown'] = 'wip/kap2_calc_detail_breakdown';
$route['wip/kap2/calc/detail/breakdown/vins'] = 'wip/kap2_calc_detail_breakdown_vins';
$route['wip/kap2/calc/upload'] = 'wip/kap2_calc_upload';
$route['wip/kap2/calc/cutoff'] = 'wip/kap2_calc_cutoff_set';
$route['wip/kap2/calc/cutoff/clear'] = 'wip/kap2_calc_cutoff_clear';
$route['wip/kap2/calc/cutoff/shop'] = 'wip/kap2_calc_cutoff_set_shop';
$route['wip/calc-combined'] = 'wip/calc_combined';
$route['wip/calc-combined/data'] = 'wip/calc_combined_data';
$route['wip/calc-combined/export'] = 'wip/calc_combined_export';

// Akun (CRUD)
$route['akun'] = 'akun/index';
$route['akun/data'] = 'akun/data';
$route['akun/create'] = 'akun/create';
$route['akun/update/(:num)'] = 'akun/update/$1';
$route['akun/delete/(:num)'] = 'akun/delete/$1';
$route['translate_uri_dashes'] = FALSE;
