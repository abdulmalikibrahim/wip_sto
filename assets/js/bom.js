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
            className: 'text-nowrap',
            render: function (row) {
                // Baris ini dibawa lewat data-row supaya form Edit tidak perlu
                // request lagi ke server (dan aman dari child row responsive).
                var data = encodeURIComponent(JSON.stringify(row));
                return '<button class="btn btn-sm btn-outline-primary btn-edit-bom me-1" title="Edit" data-row="' +
                    data + '"><i class="bi bi-pencil"></i></button>' +
                    '<button class="btn btn-sm btn-outline-secondary btn-copy-bom me-1" title="Copy — isi form baru dengan data baris ini" data-row="' +
                    data + '"><i class="bi bi-files"></i></button>' +
                    '<button class="btn btn-sm btn-danger btn-delete-bom" data-id="' + row.id + '" title="Delete">' +
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

    // Excel-style ▼ filter on every data column header.
    ExcelFilter.attach(table, {
        mode: 'server',
        distinctUrl: BASE_URL + 'bom/distinct',
        numeric: ['qty']
    });

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

    // Keep the "Download" button in sync with whichever Model card is active,
    // so it exports the same data currently shown in the table.
    function updateDownloadLink() {
        var $btn = $('#btnDownloadBom');
        if (!$btn.length) return;
        var base = BASE_URL + 'bom/export';
        $btn.attr('href', currentModelFilter ? base + '?model_filter=' + encodeURIComponent(currentModelFilter) : base);
    }

    // Show a loading spinner on the Download button while the file is
    // generated/transferred, instead of giving no feedback at all.
    $('#btnDownloadBom').on('click', function (e) {
        e.preventDefault();
        downloadExcel($(this));
    });

    // ---- Tambah / Edit / Copy satu baris BOM (admin) ----
    var BOM_FIELDS = ['material', 'katashiki', 'model', 'suffix', 'component', 'part_number',
        'material_description', 'qty', 'uom', 'shop_code'];

    // mode 'edit' menyimpan ke baris yang sama; 'add' dan 'copy' menyimpan
    // sebagai baris baru — bedanya cuma form 'copy' sudah terisi.
    function openBomModal(row, mode) {
        $('#bomEditAlert').addClass('d-none').text('');
        $('#bomEditId').val(mode === 'edit' && row ? row.id : '');
        BOM_FIELDS.forEach(function (field) {
            $('#bomEdit_' + field).val(row && row[field] != null ? row[field] : '');
        });
        $('#bomModalTitle').text(mode === 'edit' ? 'Edit BOM Entry'
            : (mode === 'copy' ? 'Copy BOM Entry' : 'Tambah BOM Entry'));
        bootstrap.Modal.getOrCreateInstance('#modalEditBom').show();
    }

    function bomRowOf($btn) {
        return JSON.parse(decodeURIComponent($btn.attr('data-row')));
    }

    $('#btnAddBom').on('click', function () {
        openBomModal(null, 'add');
    });

    $(document).on('click', '#tblBom .btn-edit-bom', function () {
        openBomModal(bomRowOf($(this)), 'edit');
    });

    $(document).on('click', '#tblBom .btn-copy-bom', function () {
        openBomModal(bomRowOf($(this)), 'copy');
    });

    $('#formEditBom').on('submit', function (e) {
        e.preventDefault();
        var $alert = $('#bomEditAlert').addClass('d-none').text('');
        var $btn = $('#btnSaveEditBom').prop('disabled', true);
        var id = $('#bomEditId').val();

        var payload = {};
        BOM_FIELDS.forEach(function (field) {
            payload[field] = $('#bomEdit_' + field).val();
        });

        $.post(BASE_URL + 'bom/' + (id ? 'update/' + id : 'create'), payload, null, 'json')
            .done(function (resp) {
                if (resp.status !== 'success') {
                    $alert.removeClass('d-none').text(resp.message || 'Gagal menyimpan.');
                    return;
                }
                toast('success', resp.message || 'Tersimpan.');
                bootstrap.Modal.getOrCreateInstance('#modalEditBom').hide();
                table.ajax.reload(null, false);
            })
            .fail(function (xhr) {
                $alert.removeClass('d-none')
                    .text((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menghubungi server.');
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
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
