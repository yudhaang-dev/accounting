import type { Page } from '@playwright/test';
import { mkdirSync, appendFileSync } from 'node:fs';

export const MANUAL_DIR = '.docs/manual-book';

/** The user every manual-book run signs in as (created by `manual:reset`). */
export const USER = {
    name: 'Budi Santoso',
    email: 'budi@example.com',
    password: 'rahasia-e2e-123',
};

const counters = new Map<string, number>();

/**
 * Saves a screenshot for a chapter of the manual book as `<chapter>/NN-name.png`, numbered in the
 * order the scenario takes them. `full` keeps the whole page; the default is the visible window,
 * which reads better in a manual.
 */
export async function manualShot(
    page: Page,
    chapter: string,
    name: string,
    full = false,
): Promise<void> {
    const dir = `${MANUAL_DIR}/${chapter}`;

    mkdirSync(dir, { recursive: true });
    // Let loading skeletons and fade-ins finish so the picture shows the settled screen.
    await page.waitForLoadState('networkidle').catch(() => undefined);
    const next = (counters.get(chapter) ?? 0) + 1;

    counters.set(chapter, next);
    await page.screenshot({
        path: `${dir}/${String(next).padStart(2, '0')}-${name}.png`,
        fullPage: full,
        animations: 'disabled',
    });
}

/** Appends a finding to the findings log while the scenario keeps going. */
export function finding(chapter: string, text: string): void {
    mkdirSync(MANUAL_DIR, { recursive: true });
    appendFileSync(
        `${MANUAL_DIR}/TEMUAN.md`,
        `- [${chapter}] ${text}\n`,
        'utf8',
    );
}
