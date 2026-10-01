import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

export const PORT = 8123;
export const BASE_URL = `http://127.0.0.1:${PORT}`;
export const E2E_DATABASE = 'accounting_e2e';

/** The signed-in user every scenario starts from (created by the global setup). */
export const USER = {
    name: 'Budi Santoso',
    email: 'budi@example.com',
    password: 'rahasia-e2e-123',
};

/** Reads `KEY=value` pairs of `.env` so the setup can reach PostgreSQL with the same credentials. */
function dotenv(): Record<string, string> {
    const values: Record<string, string> = {};

    try {
        for (const line of readFileSync(resolve('.env'), 'utf8').split(
            /\r?\n/,
        )) {
            const match = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);

            if (match?.[1] !== undefined && match[2] !== undefined) {
                values[match[1]] = match[2].replace(/^["']|["']$/g, '');
            }
        }
    } catch {
        // No .env: the process environment has to carry everything.
    }

    return values;
}

const file = dotenv();

/** Connection settings of the PostgreSQL server (shared with development, other database). */
export const db = {
    host: process.env.DB_HOST ?? file.DB_HOST ?? '127.0.0.1',
    port: process.env.DB_PORT ?? file.DB_PORT ?? '5432',
    user: process.env.DB_USERNAME ?? file.DB_USERNAME ?? '',
    password: process.env.DB_PASSWORD ?? file.DB_PASSWORD ?? '',
};

/**
 * Environment of the app under test. Variables of the process win over `.env` in Laravel, so this
 * points the app at the e2e database and keeps it from sending mail or touching queues.
 */
const inherited = Object.fromEntries(
    Object.entries(process.env).filter(
        (entry): entry is [string, string] => typeof entry[1] === 'string',
    ),
);

export const appEnv: Record<string, string> = {
    // PHP's built-in server needs PATH and the rest of the parent environment too.
    ...inherited,
    APP_ENV: 'local',
    APP_DEBUG: 'false',
    APP_URL: BASE_URL,
    DB_CONNECTION: 'pgsql',
    DB_HOST: db.host,
    DB_PORT: db.port,
    DB_DATABASE: E2E_DATABASE,
    DB_USERNAME: db.user,
    DB_PASSWORD: db.password,
    SESSION_DRIVER: 'database',
    SESSION_DOMAIN: 'null',
    CACHE_STORE: 'array',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'log',
    LOG_CHANNEL: 'stderr',
};
