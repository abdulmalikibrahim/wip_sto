<?php $is_admin = ($auth_user['role'] ?? '') === 'admin'; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="page-title mb-0">Juklak</h1>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= base_url('juklak/export') ?>" class="btn btn-success btn-sm btn-juklak-download">
            <i class="bi bi-file-earmark-arrow-down me-1"></i> Download
        </a>
        <a href="<?= base_url('juklak/template') ?>" class="btn btn-secondary btn-sm btn-juklak-download">
            <i class="bi bi-download me-1"></i> Download Template
        </a>
        <?php if ($is_admin): ?>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalUploadJuklak" <?= $table_ready ? '' : 'disabled' ?>>
            <i class="bi bi-upload me-1"></i> Upload Juklak
        </button>
        <?php endif; ?>
    </div>
</div>

<div id="juklakUploadOverlay" class="page-loading-overlay d-none">
    <div class="spinner-border text-primary" role="status"></div>
    <div class="mt-2 small text-secondary">Uploading Juklak, please wait...</div>
</div>

<?php if (!$table_ready): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Tabel <code>juklak</code> belum ada di database ini. Jalankan
    <code>database/migrations/2026_09_15_create_juklak.sql</code> dulu (mis. lewat tab SQL di phpMyAdmin).
</div>
<?php endif; ?>

<div class="alert alert-secondary small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    Juklak menentukan <strong>Main Part No</strong> untuk tiap <strong>Part No</strong> per plant. Kalau Main Part No-nya
    <em>berbeda</em> dengan Part No-nya (mis. Part No <code>42600-BY540-00</code>, main <code>42600-BY530-00</code>), part itu
    dianggap sudah diwakili main part-nya, jadi <strong>dihitung 0</strong> di WIP Calc KAP 1 / KAP 2 / KAP 1 &amp; 2 dan
    WIP Summary untuk plant tersebut. Main part tetap dihitung seperti biasa. Qty per suffix disimpan sebagai referensi.
</div>

<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-3">
            <?php foreach (array('total' => 'Total Juklak Rows', 'kap1' => 'KAP1', 'kap2' => 'KAP2', 'replaced' => 'Dihitung 0 (bukan main)') as $key => $label): ?>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi <?= $key === 'replaced' ? 'bi-slash-circle' : 'bi-journal-check' ?>"></i></div>
                    <div>
                        <div class="stat-value" data-stat="<?= $key ?>">&mdash;</div>
                        <div class="stat-label"><?= html_escape($label) ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-table me-1"></i> Juklak</div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="tblJuklak" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Plant</th>
                        <th>Part No</th>
                        <th>Part Name</th>
                        <th>Main Part No</th>
                        <th>Status</th>
                        <th>Qty per Suffix</th>
                        <?php if ($is_admin): ?><th>Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($is_admin): ?>
<!-- Upload Modal -->
<div class="modal fade" id="modalUploadJuklak" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= base_url('juklak/upload') ?>" method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-1"></i> Upload Juklak (Excel)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary">
                        Pakai <a href="<?= base_url('juklak/template') ?>">template</a>-nya: baris 1 berisi <strong>No, Part No,
                        Main Part No</strong>, lalu satu kolom per <strong>Suffix</strong> (isi Qty di bawahnya), dan terakhir
                        <strong>Plant</strong> (<code>KAP1</code> atau <code>KAP2</code>). Data mulai baris 2. Main Part No yang
                        kosong dianggap sama dengan Part No-nya.
                    </p>
                    <label class="upload-dropzone d-block mb-3" id="dropzoneJuklak">
                        <i class="bi bi-file-earmark-excel fs-2 d-block mb-1"></i>
                        <span id="dropzoneJuklakLabel">Click to choose an .xlsx file or drag it here</span>
                        <input type="file" name="juklak_file" id="juklak_file" accept=".xlsx" class="d-none" required>
                    </label>
                    <div class="mb-2">
                        <label class="form-label small">Upload Mode</label>
                        <select name="mode" class="form-select form-select-sm">
                            <option value="append">Append / update (same Plant + Part No is refreshed)</option>
                            <option value="replace">Replace (clear all Juklak data first)</option>
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
