(function ($) {
    'use strict';

    function esc(v) {
        return $('<div>').text(v == null ? '' : v).html();
    }

    // Zero reads as a dash — makes the non-zero (actually contributing)
    // suffixes easier to spot in the breakdown table.
    function dashIfZero(v) {
        return parseFloat(v) === 0 ? '<span class="text-secondary">—</span>' : esc(v);
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
                var position = (row.cutoff_position != null)
                    ? ' <span class="text-secondary small">(unit ' + esc(row.cutoff_position) + ' of ' + esc(row.total_wip) + ')</span>'
                    : '';
                return esc(value) + position;
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

    function renderBreakdown(resp) {
        var vinDisplay;
        if (!resp.cutoff) {
            vinDisplay = '<span class="text-secondary">Total (no cutoff set)</span>';
        } else if (resp.cutoff.status === 'stale') {
            vinDisplay = '<span class="text-warning">' + esc(resp.cutoff.vin) + ' (stale — not found, totaled instead)</span>';
        } else {
            vinDisplay = esc(resp.cutoff.vin) +
                ' <span class="text-secondary">(unit ' + esc(resp.cutoff.position) + ' of ' + esc(resp.cutoff.total) +
                ' in ' + esc(resp.shop_label) + ')</span>';
        }

        var rows = resp.suffixes.map(function (s) {
            var zero = parseFloat(s.subtotal) === 0;
            // "See VINs" is only useful when there's something to actually
            // list — a zero-count suffix has none to show.
            var vinBtn = zero ? '' :
                '<button type="button" class="btn btn-link btn-sm p-0 ms-2 btn-see-vins" ' +
                'data-part-number="' + esc(resp.part_number) + '" data-shop-code="' + esc(resp.shop_code) + '" ' +
                'data-model="' + esc(s.model) + '" data-suffix="' + esc(s.suffix) + '">See VINs</button>';
            return '<tr' + (zero ? ' class="text-secondary"' : '') + '>' +
                '<td>' + esc(s.model) + '</td>' +
                '<td>' + esc(s.suffix) + '</td>' +
                '<td class="text-end">' + esc(s.qty) + '</td>' +
                '<td class="text-end">' + dashIfZero(s.unit_count) + vinBtn + '</td>' +
                '<td class="text-end' + (zero ? '' : ' fw-semibold text-body') + '">' + dashIfZero(s.subtotal) + '</td>' +
                '</tr>';
        }).join('');

        $modalFormulaBody.html(
            '<dl class="row mb-3 small">' +
                fieldRow('Part Number', esc(resp.part_number)) +
                fieldRow('Material Description', esc(resp.material_description)) +
                fieldRow('Shop', esc(resp.shop_label)) +
                fieldRow('Cutoff VIN', vinDisplay) +
            '</dl>' +
            '<div class="text-secondary small mb-2">Every suffix this part is defined for in the BOM, and how many of the shop\'s cached WIP units matched each one:</div>' +
            '<div class="table-responsive">' +
                '<table class="table table-sm table-hover mb-0">' +
                    '<thead><tr><th>Model</th><th>Suffix</th><th class="text-end">BOM Qty</th><th class="text-end">Matching Units</th><th class="text-end">Subtotal</th></tr></thead>' +
                    '<tbody>' + rows + '</tbody>' +
                    '<tfoot><tr class="fw-semibold border-top">' +
                        '<td colspan="4" class="text-end">Total</td>' +
                        '<td class="text-end text-success">' + esc(resp.grand_subtotal) + '</td>' +
                    '</tr></tfoot>' +
                '</table>' +
            '</div>'
        );
    }

    // "See VINs" — the actual cached units behind one suffix row's Matching
    // Units count, so a number that looks off against a hand-counted list
    // (e.g. Excel) can be checked VIN by VIN. Duplicate VINs (the same unit
    // cached twice) are highlighted, since that's the usual cause.
    var $modalVinListBody = $('#modalVinListBody');

    function renderVinList(resp) {
        if (!resp.vins.length) {
            $modalVinListBody.html('<div class="text-secondary">No matching units found.</div>');
            return;
        }

        var dupCount = resp.vins.filter(function (v) { return v.duplicate; }).length;
        var warning = dupCount > 0 ?
            '<div class="alert alert-warning py-2 px-3 small mb-3">' +
                '<i class="bi bi-exclamation-triangle me-1"></i>' +
                dupCount + ' row(s) below share a VIN with another row — the same physical unit is cached more than once, ' +
                'which inflates this count above a hand-counted list.' +
            '</div>' : '';

        var rows = resp.vins.map(function (v) {
            return '<tr' + (v.duplicate ? ' class="table-warning"' : '') + '>' +
                '<td class="text-end">' + esc(v.seq) + '</td>' +
                '<td>' + esc(v.vin) + (v.duplicate ? ' <i class="bi bi-exclamation-triangle-fill text-warning" title="Duplicate VIN"></i>' : '') + '</td>' +
                '<td>' + esc(v.katashiki) + '</td>' +
                '<td>' + esc(v.modelcode) + '</td>' +
                '<td>' + esc(v.sfx) + '</td>' +
                '</tr>';
        }).join('');

        $modalVinListBody.html(
            '<dl class="row mb-3 small">' +
                fieldRow('Part Number', esc(resp.part_number)) +
                fieldRow('Model / Suffix', esc(resp.model) + ' / ' + esc(resp.suffix)) +
                fieldRow('Shop', esc(resp.shop_label)) +
                fieldRow('Matching Units', esc(resp.unit_count)) +
            '</dl>' +
            warning +
            '<div class="table-responsive">' +
                '<table class="table table-sm table-hover mb-0">' +
                    '<thead><tr><th class="text-end">Seq</th><th>VIN</th><th>Katashiki</th><th>Model</th><th>Suffix</th></tr></thead>' +
                    '<tbody>' + rows + '</tbody>' +
                '</table>' +
            '</div>'
        );
    }

    $modalFormulaBody.on('click', '.btn-see-vins', function () {
        var $btn = $(this);
        var params = {
            part_number: $btn.data('part-number'),
            shop_code: $btn.data('shop-code'),
            model: $btn.data('model'),
            suffix: $btn.data('suffix')
        };

        // Bootstrap doesn't officially support two modals open at once, so
        // step out of the Formula modal before opening this one instead of
        // stacking them.
        var formulaModal = bootstrap.Modal.getInstance(document.getElementById('modalFormula'));
        if (formulaModal) formulaModal.hide();

        $modalVinListBody.html('<div class="text-center text-secondary py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>');
        bootstrap.Modal.getOrCreateInstance('#modalVinList').show();

        $.getJSON(CalcBasis.url(BASE_URL + WIP_CALC_SOURCE + '/calc/detail/breakdown/vins'), params)
            .done(function (resp) {
                if (!resp.ok) {
                    $modalVinListBody.html('<div class="alert alert-danger mb-0">' + esc(resp.message || 'Failed to load the VIN list.') + '</div>');
                    return;
                }
                renderVinList(resp);
            })
            .fail(function () {
                $modalVinListBody.html('<div class="alert alert-danger mb-0">Failed to reach the server.</div>');
            });
    });

    $('#tblWipCalcDetail').on('click', '.btn-formula', function () {
        var rowData = table.row($(this).closest('tr')).data();
        if (!rowData) return;

        $modalFormulaBody.html('<div class="text-center text-secondary py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>');
        bootstrap.Modal.getOrCreateInstance('#modalFormula').show();

        $.getJSON(CalcBasis.url(BASE_URL + WIP_CALC_SOURCE + '/calc/detail/breakdown'), {
            part_number: rowData.part_number,
            shop_code: rowData.shop_code
        })
            .done(function (resp) {
                if (!resp.ok) {
                    $modalFormulaBody.html('<div class="alert alert-danger mb-0">' + esc(resp.message || 'Failed to load this part\'s breakdown.') + '</div>');
                    return;
                }
                renderBreakdown(resp);
            })
            .fail(function () {
                $modalFormulaBody.html('<div class="alert alert-danger mb-0">Failed to reach the server.</div>');
            });
    });

    function load() {
        $('#calcDetailAlert').addClass('d-none').text('');
        table.processing(true);

        $.getJSON(CalcBasis.url(BASE_URL + WIP_CALC_SOURCE + '/calc/detail/data'))
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
        var url = CalcBasis.url($btn.attr('href'));
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

    // Switching basis re-runs the whole calculation server-side.
    $(document).on('calcbasis:change', function () {
        load();
    });

    load();
})(jQuery);
