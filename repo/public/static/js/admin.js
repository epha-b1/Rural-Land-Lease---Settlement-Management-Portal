/**
 * Admin module - Jobs, Config, Delegations management.
 */
layui.use(['form', 'layer'], function () {
    var form = layui.form;
    var layer = layui.layer;

    window.loadAdminJobs = function () {
        var tbody = document.getElementById('jobs-tbody');
        if (!tbody) return;
        ApiClient.get('/admin/jobs').then(function (r) {
            if (!r.ok) { tbody.innerHTML = '<tr><td colspan="3">Access denied</td></tr>'; return; }
            var jobs = r.data.jobs || [];
            var h = '';
            for (var i = 0; i < jobs.length; i++) {
                h += '<tr><td>' + esc(jobs[i].name) + '</td><td>' + esc(jobs[i].schedule) + '</td><td>' + esc(jobs[i].description) + '</td></tr>';
            }
            tbody.innerHTML = h;
        });
    };

    var btnRun = document.getElementById('btn-run-jobs');
    if (btnRun) btnRun.addEventListener('click', function () {
        btnRun.disabled = true;
        ApiClient.post('/admin/jobs/run', {}).then(function (r) {
            btnRun.disabled = false;
            var el = document.getElementById('jobs-result');
            if (r.ok) {
                el.className = 'auth-message success'; el.classList.remove('layui-hide');
                el.textContent = 'Jobs completed: ' + JSON.stringify(r.data.results);
            } else {
                el.className = 'auth-message error'; el.classList.remove('layui-hide');
                el.textContent = r.data ? r.data.message : 'Failed';
            }
        });
    });

    window.loadAdminConfig = function () {
        var tbody = document.getElementById('config-tbody');
        if (!tbody) return;
        ApiClient.get('/admin/config').then(function (r) {
            if (!r.ok) { tbody.innerHTML = '<tr><td colspan="2">Access denied</td></tr>'; return; }
            var items = r.data.items || [];
            var h = '';
            for (var i = 0; i < items.length; i++) {
                h += '<tr><td>' + esc(items[i].config_key) + '</td><td>' + esc(items[i].config_value) + '</td></tr>';
            }
            tbody.innerHTML = h || '<tr><td colspan="2">No config</td></tr>';
        });
    };

    // === Delegations ===

    window.loadDelegations = function () {
        var tbody = document.getElementById('delegations-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7">Loading...</td></tr>';
        ApiClient.get('/delegations').then(function (r) {
            if (!r.ok) { tbody.innerHTML = '<tr><td colspan="7">Access denied</td></tr>'; return; }
            var items = r.data.items || [];
            if (!items.length) { tbody.innerHTML = '<tr><td colspan="7">No delegations</td></tr>'; return; }
            var h = '';
            for (var i = 0; i < items.length; i++) {
                var d = items[i];
                var actions = '';
                if (d.status === 'pending_approval') {
                    actions = '<a class="layui-btn layui-btn-xs" onclick="approveDelegation(' + d.id + ', true)">Approve</a> ' +
                        '<a class="layui-btn layui-btn-xs layui-btn-danger" onclick="approveDelegation(' + d.id + ', false)">Reject</a>';
                } else {
                    actions = '<span>' + esc(d.status) + '</span>';
                }
                h += '<tr><td>' + d.id + '</td><td>' + d.grantor_id + '</td><td>' + d.grantee_id +
                    '</td><td>' + esc(d.scope_level) + ' #' + d.scope_id +
                    '</td><td>' + esc(d.expires_at || '-') +
                    '</td><td>' + esc(d.status) + '</td><td>' + actions + '</td></tr>';
            }
            tbody.innerHTML = h;
        });
    };

    window.approveDelegation = function (id, approve) {
        var action = approve ? 'Approve' : 'Reject';
        layer.confirm(action + ' delegation #' + id + '?', function (idx) {
            layer.close(idx);
            ApiClient.post('/delegations/' + id + '/approve', { approve: approve }).then(function (r) {
                if (r.ok) {
                    layer.msg(action + 'd', { icon: 1 });
                    loadDelegations();
                } else {
                    layer.msg(r.data ? r.data.message : 'Failed', { icon: 2 });
                }
            });
        });
    };

    var btnRefreshDel = document.getElementById('btn-refresh-delegations');
    if (btnRefreshDel) btnRefreshDel.addEventListener('click', function () { loadDelegations(); });

    var btnNewDel = document.getElementById('btn-new-delegation');
    if (btnNewDel) btnNewDel.addEventListener('click', function () {
        var panel = document.getElementById('delegation-create-panel');
        if (panel) panel.classList.toggle('layui-hide');
    });

    form.on('submit(createDelegation)', function (data) {
        var btn = data.elem; btn.disabled = true;
        var msg = document.getElementById('delegation-create-msg');
        msg.classList.add('layui-hide');
        ApiClient.post('/delegations', {
            grantee_id: parseInt(data.field.grantee_id, 10),
            scope_level: data.field.scope_level,
            scope_id: parseInt(data.field.scope_id, 10),
            expires_at: data.field.expires_at ? new Date(data.field.expires_at).toISOString().slice(0, 19).replace('T', ' ') : ''
        }).then(function (r) {
            btn.disabled = false;
            if (r.ok) {
                msg.className = 'auth-message success'; msg.classList.remove('layui-hide');
                msg.textContent = 'Delegation #' + r.data.delegation_id + ' created (pending approval)';
                loadDelegations();
            } else {
                msg.className = 'auth-message error'; msg.classList.remove('layui-hide');
                msg.textContent = r.data ? r.data.message : 'Failed';
            }
        });
        return false;
    });

    function esc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s || '')); return d.innerHTML; }
});
