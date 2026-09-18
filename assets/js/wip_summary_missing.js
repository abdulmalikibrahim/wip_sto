(function ($) {
    'use strict';

    // Card => the unit shops it counts its parts on (Wip_calc_model::summary_stages()).
    var stages = WIP_MISSING_STAGES;
    var currentCard = 'all';
    // Starts on whichever line button the page marked active (a scoped User
    // only gets its own line's button).
    var currentScope = $('[data-scope-option].active').data('scope-option') || 'all';
    var currentStatus = '';
    var currentDecision = '';
    var allRows = [];
    var requestSeq = 0;

    function esc(v) {
        return $('<div>').text(v == null ? '' : v).html();
    }

    function label(shop) {
        return WIP_MISSING_SHOPS[shop] || String(shop).toUpperCase();
    }

    // Indonesian number format: dot for thousands, comma for decimals.
    function formatTotal(value) {
        return (parseFloat(value) || 0).toLocaleString('id-ID', { maximumFractionDigits: 3 });
    }

    // Zero reads as a dash; null = the card doesn't count that shop at all.
    function formatQty(value) {
        if (value === null || value === undefined) {
            return '<span class="text-secondary opacity-50" title="Tidak dihitung di card ini">&middot;</span>';
        }
        return parseFloat(value) === 0 ? '<span class="text-secondary">—</span>' : esc(formatTotal(value));
    }

    function qtyColumn(key, className) {
        return {
            data: key,
            className: className || 'text-center',
            render: function (value, type) {
                if (type === 'display') return formatQty(value);
                return value === null ? -1 : parseFloat(value);
            }
        };
    }

    var STATUS = {
        none: { text: 'Belum ada cutoff VIN', cls: 'text-bg-danger' },
        stale: { text: 'Cutoff VIN stale', cls: 'text-bg-warning text-dark' }
    };

    function statusBadge(row) {
        var s = STATUS[row.vin_state] || { text: row.vin_state, cls: 'text-bg-secondary' };
        var title = row.vin_state === 'stale'
            ? 'VIN ' + row.cutoff_vin + ' sudah tidak ada di data WIP ' + row.own_label + ' — semua unit dihitung'
            : 'Net ' + row.own_label + ' = 0 sampai cutoff VIN di-set';
        return '<span class="badge ' + s.cls + '" title="' + esc(title) + '">' + esc(s.text) + '</span>' +
            (row.vin_state === 'stale' ? '<div class="small text-secondary mt-1">' + esc(row.cutoff_vin) + '</div>' : '');
    }

    // Keputusan per part per line: "Tidak dihitung" membuat part-nya 0 di WIP
    // Calc & Summary beserta alasannya; "Dihitung" hanya penanda triase.
    var DECISION = {
        counted: { text: 'Dihitung', cls: 'text-bg-success' },
        excluded: { text: 'Tidak dihitung', cls: 'text-bg-dark' }
    };

    // Status = keputusannya saja; alasannya dibaca lewat tombol Detail.
    function statusCell(row) {
        var d = DECISION[row.decision];
        if (!d) return '<span class="badge text-bg-secondary">Belum diputuskan</span>';

        return '<span class="badge ' + d.cls + '" title="' +
            (row.decision === 'excluded' ? 'Dihitung 0 di WIP Calc &amp; Summary — klik Detail untuk alasannya' : 'Tetap dihitung seperti biasa') +
            '">' + esc(d.text) + '</span>';
    }

    function actionCell(row) {
        if (!WIP_MISSING_CAN_DECIDE) return '<span class="text-secondary">—</span>';

        var attrs = 'data-plant="' + esc(row.source.toLowerCase()) + '" data-part-number="' + esc(row.part_number) + '"';

        return '<div class="btn-group btn-group-sm">' +
            '<button type="button" class="btn btn-outline-success btn-decide" data-decision="counted" ' + attrs +
                ' title="Tandai tetap dihitung"><i class="bi bi-check-lg"></i></button>' +
            '<button type="button" class="btn btn-outline-warning btn-decide" data-decision="excluded" ' + attrs +
                ' title="Tandai tidak dihitung + alasan"><i class="bi bi-slash-circle"></i></button>' +
            (row.decision
                ? '<button type="button" class="btn btn-outline-secondary btn-decide-clear" ' + attrs +
                    ' title="Hapus keputusan"><i class="bi bi-arrow-counterclockwise"></i></button>'
                : '') +
            '</div>';
    }

    var columns = [
        { data: null, defaultContent: '', orderable: false, searchable: false },
        {
            data: 'source',
            className: 'text-center',
            render: function (value, type) {
                if (type !== 'display') return value;
                return '<span class="badge ' + (value === 'KAP1' ? 'text-bg-info' : 'text-bg-primary') + '">' + esc(value) + '</span>';
            }
        },
        { data: 'card_label', className: 'text-center' },
        { data: 'part_number' },
        { data: 'material_description' },
        { data: 'shop_code' },
        {
            data: 'vin_state',
            render: function (value, type, row) {
                if (type === 'display') return statusBadge(row);
                return STATUS[value] ? STATUS[value].text : value;
            }
        },
        { data: 'own_label', className: 'text-center' },
        qtyColumn('own_gross', 'text-center col-group-start'),
        qtyColumn('own_counted'),
        qtyColumn('v_weld', 'text-center col-group-start'),
        qtyColumn('v_toso'),
        qtyColumn('v_assy'),
        qtyColumn('summary', 'text-center fw-semibold col-group-start'),
        {
            data: 'decision',
            className: 'text-center',
            render: function (value, type, row) {
                if (type === 'display') return statusCell(row);
                // Alasannya ikut dicari/diurutkan walau hanya tampil di modal Detail.
                return (DECISION[value] ? DECISION[value].text : 'Belum diputuskan') + ' ' + (row.reason || '');
            }
        },
        {
            data: null,
            orderable: false,
            searchable: false,
            className: 'text-center',
            render: function (value, type, row) {
                return type === 'display' ? actionCell(row) : '';
            }
        },
        {
            data: null,
            orderable: false,
            searchable: false,
            className: 'text-center',
            render: function (value, type, row) {
                if (type !== 'display') return '';
                return '<button type="button" class="btn btn-sm btn-outline-primary btn-missing-detail text-nowrap" ' +
                    'data-source="' + esc(row.source.toLowerCase()) + '" data-card="' + esc(row.card) + '" ' +
                    'data-part-number="' + esc(row.part_number) + '"><i class="bi bi-search"></i> Detail</button>';
            }
        }
    ];

    var table = $('#tblMissingCutoff').DataTable($.extend({}, window.APP_DT_DEFAULTS, {
        data: [],
        processing: true,
        order: [], // keep the server's Line → Card → Part Number order
        columns: columns
    }));

    // Row numbers follow what's on screen (after filters / sorting).
    table.on('draw.dt', function () {
        var start = table.page.info().start;
        table.column(0, { page: 'current' }).nodes().each(function (cell, i) {
            cell.innerHTML = start + i + 1;
        });
    });

    function rowVisible(row, ignoreCard) {
        if (currentStatus && row.vin_state !== currentStatus) return false;
        if (currentDecision === 'undecided' && row.decision !== null) return false;
        if (currentDecision && currentDecision !== 'undecided' && row.decision !== currentDecision) return false;
        return ignoreCard || currentCard === 'all' || row.card === currentCard;
    }

    $.fn.dataTable.ext.search.push(function (settings, searchData, index, rowData) {
        if (settings.nTable.id !== 'tblMissingCutoff') return true;
        return rowVisible(rowData);
    });

    // Card stats follow the status filter (not the card filter — every card keeps its own count).
    function paintCards(failed) {
        var stats = { all: { parts: 0, total: 0 } };
        Object.keys(stages).forEach(function (card) {
            stats[card] = { parts: 0, total: 0 };
        });
        allRows.forEach(function (row) {
            if (!rowVisible(row, true)) return;
            [row.card, 'all'].forEach(function (card) {
                if (!stats[card]) return;
                stats[card].parts++;
                stats[card].total += parseFloat(row.summary) || 0;
            });
        });
        $('[data-card-filter]').each(function () {
            var s = failed ? null : stats[$(this).data('card-filter')];
            $(this).find('[data-card-parts]').text(s ? formatTotal(s.parts) : '—');
            $(this).find('[data-card-total]').text(s ? formatTotal(s.total) : '—');
        });
    }

    // ---- Excel export: follows the selected card, line, status and basis ----
    var $btnExport = $('#btnExportMissing');

    function updateExportHref() {
        var query = 'scope=' + encodeURIComponent(currentScope) +
            '&card=' + encodeURIComponent(currentCard === 'all' ? '' : currentCard) +
            '&status=' + encodeURIComponent(currentStatus) +
            '&decision=' + encodeURIComponent(currentDecision);
        $btnExport.attr('href', CalcBasis.url(BASE_URL + 'wip/summary/missing-cutoff/export?' + query));
    }

    $btnExport.on('click', function (e) {
        e.preventDefault();
        downloadExcel($(this));
    });

    $('[data-card-filter]').on('click', function () {
        currentCard = $(this).data('card-filter');
        $('[data-card-filter]').each(function () {
            $(this).toggleClass('active', $(this).data('card-filter') === currentCard);
        });
        table.draw();
        updateExportHref();
    });

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

    $('#missingStatusFilter').on('change', function () {
        currentStatus = $(this).val();
        paintCards();
        table.draw();
        updateExportHref();
    });

    $('#missingDecisionFilter').on('change', function () {
        currentDecision = $(this).val();
        paintCards();
        table.draw();
        updateExportHref();
    });

    // ---- Keputusan: dihitung / tidak dihitung (+ alasan) / hapus keputusan ----
    function afterDecision(resp) {
        if (resp.status !== 'success') {
            toast('error', resp.message || 'Gagal menyimpan keputusan.');
            return false;
        }
        toast('success', resp.message || 'Keputusan tersimpan.');
        load(); // angkanya ikut berubah, jadi datanya dimuat ulang
        return true;
    }

    function decisionFailed(xhr) {
        toast('error', (xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menghubungi server.');
    }

    $(document).on('click', '#tblMissingCutoff .btn-decide', function () {
        var $btn = $(this);
        var plant = $btn.attr('data-plant');
        var part = $btn.attr('data-part-number');

        if ($btn.attr('data-decision') === 'counted') {
            $.post(BASE_URL + 'wip/summary/missing-cutoff/decide', {
                plant: plant, part_number: part, decision: 'counted', reason: ''
            }, null, 'json').done(afterDecision).fail(decisionFailed);
            return;
        }

        $('#missingReasonAlert').addClass('d-none').text('');
        $('#missingReasonPlant').val(plant);
        $('#missingReasonPart').val(part);
        $('#missingReasonLine').text(plant.toUpperCase());
        $('#missingReasonPartLabel').text(part);
        $('#missingReasonText').val('');
        bootstrap.Modal.getOrCreateInstance('#modalMissingReason').show();
    });

    $('#formMissingReason').on('submit', function (e) {
        e.preventDefault();
        var reason = $.trim($('#missingReasonText').val());
        if (!reason) {
            $('#missingReasonAlert').removeClass('d-none').text('Alasan wajib diisi.');
            return;
        }

        var $btn = $('#btnSaveMissingReason').prop('disabled', true);
        $.post(BASE_URL + 'wip/summary/missing-cutoff/decide', {
            plant: $('#missingReasonPlant').val(),
            part_number: $('#missingReasonPart').val(),
            decision: 'excluded',
            reason: reason
        }, null, 'json')
            .done(function (resp) {
                if (resp.status !== 'success') {
                    $('#missingReasonAlert').removeClass('d-none').text(resp.message || 'Gagal menyimpan.');
                    return;
                }
                bootstrap.Modal.getOrCreateInstance('#modalMissingReason').hide();
                afterDecision(resp);
            })
            .fail(function (xhr) {
                $('#missingReasonAlert').removeClass('d-none')
                    .text((xhr.responseJSON && xhr.responseJSON.message) || 'Gagal menghubungi server.');
            })
            .always(function () {
                $btn.prop('disabled', false);
            });
    });

    $(document).on('click', '#tblMissingCutoff .btn-decide-clear', function () {
        var $btn = $(this);
        $.post(BASE_URL + 'wip/summary/missing-cutoff/decide/clear', {
            plant: $btn.attr('data-plant'),
            part_number: $btn.attr('data-part-number')
        }, null, 'json').done(afterDecision).fail(decisionFailed);
    });

    function load() {
        var seq = ++requestSeq;
        $('#missingAlert').addClass('d-none').text('');
        $('[data-card-parts]').html('<span class="spinner-border spinner-border-sm" role="status"></span>');
        table.processing(true);

        $.getJSON(CalcBasis.url(BASE_URL + 'wip/summary/missing-cutoff/data?scope=' + encodeURIComponent(currentScope)))
            .done(function (resp) {
                if (seq !== requestSeq) return; // a newer line/basis request superseded this one
                if (resp.status !== 'success') {
                    $('#missingAlert').removeClass('d-none').text(resp.message || 'Failed to load data.');
                    allRows = [];
                    paintCards(true);
                    table.clear().draw();
                    return;
                }
                allRows = resp.data;
                paintCards();
                table.clear().rows.add(allRows).draw();
            })
            .fail(function () {
                if (seq !== requestSeq) return;
                $('#missingAlert').removeClass('d-none').text('Failed to reach the server.');
                paintCards(true);
            })
            .always(function () {
                if (seq === requestSeq) table.processing(false);
            });
    }

    // ------------------------------------------------------------
    // Detail — every Model / Suffix of the part, and per unit shop how
    // many units match and how many of them the card counts, so the
    // Summary value can be traced back shop by shop.
    // ------------------------------------------------------------
    var $modalBody = $('#modalMissingDetailBody');
    var $btnOpenCalc = $('#btnMissingOpenCalc');

    function fieldRow(name, value) {
        return '<dt class="col-4 col-lg-3">' + esc(name) + '</dt><dd class="col-8 col-lg-9">' + value + '</dd>';
    }

    function dash(v) {
        return parseFloat(v) === 0 ? '<span class="text-secondary">—</span>' : esc(formatTotal(v));
    }

    // counted vs. all units in one shop: shown as "counted / all" when they differ.
    function unitCell(c) {
        if (parseFloat(c.all) === parseFloat(c.counted)) return dash(c.counted);
        return '<span class="text-danger fw-semibold">' + esc(formatTotal(c.counted)) + '</span>' +
            ' <span class="small text-secondary" title="Unit yang ada di shop ini tapi tidak dihitung">/ ' + esc(formatTotal(c.all)) + '</span>';
    }

    function calcUrl(resp) {
        return resp.is_list ? BASE_URL + 'wip/calc-' + resp.card : BASE_URL + 'wip/' + resp.source + '/calc';
    }

    function renderDetail(resp) {
        var own = resp.own_shop;
        var later = resp.units.filter(function (u) { return u !== own; }).map(label).join(' + ');

        var vinText;
        if (resp.vin_state === 'none') {
            vinText = '<span class="badge text-bg-danger">Belum ada cutoff VIN</span> <span class="text-secondary">— Net ' + esc(label(own)) + ' = 0</span>';
        } else if (resp.vin_state === 'stale') {
            vinText = '<span class="badge text-bg-warning text-dark">Stale</span> ' + esc(resp.cutoff_vin) +
                ' <span class="text-secondary">— tidak ada di data WIP ' + esc(label(own)) + ', semua unit dihitung</span>';
        } else {
            vinText = esc(resp.cutoff_vin);
        }

        var ownTotal = resp.unit_totals[own];
        var explain = resp.vin_state === 'none'
            ? 'Di <strong>' + esc(label(own)) + '</strong> (shop sendiri) ada <strong>' + esc(formatTotal(ownTotal.all)) + '</strong> qty unit, ' +
                'tapi belum dihitung karena belum ada cutoff VIN. Angka Summary <strong>' + esc(formatTotal(resp.grand_total)) + '</strong> ' +
                'seluruhnya berasal dari unit di <strong>' + esc(later || '-') + '</strong>, yang selalu dihitung semua karena sudah membawa part ini.'
            : 'Cutoff VIN <strong>' + esc(resp.cutoff_vin) + '</strong> tidak ditemukan di data WIP ' + esc(label(own)) + ' (mungkin data WIP sudah di-refresh), ' +
                'jadi semua unit di ' + esc(label(own)) + ' ikut dihitung. Upload ulang cutoff VIN yang masih ada di data WIP.';

        var head = '<tr><th>Model</th><th>Suffix</th><th class="text-end">Qty</th>' +
            resp.units.map(function (u) {
                return '<th class="text-end">' + esc(label(u)) +
                    (u === own ? ' <span class="badge text-bg-secondary fw-normal">shop sendiri</span>' : '') + '</th>';
            }).join('') +
            '<th class="text-end">Subtotal</th></tr>';

        var body = resp.suffixes.map(function (s) {
            var zero = parseFloat(s.subtotal) === 0;
            return '<tr' + (zero ? ' class="text-secondary"' : '') + '>' +
                '<td>' + esc(s.model) + '</td>' +
                '<td>' + esc(s.suffix) + '</td>' +
                '<td class="text-end">' + esc(s.qty) + '</td>' +
                resp.units.map(function (u) {
                    return '<td class="text-end">' + unitCell(s.units[u]) + '</td>';
                }).join('') +
                '<td class="text-end' + (zero ? '' : ' fw-semibold text-body') + '">' + dash(s.subtotal) + '</td>' +
                '</tr>';
        }).join('');

        var foot = '<tr class="fw-semibold border-top"><td colspan="3" class="text-end">Total (unit &times; Qty)</td>' +
            resp.units.map(function (u) {
                return '<td class="text-end">' + unitCell(resp.unit_totals[u]) + '</td>';
            }).join('') +
            '<td class="text-end text-success">' + esc(formatTotal(resp.grand_total)) + '</td></tr>';

        var d = DECISION[resp.decision];
        var statusText = d
            ? '<span class="badge ' + d.cls + '">' + esc(d.text) + '</span>'
            : '<span class="badge text-bg-secondary">Belum diputuskan</span>';

        // Alasan "tidak dihitung" dibaca di sini, bukan di tabel.
        var reasonBlock = '';
        if (resp.decision === 'excluded') {
            reasonBlock = '<div class="alert alert-dark py-2 px-3 small mb-3"><i class="bi bi-slash-circle me-1"></i>' +
                '<strong>Alasan tidak dihitung:</strong> ' + esc(resp.reason) +
                '<div class="text-secondary mt-1">Part ini dihitung <strong>0</strong> di WIP Calc &amp; WIP Summary untuk ' +
                esc(resp.source.toUpperCase()) + '. Angka di bawah adalah hitungan seandainya tetap dihitung.</div></div>';
        } else if (resp.reason) {
            reasonBlock = '<div class="small text-secondary mb-3">Catatan: ' + esc(resp.reason) + '</div>';
        }

        $modalBody.html(
            '<dl class="row mb-3 small">' +
                fieldRow('Line', esc(resp.source.toUpperCase())) +
                fieldRow('Card', esc(resp.card_label) + ' <span class="text-secondary">(unit ' + esc(resp.units.map(label).join(' + ')) + ')</span>') +
                fieldRow('Part Number', esc(resp.part_number)) +
                fieldRow('Material Description', esc(resp.material_description)) +
                fieldRow('Shop Code', esc(resp.shop_code)) +
                fieldRow('Status', statusText) +
                fieldRow('Cutoff VIN ' + label(own), vinText) +
            '</dl>' +
            reasonBlock +
            '<div class="alert alert-warning py-2 px-3 small mb-3"><i class="bi bi-lightbulb me-1"></i>' + explain + '</div>' +
            '<div class="text-secondary small mb-2">Angka per shop = jumlah unit yang Model + Suffix-nya cocok. ' +
                '<span class="text-danger fw-semibold">Merah</span> / abu-abu = dihitung / total unit yang ada, kalau berbeda.</div>' +
            '<div class="table-responsive">' +
                '<table class="table table-sm table-hover mb-0">' +
                    '<thead>' + head + '</thead>' +
                    '<tbody>' + body + '</tbody>' +
                    '<tfoot>' + foot + '</tfoot>' +
                '</table>' +
            '</div>'
        );
        $btnOpenCalc.attr('href', calcUrl(resp)).removeClass('d-none');
    }

    // Delegated from the document: with the responsive extension a collapsed
    // Detail button is rendered in a child row instead.
    $(document).on('click', '#tblMissingCutoff .btn-missing-detail', function () {
        var $btn = $(this);
        $btnOpenCalc.addClass('d-none');
        $modalBody.html('<div class="text-center text-secondary py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>');
        bootstrap.Modal.getOrCreateInstance('#modalMissingDetail').show();

        // attr(), not data(): a numeric-looking part number must stay a string.
        $.getJSON(CalcBasis.url(BASE_URL + 'wip/summary/missing-cutoff/detail'), {
            source: $btn.attr('data-source'),
            card: $btn.attr('data-card'),
            part_number: $btn.attr('data-part-number')
        })
            .done(function (resp) {
                if (!resp.ok) {
                    $modalBody.html('<div class="alert alert-danger mb-0">' + esc(resp.message || 'Failed to load the detail.') + '</div>');
                    return;
                }
                renderDetail(resp);
            })
            .fail(function () {
                $modalBody.html('<div class="alert alert-danger mb-0">Failed to reach the server.</div>');
            });
    });

    $(document).on('calcbasis:change', function () {
        updateExportHref();
        load();
    });

    updateExportHref();
    load();
})(jQuery);
