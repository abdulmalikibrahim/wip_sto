(function ($) {
    'use strict';

    var isAdmin = $('#tblPartList thead th').last().text().trim() === 'Action';

    var columns = [
        { data: 'no', orderable: false, searchable: false },
        { data: 'model' },
        { data: 'suffix' },
        { data: 'component' },
        { data: 'part_number' },
        { data: 'material_description' },
        { data: 'qty', className: 'text-end' },
        { data: 'uom' },
        { data: 'shop_code' },
    ];

    if (isAdmin) {
        columns.push({
            data: null,
            orderable: false,
            searchable: false,
            render: function (row) {
                return '<button class="btn btn-sm btn-danger btn-delete-part-list" data-id="' + row.id + '" title="Delete">' +
                    '<i class="bi bi-trash"></i></button>';
            }
        });
    }

    var currentModelFilter = '';

    var table = $('#tblPartList').DataTable($.extend({}, window.APP_DT_DEFAULTS, {
        processing: true,
        serverSide: true,
        ajax: {
            url: BASE_URL + 'part-list/data',
            type: 'POST',
            data: function (d) {
                d.model_filter = currentModelFilter;
            }
        },
        columns: columns,
        order: [[1, 'asc']]
    }));

    // Model cards: click to filter the table down to that model, click again
    // (or click "All") to clear it. Combines with the search box, not replaces it.
    $('#modelCards').on('click', '.model-card', function () {
        var $card = $(this);
        currentModelFilter = $card.data('model') || '';
        $('#modelCards .model-card').removeClass('active');
        $card.addClass('active');
        table.ajax.reload();
        updateDownloadLink();
    });

    // Keep the "Download" button in sync with whichever Model card is active.
    function updateDownloadLink() {
        var $btn = $('#btnDownloadPartList');
        if (!$btn.length) return;
        var base = BASE_URL + 'part-list/export';
        $btn.attr('href', currentModelFilter ? base + '?model_filter=' + encodeURIComponent(currentModelFilter) : base);
    }

    // Show a loading spinner on the Download button while the file is
    // generated/transferred, instead of giving no feedback at all.
    $('#btnDownloadPartList').on('click', function (e) {
        e.preventDefault();
        downloadExcel($(this));
    });

    $('#tblPartList').on('click', '.btn-delete-part-list', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Delete this Part List entry?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#dc3545',
            background: '#21262f',
            color: '#e4e6eb'
        }).then(function (res) {
            if (!res.isConfirmed) return;
            $.post(BASE_URL + 'part-list/delete/' + id, function (resp) {
                if (resp.status === 'success') {
                    toast('success', 'Entry deleted');
                    table.ajax.reload(null, false);
                } else {
                    toast('error', 'Failed to delete');
                }
            }, 'json');
        });
    });

    // Show a loading animation while the file uploads (a real form submit, so
    // this stays up until the browser navigates away with the server's response).
    var $uploadForm = $('#modalUpload form');
    $uploadForm.on('submit', function () {
        $uploadForm.find('button').prop('disabled', true);
        $uploadForm.find('button[type="submit"]').html(
            '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Uploading...'
        );
        $('#partListUploadOverlay').removeClass('d-none');
    });

    // Upload dropzone UX
    var $file = $('#part_list_file');
    var $dz = $('#dropzone');
    $file.on('change', function () {
        var name = this.files.length ? this.files[0].name : null;
        $('#dropzoneLabel').text(name || 'Click to choose an .xlsx file or drag it here');
    });
    $dz.on('dragover', function (e) {
        e.preventDefault();
        $dz.addClass('dragover');
    }).on('dragleave', function () {
        $dz.removeClass('dragover');
    }).on('drop', function (e) {
        e.preventDefault();
        $dz.removeClass('dragover');
        var files = e.originalEvent.dataTransfer.files;
        if (files.length) {
            $file[0].files = files;
            $file.trigger('change');
        }
    });

    // ------------------------------------------------------------------
    // Compare: Master BOM vs Part List
    // ------------------------------------------------------------------

    var currentStatusFilter = '';

    var statusBadges = {
        only_bom: '<span class="badge text-bg-danger">Only in Master BOM</span>',
        only_part_list: '<span class="badge text-bg-warning text-dark">Only in Part List</span>',
        mismatch: '<span class="badge text-bg-info text-dark">Different</span>'
    };

    var compareTable = $('#tblCompare').DataTable($.extend({}, window.APP_DT_DEFAULTS, {
        processing: true,
        serverSide: true,
        ordering: false,
        ajax: {
            url: BASE_URL + 'part-list/compare/data',
            type: 'POST',
            data: function (d) {
                d.status_filter = currentStatusFilter;
            }
        },
        columns: [
            { data: 'no', orderable: false, searchable: false },
            {
                data: 'status',
                orderable: false,
                render: function (status, type, row) {
                    return type === 'display' ? (statusBadges[status] || row.status_label) : row.status_label;
                }
            },
            { data: 'model' },
            { data: 'suffix' },
            { data: 'component' },
            { data: 'part_number' },
            { data: 'field' },
            { data: 'bom_value' },
            { data: 'part_list_value' },
        ]
    }));

    $('#statusFilter').on('click', 'button', function () {
        var $btn = $(this);
        currentStatusFilter = $btn.data('status') || '';
        $('#statusFilter button').removeClass('active');
        $btn.addClass('active');
        compareTable.ajax.reload();
    });

    // Download Excel — exports exactly what's currently shown: the active
    // Status filter button plus whatever's typed in the table's search box.
    $('#btnDownloadCompare').on('click', function (e) {
        e.preventDefault();
        var params = new URLSearchParams();
        if (currentStatusFilter) params.set('status_filter', currentStatusFilter);
        var searchTerm = compareTable.search();
        if (searchTerm) params.set('search', searchTerm);
        var qs = params.toString();
        $(this).attr('href', BASE_URL + 'part-list/compare/export' + (qs ? '?' + qs : ''));
        downloadExcel($(this));
    });
})(jQuery);
