<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="page-title mb-0">Master BOM</h1>
    <div class="d-flex gap-2">
        <a href="<?= base_url('bom/export') ?>" class="btn btn-success btn-sm" id="btnDownloadBom">
            <i class="bi bi-file-earmark-arrow-down me-1"></i> Download
        </a>
        <a href="<?= base_url('bom/template') ?>" class="btn btn-secondary btn-sm">
            <i class="bi bi-download me-1"></i> Download Template
        </a>
        <?php if (($auth_user['role'] ?? '') === 'admin'): ?>
        <button type="button" class="btn btn-success btn-sm" id="btnAddBom">
            <i class="bi bi-plus-circle me-1"></i> Tambah Manual
        </button>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalUpload">
            <i class="bi bi-upload me-1"></i> Upload BOM
        </button>
        <?php endif; ?>
    </div>
</div>

<div id="bomUploadOverlay" class="page-loading-overlay d-none">
    <div class="spinner-border text-primary" role="status"></div>
    <div class="mt-2 small text-secondary">Uploading BOM data, please wait...</div>
</div>

<?php // Total baris tidak dikartukan lagi — kartu "All" di filter Model sudah menampilkannya. ?>
<?php if (!empty($model_summary)): ?>
<div class="card mb-3">
    <div class="card-header">
        <i class="bi bi-funnel me-1"></i> Filter by Model
    </div>
    <div class="card-body">
        <div class="row g-2" id="modelCards">
            <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                <div class="model-card active" data-model="">
                    <div class="model-card-code">All</div>
                    <div class="model-card-count"><?= number_format($total_bom) ?> parts</div>
                </div>
            </div>
            <?php foreach ($model_summary as $m): ?>
            <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                <div class="model-card" data-model="<?= html_escape($m['model']) ?>">
                    <div class="model-card-code"><?= html_escape($m['model']) ?></div>
                    <div class="model-card-count"><?= number_format($m['total']) ?> parts</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-1"></i> BOM List
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="tblBom" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Material</th>
                        <th>Katashiki</th>
                        <th>Model</th>
                        <th>Suffix</th>
                        <th>Component</th>
                        <th>Part Number</th>
                        <th>Material Description</th>
                        <th>Qty</th>
                        <th>Uom</th>
                        <th>Shop Code</th>
                        <?php if (($auth_user['role'] ?? '') === 'admin'): ?><th>Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<?php if (($auth_user['role'] ?? '') === 'admin'): ?>
<!-- Edit Modal -->
<div class="modal fade" id="modalEditBom" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="formEditBom">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-1"></i> <span id="bomModalTitle">Edit BOM Entry</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="bomEditAlert" class="alert alert-danger d-none py-2 small"></div>
                    <input type="hidden" id="bomEditId" value="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small">Material</label>
                            <input type="text" class="form-control form-control-sm" id="bomEdit_material" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Katashiki</label>
                            <input type="text" class="form-control form-control-sm" id="bomEdit_katashiki" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Model</label>
                            <input type="text" class="form-control form-control-sm" id="bomEdit_model" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Suffix</label>
                            <input type="text" class="form-control form-control-sm" id="bomEdit_suffix" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Component</label>
                            <input type="text" class="form-control form-control-sm" id="bomEdit_component" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Part Number</label>
                            <input type="text" class="form-control form-control-sm" id="bomEdit_part_number" autocomplete="off">
                            <div class="form-text">Dikosongkan = diambil dari Component (akhiran &quot;-00&quot; dibuang).</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Material Description</label>
                            <input type="text" class="form-control form-control-sm" id="bomEdit_material_description" autocomplete="off">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Qty</label>
                            <input type="number" step="0.001" class="form-control form-control-sm" id="bomEdit_qty" autocomplete="off">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Uom</label>
                            <input type="text" class="form-control form-control-sm" id="bomEdit_uom" autocomplete="off">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Shop Code</label>
                            <input type="text" class="form-control form-control-sm text-uppercase" id="bomEdit_shop_code" autocomplete="off">
                            <div class="form-text">Boleh lebih dari satu, pisahkan koma: <code>WELD3,ASSY3</code>.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm" id="btnSaveEditBom">
                        <i class="bi bi-check-lg me-1"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Upload Modal -->
<div class="modal fade" id="modalUpload" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= base_url('bom/upload') ?>" method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-1"></i> Upload BOM (Excel)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary">
                        File must use the standard template: <strong>Material, Katashiki, Model, Suffix, Component, Material Description, Qty, Uom, Shop Code</strong>.
                        <code>part_number</code> will be generated automatically from <code>Component</code> (trailing "-00" removed).
                        Shop Code can list more than one shop, separated by commas (e.g. <code>WELD3,ASSY3,TOSO3</code>).
                    </p>
                    <label class="upload-dropzone d-block mb-3" id="dropzone">
                        <i class="bi bi-file-earmark-excel fs-2 d-block mb-1"></i>
                        <span id="dropzoneLabel">Click to choose an .xlsx file or drag it here</span>
                        <input type="file" name="bom_file" id="bom_file" accept=".xlsx" class="d-none" required>
                    </label>
                    <div class="mb-2">
                        <label class="form-label small">Upload Mode</label>
                        <select name="mode" class="form-select form-select-sm">
                            <option value="append">Append (add to existing data)</option>
                            <option value="replace">Replace (clear existing data first)</option>
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
