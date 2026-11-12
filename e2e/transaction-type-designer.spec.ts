import { expect, test, type Page } from '@playwright/test';
import { createActiveCompany } from './support/company';

/**
 * D3b/D3c: the transaction type designer in the production build. Its account class picker once
 * froze the tab there while the dev server was fine, so every step checks the page still answers.
 */

/** A frozen tab never answers a script, so a short timeout turns a freeze into a failure. */
const alive = async (page: Page, step: string) => {
    const answered = await Promise.race([
        page.evaluate(() => true),
        new Promise<boolean>((resolve) =>
            setTimeout(() => resolve(false), 5_000),
        ),
    ]);

    expect(answered, `the tab stopped answering after: ${step}`).toBe(true);
};

test('the designer takes account classes on an account field without freezing', async ({
    page,
}) => {
    const errors: string[] = [];

    page.on('pageerror', (error) => errors.push(error.message));

    await createActiveCompany(page);

    await page
        .getByRole('link', { name: 'Jenis transaksi', exact: true })
        .click();
    await page.getByRole('button', { name: 'Jenis baru' }).click();
    await page.getByLabel('Nama jenis').fill('Biaya Operasional');
    await page.getByLabel('Kunci').fill('operating_expense');
    await page.getByRole('button', { name: 'Buat jenis' }).click();
    await alive(page, 'creating the type');

    await page
        .getByRole('button', { name: 'Aksi untuk Biaya Operasional' })
        .click();
    await page.getByRole('menuitem', { name: 'Buka perancang' }).click();
    await expect(
        page.getByRole('heading', { name: 'Biaya Operasional', level: 1 }),
    ).toBeVisible();
    await alive(page, 'opening the designer');

    await page.getByRole('button', { name: 'Versi baru' }).click();
    await page
        .getByRole('button', { name: 'Terstruktur', exact: true })
        .click();
    await alive(page, 'switching to structured mode');

    await page.getByRole('button', { name: 'Tambah field' }).click();
    await alive(page, 'adding a field');

    await page.getByRole('combobox', { name: 'Tipe field 1' }).click();
    await page.getByRole('option', { name: 'Akun', exact: true }).click();
    await alive(page, 'choosing the account type');

    await expect(
        page.getByText('Kelas akun yang boleh dipilih', { exact: false }),
    ).toBeVisible();
    await page.getByRole('checkbox', { name: 'Beban' }).click();
    await alive(page, 'ticking a class');
    await expect(page.getByRole('checkbox', { name: 'Beban' })).toBeChecked();

    await page.getByRole('checkbox', { name: 'Beban' }).click();
    await alive(page, 'clearing the class');
    await expect(
        page.getByRole('checkbox', { name: 'Beban' }),
    ).not.toBeChecked();

    // Round trip: the classes survive saving the draft and opening it again.
    await page.getByRole('checkbox', { name: 'Beban' }).click();
    await page.getByRole('checkbox', { name: 'Aset', exact: true }).click();
    await page.getByLabel('Nama field Field 1').fill('expense_account');
    await page.getByLabel('Label Field 1').fill('Akun biaya');
    await page.getByRole('button', { name: 'Simpan draf' }).click();
    await expect(page.getByText('Draf disimpan.')).toBeVisible();
    await alive(page, 'saving the draft');

    await page.reload();
    await alive(page, 'reloading the designer');
    await page
        .getByRole('button', { name: /Draf v2|v2/ })
        .first()
        .click();
    await expect(page.getByRole('checkbox', { name: 'Beban' })).toBeChecked();
    await expect(
        page.getByRole('checkbox', { name: 'Aset', exact: true }),
    ).toBeChecked();
    await expect(
        page.getByRole('checkbox', { name: 'Liabilitas' }),
    ).not.toBeChecked();
    await alive(page, 'reopening the saved draft');

    expect(errors).toEqual([]);
});
