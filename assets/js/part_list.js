(function ($) {
    'use strict';

    var isAdmin = $('#tblPartList thead th').last().text().trim() === 'Action';

    // Row selection (checkbox column) — kept in a Set so it survives
    // server-side pagination/search/sort redraws, which only ever have
    // the CURRENT page's rows in the DOM. "Select all" only ever acts on
    // the rows currently on screen; it intentionally resets to unchecked
    // on every redraw (see the `draw` handler below) rather than trying
    // to reflect "is the new page fully selected" — one click always
    // means "select everything on THIS page", nothing to second-guess.
    var selectedIds = new Set();

    var columns = [];
    if (isAdmin) {
        columns.push({
            data: 'id',
            orderable: false,
            searchable: false,
            className: 'text-center',
            render: function (id, type) {
                if (type !== 'display') return id;
                return '<input type="checkbox" class="form-check-input row-checkbox-part-list" value="' + id + '">';
            }
        });
    }
    columns.push(
        { data: 'no', orderable: false, searchable: false },
        { data: 'model' },
        { data: 'suffix' },
        { data: 'component' },
        { data: 'part_number' },
        { data: 'material_description' },
        { data: 'qty', className: 'text-end' },
        { data: 'uom' },
        { data: 'shop_code' }
    );

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
        order: [[isAdmin ? 2 : 1, 'asc']]
    }));

    if (isAdmin) {
        function updateSelectionInfo() {
            var has = selectedIds.size > 0;
            $('#partListSelectionInfo').toggleClass('d-none', !has).text(selectedIds.size + ' selected');
            $('#btnDeleteSelectedPartList').toggleClass('d-none', !has);
        }

        // After every redraw (page change, search, sort, reload): the
        // "select all" checkbox always resets to unchecked, and any row
        // that's part of the remembered selection gets its checkbox
        // re-checked (so flipping back to an earlier page shows what was
        // already picked there).
        table.on('draw', function () {
            $('#checkAllPartList').prop('checked', false);
            $('#tblPartList tbody .row-checkbox-part-list').each(function () {
                var id = String($(this).val());
                $(this).prop('checked', selectedIds.has(id));
            });
        });

        $('#tblPartList').on('change', '.row-checkbox-part-list', function () {
            var id = String($(this).val());
            if (this.checked) {
                selectedIds.add(id);
            } else {
                selectedIds.delete(id);
            }
            updateSelectionInfo();
        });

        $('#checkAllPartList').on('change', function () {
            var checked = this.checked;
            $('#tblPartList tbody .row-checkbox-part-list').each(function () {
                $(this).prop('checked', checked);
                var id = String($(this).val());
                if (checked) {
                    selectedIds.add(id);
                } else {
                    selectedIds.delete(id);
                }
            });
            updateSelectionInfo();
        });

        // Delete Selected — bulk-deletes every id remembered in
        // selectedIds (spanning however many pages they were picked
        // across), not just whatever's currently on screen.
        $('#btnDeleteSelectedPartList').on('click', function () {
            var ids = Array.from(selectedIds);
            if (!ids.length) return;

            Swal.fire({
                title: 'Delete ' + ids.length + ' selected entr' + (ids.length === 1 ? 'y' : 'ies') + '?',
                text: 'This cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Delete',
                confirmButtonColor: '#dc3545',
                background: '#21262f',
                color: '#e4e6eb'
            }).then(function (res) {
                if (!res.isConfirmed) return;

                var $btn = $('#btnDeleteSelectedPartList');
                $btn.prop('disabled', true);
                var originalHtml = $btn.html();
                $btn.html('<span class="spinner-border spinner-border-sm me-1" role="status"></span>Deleting...');

                $.post(BASE_URL + 'part-list/delete-bulk', { ids: ids }, null, 'json')
                    .done(function (resp) {
                        if (resp.status !== 'success') {
                            toast('error', 'Failed to delete the selected entries.');
                            return;
                        }
                        toast('success', resp.deleted + ' entr' + (resp.deleted === 1 ? 'y' : 'ies') + ' deleted.');
                        selectedIds.clear();
                        updateSelectionInfo();
                        table.ajax.reload(null, false);
                    })
                    .fail(function () {
                        toast('error', 'Failed to reach the server.');
                    })
                    .always(function () {
                        $btn.prop('disabled', false).html(originalHtml);
                    });
            });
        });
    }

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
