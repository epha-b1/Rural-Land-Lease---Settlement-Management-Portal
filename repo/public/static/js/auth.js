/**
 * Auth module - handles login, registration, MFA flows, and CAPTCHA.
 */
layui.use(['form', 'layer'], function () {
    var form = layui.form;
    var layer = layui.layer;

    var msgEl = document.getElementById('auth-message');
    var currentCaptchaId = null;

    function showMessage(text, type) {
        if (!msgEl) return;
        msgEl.className = 'auth-message ' + (type || 'error');
        msgEl.textContent = text;
        msgEl.classList.remove('layui-hide');
    }

    function hideMessage() {
        if (msgEl) msgEl.classList.add('layui-hide');
    }

    // ═══════════════════════════════════════════════════════════
    //  CAPTCHA
    // ═══════════════════════════════════════════════════════════

    /**
     * Fetch a fresh CAPTCHA challenge from the backend and render it.
     * Called on page load, after every submit attempt, and via refresh button.
     */
    function loadCaptcha() {
        var qEl = document.getElementById('captcha-question');
        var aEl = document.getElementById('captcha-answer');
        if (!qEl || !aEl) return; // not on a page with captcha

        qEl.value = 'Loading...';
        currentCaptchaId = null;

        ApiClient.get('/auth/captcha').then(function (resp) {
            if (resp.ok && resp.data) {
                currentCaptchaId = resp.data.challenge_id;
                qEl.value = resp.data.question;
                aEl.value = '';
            } else {
                qEl.value = 'Error — click refresh';
            }
        });
    }

    // Refresh button wiring
    var refreshBtn = document.getElementById('captcha-refresh');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            hideMessage();
            loadCaptcha();
        });
    }

    // Initial load when the page appears
    loadCaptcha();

    // ═══════════════════════════════════════════════════════════
    //  Login Form
    // ═══════════════════════════════════════════════════════════

    form.on('submit(login)', function (data) {
        hideMessage();
        if (!currentCaptchaId) {
            showMessage('CAPTCHA not loaded — click refresh', 'error');
            return false;
        }

        var btn = data.elem;
        btn.disabled = true;
        btn.textContent = 'Signing in...';

        var body = {
            username: data.field.username,
            password: data.field.password,
            captcha_id: currentCaptchaId,
            captcha_answer: data.field.captcha_answer
        };
        if (data.field.totp_code) {
            body.totp_code = data.field.totp_code;
        }

        ApiClient.post('/auth/login', body).then(function (resp) {
            btn.disabled = false;
            btn.textContent = 'Sign In';

            if (resp.ok) {
                if (resp.data.mfa_required) {
                    // Show MFA field and fetch a fresh CAPTCHA for the next submit
                    document.getElementById('mfa-field').classList.remove('layui-hide');
                    showMessage('MFA code required. Enter the 6-digit code from your authenticator app.', 'info');
                    loadCaptcha();
                    return;
                }
                // Store token and redirect
                localStorage.setItem('access_token', resp.data.access_token);
                localStorage.setItem('user', JSON.stringify(resp.data.user));
                window.location.href = '/static/index.html';
            } else {
                showMessage(resp.data ? resp.data.message : 'Login failed', 'error');
                // CAPTCHA is single-use — always refresh after a failed attempt
                loadCaptcha();
            }
        });

        return false;
    });

    // ═══════════════════════════════════════════════════════════
    //  Register Form
    // ═══════════════════════════════════════════════════════════

    form.on('submit(register)', function (data) {
        hideMessage();
        if (!currentCaptchaId) {
            showMessage('CAPTCHA not loaded — click refresh', 'error');
            return false;
        }

        var btn = data.elem;
        btn.disabled = true;
        btn.textContent = 'Creating account...';

        ApiClient.post('/auth/register', {
            username: data.field.username,
            password: data.field.password,
            role: data.field.role,
            geo_scope_level: data.field.geo_scope_level,
            geo_scope_id: parseInt(data.field.geo_scope_id, 10),
            captcha_id: currentCaptchaId,
            captcha_answer: data.field.captcha_answer
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
                // CAPTCHA is single-use — always refresh after a failed attempt
                loadCaptcha();
            }
        });

        return false;
    });
});
