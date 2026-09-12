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

<ul class="nav nav-tabs shop-tabs mb-3" id="shopTabs">
    <?php $is_admin = ($auth_user['role'] ?? '') === 'admin'; ?>
    <?php $first = true; foreach ($shops as $key => $label): ?>
    <li class="nav-item">
        <button class="nav-link <?= $is_admin ? 'has-clear' : '' ?> <?= $first ? 'active' : '' ?>" data-shop="<?= $key ?>" type="button">
            <?= html_escape($label) ?>
        </button>
        <?php if ($is_admin): ?>
        <button type="button" class="btn-clear-shop" data-shop="<?= $key ?>" data-shop-label="<?= html_escape($label) ?>" title="Clear cached <?= html_escape($label) ?> WIP data">
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
        <?php if (($auth_user['role'] ?? '') === 'admin'): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalUploadWip">
            <i class="bi bi-upload"></i> Upload Excel
        </button>
        <?php endif; ?>
        <button class="btn btn-sm btn-success" id="btnDownload" type="button">
            <i class="bi bi-file-earmark-excel"></i> Download Excel
        </button>
        <button class="btn btn-sm btn-secondary" id="btnGetWip" type="button">
            <i class="bi bi-cloud-arrow-down"></i> Get Data WIP
        </button>
    </li>
</ul>

<div id="wipAlert" class="alert alert-danger d-none"></div>
<div id="wipEmptyHint" class="alert alert-info d-none">
    No cached data yet. Click <strong>Get Data WIP</strong> to pull from the WIP server, or use
    <strong>Upload Excel</strong> if the server is down.
</div>

<div class="card" id="tableView">
    <div class="card-body">
        <div class="table-responsive">
            <table id="tblWip" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>VIN</th>
                        <th>Suffix</th>
                        <th>Katashiki</th>
                        <th>Model</th>
                        <th>Color Code</th>
                        <th>Color Desc</th>
                        <th>Last Scan</th>
                        <th>Scan Date</th>
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

<!-- Upload Modal -->
<div class="modal fade" id="modalUploadWip" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= base_url($source . '/upload') ?>" method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-1"></i> Upload WIP Data (Excel)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary">
                        Fallback for when the WIP server is down. File must use the
                        <a href="<?= base_url($source . '/template') ?>">standard template</a>:
                        <strong>VIN, Suffix, Katashiki, Model, Color Code, Color Desc, Last Scan, Scan Date, Shop Code</strong>.
                        Shop Code must be one of: <?= html_escape(implode(', ', array_map('strtoupper', $shops))) ?>.
                    </p>
                    <label class="upload-dropzone d-block mb-3" id="dropzoneWip">
                        <i class="bi bi-file-earmark-excel fs-2 d-block mb-1"></i>
                        <span id="dropzoneWipLabel">Click to choose an .xlsx file or drag it here</span>
                        <input type="file" name="wip_file" id="wip_file" accept=".xlsx" class="d-none" required>
                    </label>
                    <div class="mb-2">
                        <label class="form-label small">Upload Mode</label>
                        <select name="mode" class="form-select form-select-sm">
                            <option value="append">Append (add to existing data)</option>
                            <option value="replace">Replace (clear existing data for this source first)</option>
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

<script>
    var WIP_SOURCE = <?= json_encode($source) ?>;
</script>
