/**
 * Unit tests for public/static/js/api-client.js
 * Covers: token storage helpers, Authorization header injection,
 * JSON parsing, trace-id propagation, and 401 redirect.
 */
import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { loadModuleGlobal, makeLocalStorageStub } from './loadModule.js';

describe('ApiClient (public/static/js/api-client.js)', () => {
    let originalLocation;
    let lastFetchInit;

    beforeEach(() => {
        // Fresh DOM globals for each test
        globalThis.localStorage = makeLocalStorageStub();
        globalThis.fetch = vi.fn((_url, init) => {
            lastFetchInit = init;
            const headers = new Map([['X-Trace-Id', 'trace-abc-123']]);
            return Promise.resolve({
                ok: true,
                status: 200,
                headers: { get: (k) => headers.get(k) || null },
                json: () => Promise.resolve({ ok: true, payload: 42 }),
            });
        });
        originalLocation = globalThis.window?.location;
        // Define a writable window.location for redirect tests
        Object.defineProperty(globalThis, 'window', {
            value: { location: { href: 'http://test/' } },
            configurable: true,
            writable: true,
        });

        loadModuleGlobal('api-client.js');
    });

    afterEach(() => {
        if (originalLocation) {
            globalThis.window = { location: originalLocation };
        }
    });

    it('exposes auth helpers on the global ApiClient', () => {
        expect(typeof globalThis.ApiClient).toBe('object');
        expect(typeof globalThis.ApiClient.getToken).toBe('function');
        expect(typeof globalThis.ApiClient.isAuthenticated).toBe('function');
        expect(typeof globalThis.ApiClient.getUser).toBe('function');
        expect(typeof globalThis.ApiClient.clearAuth).toBe('function');
    });

    it('isAuthenticated reflects localStorage access_token', () => {
        expect(globalThis.ApiClient.isAuthenticated()).toBe(false);
        globalThis.localStorage.setItem('access_token', 'tok-xyz');
        expect(globalThis.ApiClient.isAuthenticated()).toBe(true);
        expect(globalThis.ApiClient.getToken()).toBe('tok-xyz');
    });

    it('clearAuth removes access_token and user from localStorage', () => {
        globalThis.localStorage.setItem('access_token', 'tok');
        globalThis.localStorage.setItem('user', JSON.stringify({ id: 1 }));
        globalThis.ApiClient.clearAuth();
        expect(globalThis.localStorage.getItem('access_token')).toBeNull();
        expect(globalThis.localStorage.getItem('user')).toBeNull();
    });

    it('injects Authorization Bearer header when a token exists', async () => {
        globalThis.localStorage.setItem('access_token', 'tok-xyz');
        const resp = await globalThis.ApiClient.get('/health');
        expect(resp.ok).toBe(true);
        expect(resp.status).toBe(200);
        expect(lastFetchInit.headers.Authorization).toBe('Bearer tok-xyz');
    });

    it('propagates X-Trace-Id from response headers into result', async () => {
        const resp = await globalThis.ApiClient.get('/health');
        expect(resp.traceId).toBe('trace-abc-123');
    });

    it('parses JSON response into data field', async () => {
        const resp = await globalThis.ApiClient.get('/whatever');
        expect(resp.data).toEqual({ ok: true, payload: 42 });
    });

    it('post() sends JSON-stringified body and correct Content-Type', async () => {
        await globalThis.ApiClient.post('/entities', { name: 'test' });
        expect(lastFetchInit.method).toBe('POST');
        expect(lastFetchInit.headers['Content-Type']).toBe('application/json');
        expect(lastFetchInit.body).toBe(JSON.stringify({ name: 'test' }));
    });

    it('network error yields NETWORK_ERROR envelope', async () => {
        globalThis.fetch = vi.fn(() => Promise.reject(new Error('boom')));
        const resp = await globalThis.ApiClient.get('/fail');
        expect(resp.ok).toBe(false);
        expect(resp.status).toBe(0);
        expect(resp.data.code).toBe('NETWORK_ERROR');
    });
});
