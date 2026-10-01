import { expect, test, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { USER } from './support/env';
import { shot } from './support/screens';

/**
 * M1-10: the whole first slice in a real browser, against the real API and database.
 * Sign in, create a company, install its chart and calendar, activate it, then take one entry from
 * draft to a posted journal and read it back in the trial balance, with its CSV export.
 */

const sidebar = (page: Page, name: string) =>
    page.getByRole('link', { name, exact: true });

/** The status badge of an entry; the same word also appears in its decision history. */
const status = (page: Page, text: string) =>
    page.locator('[data-slot="badge"]', { hasText: text });

const chooseAccount = async (page: Page, line: number, option: RegExp) => {
    await page.getByRole('combobox', { name: `Akun Baris ${line}` }).click();
    await page.getByRole('option', { name: option }).click();
};

const fillEntry = async (
    page: Page,
    description: string,
    debit: string,
    credit: string,
) => {
    await page.getByLabel('Keterangan', { exact: true }).fill(description);
    await chooseAccount(page, 1, /^1111 · /);
    await page.getByLabel('Debit Baris 1').fill(debit);
    await chooseAccount(page, 2, /^1112 · /);
    await page.getByLabel('Kredit Baris 2').fill(credit);
};

test('from sign-in to a posted journal in the trial balance', async ({
    page,
}) => {
    await test.step('sign in as a user without any workspace', async () => {
        await page.goto('/');
        await expect(page).toHaveURL(/\/login/);
        await page.getByLabel('Email').fill(USER.email);
        await page
            .getByLabel('Kata sandi', { exact: true })
            .fill(USER.password);
        await page.getByRole('button', { name: 'Masuk', exact: true }).click();

        await expect(page).toHaveURL(/\/workspaces$/);
        await expect(page.getByText('Belum ada ruang kerja')).toBeVisible();
        await shot(page, 'workspaces-empty');
    });

    await test.step('create a workspace with its company', async () => {
        await page.getByRole('link', { name: 'Buat ruang kerja baru' }).click();
        await expect(
            page.getByRole('heading', { name: 'Ruang kerja baru' }),
        ).toBeVisible();
        await page.getByLabel('Nama ruang kerja').fill('Toko Budi');
        await page.getByLabel('Nama perusahaan').fill('PT Toko Budi');
        await shot(page, 'new-workspace');
        await page
            .getByRole('button', { name: 'Buat ruang kerja', exact: true })
            .click();

        await expect(
            page.getByRole('heading', { name: 'Menyiapkan PT Toko Budi' }),
        ).toBeVisible();
        await shot(page, 'setup-start');
    });

    await test.step('install the chart of accounts only after confirming it', async () => {
        const install = page.getByRole('button', { name: 'Pasang bagan akun' });

        await expect(install).toBeDisabled();
        await expect(
            page.getByText(/\d+ dari \d+ akun akan dibuat\./),
        ).toBeVisible();
        await shot(page, 'setup-chart-review');

        await page
            .getByRole('checkbox', {
                name: 'Saya telah meninjau daftar akun ini dan ingin memasangnya.',
            })
            .click();
        await install.click();

        await expect(page.getByText('Bagan akun terpasang')).toBeVisible();
    });

    await test.step('set up the calendar, then the first fiscal year', async () => {
        await page.getByRole('button', { name: 'Simpan kalender' }).click();
        await expect(page.getByLabel('Tahun mulai')).toBeVisible();
        await page.getByRole('button', { name: 'Buat tahun fiskal' }).click();

        await expect(page.getByText('Kalender fiskal siap')).toBeVisible();
        await shot(page, 'setup-ready');
    });

    await test.step('activate the company', async () => {
        await page.getByRole('button', { name: 'Aktifkan perusahaan' }).click();

        await expect(
            page.getByRole('heading', { name: 'PT Toko Budi' }),
        ).toBeVisible();
        await expect(page.getByText('Aktif', { exact: true })).toBeVisible();
        await shot(page, 'company-home');
    });

    await test.step('the chart and the periods are there', async () => {
        await sidebar(page, 'Akun').click();
        await expect(
            page.getByRole('heading', { name: 'Bagan akun' }),
        ).toBeVisible();
        await expect(page.getByText('Kas Kecil')).toBeVisible();
        await shot(page, 'accounts');

        await sidebar(page, 'Periode').click();
        await expect(
            page.getByRole('heading', { name: 'Periode fiskal' }),
        ).toBeVisible();
        await expect(page.getByText('Terbuka', { exact: true })).toHaveCount(
            12,
        );
        await shot(page, 'periods');
    });

    await test.step('create the first transaction type, then a draft entry', async () => {
        await sidebar(page, 'Entri').click();
        await expect(page.getByText('Belum ada entri')).toBeVisible();
        await page.getByRole('link', { name: 'Entri baru' }).first().click();

        await page.getByLabel('Kunci').fill('general');
        await page.getByLabel('Nama jenis').fill('Jurnal Umum');
        await shot(page, 'transaction-type');
        await page.getByRole('button', { name: 'Buat jenis' }).click();

        await expect(
            page.getByLabel('Keterangan', { exact: true }),
        ).toBeVisible();
        await fillEntry(
            page,
            'Pindah dana kas ke bank',
            '1500000.50',
            '1500000.50',
        );
        await expect(page.getByText('Seimbang')).toBeVisible();
        await shot(page, 'entry-form');
        await page.getByRole('button', { name: 'Simpan draf' }).click();

        await expect(
            page.getByRole('heading', { name: /^Entri / }),
        ).toBeVisible();
        await expect(status(page, 'Draf')).toBeVisible();
        await shot(page, 'entry-draft');
    });

    await test.step('submit, approve and post it', async () => {
        await page.getByRole('button', { name: 'Ajukan' }).click();
        await expect(status(page, 'Menunggu persetujuan')).toBeVisible();

        await page.getByRole('button', { name: 'Setujui' }).click();
        await expect(status(page, 'Disetujui')).toBeVisible();

        await page.getByRole('button', { name: 'Posting' }).click();
        await expect(status(page, 'Diposting')).toBeVisible();

        // The history is stamped with the moment it happened: today in the user's zone, not the
        // same clock read in the database server's zone (which once put it hours in the past).
        const today = new Date().toLocaleDateString('id-ID', {
            timeZone: 'Asia/Jakarta',
        });

        await expect(
            page.getByText('Riwayat keputusan').locator('..').locator('..'),
        ).toContainText(today);
        await shot(page, 'entry-posted');
    });

    await test.step('open the journal it produced', async () => {
        await page
            .getByRole('link', { name: /^Diposting sebagai jurnal / })
            .click();

        await expect(
            page.getByRole('heading', { name: /^Jurnal / }),
        ).toBeVisible();
        await expect(page.getByText('1111 · Kas Kecil')).toBeVisible();
        await expect(page.getByText('1112 · Bank Operasional')).toBeVisible();
        await expect(
            page.getByText(/tidak dapat diubah atau dihapus/),
        ).toBeVisible();
        await shot(page, 'journal');
    });

    await test.step('the server refuses an unbalanced entry, in words', async () => {
        await sidebar(page, 'Entri').click();
        await page.getByRole('link', { name: 'Entri baru' }).first().click();
        await fillEntry(page, 'Entri tidak seimbang', '100', '90');
        await expect(page.getByText(/^Selisih /)).toBeVisible();
        await page.getByRole('button', { name: 'Simpan draf' }).click();
        await page.getByRole('button', { name: 'Ajukan' }).click();

        await expect(page.getByRole('alert')).toContainText(/seimbang/i);
        await shot(page, 'entry-refused');
    });

    await test.step('the trial balance shows the posted journal and balances', async () => {
        await sidebar(page, 'Neraca saldo').click();
        await expect(
            page.getByRole('heading', { name: 'Neraca saldo' }),
        ).toBeVisible();
        await expect(page.getByText('Seimbang', { exact: true })).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Lihat jurnal akun 1111' }),
        ).toBeVisible();
        // The refused draft never reached the ledger: only the posted 1.500.000,50 is there.
        await expect(page.getByText('1.500.000,50').first()).toBeVisible();
        await shot(page, 'trial-balance');
    });

    await test.step('export the same report as CSV', async () => {
        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('button', { name: 'Ekspor CSV' }).click(),
        ]);

        expect(download.suggestedFilename()).toMatch(
            /^trial-balance_\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.csv$/,
        );

        const path = await download.path();
        const csv = readFileSync(path, 'utf8');

        expect(csv).toContain('Kas Kecil');
        expect(csv).toContain('1500000.50');
    });

    await test.step('drill down from an account to its journals', async () => {
        await page
            .getByRole('link', { name: 'Lihat jurnal akun 1111' })
            .click();

        await expect(page.getByText('Akun: 1111 · Kas Kecil')).toBeVisible();
        await expect(
            page.getByRole('link', { name: /^Buka jurnal / }),
        ).toHaveCount(1);
        await shot(page, 'journals-drilldown');
    });
});
