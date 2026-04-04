/**
 * Entity & Verification management module.
 * Handles profile list/create, verification review, duplicate warnings.
 */
layui.use(['form', 'layer'], function () {
    var form = layui.form;
    var layer = layui.layer;

    // === Entity List ===
    window.loadEntities = function () {
        var loading = document.getElementById('entity-list-loading');
        var table = document.getElementById('entity-table');
        var tbody = document.getElementById('entity-table-body');
        var scopeErr = document.getElementById('entity-scope-error');

        if (!loading) return;
        loading.classList.remove('layui-hide');
        table.classList.add('layui-hide');
        scopeErr.classList.add('layui-hide');

        ApiClient.get('/entities').then(function (resp) {
            loading.classList.add('layui-hide');
            if (resp.ok) {
                table.classList.remove('layui-hide');
                var items = resp.data.items || [];
                if (items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6">No entities found</td></tr>';
                    return;
                }
                var html = '';
                for (var i = 0; i < items.length; i++) {
                    var e = items[i];
                    html += '<tr><td>' + e.id + '</td><td>' + esc(e.entity_type) + '</td><td>' +
                        esc(e.display_name) + '</td><td>' + esc(e.address || '-') + '</td><td>' +
                        esc(e.status) + '</td><td>' +
                        '<a href="javascript:;" class="layui-btn layui-btn-xs" onclick="viewEntity(' + e.id + ')">View</a>' +
                        '</td></tr>';
                }
                tbody.innerHTML = html;
            } else if (resp.status === 403) {
                scopeErr.classList.remove('layui-hide');
                document.getElementById('entity-scope-error-msg').textContent =
                    resp.data ? resp.data.message : 'Access denied: outside your geographic scope';
            }
        });
    };

    window.viewEntity = function (id) {
        ApiClient.get('/entities/' + id).then(function (resp) {
            if (!resp.ok) {
                layer.msg(resp.data ? resp.data.message : 'Failed to load entity', { icon: 2 });
                return;
            }
            var p = resp.data.profile;
            var flags = resp.data.duplicate_flags || [];
            var history = resp.data.merge_history || [];

            var content = '<table class="layui-table"><tbody>' +
                '<tr><td>ID</td><td>' + p.id + '</td></tr>' +
                '<tr><td>Type</td><td>' + esc(p.entity_type) + '</td></tr>' +
                '<tr><td>Name</td><td>' + esc(p.display_name) + '</td></tr>' +
                '<tr><td>Address</td><td>' + esc(p.address || '-') + '</td></tr>' +
                '<tr><td>ID Last 4</td><td>' + esc(p.id_last4 || '-') + '</td></tr>' +
                '<tr><td>License Last 4</td><td>' + esc(p.license_last4 || '-') + '</td></tr>' +
                '<tr><td>Status</td><td>' + esc(p.status) + '</td></tr>' +
                '<tr><td>Extra Fields</td><td><pre>' + JSON.stringify(p.extra_fields || {}, null, 2) + '</pre></td></tr>' +
                '</tbody></table>';

            if (flags.length > 0) {
                content += '<div class="auth-message info" style="margin-top:10px;">Duplicate flags: ' +
                    flags.length + ' potential match(es) found. Consider merging.</div>';
            }
            if (history.length > 0) {
                content += '<div style="margin-top:10px;"><b>Merge History:</b> ' + history.length + ' merge(s)</div>';
            }

            layer.open({
                type: 1, title: 'Entity #' + p.id,
                area: ['600px', '500px'],
                content: '<div style="padding:15px;">' + content + '</div>'
            });
        });
    };

    // Refresh button
    var btnRefresh = document.getElementById('btn-refresh-entities');
    if (btnRefresh) {
        btnRefresh.addEventListener('click', function () { loadEntities(); });
    }

    // === Entity Create ===
    form.on('submit(createEntity)', function (data) {
        var btn = data.elem;
        btn.disabled = true;
        var dupWarn = document.getElementById('entity-create-dup-warning');
        var msg = document.getElementById('entity-create-msg');
        dupWarn.classList.add('layui-hide');
        msg.classList.add('layui-hide');

        var body = {
            entity_type: data.field.entity_type,
            display_name: data.field.display_name,
            address: data.field.address || '',
            id_last4: data.field.id_last4 || null,
            license_last4: data.field.license_last4 || null
        };

        ApiClient.post('/entities', body).then(function (resp) {
            btn.disabled = false;
            if (resp.ok) {
                msg.className = 'auth-message success';
                msg.textContent = 'Entity created (ID: ' + resp.data.id + ')';
                msg.classList.remove('layui-hide');
                if (resp.data.duplicate_flag) {
                    dupWarn.classList.remove('layui-hide');
                }
            } else {
                msg.className = 'auth-message error';
                msg.textContent = resp.data ? resp.data.message : 'Create failed';
                msg.classList.remove('layui-hide');
            }
        });
        return false;
    });

    // === Verifications (admin) ===
    window.loadVerifications = function () {
        var tbody = document.getElementById('verif-table-body');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="5">Loading...</td></tr>';

        ApiClient.get('/verifications').then(function (resp) {
            if (!resp.ok) {
                tbody.innerHTML = '<tr><td colspan="5">Failed to load</td></tr>';
                return;
            }
            var items = resp.data.items || [];
            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5">No verification requests</td></tr>';
                return;
            }
            var html = '';
            for (var i = 0; i < items.length; i++) {
                var v = items[i];
                var statusClass = v.status === 'approved' ? 'color:#009688' :
                    v.status === 'rejected' ? 'color:#FF5722' : 'color:#1E9FFF';
                var actions = '';
                if (v.status === 'pending') {
                    actions = '<a class="layui-btn layui-btn-xs" onclick="approveVerif(' + v.id + ')">Approve</a> ' +
                        '<a class="layui-btn layui-btn-xs layui-btn-danger" onclick="rejectVerif(' + v.id + ')">Reject</a>';
                } else {
                    actions = '<span style="' + statusClass + '">' + esc(v.status) + '</span>';
                }
                html += '<tr><td>' + v.id + '</td><td>' + v.user_id + '</td><td style="' + statusClass +
                    '">' + esc(v.status) + '</td><td>' + esc(v.submitted_at || '-') + '</td><td>' + actions + '</td></tr>';
            }
            tbody.innerHTML = html;
        });
    };

    window.approveVerif = function (id) {
        layer.confirm('Approve this verification?', function (idx) {
            layer.close(idx);
            ApiClient.post('/admin/verifications/' + id + '/approve', { note: '' }).then(function (resp) {
                if (resp.ok) {
                    layer.msg('Approved', { icon: 1 });
                    loadVerifications();
                } else {
                    layer.msg(resp.data ? resp.data.message : 'Failed', { icon: 2 });
                }
            });
        });
    };

    window.rejectVerif = function (id) {
        layer.prompt({ title: 'Enter rejection reason (required)', formType: 2 }, function (reason, idx) {
            layer.close(idx);
            if (!reason || !reason.trim()) {
                layer.msg('Reason is required', { icon: 0 });
                return;
            }
            ApiClient.post('/admin/verifications/' + id + '/reject', { reason: reason }).then(function (resp) {
                if (resp.ok) {
                    layer.msg('Rejected', { icon: 1 });
                    loadVerifications();
                } else {
                    layer.msg(resp.data ? resp.data.message : 'Failed', { icon: 2 });
                }
            });
        });
    };

    var btnRefreshVerif = document.getElementById('btn-refresh-verifications');
    if (btnRefreshVerif) {
        btnRefreshVerif.addEventListener('click', function () { loadVerifications(); });
    }

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }
});
