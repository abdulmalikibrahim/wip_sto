(function ($) {
    'use strict';

    var table = $('#tblAkun').DataTable($.extend({}, window.APP_DT_DEFAULTS, {
        processing: true,
        serverSide: true,
        ajax: { url: BASE_URL + 'akun/data', type: 'POST' },
        order: [[0, 'asc']],
        columns: [
            { data: 'no', orderable: false, searchable: false },
            { data: 'username' },
            { data: 'full_name' },
            { data: 'role', render: function (d) { return d === 'admin' ? '<span class="badge text-bg-danger">Admin</span>' : '<span class="badge text-bg-secondary">User</span>'; } },
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
