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
            className: 'text-nowrap',
            render: function (row) {
                // Baris ini dibawa lewat data-row supaya form Edit tidak perlu
                // request lagi ke server (dan aman dari child row responsive).
                var data = encodeURIComponent(JSON.stringify(row));
                return '<button class="btn btn-sm btn-outline-primary btn-edit-juklak me-1" title="Edit" data-row="' +
                    data + '"><i class="bi bi-pencil"></i></button>' +
                    '<button class="btn btn-sm btn-outline-secondary btn-copy-juklak me-1" title="Copy — isi form baru dengan data baris ini" data-row="' +
                    data + '"><i class="bi bi-files"></i></button>' +
                    '<button class="btn btn-sm btn-danger btn-delete-juklak" data-id="' + row.id + '" title="Delete">' +
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

    // Excel-style ▼ filter on every data column header. Juklak's rows are
    // all loaded in the browser, so filtering happens here, not on the server.
    ExcelFilter.attach(table, { mode: 'client' });

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

    // ---- Tambah / Edit / Copy satu baris (admin) ----

    // Saran kode Suffix dari Master BOM / Part List — diambil sekali saat modal
    // pertama dibuka, supaya halaman tidak menunggu query besar itu saat load.
    var suffixesLoaded = false;

    function loadSuffixSuggestions() {
        if (suffixesLoaded) return;
        suffixesLoaded = true;
        $.getJSON(BASE_URL + 'juklak/suffixes').done(function (resp) {
            var $list = $('#juklakSuffixList').empty();
            (resp.data || []).forEach(function (code) {
                $list.append($('<option>').attr('value', code));
            });
        });
    }

    function suffixRow(suffix, qty) {
        return '<tr>' +
            '<td><input type="text" class="form-control form-control-sm juklak-suffix text-uppercase" ' +
                'list="juklakSuffixList" value="' + esc(suffix == null ? '' : suffix) + '" placeholder="mis. 7J"></td>' +
            '<td><input type="number" step="0.001" class="form-control form-control-sm juklak-suffix-qty" ' +
                'value="' + esc(qty == null ? '' : qty) + '" placeholder="Qty"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-suffix" title="Hapus baris">' +
                '<i class="bi bi-x-lg"></i></button></td>' +
            '</tr>';
    }

    function renderSuffixRows(map) {
        var $rows = $('#juklakSuffixRows').empty();
        var keys = Object.keys(map || {});
        keys.forEach(function (suffix) {
            $rows.append(suffixRow(suffix, map[suffix]));
        });
        if (!keys.length) $rows.append(suffixRow('', ''));
    }

    $('#btnAddSuffixRow').on('click', function () {
        $('#juklakSuffixRows').append(suffixRow('', ''));
    });

    $('#juklakSuffixRows').on('click', '.btn-remove-suffix', function () {
        $(this).closest('tr').remove();
    });

    // mode 'edit' menyimpan ke baris yang sama; 'add' dan 'copy' menyimpan
    // sebagai baris baru — bedanya cuma form 'copy' sudah terisi.
    function openJuklakModal(row, mode) {
        loadSuffixSuggestions();

        $('#juklakEditAlert').addClass('d-none').text('');
        $('#juklakEditId').val(mode === 'edit' && row ? row.id : '');
        $('#juklakEditPlant').val(row ? String(row.plant).toLowerCase() : 'kap1');
        $('#juklakEditPartNo').val(row ? row.part_no : '');
        $('#juklakEditPartName').val(row ? row.part_name : '');
        $('#juklakEditMainPartNo').val(row ? row.main_part_no : '');
        renderSuffixRows(row ? row.suffix_qty_map : null);
        $('#juklakModalTitle').text(mode === 'edit' ? 'Edit Juklak'
            : (mode === 'copy' ? 'Copy Juklak' : 'Tambah Juklak'));
        bootstrap.Modal.getOrCreateInstance('#modalEditJuklak').show();
    }

    function juklakRowOf($btn) {
        return JSON.parse(decodeURIComponent($btn.attr('data-row')));
    }

    $('#btnAddJuklak').on('click', function () {
        openJuklakModal(null, 'add');
    });

    $(document).on('click', '#tblJuklak .btn-edit-juklak', function () {
        openJuklakModal(juklakRowOf($(this)), 'edit');
    });

    $(document).on('click', '#tblJuklak .btn-copy-juklak', function () {
        openJuklakModal(juklakRowOf($(this)), 'copy');
    });

    $('#formEditJuklak').on('submit', function (e) {
        e.preventDefault();
        var $alert = $('#juklakEditAlert').addClass('d-none').text('');
        var $btn = $('#btnSaveEditJuklak').prop('disabled', true);
        var id = $('#juklakEditId').val();

        var suffixes = [];
        var qtys = [];
        $('#juklakSuffixRows tr').each(function () {
            suffixes.push($(this).find('.juklak-suffix').val());
            qtys.push($(this).find('.juklak-suffix-qty').val());
        });

        $.post(BASE_URL + 'juklak/' + (id ? 'update/' + id : 'create'), {
            plant: $('#juklakEditPlant').val(),
            part_no: $('#juklakEditPartNo').val(),
            part_name: $('#juklakEditPartName').val(),
            main_part_no: $('#juklakEditMainPartNo').val(),
            // Penanda bahwa Qty per Suffix ikut dikirim: daftar kosong berarti
            // benar-benar dikosongkan, bukan "jangan diubah".
            has_suffix: 1,
            suffix: suffixes,
            suffix_qty: qtys
        }, null, 'json')
            .done(function (resp) {
                if (resp.status !== 'success') {
                    $alert.removeClass('d-none').text(resp.message || 'Gagal menyimpan.');
                    return;
                }
                toast('success', resp.message || 'Tersimpan.');
                bootstrap.Modal.getOrCreateInstance('#modalEditJuklak').hide();
                load();
            })
            .fail(function (xhr) {
                $alert.removeClass('d-none')
                    .text((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menghubungi server.');
            })
            .always(function () {
                $btn.prop('disabled', false);
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
