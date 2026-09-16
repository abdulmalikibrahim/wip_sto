<?php
$is_admin = ($auth_user['role'] ?? '') === 'admin';
$label_of = function ($key) use ($shop_labels) {
    return $shop_labels[$key] ?? strtoupper($key);
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="page-title mb-0"><?= html_escape($title) ?></h1>
    <?php if ($is_admin): ?>
    <button type="button" class="btn btn-primary btn-sm" id="btnAddSpecial" <?= $table_ready ? '' : 'disabled' ?>>
        <i class="bi bi-plus-circle me-1"></i> Tambah Part Special
    </button>
    <?php endif; ?>
</div>

<?php if (!$table_ready): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Tabel <code>wip_special_part</code> belum ada di database ini. Jalankan
    <code>database/migrations/2026_09_16_create_wip_special_part.sql</code> dulu (mis. lewat tab SQL di phpMyAdmin).
</div>
<?php endif; ?>

<div class="alert alert-secondary small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    Menu ini menentukan <strong>sebuah part dihitung di shop mana saja</strong>, menggantikan Shop Code dari Master BOM /
    Part List untuk part tersebut. Contoh: <code>12345-BZ123</code> di KAP 1 dengan pilihan <strong>Welding + Toso</strong> &rarr;
    <strong>Welding</strong> (shop paling awal yang dipilih) dihitung <strong>mulai dari cutoff VIN Welding</strong> seperti biasa,
    <strong>Toso</strong> dihitung <strong>semua unit</strong> karena unit di sana sudah membawa part itu, dan <strong>Assy</strong>
    tidak dihitung sama sekali (0). Berlaku di <strong>WIP Calc</strong> dan <strong>WIP Summary</strong>, diatur terpisah per line.
    Cutoff VIN-nya tetap diisi di halaman WIP Calc seperti biasa. Part-nya harus ada di BOM / Part List untuk line tersebut.
</div>

<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-3">
            <?php foreach (array('total' => 'Total Aturan', 'kap1' => 'KAP 1', 'kap2' => 'KAP 2') as $key => $label): ?>
            <div class="col-6 col-lg-4">
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-sliders"></i></div>
                    <div>
                        <div class="stat-value" data-stat="<?= $key ?>">&mdash;</div>
                        <div class="stat-label"><?= html_escape($label) ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-table me-1"></i> Daftar Part Special</div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="tblSpecialPart" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Plant</th>
                        <th>Part Number</th>
                        <th>Dihitung di Shop</th>
                        <th>Mulai dari Cutoff VIN</th>
                        <th>Catatan</th>
                        <?php if ($is_admin): ?><th>Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($is_admin): ?>
<!-- Add / Edit Modal -->
<div class="modal fade" id="modalSpecialPart" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="formSpecialPart">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-sliders me-1"></i> <span id="specialModalTitle">Tambah Part Special</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="specialAlert" class="alert alert-danger d-none py-2 small"></div>
                    <input type="hidden" name="id" id="specialId" value="">

                    <div class="mb-3">
                        <label class="form-label small">Plant (Line)</label>
                        <select class="form-select form-select-sm" name="plant" id="specialPlant" required>
                            <?php foreach ($plants as $key => $label): ?>
                            <option value="<?= html_escape($key) ?>"><?= html_escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Part Number</label>
                        <input type="text" class="form-control form-control-sm" name="part_number" id="specialPartNumber"
                               list="specialPartList" placeholder="mis. 12345-BZ123" required autocomplete="off">
                        <datalist id="specialPartList"></datalist>
                        <div class="form-text">Ketik minimal 3 karakter untuk melihat saran part dari Master BOM / Part List.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small d-block">Dihitung di shop mana saja</label>
                        <?php foreach ($shop_keys as $key): ?>
                        <div class="form-check">
                            <input class="form-check-input special-shop" type="checkbox" name="shops[]" value="<?= html_escape($key) ?>" id="specialShop<?= html_escape($key) ?>">
                            <label class="form-check-label small" for="specialShop<?= html_escape($key) ?>">
                                <?= html_escape($label_of($key)) ?>
                                <span class="text-secondary" data-shop-code="<?= html_escape($key) ?>"></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        <div class="form-text" id="specialShopHint">
                            Shop paling awal yang dicentang memakai cutoff VIN-nya; shop sesudahnya dihitung semua unit; yang tidak dicentang dihitung 0.
                        </div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label small">Catatan (opsional)</label>
                        <input type="text" class="form-control form-control-sm" name="note" id="specialNote" maxlength="255" placeholder="mis. alasan part ini dikecualikan dari Assy">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm" id="btnSaveSpecial"><i class="bi bi-check-lg me-1"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    var SPECIAL_SHOP_LABELS = <?= json_encode($shop_labels) ?>;
    var SPECIAL_SHOP_KEYS = <?= json_encode(array_values($shop_keys)) ?>;
    var SPECIAL_SHOP_CODES = <?= json_encode($shop_codes) ?>;
</script>
