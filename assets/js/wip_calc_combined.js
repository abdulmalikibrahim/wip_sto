(function ($) {
    'use strict';

    function esc(v) {
        return $('<div>').text(v == null ? '' : v).html();
    }

    // Zero reads as a dash — easier to scan a wide Gross/Cutoff/Net table
    // for the numbers that actually matter than a grid full of "0"s.
    function formatQty(value) {
        return parseFloat(value) === 0
            ? '<span class="text-secondary">—</span>'
            : esc(value);
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
        { data: 'part_number' },
        { data: 'material_description' },
        { data: 'shop_code' }
    ];

    // Gross/Cutoff/Net are always shown together as one fixed group per shop.
    function pushGroup(key, netClassName) {
        columns.push({
            data: key + '_gross',
            className: 'text-center col-group-start',
            render: function (value, type) {
                return type === 'display' ? formatQty(value) : value;
            }
        });
        columns.push({
            data: key + '_cutoff',
            className: 'text-center',
            render: function (value, type) {
                return type === 'display' ? formatQty(value) : value;
            }
        });
        columns.push({
            data: key,
            className: netClassName || 'text-center',
            render: function (value, type, row) {
                if (type !== 'display') return value;
                var vin = row[key + '_vin'];
                var title = vin ? ('Cutoff VIN: ' + vin) : 'No cutoff set — showing total';
                return '<span title="' + esc(title) + '">' + formatQty(value) + '</span>';
            }
        });
    }

    Object.keys(WIP_CALC_SHOPS).forEach(function (shop) {
        pushGroup(shop);
    });
    pushGroup('total', 'text-center fw-semibold');

    Object.keys(WIP_CALC_SHOPS).forEach(function (shop) {
        columns.push({
            data: shop + '_vin',
            className: 'small',
            render: function (value, type) {
                if (type !== 'display') return value || '';
                return value ? esc(value) : '<span class="text-secondary">—</span>';
            }
        });
    });

    var table = $('#tblWipCalc').DataTable($.extend({}, window.APP_DT_DEFAULTS, {
        data: [],
        processing: true,
        columns: columns
    }));

    // "Hide Zero-Total Rows" toggle — filters the table client-side, and is
    // carried over to the Excel export (as ?hide_zero=1) so the download
    // matches whatever is currently shown on screen.
    var hideZeroRows = false;
    var $btnToggleHideZero = $('#btnToggleHideZero');
    var $btnExportCalc = $('#btnExportCalc');
    var exportBaseHref = $btnExportCalc.attr('href');

    $.fn.dataTable.ext.search.push(function (settings, searchData, index, rowData) {
        if (settings.nTable.id !== 'tblWipCalc' || !hideZeroRows) return true;

        return parseFloat(rowData.total) !== 0;
    });

    function updateExportHref() {
        $btnExportCalc.attr('href', CalcBasis.url(hideZeroRows ? (exportBaseHref + '?hide_zero=1') : exportBaseHref));
    }

    $btnToggleHideZero.on('click', function () {
        hideZeroRows = !hideZeroRows;
        $(this).toggleClass('btn-secondary', !hideZeroRows).toggleClass('btn-primary', hideZeroRows);
        $(this).find('i').toggleClass('bi-eye-slash', !hideZeroRows).toggleClass('bi-eye', hideZeroRows);
        updateExportHref();
        table.draw();
    });

    // Download Excel — fetched as a blob (instead of a plain link navigation)
    // so we know exactly when the file has actually finished generating and
    // arrived, and can show/clear a loading state around that.
    var exportInProgress = false;

    $btnExportCalc.on('click', function (e) {
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
                var filename = match ? match[1] : 'wip_calc_kap1_kap2.xlsx';

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

    function load() {
        $('#calcAlert').addClass('d-none').text('');
        table.processing(true);

        $.getJSON(CalcBasis.url(BASE_URL + 'wip/calc-combined/data'))
            .done(function (resp) {
                if (resp.status !== 'success') {
                    $('#calcAlert').removeClass('d-none').text(resp.message || 'Failed to load data.');
                    table.clear().draw();
                    return;
                }
                table.clear().rows.add(resp.data).draw();
            })
            .fail(function () {
                $('#calcAlert').removeClass('d-none').text('Failed to reach the server.');
            })
            .always(function () {
                table.processing(false);
            });
    }

    load();
})(jQuery);
