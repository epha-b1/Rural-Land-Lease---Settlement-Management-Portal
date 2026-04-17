/**
 * Unit tests for public/static/js/admin.js
 * Covers: layui.use wiring, globals (loadAdminJobs, loadAdminConfig, loadDelegations,
 * approveDelegation), and delegation endpoint wiring.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadModuleGlobal, makeLayuiStub, JS_DIR } from './loadModule.js';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

describe('admin.js', () => {
    let captured;

    beforeEach(() => {
        const layui = makeLayuiStub();
        globalThis.layui = layui.stub;
        captured = layui.captured;

        globalThis.ApiClient = {
            get: vi.fn(() => Promise.resolve({ ok: true, data: { items: [], jobs: [] } })),
            post: vi.fn(() => Promise.resolve({ ok: true, data: { delegation_id: 1, results: {} } })),
        };

        document.body.innerHTML = `
            <tbody id="jobs-tbody"></tbody>
            <button id="btn-run-jobs"></button>
            <div id="jobs-result" class="layui-hide"></div>
            <tbody id="config-tbody"></tbody>
            <tbody id="delegations-tbody"></tbody>
            <button id="btn-refresh-delegations"></button>
            <button id="btn-new-delegation"></button>
            <div id="delegation-create-panel" class="layui-hide"></div>
            <form id="delegation-create-form"></form>
            <div id="delegation-create-msg"></div>
        `;

        loadModuleGlobal('admin.js');
        captured[0].cb();
    });

    it('registers a layui.use callback with form and layer deps', () => {
        expect(captured[0].modules).toEqual(['form', 'layer']);
    });

    it('exposes loadAdminJobs, loadAdminConfig, loadDelegations, approveDelegation', () => {
        expect(typeof globalThis.loadAdminJobs).toBe('function');
        expect(typeof globalThis.loadAdminConfig).toBe('function');
        expect(typeof globalThis.loadDelegations).toBe('function');
        expect(typeof globalThis.approveDelegation).toBe('function');
    });

    it('loadAdminJobs calls GET /admin/jobs', () => {
        globalThis.loadAdminJobs();
        expect(globalThis.ApiClient.get).toHaveBeenCalledWith('/admin/jobs');
    });

    it('loadDelegations calls GET /delegations', () => {
        globalThis.loadDelegations();
        expect(globalThis.ApiClient.get).toHaveBeenCalledWith('/delegations');
    });

    it('source calls the delegation approve endpoint', () => {
        const src = readFileSync(resolve(JS_DIR, 'admin.js'), 'utf8');
        expect(src).toMatch(/\/delegations\/'\s*\+\s*id\s*\+\s*'\/approve/);
    });
});
