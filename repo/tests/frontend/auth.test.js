/**
 * Unit tests for public/static/js/auth.js
 * Covers: registers a layui.use handler with form/layer, contains login + register
 * form handler registrations via form.on(), fetches CAPTCHA endpoint for fresh
 * challenges, and includes the role-specific redirect after login.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadModuleGlobal, makeLayuiStub, makeLocalStorageStub, JS_DIR } from './loadModule.js';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

describe('auth.js', () => {
    let captured;
    let formOnCalls;

    beforeEach(() => {
        globalThis.localStorage = makeLocalStorageStub();
        const layui = makeLayuiStub();
        formOnCalls = [];
        layui.stub.form.on = (filter, handler) => {
            formOnCalls.push({ filter, handler });
        };
        globalThis.layui = layui.stub;
        captured = layui.captured;

        globalThis.ApiClient = {
            post: vi.fn(() => Promise.resolve({ ok: true, data: { access_token: 't', user: { role: 'farmer' } } })),
            get: vi.fn(() => Promise.resolve({ ok: true, data: { challenge_id: 'c1', question: '1+1' } })),
        };

        Object.defineProperty(globalThis, 'window', {
            value: { location: { href: 'http://test/static/login.html' } },
            configurable: true, writable: true,
        });

        // Seed the DOM that auth.js queries on init
        document.body.innerHTML = `
            <div id="auth-message"></div>
            <form id="login-form"></form>
            <form id="register-form"></form>
            <input id="captcha-question"><input id="captcha-answer">
            <button id="captcha-refresh"></button>
        `;

        loadModuleGlobal('auth.js');
    });

    it('registers a layui.use callback with form and layer deps', () => {
        expect(captured.length).toBeGreaterThan(0);
        expect(captured[0].modules).toEqual(['form', 'layer']);
    });

    it('registers form handlers on the layui.use callback execution', () => {
        captured[0].cb();
        // Should register at least one handler; login/register form filters live in the module
        expect(formOnCalls.length).toBeGreaterThan(0);
        const filters = formOnCalls.map((c) => c.filter);
        expect(filters.join(' ')).toMatch(/login|register/i);
    });

    it('source contains the role-aware redirect after login', () => {
        const src = readFileSync(resolve(JS_DIR, 'auth.js'), 'utf8');
        expect(src).toMatch(/\/static\/index\.html\?role=/);
    });

    it('source fetches the CAPTCHA endpoint', () => {
        const src = readFileSync(resolve(JS_DIR, 'auth.js'), 'utf8');
        expect(src).toMatch(/\/auth\/captcha/);
    });
});
