/**
 * Shared helper: load a frontend module source file from
 * public/static/js/*.js and execute it in the current happy-dom scope
 * with the globals it expects (layui, ApiClient, etc.) stubbed as needed.
 *
 * Why this indirection:
 *   The Layui modules are delivered as classic <script> globals, not ES
 *   modules. They rely on `layui.use(...)` or set a global via IIFE. To
 *   import them into a test we read the source and evaluate it with
 *   `new Function(...)` so that the environment we seed is exactly what
 *   the browser would provide at runtime.
 */
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

export const JS_DIR = resolve(__dirname, '../../public/static/js');

/**
 * Load the given module file (relative to public/static/js) and execute
 * it against `globalThis`. Returns the source string (useful for static
 * assertions) and also triggers any global side effects.
 *
 * `var` declarations at the top level of a `new Function` body stay local
 * to that function, so we explicitly lift known top-level names onto
 * globalThis after execution (matching browser `<script>` semantics
 * where top-level `var X = ...` becomes a window property).
 *
 * @param {string} fileName e.g. 'api-client.js'
 * @returns {string} the raw source
 */
export function loadModuleGlobal(fileName) {
    const src = readFileSync(resolve(JS_DIR, fileName), 'utf8');
    // Known top-level globals declared via `var` across the portal modules.
    // If any show up, lift them onto globalThis.
    const lifts = [
        'ApiClient',
    ];
    const liftCode = lifts
        .map((n) => `if (typeof ${n} !== "undefined") globalThis.${n} = ${n};`)
        .join('\n');
    const fn = new Function(src + '\n' + liftCode);
    fn.call(globalThis);
    return src;
}

/**
 * Build a minimal `layui` stub that captures module callbacks passed
 * to `layui.use(modules, cb)`. Returns both the stub and the captured
 * callbacks so tests can invoke them deterministically.
 */
export function makeLayuiStub() {
    const captured = [];
    const stub = {
        use(modules, cb) {
            captured.push({ modules, cb });
        },
        form: {
            on() { /* no-op collector */ },
            render() { /* no-op */ },
        },
        layer: {
            msg() { /* no-op */ },
            open() { return 1; },
            close() { /* no-op */ },
            confirm(_msg, cb) { if (typeof cb === 'function') cb(1); },
            prompt(_opts, cb) { if (typeof cb === 'function') cb('x', 1); },
        },
        element: {},
        util: {},
    };
    return { stub, captured };
}

/** Minimal in-memory localStorage for happy-dom compatibility. */
export function makeLocalStorageStub() {
    const store = new Map();
    return {
        getItem: (k) => (store.has(k) ? store.get(k) : null),
        setItem: (k, v) => { store.set(k, String(v)); },
        removeItem: (k) => { store.delete(k); },
        clear: () => { store.clear(); },
        get length() { return store.size; },
        _store: store,
    };
}
