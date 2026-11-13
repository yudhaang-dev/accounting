// Reads: lists and filters, trial balance, ledger, statements, aging, export. Run: k6 run tests/Performance/k6/read.js
// Needs API_RATE_LIMIT_USER raised on the server under test (the default of 120 a minute refuses a load test).
import { sleep } from 'k6';
import { TARGET, company, url, login, useSession, get, MANIFEST, year, wholeRange, period } from './lib.js';

const VUS = Number(__ENV.VUS || 10);
const DURATION = __ENV.DURATION || '2m';

export const options = {
    scenarios: {
        reads: { executor: 'constant-vus', vus: VUS, duration: DURATION },
    },
    thresholds: {
        'http_req_duration{name:journals}': [`p(95)<${TARGET.list}`],
        'http_req_duration{name:journals-filtered}': [`p(95)<${TARGET.list}`],
        'http_req_duration{name:open-items}': [`p(95)<${TARGET.list}`],
        'http_req_duration{name:trial-balance}': [`p(95)<${TARGET.trialBalance}`],
        'http_req_duration{name:general-ledger}': [`p(95)<${TARGET.ledger}`],
        'http_req_duration{name:income_statement}': [`p(95)<${TARGET.statement}`],
        'http_req_duration{name:balance_sheet}': [`p(95)<${TARGET.statement}`],
        'http_req_duration{name:cash_flow_indirect}': [`p(95)<${TARGET.statement}`],
        'http_req_duration{name:aging}': [`p(95)<${TARGET.aging}`],
        'http_req_duration{name:trial-balance-csv}': [`p(95)<${TARGET.export}`],
        checks: ['rate>0.99'],
    },
};

export function setup() {
    const sessions = MANIFEST.companies.map((c) => login(c.email, MANIFEST.password));
    const definitions = MANIFEST.companies.map((c, i) => {
        useSession(sessions[i]);
        const list = get('report-definitions', url(c, '/report-definitions')).json('data');

        return Object.fromEntries(list.map((d) => [d.key, d.id]));
    });

    return { sessions, definitions };
}

export default function (data) {
    const index = (__VU - 1) % MANIFEST.companies.length;
    const c = company(index);
    useSession(data.sessions[index]);

    const range = year(c, __ITER % 3);
    const q = `from=${range.from}&to=${range.to}`;
    const p = period(c, __ITER);

    get('journals', url(c, '/journals?per_page=50'));
    get('journals-filtered', url(c, `/journals?per_page=50&filter[from]=${p.start}&filter[to]=${p.end}`));
    get('open-items', url(c, '/open-items?per_page=50&filter[status]=open'));
    get('trial-balance', url(c, `/reports/trial-balance?${q}`));
    get('general-ledger', url(c, `/reports/general-ledger?account_id=${c.accounts.cash}&${q}`));
    get('aging', url(c, `/reports/aging?as_of=${range.to}&control_role=receivables_control`));

    for (const [key, query] of [['income_statement', q], ['balance_sheet', `from=${range.to}&to=${range.to}`], ['cash_flow_indirect', q]]) {
        if (data.definitions[index][key]) {
            get(key, url(c, `/report-definitions/${data.definitions[index][key]}/result?${query}`));
        }
    }

    // Export of the largest report: the whole range as CSV.
    const whole = wholeRange(c);
    get('trial-balance-csv', url(c, `/reports/trial-balance?from=${whole.from}&to=${whole.to}`), 'text/csv');

    sleep(1);
}
