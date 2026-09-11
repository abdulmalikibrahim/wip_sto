<?php
$is_admin = ($auth_user['role'] ?? '') === 'admin';
?>
<aside class="app-sidebar" id="appSidebar">
    <nav class="nav flex-column py-3">
        <a class="nav-link <?= $active_menu === 'dashboard' ? 'active' : '' ?>" href="<?= base_url('dashboard') ?>">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a class="nav-link <?= $active_menu === 'bom' ? 'active' : '' ?>" href="<?= base_url('bom') ?>">
            <i class="bi bi-list-columns-reverse"></i> Master BOM
        </a>
        <a class="nav-link <?= $active_menu === 'part_list' ? 'active' : '' ?>" href="<?= base_url('part-list') ?>">
            <i class="bi bi-ui-checks-grid"></i> Part List
        </a>

        <div class="nav-section-label">Master WIP</div>
        <a class="nav-link ps-4 <?= $active_menu === 'wip_kap1' ? 'active' : '' ?>" href="<?= base_url('wip/kap1') ?>">
            <i class="bi bi-broadcast"></i> KAP 1
        </a>
        <a class="nav-link ps-4 <?= $active_menu === 'wip_calc_kap1' ? 'active' : '' ?>" href="<?= base_url('wip/kap1/calc') ?>">
            <i class="bi bi-calculator"></i> WIP Calc. KAP 1
        </a>
        <a class="nav-link ps-4 <?= $active_menu === 'wip_kap2' ? 'active' : '' ?>" href="<?= base_url('wip/kap2') ?>">
            <i class="bi bi-broadcast-pin"></i> KAP 2
        </a>
        <a class="nav-link ps-4 <?= $active_menu === 'wip_calc_kap2' ? 'active' : '' ?>" href="<?= base_url('wip/kap2/calc') ?>">
            <i class="bi bi-calculator"></i> WIP Calc. KAP 2
        </a>
        <a class="nav-link ps-4 <?= $active_menu === 'wip_calc_combined' ? 'active' : '' ?>" href="<?= base_url('wip/calc-combined') ?>">
            <i class="bi bi-collection"></i> WIP Calc. KAP 1 &amp; 2
        </a>

        <?php if ($is_admin): ?>
        <div class="nav-section-label">Administration</div>
        <a class="nav-link <?= $active_menu === 'akun' ? 'active' : '' ?>" href="<?= base_url('akun') ?>">
            <i class="bi bi-people"></i> Akun
        </a>
        <?php endif; ?>
    </nav>
</aside>
<div class="app-sidebar-backdrop" id="sidebarBackdrop"></div>
<main class="app-content">
    <?php if (!empty($flash)): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible fade show" role="alert">
            <?= html_escape($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
