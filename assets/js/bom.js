(function ($) {
    'use strict';

    var isAdmin = $('#tblBom thead th').last().text().trim() === 'Action';

    var columns = [
        { data: 'no', orderable: false, searchable: false },
        { data: 'material' },
        { data: 'katashiki' },
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
                return '<button class="btn btn-sm btn-danger btn-delete-bom" data-id="' + row.id + '" title="Delete">' +
                    '<i class="bi bi-trash"></i></button>';
            }
        });
    }

    var currentModelFilter = '';

    var table = $('#tblBom').DataTable($.extend({}, window.APP_DT_DEFAULTS, {
        processing: true,
        serverSide: true,
        ajax: {
            url: BASE_URL + 'bom/data',
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
    });

    $('#tblBom').on('click', '.btn-delete-bom', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Delete this BOM entry?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#dc3545',
            background: '#21262f',
            color: '#e4e6eb'
        }).then(function (res) {
            if (!res.isConfirmed) return;
            $.post(BASE_URL + 'bom/delete/' + id, function (resp) {
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
        $('#bomUploadOverlay').removeClass('d-none');
    });

    // Upload dropzone UX
    var $file = $('#bom_file');
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
})(jQuery);
