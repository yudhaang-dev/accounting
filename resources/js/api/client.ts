import createClient, { type Middleware } from 'openapi-fetch';
import type { paths } from './schema';

/**
 * Typed client for `/api/v1`, generated from the OpenAPI spec (`npm run api:types`).
 * Auth is the session cookie (ADR-0011): no token is ever stored in the browser.
 */

export type ApiErrorBody = {
    message: string;
    code?: string;
    errors?: Record<string, string[]>;
};

const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

function readCookie(name: string): string | null {
    const match = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith(`${name}=`));

    return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

const defaults: Middleware = {
    onRequest({ request }) {
        request.headers.set('Accept', 'application/json');
        request.headers.set(
            'Accept-Language',
            document.documentElement.lang || 'id',
        );

        // Writes echo the XSRF-TOKEN cookie (API-TR-21).
        const xsrf = readCookie('XSRF-TOKEN');

        if (xsrf && !SAFE_METHODS.includes(request.method)) {
            request.headers.set('X-XSRF-TOKEN', xsrf);
        }

        return request;
    },
};

export const api = createClient<paths>({
    baseUrl: '/api/v1',
    credentials: 'include',
});

api.use(defaults);

/** Requests the XSRF-TOKEN cookie; call once before the login flow. */
export async function ensureCsrfCookie(): Promise<void> {
    await api.GET('/csrf-cookie');
}

/** Fresh key for a command; reuse the same key when retrying the same user action (API-TR-13). */
export function newIdempotencyKey(): string {
    return crypto.randomUUID();
}

/** Business logic must branch on status and `code`, never on `message` (API-TR-06). */
export function isApiError(error: unknown): error is ApiErrorBody {
    return typeof error === 'object' && error !== null && 'message' in error;
}
