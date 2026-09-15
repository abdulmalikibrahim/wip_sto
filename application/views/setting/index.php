<h1 class="page-title">Setting</h1>

<?php if (!$table_ready): ?>
<div class="alert alert-warning">
    Tabel <code>app_setting</code> belum ada. Jalankan <code>database/migrations/2026_09_15_create_app_setting.sql</code> dulu.
</div>
<?php endif; ?>

<?php
if ($sto['date'] === null) {
    $state = array('is-muted', 'bi-calendar-x', 'Belum diatur', 'Get Data WIP bisa dipakai kapan saja.');
} elseif ($sto['locked']) {
    $state = array('is-danger', 'bi-lock-fill', 'Terkunci', 'Tanggal STO ' . $sto['date_label'] . ' sudah lewat.');
} elseif ($sto['days_left'] === 0) {
    $state = array('is-warn', 'bi-hourglass-split', 'Hari terakhir', 'Hari ini tanggal STO. Besok Get Data WIP dikunci.');
} else {
    $state = array('is-ok', 'bi-unlock-fill', 'Aktif (H-' . $sto['days_left'] . ')', 'Get Data WIP bisa dipakai sampai ' . $sto['date_label'] . '.');
}
?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-calendar-event me-1"></i> Tanggal Activity STO</div>
            <div class="card-body">
                <p class="small text-secondary">
                    <strong>Get Data WIP</strong> bisa dipakai <strong>sampai tanggal STO</strong>.
                    Mulai hari berikutnya tombol dikunci, supaya data WIP untuk STO tidak berubah.
                </p>
                <form method="post" action="<?= base_url('setting/save') ?>" class="row g-2 align-items-end">
                    <div class="col-sm-6">
                        <label class="form-label small" for="sto_date">Tanggal STO</label>
                        <input type="date" class="form-control" id="sto_date" name="sto_date" value="<?= html_escape($sto['date'] ?? '') ?>" required>
                    </div>
                    <div class="col-sm-6 d-flex gap-2">
                        <button type="submit" class="btn btn-primary" <?= $table_ready ? '' : 'disabled' ?>>
                            <i class="bi bi-save me-1"></i> Simpan
                        </button>
                        <?php if ($sto['date'] !== null): ?>
                        <button type="submit" name="action" value="clear" class="btn btn-outline-danger" formnovalidate>
                            <i class="bi bi-x-circle me-1"></i> Hapus Tanggal
                        </button>
                        <?php endif; ?>
                    </div>
                </form>

                <ul class="small text-secondary mt-3 mb-0 ps-3">
                    <li>Sampai tanggal STO: <strong>Get Data WIP</strong> aktif.</li>
                    <li>Lewat tanggal STO: tombol terkunci (juga ditolak di server).</li>
                    <li>Upload Excel dan Cutoff VIN tetap bisa dipakai.</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="stat-card h-100">
            <div class="stat-icon <?= $state[0] ?>"><i class="bi <?= $state[1] ?>"></i></div>
            <div>
                <div class="stat-label">Status Get Data WIP</div>
                <div class="stat-value my-1"><?= html_escape($state[2]) ?></div>
                <div class="stat-label"><?= html_escape($state[3]) ?></div>
                <div class="stat-label mt-2"><i class="bi bi-clock me-1"></i>Hari ini: <?= html_escape($sto['today_label']) ?> (WIB)</div>
            </div>
        </div>
    </div>
</div>
