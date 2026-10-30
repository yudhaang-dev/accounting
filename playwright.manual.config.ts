import { defineConfig, devices } from '@playwright/test';

/**
 * Manual-book run: a real browser against the local Laragon site (`accounting.test`) and its own
 * database, nothing started or rebuilt here. `pnpm run manual:reset` empties the database first;
 * the specs under `e2e/manual` take the screenshots into `.docs/manual-book`.
 */
export default defineConfig({
    testDir: './e2e/manual',
    outputDir: './test-results/manual',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    timeout: 600_000,
    expect: { timeout: 15_000 },
    use: {
        baseURL: process.env.MANUAL_BASE_URL ?? 'http://accounting.test',
        locale: 'id-ID',
        timezoneId: 'Asia/Jakarta',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        // `--headed` on the command line opens the window; the pause makes each step readable.
        launchOptions: {
            slowMo: process.argv.includes('--headed') ? 350 : 0,
        },
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
});
