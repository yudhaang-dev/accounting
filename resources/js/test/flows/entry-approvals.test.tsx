import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { File as NodeFile } from 'node:buffer';
import { describe, expect, it, vi } from 'vitest';
import { confirmWith } from '@/test/actions';
import {
    account,
    company,
    json,
    mockApi,
    renderApp,
    workspace,
} from '@/test/harness';

type Routes = Parameters<typeof mockApi>[0];

const base = `/api/v1/companies/${workspace.company_id}`;
const entriesPath = `/w/${workspace.company_id}/entries`;
const approvalsPath = `/w/${workspace.company_id}/approvals`;

const withPermissions = (permissions: string[]): Routes => ({
    'GET /api/v1/me': () => json({ data: account }),
    'GET /api/v1/me/workspaces': () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: 'active' } }),
});

const cash = '01JACCOUNT0000000000000001';
const sales = '01JACCOUNT0000000000000002';

const entry = (overrides: Record<string, unknown> = {}) => ({
    id: '01JENTRY00000000000000001',
    number: 'JU-2026-0001',
    status: 'draft',
    source: 'manual',
    transaction_type_key: 'general',
    transaction_type_version: 1,
    accounting_date: '2026-10-02',
    document_date: null,
    description: 'Setoran modal',
    lines: [
        {
            account_id: cash,
            debit: '1500000',
            credit: '0',
            description: null,
            dimensions: {},
        },
        {
            account_id: sales,
            debit: '0',
            credit: '1500000',
            description: null,
            dimensions: {},
        },
    ],
    values: null,
    approval: null,
    created_by: 1,
    journal_id: null,
    journal_number: null,
    posted_at: null,
    decisions: [],
    lock_version: 1,
    ...overrides,
});

const page = (data: unknown[]) =>
    json({
        data,
        links: {},
        meta: { current_page: 1, last_page: 1, total: data.length },
    });

const attachment = (overrides: Record<string, unknown> = {}) => ({
    id: '01JFILE0000000000000000001',
    filename: 'invoice-oktober.pdf',
    mime_type: 'application/pdf',
    size_bytes: 1_468_006,
    sha256: 'abc',
    uploaded_by: 1,
    created_at: '2026-10-02T08:30:00+07:00',
    ...overrides,
});

const typeRoute: Routes = {
    [`GET ${base}/transaction-types`]: () =>
        json({
            data: [
                {
                    id: '01JTYPE000000000000000001',
                    key: 'general',
                    label: 'Jurnal Umum',
                    numbering: {
                        pattern: 'JU/{yyyy}/{seq}',
                        padding: 4,
                        reset: 'yearly',
                        policy: 'on_create',
                    },
                    versions: [
                        {
                            version: 1,
                            mode: 'free_lines',
                            status: 'published',
                            fields: null,
                            posting_rules: null,
                            allowed_currencies: null,
                            rate_type_key: null,
                            allow_rate_input: false,
                        },
                    ],
                    lock_version: 1,
                },
            ],
        }),
    [`GET ${base}/accounts`]: () => page([]),
};

/**
 * A multipart body built by jsdom's own FormData and File cannot go into the Request of the test runtime,
 * which is not a browser. These give the upload the runtime's own, so the call that leaves the screen is
 * the one the API would receive.
 */
async function runtimeFormData() {
    const sample = await new Response(
        new URLSearchParams({ a: '1' }),
    ).formData();

    vi.stubGlobal('FormData', sample.constructor);
}

const pdf = (name: string) =>
    new NodeFile(['%PDF-1.4'], name, {
        type: 'application/pdf',
    }) as unknown as File;

describe('entry attachments', () => {
    const detail = (
        permissions: string[],
        status: string,
        files: unknown[] = [attachment()],
        overrides: Routes = {},
    ) =>
        mockApi({
            ...withPermissions(permissions),
            ...typeRoute,
            [`GET ${base}/entries/${entry().id}`]: () =>
                json({ data: entry({ status }) }),
            [`GET ${base}/entries/${entry().id}/attachments`]: () =>
                json({ data: files }),
            ...overrides,
        });

    it('lists the files with their size', async () => {
        detail(['entries.read'], 'posted');

        renderApp(`${entriesPath}/${entry().id}`);

        expect(await screen.findByText('invoice-oktober.pdf')).toBeVisible();
        expect(screen.getByText(/1,4 MB/)).toBeVisible();
        // A posted entry is closed: nothing to add and nothing to remove.
        expect(
            screen.queryByRole('button', { name: 'Tambah lampiran' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'Lepas invoice-oktober.pdf',
            }),
        ).not.toBeInTheDocument();
    });

    it('downloads a file under its own name', async () => {
        let saved: string | undefined;

        URL.createObjectURL = vi.fn(() => 'blob:file');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
            function (this: HTMLAnchorElement) {
                saved = this.download;
            },
        );
        const fileUrl = `GET ${base}/entries/${entry().id}/attachments/${attachment().id}`;
        const api = detail(['entries.read'], 'posted', [attachment()], {
            [fileUrl]: () =>
                new Response('%PDF', {
                    headers: { 'Content-Type': 'application/pdf' },
                }),
        });

        renderApp(`${entriesPath}/${entry().id}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', {
                name: 'Unduh invoice-oktober.pdf',
            }),
        );

        await waitFor(() => expect(saved).toBe('invoice-oktober.pdf'));
        expect(api.called(fileUrl)).toHaveLength(1);
    });

    it('adds a file to a draft as a multipart upload', async () => {
        const upload = `POST ${base}/entries/${entry().id}/attachments`;
        await runtimeFormData();
        const api = detail(['entries.read', 'entries.create'], 'draft', [], {
            [upload]: () => json({ data: attachment() }, 201),
        });

        renderApp(`${entriesPath}/${entry().id}`);
        const typist = userEvent.setup();

        await screen.findByText(/Belum ada lampiran/);
        await typist.upload(
            screen.getByLabelText('Pilih berkas lampiran'),
            pdf('invoice-oktober.pdf'),
        );

        await waitFor(() => expect(api.called(upload)).toHaveLength(1));
        // The field is named `file` and carries the file under its own name.
        const body = String(api.called(upload)[0]?.body);

        expect(body).toContain('name="file"; filename="invoice-oktober.pdf"');
    });

    it('says why the server refused a file', async () => {
        await runtimeFormData();
        detail(['entries.read', 'entries.create'], 'draft', [], {
            [`POST ${base}/entries/${entry().id}/attachments`]: () =>
                json(
                    {
                        message: 'Data tidak valid.',
                        errors: {
                            file: ['Berkas tidak boleh lebih dari 10240 KB.'],
                        },
                    },
                    422,
                ),
        });

        renderApp(`${entriesPath}/${entry().id}`);
        const typist = userEvent.setup();

        await screen.findByText(/Belum ada lampiran/);
        await typist.upload(
            screen.getByLabelText('Pilih berkas lampiran'),
            pdf('besar.pdf'),
        );

        expect(
            await screen.findByText('Berkas tidak boleh lebih dari 10240 KB.'),
        ).toBeVisible();
    });

    it('takes a file off a draft only after confirmation', async () => {
        const del = `DELETE ${base}/entries/${entry().id}/attachments/${attachment().id}`;
        const api = detail(
            ['entries.read', 'entries.create'],
            'draft',
            [attachment()],
            {
                [del]: () => new Response(null, { status: 204 }),
            },
        );

        renderApp(`${entriesPath}/${entry().id}`);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', {
                name: 'Lepas invoice-oktober.pdf',
            }),
        );

        expect(api.called(del)).toHaveLength(0);

        await confirmWith(typist, 'Lepas lampiran');

        await waitFor(() => expect(api.called(del)).toHaveLength(1));
    });

    it('lets a submitted entry take files but not lose them', async () => {
        detail(['entries.read', 'entries.create'], 'submitted');

        renderApp(`${entriesPath}/${entry().id}`);

        expect(
            await screen.findByRole('button', { name: 'Tambah lampiran' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', {
                name: 'Lepas invoice-oktober.pdf',
            }),
        ).not.toBeInTheDocument();
    });
});

describe('approval inbox', () => {
    const waiting = (overrides: Record<string, unknown> = {}) =>
        entry({ status: 'submitted', ...overrides });

    const inbox = (
        permissions: string[],
        entries: unknown[],
        overrides: Routes = {},
    ) =>
        mockApi({
            ...withPermissions(permissions),
            ...typeRoute,
            [`GET ${base}/entries`]: () => page(entries),
            ...overrides,
        });

    const mine = ['entries.read', 'entries.approve', 'entries.reject'];

    it('shows the entries whose next stage the user can take', async () => {
        inbox(mine, [
            waiting({
                id: '01JENTRY0000000000000000A1',
                number: 'JU-A',
                created_by: 2,
                approval: {
                    stages: ['entries.approve'],
                    approved: 0,
                    auto_post: false,
                    separation_of_duties: true,
                },
            }),
            // Needs a permission the user lacks at its stage.
            waiting({
                id: '01JENTRY0000000000000000A2',
                number: 'JU-B',
                created_by: 2,
                approval: {
                    stages: ['entries.approve', 'entries.post'],
                    approved: 1,
                    auto_post: false,
                    separation_of_duties: false,
                },
            }),
            // Made by the user, and separation of duties is on.
            waiting({
                id: '01JENTRY0000000000000000A3',
                number: 'JU-C',
                created_by: account.id,
                approval: {
                    stages: ['entries.approve'],
                    approved: 0,
                    auto_post: false,
                    separation_of_duties: true,
                },
            }),
        ]);

        renderApp(approvalsPath);
        const typist = userEvent.setup();

        expect(await screen.findByText('JU-A')).toBeVisible();
        expect(screen.queryByText('JU-B')).not.toBeInTheDocument();
        expect(screen.queryByText('JU-C')).not.toBeInTheDocument();
        expect(screen.getByText('Tahap 1 dari 1')).toBeVisible();

        await typist.click(
            screen.getByRole('button', { name: 'Semua yang menunggu' }),
        );

        expect(await screen.findByText('JU-B')).toBeVisible();
        expect(screen.getByText('JU-C')).toBeVisible();
        expect(screen.getByText('Tahap 2 dari 2')).toBeVisible();
        // Entries that are not the user's to decide carry no action.
        expect(
            screen.queryByRole('button', { name: 'Setujui entri JU-B' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Setujui entri JU-A' }),
        ).toBeVisible();
    });

    it('approves after confirmation', async () => {
        const approve = `POST ${base}/entries/${entry().id}/approve`;
        const api = inbox(mine, [waiting({ created_by: 2 })], {
            [approve]: () => json({ data: waiting({ status: 'approved' }) }),
        });

        renderApp(approvalsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', {
                name: 'Setujui entri JU-2026-0001',
            }),
        );

        expect(api.called(approve)).toHaveLength(0);

        await confirmWith(typist, 'Setujui');

        await waitFor(() => expect(api.called(approve)).toHaveLength(1));
    });

    it('rejects with the reason', async () => {
        const reject = `POST ${base}/entries/${entry().id}/reject`;
        const api = inbox(mine, [waiting({ created_by: 2 })], {
            [reject]: () => json({ data: waiting({ status: 'rejected' }) }),
        });

        renderApp(approvalsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole('button', {
                name: 'Tolak entri JU-2026-0001',
            }),
        );
        const dialog = await screen.findByRole('dialog');

        await typist.type(
            within(dialog).getByLabelText('Alasan'),
            'Nominal tidak sesuai',
        );
        await typist.click(
            within(dialog).getByRole('button', { name: 'Tolak entri' }),
        );

        await waitFor(() => expect(api.called(reject)).toHaveLength(1));
        expect(api.called(reject)[0]?.body).toEqual({
            reason: 'Nominal tidak sesuai',
        });
    });

    it('says so when nothing is waiting', async () => {
        inbox(mine, []);

        renderApp(approvalsPath);

        expect(
            await screen.findByText('Tidak ada entri yang menunggu Anda'),
        ).toBeVisible();
    });

    it('is not offered to someone who cannot approve', async () => {
        inbox(['entries.read'], []);

        renderApp(approvalsPath);

        // They land on the company home instead of an inbox they could never act on.
        await waitFor(() =>
            expect(
                screen.queryByText('Kotak persetujuan', {
                    selector: 'h1',
                }),
            ).not.toBeInTheDocument(),
        );
    });
});
