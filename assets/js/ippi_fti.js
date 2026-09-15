(function ($) {
    'use strict';

    // IPPI_BASE (e.g. "wip/calc-ipi"), IPPI_LABEL ("IPI"/"FTI") and IPPI_WOS
    // ("WOS IPI"/"WOS FTI") come from the view — one script for both menus.
    var isAdmin = $('#tblIppi thead th').last().text().trim() === 'Action';
    var currentLine = $('[data-line-option].active').data('line-option') || 'kap1';
    var hideZeroRows = false;
    var lastSummary = null;
    var requestSeq = 0;

    function url(path) {
        return BASE_URL + IPPI_BASE + '/' + path;
    }

    function esc(v) {
        return $('<div>').text(v == null ? '' : v).html();
    }

    // Indonesian number format: dot for thousands, comma for decimals.
    function formatNumber(value) {
        return (parseFloat(value) || 0).toLocaleString('id-ID', { maximumFractionDigits: 3 });
    }

    function formatQty(value) {
        return parseFloat(value) === 0 ? '<span class="text-secondary">—</span>' : esc(formatNumber(value));
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

    function cutoffText(row) {
        if (row.cutoff_status === 'used') {
            return esc(row.cutoff_vin) + ' <span class="text-secondary small">(unit ' + esc(row.cutoff_position) + ' of ' +
                esc(lastSummary ? lastSummary.wos_units : '?') + ')</span>';
        }
        if (row.cutoff_status === 'stale') {
            return '<span class="text-warning" title="VIN not in the ' + esc(IPPI_WOS) + ' data any more — every unit is counted">' +
                esc(row.cutoff_vin) + ' (stale)</span>';
        }
        return '<span class="text-secondary">Belum ada cutoff — Net 0</span>';
    }

    var columns = [
        { data: 'no', orderable: false, searchable: false },
        { data: 'part_no' },
        {
            data: 'part_name',
            render: function (value, type, row) {
                if (type !== 'display') return value;
                // No Welding row in the basis for this part -> nothing to count.
                return esc(value) + (row.welding_rows === 0
                    ? ' <span class="badge text-bg-secondary" title="Tidak ada baris Welding (WELD3/WELD4) untuk part ini di basis terpilih">no Welding row</span>'
                    : '');
            }
        },
        {
            data: 'cutoff_vin',
            className: 'small',
            render: function (value, type, row) {
                return type === 'display' ? cutoffText(row) : (value || '');
            }
        },
        qtyColumn('gross', 'text-center col-group-start text-secondary'),
        qtyColumn('cutoff', 'text-center text-secondary'),
        {
            data: 'net',
            className: 'text-center fw-semibold',
            render: function (value, type, row) {
                if (type !== 'display') return parseFloat(value);
                return '<a href="#" class="btn-ippi-formula text-decoration-none" data-part-number="' + esc(row.part_number) + '">' +
                    formatQty(value) + ' <i class="bi bi-calculator small"></i></a>';
            }
        }
    ];

    if (isAdmin) {
        columns.push({
            data: null,
            orderable: false,
            searchable: false,
            render: function (row) {
                return '<button class="btn btn-sm btn-outline-success btn-ippi-cutoff me-1" data-id="' + row.id + '" data-part="' +
                    esc(row.part_number) + '" data-vin="' + esc(row.cutoff_vin || '') + '" title="Set cutoff VIN"><i class="bi bi-signpost-2"></i></button>' +
                    '<button class="btn btn-sm btn-danger btn-ippi-delete" data-id="' + row.id + '" data-part="' + esc(row.part_number) +
                    '" title="Remove from list"><i class="bi bi-trash"></i></button>';
            }
        });
    }

    var table = $('#tblIppi').DataTable($.extend({}, window.APP_DT_DEFAULTS, {
        data: [],
        processing: true,
        order: [],
        columns: columns
    }));

    $.fn.dataTable.ext.search.push(function (settings, searchData, index, rowData) {
        if (settings.nTable.id !== 'tblIppi' || !hideZeroRows) return true;
        return parseFloat(rowData.net) !== 0 || parseFloat(rowData.gross) !== 0;
    });

    function paintStats(summary) {
        var s = summary || {};
        $('[data-ippi-stat="parts"]').text(summary ? (formatNumber(s.parts) + ' (' + formatNumber(s.with_cutoff) + ')') : '—');
        $('[data-ippi-stat="wos_units"]').text(summary ? formatNumber(s.wos_units) : '—');
        $('[data-ippi-stat="gross"]').text(summary ? formatNumber(s.gross) : '—');
        $('[data-ippi-stat="net"]').text(summary ? formatNumber(s.net) : '—');
    }

    function query(extra) {
        return 'line=' + encodeURIComponent(currentLine) + (extra || '');
    }

    function load() {
        var seq = ++requestSeq;
        $('#ippiAlert').addClass('d-none').text('');
        table.processing(true);

        $.getJSON(CalcBasis.url(url('data?' + query())))
            .done(function (resp) {
                if (seq !== requestSeq) return;
                if (resp.status !== 'success') {
                    $('#ippiAlert').removeClass('d-none').text(resp.message || 'Failed to load data.');
                    lastSummary = null;
                    paintStats(null);
                    table.clear().draw();
                    return;
                }
                lastSummary = resp.summary;
                paintStats(resp.summary);
                table.clear().rows.add(resp.data).draw();
            })
            .fail(function () {
                if (seq !== requestSeq) return;
                $('#ippiAlert').removeClass('d-none').text('Failed to reach the server.');
            })
            .always(function () {
                if (seq === requestSeq) table.processing(false);
            });
    }

    // ---- Line / basis / zero filter ----
    $('[data-line-option]').on('click', function () {
        var line = $(this).data('line-option');
        if (line === currentLine) return;
        currentLine = line;
        $('[data-line-option]').each(function () {
            $(this).toggleClass('active', $(this).data('line-option') === currentLine);
        });
        load();
    });

    $(document).on('calcbasis:change', load);

    $('#btnIppiHideZero').on('click', function () {
        hideZeroRows = !hideZeroRows;
        $(this).toggleClass('btn-secondary', !hideZeroRows).toggleClass('btn-primary', hideZeroRows);
        $(this).find('i').toggleClass('bi-eye-slash', !hideZeroRows).toggleClass('bi-eye', hideZeroRows);
        table.draw();
    });

    // ---- Downloads ----
    $('#btnIppiExport').on('click', function (e) {
        e.preventDefault();
        $(this).attr('href', CalcBasis.url(url('export?' + query(hideZeroRows ? '&hide_zero=1' : ''))));
        downloadExcel($(this));
    });

    $('.btn-ippi-download').on('click', function (e) {
        e.preventDefault();
        downloadExcel($(this));
    });

    // ---- Formula modal ----
    var $formulaBody = $('#modalIppiFormulaBody');

    function renderFormula(resp) {
        var c = resp.cutoff;
        var vin;
        if (c.status === 'used') {
            vin = esc(c.vin) + ' <span class="text-secondary">(unit ' + esc(c.position) + ' of ' + esc(c.total) + ' di WIP ' + esc(resp.wos_code) + ')</span>';
        } else if (c.status === 'stale') {
            vin = '<span class="text-warning">' + esc(c.vin) + ' (stale — tidak ada di WIP ' + esc(resp.wos_code) + ', semua unit dihitung)</span>';
        } else {
            vin = '<span class="text-secondary">Belum ada cutoff VIN — Net 0</span>';
        }

        var rows = resp.suffixes.map(function (s) {
            var zero = parseFloat(s.subtotal) === 0;
            return '<tr' + (zero ? ' class="text-secondary"' : '') + '>' +
                '<td>' + esc(s.model) + '</td><td>' + esc(s.suffix) + '</td>' +
                '<td class="text-end">' + esc(s.qty) + '</td>' +
                '<td class="text-end">' + formatQty(s.gross_units) + '</td>' +
                '<td class="text-end">' + formatQty(s.net_units) + '</td>' +
                '<td class="text-end' + (zero ? '' : ' fw-semibold text-body') + '">' + formatQty(s.subtotal) + '</td></tr>';
        }).join('');

        $formulaBody.html(
            '<dl class="row mb-3 small">' +
                '<dt class="col-4">Part Number</dt><dd class="col-8">' + esc(resp.part_number) + '</dd>' +
                '<dt class="col-4">Part Name</dt><dd class="col-8">' + esc(resp.part_name) + '</dd>' +
                '<dt class="col-4">Cutoff VIN</dt><dd class="col-8">' + vin + '</dd>' +
            '</dl>' +
            '<div class="text-secondary small mb-2">Suffix dari baris Welding (' + esc(resp.weld_code) + ') part ini, dan jumlah unit WIP ' +
                esc(resp.wos_code) + ' yang cocok:</div>' +
            (resp.suffixes.length ? '' : '<div class="alert alert-warning py-2 small mb-2">Tidak ada baris Welding untuk part ini di basis terpilih.</div>') +
            '<div class="table-responsive"><table class="table table-sm table-hover mb-0">' +
                '<thead><tr><th>Model</th><th>Suffix</th><th class="text-end">Qty</th><th class="text-end">Unit (Gross)</th>' +
                '<th class="text-end">Unit sejak cutoff</th><th class="text-end">Subtotal (Net)</th></tr></thead>' +
                '<tbody>' + rows + '</tbody>' +
                '<tfoot><tr class="fw-semibold border-top"><td colspan="5" class="text-end">Total Net</td>' +
                '<td class="text-end text-success">' + esc(formatNumber(resp.total)) + '</td></tr></tfoot>' +
            '</table></div>'
        );
    }

    $(document).on('click', '#tblIppi .btn-ippi-formula', function (e) {
        e.preventDefault();
        $formulaBody.html('<div class="text-center text-secondary py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>');
        bootstrap.Modal.getOrCreateInstance('#modalIppiFormula').show();

        $.getJSON(CalcBasis.url(url('breakdown?' + query('&part_number=' + encodeURIComponent($(this).data('part-number'))))))
            .done(function (resp) {
                if (!resp.ok) {
                    $formulaBody.html('<div class="alert alert-danger mb-0">' + esc(resp.message || 'Failed to load the formula.') + '</div>');
                    return;
                }
                renderFormula(resp);
            })
            .fail(function () {
                $formulaBody.html('<div class="alert alert-danger mb-0">Failed to reach the server.</div>');
            });
    });

    // ---- Cutoff VIN (admin) ----
    $(document).on('click', '#tblIppi .btn-ippi-cutoff', function () {
        $('#ippiCutoffAlert').addClass('d-none').text('');
        $('#ippiCutoffId').val($(this).data('id'));
        $('#ippiCutoffPart').text($(this).data('part') + ' (' + (currentLine === 'kap2' ? 'KAP 2' : 'KAP 1') + ')');
        $('#ippiCutoffVin').val($(this).data('vin'));
        bootstrap.Modal.getOrCreateInstance('#modalIppiCutoff').show();
    });

    $('#formIppiCutoff').on('submit', function (e) {
        e.preventDefault();
        var $btn = $('#btnIppiCutoffSave').prop('disabled', true);
        $.post(url('cutoff'), { id: $('#ippiCutoffId').val(), vin: $('#ippiCutoffVin').val() }, null, 'json')
            .done(function (resp) {
                if (resp.status !== 'success') {
                    $('#ippiCutoffAlert').removeClass('d-none').text(resp.message || 'Failed to save the cutoff VIN.');
                    return;
                }
                toast('success', resp.message);
                bootstrap.Modal.getOrCreateInstance('#modalIppiCutoff').hide();
                load();
            })
            .fail(function (xhr) {
                $('#ippiCutoffAlert').removeClass('d-none').text((xhr.responseJSON && xhr.responseJSON.message) || 'Failed to reach the server.');
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    });

    $(document).on('click', '#tblIppi .btn-ippi-delete', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Remove ' + $(this).data('part') + ' from the ' + IPPI_LABEL + ' list?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Remove',
            confirmButtonColor: '#dc3545',
            background: '#21262f',
            color: '#e4e6eb'
        }).then(function (res) {
            if (!res.isConfirmed) return;
            $.post(url('delete/' + id), function (resp) {
                if (resp.status === 'success') {
                    toast('success', 'Removed from the ' + IPPI_LABEL + ' list');
                    load();
                } else {
                    toast('error', 'Failed to delete');
                }
            }, 'json');
        });
    });

    // ---- Upload (real form submit) ----
    var $uploadForm = $('#modalUploadIppi form');
    $uploadForm.on('submit', function () {
        $uploadForm.find('button').prop('disabled', true);
        $uploadForm.find('button[type="submit"]').html('<span class="spinner-border spinner-border-sm me-1" role="status"></span>Uploading...');
        $('#ippiUploadOverlay').removeClass('d-none');
    });

    var $file = $('#ippi_fti_file');
    var $dz = $('#dropzoneIppi');
    $file.on('change', function () {
        var name = this.files.length ? this.files[0].name : null;
        $('#dropzoneIppiLabel').text(name || 'Click to choose an .xlsx file or drag it here');
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
