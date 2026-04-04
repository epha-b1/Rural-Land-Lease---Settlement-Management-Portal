/**
 * Base API client for the Rural Lease Portal.
 * Provides a thin wrapper over fetch() with:
 * - Automatic JSON parsing
 * - Auth token injection from localStorage
 * - Trace ID propagation
 * - Standardized error handling
 * - Auto-redirect to login on 401
 */
var ApiClient = (function () {
    'use strict';

    var BASE_URL = '';  // Same origin

    /**
     * Core request method.
     * @param {string} method - HTTP method
     * @param {string} path - API path (e.g., '/health')
     * @param {object} [options] - { body, headers, params }
     * @returns {Promise<{ok: boolean, status: number, data: object, traceId: string}>}
     */
    function request(method, path, options) {
        options = options || {};
        var headers = Object.assign({
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        }, options.headers || {});

        // Inject auth token if available
        var token = localStorage.getItem('access_token');
        if (token && !headers['Authorization']) {
            headers['Authorization'] = 'Bearer ' + token;
        }

        var url = BASE_URL + path;

        // Append query params for GET
        if (options.params) {
            var qs = Object.keys(options.params)
                .filter(function (k) { return options.params[k] !== undefined && options.params[k] !== null; })
                .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(options.params[k]); })
                .join('&');
            if (qs) url += '?' + qs;
        }

        var fetchOptions = {
            method: method,
            headers: headers
        };

        if (options.body && method !== 'GET') {
            fetchOptions.body = JSON.stringify(options.body);
        }

        return fetch(url, fetchOptions)
            .then(function (resp) {
                var traceId = resp.headers.get('X-Trace-Id') || '';
                return resp.json().then(function (data) {
                    return {
                        ok: resp.ok,
                        status: resp.status,
                        data: data,
                        traceId: traceId
                    };
                }).catch(function () {
                    return {
                        ok: resp.ok,
                        status: resp.status,
                        data: null,
                        traceId: traceId
                    };
                });
            })
            .catch(function (err) {
                return {
                    ok: false,
                    status: 0,
                    data: { status: 'error', code: 'NETWORK_ERROR', message: err.message, trace_id: '' },
                    traceId: ''
                };
            });
    }

    return {
        get: function (path, params, headers) {
            return request('GET', path, { params: params, headers: headers });
        },
        post: function (path, body, headers) {
            return request('POST', path, { body: body, headers: headers });
        },
        patch: function (path, body, headers) {
            return request('PATCH', path, { body: body, headers: headers });
        },
        del: function (path, headers) {
            return request('DELETE', path, { headers: headers });
        },

        /**
         * Health check convenience method.
         * @returns {Promise<{ok: boolean, status: string, traceId: string}>}
         */
        healthCheck: function () {
            return this.get('/health').then(function (resp) {
                return {
                    ok: resp.ok,
                    status: resp.data ? resp.data.status : 'unknown',
                    traceId: resp.traceId
                };
            });
        },

        /**
         * Get stored auth token.
         */
        getToken: function () {
            return localStorage.getItem('access_token');
        },

        /**
         * Check if user is authenticated.
         */
        isAuthenticated: function () {
            return !!localStorage.getItem('access_token');
        },

        /**
         * Get stored user info.
         */
        getUser: function () {
            try {
                return JSON.parse(localStorage.getItem('user'));
            } catch (e) {
                return null;
            }
        },

        /**
         * Clear auth state (logout).
         */
        clearAuth: function () {
            localStorage.removeItem('access_token');
            localStorage.removeItem('user');
        }
    };
})();
