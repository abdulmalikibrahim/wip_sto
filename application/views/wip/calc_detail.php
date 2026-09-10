<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="page-title mb-0"><?= html_escape($title) ?></h1>
    <a href="<?= base_url($source . '/calc') ?>" class="btn btn-sm btn-secondary">
        <i class="bi bi-arrow-left"></i> Back to WIP Calc List
    </a>
</div>

<div class="d-flex flex-wrap justify-content-end align-items-center gap-2 mb-3">
    <a href="<?= base_url($source . '/calc/detail/export') ?>" class="btn btn-sm btn-success" id="btnExportCalcDetail">
        <i class="bi bi-file-earmark-excel"></i> Download Excel
    </a>
</div>

<div id="calcDetailAlert" class="alert alert-danger d-none"></div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-list-check me-1"></i> WIP Calc Detail
        <span class="text-secondary small ms-1">— one row per BOM line that actually matched a WIP unit, showing the cutoff VIN and the Subtotal's formula. BOM lines with no matching unit (0 units × qty = 0) aren't listed here, since they don't contribute anything to the totals.</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="tblWipCalcDetail" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Part Number</th>
                        <th>Material</th>
                        <th>Material Description</th>
                        <th>Model</th>
                        <th>Suffix</th>
                        <th>Shop</th>
                        <th>Cutoff VIN</th>
                        <th>Formula</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Formula Detail Modal -->
<div class="modal fade" id="modalFormula" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calculator me-1"></i> Formula Detail</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalFormulaBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    var WIP_CALC_SOURCE = <?= json_encode($source) ?>;
</script>
