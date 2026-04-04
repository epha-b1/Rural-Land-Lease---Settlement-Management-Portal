/**
 * Auth module - handles login, registration, and MFA flows.
 */
layui.use(['form', 'layer'], function () {
    var form = layui.form;
    var layer = layui.layer;

    var msgEl = document.getElementById('auth-message');

    function showMessage(text, type) {
        if (!msgEl) return;
        msgEl.className = 'auth-message ' + (type || 'error');
        msgEl.textContent = text;
        msgEl.classList.remove('layui-hide');
    }

    function hideMessage() {
        if (msgEl) msgEl.classList.add('layui-hide');
    }

    // === Login Form ===
    form.on('submit(login)', function (data) {
        hideMessage();
        var btn = data.elem;
        btn.disabled = true;
        btn.textContent = 'Signing in...';

        var body = {
            username: data.field.username,
            password: data.field.password
        };
        if (data.field.totp_code) {
            body.totp_code = data.field.totp_code;
        }

        ApiClient.post('/auth/login', body).then(function (resp) {
            btn.disabled = false;
            btn.textContent = 'Sign In';

            if (resp.ok) {
                if (resp.data.mfa_required) {
                    // Show MFA field
                    document.getElementById('mfa-field').classList.remove('layui-hide');
                    showMessage('MFA code required. Enter the 6-digit code from your authenticator app.', 'info');
                    return;
                }
                // Store token and redirect
                localStorage.setItem('access_token', resp.data.access_token);
                localStorage.setItem('user', JSON.stringify(resp.data.user));
                window.location.href = '/static/index.html';
            } else {
                showMessage(resp.data ? resp.data.message : 'Login failed', 'error');
            }
        });

        return false;
    });

    // === Register Form ===
    form.on('submit(register)', function (data) {
        hideMessage();
        var btn = data.elem;
        btn.disabled = true;
        btn.textContent = 'Creating account...';

        ApiClient.post('/auth/register', {
            username: data.field.username,
            password: data.field.password,
            role: data.field.role,
            geo_scope_level: data.field.geo_scope_level,
            geo_scope_id: parseInt(data.field.geo_scope_id, 10)
        }).then(function (resp) {
            btn.disabled = false;
            btn.textContent = 'Create Account';

            if (resp.ok) {
                showMessage('Account created successfully! Redirecting to login...', 'success');
                setTimeout(function () {
                    window.location.href = '/static/login.html';
                }, 1500);
            } else {
                showMessage(resp.data ? resp.data.message : 'Registration failed', 'error');
            }
        });

        return false;
    });
});
