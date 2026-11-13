// Mixed load: reads and event writes at once. Run: k6 run tests/Performance/k6/mixed.js
import * as read from './read.js';
import * as write from './write.js';

export const options = {
    scenarios: {
        reads: { executor: 'constant-vus', vus: Number(__ENV.VUS || 10), duration: __ENV.DURATION || '2m', exec: 'reads' },
        events: { executor: 'constant-arrival-rate', rate: Number(__ENV.RATE || 10), timeUnit: '1s', duration: __ENV.DURATION || '2m', preAllocatedVUs: 20, maxVUs: 60, exec: 'events' },
    },
    thresholds: { ...read.options.thresholds, ...write.options.thresholds },
};

export function setup() {
    return { read: read.setup(), write: write.setup() };
}

export function reads(data) {
    read.default(data.read);
}

export function events(data) {
    write.default(data.write);
}
