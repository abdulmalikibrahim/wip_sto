<?php
$shop_keys = array_keys($stages);
// Welding is the card shown first.
$default_stage = in_array('weld', $shop_keys, true) ? 'weld' : ($shop_keys[0] ?? '');
$label_of = function ($key) use ($shops) {
    return $key === 'total' ? 'Total' : ($shops[$key] ?? strtoupper($key));
};
$units_text = function ($card) use ($stages, $label_of) {
    return implode(' + ', array_map($label_of, $stages[$card] ?? array()));
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="page-title mb-0"><?= html_escape($title) ?></h1>
</div>

<div class="alert alert-secondary small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    Tiap card hanya berisi <strong>part shop itu sendiri</strong> (part yang Shop Code-nya mencantumkan shop tersebut), tapi
    WIP-nya dihitung dari <strong>unit mulai shop itu sampai Assy</strong>, karena unit sesudahnya sudah membawa part tersebut:
    <strong>IPI</strong> / <strong>FTI</strong> = part di daftar WIP Calc IPI / FTI, unit WOS IPI / WOS FTI (Net dari cutoff VIN
    IPI / FTI) + Welding + Toso + Assy; <strong>Welding</strong> = part Welding lainnya, unit Welding + Toso + Assy;
    <strong>Toso</strong> = unit Toso + Assy; <strong>Assy</strong> = unit Assy saja. Di shop card itu sendiri dihitung <strong>Net</strong> seperti WIP Calc (mulai
    cutoff VIN part itu, 0 kalau belum ada cutoff VIN); di shop lainnya dihitung semua unit. Card <strong>Total</strong> = jumlah semua
    card, dan menampilkan semua part tanpa filter card.
    Untuk <strong>KAP 1 &amp; 2</strong>, part yang sama di kedua line dijumlahkan jadi satu baris.
</div>

<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-3">
            <?php foreach ($shop_keys as $key): ?>
            <div class="col-sm-6 col-lg-4 col-xxl-2">
                <div class="stat-card stat-card-filter<?= $key === $default_stage ? ' active' : '' ?>" data-stage="<?= html_escape($key) ?>" title="Show the <?= html_escape($label_of($key)) ?> summary">
                    <div class="stat-icon"><i class="bi bi-bar-chart-steps"></i></div>
                    <div class="flex-grow-1">
                        <div class="stat-label">Summary <?= html_escape($label_of($key)) ?></div>
                        <div class="stat-value my-1" data-stage-total>&mdash;</div>
                        <div class="stat-label small">
                            Part <?= html_escape($label_of($key)) ?> &middot; unit <?= html_escape($units_text($key)) ?>
                            &middot; <span data-stage-parts>&mdash;</span> part
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="col-sm-6 col-lg-4 col-xxl-2">
                <div class="stat-card stat-card-filter" data-stage="total" title="Show every part, no card filter">
                    <div class="stat-icon"><i class="bi bi-collection"></i></div>
                    <div class="flex-grow-1">
                        <div class="stat-label">Summary Total</div>
                        <div class="stat-value my-1" data-stage-total>&mdash;</div>
                        <div class="stat-label small">
                            Jumlah semua card &middot; <span data-stage-parts>&mdash;</span> part
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-text mt-2 mb-0"><i class="bi bi-cursor"></i> Klik salah satu kartu untuk melihat summary shop tersebut; <strong>Total</strong> menampilkan semua part.</div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap align-items-center gap-3">
        <div class="d-flex align-items-center gap-2">
            <span class="small text-secondary"><i class="bi bi-diagram-3 me-1"></i>Line:</span>
            <div class="btn-group btn-group-sm" role="group" aria-label="KAP line">
                <button type="button" class="btn btn-outline-primary active" data-scope-option="all">KAP 1 &amp; 2</button>
                <button type="button" class="btn btn-outline-primary" data-scope-option="kap1">KAP 1</button>
                <button type="button" class="btn btn-outline-primary" data-scope-option="kap2">KAP 2</button>
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
        <a href="<?= base_url('wip/summary/missing-cutoff') ?>" class="btn btn-sm btn-outline-warning" title="Part yang Summary-nya terisi padahal cutoff VIN shop sendiri belum ada">
            <i class="bi bi-exclamation-diamond"></i> Tanpa Cutoff VIN
        </a>
        <button type="button" class="btn btn-sm btn-primary" id="btnToggleHideZero" title="Zero rows are hidden automatically — click to show them">
            <i class="bi bi-eye"></i> Hide Zero Rows
        </button>
        <a href="<?= base_url('wip/summary/export') ?>" class="btn btn-sm btn-success" id="btnExportSummary">
            <i class="bi bi-file-earmark-excel"></i> Download Excel
        </a>
    </div>
</div>

<div id="summaryAlert" class="alert alert-danger d-none"></div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-1"></i> Summary <span id="summaryStageName"><?= html_escape($label_of($default_stage)) ?></span>
        <span class="text-secondary small ms-1">&mdash; <span id="summaryFormula">Part <?= html_escape($label_of($default_stage)) ?> &middot; unit <?= html_escape($units_text($default_stage)) ?></span></span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="tblWipSummary" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Source</th>
                        <th>Part Number</th>
                        <th>Material Description</th>
                        <th>UOM</th>
                        <th>Shop Code</th>
                        <?php // One column group per card — same order as wip_summary.js builds them; only the selected card's group is shown. ?>
                        <?php foreach ($stages as $card => $units): ?>
                            <?php foreach ($units as $unit_shop): ?>
                        <th class="text-center"><?= html_escape($label_of($unit_shop)) ?></th>
                            <?php endforeach; ?>
                        <th class="text-center col-group-start">Summary <?= html_escape($label_of($card)) ?></th>
                        <th><?= html_escape($label_of($card)) ?> Cutoff VIN</th>
                        <?php endforeach; ?>
                        <?php // The Total card's group: every card's value, then their sum. ?>
                        <?php foreach ($stages as $card => $units): ?>
                        <th class="text-center">Summary <?= html_escape($label_of($card)) ?></th>
                        <?php endforeach; ?>
                        <th class="text-center col-group-start">Total</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script>
    var WIP_SUMMARY_SHOPS = <?= json_encode($shops) ?>;
    var WIP_SUMMARY_STAGES = <?= json_encode($stages) ?>;
</script>
