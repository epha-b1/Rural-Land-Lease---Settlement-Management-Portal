/**
 * Unit tests for public/static/js/finance.js
 * Covers: layui.use wiring, loadContracts/loadInvoices/openReceipt/printReceipt
 * globals, receipt API path, print flow, and idempotency key injection on payment.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { loadModuleGlobal, makeLayuiStub, JS_DIR } from './loadModule.js';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

describe('finance.js', () => {
    let captured;

    beforeEach(() => {
        const layui = makeLayuiStub();
        globalThis.layui = layui.stub;
        captured = layui.captured;

        globalThis.ApiClient = {
            get: vi.fn(() => Promise.resolve({
                ok: true,
                data: { items: [], invoice: { id: 1 }, contract: {}, payments: [], refunds: [], balance_cents: 0 },
            })),
            post: vi.fn(() => Promise.resolve({ ok: true, data: { contract_id: 1 } })),
            getToken: vi.fn(() => 'token-xyz'),
        };

        document.body.innerHTML = `
            <tbody id="contracts-tbody"></tbody>
            <tbody id="invoices-tbody"></tbody>
            <button id="btn-refresh-contracts"></button>
            <button id="btn-refresh-invoices"></button>
            <form id="contract-create-form"></form>
            <div id="contract-create-msg"></div>
            <form id="payment-form"></form>
            <div id="payment-msg"></div>
            <input id="export-from" value="2020-01-01">
            <input id="export-to" value="2030-12-31">
            <a id="btn-export-ledger-csv"></a>
            <a id="btn-export-ledger-xlsx"></a>
            <a id="btn-export-recon-csv"></a>
            <a id="btn-export-recon-xlsx"></a>
        `;

        loadModuleGlobal('finance.js');
        captured[0].cb();
    });

    it('registers a layui.use callback with form and layer deps', () => {
        expect(captured[0].modules).toEqual(['form', 'layer']);
    });

    it('exposes loadContracts, loadInvoices, openReceipt, printReceipt on window', () => {
        expect(typeof globalThis.loadContracts).toBe('function');
        expect(typeof globalThis.loadInvoices).toBe('function');
        expect(typeof globalThis.openReceipt).toBe('function');
        expect(typeof globalThis.printReceipt).toBe('function');
    });

    it('loadContracts fetches /contracts', () => {
        globalThis.loadContracts();
        expect(globalThis.ApiClient.get).toHaveBeenCalledWith('/contracts');
    });

    it('openReceipt fetches /invoices/:id/receipt', () => {
        globalThis.openReceipt(42);
        expect(globalThis.ApiClient.get).toHaveBeenCalledWith('/invoices/42/receipt');
    });

    it('source generates an Idempotency-Key on payment submit', () => {
        const src = readFileSync(resolve(JS_DIR, 'finance.js'), 'utf8');
        expect(src).toMatch(/Idempotency-Key/);
        expect(src).toMatch(/iKey/);
    });

    it('source uses window.print in the print flow', () => {
        const src = readFileSync(resolve(JS_DIR, 'finance.js'), 'utf8');
        expect(src).toMatch(/win\.print\(\)/);
    });
});
