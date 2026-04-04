/**
 * Messaging module - conversations, messages, recall, risk warnings, risk rules admin.
 */
layui.use(['layer'], function () {
    var layer = layui.layer;
    var currentConvId = null;

    // Load conversations
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
                h += '<div style="padding:4px 0;' + cls + '"><b>#' + m.id + '</b> [' + esc(m.message_type) + ']: ' +
                    esc(m.body || '') + (m.risk_result ? ' <span style="color:orange">[' + m.risk_result + ']</span>' : '') +
                    ' <small style="color:#999">' + esc(m.created_at || '') + '</small>';
                if (!m.recalled_at) {
                    h += ' <a onclick="recallMsg(' + m.id + ')" style="color:#FF5722;cursor:pointer;font-size:12px">recall</a>';
                }
                h += '</div>';
            }
            el.innerHTML = h;
        });
    }

    // New conversation
    var btnNewConv = document.getElementById('btn-new-conv');
    if (btnNewConv) btnNewConv.addEventListener('click', function () {
        ApiClient.post('/conversations', {}).then(function (r) {
            if (r.ok) { layer.msg('Created #' + r.data.id, { icon: 1 }); loadMessaging(); }
            else layer.msg('Failed', { icon: 2 });
        });
    });

    // Send message
    var btnSend = document.getElementById('btn-send-msg');
    if (btnSend) btnSend.addEventListener('click', function () {
        var input = document.getElementById('msg-input');
        var warn = document.getElementById('msg-risk-warning');
        if (!currentConvId || !input.value.trim()) return;
        warn.classList.add('layui-hide');
        ApiClient.post('/messages', { conversation_id: currentConvId, type: 'text', content: input.value }).then(function (r) {
            if (r.ok) {
                input.value = '';
                if (r.data.warning) { warn.textContent = r.data.warning; warn.classList.remove('layui-hide'); }
                loadMessages(currentConvId);
            } else {
                layer.msg(r.data ? r.data.message : 'Send failed', { icon: 2 });
            }
        });
    });

    window.recallMsg = function (id) {
        ApiClient.patch('/messages/' + id + '/recall', {}).then(function (r) {
            if (r.ok) { layer.msg('Recalled', { icon: 1 }); if (currentConvId) loadMessages(currentConvId); }
            else layer.msg(r.data ? r.data.message : 'Recall failed', { icon: 2 });
        });
    };

    // Risk rules admin
    window.loadRiskRules = function () {
        var tbody = document.getElementById('risk-rules-tbody');
        if (!tbody) return;
        ApiClient.get('/admin/risk-keywords').then(function (r) {
            if (!r.ok) { tbody.innerHTML = '<tr><td colspan="5">Error</td></tr>'; return; }
            var items = r.data.items || [];
            var h = '';
            for (var i = 0; i < items.length; i++) {
                var rr = items[i];
                h += '<tr><td>' + rr.id + '</td><td>' + esc(rr.pattern) + '</td><td>' + esc(rr.action) +
                    '</td><td>' + esc(rr.category) + '</td><td>' + (rr.active ? 'Yes' : 'No') + '</td></tr>';
            }
            tbody.innerHTML = h || '<tr><td colspan="5">No rules</td></tr>';
        });
    };

    // Audit log viewer
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
