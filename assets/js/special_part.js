(function ($) {
    'use strict';

    var isAdmin = $('#tblSpecialPart thead th').last().text().trim() === 'Action';

    function esc(v) {
        return $('<div>').text(v == null ? '' : v).html();
    }

    function label(shop) {
        return SPECIAL_SHOP_LABELS[shop] || String(shop).toUpperCase();
    }

    // The shop chain of a rule: the first shop counts from its cutoff VIN, the
    // rest count every unit — shown the same way the rule is explained.
    function shopChain(row) {
        if (!row.shops.length) return '<span class="text-secondary">—</span>';
        return row.shops.map(function (shop, i) {
            var own = i === 0;
            return '<span class="badge ' + (own ? 'text-bg-success' : 'text-bg-secondary') + ' me-1" title="' +
                (own ? 'Dihitung Net mulai cutoff VIN shop ini' : 'Dihitung semua unit') + '">' +
                esc(label(shop)) + (own ? ' (cutoff)' : ' (semua unit)') + '</span>';
        }).join('');
    }

    // Shops of the line that the rule leaves out — counted 0.
    function excludedShops(row) {
        var left = SPECIAL_SHOP_KEYS.filter(function (shop) {
            return row.shops.indexOf(shop) === -1;
        });
        if (!left.length) return '';
        return '<div class="small text-secondary mt-1">Tidak dihitung: ' + esc(left.map(label).join(', ')) + '</div>';
    }

    var columns = [
        { data: 'no', orderable: false, searchable: false },
        {
            data: 'plant',
            className: 'text-center',
            render: function (value, type) {
                if (type !== 'display') return value;
                return '<span class="badge ' + (value === 'KAP 1' ? 'text-bg-info' : 'text-bg-primary') + '">' + esc(value) + '</span>';
            }
        },
        { data: 'part_no' },
        {
            data: 'shop_labels',
            render: function (value, type, row) {
                if (type !== 'display') return (value || []).join(', ');
                return shopChain(row) + excludedShops(row);
            }
        },
        { data: 'start_label', className: 'text-center' },
        {
            data: 'note',
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
            className: 'text-nowrap text-center',
            render: function (row) {
                return '<button class="btn btn-sm btn-outline-primary btn-edit-special me-1" data-id="' + row.id + '" title="Edit">' +
                    '<i class="bi bi-pencil"></i></button>' +
                    '<button class="btn btn-sm btn-danger btn-delete-special" data-id="' + row.id + '" title="Delete">' +
                    '<i class="bi bi-trash"></i></button>';
            }
        });
    }

    var table = $('#tblSpecialPart').DataTable($.extend({}, window.APP_DT_DEFAULTS, {
        data: [],
        processing: true,
        order: [],
        columns: columns
    }));

    var rowsById = {};

    function paintStats(rows) {
        var stats = { total: rows.length, kap1: 0, kap2: 0 };
        rows.forEach(function (r) {
            if (r.plant_key === 'kap1') stats.kap1++;
            if (r.plant_key === 'kap2') stats.kap2++;
        });
        Object.keys(stats).forEach(function (key) {
            $('[data-stat="' + key + '"]').text(stats[key].toLocaleString('id-ID'));
        });
    }

    function load() {
        table.processing(true);
        $.getJSON(BASE_URL + 'special-part/data')
            .done(function (resp) {
                var rows = resp.data || [];
                rowsById = {};
                rows.forEach(function (r) { rowsById[r.id] = r; });
                table.clear().rows.add(rows).draw();
                paintStats(rows);
            })
            .fail(function () {
                toast('error', 'Gagal memuat data Part Special.');
            })
            .always(function () {
                table.processing(false);
            });
    }

    // ---- Add / Edit form (admin only) ----
    var $modal = $('#modalSpecialPart');

    if ($modal.length) {
        var $alert = $('#specialAlert');
        var $plant = $('#specialPlant');

        // "Welding (WELD3)" — the shop code follows the selected line.
        function paintShopCodes() {
            var codes = SPECIAL_SHOP_CODES[$plant.val()] || {};
            $('[data-shop-code]').each(function () {
                var code = codes[$(this).data('shop-code')];
                $(this).text(code ? '(' + code + ')' : '');
            });
        }

        function openModal(row) {
            $alert.addClass('d-none').text('');
            $('#specialId').val(row ? row.id : '');
            $plant.val(row ? row.plant_key : 'kap1');
            $('#specialPartNumber').val(row ? row.part_no : '');
            $('#specialNote').val(row ? row.note : '');
            $('.special-shop').each(function () {
                this.checked = !!row && row.shops.indexOf(this.value) !== -1;
            });
            $('#specialModalTitle').text(row ? 'Edit Part Special' : 'Tambah Part Special');
            paintShopCodes();
            bootstrap.Modal.getOrCreateInstance('#modalSpecialPart').show();
        }

        $plant.on('change', paintShopCodes);

        $('#btnAddSpecial').on('click', function () {
            openModal(null);
        });

        $('#tblSpecialPart').on('click', '.btn-edit-special', function () {
            var row = rowsById[$(this).data('id')];
            if (row) openModal(row);
        });

        // Part number suggestions, from Master BOM / Part List.
        var suggestTimer = null;
        $('#specialPartNumber').on('input', function () {
            var term = $(this).val();
            clearTimeout(suggestTimer);
            if (term.length < 3) return;
            suggestTimer = setTimeout(function () {
                $.getJSON(BASE_URL + 'special-part/suggest', { q: term })
                    .done(function (resp) {
                        var $list = $('#specialPartList').empty();
                        (resp.data || []).forEach(function (part) {
                            $list.append($('<option>').attr('value', part));
                        });
                    });
            }, 300);
        });

        $('#formSpecialPart').on('submit', function (e) {
            e.preventDefault();
            $alert.addClass('d-none').text('');

            var shops = $('.special-shop:checked').map(function () { return this.value; }).get();
            if (!shops.length) {
                $alert.removeClass('d-none').text('Pilih minimal satu shop yang dihitung.');
                return;
            }

            var $btn = $('#btnSaveSpecial').prop('disabled', true);
            $.post(BASE_URL + 'special-part/save', {
                id: $('#specialId').val(),
                plant: $plant.val(),
                part_number: $('#specialPartNumber').val(),
                shops: shops,
                note: $('#specialNote').val()
            }, null, 'json')
                .done(function (resp) {
                    if (resp.status !== 'success') {
                        $alert.removeClass('d-none').text(resp.message || 'Gagal menyimpan.');
                        return;
                    }
                    toast('success', resp.message || 'Tersimpan.');
                    bootstrap.Modal.getOrCreateInstance('#modalSpecialPart').hide();
                    load();
                })
                .fail(function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menghubungi server.';
                    $alert.removeClass('d-none').text(msg);
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });

        $('#tblSpecialPart').on('click', '.btn-delete-special', function () {
            var id = $(this).data('id');
            var row = rowsById[id];
            Swal.fire({
                title: 'Hapus aturan ini?',
                html: row ? 'Part <strong>' + esc(row.part_no) + '</strong> (' + esc(row.plant) + ') akan kembali dihitung ' +
                    'mengikuti Shop Code di BOM / Part List.' : '',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Hapus',
                confirmButtonColor: '#dc3545',
                background: '#21262f',
                color: '#e4e6eb'
            }).then(function (res) {
                if (!res.isConfirmed) return;
                $.post(BASE_URL + 'special-part/delete/' + id, function (resp) {
                    if (resp.status === 'success') {
                        toast('success', 'Aturan dihapus.');
                        load();
                    } else {
                        toast('error', 'Gagal menghapus.');
                    }
                }, 'json');
            });
        });
    }

    load();
})(jQuery);
