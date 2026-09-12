<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="page-title mb-0"><?= html_escape($title) ?></h1>
</div>

<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-3" id="cutoffStatus">
            <?php foreach ($shops as $key => $label): ?>
            <div class="col-sm-4">
                <div class="stat-card stat-card-filter" data-cutoff-shop="<?= $key ?>" title="Click to show only <?= html_escape($label) ?> data">
                    <div class="stat-icon"><i class="bi bi-signpost-2"></i></div>
                    <div class="flex-grow-1">
                        <div class="stat-value fs-6" data-cutoff-summary>&mdash;</div>
                        <div class="stat-label"><?= html_escape($label) ?> parts with a cutoff VIN</div>
                    </div>
                    <?php if (($auth_user['role'] ?? '') === 'admin'): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger btn-clear-cutoff" data-shop="<?= $key ?>" data-shop-label="<?= html_escape($label) ?>" title="Clear all <?= html_escape($label) ?> cutoff VINs (back to Gross totals)">
                        <i class="bi bi-x-circle"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="form-text mt-2 mb-0"><i class="bi bi-cursor"></i> Click a shop above to filter the list to just that shop; click it again (or the &times; below) to show all shops. Click <i class="bi bi-x-circle"></i> to clear that shop's cutoff VINs (back to Gross totals).</div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-end align-items-center gap-2 mb-3">
    <a href="<?= base_url($source . '/calc/detail') ?>" class="btn btn-sm btn-info">
        <i class="bi bi-list-check"></i> View Calculation Detail
    </a>
    <button type="button" class="btn btn-sm btn-secondary" id="btnToggleHideZero">
        <i class="bi bi-eye-slash"></i> Hide Zero-Total Rows
    </button>
    <a href="<?= base_url($source . '/calc/export') ?>" class="btn btn-sm btn-success" id="btnExportCalc">
        <i class="bi bi-file-earmark-excel"></i> Download Excel
    </a>
    <a href="<?= base_url($source . '/calc/template') ?>" class="btn btn-sm btn-secondary">
        <i class="bi bi-download"></i> Download Cutoff Template
    </a>
    <?php if (($auth_user['role'] ?? '') === 'admin'): ?>
    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalSetCutoff">
        <i class="bi bi-plus-circle"></i> Add Cutoff VIN
    </button>
    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalUploadCutoff">
        <i class="bi bi-upload"></i> Upload Cutoff VIN
    </button>
    <?php endif; ?>
</div>

<div id="calcAlert" class="alert alert-danger d-none"></div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-1"></i> WIP Calc List
        <span class="text-secondary small ms-1">— each shop's Cutoff VIN column shows the VIN used to work out that row's quantity (blank = totaled, no cutoff set yet)</span>
        <span id="calcShopFilterBadge" class="badge bg-primary ms-2 d-none">
            Showing: <span id="calcShopFilterName"></span>
            <i class="bi bi-x-circle ms-1" id="btnClearShopFilter" role="button" title="Clear filter"></i>
        </span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="tblWipCalc" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th rowspan="2">No</th>
                        <th rowspan="2">Part Number</th>
                        <th rowspan="2">Material Description</th>
                        <th rowspan="2">Shop Code</th>
                        <?php foreach ($shops as $key => $label): ?>
                        <th colspan="3" class="text-center col-group-start"><?= html_escape($label) ?></th>
                        <?php endforeach; ?>
                        <th colspan="3" class="text-center col-group-start">Total</th>
                        <?php foreach ($shops as $key => $label): ?>
                        <th><?= html_escape($label) ?> Cutoff VIN</th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <?php for ($i = 0; $i < count($shops) + 1; $i++): ?>
                        <th class="col-group-start">Gross</th>
                        <th>Cutoff</th>
                        <th>Net</th>
                        <?php endfor; ?>
                        <?php foreach ($shops as $key => $label): ?>
                        <th></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mt-3">
    <button type="button" class="card-header collapse-toggle w-100 text-start border-0 bg-transparent d-flex align-items-center" data-bs-toggle="collapse" data-bs-target="#calcExplainCollapse" aria-expanded="false" aria-controls="calcExplainCollapse">
        <i class="bi bi-question-circle me-1"></i> Kenapa angkanya bisa seperti ini?
        <i class="bi bi-chevron-down ms-auto collapse-toggle-icon"></i>
    </button>
    <div class="collapse" id="calcExplainCollapse">
        <div class="card-body small text-secondary">
            <p class="mb-2">Berikut cara angka Welding/Toso/Assy di atas dihitung, siapa tahu ada yang kelihatan kecil atau 0:</p>
            <ul class="mb-2 ps-3">
                <li class="mb-1">Untuk setiap part, sistem menghitung unit dari data <a href="<?= base_url($source) ?>">Master WIP</a> yang tersimpan, yang Model + Suffix-nya cocok dengan baris BOM part tersebut, lalu dikalikan dengan Qty di BOM.</li>
                <li class="mb-1">Kalau sebuah part belum diupload cutoff VIN-nya, sistem menghitung <em>semua</em> unit yang ada di shop tersebut — tidak ada yang dikecualikan.</li>
                <li class="mb-1">Angka tetap bisa menunjukkan <strong>0</strong> meskipun belum ada cutoff, kalau data Master WIP untuk shop itu belum ditarik/diupload, atau memang belum ada unit yang Model + Suffix-nya cocok dengan part tersebut.</li>
                <li class="mb-1">Setelah cutoff VIN untuk sebuah part diupload (lihat tombol <strong>Upload Cutoff VIN</strong> di atas), hanya unit dari VIN itu ke atas yang akan dihitung untuk part tersebut — arahkan kursor ke angkanya untuk melihat VIN cutoff yang dipakai.</li>
                <li>Tiap shop punya 3 kolom: <strong>Gross</strong> = total kalau semua unit dihitung tanpa batas cutoff, <strong>Cutoff</strong> = bagian unit lama yang dikecualikan (sebelum VIN cutoff), <strong>Net</strong> = Gross &minus; Cutoff (angka final yang sebenarnya terpakai).</li>
            </ul>
            <p class="mb-0">Singkatnya: angka yang kecil atau 0 biasanya cuma berarti data WIP atau cutoff VIN untuk part itu belum tersedia, bukan berarti ada yang salah.</p>
        </div>
    </div>
</div>

<!-- Upload Cutoff VIN Modal -->
<div class="modal fade" id="modalUploadCutoff" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="<?= base_url($source . '/calc/upload') ?>" method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-1"></i> Upload Cutoff VIN (Excel)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-secondary">
                        File must use the <a href="<?= base_url($source . '/calc/template') ?>">standard template</a>:
                        <strong>Part Number, VIN, Shop</strong>. One row per part number — each part is installed at a
                        different point along the line, so each one needs its own cutoff VIN. The VIN must already
                        exist in the cached <a href="<?= base_url($source) ?>">Master WIP</a> data for that Shop
                        (<?= html_escape(implode(', ', $shop_codes)) ?>).
                        Uploading again replaces the cutoff for the part(s) included in the file; parts left out keep
                        their current cutoff (or stay totaled if none was set yet).
                    </p>
                    <label class="upload-dropzone d-block mb-3" id="dropzoneCutoff">
                        <i class="bi bi-file-earmark-excel fs-2 d-block mb-1"></i>
                        <span id="dropzoneCutoffLabel">Click to choose an .xlsx file or drag it here</span>
                        <input type="file" name="cutoff_file" id="cutoff_file" accept=".xlsx" class="d-none" required>
                    </label>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i> Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Cutoff VIN Modal (single entry, AJAX only — no page reload) -->
<div class="modal fade" id="modalSetCutoff" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="formSetCutoff">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> Add Cutoff VIN</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="setCutoffAlert" class="alert alert-danger d-none py-2 small"></div>
                    <div class="mb-3">
                        <label class="form-label small">Shop</label>
                        <select class="form-select form-select-sm" name="shop_code" id="cutoffShopCode" required>
                            <?php foreach ($shops as $key => $label): ?>
                            <option value="<?= html_escape($shop_codes[$key] ?? '') ?>">
                                <?= html_escape($label) ?> (<?= html_escape($shop_codes[$key] ?? '') ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Part Number</label>
                        <input type="text" class="form-control form-control-sm" name="part_number" id="cutoffPartNumber" list="partNumberList" placeholder="e.g. 62765-BZ040" required autocomplete="off">
                        <datalist id="partNumberList"></datalist>
                    </div>
                    <div class="mb-1">
                        <label class="form-label small">VIN</label>
                        <input type="text" class="form-control form-control-sm text-uppercase" name="vin" id="cutoffVin" placeholder="e.g. MHKAB1BA9TJ176981" required autocomplete="off">
                        <div class="form-text">Must already exist in the cached <a href="<?= base_url($source) ?>" target="_blank">Master WIP</a> data for the selected Shop.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm" id="btnSaveCutoff">
                        <i class="bi bi-check-lg me-1"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    var WIP_CALC_SOURCE = <?= json_encode($source) ?>;
    var WIP_CALC_SHOPS = <?= json_encode($shops) ?>;
    var WIP_CALC_SHOP_CODES = <?= json_encode($shop_codes) ?>;
</script>
