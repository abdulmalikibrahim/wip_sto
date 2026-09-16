(function ($) {
    'use strict';

    // Card => the unit shops whose units it counts its parts on
    // (Wip_calc_model::summary_stages()), e.g. ipi -> wos_ipi, weld, toso, assy.
    // 'total' is one more card on top: every card added up, no card filter.
    var stages = WIP_SUMMARY_STAGES;
    var stageKeys = Object.keys(stages);

    // Welding is what's shown first.
    var currentStage = stageKeys.indexOf('weld') !== -1 ? 'weld' : stageKeys[0];
    var currentScope = 'all';
    var hideZeroRows = true; // zero rows are hidden by default; the button shows them again
    var requestSeq = 0;

    function esc(v) {
        return $('<div>').text(v == null ? '' : v).html();
    }

    function label(shop) {
        if (shop === 'total') return 'Total';
        return WIP_SUMMARY_SHOPS[shop] || shop.toUpperCase();
    }

    // Indonesian number format: dot for thousands, comma for decimals (390.027 / 12,5).
    function formatTotal(value) {
        return (parseFloat(value) || 0).toLocaleString('id-ID', { maximumFractionDigits: 3 });
    }

    // Zero reads as a dash, same as the WIP Calc tables.
    function formatQty(value) {
        return parseFloat(value) === 0
            ? '<span class="text-secondary">—</span>'
            : esc(formatTotal(value));
    }

    function qtyColumn(key, className) {
        return {
            data: key,
            className: className,
            render: function (value, type) {
                return type === 'display' ? formatQty(value) : parseFloat(value);
            }
        };
    }

    var columns = [
        { data: 'no', orderable: false, searchable: false },
        {
            data: 'source',
            className: 'text-center',
            render: function (value, type) {
                if (type !== 'display') return value;
                var cls = value === 'KAP1' ? 'text-bg-info' : (value === 'KAP2' ? 'text-bg-primary' : 'text-bg-success');
                return '<span class="badge ' + cls + '">' + esc(value) + '</span>';
            }
        },
        {
            data: 'part_number',
            render: function (value, type, row) {
                if (type !== 'display') return value;

                var html = esc(value);
                // Juklak: represented by its main part (on at least one line) — counted as 0 there.
                if (row.juklak_main) {
                    html += ' <span class="badge text-bg-warning text-dark" title="Juklak: sudah diwakili main part ' +
                        esc(row.juklak_main) + ' — dihitung 0">Juklak &rarr; ' + esc(row.juklak_main) + '</span>';
                }
                // Ditandai "tidak dihitung" di menu Summary Tanpa Cutoff, dengan alasannya.
                if (row.excluded_reason) {
                    html += ' <span class="badge text-bg-dark" title="Tidak dihitung: ' + esc(row.excluded_reason) +
                        '">Tidak dihitung</span>';
                }

                return html;
            }
        },
        { data: 'material_description' },
        { data: 'uom', className: 'text-center' },
        { data: 'shop_code' }
    ];

    // One column group per card — its unit shops, its Summary, its cutoff
    // VIN — then the Total card's group (every card's Summary, then Total),
    // in the same order as the view's header. Only the selected card's group
    // is visible.
    var groupCols = {};
    stageKeys.forEach(function (card) {
        var indexes = [];
        stages[card].forEach(function (unitShop) {
            indexes.push(columns.length);
            columns.push(qtyColumn(card + '__' + unitShop, 'text-center'));
        });
        indexes.push(columns.length);
        columns.push(qtyColumn('sum_' + card, 'text-center fw-semibold col-group-start'));
        indexes.push(columns.length);
        columns.push({
            data: card + '_vin',
            className: 'small',
            render: function (value, type) {
                if (type !== 'display') return value || '';
                return value ? esc(value) : '<span class="text-secondary">—</span>';
            }
        });
        // That VIN's Sequence in the card's own shop ("131 / 200"), labelled per
        // KAP line when both are in scope — same shape as the VIN column itself.
        indexes.push(columns.length);
        columns.push({
            data: card + '_vin_seq',
            className: 'text-center small',
            render: function (value, type) {
                if (type !== 'display') return value || '';
                return value ? esc(value) : '<span class="text-secondary">—</span>';
            }
        });
        groupCols[card] = indexes;
    });
    groupCols.total = [];
    stageKeys.forEach(function (card) {
        groupCols.total.push(columns.length);
        columns.push(qtyColumn('sum_' + card, 'text-center'));
    });
    groupCols.total.push(columns.length);
    columns.push(qtyColumn('sum_total', 'text-center fw-semibold col-group-start'));

    var table = $('#tblWipSummary').DataTable($.extend({}, window.APP_DT_DEFAULTS, {
        data: [],
        processing: true,
        order: [], // keep the server's Part Number order
        columns: columns
    }));

    $.fn.dataTable.ext.search.push(function (settings, searchData, index, rowData) {
        if (settings.nTable.id !== 'tblWipSummary') return true;
        // A card lists only its own shop's parts; Total lists every part.
        if (currentStage !== 'total' && !rowData['in_' + currentStage]) return false;

        return !hideZeroRows || parseFloat(rowData['sum_' + currentStage]) !== 0;
    });

    // ---- Excel export: follows the selected card, line, basis and zero filter ----
    var $btnExport = $('#btnExportSummary');

    function updateExportHref() {
        var query = 'stage=' + encodeURIComponent(currentStage) +
            '&scope=' + encodeURIComponent(currentScope) +
            (hideZeroRows ? '&hide_zero=1' : '');
        $btnExport.attr('href', CalcBasis.url(BASE_URL + 'wip/summary/export?' + query));
    }

    $btnExport.on('click', function (e) {
        e.preventDefault();
        downloadExcel($(this));
    });

    // ---- Card switch ----
    function applyStage() {
        $('[data-stage]').each(function () {
            $(this).toggleClass('active', $(this).data('stage') === currentStage);
        });

        Object.keys(groupCols).forEach(function (card) {
            groupCols[card].forEach(function (i) {
                table.column(i).visible(card === currentStage, false);
            });
        });
        $('#summaryStageName').text(label(currentStage));
        $('#summaryFormula').text(currentStage === 'total'
            ? 'Jumlah semua card: ' + stageKeys.map(label).join(' + ') + ' · semua part'
            : 'Part ' + label(currentStage) + ' · unit ' + stages[currentStage].map(label).join(' + '));

        table.columns.adjust();
        table.draw(false);
        updateExportHref();
    }

    $('[data-stage]').on('click', function () {
        var stage = $(this).data('stage');
        if (stage === currentStage) return;
        currentStage = stage;
        applyStage();
    });

    // ---- Line (KAP) switch ----
    $('[data-scope-option]').on('click', function () {
        var scope = $(this).data('scope-option');
        if (scope === currentScope) return;
        currentScope = scope;
        $('[data-scope-option]').each(function () {
            $(this).toggleClass('active', $(this).data('scope-option') === currentScope);
        });
        updateExportHref();
        load();
    });

    $('#btnToggleHideZero').on('click', function () {
        hideZeroRows = !hideZeroRows;
        $(this).toggleClass('btn-secondary', !hideZeroRows).toggleClass('btn-primary', hideZeroRows);
        $(this).find('i').toggleClass('bi-eye-slash', !hideZeroRows).toggleClass('bi-eye', hideZeroRows);
        updateExportHref();
        table.draw();
    });

    function paintTotals(resp) {
        $('[data-stage]').each(function () {
            var stage = $(this).data('stage');
            $(this).find('[data-stage-total]').text(resp ? formatTotal(resp.totals['sum_' + stage]) : '—');
            $(this).find('[data-stage-parts]').text(resp ? formatTotal(resp.parts[stage]) : '—');
        });
    }

    function load() {
        var seq = ++requestSeq;
        $('#summaryAlert').addClass('d-none').text('');
        $('[data-stage-total]').html('<span class="spinner-border spinner-border-sm" role="status"></span>');
        table.processing(true);

        $.getJSON(CalcBasis.url(BASE_URL + 'wip/summary/data?scope=' + encodeURIComponent(currentScope)))
            .done(function (resp) {
                if (seq !== requestSeq) return; // a newer line/basis request superseded this one
                if (resp.status !== 'success') {
                    $('#summaryAlert').removeClass('d-none').text(resp.message || 'Failed to load data.');
                    paintTotals(null);
                    table.clear().draw();
                    return;
                }
                paintTotals(resp);
                table.clear().rows.add(resp.data).draw();
            })
            .fail(function () {
                if (seq !== requestSeq) return;
                $('#summaryAlert').removeClass('d-none').text('Failed to reach the server.');
                paintTotals(null);
            })
            .always(function () {
                if (seq === requestSeq) table.processing(false);
            });
    }

    $(document).on('calcbasis:change', function () {
        updateExportHref();
        load();
    });

    applyStage();
    load();
})(jQuery);
