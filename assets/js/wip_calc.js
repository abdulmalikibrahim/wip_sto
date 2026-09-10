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
        { data: 'part_number' },
        { data: 'material_description' },
        { data: 'shop_code' }
    ];

    // Gross/Cutoff/Net are always shown together as one fixed group per
    // shop (Gross | Cutoff | Net) — no show/hide toggle, so there's no
    // dynamic column-visibility juggling for DataTables' header colspans to
    // get wrong (that's what broke when these were togglable).
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
        $btnExportCalc.attr('href', hideZeroRows ? (exportBaseHref + '?hide_zero=1') : exportBaseHref);
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
                var filename = match ? match[1] : 'wip_calc.xlsx';

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

    function renderPartNumberList(rows) {
        var seen = {};
        var $list = $('#partNumberList').empty();
        (rows || []).forEach(function (r) {
            if (seen[r.part_number]) return;
            seen[r.part_number] = true;
            $list.append('<option value="' + esc(r.part_number) + '">');
        });
    }

    function renderSummary(summary) {
        $('#cutoffStatus [data-cutoff-shop]').each(function () {
            var shop = $(this).data('cutoff-shop');
            var info = summary ? summary[shop] : null;
            var $val = $(this).find('[data-cutoff-summary]');

            if (!info) {
                $val.text('—');
                return;
            }
            $val.text(info.with_cutoff + ' / ' + info.total_parts);
            if (info.stale > 0) {
                $val.append(' <span class="text-warning small">(' + info.stale + ' stale)</span>');
            }
        });
    }

    function load() {
        $('#calcAlert').addClass('d-none').text('');
        table.processing(true);

        $.getJSON(BASE_URL + WIP_CALC_SOURCE + '/calc/data')
            .done(function (resp) {
                if (resp.status !== 'success') {
                    $('#calcAlert').removeClass('d-none').text(resp.message || 'Failed to load data.');
                    table.clear().draw();
                    renderSummary(null);
                    return;
                }
                table.clear().rows.add(resp.data).draw();
                renderSummary(resp.cutoff_summary);
                renderPartNumberList(resp.data);
            })
            .fail(function () {
                $('#calcAlert').removeClass('d-none').text('Failed to reach the server.');
            })
            .always(function () {
                table.processing(false);
            });
    }

    // Upload dropzone UX
    var $file = $('#cutoff_file');
    var $dz = $('#dropzoneCutoff');
    $file.on('change', function () {
        var name = this.files.length ? this.files[0].name : null;
        $('#dropzoneCutoffLabel').text(name || 'Click to choose an .xlsx file or drag it here');
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

    // Add Cutoff VIN modal — single entry, AJAX only, no page reload.
    var $formSetCutoff = $('#formSetCutoff');
    var $setCutoffAlert = $('#setCutoffAlert');
    var $btnSaveCutoff = $('#btnSaveCutoff');

    $('#modalSetCutoff').on('shown.bs.modal', function () {
        $setCutoffAlert.addClass('d-none').text('');
        $('#cutoffPartNumber').trigger('focus');
    });

    $formSetCutoff.on('submit', function (e) {
        e.preventDefault();
        $setCutoffAlert.addClass('d-none').text('');
        $btnSaveCutoff.prop('disabled', true);

        $.post(BASE_URL + WIP_CALC_SOURCE + '/calc/cutoff', {
            shop_code: $('#cutoffShopCode').val(),
            part_number: $('#cutoffPartNumber').val(),
            vin: $('#cutoffVin').val()
        }, null, 'json')
            .done(function (resp) {
                if (resp.status !== 'success') {
                    $setCutoffAlert.removeClass('d-none').text(resp.message || 'Failed to save cutoff VIN.');
                    return;
                }
                toast('success', resp.message || 'Cutoff VIN saved.');
                $formSetCutoff[0].reset();
                bootstrap.Modal.getOrCreateInstance('#modalSetCutoff').hide();
                load();
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Failed to reach the server.';
                $setCutoffAlert.removeClass('d-none').text(msg);
            })
            .always(function () {
                $btnSaveCutoff.prop('disabled', false);
            });
    });

    load();
})(jQuery);
