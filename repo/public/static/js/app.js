/**
 * Rural Lease Portal - Main Application
 * Initializes Layui components, handles navigation, auth state,
 * role-aware UI, health check, and MFA enrollment.
 */
layui.use(['element', 'layer', 'util'], function () {
    var element = layui.element;
    var layer = layui.layer;

    // === Auth Gate ===
    if (!ApiClient.isAuthenticated()) {
        window.location.href = '/static/login.html';
        return;
    }

    var currentUser = ApiClient.getUser();

    // Populate user info in header
    var navUsername = document.getElementById('nav-username');
    var navRole = document.getElementById('nav-role-display');
    if (navUsername && currentUser) {
        navUsername.textContent = currentUser.username;
    }
    if (navRole && currentUser) {
        navRole.textContent = 'Role: ' + currentUser.role;
    }

    // Show admin nav items for system_admin
    if (currentUser && currentUser.role === 'system_admin') {
        var adminNav = document.getElementById('nav-admin');
        if (adminNav) adminNav.classList.remove('layui-hide');
    }

    // === Logout ===
    var btnLogout = document.getElementById('btn-logout');
    if (btnLogout) {
        btnLogout.addEventListener('click', function () {
            ApiClient.post('/auth/logout').then(function () {
                ApiClient.clearAuth();
                window.location.href = '/static/login.html';
            });
        });
    }

    // === Page Navigation ===
    var navLinks = document.querySelectorAll('[data-page]');
    for (var i = 0; i < navLinks.length; i++) {
        navLinks[i].addEventListener('click', function () {
            var target = this.getAttribute('data-page');
            showPage(target);
            if (target === 'health') refreshHealthDetail();
            if (target === 'mfa') refreshMfaStatus();
            if (target === 'entities' && typeof loadEntities === 'function') loadEntities();
            if (target === 'verifications' && typeof loadVerifications === 'function') loadVerifications();
            if (target === 'contracts' && typeof loadContracts === 'function') loadContracts();
            if (target === 'invoices' && typeof loadInvoices === 'function') loadInvoices();
            if (target === 'messaging' && typeof loadMessaging === 'function') loadMessaging();
            if (target === 'risk-rules' && typeof loadRiskRules === 'function') loadRiskRules();
            if (target === 'audit-logs' && typeof loadAuditLogs === 'function') loadAuditLogs();
            if (target === 'admin-jobs' && typeof loadAdminJobs === 'function') loadAdminJobs();
            if (target === 'admin-config' && typeof loadAdminConfig === 'function') loadAdminConfig();
        });
    }

    function showPage(pageId) {
        var pages = document.querySelectorAll('.page-view');
        for (var j = 0; j < pages.length; j++) {
            pages[j].classList.remove('active');
        }
        var el = document.getElementById('page-' + pageId);
        if (el) el.classList.add('active');
    }

    // === Health Check on Startup ===
    var statusIcon = document.getElementById('status-icon');
    var statusText = document.getElementById('status-text');
    var healthLoading = document.getElementById('health-loading');
    var healthSuccess = document.getElementById('health-success');
    var healthError = document.getElementById('health-error');
    var healthDetail = document.getElementById('health-detail');
    var healthErrorDetail = document.getElementById('health-error-detail');

    function updateHealthUI(result) {
        healthLoading.classList.add('layui-hide');
        if (result.ok && result.status === 'ok') {
            healthSuccess.classList.remove('layui-hide');
            healthError.classList.add('layui-hide');
            healthDetail.textContent = 'Trace ID: ' + (result.traceId || 'N/A');
            statusIcon.className = 'layui-icon layui-icon-ok-circle status-ok';
            statusText.textContent = 'Online';
        } else {
            healthError.classList.remove('layui-hide');
            healthSuccess.classList.add('layui-hide');
            healthErrorDetail.textContent = result.status === 'degraded'
                ? 'Database connection issue. Trace ID: ' + (result.traceId || 'N/A')
                : 'Cannot reach the API server.';
            statusIcon.className = 'layui-icon layui-icon-close-fill status-err';
            statusText.textContent = 'Offline';
        }
    }

    function performHealthCheck() {
        healthLoading.classList.remove('layui-hide');
        healthSuccess.classList.add('layui-hide');
        healthError.classList.add('layui-hide');
        statusIcon.className = 'layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop';
        statusText.textContent = 'Checking...';
        ApiClient.healthCheck().then(function (result) {
            updateHealthUI(result);
        });
    }

    performHealthCheck();

    // === User Info Panel ===
    function loadUserInfo() {
        ApiClient.get('/auth/me').then(function (resp) {
            var tbody = document.getElementById('user-info-body');
            if (!tbody) return;
            if (resp.ok && resp.data) {
                var u = resp.data;
                tbody.innerHTML =
                    '<tr><td>Username</td><td>' + escapeHtml(u.username) + '</td></tr>' +
                    '<tr><td>Role</td><td>' + escapeHtml(u.role) + '</td></tr>' +
                    '<tr><td>Scope</td><td>' + escapeHtml(u.geo_scope_level) + ' (ID: ' + u.geo_scope_id + ')</td></tr>' +
                    '<tr><td>Status</td><td>' + escapeHtml(u.status) + '</td></tr>' +
                    '<tr><td>MFA</td><td>' + (u.mfa_enabled ? 'Enabled' : 'Disabled') + '</td></tr>';
            } else if (resp.status === 401) {
                ApiClient.clearAuth();
                window.location.href = '/static/login.html';
            } else {
                tbody.innerHTML = '<tr><td colspan="2">Failed to load user info</td></tr>';
            }
        });
    }
    loadUserInfo();

    // === Health Detail Page ===
    var btnRefresh = document.getElementById('btn-refresh-health');
    if (btnRefresh) {
        btnRefresh.addEventListener('click', refreshHealthDetail);
    }

    function refreshHealthDetail() {
        var tbody = document.getElementById('health-table-body');
        tbody.innerHTML = '<tr><td colspan="2">Loading...</td></tr>';
        ApiClient.get('/health').then(function (resp) {
            var rows = '';
            rows += '<tr><td>Status</td><td>' + escapeHtml(resp.data ? resp.data.status : 'unknown') + '</td></tr>';
            rows += '<tr><td>HTTP Code</td><td>' + resp.status + '</td></tr>';
            rows += '<tr><td>Trace ID</td><td>' + escapeHtml(resp.traceId || 'N/A') + '</td></tr>';
            rows += '<tr><td>Timestamp</td><td>' + new Date().toISOString() + '</td></tr>';
            tbody.innerHTML = rows;
        });
    }

    // === MFA Enrollment (Admin Only) ===
    var btnMfaEnroll = document.getElementById('btn-mfa-enroll');
    var btnMfaVerify = document.getElementById('btn-mfa-verify');

    function refreshMfaStatus() {
        var notEnrolled = document.getElementById('mfa-not-enrolled');
        var enrollStep = document.getElementById('mfa-enroll-step');
        var enabled = document.getElementById('mfa-enabled');
        if (!notEnrolled) return;

        ApiClient.get('/auth/me').then(function (resp) {
            if (resp.ok && resp.data) {
                if (resp.data.mfa_enabled) {
                    notEnrolled.classList.add('layui-hide');
                    enrollStep.classList.add('layui-hide');
                    enabled.classList.remove('layui-hide');
                } else {
                    notEnrolled.classList.remove('layui-hide');
                    enrollStep.classList.add('layui-hide');
                    enabled.classList.add('layui-hide');
                }
            }
        });
    }

    if (btnMfaEnroll) {
        btnMfaEnroll.addEventListener('click', function () {
            btnMfaEnroll.disabled = true;
            btnMfaEnroll.textContent = 'Enrolling...';

            ApiClient.post('/auth/mfa/enroll').then(function (resp) {
                btnMfaEnroll.disabled = false;
                btnMfaEnroll.textContent = 'Enable MFA';

                if (resp.ok) {
                    document.getElementById('mfa-not-enrolled').classList.add('layui-hide');
                    document.getElementById('mfa-enroll-step').classList.remove('layui-hide');
                    document.getElementById('mfa-secret-display').textContent = resp.data.qr_payload;
                } else {
                    layer.msg(resp.data ? resp.data.message : 'Enrollment failed', { icon: 2 });
                }
            });
        });
    }

    if (btnMfaVerify) {
        btnMfaVerify.addEventListener('click', function () {
            var code = document.getElementById('mfa-verify-code').value;
            if (!code || code.length !== 6) {
                layer.msg('Enter a 6-digit code', { icon: 0 });
                return;
            }

            btnMfaVerify.disabled = true;
            ApiClient.post('/auth/mfa/verify', { totp_code: code }).then(function (resp) {
                btnMfaVerify.disabled = false;
                if (resp.ok) {
                    layer.msg('MFA enabled successfully!', { icon: 1 });
                    refreshMfaStatus();
                    // Update stored user
                    var u = ApiClient.getUser();
                    if (u) { u.mfa_enabled = true; localStorage.setItem('user', JSON.stringify(u)); }
                } else {
                    layer.msg(resp.data ? resp.data.message : 'Verification failed', { icon: 2 });
                }
            });
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }
});
