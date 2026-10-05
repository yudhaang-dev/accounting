import { screen, waitFor, within } from "@testing-library/react";
import { confirmDialog, confirmWith, rowAction } from "@/test/actions";
import { cleanup } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import {
    account,
    company,
    json,
    mockApi,
    renderApp,
    workspace,
} from "@/test/harness";

const base = `/api/v1/companies/${workspace.company_id}`;

const withPermissions = (permissions: string[]) => ({
    "GET /api/v1/me": () => json({ data: account }),
    "GET /api/v1/me/workspaces": () =>
        json({ data: [{ ...workspace, permissions }] }),
    [`GET ${base}`]: () => json({ data: { ...company, status: "active" } }),
});

const row = (overrides: Record<string, unknown>) => ({
    id: "01JACCOUNT0000000000000001",
    code: "1111",
    name: "Kas Kecil",
    class: "asset",
    normal_balance: "debit",
    parent_id: null,
    is_leaf: true,
    status: "active",
    allowed_sources: ["entry", "subledger", "system"],
    dimension_requirements: {},
    currency_restriction: "any",
    restricted_currency: null,
    is_revaluable: false,
    lock_version: 1,
    ...overrides,
});

const postingDefaults = {
    allowed_sources: ["entry", "subledger", "system"],
    currency_restriction: "any",
    restricted_currency: null,
    is_revaluable: false,
};

const list = (data: unknown[]) =>
    json({
        data,
        links: {},
        meta: { current_page: 1, last_page: 1, total: data.length },
    });

const id1 = "01JACCOUNT0000000000000001";
const id2 = "01JACCOUNT0000000000000002";
const id3 = "01JACCOUNT0000000000000003";
const id4 = "01JACCOUNT0000000000000004";
const liability = row({
    id: id4,
    code: "2000",
    name: "LIABILITAS",
    class: "liability",
    normal_balance: "credit",
    is_leaf: false,
});

const accounts = [
    row({ id: id1, code: "1000", name: "ASET", is_leaf: false }),
    row({ id: id2, code: "1111", name: "Kas Kecil", parent_id: id1 }),
    row({ id: id3, code: "1113", name: "Bank Khusus", status: "inactive" }),
];

const accountsPath = `/w/${workspace.company_id}/accounts`;
const periodsPath = `/w/${workspace.company_id}/periods`;

describe("chart of accounts", () => {
    it("lists the accounts, marking parents and inactive ones", async () => {
        mockApi({
            ...withPermissions(["accounts.read"]),
            [`GET ${base}/accounts`]: () => list(accounts),
        });

        renderApp(accountsPath);

        expect(await screen.findByText("Kas Kecil")).toBeInTheDocument();
        expect(screen.getByText("1–3 dari 3")).toBeInTheDocument();
    });

    it("indents each account under its parent and sets parents apart", async () => {
        mockApi({
            ...withPermissions(["accounts.read"]),
            [`GET ${base}/accounts`]: () => list(accounts),
        });

        renderApp(accountsPath);

        const child = await screen.findByText("Kas Kecil");
        const parent = screen.getByText("ASET");

        expect(parent).toHaveStyle({ paddingInlineStart: "0px" });
        expect(parent.className).toMatch(/font-medium/);
        expect(child).toHaveStyle({ paddingInlineStart: "20px" });
        expect(child.className).not.toMatch(/font-medium/);
    });

    it("keeps every account at its own level when the chart is filtered", async () => {
        mockApi({
            ...withPermissions(["accounts.read"]),
            [`GET ${base}/accounts`]: () => list(accounts),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByRole("searchbox", {
                name: "Cari kode atau nama",
            }),
            "kas",
        );

        // The parent is out of the view, yet the child is still one level in.
        expect(screen.queryByText("ASET")).not.toBeInTheDocument();
        expect(screen.getByText("Kas Kecil")).toHaveStyle({
            paddingInlineStart: "20px",
        });
    });

    it("pages the chart in the browser, with a choice of rows and all of them", async () => {
        const many = Array.from({ length: 30 }, (_, n) =>
            row({
                id: `01JMANY${String(n).padStart(19, "0")}`,
                code: String(2000 + n),
                name: `Akun ${n}`,
            }),
        );

        mockApi({
            ...withPermissions(["accounts.read"]),
            [`GET ${base}/accounts`]: () => list(many),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        // The default shows the whole of a small chart.
        expect(await screen.findByText("1–30 dari 30")).toBeInTheDocument();

        await typist.click(
            screen.getByRole("combobox", { name: "Baris per halaman" }),
        );
        await typist.click(await screen.findByRole("option", { name: "10" }));

        expect(screen.getByText("1–10 dari 30")).toBeInTheDocument();
        expect(screen.getByText("Halaman 1 dari 3")).toBeInTheDocument();
        expect(screen.queryByText("Akun 10")).not.toBeInTheDocument();

        await typist.click(screen.getByRole("button", { name: "Berikutnya" }));
        expect(screen.getByText("11–20 dari 30")).toBeInTheDocument();
        expect(screen.getByText("Akun 10")).toBeInTheDocument();

        await typist.click(
            screen.getByRole("combobox", { name: "Baris per halaman" }),
        );
        await typist.click(
            await screen.findByRole("option", { name: "Semua" }),
        );

        expect(screen.getByText("1–30 dari 30")).toBeInTheDocument();
        expect(screen.getByText("Akun 29")).toBeInTheDocument();
    });

    it("remembers how many rows to show", async () => {
        const many = Array.from({ length: 30 }, (_, n) =>
            row({
                id: `01JMANY${String(n).padStart(19, "0")}`,
                code: String(2000 + n),
                name: `Akun ${n}`,
            }),
        );

        mockApi({
            ...withPermissions(["accounts.read"]),
            [`GET ${base}/accounts`]: () => list(many),
        });

        const first = renderApp(accountsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole("combobox", { name: "Baris per halaman" }),
        );
        await typist.click(await screen.findByRole("option", { name: "25" }));
        expect(screen.getByText("1–25 dari 30")).toBeInTheDocument();
        first.queryClient.clear();
        cleanup();

        renderApp(accountsPath);
        expect(await screen.findByText("1–25 dari 30")).toBeInTheDocument();
    });

    it("offers no editing to someone who may only read", async () => {
        mockApi({
            ...withPermissions(["accounts.read"]),
            [`GET ${base}/accounts`]: () => list(accounts),
        });

        renderApp(accountsPath);

        await screen.findByText("Kas Kecil");
        expect(
            screen.queryByRole("button", { name: "Tambah akun" }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: /^Ubah/ }),
        ).not.toBeInTheDocument();
    });

    it("sends someone without the permission back to the company home", async () => {
        mockApi({ ...withPermissions([]) });

        const { router } = renderApp(accountsPath);

        await screen.findByRole("heading", { name: "PT Toko Budi" });
        expect(router.state.location.pathname).toBe(
            `/w/${workspace.company_id}`,
        );
        expect(
            screen.queryByRole("link", { name: "Akun" }),
        ).not.toBeInTheDocument();
    });

    it("only shows menu entries the user may open", async () => {
        mockApi({ ...withPermissions(["accounts.read"]) });

        renderApp(`/w/${workspace.company_id}`);

        expect(
            await screen.findByRole("link", { name: "Akun" }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole("link", { name: "Periode" }),
        ).not.toBeInTheDocument();
    });

    it("narrows the chart by what is typed, in the browser", async () => {
        mockApi({
            ...withPermissions(["accounts.read"]),
            [`GET ${base}/accounts`]: () => list(accounts),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await typist.type(
            await screen.findByRole("searchbox", {
                name: "Cari kode atau nama",
            }),
            "kas",
        );

        expect(screen.getByText("Kas Kecil")).toBeInTheDocument();
        expect(screen.queryByText("Bank Khusus")).not.toBeInTheDocument();
        expect(screen.getByText("1–1 dari 1")).toBeInTheDocument();
    });

    it("adds a contra account of another class under a parent", async () => {
        const api = mockApi({
            ...withPermissions(["accounts.read", "accounts.manage"]),
            [`GET ${base}/accounts`]: () => list([...accounts, liability]),
            [`POST ${base}/accounts`]: () =>
                json({ data: row({ code: "2100", name: "Utang" }) }, 201),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole("button", { name: "Tambah akun" }),
        );
        const dialog = await screen.findByRole("dialog");

        await typist.type(within(dialog).getByLabelText("Kode"), "2100");
        await typist.type(within(dialog).getByLabelText("Nama akun"), "Utang");

        await typist.click(
            within(dialog).getByRole("combobox", { name: "Kelas" }),
        );
        await typist.click(
            await screen.findByRole("option", { name: "Liabilitas" }),
        );
        await typist.click(
            within(dialog).getByRole("combobox", { name: "Saldo normal" }),
        );
        await typist.click(
            await screen.findByRole("option", { name: "Kredit" }),
        );
        await typist.click(
            within(dialog).getByRole("combobox", { name: "Akun induk" }),
        );
        await typist.click(
            await screen.findByRole("option", { name: "2000 · LIABILITAS" }),
        );
        await typist.click(
            within(dialog).getByRole("button", { name: "Simpan" }),
        );

        await waitFor(() =>
            expect(api.called(`POST ${base}/accounts`)).toHaveLength(1),
        );
        expect(api.called(`POST ${base}/accounts`)[0]?.body).toEqual({
            code: "2100",
            name: "Utang",
            class: "liability",
            normal_balance: "credit",
            parent_id: id4,
            ...postingDefaults,
        });
    });

    it("forgets the parent when the class changes", async () => {
        const api = mockApi({
            ...withPermissions(["accounts.read", "accounts.manage"]),
            [`GET ${base}/accounts`]: () => list(accounts),
            [`POST ${base}/accounts`]: () =>
                json({ data: row({ code: "2100", name: "Utang" }) }, 201),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole("button", { name: "Tambah akun" }),
        );
        const dialog = await screen.findByRole("dialog");

        await typist.type(within(dialog).getByLabelText("Kode"), "2100");
        await typist.type(within(dialog).getByLabelText("Nama akun"), "Utang");
        await typist.click(
            within(dialog).getByRole("combobox", { name: "Akun induk" }),
        );
        await typist.click(
            await screen.findByRole("option", { name: "1000 · ASET" }),
        );
        // Switching class drops the parent: a parent must share the class of its children.
        await typist.click(
            within(dialog).getByRole("combobox", { name: "Kelas" }),
        );
        await typist.click(
            await screen.findByRole("option", { name: "Liabilitas" }),
        );
        await typist.click(
            within(dialog).getByRole("button", { name: "Simpan" }),
        );

        await waitFor(() =>
            expect(api.called(`POST ${base}/accounts`)).toHaveLength(1),
        );
        expect(api.called(`POST ${base}/accounts`)[0]?.body).toMatchObject({
            class: "liability",
            parent_id: null,
        });
    });

    it("adds an account", async () => {
        const api = mockApi({
            ...withPermissions(["accounts.read", "accounts.manage"]),
            [`GET ${base}/accounts`]: () => list(accounts),
            [`POST ${base}/accounts`]: () =>
                json({ data: row({ code: "1200", name: "Piutang" }) }, 201),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole("button", { name: "Tambah akun" }),
        );
        const dialog = await screen.findByRole("dialog");

        await typist.type(within(dialog).getByLabelText("Kode"), "1200");
        await typist.type(
            within(dialog).getByLabelText("Nama akun"),
            "Piutang",
        );
        await typist.click(
            within(dialog).getByRole("button", { name: "Simpan" }),
        );

        await waitFor(() =>
            expect(api.called(`POST ${base}/accounts`)).toHaveLength(1),
        );
        expect(api.called(`POST ${base}/accounts`)[0]?.body).toEqual({
            code: "1200",
            name: "Piutang",
            class: "asset",
            normal_balance: null,
            parent_id: null,
            ...postingDefaults,
        });
    });

    describe("posting attributes (GL-FR-02)", () => {
        const branch = {
            id: "01JDIMTYPE0000000000000001",
            key: "branch",
            label: "Cabang",
            is_hierarchical: true,
            leaf_only: false,
            status: "active",
            lock_version: 1,
        };
        const dollar = {
            code: "USD",
            name: "US Dollar",
            minor_unit: 2,
            scale: 2,
            rounding_mode: "half_up",
            is_functional: false,
            status: "active",
            lock_version: 1,
        };
        const full = [
            "accounts.read",
            "accounts.manage",
            "currencies.read",
            "dimensions.read",
        ];
        const path = `PATCH ${base}/accounts/${id2}`;
        const routes = () => ({
            ...withPermissions(full),
            [`GET ${base}/accounts`]: () => list(accounts),
            [`GET ${base}/currencies`]: () =>
                list([{ ...dollar, code: "IDR", is_functional: true }, dollar]),
            [`GET ${base}/dimension-types`]: () => list([branch]),
            [path]: () => json({ data: row({ lock_version: 2 }) }),
        });

        it("makes an account a bank account in one currency, revalued, with a required dimension", async () => {
            const api = mockApi(routes());

            renderApp(accountsPath);
            const typist = userEvent.setup();

            await rowAction(typist, "1111", "Ubah");
            const dialog = await screen.findByRole("dialog");

            await typist.click(
                within(dialog).getByRole("combobox", { name: "Mata uang" }),
            );
            await typist.click(
                await screen.findByRole("option", {
                    name: "Satu mata uang asing tertentu",
                }),
            );
            await typist.click(
                await within(dialog).findByRole("combobox", {
                    name: "Mata uang asing",
                }),
            );
            await typist.click(
                await screen.findByRole("option", { name: "USD · US Dollar" }),
            );
            await typist.click(
                within(dialog).getByRole("checkbox", {
                    name: /Direvaluasi di akhir periode/,
                }),
            );
            await typist.click(
                await within(dialog).findByRole("combobox", { name: "Cabang" }),
            );
            await typist.click(
                await screen.findByRole("option", { name: "Wajib" }),
            );
            await typist.click(
                within(dialog).getByRole("button", { name: "Simpan" }),
            );

            await waitFor(() => expect(api.called(path)).toHaveLength(1));
            expect(api.called(path)[0]?.body).toMatchObject({
                lock_version: 1,
                allowed_sources: ["entry", "subledger", "system"],
                currency_restriction: "specific",
                restricted_currency: "USD",
                is_revaluable: true,
                dimension_requirements: { branch: "required" },
            });
        });

        it("turns an account into a subledger control account by dropping the other sources", async () => {
            const api = mockApi(routes());

            renderApp(accountsPath);
            const typist = userEvent.setup();

            await rowAction(typist, "1111", "Ubah");
            const dialog = await screen.findByRole("dialog");

            await typist.click(
                within(dialog).getByRole("checkbox", { name: "Entri manual" }),
            );
            await typist.click(
                within(dialog).getByRole("checkbox", { name: /^Sistem/ }),
            );
            await typist.click(
                within(dialog).getByRole("button", { name: "Simpan" }),
            );

            await waitFor(() => expect(api.called(path)).toHaveLength(1));
            expect(api.called(path)[0]?.body).toMatchObject({
                allowed_sources: ["subledger"],
            });
        });

        it("keeps the last source checked and offers no revaluation for a functional-only account", async () => {
            mockApi({
                ...routes(),
                [`GET ${base}/accounts`]: () =>
                    list([
                        row({
                            id: id2,
                            code: "1111",
                            parent_id: null,
                            allowed_sources: ["subledger"],
                            currency_restriction: "functional_only",
                        }),
                    ]),
            });

            renderApp(accountsPath);
            const typist = userEvent.setup();

            await rowAction(typist, "1111", "Ubah");
            const dialog = await screen.findByRole("dialog");

            // The checkbox is not a native input: it says it is disabled through ARIA.
            expect(
                within(dialog).getByRole("checkbox", { name: "Subledger" }),
            ).toHaveAttribute("aria-disabled", "true");
            expect(
                within(dialog).getByRole("checkbox", {
                    name: /Direvaluasi di akhir periode/,
                }),
            ).toHaveAttribute("aria-disabled", "true");
        });

        it("leaves out what it may not read, so the account keeps its own requirements", async () => {
            const api = mockApi({
                ...withPermissions(["accounts.read", "accounts.manage"]),
                [`GET ${base}/accounts`]: () => list(accounts),
                [path]: () => json({ data: row({ lock_version: 2 }) }),
            });

            renderApp(accountsPath);
            const typist = userEvent.setup();

            await rowAction(typist, "1111", "Ubah");
            const dialog = await screen.findByRole("dialog");

            expect(
                within(dialog).queryByText("Dimensi"),
            ).not.toBeInTheDocument();

            await typist.click(
                within(dialog).getByRole("button", { name: "Simpan" }),
            );

            await waitFor(() => expect(api.called(path)).toHaveLength(1));
            expect(api.called(path)[0]?.body).not.toHaveProperty(
                "dimension_requirements",
            );
        });
    });

    it("shows a duplicate code next to the field", async () => {
        mockApi({
            ...withPermissions(["accounts.read", "accounts.manage"]),
            [`GET ${base}/accounts`]: () => list(accounts),
            [`POST ${base}/accounts`]: () =>
                json(
                    {
                        message: "Invalid",
                        errors: { code: ["Kode akun 1111 sudah dipakai."] },
                    },
                    422,
                ),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole("button", { name: "Tambah akun" }),
        );
        const dialog = await screen.findByRole("dialog");

        await typist.type(within(dialog).getByLabelText("Kode"), "1111");
        await typist.type(within(dialog).getByLabelText("Nama akun"), "Kas");
        await typist.click(
            within(dialog).getByRole("button", { name: "Simpan" }),
        );

        expect(
            await within(dialog).findByText("Kode akun 1111 sudah dipakai."),
        ).toBeInTheDocument();
    });

    it("edits an account with its version", async () => {
        const path = `PATCH ${base}/accounts/${id2}`;
        const api = mockApi({
            ...withPermissions(["accounts.read", "accounts.manage"]),
            [`GET ${base}/accounts`]: () => list(accounts),
            [path]: () =>
                json({ data: row({ name: "Kas Besar", lock_version: 2 }) }),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await rowAction(typist, "1111", "Ubah");
        const dialog = await screen.findByRole("dialog");
        const name = within(dialog).getByLabelText("Nama akun");

        await typist.clear(name);
        await typist.type(name, "Kas Besar");
        await typist.click(
            within(dialog).getByRole("button", { name: "Simpan" }),
        );

        await waitFor(() => expect(api.called(path)).toHaveLength(1));
        expect(api.called(path)[0]?.body).toMatchObject({
            lock_version: 1,
            name: "Kas Besar",
            code: "1111",
        });
    });

    it("reloads and explains when someone else changed the account first", async () => {
        const api = mockApi({
            ...withPermissions(["accounts.read", "accounts.manage"]),
            [`GET ${base}/accounts`]: () => list(accounts),
            [`PATCH ${base}/accounts/${id2}`]: () =>
                json({ message: "Conflict", code: "VERSION_CONFLICT" }, 409),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await rowAction(typist, "1111", "Ubah");
        const dialog = await screen.findByRole("dialog");

        await typist.click(
            within(dialog).getByRole("button", { name: "Simpan" }),
        );

        expect(
            await within(dialog).findByText(/Akun sudah diubah di tempat lain/),
        ).toBeInTheDocument();
        expect(api.called(`GET ${base}/accounts`).length).toBeGreaterThan(1);
    });

    it("deactivates and reactivates an account", async () => {
        const off = `POST ${base}/accounts/${id2}/deactivate`;
        const on = `POST ${base}/accounts/${id3}/reactivate`;
        const api = mockApi({
            ...withPermissions(["accounts.read", "accounts.manage"]),
            [`GET ${base}/accounts`]: () => list(accounts),
            [off]: () => json({ data: row({ status: "inactive" }) }),
            [on]: () => json({ data: row({ status: "active" }) }),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await rowAction(typist, "1111", "Nonaktifkan");
        await confirmWith(typist, "Nonaktifkan");
        await rowAction(typist, "1113", "Aktifkan");

        await waitFor(() => {
            expect(api.called(off)).toHaveLength(1);
            expect(api.called(on)).toHaveLength(1);
        });
    });

    it("asks before deactivating, and leaves the account alone when told not to", async () => {
        const off = `POST ${base}/accounts/${id2}/deactivate`;
        const api = mockApi({
            ...withPermissions(["accounts.read", "accounts.manage"]),
            [`GET ${base}/accounts`]: () => list(accounts),
            [off]: () => json({ data: row({ status: "inactive" }) }),
        });

        renderApp(accountsPath);
        const typist = userEvent.setup();

        await rowAction(typist, "1111", "Nonaktifkan");

        const dialog = await confirmDialog();

        expect(
            within(dialog).getByText("Nonaktifkan akun 1111?"),
        ).toBeInTheDocument();
        expect(api.called(off)).toHaveLength(0);

        await typist.click(
            within(dialog).getByRole("button", { name: "Batal" }),
        );

        await waitFor(() =>
            expect(screen.queryByRole("alertdialog")).not.toBeInTheDocument(),
        );
        expect(api.called(off)).toHaveLength(0);
    });

    it("does not ask before reactivating: that is easy to undo", async () => {
        const on = `POST ${base}/accounts/${id3}/reactivate`;
        const api = mockApi({
            ...withPermissions(["accounts.read", "accounts.manage"]),
            [`GET ${base}/accounts`]: () => list(accounts),
            [on]: () => json({ data: row({ status: "active" }) }),
        });

        renderApp(accountsPath);

        await rowAction(userEvent.setup(), "1113", "Aktifkan");

        await waitFor(() => expect(api.called(on)).toHaveLength(1));
        expect(screen.queryByRole("alertdialog")).not.toBeInTheDocument();
    });

    it("keeps the actions of a row in one menu", async () => {
        mockApi({
            ...withPermissions(["accounts.read", "accounts.manage"]),
            [`GET ${base}/accounts`]: () => list(accounts),
        });

        renderApp(accountsPath);

        await screen.findByText("Kas Kecil");

        // One button per row, whatever the number of actions.
        expect(
            screen.getAllByRole("button", { name: /^Aksi untuk / }),
        ).toHaveLength(3);
        expect(
            screen.queryByRole("button", { name: "Ubah" }),
        ).not.toBeInTheDocument();
    });

    it("says the chart is empty when there are no accounts at all", async () => {
        mockApi({
            ...withPermissions(["accounts.read"]),
            [`GET ${base}/accounts`]: () => list([]),
        });

        renderApp(accountsPath);

        expect(await screen.findByText("Belum ada akun")).toBeInTheDocument();
    });
});

const year = {
    id: "01JYEAR000000000000000001",
    name: "FY2026",
    start_date: "2026-01-01",
    end_date: "2026-12-31",
    period_count: 12,
};

const period = (n: number, status: string) => ({
    id: `01JPERIOD00000000000000${String(n).padStart(3, "0")}`,
    fiscal_year_id: year.id,
    number: n,
    name: `2026-${String(n).padStart(2, "0")}`,
    start_date: `2026-${String(n).padStart(2, "0")}-01`,
    end_date: `2026-${String(n).padStart(2, "0")}-28`,
    status,
});

const closingState = (overrides: Record<string, unknown> = {}) => ({
    closed: false,
    closing: null,
    ready: true,
    checks: {
        retained_earnings: { ok: true },
        last_period: { ok: true, name: "2026-12", status: "open" },
        unfinished_entries: { ok: true, count: 0 },
        reconciliation: { ok: true, count: 0 },
    },
    ...overrides,
});

const closingRun = (overrides: Record<string, unknown> = {}) => ({
    id: "01JCLOSING",
    fiscal_year_id: year.id,
    status: "closed",
    journal_id: "01JJOURNAL000000000000009",
    journal_number: "JU-2026-12-0009",
    line_count: 3,
    result: "600000.00",
    retained_earnings_mapping_version: 1,
    reversal_journal_id: null,
    created_at: "2026-12-31T10:00:00Z",
    ...overrides,
});

describe("fiscal periods", () => {
    const closingPath = `${base}/fiscal-years/${year.id}/closing`;
    const periodRoutes = (permissions: string[], state = closingState()) => ({
        ...withPermissions(permissions),
        [`GET ${base}/fiscal-years`]: () => json({ data: [year] }),
        [`GET ${base}/periods`]: () =>
            list([period(1, "closed"), period(2, "open")]),
        [`GET ${closingPath}`]: () => json({ data: state }),
    });

    it("lists the periods of the fiscal year", async () => {
        mockApi(periodRoutes(["periods.read"]));

        renderApp(periodsPath);

        expect(await screen.findByText("2026-01")).toBeInTheDocument();
        expect(screen.getByText("2026-02")).toBeInTheDocument();
        expect(screen.getByText("Ditutup")).toBeInTheDocument();
        expect(screen.getByText("Terbuka")).toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: /^Tutup/ }),
        ).not.toBeInTheDocument();
    });

    it("closes an open period", async () => {
        const close = `POST ${base}/periods/${period(2, "open").id}/close`;
        const api = mockApi({
            ...periodRoutes(["periods.read", "periods.close"]),
            [close]: () => json({ data: period(2, "closed") }),
        });

        renderApp(periodsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole("button", { name: "Tutup 2026-02" }),
        );
        await confirmWith(typist, "Tutup periode");

        await waitFor(() => expect(api.called(close)).toHaveLength(1));
    });

    it("soft-closes an open period after asking", async () => {
        const soft = `POST ${base}/periods/${period(2, "open").id}/soft-close`;
        const api = mockApi({
            ...periodRoutes(["periods.read", "periods.close"]),
            [soft]: () => json({ data: period(2, "soft_closed") }),
        });

        renderApp(periodsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole("button", {
                name: "Tutup sementara 2026-02",
            }),
        );
        await confirmWith(typist, "Tutup sementara");

        await waitFor(() => expect(api.called(soft)).toHaveLength(1));
    });

    it("lets a soft-closed period be closed or reopened, and a closed one only reopened", async () => {
        mockApi({
            ...withPermissions([
                "periods.read",
                "periods.close",
                "periods.reopen",
            ]),
            [`GET ${base}/fiscal-years`]: () => json({ data: [year] }),
            [`GET ${base}/periods`]: () =>
                list([period(1, "closed"), period(2, "soft_closed")]),
        });

        renderApp(periodsPath);

        await screen.findByText("Ditutup sementara");
        expect(
            screen.getByRole("button", { name: "Tutup 2026-02" }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole("button", { name: "Buka kembali 2026-02" }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole("button", { name: "Buka kembali 2026-01" }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: "Tutup 2026-01" }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole("button", { name: /^Tutup sementara/ }),
        ).not.toBeInTheDocument();
    });

    describe("revaluation (GL-FR-09)", () => {
        const bank = "01JACCOUNT0000000000000001";
        const run = `POST ${base}/periods/${period(2, "open").id}/revaluation`;
        const revalueRoutes = (permissions: string[]) => ({
            ...periodRoutes(["periods.read", ...permissions]),
            [`GET ${base}/accounts`]: () =>
                list([row({ id: bank, code: "1112", name: "Bank USD" })]),
        });

        it("shows what was adjusted and links to the journals", async () => {
            const api = mockApi({
                ...revalueRoutes([
                    "periods.revalue",
                    "journals.read",
                    "accounts.read",
                ]),
                [run]: () =>
                    json(
                        {
                            data: {
                                id: "01JRUN",
                                period_id: period(2, "open").id,
                                journal_id: "01JJOURNAL000000000000001",
                                reversal_journal_id:
                                    "01JJOURNAL000000000000002",
                                adjustments: [
                                    {
                                        account_id: bank,
                                        currency: "USD",
                                        dimension_set_id: "01JSET",
                                        transaction_balance: "1000.00",
                                        carried: "15000000.00",
                                        revalued: "15200000.00",
                                        adjustment: "200000.00",
                                    },
                                ],
                            },
                        },
                        201,
                    ),
            });

            renderApp(periodsPath);
            const typist = userEvent.setup();

            await typist.click(
                await screen.findByRole("button", {
                    name: "Revaluasi 2026-02",
                }),
            );
            const dialog = await screen.findByRole("dialog");

            await typist.click(
                within(dialog).getByRole("button", {
                    name: "Jalankan revaluasi",
                }),
            );

            expect(
                await within(dialog).findByText("1112 · Bank USD"),
            ).toBeInTheDocument();
            expect(within(dialog).getByText("+200.000,00")).toBeInTheDocument();
            expect(
                within(dialog).getByText("15.200.000,00"),
            ).toBeInTheDocument();
            expect(
                within(dialog).getByRole("link", {
                    name: "Lihat jurnal revaluasi",
                }),
            ).toHaveAttribute(
                "href",
                `/w/${workspace.company_id}/journals/01JJOURNAL000000000000001`,
            );
            expect(
                within(dialog).getByRole("link", {
                    name: "Lihat jurnal pembalik",
                }),
            ).toBeInTheDocument();
            expect(api.called(run)).toHaveLength(1);
        });

        it("says so when nothing needed adjusting", async () => {
            mockApi({
                ...revalueRoutes(["periods.revalue"]),
                [run]: () =>
                    json({
                        data: {
                            id: null,
                            period_id: period(2, "open").id,
                            journal_id: null,
                            reversal_journal_id: null,
                            adjustments: [],
                        },
                    }),
            });

            renderApp(periodsPath);
            const typist = userEvent.setup();

            await typist.click(
                await screen.findByRole("button", {
                    name: "Revaluasi 2026-02",
                }),
            );
            const dialog = await screen.findByRole("dialog");

            await typist.click(
                within(dialog).getByRole("button", {
                    name: "Jalankan revaluasi",
                }),
            );

            expect(
                await within(dialog).findByText(
                    /Tidak ada selisih yang perlu disesuaikan/,
                ),
            ).toBeInTheDocument();
        });

        it("points to the settings when the policy or a role is missing", async () => {
            mockApi({
                ...revalueRoutes(["periods.revalue"]),
                [run]: () =>
                    json(
                        {
                            message: "Kebijakan belum menetapkan jenis kurs.",
                            code: "MAPPING_REQUIRED",
                        },
                        422,
                    ),
            });

            renderApp(periodsPath);
            const typist = userEvent.setup();

            await typist.click(
                await screen.findByRole("button", {
                    name: "Revaluasi 2026-02",
                }),
            );
            const dialog = await screen.findByRole("dialog");

            await typist.click(
                within(dialog).getByRole("button", {
                    name: "Jalankan revaluasi",
                }),
            );

            expect(
                await within(dialog).findByText(
                    /jenis kurs\. Atur jenis kurs dan peran akun di Pengaturan buku besar/,
                ),
            ).toBeInTheDocument();
        });

        it("offers no revaluation without the permission or for a closed period", async () => {
            mockApi(revalueRoutes(["periods.close"]));

            renderApp(periodsPath);

            await screen.findByText("2026-02");
            expect(
                screen.queryByRole("button", { name: /^Revaluasi/ }),
            ).not.toBeInTheDocument();
        });

        it("does not offer it for a closed period", async () => {
            mockApi(revalueRoutes(["periods.revalue"]));

            renderApp(periodsPath);

            await screen.findByText("2026-02");
            expect(
                screen.getAllByRole("button", { name: /^Revaluasi/ }),
            ).toHaveLength(2);
            expect(
                screen.queryByRole("button", { name: "Revaluasi 2026-01" }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole("button", {
                    name: "Revaluasi open item 2026-01",
                }),
            ).not.toBeInTheDocument();
        });
    });

    describe("open item revaluation (SL-FR-07)", () => {
        const item = "01JOPENITEM000000000000001";
        const run = `POST ${base}/periods/${period(2, "open").id}/open-item-revaluation`;
        const revalueRoutes = (permissions: string[]) => ({
            ...periodRoutes(["periods.read", ...permissions]),
            [`GET ${base}/open-items`]: () =>
                list([{ id: item, document_number: "INV-001" }]),
        });
        const open = async (typist: ReturnType<typeof userEvent.setup>) => {
            await typist.click(
                await screen.findByRole("button", {
                    name: "Revaluasi open item 2026-02",
                }),
            );
            const dialog = await screen.findByRole("dialog");

            await typist.click(
                within(dialog).getByRole("button", {
                    name: "Jalankan revaluasi open item",
                }),
            );

            return dialog;
        };

        it("shows what was adjusted per document and links to the journals", async () => {
            const api = mockApi({
                ...revalueRoutes([
                    "periods.revalue",
                    "journals.read",
                    "open-items.read",
                ]),
                [run]: () =>
                    json(
                        {
                            data: {
                                id: "01JRUN",
                                period_id: period(2, "open").id,
                                journal_id: "01JJOURNAL000000000000001",
                                reversal_journal_id:
                                    "01JJOURNAL000000000000002",
                                adjustments: [
                                    {
                                        open_item_id: item,
                                        currency: "USD",
                                        remaining_amount: "1000.00",
                                        carried: "15000000.00",
                                        revalued: "15200000.00",
                                        adjustment: "200000.00",
                                    },
                                ],
                            },
                        },
                        201,
                    ),
            });

            renderApp(periodsPath);
            const dialog = await open(userEvent.setup());

            expect(
                await within(dialog).findByText("INV-001"),
            ).toBeInTheDocument();
            expect(within(dialog).getByText("+200.000,00")).toBeInTheDocument();
            expect(
                within(dialog).getByRole("link", {
                    name: "Lihat jurnal revaluasi",
                }),
            ).toHaveAttribute(
                "href",
                `/w/${workspace.company_id}/journals/01JJOURNAL000000000000001`,
            );
            expect(
                within(dialog).getByRole("link", {
                    name: "Lihat jurnal pembalik",
                }),
            ).toBeInTheDocument();
            expect(api.called(run)).toHaveLength(1);
        });

        it("says so when nothing needed adjusting", async () => {
            mockApi({
                ...revalueRoutes(["periods.revalue"]),
                [run]: () =>
                    json({
                        data: {
                            id: null,
                            period_id: period(2, "open").id,
                            journal_id: null,
                            reversal_journal_id: null,
                            adjustments: [],
                        },
                    }),
            });

            renderApp(periodsPath);
            const dialog = await open(userEvent.setup());

            expect(
                await within(dialog).findByText(
                    /Tidak ada selisih yang perlu disesuaikan/,
                ),
            ).toBeInTheDocument();
        });

        it("points to the settings when the policy has no rate type", async () => {
            mockApi({
                ...revalueRoutes(["periods.revalue"]),
                [run]: () =>
                    json(
                        {
                            message: "Kebijakan belum menetapkan jenis kurs.",
                            code: "MAPPING_REQUIRED",
                        },
                        422,
                    ),
            });

            renderApp(periodsPath);
            const dialog = await open(userEvent.setup());

            expect(
                await within(dialog).findByText(
                    /jenis kurs\. Atur jenis kurs dan peran akun di Pengaturan buku besar/,
                ),
            ).toBeInTheDocument();
        });

        it("tells the user about an allocation dated after the period", async () => {
            mockApi({
                ...revalueRoutes(["periods.revalue"]),
                [run]: () =>
                    json(
                        {
                            message: "Ada alokasi bertanggal sesudah periode.",
                            errors: {
                                period_id: [
                                    "Ada alokasi bertanggal sesudah periode.",
                                ],
                            },
                        },
                        422,
                    ),
            });

            renderApp(periodsPath);
            const dialog = await open(userEvent.setup());

            expect(
                await within(dialog).findByText(
                    /alokasi bertanggal sesudah periode/,
                ),
            ).toBeInTheDocument();
        });

        it("offers no open item revaluation without the permission", async () => {
            mockApi(revalueRoutes(["periods.close"]));

            renderApp(periodsPath);

            await screen.findByText("2026-02");
            expect(
                screen.queryByRole("button", { name: /^Revaluasi open item/ }),
            ).not.toBeInTheDocument();
        });
    });

    describe("closing the fiscal year (GL-FR-12, GL-FR-14)", () => {
        const close = `POST ${base}/fiscal-years/${year.id}/close`;
        const reopen = `POST ${base}/fiscal-years/${year.id}/reopen`;

        it("links an unresolved reconciliation to its differences on the closing date", async () => {
            mockApi(
                periodRoutes(
                    ["periods.read", "reconciliations.read"],
                    closingState({
                        ready: false,
                        checks: {
                            ...closingState().checks,
                            reconciliation: { ok: false, count: 2 },
                        },
                    }),
                ),
            );

            renderApp(periodsPath);

            expect(
                await screen.findByRole("link", { name: "Tinjau selisih" }),
            ).toHaveAttribute(
                "href",
                `/w/${workspace.company_id}/reconciliations?as_of=${year.end_date}`,
            );
        });

        it("shows what keeps the year from closing and offers no closing meanwhile", async () => {
            mockApi(
                periodRoutes(
                    ["periods.read", "fiscal-years.close", "entries.read"],
                    closingState({
                        ready: false,
                        checks: {
                            retained_earnings: { ok: false },
                            last_period: {
                                ok: false,
                                name: "2026-12",
                                status: "closed",
                            },
                            unfinished_entries: { ok: false, count: 3 },
                            reconciliation: { ok: true, count: 0 },
                        },
                    }),
                ),
            );

            renderApp(periodsPath);

            expect(
                await screen.findByText("Akun saldo laba belum dipetakan."),
            ).toBeInTheDocument();
            expect(
                screen.getByText(/Periode terakhir \(2026-12\) sudah ditutup/),
            ).toBeInTheDocument();
            expect(
                screen.getByText(
                    /3 entri bertanggal sampai 2026-12-31 belum selesai/,
                ),
            ).toBeInTheDocument();
            expect(
                screen.getByRole("link", {
                    name: "Atur di Pengaturan buku besar",
                }),
            ).toBeInTheDocument();
            expect(
                screen.getByRole("link", { name: "Lihat entri" }),
            ).toBeInTheDocument();
            expect(
                screen.getByRole("button", { name: "Tutup tahun buku" }),
            ).toBeDisabled();
        });

        it("closes the year after asking", async () => {
            const api = mockApi({
                ...periodRoutes(["periods.read", "fiscal-years.close"]),
                [close]: () => json({ data: closingRun() }, 201),
            });

            renderApp(periodsPath);
            const typist = userEvent.setup();

            await typist.click(
                await screen.findByRole("button", { name: "Tutup tahun buku" }),
            );
            await confirmWith(typist, "Tutup tahun buku");

            await waitFor(() => expect(api.called(close)).toHaveLength(1));
            // The status is read again after the closing.
            await waitFor(() =>
                expect(api.called(`GET ${closingPath}`).length).toBeGreaterThan(
                    1,
                ),
            );
        });

        it("shows the result of a closed year with its journal, and reopens it with a reason", async () => {
            const api = mockApi({
                ...periodRoutes(
                    ["periods.read", "fiscal-years.reopen", "journals.read"],
                    closingState({
                        closed: true,
                        closing: closingRun(),
                        ready: false,
                    }),
                ),
                [reopen]: () =>
                    json({
                        data: closingRun({
                            status: "reopened",
                            reversal_journal_id: "01JJOURNAL000000000000010",
                        }),
                    }),
            });

            renderApp(periodsPath);
            const typist = userEvent.setup();

            expect(
                await screen.findByText(/Tahun buku FY2026 sudah ditutup/),
            ).toBeInTheDocument();
            expect(
                screen.getByText(
                    /Laba tahun berjalan 600\.000,00 dipindahkan ke saldo laba/,
                ),
            ).toBeInTheDocument();
            expect(
                screen.getByRole("link", {
                    name: "Lihat jurnal penutup JU-2026-12-0009",
                }),
            ).toHaveAttribute(
                "href",
                `/w/${workspace.company_id}/journals/01JJOURNAL000000000000009`,
            );
            // A closed year offers no closing and no checklist.
            expect(
                screen.queryByRole("button", { name: "Tutup tahun buku" }),
            ).not.toBeInTheDocument();

            await typist.click(
                screen.getByRole("button", { name: "Buka kembali tahun buku" }),
            );
            const dialog = await screen.findByRole("dialog");

            await typist.click(
                within(dialog).getByRole("button", { name: "Buka kembali" }),
            );
            expect(
                await within(dialog).findByText("Wajib diisi"),
            ).toBeInTheDocument();
            expect(api.called(reopen)).toHaveLength(0);

            await typist.type(
                within(dialog).getByLabelText("Alasan"),
                "Koreksi audit",
            );
            await typist.click(
                within(dialog).getByRole("button", { name: "Buka kembali" }),
            );

            await waitFor(() =>
                expect(api.called(reopen)[0]?.body).toEqual({
                    reason: "Koreksi audit",
                }),
            );
        });

        it("says so when a closed year had no income or expense, and a loss as a loss", async () => {
            mockApi(
                periodRoutes(
                    ["periods.read"],
                    closingState({
                        closed: true,
                        closing: closingRun({
                            journal_id: null,
                            journal_number: null,
                            result: "0.00",
                        }),
                    }),
                ),
            );

            renderApp(periodsPath);

            expect(
                await screen.findByText(
                    /Tidak ada pendapatan atau beban yang perlu ditutup/,
                ),
            ).toBeInTheDocument();
        });

        it("shows a loss as a loss", async () => {
            mockApi(
                periodRoutes(
                    ["periods.read"],
                    closingState({
                        closed: true,
                        closing: closingRun({ result: "-250000.00" }),
                    }),
                ),
            );

            renderApp(periodsPath);

            expect(
                await screen.findByText(
                    /Rugi tahun berjalan 250\.000,00 dipindahkan ke saldo laba/,
                ),
            ).toBeInTheDocument();
        });

        it("offers neither closing nor reopening without the permissions", async () => {
            mockApi(periodRoutes(["periods.read"]));

            renderApp(periodsPath);

            await screen.findByText("Akun saldo laba sudah dipetakan.");
            expect(
                screen.queryByRole("button", { name: "Tutup tahun buku" }),
            ).not.toBeInTheDocument();
        });

        it("shows why the server refused the closing", async () => {
            const api = mockApi({
                ...periodRoutes(["periods.read", "fiscal-years.close"]),
                [close]: () =>
                    json(
                        {
                            message: "Ada entri yang belum selesai.",
                            code: "INVALID_STATE_TRANSITION",
                        },
                        409,
                    ),
            });

            renderApp(periodsPath);
            const typist = userEvent.setup();

            await typist.click(
                await screen.findByRole("button", { name: "Tutup tahun buku" }),
            );
            await confirmWith(typist, "Tutup tahun buku");

            await waitFor(() => expect(api.called(close)).toHaveLength(1));
        });
    });

    it("will not reopen a period without a reason", async () => {
        const reopen = `POST ${base}/periods/${period(1, "closed").id}/reopen`;
        const api = mockApi({
            ...periodRoutes(["periods.read", "periods.reopen"]),
            [reopen]: () => json({ data: period(1, "open") }),
        });

        renderApp(periodsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole("button", { name: "Buka kembali 2026-01" }),
        );
        const dialog = await screen.findByRole("dialog");

        await typist.click(
            within(dialog).getByRole("button", { name: "Buka kembali" }),
        );
        expect(
            await within(dialog).findByText("Wajib diisi"),
        ).toBeInTheDocument();
        expect(api.called(reopen)).toHaveLength(0);

        await typist.type(
            within(dialog).getByLabelText("Alasan"),
            "Koreksi audit",
        );
        await typist.click(
            within(dialog).getByRole("button", { name: "Buka kembali" }),
        );

        await waitFor(() =>
            expect(api.called(reopen)[0]?.body).toEqual({
                reason: "Koreksi audit",
            }),
        );
    });

    it("adds the next fiscal year", async () => {
        const create = `POST ${base}/fiscal-years`;
        const api = mockApi({
            ...periodRoutes(["periods.read", "periods.manage"]),
            [create]: () =>
                json(
                    {
                        data: [
                            {
                                ...year,
                                id: "01JYEAR000000000000000002",
                                name: "FY2027",
                            },
                        ],
                    },
                    201,
                ),
        });

        renderApp(periodsPath);
        const typist = userEvent.setup();

        await typist.click(
            await screen.findByRole("button", { name: "Tambah tahun fiskal" }),
        );

        await waitFor(() =>
            expect(api.called(create)[0]?.body).toEqual({ count: 1 }),
        );
    });

    it("invites creating a fiscal year when there is none", async () => {
        mockApi({
            ...withPermissions(["periods.read", "periods.manage"]),
            [`GET ${base}/fiscal-years`]: () => json({ data: [] }),
        });

        renderApp(periodsPath);

        expect(
            await screen.findByText("Belum ada tahun fiskal"),
        ).toBeInTheDocument();
    });
});
