<?php
$is_admin = ($auth_user['role'] ?? '') === 'admin';
$can_decide = $is_admin && $decision_ready;
$label_of = function ($key) use ($shops) {
    return $shops[$key] ?? strtoupper($key);
};
$units_text = function ($card) use ($stages, $label_of) {
    return implode(' + ', array_map($label_of, $stages[$card] ?? array()));
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="page-title mb-0"><?= html_escape($title) ?></h1>
    <a href="<?= base_url('wip/summary') ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> WIP Summary
    </a>
</div>

<?php if (!$decision_ready): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Tabel <code>wip_part_decision</code> belum ada, jadi kolom <strong>Keputusan</strong> belum bisa diisi. Jalankan
    <code>database/migrations/2026_09_16_create_wip_part_decision.sql</code> dulu (mis. lewat tab SQL di phpMyAdmin).
</div>
<?php endif; ?>

<div class="alert alert-secondary small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    Daftar part di <a href="<?= base_url('wip/summary') ?>">WIP Summary</a> yang <strong>Summary-nya terisi padahal cutoff VIN
    di shop sendiri belum ada</strong>. Di card sebuah shop, unit di shop itu sendiri baru dihitung mulai cutoff VIN (Net 0 kalau
    belum ada), sedangkan unit di shop sesudahnya (mis. Assy untuk part Toso) selalu dihitung semua &mdash; jadi angka Summary-nya
    hanya berasal dari shop sesudahnya. Status <strong>stale</strong> = cutoff VIN sudah diisi tapi VIN-nya sudah tidak ada di data
    WIP, sehingga semua unit shop sendiri ikut dihitung. Satu baris per line (KAP 1 / KAP 2) &times; card &times; part; part Juklak
    (dihitung 0) tidak ikut. Klik <strong>Detail</strong> untuk rincian per Model / Suffix.
</div>

<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-3">
            <div class="col-sm-6 col-lg-4 col-xxl-2">
                <div class="stat-card stat-card-filter active" data-card-filter="all" title="Tampilkan semua card">
                    <div class="stat-icon"><i class="bi bi-collection"></i></div>
                    <div class="flex-grow-1">
                        <div class="stat-label">Semua Card</div>
                        <div class="stat-value my-1"><span data-card-parts>&mdash;</span> <span class="fs-6 fw-normal">part</span></div>
                        <div class="stat-label small">Summary <span data-card-total>&mdash;</span></div>
                    </div>
                </div>
            </div>
            <?php foreach ($stages as $card => $units): ?>
            <div class="col-sm-6 col-lg-4 col-xxl-2">
                <div class="stat-card stat-card-filter" data-card-filter="<?= html_escape($card) ?>" title="Tampilkan card <?= html_escape($label_of($card)) ?> saja">
                    <div class="stat-icon"><i class="bi bi-exclamation-diamond"></i></div>
                    <div class="flex-grow-1">
                        <div class="stat-label">Card <?= html_escape($label_of($card)) ?></div>
                        <div class="stat-value my-1"><span data-card-parts>&mdash;</span> <span class="fs-6 fw-normal">part</span></div>
                        <div class="stat-label small">
                            Summary <span data-card-total>&mdash;</span> &middot; unit <?= html_escape($units_text($card)) ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="form-text mt-2 mb-0"><i class="bi bi-cursor"></i> Klik salah satu kartu untuk memfilter list ke card tersebut; <strong>Semua Card</strong> menampilkan semuanya.</div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap align-items-center gap-3">
        <div class="d-flex align-items-center gap-2">
            <span class="small text-secondary"><i class="bi bi-diagram-3 me-1"></i>Line:</span>
            <div class="btn-group btn-group-sm" role="group" aria-label="KAP line">
                <?php if (!empty($locked_scope)): /* scoped User: its own line only */ ?>
                <button type="button" class="btn btn-outline-primary active" data-scope-option="<?= html_escape($locked_scope) ?>"><?= html_escape(strtoupper(str_replace('kap', 'KAP ', $locked_scope))) ?></button>
                <?php else: ?>
                <button type="button" class="btn btn-outline-primary active" data-scope-option="all">KAP 1 &amp; 2</button>
                <button type="button" class="btn btn-outline-primary" data-scope-option="kap1">KAP 1</button>
                <button type="button" class="btn btn-outline-primary" data-scope-option="kap2">KAP 2</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="small text-secondary"><i class="bi bi-database me-1"></i>Calculate from:</span>
            <div class="btn-group btn-group-sm" role="group" aria-label="Calculation basis">
                <button type="button" class="btn btn-outline-primary" data-basis-option="bom">Master BOM</button>
                <button type="button" class="btn btn-outline-primary" data-basis-option="part_list">Part List</button>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="small text-secondary"><i class="bi bi-funnel me-1"></i>Status:</span>
            <select class="form-select form-select-sm w-auto" id="missingStatusFilter">
                <option value="">Semua status</option>
                <option value="none">Belum ada cutoff VIN</option>
                <option value="stale">Cutoff VIN stale</option>
            </select>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="small text-secondary"><i class="bi bi-check2-square me-1"></i>Keputusan:</span>
            <select class="form-select form-select-sm w-auto" id="missingDecisionFilter">
                <option value="">Semua keputusan</option>
                <option value="undecided">Belum diputuskan</option>
                <option value="counted">Dihitung</option>
                <option value="excluded">Tidak dihitung</option>
            </select>
        </div>
    </div>
    <a href="<?= base_url('wip/summary/missing-cutoff/export') ?>" class="btn btn-sm btn-success" id="btnExportMissing">
        <i class="bi bi-file-earmark-excel"></i> Download Excel
    </a>
</div>

<div id="missingAlert" class="alert alert-danger d-none"></div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-1"></i> Part Tanpa Cutoff VIN
        <span class="text-secondary small ms-1">&mdash; <strong>Gross</strong> = semua unit di shop sendiri (yang belum dihitung), Welding / Toso / Assy = unit di shop sesudahnya yang ikut dihitung (&middot; = tidak dihitung di card itu)</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="tblMissingCutoff" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Line</th>
                        <th>Card</th>
                        <th>Part Number</th>
                        <th>Material Description</th>
                        <th>Shop Code</th>
                        <th>Status Cutoff</th>
                        <th class="text-center">Shop Sendiri</th>
                        <th class="text-center col-group-start" title="Semua unit di shop sendiri × Qty — yang akan terhitung kalau cutoff VIN di-set di unit paling awal">Gross Shop Sendiri</th>
                        <th class="text-center" title="Yang dihitung card ini di shop sendiri: 0 kalau belum ada cutoff, semua unit kalau stale">Terhitung Shop Sendiri</th>
                        <th class="text-center col-group-start" title="Unit di Welding (dihitung semua)">Welding</th>
                        <th class="text-center" title="Unit di Toso (dihitung semua)">Toso</th>
                        <th class="text-center" title="Unit di Assy (dihitung semua)">Assy</th>
                        <th class="text-center col-group-start">Summary</th>
                        <th class="text-center">Status</th>
                        <th>Action</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Detail Modal — per Model / Suffix breakdown of one row -->
<div class="modal fade" id="modalMissingDetail" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-search me-1"></i> Detail Part Tanpa Cutoff VIN</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalMissingDetailBody"></div>
            <div class="modal-footer">
                <a href="#" class="btn btn-primary btn-sm d-none" id="btnMissingOpenCalc" target="_blank">
                    <i class="bi bi-calculator me-1"></i> Buka WIP Calc (set cutoff VIN)
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php if ($can_decide): ?>
<!-- Alasan "tidak dihitung" -->
<div class="modal fade" id="modalMissingReason" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="formMissingReason">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-slash-circle me-1"></i> Tandai Tidak Dihitung</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="missingReasonAlert" class="alert alert-danger d-none py-2 small"></div>
                    <input type="hidden" id="missingReasonPlant" value="">
                    <input type="hidden" id="missingReasonPart" value="">
                    <dl class="row small mb-3">
                        <dt class="col-4">Line</dt><dd class="col-8" id="missingReasonLine">&mdash;</dd>
                        <dt class="col-4">Part Number</dt><dd class="col-8" id="missingReasonPartLabel">&mdash;</dd>
                    </dl>
                    <div class="alert alert-warning py-2 px-3 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Part ini akan <strong>dihitung 0</strong> di WIP Calc dan WIP Summary untuk line tersebut (semua shop),
                        dan alasannya ditampilkan sebagai keterangan.
                    </div>
                    <label class="form-label small">Alasan <span class="text-danger">*</span></label>
                    <textarea class="form-control form-control-sm" id="missingReasonText" rows="3" maxlength="255" required
                              placeholder="mis. part belum implementasi, jadi belum terpasang di unit"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm" id="btnSaveMissingReason">
                        <i class="bi bi-check-lg me-1"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    var WIP_MISSING_SHOPS = <?= json_encode($shops) ?>;
    var WIP_MISSING_STAGES = <?= json_encode($stages) ?>;
    var WIP_MISSING_CAN_DECIDE = <?= $can_decide ? 'true' : 'false' ?>;
</script>
