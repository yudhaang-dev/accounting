import { api, unwrap } from "@/api/client";
import type { ReportDefinition } from "@/modules/reporting/report-definitions";

export const KINDS = [
    "custom",
    "income_statement",
    "balance_sheet",
    "cash_flow_indirect",
] as const;
export const BASES = ["balance", "movement", "opening", "closing"] as const;
export const ROW_TYPES = [
    "heading",
    "accounts",
    "subtotal",
    "current_year_earnings",
    "cash_equivalents",
] as const;
export const COLUMN_KINDS = [
    "period",
    "ytd",
    "as_of",
    "plan",
    "difference",
] as const;
export const SELECTOR_TYPES = ["account", "parent", "class"] as const;
export const UNMAPPED = "unmapped";

export type SelectorRow = {
    type: (typeof SELECTOR_TYPES)[number];
    id: string;
    class: string;
};

export type TermRow = { row: string; sign: "1" | "-1" };

export type RowForm = {
    key: string;
    label: string;
    type: (typeof ROW_TYPES)[number];
    sign: "debit" | "credit";
    /** `higher`, `lower`, or empty for what the sign implies. */
    favorable: string;
    /** Empty means the basis of the whole definition. */
    basis: string;
    accounts: SelectorRow[];
    terms: TermRow[];
};

export type ColumnForm = {
    key: string;
    label: string;
    kind: (typeof COLUMN_KINDS)[number];
    /** How far back the range moves, as positive numbers; the API takes them negative. */
    shift_years: string;
    shift_months: string;
    span: "period" | "ytd";
    plan_version_id: string;
    minuend: string;
    subtrahend: string;
};

export type DefinitionForm = {
    key: string;
    name: string;
    kind: (typeof KINDS)[number];
    basis: (typeof BASES)[number];
    /** Publish a snapshot when a period or the fiscal year closes (RP-FR-14). */
    publish_on_period_close: boolean;
    publish_on_year_close: boolean;
    rows: RowForm[];
    columns: ColumnForm[];
};

export const blankRow = (): RowForm => ({
    key: "",
    label: "",
    type: "accounts",
    sign: "debit",
    favorable: "",
    basis: "",
    accounts: [{ type: "class", id: "", class: "asset" }],
    terms: [],
});

export const blankColumn = (): ColumnForm => ({
    key: "",
    label: "",
    kind: "period",
    shift_years: "",
    shift_months: "",
    span: "period",
    plan_version_id: "",
    minuend: "",
    subtrahend: "",
});

export const blankDefinition = (): DefinitionForm => ({
    key: "",
    name: "",
    kind: "custom",
    basis: "movement",
    publish_on_period_close: false,
    publish_on_year_close: false,
    rows: [blankRow()],
    columns: [{ ...blankColumn(), key: "period", label: "Periode" }],
});

type Structure = {
    basis?: string;
    rows?: Record<string, unknown>[];
    columns?: Record<string, unknown>[];
};

const text = (value: unknown) => (typeof value === "string" ? value : "");

/** The editor's form for a definition as the API holds it. */
export function toForm(definition: ReportDefinition): DefinitionForm {
    const structure = definition.structure as Structure;

    return {
        key: definition.key,
        name: definition.name,
        kind: (KINDS as readonly string[]).includes(definition.kind)
            ? (definition.kind as DefinitionForm["kind"])
            : "custom",
        basis: (BASES as readonly string[]).includes(structure.basis ?? "")
            ? (structure.basis as DefinitionForm["basis"])
            : "movement",
        publish_on_period_close: definition.publish_on_period_close,
        publish_on_year_close: definition.publish_on_year_close,
        rows: (structure.rows ?? []).map((row) => ({
            ...blankRow(),
            key: text(row.key),
            label: text(row.label),
            type: row.type as RowForm["type"],
            sign: row.sign === "credit" ? "credit" : "debit",
            favorable: text(row.favorable),
            basis: text(row.basis),
            accounts: (
                (row.accounts as Record<string, string>[] | undefined) ?? []
            ).map((selector) => ({
                type: selector.type as SelectorRow["type"],
                id: selector.id ?? "",
                class: selector.class ?? "",
            })),
            terms: (
                (row.terms as { row: string; sign: number }[] | undefined) ?? []
            ).map((term) => ({
                row: term.row,
                sign: term.sign === -1 ? "-1" : "1",
            })),
        })),
        columns: (structure.columns ?? []).map((column) => {
            const shift = (column.shift ?? {}) as {
                years?: number;
                months?: number;
            };

            return {
                ...blankColumn(),
                key: text(column.key),
                label: text(column.label),
                kind: column.kind as ColumnForm["kind"],
                shift_years: shift.years ? String(-shift.years) : "",
                shift_months: shift.months ? String(-shift.months) : "",
                span: column.span === "ytd" ? "ytd" : "period",
                plan_version_id: text(column.plan_version_id),
                minuend: text(column.minuend),
                subtrahend: text(column.subtrahend),
            };
        }),
    };
}

const wholeNumber = (value: string) => {
    const parsed = Number.parseInt(value, 10);

    return Number.isNaN(parsed) ? 0 : parsed;
};

/** The structure the API takes: only what each type of row or column uses is sent. */
export function toStructure(form: DefinitionForm) {
    return {
        basis: form.basis,
        rows: form.rows.map((row) => {
            const out: Record<string, unknown> = {
                key: row.key,
                label: row.label,
                type: row.type,
            };

            if (row.type !== "heading") {
                out.sign = row.sign;

                if (row.favorable) {
                    out.favorable = row.favorable;
                }
            }

            if (row.type === "accounts" || row.type === "cash_equivalents") {
                if (row.basis) {
                    out.basis = row.basis;
                }
            }

            if (row.type === "accounts") {
                out.accounts = row.accounts.map((selector) =>
                    selector.type === "class"
                        ? { type: "class", class: selector.class }
                        : { type: selector.type, id: selector.id },
                );
            }

            if (row.type === "subtotal") {
                out.terms = row.terms.map((term) => ({
                    row: term.row,
                    sign: Number(term.sign),
                }));
            }

            return out;
        }),
        columns: form.columns.map((column) => {
            const out: Record<string, unknown> = {
                key: column.key,
                label: column.label,
                kind: column.kind,
            };

            if (column.kind === "difference") {
                out.minuend = column.minuend;
                out.subtrahend = column.subtrahend;
            } else {
                out.shift = {
                    years: -wholeNumber(column.shift_years),
                    months: -wholeNumber(column.shift_months),
                };

                if (column.kind === "plan") {
                    out.span = column.span;
                    out.plan_version_id = column.plan_version_id || null;
                }
            }

            return out;
        }),
    };
}

/**
 * Server errors by the path of the field in the form: the API names `structure.rows.0.key`, the form
 * `rows.0.key`. A message that no field shows is still kept, under its path, for the summary.
 */
export function formErrors(
    errors: Record<string, string[]>,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(errors).flatMap(([path, messages]) =>
            messages[0] === undefined
                ? []
                : [[path.replace(/^structure\./, ""), messages[0]]],
        ),
    );
}

export async function createDefinition(
    companyId: string,
    form: DefinitionForm,
): Promise<ReportDefinition> {
    return (
        await unwrap(
            api.POST("/companies/{companyId}/report-definitions", {
                params: { path: { companyId } },
                body: {
                    key: form.key,
                    name: form.name,
                    kind: form.kind,
                    publish_on_period_close: form.publish_on_period_close,
                    publish_on_year_close: form.publish_on_year_close,
                    structure: toStructure(form),
                } as never,
            }),
        )
    ).data;
}

export async function updateDefinition(
    companyId: string,
    definition: ReportDefinition,
    form: DefinitionForm,
): Promise<ReportDefinition> {
    return (
        await unwrap(
            api.PUT("/companies/{companyId}/report-definitions/{id}", {
                params: { path: { companyId, id: definition.id } },
                body: {
                    name: form.name,
                    publish_on_period_close: form.publish_on_period_close,
                    publish_on_year_close: form.publish_on_year_close,
                    structure: toStructure(form),
                    lock_version: definition.lock_version,
                } as never,
            }),
        )
    ).data;
}
