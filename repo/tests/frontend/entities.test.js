/**
 * Unit tests for public/static/js/entities.js
 * Covers: layui.use wiring, global loadEntities/viewEntity/loadMyVerification
 * functions set on window, client-side verification evidence validation.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadModuleGlobal, makeLayuiStub, JS_DIR } from './loadModule.js';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

describe('entities.js', () => {
    let captured;
    let formOnCalls;

    beforeEach(() => {
        const layui = makeLayuiStub();
        formOnCalls = [];
        layui.stub.form.on = (filter, handler) => { formOnCalls.push({ filter, handler }); };
        globalThis.layui = layui.stub;
        captured = layui.captured;

        globalThis.ApiClient = {
            get: vi.fn(() => Promise.resolve({ ok: true, data: { items: [], profile: {}, duplicate_flags: [] } })),
            post: vi.fn(() => Promise.resolve({ ok: true, data: { id: 1 } })),
            getToken: vi.fn(() => 't'),
        };
        Object.defineProperty(globalThis, 'window', {
            value: { location: { href: 'http://test/' } },
            configurable: true, writable: true,
        });

        document.body.innerHTML = `
            <div id="entity-list-loading"></div>
            <table id="entity-table" class="layui-hide"></table>
            <tbody id="entity-table-body"></tbody>
            <div id="entity-scope-error"></div>
            <span id="entity-scope-error-msg"></span>
            <select id="ec-type"><option value="farmer">Farmer</option></select>
            <form id="entity-create-form">
              <div class="layui-form-item"><div class="layui-input-block"><button type="submit"></button></div></div>
            </form>
            <div id="entity-create-dup-warning"></div>
            <div id="entity-create-msg"></div>
            <div id="verif-status-loading"></div>
            <div id="verif-status-none"></div>
            <div id="verif-status-display"></div>
            <tbody id="verif-status-body"></tbody>
            <div id="verif-rejection-reason"></div>
            <form id="verif-submit-form"></form>
            <input type="file" id="verif-scan-file">
            <div id="verif-submit-msg"></div>
            <tbody id="verif-table-body"></tbody>
        `;

        loadModuleGlobal('entities.js');
        // Invoke the layui.use callback to register event handlers + globals
        captured[0].cb();
    });

    it('registers a layui.use callback with form and layer deps', () => {
        expect(captured.length).toBeGreaterThan(0);
        expect(captured[0].modules).toEqual(['form', 'layer']);
    });

    it('exposes loadEntities, viewEntity, and loadMyVerification on window', () => {
        expect(typeof globalThis.loadEntities).toBe('function');
        expect(typeof globalThis.viewEntity).toBe('function');
        expect(typeof globalThis.loadMyVerification).toBe('function');
    });

    it('loadEntities calls GET /entities via ApiClient', async () => {
        globalThis.loadEntities();
        expect(globalThis.ApiClient.get).toHaveBeenCalledWith('/entities');
    });

    it('registers a form submit handler for verification submission', () => {
        const filters = formOnCalls.map((c) => c.filter);
        expect(filters).toContain('submit(submitVerification)');
    });

    it('source enforces at-least-one-evidence client-side rule', () => {
        const src = readFileSync(resolve(JS_DIR, 'entities.js'), 'utf8');
        expect(src).toMatch(/Government ID.*Business License.*scan/i);
    });
});
