/**
 * Entity & Verification management module.
 * Handles profile list/create, verification review (admin) + user submission,
 * duplicate detection, guided merge UX, and dynamic extra fields.
 */
layui.use(['form', 'layer'], function () {
    var form = layui.form;
    var layer = layui.layer;

    // ═══════════════════════════════════════════════════════════
    //  Entity List
    // ═══════════════════════════════════════════════════════════

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
                content += '<div class="auth-message info" style="margin-top:10px;">' +
                    '<b>Duplicate flags:</b> ' + flags.length + ' potential match(es) found.' +
                    ' <a href="javascript:;" onclick="openMergeWorkspace(' + p.id + ')" class="layui-btn layui-btn-xs layui-btn-warm">Start Guided Merge</a>' +
                    '</div>';
            }
            if (history.length > 0) {
                content += '<div style="margin-top:10px;"><b>Merge History (' + history.length + '):</b><ul>';
                for (var i = 0; i < history.length; i++) {
                    var h = history[i];
                    content += '<li>Merge #' + h.id + ': source #' + h.source_profile_id +
                        ' into target #' + h.target_profile_id +
                        ' by user #' + h.merged_by +
                        ' at ' + esc(h.created_at) + '</li>';
                }
                content += '</ul></div>';
            }

            layer.open({
                type: 1, title: 'Entity #' + p.id,
                area: ['650px', '550px'],
                content: '<div style="padding:15px;">' + content + '</div>'
            });
        });
    };

    // Refresh button
    var btnRefresh = document.getElementById('btn-refresh-entities');
    if (btnRefresh) {
        btnRefresh.addEventListener('click', function () { loadEntities(); });
    }

    // ═══════════════════════════════════════════════════════════
    //  Entity Create (with dynamic extra fields — Fix C)
    // ═══════════════════════════════════════════════════════════

    // Cache for extra field definitions per entity type
    var extraFieldDefs = {};
    var extraFieldContainer = null;

    /**
     * Fetch extra field definitions for a given entity type and render
     * dynamic form controls (text, number, date, select).
     */
    function loadExtraFields(entityType) {
        if (!extraFieldContainer) {
            // Insert container before the submit button
            var submitItem = document.querySelector('#entity-create-form .layui-form-item:last-child');
            if (submitItem) {
                extraFieldContainer = document.createElement('div');
                extraFieldContainer.id = 'extra-fields-container';
                submitItem.parentNode.insertBefore(extraFieldContainer, submitItem);
            } else {
                return;
            }
        }

        if (!entityType) {
            extraFieldContainer.innerHTML = '';
            return;
        }

        // Check cache
        if (extraFieldDefs[entityType]) {
            renderExtraFields(extraFieldDefs[entityType]);
            return;
        }

        extraFieldContainer.innerHTML = '<div class="layui-form-item"><div class="layui-input-block"><small>Loading extra fields...</small></div></div>';

        ApiClient.get('/entities/field-definitions', { entity_type: entityType }).then(function (resp) {
            if (resp.ok) {
                extraFieldDefs[entityType] = resp.data.items || [];
                renderExtraFields(extraFieldDefs[entityType]);
            } else {
                extraFieldContainer.innerHTML = '';
            }
        });
    }

    function renderExtraFields(defs) {
        if (!extraFieldContainer) return;
        if (!defs || defs.length === 0) {
            extraFieldContainer.innerHTML = '';
            return;
        }

        var html = '<div class="layui-form-item" style="margin-bottom:5px;"><div class="layui-input-block"><b>Additional Fields</b></div></div>';
        for (var i = 0; i < defs.length; i++) {
            var d = defs[i];
            html += '<div class="layui-form-item">';
            html += '<label class="layui-form-label">' + esc(d.field_label) + '</label>';
            html += '<div class="layui-input-block">';
            switch (d.field_type) {
                case 'text':
                    html += '<input type="text" name="ef_' + esc(d.field_key) + '" placeholder="' + esc(d.field_label) + '" class="layui-input">';
                    break;
                case 'number':
                    html += '<input type="number" name="ef_' + esc(d.field_key) + '" placeholder="' + esc(d.field_label) + '" class="layui-input">';
                    break;
                case 'date':
                    html += '<input type="date" name="ef_' + esc(d.field_key) + '" class="layui-input">';
                    break;
                case 'select':
                    html += '<select name="ef_' + esc(d.field_key) + '">';
                    html += '<option value="">-- Select --</option>';
                    var opts = d.options || [];
                    for (var j = 0; j < opts.length; j++) {
                        html += '<option value="' + esc(opts[j]) + '">' + esc(opts[j]) + '</option>';
                    }
                    html += '</select>';
                    break;
                default:
                    html += '<input type="text" name="ef_' + esc(d.field_key) + '" class="layui-input">';
            }
            html += '</div></div>';
        }
        extraFieldContainer.innerHTML = html;
        form.render('select');
    }

    // Watch entity type changes to load extra fields
    var ecTypeEl = document.getElementById('ec-type');
    if (ecTypeEl) {
        // Load on initial page
        loadExtraFields(ecTypeEl.value);
        form.on('select(ecTypeFilter)', function (data) {
            loadExtraFields(data.value);
        });
        // Also handle the native change for non-layui-rendered selects
        ecTypeEl.addEventListener('change', function () {
            loadExtraFields(ecTypeEl.value);
        });
    }

    // Entity Create form submission — now includes extra_fields
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

        // Collect extra fields (prefixed with "ef_")
        var extraFields = {};
        var hasExtra = false;
        for (var key in data.field) {
            if (key.indexOf('ef_') === 0) {
                var val = data.field[key];
                if (val !== '' && val !== null && val !== undefined) {
                    var fieldKey = key.substring(3);
                    // Coerce numbers
                    var defs = extraFieldDefs[body.entity_type] || [];
                    for (var d = 0; d < defs.length; d++) {
                        if (defs[d].field_key === fieldKey && defs[d].field_type === 'number') {
                            val = parseFloat(val);
                            break;
                        }
                    }
                    extraFields[fieldKey] = val;
                    hasExtra = true;
                }
            }
        }
        if (hasExtra) {
            body.extra_fields = extraFields;
        }

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

    // ═══════════════════════════════════════════════════════════
    //  Guided Duplicate Merge UX (Fix B)
    // ═══════════════════════════════════════════════════════════

    /**
     * Open the guided merge workspace for a given entity ID.
     * Fetches the entity's duplicate flags, loads both source and target
     * profiles, and presents a side-by-side comparison with an editable
     * resolution map.
     */
    window.openMergeWorkspace = function (entityId) {
        ApiClient.get('/entities/' + entityId).then(function (resp) {
            if (!resp.ok) {
                layer.msg('Failed to load entity', { icon: 2 });
                return;
            }
            var flags = resp.data.duplicate_flags || [];
            if (flags.length === 0) {
                layer.msg('No duplicate flags for this entity', { icon: 0 });
                return;
            }

            // Find the other entity in the first open flag
            var flag = flags[0];
            var otherId = flag.left_profile_id === entityId ? flag.right_profile_id : flag.left_profile_id;

            // Load both profiles
            Promise.all([
                ApiClient.get('/entities/' + entityId),
                ApiClient.get('/entities/' + otherId)
            ]).then(function (results) {
                var left = results[0].ok ? results[0].data.profile : null;
                var right = results[1].ok ? results[1].data.profile : null;
                if (!left || !right) {
                    layer.msg('Could not load both profiles', { icon: 2 });
                    return;
                }
                showMergeDialog(left, right);
            });
        });
    };

    function showMergeDialog(source, target) {
        var fields = ['display_name', 'address', 'id_last4', 'license_last4', 'entity_type'];
        var html = '<div style="padding:15px;">';
        html += '<h3 style="margin-bottom:10px;">Side-by-Side Comparison</h3>';
        html += '<p style="margin-bottom:10px;color:#666;">Select which value to keep for each field. The source profile will be deactivated after merge.</p>';
        html += '<table class="layui-table">';
        html += '<thead><tr><th>Field</th><th>Source #' + source.id + '</th><th>Target #' + target.id + '</th><th>Keep</th></tr></thead>';
        html += '<tbody>';

        for (var i = 0; i < fields.length; i++) {
            var f = fields[i];
            var sv = source[f] || '-';
            var tv = target[f] || '-';
            var isSame = sv === tv;
            var highlight = isSame ? '' : ' style="background:#fff3cd;"';
            html += '<tr' + highlight + '>';
            html += '<td><b>' + esc(f) + '</b></td>';
            html += '<td>' + esc(sv) + '</td>';
            html += '<td>' + esc(tv) + '</td>';
            html += '<td>';
            html += '<label><input type="radio" name="merge_' + f + '" value="source"' + (isSame ? '' : ' checked') + '> Source</label> ';
            html += '<label><input type="radio" name="merge_' + f + '" value="target"' + (isSame ? ' checked' : '') + '> Target</label>';
            html += '</td></tr>';
        }

        // Extra fields comparison
        var sourceExtra = source.extra_fields || {};
        var targetExtra = target.extra_fields || {};
        var allExtraKeys = Object.keys(Object.assign({}, sourceExtra, targetExtra));
        if (allExtraKeys.length > 0) {
            html += '<tr><td colspan="4"><b>Extra Fields</b></td></tr>';
            for (var k = 0; k < allExtraKeys.length; k++) {
                var ek = allExtraKeys[k];
                var sev = sourceExtra[ek] !== undefined ? String(sourceExtra[ek]) : '-';
                var tev = targetExtra[ek] !== undefined ? String(targetExtra[ek]) : '-';
                html += '<tr><td>' + esc(ek) + '</td><td>' + esc(sev) + '</td><td>' + esc(tev) + '</td>';
                html += '<td><label><input type="radio" name="merge_ef_' + ek + '" value="source"> Source</label> ';
                html += '<label><input type="radio" name="merge_ef_' + ek + '" value="target" checked> Target</label></td></tr>';
            }
        }

        html += '</tbody></table>';
        html += '<div style="text-align:center;margin-top:15px;">';
        html += '<button class="layui-btn layui-btn-danger" id="btn-exec-merge">Merge (Deactivate Source #' + source.id + ')</button>';
        html += ' <button class="layui-btn layui-btn-primary" id="btn-cancel-merge">Cancel</button>';
        html += '</div>';
        html += '<div id="merge-result-msg" class="auth-message layui-hide" style="margin-top:10px;"></div>';
        html += '</div>';

        var mergeLayerIdx = layer.open({
            type: 1,
            title: 'Guided Merge: #' + source.id + ' into #' + target.id,
            area: ['800px', '600px'],
            content: html,
            success: function () {
                document.getElementById('btn-cancel-merge').addEventListener('click', function () {
                    layer.close(mergeLayerIdx);
                });
                document.getElementById('btn-exec-merge').addEventListener('click', function () {
                    executeMerge(source.id, target.id, mergeLayerIdx);
                });
            }
        });
    }

    function executeMerge(sourceId, targetId, layerIdx) {
        var btn = document.getElementById('btn-exec-merge');
        btn.disabled = true;
        btn.textContent = 'Merging...';

        // Build resolution map from radio buttons
        var resolutionMap = {};
        var radios = document.querySelectorAll('input[type="radio"]:checked');
        for (var i = 0; i < radios.length; i++) {
            var name = radios[i].name;
            if (name.indexOf('merge_') === 0) {
                var fieldKey = name.substring(6);
                resolutionMap[fieldKey] = radios[i].value;
            }
        }

        ApiClient.post('/entities/' + sourceId + '/merge', {
            target_id: targetId,
            resolution_map: resolutionMap
        }).then(function (resp) {
            btn.disabled = false;
            btn.textContent = 'Merge';
            var msgEl = document.getElementById('merge-result-msg');
            if (resp.ok) {
                msgEl.className = 'auth-message success';
                msgEl.textContent = 'Merge complete! Target profile: #' + resp.data.merged_profile_id +
                    ', History ID: #' + resp.data.change_history_id;
                msgEl.classList.remove('layui-hide');
                // Refresh entity list in background
                if (typeof loadEntities === 'function') loadEntities();
                setTimeout(function () { layer.close(layerIdx); }, 2000);
            } else {
                msgEl.className = 'auth-message error';
                msgEl.textContent = resp.data ? resp.data.message : 'Merge failed';
                msgEl.classList.remove('layui-hide');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    //  User Verification Workflow (Fix A)
    // ═══════════════════════════════════════════════════════════

    /**
     * Load the authenticated user's verification status from GET /verifications/mine.
     * Renders the status panel with Pending/Approved/Rejected states.
     */
    window.loadMyVerification = function () {
        var loadingEl = document.getElementById('verif-status-loading');
        var noneEl = document.getElementById('verif-status-none');
        var displayEl = document.getElementById('verif-status-display');
        var bodyEl = document.getElementById('verif-status-body');
        var rejEl = document.getElementById('verif-rejection-reason');

        if (!loadingEl) return;
        loadingEl.classList.remove('layui-hide');
        noneEl.classList.add('layui-hide');
        displayEl.classList.add('layui-hide');
        rejEl.classList.add('layui-hide');

        ApiClient.get('/verifications/mine').then(function (resp) {
            loadingEl.classList.add('layui-hide');
            if (!resp.ok) {
                noneEl.classList.remove('layui-hide');
                noneEl.innerHTML = '<p>Error loading verification status.</p>';
                return;
            }

            if (resp.data.status === 'none') {
                noneEl.classList.remove('layui-hide');
                return;
            }

            displayEl.classList.remove('layui-hide');
            var statusColor = resp.data.status === 'approved' ? 'color:#009688;font-weight:bold' :
                resp.data.status === 'rejected' ? 'color:#FF5722;font-weight:bold' :
                'color:#1E9FFF;font-weight:bold';

            var statusLabel = resp.data.status.charAt(0).toUpperCase() + resp.data.status.slice(1);

            bodyEl.innerHTML =
                '<tr><td width="140">Request ID</td><td>' + resp.data.id + '</td></tr>' +
                '<tr><td>Status</td><td><span style="' + statusColor + '">' + esc(statusLabel) + '</span></td></tr>' +
                '<tr><td>Submitted</td><td>' + esc(resp.data.submitted_at || '-') + '</td></tr>' +
                '<tr><td>Reviewed</td><td>' + esc(resp.data.reviewed_at || 'Not yet reviewed') + '</td></tr>';

            // Show rejection reason when rejected
            if (resp.data.status === 'rejected' && resp.data.rejection_reason) {
                rejEl.classList.remove('layui-hide');
                rejEl.textContent = 'Rejection reason: ' + resp.data.rejection_reason;
            }
        });
    };

    // Refresh button for verification status
    var btnRefreshVerif = document.getElementById('btn-refresh-my-verif');
    if (btnRefreshVerif) {
        btnRefreshVerif.addEventListener('click', function () { loadMyVerification(); });
    }

    // Submit verification form
    form.on('submit(submitVerification)', function (data) {
        var btn = data.elem;
        btn.disabled = true;
        var msg = document.getElementById('verif-submit-msg');
        msg.classList.add('layui-hide');

        var idNum = (data.field.id_number || '').trim();
        var licNum = (data.field.license_number || '').trim();
        var fileInput = document.getElementById('verif-scan-file');
        var hasFile = fileInput && fileInput.files && fileInput.files.length > 0;

        // Client-side: require at least one piece of evidence
        if (!idNum && !licNum && !hasFile) {
            btn.disabled = false;
            msg.className = 'auth-message error';
            msg.textContent = 'Please provide at least one of: Government ID, Business License, or a scan upload.';
            msg.classList.remove('layui-hide');
            return false;
        }

        var body = {
            id_number: idNum || null,
            license_number: licNum || null
        };

        // Handle scan file upload if selected
        var fileInput = document.getElementById('verif-scan-file');
        if (fileInput && fileInput.files && fileInput.files.length > 0) {
            var fd = new FormData();
            fd.append('scan_file', fileInput.files[0]);
            if (body.id_number) fd.append('id_number', body.id_number);
            if (body.license_number) fd.append('license_number', body.license_number);

            var token = ApiClient.getToken();
            fetch('/verifications', {
                method: 'POST',
                headers: { 'Authorization': 'Bearer ' + token },
                body: fd
            }).then(function (resp) {
                return resp.json().then(function (d) { return { ok: resp.ok, data: d }; });
            }).then(function (result) {
                btn.disabled = false;
                if (result.ok) {
                    msg.className = 'auth-message success';
                    msg.textContent = 'Verification submitted (ID: ' + result.data.id + '). Status: Pending';
                    msg.classList.remove('layui-hide');
                    loadMyVerification();
                } else {
                    msg.className = 'auth-message error';
                    msg.textContent = result.data ? result.data.message : 'Submission failed';
                    msg.classList.remove('layui-hide');
                }
            });
        } else {
            // JSON submission (no file)
            ApiClient.post('/verifications', body).then(function (resp) {
                btn.disabled = false;
                if (resp.ok) {
                    msg.className = 'auth-message success';
                    msg.textContent = 'Verification submitted (ID: ' + resp.data.id + '). Status: Pending';
                    msg.classList.remove('layui-hide');
                    loadMyVerification();
                } else {
                    msg.className = 'auth-message error';
                    msg.textContent = resp.data ? resp.data.message : 'Submission failed';
                    msg.classList.remove('layui-hide');
                }
            });
        }
        return false;
    });

    // ═══════════════════════════════════════════════════════════
    //  Verifications Admin Review (existing)
    // ═══════════════════════════════════════════════════════════

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

    var btnRefreshVerifAdmin = document.getElementById('btn-refresh-verifications');
    if (btnRefreshVerifAdmin) {
        btnRefreshVerifAdmin.addEventListener('click', function () { loadVerifications(); });
    }

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }
});
