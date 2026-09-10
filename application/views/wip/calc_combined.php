<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="page-title mb-0"><?= html_escape($title) ?></h1>
</div>

<div class="alert alert-secondary small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    This is <strong>KAP 1</strong>'s and <strong>KAP 2</strong>'s WIP Calc lists shown together. The
    <strong>Source</strong> column marks which line each row came from — except when the same Part Number appears in
    both lines with <em>no cutoff VIN set on either side</em>: those are summed into one row marked
    <span class="badge text-bg-success">Both</span>, since there's nothing cutoff-specific to keep separate. If a
    cutoff VIN is set on either side, the two rows stay separate instead of being blended together. Cutoff VINs are
    still managed on the individual <a href="<?= base_url('wip/kap1/calc') ?>">WIP Calc. KAP 1</a> /
    <a href="<?= base_url('wip/kap2/calc') ?>">WIP Calc. KAP 2</a> pages.
</div>

<div class="d-flex flex-wrap justify-content-end align-items-center gap-2 mb-3">
    <button type="button" class="btn btn-sm btn-secondary" id="btnToggleHideZero">
        <i class="bi bi-eye-slash"></i> Hide Zero-Total Rows
    </button>
    <a href="<?= base_url('wip/calc-combined/export') ?>" class="btn btn-sm btn-success" id="btnExportCalc">
        <i class="bi bi-file-earmark-excel"></i> Download Excel
    </a>
</div>

<div id="calcAlert" class="alert alert-danger d-none"></div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-1"></i> WIP Calc List — KAP 1 &amp; 2
        <span class="text-secondary small ms-1">— each shop's Cutoff VIN column shows the VIN used to work out that row's quantity (blank = totaled, no cutoff set yet)</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="tblWipCalc" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th rowspan="2">No</th>
                        <th rowspan="2">Source</th>
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

<script>
    var WIP_CALC_SHOPS = <?= json_encode($shops) ?>;
</script>
