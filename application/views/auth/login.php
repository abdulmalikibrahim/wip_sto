<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - WIP & BOM Monitoring</title>
    <link rel="stylesheet" href="<?= base_url('assets/vendor/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body class="login-page">
    <div class="login-card">
        <div class="text-center mb-4">
            <i class="bi bi-diagram-3-fill" style="font-size:2.4rem;color:var(--app-accent)"></i>
            <h5 class="mt-2 mb-0 fw-semibold">WIP &amp; BOM Monitoring</h5>
            <div class="text-secondary small">Sign in to continue</div>
        </div>

        <?php $flash = get_flash(); ?>
        <?php if (!empty($flash)): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> py-2 small"><?= html_escape($flash['message']) ?></div>
        <?php endif; ?>
        <?php if (validation_errors()): ?>
            <div class="alert alert-danger py-2 small"><?= validation_errors() ?></div>
        <?php endif; ?>

        <?= form_open(current_url()) ?>
            <div class="mb-3">
                <label class="form-label small">Username</label>
                <div class="input-group">
                    <span class="input-group-text bg-transparent"><i class="bi bi-person"></i></span>
                    <input type="text" name="username" class="form-control" value="<?= set_value('username') ?>" autofocus required>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label small">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-transparent"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" class="form-control" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right me-1"></i> Login
            </button>
        <?= form_close() ?>
    </div>
</body>
</html>
