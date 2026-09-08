<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="page-title mb-0"><?= html_escape($title) ?></h1>
    <div class="btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-outline-secondary active" data-view="table" id="btnViewTable">
            <i class="bi bi-table"></i> Table
        </button>
        <button type="button" class="btn btn-outline-secondary" data-view="card" id="btnViewCard">
            <i class="bi bi-grid-3x3-gap"></i> Card
        </button>
    </div>
</div>

<ul class="nav nav-tabs shop-tabs mb-3" id="shopTabs">
    <?php $first = true; foreach ($shops as $key => $label): ?>
    <li class="nav-item">
        <button class="nav-link <?= $first ? 'active' : '' ?>" data-shop="<?= $key ?>" type="button">
            <?= html_escape($label) ?>
        </button>
    </li>
    <?php $first = false; endforeach; ?>
    <li class="ms-auto d-flex align-items-center gap-2 pe-2">
        <span id="wipLastUpdated" class="text-secondary small d-none d-md-inline"></span>
        <button class="btn btn-sm btn-outline-success" id="btnDownload" type="button">
            <i class="bi bi-file-earmark-excel"></i> Download Excel
        </button>
        <button class="btn btn-sm btn-outline-secondary" id="btnRefresh" type="button">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </li>
</ul>

<div id="wipAlert" class="alert alert-danger d-none"></div>

<div class="position-relative">
    <div id="wipLoading" class="wip-loading-overlay d-none">
        <div class="spinner-border text-primary" role="status"></div>
        <div class="mt-2 small text-secondary">Loading WIP data...</div>
    </div>

    <div class="card" id="tableView">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tblWip" class="table table-hover align-middle w-100">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>VIN</th>
                            <th>Suffix</th>
                            <th>Model</th>
                            <th>Color Code</th>
                            <th>Color Desc</th>
                            <th>Last Scan</th>
                            <th>Scan Date</th>
                            <th>Shop Code</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="cardView" class="row g-3 d-none"></div>
</div>

<script>
    var WIP_SOURCE = <?= json_encode($source) ?>;
</script>
