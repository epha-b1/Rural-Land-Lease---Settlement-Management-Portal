/**
 * Finance module - Contracts and Invoices management.
 */
layui.use(['form', 'layer'], function () {
    var form = layui.form;
    var layer = layui.layer;

    // === Contracts List ===
    window.loadContracts = function () {
        var tbody = document.getElementById('contracts-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="6">Loading...</td></tr>';
        ApiClient.get('/contracts').then(function (r) {
            if (!r.ok) { tbody.innerHTML = '<tr><td colspan="6">Error loading</td></tr>'; return; }
            var items = r.data.items || [];
            if (!items.length) { tbody.innerHTML = '<tr><td colspan="6">No contracts</td></tr>'; return; }
            var h = '';
            for (var i = 0; i < items.length; i++) {
                var c = items[i];
                h += '<tr><td>' + c.id + '</td><td>' + c.profile_id + '</td><td>' +
                    esc(c.start_date) + ' to ' + esc(c.end_date) + '</td><td>$' +
                    (c.rent_cents / 100).toFixed(2) + '</td><td>' + esc(c.frequency) +
                    '</td><td>' + esc(c.status) + '</td></tr>';
            }
            tbody.innerHTML = h;
        });
    };
    var br = document.getElementById('btn-refresh-contracts');
    if (br) br.addEventListener('click', function () { loadContracts(); });

    // === Create Contract ===
    form.on('submit(createContract)', function (data) {
        var btn = data.elem; btn.disabled = true;
        var msg = document.getElementById('contract-create-msg');
        msg.classList.add('layui-hide');
        ApiClient.post('/contracts', {
            profile_id: parseInt(data.field.profile_id, 10),
            start_date: data.field.start_date,
            end_date: data.field.end_date,
            rent_cents: parseInt(data.field.rent_cents, 10),
            deposit_cents: parseInt(data.field.deposit_cents || '0', 10),
            maintenance_cents: parseInt(data.field.maintenance_cents || '0', 10),
            frequency: data.field.frequency
        }).then(function (r) {
            btn.disabled = false;
            if (r.ok) {
                msg.className = 'auth-message success'; msg.classList.remove('layui-hide');
                msg.textContent = 'Contract #' + r.data.contract_id + ' created with ' + r.data.invoices_created + ' invoices';
            } else {
                msg.className = 'auth-message error'; msg.classList.remove('layui-hide');
                msg.textContent = r.data ? r.data.message : 'Failed';
            }
        });
        return false;
    });

    // === Invoices List ===
    window.loadInvoices = function () {
        var tbody = document.getElementById('invoices-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="6">Loading...</td></tr>';
        ApiClient.get('/invoices').then(function (r) {
            if (!r.ok) { tbody.innerHTML = '<tr><td colspan="6">Error loading</td></tr>'; return; }
            var items = r.data.items || [];
            if (!items.length) { tbody.innerHTML = '<tr><td colspan="6">No invoices</td></tr>'; return; }
            var h = '';
            for (var i = 0; i < items.length; i++) {
                var inv = items[i];
                var sc = inv.status === 'paid' ? 'color:#009688' : inv.status === 'overdue' ? 'color:#FF5722' : '';
                h += '<tr><td>' + inv.id + '</td><td>' + inv.contract_id + '</td><td>' +
                    esc(inv.due_date) + '</td><td>$' + (inv.amount_cents / 100).toFixed(2) +
                    '</td><td>$' + (inv.late_fee_cents / 100).toFixed(2) +
                    '</td><td style="' + sc + '">' + esc(inv.status) + '</td></tr>';
            }
            tbody.innerHTML = h;
        });
    };
    var bi = document.getElementById('btn-refresh-invoices');
    if (bi) bi.addEventListener('click', function () { loadInvoices(); });

    // === Payment Form ===
    form.on('submit(postPayment)', function (data) {
        var btn = data.elem; btn.disabled = true;
        var msg = document.getElementById('payment-msg');
        msg.classList.add('layui-hide');
        var iKey = 'pay-' + Date.now() + '-' + Math.random().toString(36).substr(2, 8);
        ApiClient.post('/payments', {
            invoice_id: parseInt(data.field.invoice_id, 10),
            amount_cents: parseInt(data.field.amount_cents, 10),
            paid_at: new Date().toISOString(),
            method: data.field.method || 'cash'
        }, { 'Idempotency-Key': iKey }).then(function (r) {
            btn.disabled = false;
            if (r.ok) {
                msg.className = 'auth-message success'; msg.classList.remove('layui-hide');
                msg.textContent = 'Payment #' + r.data.payment_id + ' recorded. Balance: $' + (r.data.balance_cents / 100).toFixed(2);
            } else {
                msg.className = 'auth-message error'; msg.classList.remove('layui-hide');
                msg.textContent = r.data ? r.data.message : 'Payment failed';
            }
        });
        return false;
    });

    // === Export buttons ===
    var btnLedger = document.getElementById('btn-export-ledger');
    if (btnLedger) btnLedger.addEventListener('click', function () {
        window.open('/exports/ledger?from=2020-01-01&to=' + new Date().toISOString().slice(0, 10), '_blank');
    });
    var btnRecon = document.getElementById('btn-export-recon');
    if (btnRecon) btnRecon.addEventListener('click', function () {
        window.open('/exports/reconciliation?from=2020-01-01&to=' + new Date().toISOString().slice(0, 10), '_blank');
    });

    function esc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s || '')); return d.innerHTML; }
});
