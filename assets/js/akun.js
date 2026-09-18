(function ($) {
    'use strict';

    var ROLE_LABELS = {
        admin:  '<span class="badge text-bg-danger">Admin</span>',
        user:   '<span class="badge text-bg-primary">User</span>',
        viewer: '<span class="badge text-bg-secondary">View Only</span>'
    };

    var ROLE_HINTS = {
        admin:  'Full access to every page and action.',
        user:   'Sets, uploads and clears cutoff VINs for its own shops only. Everything else is read-only.',
        viewer: 'Read-only: can browse and download, but cannot change anything.'
    };

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    // Show the scope block only for role "user", and within it only the
    // shop checkboxes that belong to the chosen plant.
    function syncScope() {
        var isUser = $('#akun_role').val() === 'user';
        var plant = $('#akun_plant').val();

        $('#akun_role_hint').text(ROLE_HINTS[$('#akun_role').val()] || '');
        $('#akun_scope').toggleClass('d-none', !isUser);
        $('.akun-shop-group').each(function () {
            $(this).toggleClass('d-none', $(this).data('plant') !== plant);
        });
    }

    function setScope(plant, shopCodes) {
        var codes = (shopCodes || '').split(',').map(function (c) { return c.trim().toUpperCase(); });
        $('#akun_plant').val(plant || '');
        $('.akun-shop-code').each(function () {
            var group = $(this).closest('.akun-shop-group').data('plant');
            $(this).prop('checked', group === plant && codes.indexOf(this.value.toUpperCase()) !== -1);
        });
        syncScope();
    }

    $('#akun_role, #akun_plant').on('change', syncScope);

    var table = $('#tblAkun').DataTable($.extend({}, window.APP_DT_DEFAULTS, {
        processing: true,
        serverSide: true,
        ajax: { url: BASE_URL + 'akun/data', type: 'POST' },
        order: [[0, 'asc']],
        columns: [
            { data: 'no', orderable: false, searchable: false },
            { data: 'username' },
            { data: 'full_name' },
            { data: 'role', render: function (d) { return ROLE_LABELS[d] || esc(d); } },
            {
                data: 'plant', orderable: true,
                render: function (d, t, row) {
                    if (row.role !== 'user') {
                        return '<span class="text-secondary">All</span>';
                    }
                    return '<strong>' + esc((d || '').toUpperCase()) + '</strong> ' +
                        '<span class="text-secondary small">' + esc((row.shop_codes || '').split(',').join(', ')) + '</span>';
                }
            },
            { data: 'is_active', render: function (d) { return d == 1 ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>'; } },
            { data: 'last_login', render: function (d) { return d || '<span class="text-secondary">Never</span>'; } },
            {
                data: null, orderable: false, searchable: false,
                render: function (row) {
                    return '<button class="btn btn-sm btn-primary btn-edit-akun me-1" data-row="' + encodeURIComponent(JSON.stringify(row)) + '"><i class="bi bi-pencil"></i></button>' +
                        '<button class="btn btn-sm btn-danger btn-delete-akun" data-id="' + row.id + '"><i class="bi bi-trash"></i></button>';
                }
            }
        ]
    }));

    var modal = new bootstrap.Modal('#modalAkun');

    $('#btnAddAkun').on('click', function () {
        $('#formAkun')[0].reset();
        $('#akun_id').val('');
        $('#akun_username').prop('disabled', false);
        $('#akun_password').prop('required', true);
        $('#akun_password_hint').text('');
        $('#akun_is_active').prop('checked', true);
        setScope('', '');
        $('#modalAkunTitle').text('Add Account');
        modal.show();
    });

    $('#tblAkun').on('click', '.btn-edit-akun', function () {
        var row = JSON.parse(decodeURIComponent($(this).data('row')));
        $('#formAkun')[0].reset();
        $('#akun_id').val(row.id);
        $('#akun_username').val(row.username).prop('disabled', true);
        $('#akun_full_name').val(row.full_name);
        $('#akun_role').val(row.role);
        setScope(row.plant, row.shop_codes);
        $('#akun_is_active').prop('checked', row.is_active == 1);
        $('#akun_password').prop('required', false);
        $('#akun_password_hint').text('(leave blank to keep current password)');
        $('#modalAkunTitle').text('Edit Account');
        modal.show();
    });

    $('#tblAkun').on('click', '.btn-delete-akun', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Delete this account?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#dc3545',
            background: '#21262f',
            color: '#e4e6eb'
        }).then(function (res) {
            if (!res.isConfirmed) return;
            $.post(BASE_URL + 'akun/delete/' + id, function (resp) {
                if (resp.status === 'success') {
                    toast('success', resp.message);
                    table.ajax.reload(null, false);
                } else {
                    toast('error', resp.message);
                }
            }, 'json');
        });
    });

    $('#formAkun').on('submit', function (e) {
        e.preventDefault();
        var id = $('#akun_id').val();
        var url = id ? BASE_URL + 'akun/update/' + id : BASE_URL + 'akun/create';

        var data = $(this).serializeArray();
        if (!$('#akun_is_active').is(':checked')) {
            data.push({ name: 'is_active', value: '' });
        }

        // Only the chosen plant's ticked shops — never a leftover tick from
        // the other (hidden) plant group. The server re-checks this anyway.
        if ($('#akun_role').val() === 'user') {
            var plant = $('#akun_plant').val();
            $('.akun-shop-group[data-plant="' + plant + '"] .akun-shop-code:checked').each(function () {
                data.push({ name: 'shop_codes[]', value: this.value });
            });
        }

        $.post(url, $.param(data), function (resp) {
            if (resp.status === 'success') {
                toast('success', resp.message);
                modal.hide();
                table.ajax.reload(null, false);
            } else {
                toast('error', resp.message);
            }
        }, 'json').fail(function () {
            toast('error', 'Request failed.');
        });
    });
})(jQuery);
