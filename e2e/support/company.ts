import { expect, type Page } from '@playwright/test';
import { USER } from './env';

/**
 * Signs in as the seeded user and sets a company up through the wizard (chart only, calendar, first
 * fiscal year, activation), leaving the page on the company home. Scenarios that need a working
 * company but are about something else start here instead of repeating the M1 flow.
 */
export async function createActiveCompany(
    page: Page,
    name = 'PT Contoh Maju',
): Promise<void> {
    await page.goto('/');
    await expect(page).toHaveURL(/\/login/);
    await page.getByLabel('Email').fill(USER.email);
    await page.getByLabel('Kata sandi', { exact: true }).fill(USER.password);
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();

    // Signing in redirects; going elsewhere before it finishes would drop the session.
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));

    // The scenarios share one user, so an earlier one may have left a workspace behind: always
    // start a new one instead of relying on the empty-account redirect.
    await page.goto('/workspaces/new');
    await expect(page).toHaveURL(/\/workspaces\/new$/);
    await page.getByLabel('Nama ruang kerja').fill(name);
    await page.getByLabel('Nama perusahaan').fill(name);
    await page
        .getByRole('button', { name: 'Buat ruang kerja', exact: true })
        .click();

    await expect(
        page.getByRole('heading', { name: `Menyiapkan ${name}` }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Hanya bagan akun' }).click();
    await page
        .getByRole('checkbox', {
            name: 'Saya telah meninjau daftar akun ini dan ingin memasangnya.',
        })
        .click();
    await page.getByRole('button', { name: 'Pasang bagan akun' }).click();
    await expect(page.getByText('Bagan akun terpasang')).toBeVisible();

    await page.getByRole('button', { name: 'Simpan kalender' }).click();
    await page.getByRole('button', { name: 'Buat tahun fiskal' }).click();
    await expect(page.getByText('Kalender fiskal siap')).toBeVisible();

    await page.getByRole('button', { name: 'Aktifkan perusahaan' }).click();
    await page
        .getByRole('alertdialog')
        .getByRole('button', { name: 'Aktifkan', exact: true })
        .click();
    await expect(page.getByRole('heading', { name })).toBeVisible();
}
