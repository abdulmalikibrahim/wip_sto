<h1 class="page-title">Dashboard</h1>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-list-columns-reverse"></i></div>
            <div>
                <div class="stat-value"><?= number_format($total_bom) ?></div>
                <div class="stat-label">Total BOM Records</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-broadcast"></i></div>
            <div>
                <div class="stat-value">KAP 1</div>
                <div class="stat-label">Welding / Toso / Assy</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-broadcast-pin"></i></div>
            <div>
                <div class="stat-value">KAP 2</div>
                <div class="stat-label">Welding / Toso / Assy</div>
            </div>
        </div>
    </div>
    <?php if ($total_users !== null): ?>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-people"></i></div>
            <div>
                <div class="stat-value"><?= number_format($total_users) ?></div>
                <div class="stat-label">Total Accounts</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-lightning-charge me-1"></i> Quick Access</div>
            <div class="card-body d-flex flex-wrap gap-2">
                <a href="<?= base_url('bom') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-list-columns-reverse me-1"></i> Master BOM</a>
                <a href="<?= base_url('wip/kap1') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-broadcast me-1"></i> WIP KAP 1</a>
                <a href="<?= base_url('wip/kap2') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-broadcast-pin me-1"></i> WIP KAP 2</a>
                <?php if (($auth_user['role'] ?? '') === 'admin'): ?>
                <a href="<?= base_url('akun') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-people me-1"></i> Manage Akun</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-clock-history me-1"></i> Recent BOM Uploads</div>
            <div class="card-body p-0">
                <?php if (empty($recent_uploads)): ?>
                    <div class="p-3 text-secondary small">No upload history yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>File</th><th>Mode</th><th>Rows</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent_uploads as $u): ?>
                            <tr>
                                <td class="small"><?= html_escape($u['file_name']) ?></td>
                                <td class="small text-capitalize"><?= html_escape($u['mode']) ?></td>
                                <td class="small"><?= (int) $u['inserted_rows'] ?>/<?= (int) $u['total_rows'] ?></td>
                                <td class="small">
                                    <?= $u['status'] === 'success' ? '<span class="badge text-bg-success">OK</span>' : '<span class="badge text-bg-danger">Failed</span>' ?>
                                </td>
                                <td class="small text-secondary"><?= html_escape($u['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
