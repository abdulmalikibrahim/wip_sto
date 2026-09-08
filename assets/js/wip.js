(function ($) {
    'use strict';

    var table = $('#tblWip').DataTable($.extend({}, window.APP_DT_DEFAULTS, {
        data: [],
        columns: [
            { data: 'no' },
            { data: 'vin' },
            { data: 'sfx' },
            { data: 'modelcode' },
            { data: 'colorcode' },
            { data: 'colorname' },
            { data: 'wipname' },
            { data: 'scandate' },
            { data: 'shopcode' },
        ]
    }));

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
                            '<dt>Suffix</dt><dd>' + esc(r.sfx) + '</dd>' +
                            '<dt>Model</dt><dd>' + esc(r.modelcode) + '</dd>' +
                            '<dt>Color</dt><dd>' + esc(r.colorcode) + ' - ' + esc(r.colorname) + '</dd>' +
                            '<dt>Last Scan</dt><dd>' + esc(r.wipname) + '</dd>' +
                            '<dt>Scan Date</dt><dd>' + esc(r.scandate) + '</dd>' +
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
        $('#wipLoading').toggleClass('d-none', !isLoading);
        $('#btnRefresh, #btnDownload, #shopTabs .nav-link').prop('disabled', isLoading);
        $('#btnRefresh i').toggleClass('spin', isLoading);
    }

    function loadShop(shop) {
        currentShop = shop;
        $('#wipAlert').addClass('d-none').text('');
        setLoading(true);

        $.getJSON(BASE_URL + WIP_SOURCE + '/data/' + shop)
            .done(function (resp) {
                if (resp.status !== 'success') {
                    $('#wipAlert').removeClass('d-none').text(resp.message || 'Failed to load data.');
                    table.clear().draw();
                    renderCards([]);
                    return;
                }
                table.clear().rows.add(resp.data).draw();
                renderCards(resp.data);
                $('#wipLastUpdated').text('Updated ' + new Date().toLocaleTimeString('en-GB'));
            })
            .fail(function () {
                $('#wipAlert').removeClass('d-none').text('Failed to reach the server.');
                table.clear().draw();
                renderCards([]);
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

    $('#btnRefresh').on('click', function () {
        loadShop(currentShop);
    });

    $('#btnDownload').on('click', function () {
        window.location.href = BASE_URL + WIP_SOURCE + '/export/' + currentShop;
    });

    $('#btnViewTable').on('click', function () {
        $(this).addClass('active');
        $('#btnViewCard').removeClass('active');
        $('#tableView').removeClass('d-none');
        $('#cardView').addClass('d-none');
        table.columns.adjust().responsive.recalc();
    });

    $('#btnViewCard').on('click', function () {
        $(this).addClass('active');
        $('#btnViewTable').removeClass('active');
        $('#cardView').removeClass('d-none');
        $('#tableView').addClass('d-none');
    });

    loadShop(currentShop);
})(jQuery);
