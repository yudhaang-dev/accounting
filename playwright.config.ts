import { defineConfig, devices } from '@playwright/test';
import { appEnv, BASE_URL, PORT } from './e2e/support/env';
import { prepare } from './e2e/support/prepare';

// Workers load this file again; only the main process may rebuild the database.
if (process.env.TEST_WORKER_INDEX === undefined) {
    prepare();
}

/**
 * End-to-end tests: a real browser against the real Laravel app and a real PostgreSQL database
 * (`accounting_e2e`, rebuilt before every run), so they never touch development data. They are
 * separate from `pnpm test` (Vitest, mocked network) because they are slower.
 */
export default defineConfig({
    testDir: './e2e',
    outputDir: './test-results/e2e',
    // One scenario after another: they share one database and one session.
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    timeout: 60_000,
    expect: { timeout: 10_000 },
    use: {
        baseURL: BASE_URL,
        locale: 'id-ID',
        timezoneId: 'Asia/Jakarta',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'desktop',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1366, height: 800 },
            },
        },
    ],
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${PORT} --no-reload`,
        url: `${BASE_URL}/login`,
        env: appEnv,
        reuseExistingServer: false,
        timeout: 60_000,
    },
});
