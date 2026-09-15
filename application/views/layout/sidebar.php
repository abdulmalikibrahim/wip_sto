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
        <a class="nav-link <?= $active_menu === 'juklak' ? 'active' : '' ?>" href="<?= base_url('juklak') ?>">
            <i class="bi bi-journal-check"></i> Juklak
        </a>

        <div class="nav-section-label">Master WIP</div>
        <?php
        // One collapsible group per line/list, each with its WIP data page and
        // its calculation page. [label, active_menu key, url, icon] per item.
        $wip_groups = array(
            'Kap1' => array('label' => 'KAP 1', 'icon' => 'bi-broadcast', 'items' => array(
                array('WIP', 'wip_kap1', 'wip/kap1', 'bi-list-ul'),
                array('Calculation', 'wip_calc_kap1', 'wip/kap1/calc', 'bi-calculator'),
            )),
            'Kap2' => array('label' => 'KAP 2', 'icon' => 'bi-broadcast-pin', 'items' => array(
                array('WIP', 'wip_kap2', 'wip/kap2', 'bi-list-ul'),
                array('Calculation', 'wip_calc_kap2', 'wip/kap2/calc', 'bi-calculator'),
            )),
            'Ipi' => array('label' => 'IPI', 'icon' => 'bi-box-seam', 'items' => array(
                array('WIP', 'wip_wos_ipi', 'wip/wos-ipi', 'bi-list-ul'),
                array('Calculation', 'calc_ipi', 'wip/calc-ipi', 'bi-calculator'),
            )),
            'Fti' => array('label' => 'FTI', 'icon' => 'bi-box-seam-fill', 'items' => array(
                array('WIP', 'wip_wos_fti', 'wip/wos-fti', 'bi-list-ul'),
                array('Calculation', 'calc_fti', 'wip/calc-fti', 'bi-calculator'),
            )),
        );
        ?>
        <?php foreach ($wip_groups as $gid => $group): ?>
        <?php $open = in_array($active_menu, array_column($group['items'], 1), true); // the group holding the current page starts open ?>
        <a class="nav-link nav-group-toggle<?= $open ? ' has-active' : ' collapsed' ?>" href="#navGroup<?= $gid ?>" data-bs-toggle="collapse" role="button" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="navGroup<?= $gid ?>">
            <i class="bi <?= $group['icon'] ?>"></i> <?= html_escape($group['label']) ?>
            <i class="bi bi-chevron-down ms-auto nav-group-chevron"></i>
        </a>
        <div class="collapse<?= $open ? ' show' : '' ?>" id="navGroup<?= $gid ?>">
            <?php foreach ($group['items'] as $item): ?>
            <a class="nav-link nav-sub-link <?= $active_menu === $item[1] ? 'active' : '' ?>" href="<?= base_url($item[2]) ?>">
                <i class="bi <?= $item[3] ?>"></i> <?= html_escape($item[0]) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <a class="nav-link <?= $active_menu === 'wip_summary' ? 'active' : '' ?>" href="<?= base_url('wip/summary') ?>">
            <i class="bi bi-bar-chart-steps"></i> WIP Summary
        </a>
        <a class="nav-link <?= $active_menu === 'wip_summary_missing' ? 'active' : '' ?>" href="<?= base_url('wip/summary/missing-cutoff') ?>">
            <i class="bi bi-exclamation-diamond"></i> Summary Tanpa Cutoff
        </a>

        <?php if ($is_admin): ?>
        <div class="nav-section-label">Administration</div>
        <a class="nav-link <?= $active_menu === 'akun' ? 'active' : '' ?>" href="<?= base_url('akun') ?>">
            <i class="bi bi-people"></i> Akun
        </a>
        <a class="nav-link <?= $active_menu === 'setting' ? 'active' : '' ?>" href="<?= base_url('setting') ?>">
            <i class="bi bi-gear"></i> Setting
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
