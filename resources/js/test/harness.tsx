import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createMemoryHistory, RouterProvider } from '@tanstack/react-router';
import { render } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import { vi } from 'vitest';
import { i18n } from '@/i18n';
import { createAppRouter } from '@/app/router';

export type Call = {
    method: string;
    path: string;
    query: Record<string, string>;
    accept: string | null;
    body: unknown;
};
type Reply = Response | ((call: Call) => Response | Promise<Response>);

export const json = (
    body: unknown,
    status = 200,
    headers: Record<string, string> = {},
) =>
    new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json', ...headers },
    });

export const account = {
    id: 1,
    name: 'Budi Santoso',
    email: 'budi@example.com',
    email_verified: true,
    two_factor_enabled: false,
};

/**
 * Replaces `fetch` with a table of `"METHOD /path"` -> reply. Anything not listed fails the test
 * loudly, so a screen can never silently call an endpoint the test did not expect.
 */
export function mockApi(routes: Record<string, Reply>) {
    const calls: Call[] = [];

    const table: Record<string, Reply> = {
        'GET /api/v1/csrf-cookie': () => new Response(null, { status: 204 }),
        ...routes,
    };

    vi.stubGlobal(
        'fetch',
        vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const request =
                input instanceof Request
                    ? input
                    : new Request(
                          new URL(String(input), window.location.origin),
                          init,
                      );
            const path = new URL(request.url).pathname;
            const text = await request.clone().text();
            const call: Call = {
                method: request.method,
                path,
                query: Object.fromEntries(new URL(request.url).searchParams),
                accept: request.headers.get('Accept'),
                body: text ? JSON.parse(text) : undefined,
            };

            calls.push(call);

            const reply = table[`${call.method} ${path}`];

            if (!reply) {
                throw new Error(`Unexpected request: ${call.method} ${path}`);
            }

            return typeof reply === 'function' ? reply(call) : reply.clone();
        }),
    );

    return {
        calls,
        called: (key: string) =>
            calls.filter((c) => `${c.method} ${c.path}` === key),
    };
}

/** Mounts the real router at `path`, with the real screens and a mocked network. */
export function renderApp(path: string) {
    const queryClient = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });
    const router = createAppRouter(
        queryClient,
        createMemoryHistory({ initialEntries: [path] }),
    );

    render(
        <I18nextProvider i18n={i18n}>
            <QueryClientProvider client={queryClient}>
                <RouterProvider router={router} />
            </QueryClientProvider>
        </I18nextProvider>,
    );

    return { router, queryClient };
}

export const signedOut = {
    'GET /api/v1/me': () =>
        json({ message: 'Unauthenticated.', code: 'UNAUTHENTICATED' }, 401),
};

export const workspace = {
    id: '01JWORKSPACE000000000000001',
    name: 'Toko Budi',
    company_id: '01JCOMPANY00000000000000001',
    company_name: 'PT Toko Budi',
    permissions: [],
};

export const company = {
    id: workspace.company_id,
    name: workspace.company_name,
    legal_name: null,
    tax_id: null,
    functional_currency: 'IDR',
    timezone: 'Asia/Jakarta',
    default_locale: 'id',
    status: 'setup',
    lock_version: 1,
};

/** A signed-in user without any workspace yet. */
export const signedIn = {
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () => json({ data: [] }),
};

/** A signed-in user with a single workspace, so `/` opens its company straight away. */
export const signedInWithWorkspace = {
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () => json({ data: [workspace] }),
    [`GET /api/v1/companies/${workspace.company_id}`]: () =>
        json({ data: company }),
};
