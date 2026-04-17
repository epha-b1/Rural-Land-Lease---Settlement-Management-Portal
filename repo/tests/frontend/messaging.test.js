/**
 * Unit tests for public/static/js/messaging.js
 * Covers: layui.use wiring, globals (loadMessaging, loadRiskRules, loadAuditLogs,
 * editRiskRule, deleteRiskRule), risk-keyword CRUD endpoint wiring, and
 * preflight-risk path.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadModuleGlobal, makeLayuiStub, JS_DIR } from './loadModule.js';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

describe('messaging.js', () => {
    let captured;

    beforeEach(() => {
        const layui = makeLayuiStub();
        globalThis.layui = layui.stub;
        captured = layui.captured;

        globalThis.ApiClient = {
            get: vi.fn(() => Promise.resolve({ ok: true, data: { items: [] } })),
            post: vi.fn(() => Promise.resolve({ ok: true, data: { id: 1 } })),
            patch: vi.fn(() => Promise.resolve({ ok: true, data: {} })),
            del: vi.fn(() => Promise.resolve({ ok: true, data: { disabled: true } })),
            getToken: vi.fn(() => 'tok'),
        };

        document.body.innerHTML = `
            <div id="conv-list"></div>
            <div id="msg-panel" class="layui-hide"></div>
            <div id="msg-list"></div>
            <input id="msg-input">
            <button id="btn-send-msg"></button>
            <button id="btn-new-conv"></button>
            <button id="btn-preflight-msg"></button>
            <input type="file" id="msg-file">
            <div id="msg-attachment-row" class="layui-hide"></div>
            <div id="msg-preflight-warning" class="layui-hide"></div>
            <div id="msg-risk-warning" class="layui-hide"></div>
            <tbody id="risk-rules-tbody"></tbody>
            <button id="btn-add-risk-rule"></button>
            <button id="btn-refresh-risk-rules"></button>
            <div id="risk-rule-form-panel" class="layui-hide"></div>
            <div id="risk-rule-form-title"></div>
            <input id="risk-rule-edit-id" value="">
            <form id="risk-rule-form"></form>
            <div id="risk-rule-form-msg"></div>
            <tbody id="audit-tbody"></tbody>
            <button id="btn-refresh-audit"></button>
        `;

        loadModuleGlobal('messaging.js');
        captured[0].cb();
    });

    it('registers a layui.use callback with form and layer', () => {
        expect(captured[0].modules).toEqual(['form', 'layer']);
    });

    it('exposes loadMessaging, loadRiskRules, loadAuditLogs, editRiskRule, deleteRiskRule', () => {
        expect(typeof window.loadMessaging).toBe('function');
        expect(typeof window.loadRiskRules).toBe('function');
        expect(typeof window.loadAuditLogs).toBe('function');
        expect(typeof window.editRiskRule).toBe('function');
        expect(typeof window.deleteRiskRule).toBe('function');
    });

    it('loadRiskRules fetches /admin/risk-keywords', () => {
        window.loadRiskRules();
        expect(globalThis.ApiClient.get).toHaveBeenCalledWith('/admin/risk-keywords');
    });

    it('loadAuditLogs fetches /audit-logs', () => {
        window.loadAuditLogs();
        expect(globalThis.ApiClient.get).toHaveBeenCalledWith('/audit-logs');
    });

    it('source calls the preflight-risk endpoint', () => {
        const src = readFileSync(resolve(JS_DIR, 'messaging.js'), 'utf8');
        expect(src).toMatch(/\/messages\/preflight-risk/);
    });

    it('source wires attachment base64 upload flow', () => {
        const src = readFileSync(resolve(JS_DIR, 'messaging.js'), 'utf8');
        expect(src).toMatch(/data_base64/);
    });
});
