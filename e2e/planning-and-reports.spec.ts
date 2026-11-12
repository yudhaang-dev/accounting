import { expect, test, type Page } from '@playwright/test';
import { createActiveCompany } from './support/company';

/**
 * D3c: the two heavy screens besides the designer, in the production build: the report viewer
 * (definition list, template import, a run) and the plan grid editor (rows, cells, save).
 */

const sidebar = (page: Page, name: string) =>
    page.getByRole('link', { name, exact: true });

test('the report viewer imports a template and runs a report', async ({
    page,
}) => {
    const errors: string[] = [];

    page.on('pageerror', (error) => errors.push(error.message));

    await createActiveCompany(page);

    await sidebar(page, 'Laporan').click();
    await expect(
        page.getByRole('heading', { name: 'Laporan', level: 1 }),
    ).toBeVisible();
    await expect(page.getByText('Belum ada definisi laporan')).toBeVisible();

    await page.getByRole('button', { name: 'Impor template' }).first().click();
    await page
        .getByRole('checkbox', {
            name: 'Saya sudah membaca definisi ini dan ingin membuatnya untuk perusahaan ini.',
        })
        .click();
    await page.getByRole('button', { name: /^Impor \d+ definisi$/ }).click();
    await expect(page.getByText('Definisi laporan diimpor.')).toBeVisible();

    await page
        .getByRole('link', { name: /Neraca/ })
        .first()
        .click();
    await expect(
        page.getByRole('heading', { name: /Neraca/, level: 1 }),
    ).toBeVisible();
    await expect(page.getByText('Dalam IDR')).toBeVisible();

    expect(errors).toEqual([]);
});

test('the plan grid edits cells and keeps them after a reload', async ({
    page,
}) => {
    const errors: string[] = [];

    page.on('pageerror', (error) => errors.push(error.message));

    await createActiveCompany(page);

    await sidebar(page, 'Anggaran').click();
    await expect(
        page.getByRole('heading', { name: 'Anggaran dan proyeksi' }),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Rencana baru' }).first().click();
    await page.getByLabel('Nama rencana').fill('Anggaran Tahunan');
    await page.getByLabel('Mulai').fill(`${new Date().getFullYear()}-01-01`);
    await page.getByLabel('Selesai').fill(`${new Date().getFullYear()}-12-31`);
    await page.getByRole('button', { name: 'Simpan', exact: true }).click();
    await expect(
        page.getByText('Rencana Anggaran Tahunan dibuat.'),
    ).toBeVisible();

    await page
        .getByRole('button', { name: 'Versi baru untuk Anggaran Tahunan' })
        .click();
    await page.getByLabel('Nama versi').fill('Awal');
    await page.getByRole('button', { name: 'Buat versi' }).click();
    await expect(page.getByText('Versi Awal dibuat.')).toBeVisible();

    await expect(page.getByText('Versi ini belum punya baris.')).toBeVisible();

    const addRow = page.getByRole('form', { name: 'Tambah baris' });

    await addRow.getByRole('combobox', { name: 'Akun' }).click();
    await page.getByRole('option').first().click();
    await addRow.getByRole('button', { name: 'Tambah', exact: true }).click();

    const cell = page.getByRole('textbox', { name: /, / }).first();

    await cell.fill('1500000');
    await page.getByRole('button', { name: 'Simpan perubahan' }).click();
    await expect(page.getByText('Perubahan disimpan.')).toBeVisible();

    await page.reload();
    await expect(
        page.getByRole('heading', {
            name: 'Anggaran Tahunan · Awal',
            level: 1,
        }),
    ).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('textbox', { name: /, / }).first()).toHaveValue(
        /1\.500\.000/,
    );

    expect(errors).toEqual([]);
});
