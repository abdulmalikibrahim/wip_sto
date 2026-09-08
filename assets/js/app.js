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
})(jQuery);
