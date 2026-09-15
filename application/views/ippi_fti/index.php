<?php $is_admin = ($auth_user['role'] ?? '') === 'admin'; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="page-title mb-0"><?= html_escape($title) ?></h1>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= base_url($base . '/list') ?>" class="btn btn-success btn-sm btn-ippi-download">
            <i class="bi bi-file-earmark-arrow-down me-1"></i> Download Daftar Part
        </a>
        <a href="<?= base_url($base . '/template') ?>" class="btn btn-secondary btn-sm btn-ippi-download">
            <i class="bi bi-download me-1"></i> Download Template
        </a>
        <?php if ($is_admin): ?>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalUploadIppi" <?= $table_ready ? '' : 'disabled' ?>>
            <i class="bi bi-upload me-1"></i> Upload Daftar Part
        </button>
        <?php endif; ?>
    </div>
</div>

<div id="ippiUploadOverlay" class="page-loading-overlay d-none">
    <div class="spinner-border text-primary" role="status"></div>
    <div class="mt-2 small text-secondary">Uploading <?= html_escape($type_label) ?> part list, please wait...</div>
</div>

<?php if (!$table_ready): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Tabel <code>ippi_fti</code> belum siap di database ini. Jalankan
    <code>database/migrations/2026_09_15_create_ippi_fti.sql</code> lalu
    <code>2026_09_15_ippi_fti_split_ipi_fti.sql</code> dulu (mis. lewat tab SQL di phpMyAdmin).
</div>
<?php endif; ?>

<div class="alert alert-secondary small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    Part <strong><?= html_escape($type_label) ?></strong> = part Welding dari daftar yang di-upload, dihitung terhadap
    <strong>urutan unit <a href="<?= base_url($wos_url) ?>">WIP <?= html_escape($wos_code) ?></a></strong> (bukan unit Welding)
    dengan <strong>cutoff VIN sendiri</strong>, terpisah dari cutoff Welding. Qty per suffix diambil dari baris Welding
    (<code>WELD3</code> / <code>WELD4</code>) part itu di Master BOM / Part List. <strong>Gross</strong> = semua unit
    <?= html_escape($wos_code) ?>, <strong>Net</strong> = unit mulai cutoff VIN; kalau belum ada cutoff VIN, Cutoff dan Net 0.
    Di WIP Summary, part ini jadi card <strong><?= html_escape($type_label) ?></strong> sendiri (Net ini + semua unit Welding, Toso, Assy)
    dan tidak dihitung lagi di card Welding. WIP Calc KAP 1/2 tidak berubah.
</div>

<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-3">
            <?php foreach (array(
                'parts'     => array('bi-list-check', 'Part ' . $type_label . ' (dengan cutoff VIN)'),
                'wos_units' => array('bi-box-seam', 'Unit di WIP ' . $wos_code),
                'gross'     => array('bi-stack', 'Total Gross'),
                'net'       => array('bi-check2-circle', 'Total Net'),
            ) as $key => $meta): ?>
            <div class="col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi <?= $meta[0] ?>"></i></div>
                    <div>
                        <div class="stat-value" data-ippi-stat="<?= $key ?>">&mdash;</div>
                        <div class="stat-label"><?= html_escape($meta[1]) ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap align-items-center gap-3">
        <div class="d-flex align-items-center gap-2">
            <span class="small text-secondary"><i class="bi bi-diagram-3 me-1"></i>Line:</span>
            <div class="btn-group btn-group-sm" role="group" aria-label="KAP line">
                <?php $first = true; foreach ($lines as $key => $label): ?>
                <button type="button" class="btn btn-outline-primary<?= $first ? ' active' : '' ?>" data-line-option="<?= $key ?>"><?= html_escape($label) ?></button>
                <?php $first = false; endforeach; ?>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="small text-secondary"><i class="bi bi-database me-1"></i>Calculate from:</span>
            <div class="btn-group btn-group-sm" role="group" aria-label="Calculation basis">
                <button type="button" class="btn btn-outline-primary" data-basis-option="bom">Master BOM</button>
                <button type="button" class="btn btn-outline-primary" data-basis-option="part_list">Part List</button>
            </div>
        </div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <button type="button" class="btn btn-sm btn-secondary" id="btnIppiHideZero">
            <i class="bi bi-eye-slash"></i> Hide Zero Rows
        </button>
        <a href="<?= base_url($base . '/export') ?>" class="btn btn-sm btn-success" id="btnIppiExport">
            <i class="bi bi-file-earmark-excel"></i> Download Excel
        </a>
    </div>
</div>

<div id="ippiAlert" class="alert alert-danger d-none"></div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-1"></i> <?= html_escape($title) ?>
        <span class="text-secondary small ms-1">&mdash; klik angka <strong>Net</strong> (<i class="bi bi-calculator"></i>) untuk melihat formula-nya</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="tblIppi" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Part No</th>
                        <th>Part Name</th>
                        <th>Cutoff VIN</th>
                        <th class="text-center col-group-start">Gross</th>
                        <th class="text-center">Cutoff</th>
                        <th class="text-center">Net</th>
                        <?php if ($is_admin): ?><th>Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Formula Modal -->
<div class="modal fade" id="modalIppiFormula" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calculator me-1"></i> Formula Detail &mdash; <?= html_escape($type_label) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalIppiFormulaBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php if ($is_admin): ?>
<!-- Set Cutoff VIN Modal -->
<div class="modal fade" id="modalIppiCutoff" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="formIppiCutoff">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-signpost-2 me-1"></i> Cutoff VIN <?= html_escape($type_label) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="ippiCutoffAlert" class="alert alert-danger d-none py-2 small"></div>
                    <input type="hidden" name="id" id="ippiCutoffId">
                    <div class="mb-2 small"><span class="text-secondary">Part:</span> <strong id="ippiCutoffPart"></strong></div>
                    <label class="form-label small">VIN</label>
                    <input type="text" class="form-control form-control-sm text-uppercase" name="vin" id="ippiCutoffVin" placeholder="e.g. MHKAB1BA9TJ176981" autocomplete="off">
                    <div class="form-text">Harus ada di data <a href="<?= base_url($wos_url) ?>" target="_blank">WIP <?= html_escape($wos_code) ?></a> line ini. Kosongkan untuk menghapus cutoff (Net jadi 0).</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm" id="btnIppiCutoffSave"><i class="bi bi-check-lg me-1"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upload Modal -->
<div class="modal fade" id="modalUploadIppi" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= base_url($base . '/upload') ?>" method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-1"></i> Upload Daftar Part <?= html_escape($type_label) ?> (Excel)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary">
                        Pakai <a href="<?= base_url($base . '/template') ?>">template</a>-nya: <strong>No, Part No, Part Name, Plant,
                        Cutoff VIN</strong>. Plant diisi <code>KAP1</code> atau <code>KAP2</code>. Cutoff VIN boleh kosong (cutoff yang
                        sudah ada tidak berubah); kalau diisi, VIN harus ada di data WIP <?= html_escape($wos_code) ?> line tersebut.
                    </p>
                    <label class="upload-dropzone d-block mb-3" id="dropzoneIppi">
                        <i class="bi bi-file-earmark-excel fs-2 d-block mb-1"></i>
                        <span id="dropzoneIppiLabel">Click to choose an .xlsx file or drag it here</span>
                        <input type="file" name="ippi_fti_file" id="ippi_fti_file" accept=".xlsx" class="d-none" required>
                    </label>
                    <div class="mb-2">
                        <label class="form-label small">Upload Mode</label>
                        <select name="mode" class="form-select form-select-sm">
                            <option value="append">Append / update (same Plant + Part No is refreshed)</option>
                            <option value="replace">Replace (clear the whole <?= html_escape($type_label) ?> list first)</option>
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
    var IPPI_BASE = <?= json_encode($base) ?>;
    var IPPI_LABEL = <?= json_encode($type_label) ?>;
    var IPPI_WOS = <?= json_encode($wos_code) ?>;
</script>
