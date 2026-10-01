import createClient, { type Middleware } from 'openapi-fetch';
import { defaultHeaders, notifyUnauthenticated } from '@/lib/http';
import type { paths } from './schema';

/**
 * Typed client for `/api/v1`, generated from the OpenAPI spec (`npm run api:types`).
 * Auth is the session cookie (ADR-0011): no token is ever stored in the browser.
 */

// The signed-in probe answers 401 by design when nobody is logged in; that is not "session expired".
const SESSION_PROBES = ['/api/v1/me', '/api/v1/csrf-cookie'];

const defaults: Middleware = {
    onRequest({ request }) {
        for (const [name, value] of Object.entries(
            defaultHeaders(request.method),
        )) {
            request.headers.set(name, value);
        }

        return request;
    },
    onResponse({ request, response }) {
        const path = new URL(request.url).pathname;

        if (response.status === 401 && !SESSION_PROBES.includes(path)) {
            notifyUnauthenticated();
        }

        return response;
    },
};

export const api = createClient<paths>({
    baseUrl: `${window.location.origin}/api/v1`,
    credentials: 'same-origin',
    // Resolved per call, so tests (and polyfills) can replace the global fetch.
    fetch: (request) => globalThis.fetch(request),
});

api.use(defaults);

/** Fresh key for a command; reuse the same key when retrying the same user action (API-TR-13). */
export function newIdempotencyKey(): string {
    return crypto.randomUUID();
}
