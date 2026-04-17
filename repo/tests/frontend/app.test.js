/**
 * Unit tests for public/static/js/app.js
 * Covers: auth gate redirect, role-specific landing routing, layui.use wiring.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadModuleGlobal, makeLayuiStub, makeLocalStorageStub } from './loadModule.js';

describe('app.js (main bootstrap)', () => {
    let captured;
    let redirectTarget;

    beforeEach(() => {
        globalThis.localStorage = makeLocalStorageStub();
        const layui = makeLayuiStub();
        globalThis.layui = layui.stub;
        captured = layui.captured;

        redirectTarget = null;
        const loc = new Proxy({ href: 'http://test/static/index.html', search: '' }, {
            set(target, prop, value) {
                if (prop === 'href') redirectTarget = value;
                target[prop] = value;
                return true;
            },
        });
        Object.defineProperty(globalThis, 'window', {
            value: { location: loc },
            configurable: true, writable: true,
        });

        globalThis.ApiClient = {
            isAuthenticated: vi.fn(() => false),
            getUser: vi.fn(() => null),
            get: vi.fn(() => Promise.resolve({ ok: true, data: {} })),
            post: vi.fn(() => Promise.resolve({ ok: true, data: {} })),
            healthCheck: vi.fn(() => Promise.resolve({ ok: true, status: 'ok', traceId: 't' })),
            clearAuth: vi.fn(),
        };

        loadModuleGlobal('app.js');
    });

    it('registers a layui.use callback with element/layer/util', () => {
        expect(captured.length).toBeGreaterThan(0);
        expect(captured[0].modules).toEqual(['element', 'layer', 'util']);
        expect(typeof captured[0].cb).toBe('function');
    });

    it('redirects unauthenticated user to /static/login.html', () => {
        captured[0].cb();
        expect(redirectTarget).toBe('/static/login.html');
    });

    it('sets farmer landing page when authenticated as farmer', () => {
        globalThis.ApiClient.isAuthenticated = vi.fn(() => true);
        globalThis.ApiClient.getUser = vi.fn(() => ({ username: 'f', role: 'farmer' }));
        // happy-dom provides document; seed minimal DOM elements the callback reads
        document.body.innerHTML = `
            <span id="nav-username"></span>
            <span id="nav-role-display"></span>
            <div id="nav-admin" class="layui-hide"></div>
            <div id="nav-verification" class="layui-hide"></div>
            <button id="btn-logout"></button>
            <span id="status-icon"></span><span id="status-text"></span>
            <div id="health-loading" class="layui-hide"></div>
            <div id="health-success" class="layui-hide"></div>
            <div id="health-error" class="layui-hide"></div>
            <div id="health-detail"></div>
            <div id="health-error-detail"></div>
            <tbody id="user-info-body"></tbody>
        `;
        captured[0].cb();
        // Farmer nav gets un-hidden
        expect(document.getElementById('nav-verification').classList.contains('layui-hide')).toBe(false);
        // Admin nav stays hidden
        expect(document.getElementById('nav-admin').classList.contains('layui-hide')).toBe(true);
    });
});
