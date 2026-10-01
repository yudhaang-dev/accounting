/**
 * Shared HTTP layer for the SPA: the same headers, CSRF handling and error shape for the typed
 * `/api/v1` client and for Fortify's auth routes (which are not part of the OpenAPI spec).
 * Auth is the session cookie (ADR-0011); no token is ever kept in the browser.
 */

const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

export type ApiErrorBody = {
    message: string;
    code?: string;
    errors?: Record<string, string[]>;
};

/** Normalised failure: branch on `status` and `code`, never on `message` (API-TR-06). */
export class ApiError extends Error {
    constructor(
        readonly status: number,
        message: string,
        readonly code?: string,
        readonly errors: Record<string, string[]> = {},
        /** Seconds from the `Retry-After` header on 429, when present. */
        readonly retryAfter?: number,
    ) {
        super(message);
        this.name = 'ApiError';
    }

    get isNetwork(): boolean {
        return this.status === 0;
    }

    get isRateLimited(): boolean {
        return this.status === 429;
    }

    /** First message for a field, if the server sent one. */
    first(field: string): string | undefined {
        return this.errors[field]?.[0];
    }
}

export function isApiError(error: unknown): error is ApiError {
    return error instanceof ApiError;
}

export function readCookie(name: string): string | null {
    const match = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith(`${name}=`));

    return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

/** Headers every request carries; writes echo the XSRF-TOKEN cookie (API-TR-21). */
export function defaultHeaders(method: string): Record<string, string> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Accept-Language': document.documentElement.lang || 'id',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const xsrf = readCookie('XSRF-TOKEN');

    if (xsrf && !SAFE_METHODS.includes(method.toUpperCase())) {
        headers['X-XSRF-TOKEN'] = xsrf;
    }

    return headers;
}

type UnauthenticatedHandler = () => void;

let onUnauthenticated: UnauthenticatedHandler | null = null;

/** Called when a request that needs a session answers 401 (for example an expired session). */
export function setUnauthenticatedHandler(
    handler: UnauthenticatedHandler | null,
): void {
    onUnauthenticated = handler;
}

export function notifyUnauthenticated(): void {
    onUnauthenticated?.();
}

/** Requests the XSRF-TOKEN cookie; needed before the first write of a fresh browser session. */
export async function ensureCsrfCookie(): Promise<void> {
    await fetch('/api/v1/csrf-cookie', {
        credentials: 'same-origin',
        headers: defaultHeaders('GET'),
    });
}

async function toApiError(response: Response): Promise<ApiError> {
    let body: Partial<ApiErrorBody> = {};

    try {
        body = (await response.json()) as Partial<ApiErrorBody>;
    } catch {
        // Not JSON (proxy error page, empty body): fall back to the status below.
    }

    const retryAfter = Number(response.headers.get('Retry-After'));

    return new ApiError(
        response.status,
        body.message ?? response.statusText,
        body.code,
        body.errors,
        Number.isFinite(retryAfter) && retryAfter > 0 ? retryAfter : undefined,
    );
}

export type RequestOptions = {
    /** Do not treat a 401 as "session expired" (login, 2FA and similar calls expect it). */
    expectUnauthenticated?: boolean;
};

/**
 * JSON request. Resolves with the parsed body (undefined for 204) or rejects with an `ApiError`.
 * A stale CSRF token (419) is refreshed once and the request retried.
 */
export async function request<T = undefined>(
    method: string,
    url: string,
    body?: unknown,
    options: RequestOptions = {},
): Promise<T> {
    const send = async (): Promise<Response> => {
        if (
            !SAFE_METHODS.includes(method.toUpperCase()) &&
            readCookie('XSRF-TOKEN') === null
        ) {
            await ensureCsrfCookie();
        }

        return fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                ...defaultHeaders(method),
                ...(body === undefined
                    ? {}
                    : { 'Content-Type': 'application/json' }),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
    };

    let response: Response;

    try {
        response = await send();

        if (response.status === 419) {
            await ensureCsrfCookie();
            response = await send();
        }
    } catch {
        throw new ApiError(0, 'Network error', 'NETWORK');
    }

    if (!response.ok) {
        if (response.status === 401 && !options.expectUnauthenticated) {
            notifyUnauthenticated();
        }

        throw await toApiError(response);
    }

    if (response.status === 204) {
        return undefined as T;
    }

    return (await response.json()) as T;
}
