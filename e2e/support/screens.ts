import type { Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const DIR = 'test-results/screens';

let counter = 0;

/**
 * Saves a full-page screenshot named in the order the scenario takes them (`01-name.png`). They are
 * for people to look at after a run, so they live under the ignored `test-results` folder.
 */
export async function shot(page: Page, name: string): Promise<void> {
    mkdirSync(DIR, { recursive: true });
    // Let loading skeletons and fade-ins finish: a screenshot should show the settled screen.
    await page.waitForLoadState('networkidle').catch(() => undefined);
    counter += 1;
    await page.screenshot({
        path: `${DIR}/${String(counter).padStart(2, '0')}-${name}.png`,
        fullPage: true,
        animations: 'disabled',
    });
}
