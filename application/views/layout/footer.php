    </main>
</div>
<script src="<?= base_url('assets/vendor/js/jquery.min.js') ?>"></script>
<script src="<?= base_url('assets/vendor/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/vendor/js/jquery.dataTables.min.js') ?>"></script>
<script src="<?= base_url('assets/vendor/js/dataTables.bootstrap5.min.js') ?>"></script>
<script src="<?= base_url('assets/vendor/js/dataTables.responsive.min.js') ?>"></script>
<script src="<?= base_url('assets/vendor/js/responsive.bootstrap5.min.js') ?>"></script>
<script src="<?= base_url('assets/vendor/js/sweetalert2.all.min.js') ?>"></script>
<script>
    var BASE_URL = "<?= base_url() ?>";
</script>
<script src="<?= base_url('assets/js/app.js') ?>?v=<?= @filemtime(FCPATH . 'assets/js/app.js') ?: time() ?>"></script>
<?php if (!empty($page_js)): ?>
<script src="<?= base_url($page_js) ?>?v=<?= @filemtime(FCPATH . $page_js) ?: time() ?>"></script>
<?php endif; ?>
</body>
</html>
