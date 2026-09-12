<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="page-title mb-0">Part List</h1>
    <div class="d-flex gap-2">
        <a href="<?= base_url('part-list/export') ?>" class="btn btn-success btn-sm" id="btnDownloadPartList">
            <i class="bi bi-file-earmark-arrow-down me-1"></i> Download
        </a>
        <a href="<?= base_url('part-list/template') ?>" class="btn btn-secondary btn-sm">
            <i class="bi bi-download me-1"></i> Download Template
        </a>
        <?php if (($auth_user['role'] ?? '') === 'admin'): ?>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalUpload">
            <i class="bi bi-upload me-1"></i> Upload Part List
        </button>
        <?php endif; ?>
    </div>
</div>

<div id="partListUploadOverlay" class="page-loading-overlay d-none">
    <div class="spinner-border text-primary" role="status"></div>
    <div class="mt-2 small text-secondary">Uploading Part List data, please wait...</div>
</div>

<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-3">
            <div class="col-sm-4 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-ui-checks-grid"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($total_part_list) ?></div>
                        <div class="stat-label">Total Part List Records</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

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
                    <div class="model-card-count"><?= number_format($total_part_list) ?> parts</div>
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

<div class="card mb-3">
    <div class="card-header">
        <i class="bi bi-table me-1"></i> Part List
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="tblPartList" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>No</th>
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

<hr class="my-4">

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h2 class="page-title mb-0" style="font-size:1.1rem;"><i class="bi bi-arrow-left-right me-1"></i> Compare: Master BOM vs Part List</h2>
</div>

<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-3">
            <div class="col-6 col-sm-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(220, 53, 69, 0.15); color: #dc3545;"><i class="bi bi-dash-circle"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($compare_summary['only_bom']) ?></div>
                        <div class="stat-label">Only in Master BOM</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-sm-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(255, 193, 7, 0.15); color: #ffc107;"><i class="bi bi-plus-circle"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($compare_summary['only_part_list']) ?></div>
                        <div class="stat-label">Only in Part List</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-sm-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(13, 202, 240, 0.15); color: #0dcaf0;"><i class="bi bi-exclamation-triangle"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($compare_summary['mismatch_parts']) ?></div>
                        <div class="stat-label">Different Values</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-sm-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(25, 135, 84, 0.15); color: #198754;"><i class="bi bi-check-circle"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($compare_summary['matching']) ?></div>
                        <div class="stat-label">Matching</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-list-check me-1"></i> Differences</span>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="btn-group btn-group-sm" id="statusFilter" role="group">
                <button type="button" class="btn btn-outline-secondary active" data-status="">All</button>
                <button type="button" class="btn btn-outline-danger" data-status="only_bom">Only in Master BOM</button>
                <button type="button" class="btn btn-outline-warning" data-status="only_part_list">Only in Part List</button>
                <button type="button" class="btn btn-outline-info" data-status="mismatch">Different</button>
            </div>
            <a href="<?= base_url('part-list/compare/export') ?>" class="btn btn-success btn-sm" id="btnDownloadCompare" title="Download the differences currently shown (respects the Status filter and search box above)">
                <i class="bi bi-file-earmark-arrow-down me-1"></i> Download Excel
            </a>
        </div>
    </div>
    <div class="card-body">
        <p class="small text-secondary mb-2">
            Parts are matched between Master BOM and Part List by <strong>Model + Suffix + Part Number</strong>.
            Each row below is one concrete difference — a part missing on one side, or one field whose value disagrees between the two sides.
        </p>
        <div class="table-responsive">
            <table id="tblCompare" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Status</th>
                        <th>Model</th>
                        <th>Suffix</th>
                        <th>Component</th>
                        <th>Part Number</th>
                        <th>Field</th>
                        <th>Master BOM Value</th>
                        <th>Part List Value</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Upload Modal -->
<div class="modal fade" id="modalUpload" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= base_url('part-list/upload') ?>" method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-1"></i> Upload Part List (Excel)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary">
                        File must use the <a href="<?= base_url('part-list/template') ?>">standard template</a>: <strong>Part No, Part Name, Shop, Model</strong>, followed by one column per <strong>Suffix</strong> — put the Qty in the cell where that part is used at that suffix, leave it blank where it isn't.
                        <code>part_number</code> is generated automatically from <code>Part No</code> (trailing "-00" removed). Shop can list more than one shop, separated by commas (e.g. <code>WELD3,ASSY3,TOSO3</code>).
                    </p>
                    <p class="small text-secondary">
                        Each Suffix column's Model is looked up from Master BOM automatically (not read from the Model column, which is just free text here) — a Suffix Master BOM doesn't recognize yet is still uploaded, just flagged in the result message. Non-numeric cells (e.g. "X") are skipped and reported, not guessed at.
                    </p>
                    <label class="upload-dropzone d-block mb-3" id="dropzone">
                        <i class="bi bi-file-earmark-excel fs-2 d-block mb-1"></i>
                        <span id="dropzoneLabel">Click to choose an .xlsx file or drag it here</span>
                        <input type="file" name="part_list_file" id="part_list_file" accept=".xlsx" class="d-none" required>
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
