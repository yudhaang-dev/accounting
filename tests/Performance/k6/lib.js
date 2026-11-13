// Shared by the k6 scenarios of D3e (tests/Performance/README.md). Reads the manifest of the volume seeder.
import http from 'k6/http';
import encoding from 'k6/encoding';
import { check, fail } from 'k6';

export const BASE = __ENV.BASE_URL || 'http://localhost';
export const MANIFEST = JSON.parse(open(__ENV.MANIFEST || '../../../storage/app/perf-manifest.json'));

// Proposed p95 targets of D3e (milliseconds). Not agreed yet; they are the thresholds of a run, nothing more.
export const TARGET = {
    list: 300,
    post: 300,
    trialBalance: 1000,
    ledger: 1000,
    statement: 2000,
    aging: 2000,
    reconciliation: 2000,
    export: 10000,
};

export function company(index = 0) {
    return MANIFEST.companies[index % MANIFEST.companies.length];
}

export function url(c, path) {
    return `${BASE}/api/v1/companies/${c.company_id}${path}`;
}

const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' };

function xsrf() {
    const cookies = http.cookieJar().cookiesForURL(BASE);
    const value = cookies['XSRF-TOKEN'] && cookies['XSRF-TOKEN'][0];

    return value ? decodeURIComponent(value) : '';
}

/** Signs a user in the way the SPA does (CSRF cookie, then the Fortify login) and returns the session cookies. */
export function login(email, password) {
    http.get(`${BASE}/api/v1/csrf-cookie`, { headers: { Accept: 'application/json' } });
    const response = http.post(`${BASE}/login`, JSON.stringify({ email, password }), {
        headers: { ...JSON_HEADERS, 'X-XSRF-TOKEN': xsrf() },
    });

    if (response.status !== 200 && response.status !== 204) {
        fail(`login answered ${response.status}: ${response.body}`);
    }

    return http.cookieJar().cookiesForURL(BASE);
}

/** Gives a virtual user the session that `setup()` created. All users share one session: reads only. */
export function useSession(cookies) {
    const jar = http.cookieJar();

    for (const name of Object.keys(cookies)) {
        jar.set(BASE, name, cookies[name][0]);
    }
}

export function get(name, address, accept = 'application/json') {
    const response = http.get(address, { headers: { Accept: accept }, tags: { name } });
    check(response, { [`${name} 200`]: (r) => r.status === 200 });

    return response;
}

/** An access token of the integration client of a company (client credentials). */
export function clientToken(c) {
    const credentials = `${c.events.client_id}:${c.events.client_secret}`;
    const response = http.post(`${BASE}/api/v1/oauth/token`, { grant_type: 'client_credentials', scope: 'financial-events:write financial-events:read:self' }, {
        headers: { Authorization: `Basic ${encoding.b64encode(credentials)}`, Accept: 'application/json' },
    });

    if (response.status !== 200) {
        fail(`token answered ${response.status}: ${response.body}`);
    }

    return response.json('access_token');
}

export function period(c, index) {
    return c.periods[index % c.periods.length];
}

/** First and last day of the books, as `from`/`to`. */
export function wholeRange(c) {
    return { from: c.periods[0].start, to: c.periods[c.periods.length - 1].end };
}

/** One fiscal year of the books (twelve monthly periods per year). */
export function year(c, n = 0) {
    const first = c.periods[(n * 12) % c.periods.length];
    const last = c.periods[Math.min(((n * 12) % c.periods.length) + 11, c.periods.length - 1)];

    return { from: first.start, to: last.end };
}
