<?php
// "Alur Data -> WIP Summary" guide: the steps that turn master data into
// the WIP Summary figures. Rule text is static markup (trusted, echoed raw).
$flow_steps = array(
    array(
        'icon'  => 'bi-list-columns-reverse',
        'title' => 'Master Part',
        'tag'   => 'BOM / Part List',
        'lead'  => 'Daftar part + Qty per Model & Suffix.',
        'rules' => array(
            'Pilih basis hitung: <strong>Master BOM</strong> atau <strong>Part List</strong> (tombol di halaman Calculation &amp; Summary).',
            '<strong>Shop Code</strong> = part dipakai di shop mana. Akhiran <strong>3</strong> = KAP 1, <strong>4</strong> = KAP 2.',
        ),
        'links' => array(array('Master BOM', 'bom'), array('Part List', 'part-list')),
    ),
    array(
        'icon'  => 'bi-journal-check',
        'title' => 'Juklak',
        'tag'   => 'Part pengganti',
        'lead'  => 'Tentukan part mana yang diwakili Main Part.',
        'rules' => array(
            'Part yang diwakili part lain dihitung <strong>0</strong>.',
            'Hanya <strong>Main Part</strong> yang dihitung, supaya tidak dobel.',
        ),
        'links' => array(array('Juklak', 'juklak')),
    ),
    array(
        'icon'  => 'bi-broadcast',
        'title' => 'Data WIP',
        'tag'   => 'Unit di line',
        'lead'  => 'Daftar unit (VIN) yang sedang ada di tiap shop.',
        'rules' => array(
            'KAP 1 / KAP 2: klik <strong>Get Data WIP</strong> per shop (data lama shop itu diganti). Hanya bisa <strong>sampai tanggal STO</strong>.',
            'Server tidak bisa diakses? Pakai <strong>Upload Excel</strong>.',
            'IPI / FTI (WOS): data masuk lewat <strong>Upload Excel</strong>.',
        ),
        'links' => array(array('WIP KAP 1', 'wip/kap1'), array('WIP KAP 2', 'wip/kap2'), array('WIP IPI', 'wip/wos-ipi'), array('WIP FTI', 'wip/wos-fti')),
    ),
    array(
        'icon'  => 'bi-flag-fill',
        'title' => 'Cutoff VIN',
        'tag'   => 'Batas hitung',
        'lead'  => 'VIN pertama yang mulai dihitung, per part di tiap shop.',
        'rules' => array(
            'Dihitung: unit <strong>Cutoff VIN &rarr; unit terbaru</strong>. Unit yang lebih lama diabaikan.',
            'Belum ada cutoff = <strong>Net 0</strong>.',
            'Isi per part, sekaligus satu shop, atau <strong>Upload Excel</strong>. VIN harus ada di Data WIP.',
        ),
        'links' => array(array('Calc KAP 1', 'wip/kap1/calc'), array('Calc KAP 2', 'wip/kap2/calc'), array('Calc IPI', 'wip/calc-ipi'), array('Calc FTI', 'wip/calc-fti')),
    ),
    array(
        'icon'  => 'bi-bar-chart-steps',
        'title' => 'WIP Summary',
        'tag'   => 'Hasil akhir',
        'lead'  => 'Total part per shop: KAP 1, KAP 2, atau gabungan.',
        'rules' => array(
            'Shop sendiri = <strong>Net</strong> (dari cutoff). Shop sesudahnya = <strong>semua unit</strong> (part sudah terpasang).',
            'Card <strong>Total</strong> = jumlah semua card. Bisa <strong>Export Excel</strong>.',
        ),
        'links' => array(array('WIP Summary', 'wip/summary')),
    ),
);

$flow_rules = array(
    array('bi-flag', 'warn', 'Tanpa cutoff = 0', 'Part tanpa Cutoff VIN belum dihitung (Net 0).'),
    array('bi-arrow-repeat', 'warn', 'Cek lagi setelah Get Data', 'VIN cutoff hilang dari data WIP? Semua unit ikut terhitung.'),
    array('bi-1-circle', '', '1 suffix dihitung 1&times;', 'Part dobel di Model + Suffix yang sama: pakai Qty terbesar.'),
    array('bi-journal-x', '', 'Juklak = 0', 'Part yang diwakili Main Part lain tidak dihitung.'),
    array('bi-box-seam', '', 'Part IPI / FTI', 'Part welding yang ada di list IPI / FTI masuk card IPI / FTI, bukan Welding.'),
    array('bi-files', 'danger', 'Replace vs Append', 'Append = menambah. Upload file yang sama 2&times; = unit dobel.'),
    array('bi-file-earmark-excel', 'ok', 'Excel: Paste Values', 'Kolom VIN / Part No jangan berisi rumus (VLOOKUP).'),
    array('bi-shield-lock', '', 'Khusus Admin', 'Upload, Clear, dan set Cutoff hanya bisa oleh Admin.'),
);
?>
<h1 class="page-title">Dashboard</h1>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-list-columns-reverse"></i></div>
            <div>
                <div class="stat-value"><?= number_format($total_bom) ?></div>
                <div class="stat-label">Total BOM Records</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-broadcast"></i></div>
            <div>
                <div class="stat-value">KAP 1</div>
                <div class="stat-label">Welding / Toso / Assy</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-broadcast-pin"></i></div>
            <div>
                <div class="stat-value">KAP 2</div>
                <div class="stat-label">Welding / Toso / Assy</div>
            </div>
        </div>
    </div>
    <?php if ($total_users !== null): ?>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-people"></i></div>
            <div>
                <div class="stat-value"><?= number_format($total_users) ?></div>
                <div class="stat-label">Total Accounts</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Alur Data -> WIP Summary: auto-playing step walkthrough (assets/js/dashboard.js) -->
<div class="card flow-guide flow-anim mb-4" id="flowGuide">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <div>
            <div class="fw-semibold"><i class="bi bi-diagram-3 me-1"></i> Alur Data &rarr; WIP Summary</div>
            <div class="small text-secondary">5 langkah sampai angka Summary keluar. Klik langkah untuk lihat detail.</div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="flowPlay" aria-pressed="true">
            <i class="bi bi-pause-fill"></i> Pause
        </button>
    </div>
    <div class="card-body">
        <div class="flow-track-wrap">
            <div class="flow-track" aria-hidden="true">
                <div class="flow-fill"></div>
                <span class="flow-packet"></span>
                <span class="flow-packet"></span>
                <span class="flow-packet"></span>
            </div>
            <ol class="flow-steps">
                <?php foreach ($flow_steps as $i => $step): ?>
                <li>
                    <button type="button" class="flow-step" data-step="<?= $i ?>" aria-controls="flowPanel<?= $i ?>">
                        <span class="flow-node"><i class="bi <?= $step['icon'] ?>"></i><span class="flow-num"><?= $i + 1 ?></span></span>
                        <span class="flow-text">
                            <span class="flow-title"><?= $step['title'] ?></span>
                            <span class="flow-tag"><?= $step['tag'] ?></span>
                        </span>
                    </button>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>

        <div class="flow-panels" aria-live="polite">
            <?php foreach ($flow_steps as $i => $step): ?>
            <div class="flow-panel" id="flowPanel<?= $i ?>" <?= $i === 0 ? '' : 'hidden' ?>>
                <div class="flow-panel-icon"><i class="bi <?= $step['icon'] ?>"></i></div>
                <div>
                    <div class="flow-panel-kicker">Langkah <?= $i + 1 ?> dari <?= count($flow_steps) ?></div>
                    <h2 class="flow-panel-title"><?= $step['title'] ?></h2>
                    <p class="flow-panel-lead"><?= $step['lead'] ?></p>
                    <ul class="flow-rules">
                        <?php foreach ($step['rules'] as $r => $rule): ?>
                        <li style="--i: <?= $r ?>"><?= $rule ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="flow-panel-links">
                    <?php foreach ($step['links'] as $link): ?>
                    <a href="<?= base_url($link[1]) ?>" class="btn btn-sm btn-outline-primary"><?= $link[0] ?> <i class="bi bi-arrow-right-short"></i></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="flow-timer" aria-hidden="true"><div class="flow-timer-bar"></div></div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card h-100 flow-anim">
            <div class="card-header"><i class="bi bi-flag-fill me-1"></i> Cara Hitung Net</div>
            <div class="card-body">
                <p class="small text-secondary mb-3">Unit masuk dari kiri. Yang dihitung: <strong class="text-success">Cutoff VIN sampai unit terbaru</strong>.</p>
                <div class="conveyor" aria-hidden="true">
                    <div class="conveyor-end"><i class="bi bi-box-arrow-in-right"></i>Masuk</div>
                    <div class="conveyor-belt"><div class="conveyor-units" id="conveyorUnits"></div></div>
                    <div class="conveyor-end"><i class="bi bi-box-arrow-right"></i>Keluar</div>
                </div>
                <div class="conveyor-legend small">
                    <span><i class="lg lg-new"></i>Dihitung</span>
                    <span><i class="lg lg-cut"></i>Cutoff VIN</span>
                    <span><i class="lg lg-old"></i>Lebih lama (diabaikan)</span>
                </div>
                <div class="formula-box">
                    <div class="formula-line">
                        Net = <span class="num" id="convCount">0</span> unit &times; Qty <span class="num">2</span> = <span class="num text-success" id="convNet">0</span>
                    </div>
                    <div class="small text-secondary mt-1">Dihitung per Model + Suffix, lalu dijumlah per part.</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100 flow-anim">
            <div class="card-header"><i class="bi bi-bar-chart-steps me-1"></i> Isi Card di WIP Summary</div>
            <div class="card-body">
                <p class="small text-secondary mb-2">Tiap card menghitung part-nya di shop sendiri, lalu di semua shop sesudahnya.</p>
                <div class="summary-map">
                    <?php foreach ($summary_stages as $card => $units): ?>
                    <div class="smap-row">
                        <div class="smap-card"><?= html_escape($summary_labels[$card] ?? $card) ?></div>
                        <div class="smap-chain">
                            <?php foreach ($units as $u => $unit_shop): ?>
                            <?php if ($u > 0): ?><i class="bi bi-chevron-right smap-arrow"></i><?php endif; ?>
                            <span class="smap-chip<?= $u === 0 ? ' is-own' : '' ?>" style="--i: <?= $u ?>"><?php if ($u === 0): ?><i class="bi bi-flag-fill me-1"></i><?php endif; ?><?= html_escape($summary_labels[$unit_shop] ?? $unit_shop) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="smap-row">
                        <div class="smap-card">Total</div>
                        <div class="smap-chain small text-secondary">= jumlah semua card di atas</div>
                    </div>
                </div>
                <div class="smap-legend small text-secondary d-flex flex-wrap gap-3 mt-2">
                    <span><span class="smap-chip is-own"><i class="bi bi-flag-fill me-1"></i>Net</span> dari Cutoff VIN</span>
                    <span><span class="smap-chip">Semua unit</span> part sudah terpasang</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4 flow-anim">
    <div class="card-header"><i class="bi bi-exclamation-diamond me-1"></i> Aturan Penting</div>
    <div class="card-body">
        <div class="row g-2 row-cols-1 row-cols-sm-2 row-cols-xl-4">
            <?php foreach ($flow_rules as $i => $rule): ?>
            <div class="col">
                <div class="rule-tile reveal" style="--d: <?= $i * 0.06 ?>s">
                    <span class="rule-ico <?= $rule[1] ?>"><i class="bi <?= $rule[0] ?>"></i></span>
                    <div>
                        <div class="rule-title"><?= $rule[2] ?></div>
                        <div class="rule-text"><?= $rule[3] ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-lightning-charge me-1"></i> Quick Access</div>
            <div class="card-body d-flex flex-wrap align-items-start gap-2">
                <a href="<?= base_url('bom') ?>" class="btn btn-primary btn-sm"><i class="bi bi-list-columns-reverse me-1"></i> Master BOM</a>
                <a href="<?= base_url('wip/kap1') ?>" class="btn btn-primary btn-sm"><i class="bi bi-broadcast me-1"></i> WIP KAP 1</a>
                <a href="<?= base_url('wip/kap2') ?>" class="btn btn-primary btn-sm"><i class="bi bi-broadcast-pin me-1"></i> WIP KAP 2</a>
                <a href="<?= base_url('wip/summary') ?>" class="btn btn-primary btn-sm"><i class="bi bi-bar-chart-steps me-1"></i> WIP Summary</a>
                <?php if (($auth_user['role'] ?? '') === 'admin'): ?>
                <a href="<?= base_url('akun') ?>" class="btn btn-primary btn-sm"><i class="bi bi-people me-1"></i> Manage Akun</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-clock-history me-1"></i> Recent BOM Uploads</div>
            <div class="card-body p-0">
                <?php if (empty($recent_uploads)): ?>
                    <div class="p-3 text-secondary small">No upload history yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>File</th><th>Mode</th><th>Rows</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent_uploads as $u): ?>
                            <tr>
                                <td class="small"><?= html_escape($u['file_name']) ?></td>
                                <td class="small text-capitalize"><?= html_escape($u['mode']) ?></td>
                                <td class="small"><?= (int) $u['inserted_rows'] ?>/<?= (int) $u['total_rows'] ?></td>
                                <td class="small">
                                    <?= $u['status'] === 'success' ? '<span class="badge text-bg-success">OK</span>' : '<span class="badge text-bg-danger">Failed</span>' ?>
                                </td>
                                <td class="small text-secondary"><?= html_escape($u['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
