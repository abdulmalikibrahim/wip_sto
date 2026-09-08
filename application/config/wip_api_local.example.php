<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------
| LOCAL / UNTRACKED overrides for wip_api.php
| -------------------------------------------------------------------
| Copy this file to "wip_api_local.php" (same folder) and fill in the real
| session cookie captured from the KAP2 Andon web app if the endpoint
| requires authentication. wip_api_local.php is gitignored, so real
| credentials never get committed.
*/

// Example, taken from docs/get_data_wip_kap2.md:
// $config['kap2_cookie'] = 'UserLogin=userANDON; webbase_AndonPCD=%2FWeb_AndonPCD; jwtCookie=eyJhbGciOi...; UserName=...; UserGroupID=...';
$config['kap2_cookie'] = '';
