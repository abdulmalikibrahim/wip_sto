(function ($) {
    'use strict';

    function esc(v) {
        return $('<div>').text(v == null ? '' : v).html();
    }

    var columns = [
        { data: 'no', orderable: false, searchable: false },
        { data: 'part_number' },
        { data: 'material' },
        { data: 'material_description' },
        { data: 'model' },
        { data: 'suffix' },
        { data: 'shop_label' },
        {
            data: 'cutoff_vin',
            render: function (value, type, row) {
                if (type !== 'display') return value || '';
                if (!value) {
                    return '<span class="text-secondary">Total (no cutoff)</span>';
                }
                if (row.cutoff_status === 'stale') {
                    return '<span class="text-warning" title="VIN not found in the current WIP data — falling back to total">' +
                        esc(value) + ' (stale)</span>';
                }
                return esc(value);
            }
        },
        {
            data: null,
            orderable: false,
            searchable: false,
            className: 'text-center',
            render: function (data, type) {
                if (type !== 'display') return '';
                return '<button type="button" class="btn btn-sm btn-primary btn-formula">' +
                    '<i class="bi bi-calculator me-1"></i>Formula</button>';
            }
        },
        { data: 'subtotal', className: 'text-end fw-semibold' }
    ];

    var table = $('#tblWipCalcDetail').DataTable($.extend({}, window.APP_DT_DEFAULTS, {
        data: [],
        processing: true,
        columns: columns
    }));

    // Formula button — shows the multiplication behind that row's Subtotal
    // in a modal instead of cramming it into the table column.
    var $modalFormulaBody = $('#modalFormulaBody');

    function fieldRow(label, value) {
        return '<dt class="col-5">' + esc(label) + '</dt><dd class="col-7">' + value + '</dd>';
    }

    $('#tblWipCalcDetail').on('click', '.btn-formula', function () {
        var rowData = table.row($(this).closest('tr')).data();
        if (!rowData) return;

        var vinDisplay;
        if (!rowData.cutoff_vin) {
            vinDisplay = '<span class="text-secondary">Total (no cutoff set)</span>';
        } else if (rowData.cutoff_status === 'stale') {
            vinDisplay = '<span class="text-warning">' + esc(rowData.cutoff_vin) + ' (stale — not found, totaled instead)</span>';
        } else {
            vinDisplay = esc(rowData.cutoff_vin);
        }

        $modalFormulaBody.html(
            '<dl class="row mb-3 small">' +
                fieldRow('Part Number', esc(rowData.part_number)) +
                fieldRow('Material', esc(rowData.material)) +
                fieldRow('Model', esc(rowData.model)) +
                fieldRow('Suffix', esc(rowData.suffix)) +
                fieldRow('Shop', esc(rowData.shop_label)) +
                fieldRow('Cutoff VIN', vinDisplay) +
            '</dl>' +
            '<div class="text-center border-top pt-3">' +
                '<div class="text-secondary small mb-1">Matching WIP units × Qty per unit (BOM)</div>' +
                '<div class="fs-4">' +
                    '<strong>' + esc(rowData.unit_count) + '</strong> unit &times; <strong>' + esc(rowData.qty) + '</strong>' +
                    ' = <strong class="text-success">' + esc(rowData.subtotal) + '</strong>' +
                '</div>' +
            '</div>'
        );

        bootstrap.Modal.getOrCreateInstance('#modalFormula').show();
    });

    function load() {
        $('#calcDetailAlert').addClass('d-none').text('');
        table.processing(true);

        $.getJSON(BASE_URL + WIP_CALC_SOURCE + '/calc/detail/data')
            .done(function (resp) {
                if (resp.status !== 'success') {
                    $('#calcDetailAlert').removeClass('d-none').text(resp.message || 'Failed to load data.');
                    table.clear().draw();
                    return;
                }
                table.clear().rows.add(resp.data).draw();
            })
            .fail(function () {
                $('#calcDetailAlert').removeClass('d-none').text('Failed to reach the server.');
            })
            .always(function () {
                table.processing(false);
            });
    }

    // Download Excel — same blob-fetch loading-indicator pattern as the WIP Calc List page.
    var exportInProgress = false;
    var $btnExportCalcDetail = $('#btnExportCalcDetail');

    $btnExportCalcDetail.on('click', function (e) {
        e.preventDefault();
        if (exportInProgress) return;

        var $btn = $(this);
        var url = $btn.attr('href');
        var originalHtml = $btn.html();

        exportInProgress = true;
        $btn.addClass('disabled').css('pointer-events', 'none')
            .html('<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Preparing...');

        fetch(url, { credentials: 'same-origin' })
            .then(function (resp) {
                if (!resp.ok) {
                    throw new Error('Failed to generate the report (HTTP ' + resp.status + ').');
                }
                var disposition = resp.headers.get('Content-Disposition') || '';
                var match = disposition.match(/filename="?([^";]+)"?/);
                var filename = match ? match[1] : 'wip_calc_detail.xlsx';

                return resp.blob().then(function (blob) {
                    return { blob: blob, filename: filename };
                });
            })
            .then(function (result) {
                var blobUrl = URL.createObjectURL(result.blob);
                var $tmp = $('<a>').attr({ href: blobUrl, download: result.filename }).appendTo('body');
                $tmp[0].click();
                $tmp.remove();
                setTimeout(function () { URL.revokeObjectURL(blobUrl); }, 1000);
            })
            .catch(function (err) {
                toast('error', (err && err.message) || 'Failed to reach the server.');
            })
            .finally(function () {
                exportInProgress = false;
                $btn.removeClass('disabled').css('pointer-events', '').html(originalHtml);
            });
    });

    load();
})(jQuery);
