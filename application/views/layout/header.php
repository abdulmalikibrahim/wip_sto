<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($title) ? $title . ' - WIP & BOM Monitoring' : 'WIP & BOM Monitoring' ?></title>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/css/dataTables.bootstrap5.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/css/responsive.bootstrap5.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body>
<nav class="navbar navbar-expand navbar-dark app-topbar px-3">
    <button class="btn btn-sm btn-outline-secondary me-2 d-lg-none" id="btnToggleSidebar" type="button">
        <i class="bi bi-list"></i>
    </button>
    <a class="navbar-brand fw-semibold" href="<?= base_url('dashboard') ?>">
        <i class="bi bi-diagram-3-fill me-1"></i>WIP &amp; BOM Monitoring
    </a>
    <div class="ms-auto d-flex align-items-center gap-3">
        <span class="text-secondary small d-none d-md-inline" id="liveClock"></span>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-person-circle"></i>
                <span><?= html_escape($auth_user['full_name'] ?? 'User') ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><span class="dropdown-item-text small text-secondary">Signed in as <strong><?= html_escape($auth_user['username'] ?? '') ?></strong></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= base_url('logout') ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>
<div class="app-wrapper">
