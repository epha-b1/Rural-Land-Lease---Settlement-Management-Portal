/**
 * Messaging module — conversations, messages (text/voice/image),
 * recall, report, risk warnings, risk rules admin, audit log view.
 *
 * Remediation coverage:
 *   I-11: voice/image send + per-message Report action
 *   I-12: pre-send risk check via /messages/preflight-risk before POST
 */
layui.use(['form', 'layer'], function () {
    var form = layui.form;
    var layer = layui.layer;
    var currentConvId = null;

    // === Conversations list ===
    window.loadMessaging = function () {
        var el = document.getElementById('conv-list');
        if (!el) return;
        el.innerHTML = 'Loading...';
        ApiClient.get('/conversations').then(function (r) {
            if (!r.ok) { el.innerHTML = 'Error loading'; return; }
            var items = r.data.items || [];
            if (!items.length) { el.innerHTML = 'No conversations. Create one to start messaging.'; return; }
            var h = '';
            for (var i = 0; i < items.length; i++) {
                h += '<a class="layui-btn layui-btn-sm" onclick="openConv(' + items[i].id + ')">Conv #' + items[i].id + '</a> ';
            }
            el.innerHTML = h;
        });
    };

    window.openConv = function (id) {
        currentConvId = id;
        document.getElementById('msg-panel').classList.remove('layui-hide');
        loadMessages(id);
    };

    function loadMessages(convId) {
        var el = document.getElementById('msg-list');
        el.innerHTML = 'Loading...';
        ApiClient.get('/conversations/' + convId + '/messages').then(function (r) {
            if (!r.ok) { el.innerHTML = 'Error'; return; }
            var items = (r.data.items || []).reverse();
            if (!items.length) { el.innerHTML = '<em>No messages yet</em>'; return; }
            var h = '';
            for (var i = 0; i < items.length; i++) {
                var m = items[i];
                var cls = m.recalled_at ? 'color:#999;font-style:italic' : '';
                h += '<div style="padding:4px 0;' + cls + '" data-msg-id="' + m.id + '">';
                h += '<b>#' + m.id + '</b> [' + esc(m.message_type) + ']: ';
                h += esc(m.body || '');
                if (m.attachment_id) {
                    h += ' <span style="color:#1E9FFF">[att#' + m.attachment_id + ']</span>';
                }
                if (m.risk_result) {
                    h += ' <span style="color:orange">[' + esc(m.risk_result) + ']</span>';
                }
                if (m.read_at) {
                    h += ' <small style="color:#009688">✓ read</small>';
                }
                h += ' <small style="color:#999">' + esc(m.created_at || '') + '</small>';

                // Action row (recall + report) — hidden for recalled rows
                if (!m.recalled_at) {
                    h += ' <a class="msg-action" onclick="recallMsg(' + m.id + ')" '
                       + 'style="color:#FF5722;cursor:pointer;font-size:12px;margin-left:6px;">recall</a>';
                    // Issue I-11: per-message Report action
                    h += ' <a class="msg-report" onclick="reportMsg(' + m.id + ')" '
                       + 'style="color:#b33;cursor:pointer;font-size:12px;margin-left:6px;" '
                       + 'title="Report this message for harassment / fraud">report</a>';
                }
                h += '</div>';
            }
            el.innerHTML = h;
        });
    }

    // === New conversation ===
    var btnNewConv = document.getElementById('btn-new-conv');
    if (btnNewConv) btnNewConv.addEventListener('click', function () {
        ApiClient.post('/conversations', {}).then(function (r) {
            if (r.ok) { layer.msg('Created #' + r.data.id, { icon: 1 }); loadMessaging(); }
            else layer.msg('Failed', { icon: 2 });
        });
    });

    // === Type selector toggles attachment row ===
    var typeRadios = document.querySelectorAll('input[name="msg-type"]');
    for (var ti = 0; ti < typeRadios.length; ti++) {
        typeRadios[ti].addEventListener('change', function () {
            var t = currentMsgType();
            var attRow = document.getElementById('msg-attachment-row');
            if (!attRow) return;
            if (t === 'image' || t === 'voice') attRow.classList.remove('layui-hide');
            else attRow.classList.add('layui-hide');
        });
    }

    function currentMsgType() {
        var r = document.querySelector('input[name="msg-type"]:checked');
        return r ? r.value : 'text';
    }

    // === Pre-send risk check (Issue I-12) ===
    var btnPreflight = document.getElementById('btn-preflight-msg');
    if (btnPreflight) btnPreflight.addEventListener('click', function () {
        var content = document.getElementById('msg-input').value || '';
        if (!content.trim()) { layer.msg('Nothing to check', { icon: 0 }); return; }
        runPreflight(content).then(function (r) {
            renderPreflight(r);
            if (r.action === 'warn' || r.action === 'flag') {
                layer.msg('Policy warning — see banner', { icon: 0 });
            } else if (r.action === 'block') {
                layer.msg('BLOCKED by policy — edit your message', { icon: 2 });
            } else {
                layer.msg('OK — no policy issues', { icon: 1 });
            }
        });
    });

    function runPreflight(content) {
        return ApiClient.post('/messages/preflight-risk', { content: content }).then(function (r) {
            if (!r.ok) return { action: 'allow', warning: null };
            return { action: r.data.action || 'allow', warning: r.data.warning || null };
        });
    }

    function renderPreflight(r) {
        var el = document.getElementById('msg-preflight-warning');
        if (!el) return;
        if (r.action === 'allow') { el.classList.add('layui-hide'); return; }
        el.classList.remove('layui-hide');
        if (r.action === 'block') {
            el.className = 'auth-message error';
            el.textContent = 'BLOCKED — content matches a blocked policy rule. ' + (r.warning || '');
        } else if (r.action === 'warn') {
            el.className = 'auth-message info';
            el.textContent = 'WARNING — ' + (r.warning || 'sensitive terms detected. Review before sending.');
        } else { // flag
            el.className = 'auth-message info';
            el.textContent = 'NOTICE — content will be flagged for review after send.';
        }
    }

    // === Send (routes text / image / voice) ===
    var btnSend = document.getElementById('btn-send-msg');
    if (btnSend) btnSend.addEventListener('click', function () {
        var input = document.getElementById('msg-input');
        var content = input.value || '';
        if (!currentConvId) { layer.msg('Open a conversation first', { icon: 0 }); return; }
        var type = currentMsgType();

        // Issue I-12: always run the pre-send risk check so the user sees
        // the server's evaluation BEFORE we POST /messages. For text and
        // image/voice with a caption, we check the content field.
        runPreflight(content).then(function (pre) {
            renderPreflight(pre);
            if (pre.action === 'block') {
                // Server will reject too — don't even POST.
                return;
            }
            submitMessage(type, content);
        });
    });

    function submitMessage(type, content) {
        var warn = document.getElementById('msg-risk-warning');
        warn.classList.add('layui-hide');

        if (type === 'text') {
            ApiClient.post('/messages', {
                conversation_id: currentConvId, type: 'text', content: content,
            }).then(handleSendResponse);
            return;
        }

        // image or voice: read the attachment file as base64 and include it
        var fileInput = document.getElementById('msg-file');
        var file = fileInput && fileInput.files && fileInput.files[0];
        if (!file) {
            layer.msg('Please choose an image/voice file', { icon: 0 });
            return;
        }
        if (file.size > 10 * 1024 * 1024) {
            layer.msg('File exceeds 10 MB limit', { icon: 2 });
            return;
        }
        var reader = new FileReader();
        reader.onload = function () {
            var dataUrl = reader.result || '';
            var comma = String(dataUrl).indexOf(',');
            var b64 = comma >= 0 ? String(dataUrl).substring(comma + 1) : '';
            ApiClient.post('/messages', {
                conversation_id: currentConvId,
                type: type,
                content: content,
                attachment: {
                    file_name: file.name,
                    mime_type: file.type,
                    data_base64: b64,
                },
            }).then(handleSendResponse);
        };
        reader.onerror = function () { layer.msg('Failed to read file', { icon: 2 }); };
        reader.readAsDataURL(file);
    }

    function handleSendResponse(r) {
        var input = document.getElementById('msg-input');
        var warn = document.getElementById('msg-risk-warning');
        if (r.ok) {
            input.value = '';
            var fileInput = document.getElementById('msg-file');
            if (fileInput) fileInput.value = '';
            if (r.data.warning) {
                warn.textContent = r.data.warning;
                warn.classList.remove('layui-hide');
            }
            loadMessages(currentConvId);
        } else {
            layer.msg(r.data ? r.data.message : 'Send failed', { icon: 2 });
        }
    }

    // === Recall ===
    window.recallMsg = function (id) {
        ApiClient.patch('/messages/' + id + '/recall', {}).then(function (r) {
            if (r.ok) { layer.msg('Recalled', { icon: 1 }); if (currentConvId) loadMessages(currentConvId); }
            else layer.msg(r.data ? r.data.message : 'Recall failed', { icon: 2 });
        });
    };

    // === Report (Issue I-11) ===
    window.reportMsg = function (id) {
        layer.prompt({
            title: 'Report message #' + id,
            formType: 2,
            placeholder: 'Describe why you are reporting (required) — e.g. harassment, fraud',
        }, function (reason, idx) {
            reason = (reason || '').trim();
            if (!reason) {
                layer.msg('Reason is required', { icon: 0 });
                return;
            }
            // Category picker kept simple — could be a dropdown in a richer UI.
            var category = /fraud|scam/i.test(reason) ? 'fraud' : 'harassment';
            ApiClient.post('/messages/' + id + '/report', {
                category: category,
                reason: reason,
            }).then(function (r) {
                if (r.ok) {
                    layer.close(idx);
                    layer.msg('Report submitted — thank you', { icon: 1 });
                } else if (r.status === 403) {
                    layer.msg('Outside your scope — cannot report this message', { icon: 2 });
                } else {
                    layer.msg(r.data ? r.data.message : 'Report failed', { icon: 2 });
                }
            });
        });
    };

    // === Risk rules admin (CRUD) ===
    window.loadRiskRules = function () {
        var tbody = document.getElementById('risk-rules-tbody');
        if (!tbody) return;
        ApiClient.get('/admin/risk-keywords').then(function (r) {
            if (!r.ok) { tbody.innerHTML = '<tr><td colspan="6">Error</td></tr>'; return; }
            var items = r.data.items || [];
            var h = '';
            for (var i = 0; i < items.length; i++) {
                var rr = items[i];
                h += '<tr><td>' + rr.id + '</td><td>' + esc(rr.pattern) + '</td><td>' + esc(rr.action) +
                    '</td><td>' + esc(rr.category) + '</td><td>' + (rr.active ? 'Yes' : 'No') +
                    '</td><td>' +
                    '<a class="layui-btn layui-btn-xs" onclick="editRiskRule(' + rr.id + ',\'' + esc(rr.pattern).replace(/'/g, "\\'") + '\',\'' + esc(rr.action) + '\',\'' + esc(rr.category) + '\',' + (rr.is_regex ? 1 : 0) + ',' + (rr.active ? 1 : 0) + ')">Edit</a> ' +
                    '<a class="layui-btn layui-btn-xs layui-btn-danger" onclick="deleteRiskRule(' + rr.id + ')">Disable</a>' +
                    '</td></tr>';
            }
            tbody.innerHTML = h || '<tr><td colspan="6">No rules</td></tr>';
        });
    };

    var btnAddRule = document.getElementById('btn-add-risk-rule');
    if (btnAddRule) btnAddRule.addEventListener('click', function () {
        document.getElementById('risk-rule-edit-id').value = '';
        document.getElementById('risk-rule-form-title').textContent = 'Add Risk Rule';
        var form = document.getElementById('risk-rule-form');
        if (form) form.reset();
        document.getElementById('risk-rule-form-panel').classList.remove('layui-hide');
    });

    var btnRefreshRules = document.getElementById('btn-refresh-risk-rules');
    if (btnRefreshRules) btnRefreshRules.addEventListener('click', function () { loadRiskRules(); });

    window.editRiskRule = function (id, pattern, action, category, isRegex, active) {
        document.getElementById('risk-rule-edit-id').value = id;
        document.getElementById('risk-rule-form-title').textContent = 'Edit Risk Rule #' + id;
        var form = document.getElementById('risk-rule-form');
        if (form) {
            form.pattern.value = pattern;
            form.action.value = action;
            form.category.value = category;
            form.is_regex.value = isRegex;
            form.active.value = active;
        }
        document.getElementById('risk-rule-form-panel').classList.remove('layui-hide');
    };

    window.deleteRiskRule = function (id) {
        layer.confirm('Disable risk rule #' + id + '?', function (idx) {
            layer.close(idx);
            ApiClient.del('/admin/risk-keywords/' + id).then(function (r) {
                if (r.ok) {
                    layer.msg('Disabled', { icon: 1 });
                    loadRiskRules();
                } else {
                    layer.msg(r.data ? r.data.message : 'Failed', { icon: 2 });
                }
            });
        });
    };

    form.on('submit(saveRiskRule)', function (data) {
        var btn = data.elem; btn.disabled = true;
        var msg = document.getElementById('risk-rule-form-msg');
        msg.classList.add('layui-hide');
        var editId = document.getElementById('risk-rule-edit-id').value;
        var body = {
            pattern: data.field.pattern,
            is_regex: parseInt(data.field.is_regex, 10),
            action: data.field.action,
            category: data.field.category || '',
            active: parseInt(data.field.active, 10)
        };
        var req = editId
            ? ApiClient.patch('/admin/risk-keywords/' + editId, body)
            : ApiClient.post('/admin/risk-keywords', body);
        req.then(function (r) {
            btn.disabled = false;
            if (r.ok) {
                msg.className = 'auth-message success'; msg.classList.remove('layui-hide');
                msg.textContent = editId ? 'Rule updated' : 'Rule created (ID: ' + r.data.id + ')';
                loadRiskRules();
                document.getElementById('risk-rule-form-panel').classList.add('layui-hide');
            } else {
                msg.className = 'auth-message error'; msg.classList.remove('layui-hide');
                msg.textContent = r.data ? r.data.message : 'Failed';
            }
        });
        return false;
    });

    // === Audit log viewer ===
    window.loadAuditLogs = function () {
        var tbody = document.getElementById('audit-tbody');
        if (!tbody) return;
        ApiClient.get('/audit-logs').then(function (r) {
            if (!r.ok) { tbody.innerHTML = '<tr><td colspan="6">Access denied or error</td></tr>'; return; }
            var items = r.data.items || [];
            var h = '';
            for (var i = 0; i < items.length; i++) {
                var a = items[i];
                h += '<tr><td>' + a.id + '</td><td>' + esc(a.event_type) + '</td><td>' + (a.actor_id || '-') +
                    '</td><td>' + esc((a.resource_type || '') + (a.resource_id ? '#' + a.resource_id : '')) +
                    '</td><td>' + esc(a.created_at || '') + '</td><td style="font-size:11px">' + esc(a.trace_id || '-') + '</td></tr>';
            }
            tbody.innerHTML = h || '<tr><td colspan="6">No audit entries</td></tr>';
        });
    };
    var btnAudit = document.getElementById('btn-refresh-audit');
    if (btnAudit) btnAudit.addEventListener('click', function () { loadAuditLogs(); });

    function esc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s || '')); return d.innerHTML; }
});
