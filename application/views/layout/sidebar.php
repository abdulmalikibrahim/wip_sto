<?php
$is_admin = ($auth_user['role'] ?? '') === 'admin';
// Scoped User: a focused menu — only its own KAP line's WIP group, and no
// IPI / FTI / Part Special. Menu visibility only; the controllers enforce
// what each account may actually write.
$is_operator = ($auth_user['role'] ?? '') === 'user';
$operator_plant = $auth_user['plant'] ?? '';
// Editor: only the three menus it may open (MY_Controller enforces it).
$is_editor = ($auth_user['role'] ?? '') === 'editor';
?>
<aside class="app-sidebar" id="appSidebar">
    <nav class="nav flex-column py-3">
        <?php if (!$is_editor): ?>
        <a class="nav-link <?= $active_menu === 'dashboard' ? 'active' : '' ?>" href="<?= base_url('dashboard') ?>">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <?php endif; ?>
        <a class="nav-link <?= $active_menu === 'bom' ? 'active' : '' ?>" href="<?= base_url('bom') ?>">
            <i class="bi bi-list-columns-reverse"></i> Master BOM
        </a>
        <a class="nav-link <?= $active_menu === 'part_list' ? 'active' : '' ?>" href="<?= base_url('part-list') ?>">
            <i class="bi bi-ui-checks-grid"></i> Part List
        </a>
        <a class="nav-link <?= $active_menu === 'juklak' ? 'active' : '' ?>" href="<?= base_url('juklak') ?>">
            <i class="bi bi-journal-check"></i> Juklak
        </a>
        <?php if (!$is_editor): /* everything below the three master-data menus */ ?>
        <?php if (!$is_operator): ?>
        <a class="nav-link <?= $active_menu === 'special_part' ? 'active' : '' ?>" href="<?= base_url('special-part') ?>">
            <i class="bi bi-sliders"></i> Part Special
        </a>
        <?php endif; ?>

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
        if ($is_operator) {
            // 'kap1' -> 'Kap1': keep just the account's own line.
            $own = ucfirst((string) $operator_plant);
            $wip_groups = isset($wip_groups[$own]) ? array($own => $wip_groups[$own]) : array();
        }
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
        <?php endif; /* !$is_editor */ ?>

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
