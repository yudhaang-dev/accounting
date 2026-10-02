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

const chooseAccount = async (
    page: Page,
    line: number,
    search: string,
    option: RegExp,
) => {
    await page
        .getByRole('combobox', { name: `Akun Baris ${line}` })
        .fill(search);
    await page.getByRole('option', { name: option }).click();
};

const fillEntry = async (
    page: Page,
    description: string,
    debit: string,
    credit: string,
) => {
    await page.getByLabel('Keterangan', { exact: true }).fill(description);
    await chooseAccount(page, 1, '1111', /^1111 · /);
    await page.getByLabel('Debit Baris 1').fill(debit);
    await chooseAccount(page, 2, 'bank oper', /^1112 · /);
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

        // Nothing to pick from, so the first step of the setup starts by itself.
        await expect(page).toHaveURL(/\/workspaces\/new$/);
        await expect(page.getByText('Langkah 1 dari 4')).toBeVisible();
        await shot(page, 'new-workspace-first-login');
    });

    await test.step('create a workspace with its company', async () => {
        await expect(
            page.getByRole('heading', { name: 'Ruang kerja baru' }),
        ).toBeVisible();
        await page.getByLabel('Nama ruang kerja').fill('Toko Budi');
        await page.getByLabel('Nama perusahaan').fill('PT Toko Budi');
        await page
            .getByRole('button', { name: 'Buat ruang kerja', exact: true })
            .click();

        await expect(
            page.getByRole('heading', { name: 'Menyiapkan PT Toko Budi' }),
        ).toBeVisible();
        await expect(page.getByText('Langkah 2 dari 4')).toBeVisible();
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

    await test.step('leaving and coming back lands on the wizard again, not the home', async () => {
        await page.goto('/');

        await expect(page).toHaveURL(/\/setup$/);
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
        // Activation locks the functional currency, so it asks first.
        await expect(page.getByRole('alertdialog')).toContainText(
            'mata uang fungsionalnya tidak dapat diubah',
        );
        await shot(page, 'confirm-activate');
        await page
            .getByRole('alertdialog')
            .getByRole('button', { name: 'Aktifkan', exact: true })
            .click();

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

        // The whole chart is one tree: a child sits further in than its parent.
        const indent = (name: string) =>
            page
                .locator('td span', { hasText: new RegExp(`^${name}$`) })
                .evaluate((el) =>
                    parseFloat(getComputedStyle(el).paddingInlineStart),
                );

        expect(await indent('Kas Kecil')).toBeGreaterThan(
            await indent('Aset Lancar'),
        );
        expect(await indent('Aset Lancar')).toBeGreaterThan(
            await indent('ASET'),
        );
        await shot(page, 'accounts');

        // Show every row.
        await page.getByRole('combobox', { name: 'Baris per halaman' }).click();
        await page.getByRole('option', { name: 'Semua' }).click();
        await expect(page.getByText('1–101 dari 101')).toBeVisible();

        // One actions menu per row.
        await page.getByRole('button', { name: 'Aksi untuk 1111' }).click();
        await expect(
            page.getByRole('menuitem', { name: 'Nonaktifkan' }),
        ).toBeVisible();
        await shot(page, 'row-actions');
        await page.keyboard.press('Escape');

        // The language is a menu; the choice is remembered across a reload.
        await page.getByRole('button', { name: 'Bahasa' }).click();
        await shot(page, 'language-menu');
        await page.getByRole('menuitem', { name: 'English' }).click();
        await expect(
            page.getByRole('heading', { name: 'Chart of accounts' }),
        ).toBeVisible();
        await page.reload();
        await expect(
            page.getByRole('heading', { name: 'Chart of accounts' }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Language' }).click();
        await page.getByRole('menuitem', { name: 'Indonesia' }).click();
        await expect(
            page.getByRole('heading', { name: 'Bagan akun' }),
        ).toBeVisible();

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

        // The key writes itself from the name; the user keeps the last word.
        await page.getByLabel('Nama jenis').fill('Jurnal Umum');
        await expect(page.getByLabel('Kunci')).toHaveValue('jurnal_umum');
        await page.getByLabel('Kunci').fill('general');
        await shot(page, 'transaction-type');
        await page.getByRole('button', { name: 'Buat jenis' }).click();

        await expect(
            page.getByLabel('Keterangan', { exact: true }),
        ).toBeVisible();
        await fillEntry(
            page,
            'Pindah dana kas ke bank',
            '1500000,50',
            '1500000,50',
        );
        await expect(page.getByText('Seimbang')).toBeVisible();
        // What was typed as 1500000,50 is shown grouped, and the date is written out.
        await expect(page.getByLabel('Debit Baris 1')).toHaveValue(
            '1.500.000,50',
        );
        await expect(
            page.getByRole('button', { name: /Tanggal akuntansi/ }),
        ).toContainText(
            new Date().toLocaleDateString('id-ID', {
                day: 'numeric',
                month: 'long',
                year: 'numeric',
                timeZone: 'Asia/Jakarta',
            }),
        );
        await shot(page, 'entry-form');

        // The calendar opens in a popover, on the month of the date shown.
        await page.getByRole('button', { name: /Tanggal akuntansi/ }).click();
        await expect(
            page.getByRole('button', { name: 'Hari ini', exact: true }),
        ).toBeVisible();
        await shot(page, 'date-picker-open');
        await page.keyboard.press('Escape');

        // The account list narrows as you type.
        await page.getByRole('combobox', { name: 'Akun Baris 2' }).fill('bank');
        await expect(
            page.getByRole('option', { name: /^1112 · / }),
        ).toBeVisible();
        await shot(page, 'account-combobox');
        await page.keyboard.press('Escape');

        await page.getByRole('button', { name: 'Simpan draf' }).click();

        await expect(
            page.getByRole('heading', { name: /^Entri / }),
        ).toBeVisible();
        await expect(status(page, 'Draf')).toBeVisible();
        // Entries show the name of their type, not its key.
        await expect(page.getByText('Jurnal Umum')).toBeVisible();
        await shot(page, 'entry-draft');
    });

    await test.step('submit, approve and post it', async () => {
        await page.getByRole('button', { name: 'Ajukan' }).click();
        await expect(status(page, 'Menunggu persetujuan')).toBeVisible();

        await page
            .getByRole('button', { name: 'Setujui', exact: true })
            .click();
        await page
            .getByRole('alertdialog')
            .getByRole('button', { name: 'Setujui', exact: true })
            .click();
        await expect(status(page, 'Disetujui')).toBeVisible();

        await page
            .getByRole('button', { name: 'Posting', exact: true })
            .click();
        await shot(page, 'confirm-post');
        await page
            .getByRole('alertdialog')
            .getByRole('button', { name: 'Posting', exact: true })
            .click();
        await expect(status(page, 'Diposting')).toBeVisible();

        // The history is stamped with the moment it happened: today in the user's zone, not the
        // same clock read in the database server's zone (which once put it hours in the past).
        const today = new Date().toLocaleDateString('id-ID', {
            timeZone: 'Asia/Jakarta',
        });

        await expect(
            page.getByText('Riwayat keputusan').locator('..').locator('..'),
        ).toContainText(today);
        // The trail leads back: company, the entries, then this entry's number.
        const trail = page.getByRole('navigation', {
            name: 'Navigasi halaman',
        });

        await expect(trail).toContainText('Toko Budi');
        await expect(trail.getByRole('link', { name: 'Entri' })).toBeVisible();
        await expect(trail).toContainText('ENT/');
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

        // Choose the end of the range from the calendar's own "today" and ask again.
        await page.getByRole('button', { name: /Sampai tanggal/ }).click();
        await page
            .getByRole('button', { name: 'Hari ini', exact: true })
            .click();
        await page.getByRole('button', { name: 'Tampilkan' }).click();
        await expect(page.getByText('Seimbang', { exact: true })).toBeVisible();
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
