<?php $is_admin = ($auth_user['role'] ?? '') === 'admin'; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="page-title mb-0"><?= html_escape($title) ?></h1>
    <div class="btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-secondary active" data-view="table" id="btnViewTable">
            <i class="bi bi-table"></i> Table
        </button>
        <button type="button" class="btn btn-secondary" data-view="card" id="btnViewCard">
            <i class="bi bi-grid-3x3-gap"></i> Card
        </button>
    </div>
</div>

<div class="alert alert-secondary small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    <strong><?= html_escape($wos_code) ?></strong> adalah daftar unit WIP WOS (WIP tambahan <strong>sebelum Welding</strong>), diisi lewat
    <strong>Upload Excel</strong> per line (KAP 1 / KAP 2). Daftar ini jadi urutan unit untuk menu
    <a href="<?= base_url($calc_url) ?>"><?= html_escape($calc_label) ?></a>. Di WIP Calc KAP 1/2, unit WOS IPI dan
    WOS FTI dihitung <strong>bersama</strong> sebagai kolom WOS (shop code <code>WOS3</code> / <code>WOS4</code>); di WIP Summary
    unit ini hanya dipakai card IPI / FTI untuk part di daftarnya.
</div>

<!-- The KAP lines are this page's tabs; wip.js treats them like Master WIP's shop tabs. -->
<ul class="nav nav-tabs shop-tabs mb-3" id="shopTabs">
    <?php $first = true; foreach ($lines as $key => $label): ?>
    <li class="nav-item">
        <button class="nav-link <?= $is_admin ? 'has-clear' : '' ?> <?= $first ? 'active' : '' ?>" data-shop="<?= $key ?>" type="button">
            <?= html_escape($label) ?>
        </button>
        <?php if ($is_admin): ?>
        <button type="button" class="btn-clear-shop" data-shop="<?= $key ?>" data-shop-label="<?= html_escape($wos_code . ' ' . $label) ?>" title="Clear cached <?= html_escape($wos_code . ' ' . $label) ?> data">
            <i class="bi bi-x-circle"></i>
        </button>
        <?php endif; ?>
    </li>
    <?php $first = false; endforeach; ?>
    <li class="ms-auto d-flex align-items-center gap-2 pe-2">
        <span id="wipLastUpdated" class="text-secondary small d-none d-md-inline"></span>
        <a href="<?= base_url($source . '/template') ?>" class="btn btn-sm btn-secondary">
            <i class="bi bi-download"></i> Download Template
        </a>
        <?php if ($is_admin): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalUploadWip">
            <i class="bi bi-upload"></i> Upload Excel
        </button>
        <?php endif; ?>
        <button class="btn btn-sm btn-success" id="btnDownload" type="button">
            <i class="bi bi-file-earmark-excel"></i> Download Excel
        </button>
    </li>
</ul>

<div id="wipAlert" class="alert alert-danger d-none"></div>
<div id="wipEmptyHint" class="alert alert-info d-none">
    Belum ada data <?= html_escape($wos_code) ?> untuk line ini. Gunakan <strong>Upload Excel</strong> untuk mengisinya.
</div>

<div class="card" id="tableView">
    <div class="card-body">
        <div class="table-responsive">
            <table id="tblWip" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Sequence</th>
                        <th>VIN</th>
                        <th>Suffix</th>
                        <th>Katashiki</th>
                        <th>Model</th>
                        <th>Shop Code</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<div id="cardViewWrap" class="position-relative d-none">
    <div id="wipLoading" class="wip-loading-overlay d-none">
        <div class="spinner-border text-primary" role="status"></div>
        <div class="mt-2 small text-secondary">Loading WIP data...</div>
    </div>
    <div id="cardView" class="row g-3"></div>
</div>

<?php if ($is_admin): ?>
<!-- Upload Modal -->
<div class="modal fade" id="modalUploadWip" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= base_url($source . '/upload') ?>" method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-1"></i> Upload WIP <?= html_escape($wos_code) ?> (Excel)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary">
                        Pakai <a href="<?= base_url($source . '/template') ?>">template</a>-nya:
                        <strong>VIN, Suffix, Katashiki, Model, Shop Code</strong>. Semua baris disimpan sebagai
                        <strong><?= html_escape($wos_code) ?></strong> untuk line yang dipilih di bawah (Shop Code di file diabaikan).
                        <strong>Urutan baris penting:</strong> urutan di file = Sequence yang dipakai cutoff VIN.
                    </p>
                    <div class="mb-3">
                        <label class="form-label small">Line</label>
                        <select name="line" class="form-select form-select-sm" required>
                            <?php foreach ($lines as $key => $label): ?>
                            <option value="<?= html_escape($key) ?>"><?= html_escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label class="upload-dropzone d-block mb-3" id="dropzoneWip">
                        <i class="bi bi-file-earmark-excel fs-2 d-block mb-1"></i>
                        <span id="dropzoneWipLabel">Click to choose an .xlsx file or drag it here</span>
                        <input type="file" name="wip_file" id="wip_file" accept=".xlsx" class="d-none" required>
                    </label>
                    <div class="mb-2">
                        <label class="form-label small">Upload Mode</label>
                        <select name="mode" class="form-select form-select-sm">
                            <option value="append">Append (add to this line's <?= html_escape($wos_code) ?> data)</option>
                            <option value="replace">Replace (clear this line's <?= html_escape($wos_code) ?> data first)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i> Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    var WIP_SOURCE = <?= json_encode($source) ?>;
</script>
