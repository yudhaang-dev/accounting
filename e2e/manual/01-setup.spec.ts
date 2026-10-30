import { expect, test, type Page } from '@playwright/test';
import { manualShot, USER } from './support';

/**
 * Manual book, chapter 1: setting a company up from an empty database, as the accountant does it
 * (PANDUAN-AKUNTAN-SETUP, parts 1 to 4). Run `pnpm run manual:reset` first.
 */

const C = '01-penyiapan';
const shot = (page: Page, name: string, full = false) =>
    manualShot(page, C, name, full);

test('company setup from an empty database', async ({ page }) => {
    await test.step('sign in', async () => {
        await page.goto('/');
        await expect(page).toHaveURL(/\/login/);
        await shot(page, 'masuk');
        await page.getByLabel('Email').fill(USER.email);
        await page
            .getByLabel('Kata sandi', { exact: true })
            .fill(USER.password);
        await page.getByRole('button', { name: 'Masuk', exact: true }).click();
        await expect(page).toHaveURL(/\/workspaces\/new$/);
    });

    await test.step('workspace and company profile', async () => {
        await expect(page.getByText('Langkah 1 dari 4')).toBeVisible();
        await page.getByLabel('Nama ruang kerja').fill('Sinar Nusantara');
        await page
            .getByLabel('Nama perusahaan')
            .fill('PT Sinar Nusantara Perkasa');
        await shot(page, 'ruang-kerja-baru');
        await page
            .getByRole('button', { name: 'Buat ruang kerja', exact: true })
            .click();
        await expect(
            page.getByRole('heading', {
                name: 'Menyiapkan PT Sinar Nusantara Perkasa',
            }),
        ).toBeVisible();
    });

    await test.step('chart of accounts: review, confirm, install', async () => {
        await expect(
            page.getByText(/\d+ dari \d+ akun akan dibuat\./),
        ).toBeVisible();
        await shot(page, 'bagan-akun-tinjau');
        await page
            .getByRole('checkbox', {
                name: 'Saya telah meninjau daftar akun ini dan ingin memasangnya.',
            })
            .click();
        await shot(page, 'bagan-akun-konfirmasi');
        await page.getByRole('button', { name: 'Pasang bagan akun' }).click();
        await expect(page.getByText('Bagan akun terpasang')).toBeVisible();
        await shot(page, 'bagan-akun-terpasang');
    });

    await test.step('fiscal calendar', async () => {
        await page.getByRole('button', { name: 'Simpan kalender' }).click();
        await expect(page.getByLabel('Tahun mulai')).toBeVisible();
        await shot(page, 'kalender-tahun-fiskal');
        await page.getByRole('button', { name: 'Buat tahun fiskal' }).click();
        await expect(page.getByText('Kalender fiskal siap')).toBeVisible();
        await shot(page, 'penyiapan-siap');
    });

    await test.step('activate the company', async () => {
        await page.getByRole('button', { name: 'Aktifkan perusahaan' }).click();
        await expect(page.getByRole('alertdialog')).toContainText(
            'mata uang fungsionalnya tidak dapat diubah',
        );
        await shot(page, 'aktivasi-konfirmasi');
        await page
            .getByRole('alertdialog')
            .getByRole('button', { name: 'Aktifkan', exact: true })
            .click();
        await expect(
            page.getByRole('heading', { name: 'PT Sinar Nusantara Perkasa' }),
        ).toBeVisible();
        await expect(page.getByText('Aktif', { exact: true })).toBeVisible();
        await shot(page, 'beranda-perusahaan');
    });
});
