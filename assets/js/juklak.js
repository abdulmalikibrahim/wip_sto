(function ($) {
    'use strict';

    var isAdmin = $('#tblJuklak thead th').last().text().trim() === 'Action';

    function esc(v) {
        return $('<div>').text(v == null ? '' : v).html();
    }

    var columns = [
        { data: 'no', orderable: false, searchable: false },
        {
            data: 'plant',
            className: 'text-center',
            render: function (value, type) {
                if (type !== 'display') return value;
                return '<span class="badge ' + (value === 'KAP1' ? 'text-bg-info' : 'text-bg-primary') + '">' + esc(value) + '</span>';
            }
        },
        { data: 'part_no' },
        { data: 'part_name' },
        { data: 'main_part_no' },
        {
            data: 'is_main',
            className: 'text-center',
            render: function (value, type) {
                if (type !== 'display') return value ? 'Main' : 'Dihitung 0';
                return value
                    ? '<span class="badge text-bg-success">Main</span>'
                    : '<span class="badge text-bg-warning text-dark" title="Sudah diwakili Main Part No — dihitung 0 di WIP Calc &amp; Summary">Dihitung 0</span>';
            }
        },
        {
            data: 'suffix_qty',
            className: 'small',
            render: function (value, type) {
                if (type !== 'display') return value;
                return value ? esc(value) : '<span class="text-secondary">—</span>';
            }
        }
    ];

    if (isAdmin) {
        columns.push({
            data: null,
            orderable: false,
            searchable: false,
            render: function (row) {
                return '<button class="btn btn-sm btn-danger btn-delete-juklak" data-id="' + row.id + '" title="Delete">' +
                    '<i class="bi bi-trash"></i></button>';
            }
        });
    }

    var table = $('#tblJuklak').DataTable($.extend({}, window.APP_DT_DEFAULTS, {
        data: [],
        processing: true,
        order: [],
        columns: columns
    }));

    function paintStats(rows) {
        var stats = { total: rows.length, kap1: 0, kap2: 0, replaced: 0 };
        rows.forEach(function (r) {
            if (r.plant === 'KAP1') stats.kap1++;
            if (r.plant === 'KAP2') stats.kap2++;
            if (!r.is_main) stats.replaced++;
        });
        Object.keys(stats).forEach(function (key) {
            $('[data-stat="' + key + '"]').text(stats[key].toLocaleString('id-ID'));
        });
    }

    function load() {
        table.processing(true);
        $.getJSON(BASE_URL + 'juklak/data')
            .done(function (resp) {
                table.clear().rows.add(resp.data || []).draw();
                paintStats(resp.data || []);
            })
            .fail(function () {
                toast('error', 'Failed to load Juklak data.');
            })
            .always(function () {
                table.processing(false);
            });
    }

    $('#tblJuklak').on('click', '.btn-delete-juklak', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Delete this Juklak row?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#dc3545',
            background: '#21262f',
            color: '#e4e6eb'
        }).then(function (res) {
            if (!res.isConfirmed) return;
            $.post(BASE_URL + 'juklak/delete/' + id, function (resp) {
                if (resp.status === 'success') {
                    toast('success', 'Juklak row deleted');
                    load();
                } else {
                    toast('error', 'Failed to delete');
                }
            }, 'json');
        });
    });

    $('.btn-juklak-download').on('click', function (e) {
        e.preventDefault();
        downloadExcel($(this));
    });

    // Loading overlay while the upload runs (a real form submit).
    var $uploadForm = $('#modalUploadJuklak form');
    $uploadForm.on('submit', function () {
        $uploadForm.find('button').prop('disabled', true);
        $uploadForm.find('button[type="submit"]').html(
            '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Uploading...'
        );
        $('#juklakUploadOverlay').removeClass('d-none');
    });

    // Upload dropzone UX
    var $file = $('#juklak_file');
    var $dz = $('#dropzoneJuklak');
    $file.on('change', function () {
        var name = this.files.length ? this.files[0].name : null;
        $('#dropzoneJuklakLabel').text(name || 'Click to choose an .xlsx file or drag it here');
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

    load();
})(jQuery);
