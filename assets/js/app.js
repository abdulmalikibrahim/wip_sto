// ============================================================
// Shared UI behaviour: sidebar toggle, live clock, DataTables defaults
// ============================================================
(function ($) {
    'use strict';

    // Sidebar toggle for small screens
    $('#btnToggleSidebar').on('click', function () {
        $('#appSidebar').toggleClass('show');
        $('#sidebarBackdrop').toggleClass('show');
    });
    $('#sidebarBackdrop').on('click', function () {
        $('#appSidebar').removeClass('show');
        $(this).removeClass('show');
    });

    // Live clock in the topbar
    function tickClock() {
        var el = document.getElementById('liveClock');
        if (!el) return;
        var now = new Date();
        el.textContent = now.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) +
            '  ' + now.toLocaleTimeString('en-GB');
    }
    tickClock();
    setInterval(tickClock, 1000);

    // ------------------------------------------------------------
    // WIP Calc basis — whether the calculation reads part usage from
    // the Master BOM or the Part List. Remembered per browser so the
    // choice carries across the Calc, Detail and Combined pages, and
    // sent along as ?basis= on every calc request, export and template.
    // ------------------------------------------------------------
    window.CalcBasis = {
        KEY: 'wipCalcBasis',

        get: function () {
            try {
                return localStorage.getItem(this.KEY) === 'part_list' ? 'part_list' : 'bom';
            } catch (e) {
                return 'bom'; // private windows / blocked storage — fall back to the default
            }
        },

        set: function (value) {
            try {
                localStorage.setItem(this.KEY, value === 'part_list' ? 'part_list' : 'bom');
            } catch (e) { /* not fatal — the choice just won't stick */ }
        },

        label: function () {
            return this.get() === 'part_list' ? 'Part List' : 'Master BOM';
        },

        /** Append the current basis to a URL, keeping any query it already has. */
        url: function (url) {
            return url + (url.indexOf('?') === -1 ? '?' : '&') + 'basis=' + this.get();
        }
    };

    // Wire any basis switch on the page. Pages react by listening for
    // 'calcbasis:change' on the document and reloading their own data.
    $(function () {
        var $options = $('[data-basis-option]');
        if (!$options.length) return;

        function paint() {
            var current = window.CalcBasis.get();
            $options.each(function () {
                $(this).toggleClass('active', $(this).data('basis-option') === current);
            });
        }

        paint();

        $options.on('click', function () {
            var value = $(this).data('basis-option');
            if (value === window.CalcBasis.get()) return;

            window.CalcBasis.set(value);
            paint();
            $(document).trigger('calcbasis:change', [value]);
        });
    });

    // Sensible DataTables defaults used across the app
    window.APP_DT_DEFAULTS = {
        lengthMenu: [[25, 50, 100], [25, 50, 100]],
        pageLength: 25,
        responsive: true,
        searching: true,
        language: {
            search: '',
            searchPlaceholder: 'Search...',
            lengthMenu: 'Show _MENU_ entries',
            zeroRecords: 'No matching records found',
            info: 'Showing _START_ to _END_ of _TOTAL_ entries',
            infoEmpty: 'No entries available',
            infoFiltered: '(filtered from _MAX_ total entries)',
            paginate: { previous: '‹', next: '›' }
        }
    };

    window.toast = function (icon, title) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: icon,
            title: title,
            showConfirmButton: false,
            timer: 2800,
            timerProgressBar: true,
            background: '#21262f',
            color: '#e4e6eb'
        });
    };

    /**
     * Turn a plain "download .xlsx" button/link into an AJAX-backed download
     * that shows a spinner for as long as the file is being generated and
     * transferred. A plain <a href> download gives no feedback at all since
     * the page never navigates away while the browser fetches it — this
     * fetches the file itself so we know exactly when it starts and ends.
     */
    window.downloadExcel = function ($btn) {
        if ($btn.hasClass('is-downloading')) return; // ignore repeat clicks mid-download

        var url = $btn.attr('href');
        var originalHtml = $btn.html();

        $btn.addClass('is-downloading disabled').attr('aria-disabled', 'true');
        $btn.html('<span class="spinner-border spinner-border-sm me-1" role="status"></span>Downloading...');

        fetch(url, { credentials: 'same-origin' })
            .then(function (resp) {
                if (!resp.ok) throw new Error('Download failed');
                var disposition = resp.headers.get('Content-Disposition') || '';
                var match = disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
                var filename = match ? decodeURIComponent(match[1]) : 'download.xlsx';
                return resp.blob().then(function (blob) { return { blob: blob, filename: filename }; });
            })
            .then(function (result) {
                var blobUrl = window.URL.createObjectURL(result.blob);
                var a = document.createElement('a');
                a.href = blobUrl;
                a.download = result.filename;
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(function () { window.URL.revokeObjectURL(blobUrl); }, 1000);
            })
            .catch(function () {
                toast('error', 'Download failed. Please try again.');
            })
            .finally(function () {
                $btn.removeClass('is-downloading disabled').removeAttr('aria-disabled');
                $btn.html(originalHtml);
            });
    };
})(jQuery);
