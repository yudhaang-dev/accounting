import { describe, expect, it, vi } from 'vitest';
import { ApiError, request, setUnauthenticatedHandler } from './http';
import { safeRedirect } from './session';
import { json, mockApi } from '@/test/harness';

describe('request', () => {
    it('turns a validation response into an ApiError with field messages', async () => {
        mockApi({
            'POST /x': () =>
                json(
                    { message: 'Invalid', errors: { email: ['Bad email'] } },
                    422,
                ),
        });

        const error = await request('POST', '/x', {}).catch((e: unknown) => e);

        expect(error).toBeInstanceOf(ApiError);
        expect(error).toMatchObject({ status: 422, message: 'Invalid' });
        expect((error as ApiError).first('email')).toBe('Bad email');
    });

    it('reads the stable code and Retry-After of a throttled response', async () => {
        mockApi({
            'POST /x': () =>
                json({ message: 'Slow down', code: 'TOO_MANY_REQUESTS' }, 429, {
                    'Retry-After': '42',
                }),
        });

        const error = (await request('POST', '/x', {}).catch(
            (e: unknown) => e,
        )) as ApiError;

        expect(error.isRateLimited).toBe(true);
        expect(error.code).toBe('TOO_MANY_REQUESTS');
        expect(error.retryAfter).toBe(42);
    });

    it('reports a lost connection as a network error, not a server error', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockRejectedValue(new TypeError('offline')),
        );

        const error = (await request('GET', '/x').catch(
            (e: unknown) => e,
        )) as ApiError;

        expect(error.isNetwork).toBe(true);
    });

    it('refreshes a stale CSRF token once and retries the write', async () => {
        let attempts = 0;
        const api = mockApi({
            'POST /x': () => {
                attempts += 1;

                return attempts === 1
                    ? json(
                          { message: 'expired', code: 'CSRF_TOKEN_MISMATCH' },
                          419,
                      )
                    : json({ ok: true });
            },
        });

        await expect(request('POST', '/x', {})).resolves.toEqual({ ok: true });
        expect(api.called('POST /x')).toHaveLength(2);
        expect(api.called('GET /api/v1/csrf-cookie').length).toBeGreaterThan(0);
    });

    it('echoes the XSRF cookie on writes only', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D; path=/';
        mockApi({ 'POST /x': () => json({}), 'GET /x': () => json({}) });

        await request('POST', '/x', {});
        await request('GET', '/x');

        const [post, get] = vi
            .mocked(fetch)
            .mock.calls.filter(([url]) => url === '/x');

        expect(new Headers(post?.[1]?.headers).get('X-XSRF-TOKEN')).toBe(
            'abc=',
        );
        expect(new Headers(get?.[1]?.headers).get('X-XSRF-TOKEN')).toBeNull();
    });

    it('announces an ended session on 401, except where 401 is an expected answer', async () => {
        const ended = vi.fn();

        setUnauthenticatedHandler(ended);
        mockApi({ 'GET /x': () => json({ code: 'UNAUTHENTICATED' }, 401) });

        await request('GET', '/x').catch(() => undefined);
        expect(ended).toHaveBeenCalledTimes(1);

        await request('GET', '/x', undefined, {
            expectUnauthenticated: true,
        }).catch(() => undefined);
        expect(ended).toHaveBeenCalledTimes(1);

        setUnauthenticatedHandler(null);
    });

    it('returns nothing for 204', async () => {
        mockApi({ 'POST /x': () => new Response(null, { status: 204 }) });

        await expect(request('POST', '/x', {})).resolves.toBeUndefined();
    });
});

describe('safeRedirect', () => {
    it.each(['/', '/reports?from=2026-01-01', '/a/b#c'])(
        'keeps the site-relative path %s',
        (value) => {
            expect(safeRedirect(value)).toBe(value);
        },
    );

    it.each([
        '//evil.example',
        'https://evil.example',
        '/\\evil.example',
        'javascript:alert(1)',
        '',
        42,
        null,
    ])(
        'refuses %s so the login cannot be used as an open redirect',
        (value) => {
            expect(safeRedirect(value)).toBeUndefined();
        },
    );
});
