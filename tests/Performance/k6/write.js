// Writes: financial events from an integration client (the machine path, TE-FR-12), each with its own source key.
// Run: k6 run tests/Performance/k6/write.js
import http from 'k6/http';
import { check } from 'k6';
import { TARGET, company, url, clientToken, MANIFEST } from './lib.js';

const RATE = Number(__ENV.RATE || 20);
const DURATION = __ENV.DURATION || '2m';

export const options = {
    scenarios: {
        events: { executor: 'constant-arrival-rate', rate: RATE, timeUnit: '1s', duration: DURATION, preAllocatedVUs: 20, maxVUs: 100 },
    },
    thresholds: {
        'http_req_duration{name:financial-event}': [`p(95)<${TARGET.post}`],
        http_req_failed: ['rate<0.01'],
    },
};

export function setup() {
    return { tokens: MANIFEST.companies.map((c) => clientToken(c)) };
}

export default function (data) {
    const index = __VU % MANIFEST.companies.length;
    const c = company(index);
    const run = `${Date.now()}-${__VU}-${__ITER}`;
    const body = {
        transaction_type: { key: c.events.type_key, version: c.events.type_version },
        accounting_date: c.periods[1].start,
        currency: 'IDR',
        party: c.party_id,
        amounts: { total: String(100000 + (__ITER % 50) * 1000) },
        references: { source_transaction_id: `K6-${run}`, source_document_number: `K6/${run}` },
    };

    const response = http.post(url(c, '/financial-events'), JSON.stringify(body), {
        headers: { Authorization: `Bearer ${data.tokens[index]}`, 'Content-Type': 'application/json', Accept: 'application/json', 'Idempotency-Key': `k6-${run}` },
        tags: { name: 'financial-event' },
    });
    check(response, { 'event accepted': (r) => r.status === 201 || r.status === 200 || r.status === 202 });
}
