/**
 * Admin module - Jobs, Config management.
 */
layui.use(['layer'], function () {
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

    function esc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s || '')); return d.innerHTML; }
});
