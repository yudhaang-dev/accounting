import { queryOptions } from "@tanstack/react-query";
import { api, unwrap } from "@/api/client";
import type { components, operations } from "@/api/schema";
import { fetchAll } from "@/lib/fetch-all";
import {
    filterParams,
    type ReportFilter,
} from "@/modules/reporting/report-filter";

export type ReportDefinition =
    components["schemas"]["ReportDefinitionResource"];

type Result =
    operations["report-definitions.result"]["responses"][200]["content"]["application/json"]["data"];

export type CoverageStatus = "complete" | "partial" | "unavailable";

export type ReportColumn = Result["columns"][number] & {
    coverage_status: CoverageStatus;
    plan_version_id?: string | null;
};

export type BalanceCheck = {
    assets: string;
    liabilities_and_equity: string;
    difference: string;
    balanced: boolean;
};

export type CashCheck = {
    opening_balance: string;
    net_increase: string;
    closing_balance: string;
    reconciled: boolean;
};

/** The result of a definition. The generated type leaves out the checks, variances and coverage. */
export type ReportResult = Omit<Result, "columns" | "rows"> & {
    columns: ReportColumn[];
    rows: (Result["rows"][number] & {
        variances?: Record<
            string,
            { percentage: string | null; favorable: boolean | null }
        >;
    })[];
    coverage_from: string | null;
    coverage_to: string | null;
    coverage_granularity: "transactions" | "period_summary";
    coverage_status: CoverageStatus;
    balance_checks?: Record<string, BalanceCheck>;
    cash_checks?: Record<string, CashCheck>;
};

/** A balance sheet reads one date; every other kind reads a range. */
export const dateModeOf = (definition: ReportDefinition) =>
    definition.kind === "balance_sheet"
        ? ("as_of" as const)
        : ("range" as const);

/** Whether the structure has a `plan` column, so the viewer offers a plan version. */
export const comparesWithPlan = (definition: ReportDefinition): boolean => {
    const columns = definition.structure.columns;

    return (
        Array.isArray(columns) &&
        columns.some((column) => (column as { kind?: string }).kind === "plan")
    );
};

export const reportDefinitionsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ["companies", companyId, "report-definitions", "list"],
        queryFn: async (): Promise<ReportDefinition[]> =>
            fetchAll((page) =>
                unwrap(
                    api.GET("/companies/{companyId}/report-definitions", {
                        params: {
                            path: { companyId },
                            query: { per_page: 200, page },
                        },
                    }),
                ),
            ),
    });

export const reportDefinitionQuery = (companyId: string, id: string) =>
    queryOptions({
        queryKey: ["companies", companyId, "report-definitions", id],
        queryFn: async (): Promise<ReportDefinition> =>
            (
                await unwrap(
                    api.GET("/companies/{companyId}/report-definitions/{id}", {
                        params: { path: { companyId, id } },
                    }),
                )
            ).data,
    });

export const reportResultQuery = (
    companyId: string,
    id: string,
    filter: ReportFilter,
    mode: "range" | "as_of",
) =>
    queryOptions({
        queryKey: [
            "companies",
            companyId,
            "report-definitions",
            id,
            "result",
            mode,
            filter,
        ],
        queryFn: async (): Promise<ReportResult> =>
            (
                (await unwrap(
                    api.GET(
                        "/companies/{companyId}/report-definitions/{id}/result",
                        {
                            params: { path: { companyId, id } },
                            // The generated query type cannot express `dimensions[<key>]`.
                            querySerializer: () =>
                                filterParams(filter, mode).toString(),
                        },
                    ),
                )) as unknown as { data: ReportResult }
            ).data,
    });
