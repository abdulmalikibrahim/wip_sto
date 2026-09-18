<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="page-title mb-0">Akun</h1>
    <button type="button" class="btn btn-primary btn-sm" id="btnAddAkun">
        <i class="bi bi-person-plus me-1"></i> Add Account
    </button>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-people me-1"></i> Account List</div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="tblAkun" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Role</th>
                        <th>Scope</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAkun" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="formAkun">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalAkunTitle">Add Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="akun_id">
                    <div class="mb-3">
                        <label class="form-label small">Username</label>
                        <input type="text" name="username" id="akun_username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Full Name</label>
                        <input type="text" name="full_name" id="akun_full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Password <span id="akun_password_hint" class="text-secondary"></span></label>
                        <input type="password" name="password" id="akun_password" class="form-control" autocomplete="new-password">
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <label class="form-label small">Role</label>
                            <select name="role" id="akun_role" class="form-select">
                                <option value="viewer">View Only</option>
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small d-block">Status</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="akun_is_active" checked>
                                <label class="form-check-label small" for="akun_is_active">Active</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-text mt-2" id="akun_role_hint"></div>

                    <!-- Scope — only meaningful for role "User"; JS shows/hides it. -->
                    <div id="akun_scope" class="border rounded p-3 mt-3 d-none">
                        <div class="mb-3">
                            <label class="form-label small">Plant</label>
                            <select name="plant" id="akun_plant" class="form-select form-select-sm">
                                <option value="">— choose —</option>
                                <?php foreach (array_keys($shop_code_map) as $plant): ?>
                                <option value="<?= html_escape($plant) ?>"><?= html_escape(strtoupper($plant)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <label class="form-label small d-block">Shop Codes</label>
                        <?php foreach ($shop_code_map as $plant => $shops): ?>
                        <div class="akun-shop-group d-none" data-plant="<?= html_escape($plant) ?>">
                            <?php foreach ($shops as $key => $shop_code): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input akun-shop-code" type="checkbox"
                                       value="<?= html_escape($shop_code) ?>"
                                       id="akun_shop_<?= html_escape($plant . '_' . $key) ?>">
                                <label class="form-check-label small" for="akun_shop_<?= html_escape($plant . '_' . $key) ?>">
                                    <?= html_escape($shop_code) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                        <div class="form-text mt-2">
                            This account may set, upload and clear cutoff VINs for the ticked shops only,
                            and its Part List shows just those shops' parts.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
