import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { account, json, mockApi, renderApp, workspace } from '@/test/harness';

// Translation of a group (CO-FR-06): the policy that names three rate types and the equity account for the
// difference, the rate types, and the rates of the group.

const groupId = '01JGROUP00000000000000001';
const base = `/api/v1/groups/${groupId}`;

const group = (abilities: string[]) => ({
    id: groupId,
    name: 'Grup Acme',
    parent_company_id: workspace.company_id,
    presentation_currency: 'IDR',
    package_policy: 'draft',
    lock_version: 1,
    abilities,
});

const rateType = (id: string, code: string, name: string, active = true) => ({
    id,
    code,
    name,
    is_active: active,
    lock_version: 1,
});

const closing = rateType('01JRT0000000000000000001', 'closing', 'Kurs penutup');
const average = rateType(
    '01JRT0000000000000000002',
    'average',
    'Kurs rata-rata',
);
const historical = rateType(
    '01JRT0000000000000000003',
    'historical',
    'Kurs historis',
);
const equity = {
    id: '01JGACC00000000000000009',
    code: '3900',
    name: 'Selisih translasi',
    class: 'equity',
    cash_flow_class: null,
    is_active: true,
    lock_version: 1,
};

const emptyPolicy = {
    version: 0,
    effective_date: null,
    closing_rate_type_id: null,
    average_rate_type_id: null,
    historical_rate_type_id: null,
    difference_account_id: null,
    created_by: null,
};

const routes = (
    abilities: string[],
    policy: unknown = emptyPolicy,
    rates: unknown[] = [],
) => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions: [] }] }),
    'GET /api/v1/groups': () => json({ data: [group(abilities)] }),
    [`GET ${base}/translation-policy`]: () => json({ data: policy }),
    [`GET ${base}/rate-types`]: () =>
        json({ data: [closing, average, historical] }),
    [`GET ${base}/accounts`]: () => json({ data: [equity] }),
    [`GET ${base}/rates`]: () => json({ data: rates }),
});

async function choose(
    user: ReturnType<typeof userEvent.setup>,
    label: string,
    option: string,
) {
    await user.click(await screen.findByRole('combobox', { name: label }));
    await user.click(await screen.findByRole('option', { name: option }));
}

describe('group translation policy (CO-FR-06)', () => {
    it('saves the first policy as version 0 -> 1 once all four choices are made', async () => {
        const api = mockApi({
            ...routes(['read', 'run']),
            [`PUT ${base}/translation-policy`]: () =>
                json({
                    data: {
                        ...emptyPolicy,
                        version: 1,
                        effective_date: '2026-10-23',
                    },
                }),
        });

        renderApp(`/groups/${groupId}/translation`);
        const user = userEvent.setup();

        await screen.findByText('Belum ada kebijakan');
        const save = screen.getByRole('button', {
            name: 'Simpan sebagai versi baru',
        });

        expect(save.hasAttribute('disabled')).toBe(true);

        await choose(user, 'Kurs penutup', 'Kurs penutup');
        await choose(user, 'Kurs rata-rata', 'Kurs rata-rata');
        await choose(user, 'Kurs historis', 'Kurs historis');
        expect(save.hasAttribute('disabled')).toBe(true);

        await choose(
            user,
            'Akun selisih translasi',
            '3900 · Selisih translasi',
        );
        await user.click(save);

        await waitFor(() =>
            expect(api.called(`PUT ${base}/translation-policy`)).toHaveLength(
                1,
            ),
        );
        expect(
            api.called(`PUT ${base}/translation-policy`)[0]?.body,
        ).toMatchObject({
            lock_version: 0,
            closing_rate_type_id: closing.id,
            average_rate_type_id: average.id,
            historical_rate_type_id: historical.id,
            difference_account_id: equity.id,
        });
    });

    it('shows the version in force and sends it as lock_version', async () => {
        const api = mockApi({
            ...routes(['read', 'run'], {
                version: 2,
                effective_date: '2026-01-01',
                closing_rate_type_id: closing.id,
                average_rate_type_id: average.id,
                historical_rate_type_id: historical.id,
                difference_account_id: equity.id,
                created_by: 1,
            }),
            [`PUT ${base}/translation-policy`]: () =>
                json({
                    data: {
                        ...emptyPolicy,
                        version: 3,
                        effective_date: '2026-10-23',
                    },
                }),
        });

        renderApp(`/groups/${groupId}/translation`);
        const user = userEvent.setup();

        await screen.findByText(/Versi 2 berlaku sejak/);

        await choose(user, 'Kurs penutup', 'Kurs rata-rata');
        await user.click(
            screen.getByRole('button', { name: 'Simpan sebagai versi baru' }),
        );

        await waitFor(() =>
            expect(api.called(`PUT ${base}/translation-policy`)).toHaveLength(
                1,
            ),
        );
        expect(
            (
                api.called(`PUT ${base}/translation-policy`)[0]!.body as {
                    lock_version: number;
                }
            ).lock_version,
        ).toBe(2);
    });

    it('creates a rate type and records a rate with the canonical decimal', async () => {
        const api = mockApi({
            ...routes(['read', 'run']),
            [`POST ${base}/rate-types`]: () =>
                json(
                    {
                        data: rateType(
                            '01JRT0000000000000000004',
                            'budget',
                            'Kurs anggaran',
                        ),
                    },
                    201,
                ),
            [`POST ${base}/rates`]: () =>
                json(
                    {
                        data: {
                            id: '01JRATE000000000000000001',
                            rate_type_id: closing.id,
                            currency: 'USD',
                            effective_date: '2026-10-23',
                            rate: '15800.5',
                            version: 1,
                            source: 'manual',
                            created_by: 1,
                            created_at: null,
                        },
                    },
                    201,
                ),
        });

        renderApp(`/groups/${groupId}/translation`);
        const user = userEvent.setup();

        await user.click(
            await screen.findByRole('button', { name: 'Jenis kurs baru' }),
        );
        const typeDialog = await screen.findByRole('dialog');

        await user.type(within(typeDialog).getByLabelText('Kode'), 'budget');
        await user.type(
            within(typeDialog).getByLabelText('Nama'),
            'Kurs anggaran',
        );
        await user.click(
            within(typeDialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() =>
            expect(api.called(`POST ${base}/rate-types`)).toHaveLength(1),
        );
        expect(api.called(`POST ${base}/rate-types`)[0]?.body).toEqual({
            code: 'budget',
            name: 'Kurs anggaran',
        });

        await user.click(screen.getByRole('button', { name: 'Catat kurs' }));
        const rateDialog = await screen.findByRole('dialog');

        await user.type(within(rateDialog).getByLabelText('Mata uang'), 'usd');
        await user.type(within(rateDialog).getByLabelText('Kurs'), '15800,5');
        await user.click(
            within(rateDialog).getByRole('button', { name: 'Simpan' }),
        );

        await waitFor(() =>
            expect(api.called(`POST ${base}/rates`)).toHaveLength(1),
        );
        expect(api.called(`POST ${base}/rates`)[0]?.body).toMatchObject({
            rate_type_id: closing.id,
            currency: 'USD',
            rate: '15800.5',
        });
    });

    it('lists rates and hides every action from a user who may only read', async () => {
        mockApi(
            routes(['read'], emptyPolicy, [
                {
                    id: '01JRATE000000000000000001',
                    rate_type_id: closing.id,
                    currency: 'USD',
                    effective_date: '2026-09-30',
                    rate: '15800.5',
                    version: 1,
                    source: 'manual',
                    created_by: 1,
                    created_at: null,
                },
            ]),
        );

        renderApp(`/groups/${groupId}/translation`);

        expect(await screen.findByText('15.800,5')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Catat kurs' })).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Jenis kurs baru' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Simpan sebagai versi baru' }),
        ).toBeNull();
    });
});
