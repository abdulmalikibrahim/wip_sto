(function ($) {
    'use strict';

    var dtOptions = $.extend({}, window.APP_DT_DEFAULTS, {
        data: [],
        processing: true,
        columns: [
            { data: 'no' },
            { data: 'seq', className: 'text-end' },
            { data: 'vin' },
            { data: 'sfx' },
            { data: 'katashiki' },
            { data: 'modelcode' },
            { data: 'shopcode' },
        ]
    });
    dtOptions.language = $.extend({}, dtOptions.language, {
        processing: '<div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>Loading WIP data...'
    });

    var table = $('#tblWip').DataTable(dtOptions);

    var currentShop = $('#shopTabs .nav-link.active').data('shop');

    function renderCards(rows) {
        var $c = $('#cardView').empty();
        if (!rows.length) {
            $c.append('<div class="col-12 text-secondary small">No data available.</div>');
            return;
        }
        rows.forEach(function (r) {
            var html =
                '<div class="col-sm-6 col-lg-4 col-xl-3">' +
                    '<div class="wip-card">' +
                        '<div class="d-flex justify-content-between align-items-start">' +
                            '<div class="vin">' + esc(r.vin) + '</div>' +
                            '<span class="badge text-bg-primary">' + esc(r.shopcode) + '</span>' +
                        '</div>' +
                        '<dl>' +
                            '<dt>Sequence</dt><dd>' + esc(r.seq) + '</dd>' +
                            '<dt>Suffix</dt><dd>' + esc(r.sfx) + '</dd>' +
                            '<dt>Katashiki</dt><dd>' + esc(r.katashiki) + '</dd>' +
                            '<dt>Model</dt><dd>' + esc(r.modelcode) + '</dd>' +
                        '</dl>' +
                    '</div>' +
                '</div>';
            $c.append(html);
        });
    }

    function esc(v) {
        return $('<div>').text(v == null ? '' : v).html();
    }

    function setLoading(isLoading) {
        table.processing(isLoading);
        $('#wipLoading').toggleClass('d-none', !isLoading);
        $('#btnGetWip, #btnDownload, #shopTabs .nav-link').prop('disabled', isLoading);
        $('#btnGetWip i').toggleClass('spin', isLoading);
    }

    function showUpdated(updatedAt) {
        if (!updatedAt) {
            $('#wipLastUpdated').text('');
            return;
        }
        // updatedAt comes as "YYYY-MM-DD HH:MM:SS" from the server.
        var d = new Date(updatedAt.replace(' ', 'T'));
        var label = isNaN(d.getTime()) ? updatedAt : d.toLocaleString('en-GB');
        $('#wipLastUpdated').text('Updated ' + label);
    }

    function applyResult(resp, clearOnError) {
        if (resp.status !== 'success') {
            $('#wipAlert').removeClass('d-none').text(resp.message || 'Failed to load data.');
            if (clearOnError) {
                table.clear().draw();
                renderCards([]);
                $('#wipEmptyHint').addClass('d-none');
                showUpdated(null);
            }
            return;
        }
        table.clear().rows.add(resp.data).draw();
        renderCards(resp.data);
        showUpdated(resp.updated_at);
        $('#wipEmptyHint').toggleClass('d-none', resp.data.length > 0);
    }

    /**
     * Load the currently cached data for a shop straight from the database —
     * this never touches the live WIP server.
     */
    function loadShop(shop) {
        currentShop = shop;
        $('#wipAlert').addClass('d-none').text('');
        setLoading(true);

        $.getJSON(BASE_URL + WIP_SOURCE + '/data/' + shop)
            .done(function (resp) {
                applyResult(resp, true);
            })
            .fail(function () {
                $('#wipAlert').removeClass('d-none').text('Failed to reach the server.');
                table.clear().draw();
                renderCards([]);
                showUpdated(null);
            })
            .always(function () {
                setLoading(false);
            });
    }

    /**
     * "Get Data WIP": pull fresh data from the live WIP server for the current
     * shop, replacing its cached rows in the database. On failure the cached
     * data already shown is left as-is.
     */
    function getWip(shop) {
        $('#wipAlert').addClass('d-none').text('');
        setLoading(true);

        $.post(BASE_URL + WIP_SOURCE + '/getwip/' + shop)
            .done(function (resp) {
                applyResult(resp, false);
                if (resp.status === 'success') {
                    toast('success', 'WIP data refreshed from server.');
                } else {
                    toast('error', resp.message || 'Failed to reach the WIP server.');
                }
            })
            .fail(function () {
                $('#wipAlert').removeClass('d-none').text('Failed to reach the server.');
                toast('error', 'Failed to reach the server.');
            })
            .always(function () {
                setLoading(false);
            });
    }

    $('#shopTabs .nav-link').on('click', function () {
        $('#shopTabs .nav-link').removeClass('active');
        $(this).addClass('active');
        loadShop($(this).data('shop'));
    });

    $('#btnGetWip').on('click', function () {
        getWip(currentShop);
    });

    // Per-shop "Clear" button next to each tab — deletes that shop's cached
    // WIP rows only (other shops and the live WIP server are untouched).
    // Confirmed first since it can remove a lot of rows at once; reloads the
    // current tab afterwards so an emptied active shop shows immediately.
    $('#shopTabs').on('click', '.btn-clear-shop', function (e) {
        e.stopPropagation(); // don't also switch tabs
        var $btn = $(this);
        var shop = $btn.data('shop');
        var shopLabel = $btn.data('shop-label') || shop;

        Swal.fire({
            title: 'Clear ' + shopLabel + ' WIP data?',
            html: 'This deletes every cached WIP row for <strong>' + esc(shopLabel) + '</strong>. ' +
                'The live WIP server is not affected — pull or upload fresh data any time to bring it back.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Clear',
            confirmButtonColor: '#dc3545',
            background: '#21262f',
            color: '#e4e6eb'
        }).then(function (res) {
            if (!res.isConfirmed) return;

            $btn.prop('disabled', true);
            var originalHtml = $btn.html();
            $btn.html('<span class="spinner-border spinner-border-sm" role="status"></span>');

            $.post(BASE_URL + WIP_SOURCE + '/clear/' + shop, null, null, 'json')
                .done(function (resp) {
                    if (resp.status !== 'success') {
                        toast('error', resp.message || 'Failed to clear WIP data.');
                        return;
                    }
                    toast('success', resp.message || 'WIP data cleared.');
                    if (shop === currentShop) loadShop(shop);
                })
                .fail(function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Failed to reach the server.';
                    toast('error', msg);
                })
                .always(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        });
    });

    $('#btnDownload').on('click', function () {
        window.location.href = BASE_URL + WIP_SOURCE + '/export/' + currentShop;
    });

    // Upload dropzone UX
    var $wipFile = $('#wip_file');
    var $wipDz = $('#dropzoneWip');
    $wipFile.on('change', function () {
        var name = this.files.length ? this.files[0].name : null;
        $('#dropzoneWipLabel').text(name || 'Click to choose an .xlsx file or drag it here');
    });
    $wipDz.on('dragover', function (e) {
        e.preventDefault();
        $wipDz.addClass('dragover');
    }).on('dragleave', function () {
        $wipDz.removeClass('dragover');
    }).on('drop', function (e) {
        e.preventDefault();
        $wipDz.removeClass('dragover');
        var files = e.originalEvent.dataTransfer.files;
        if (files.length) {
            $wipFile[0].files = files;
            $wipFile.trigger('change');
        }
    });

    $('#btnViewTable').on('click', function () {
        $(this).addClass('active');
        $('#btnViewCard').removeClass('active');
        $('#tableView').removeClass('d-none');
        $('#cardViewWrap').addClass('d-none');
        table.columns.adjust().responsive.recalc();
    });

    $('#btnViewCard').on('click', function () {
        $(this).addClass('active');
        $('#btnViewTable').removeClass('active');
        $('#cardViewWrap').removeClass('d-none');
        $('#tableView').addClass('d-none');
    });

    loadShop(currentShop);
})(jQuery);
