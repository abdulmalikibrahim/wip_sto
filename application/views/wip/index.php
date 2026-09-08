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
    <li class="ms-auto d-flex align-items-center pe-2">
        <button class="btn btn-sm btn-outline-secondary" id="btnRefresh" type="button">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </li>
</ul>

<div id="wipAlert" class="alert alert-danger d-none"></div>

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

<script>
    var WIP_SOURCE = <?= json_encode($source) ?>;
</script>
