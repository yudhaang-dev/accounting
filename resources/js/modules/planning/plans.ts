import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import { downloadFile } from '@/lib/download';
import { fetchAll } from '@/lib/fetch-all';
import {
    ApiError,
    apiErrorFrom,
    defaultHeaders,
    type ApiErrorBody,
} from '@/lib/http';

export type Plan = components['schemas']['PlanResource'];
export type PlanVersion = components['schemas']['PlanVersionResource'];
export type PlanLine = Omit<
    components['schemas']['PlanLineResource'],
    'dimensions'
> & {
    /** Dimension type key => dimension value ID; empty for general activity. */
    dimensions: Record<string, string>;
};
export type PlanVersionStatus = 'draft' | 'published' | 'archived';
export type PlanningPolicy = {
    require_distinct_publisher: boolean;
    default_versions: { fiscal_year_id: string; plan_version_id: string }[];
    lock_version: number;
};

/** The API caps a request at 500 cells (PL-FR-02). */
export const MAX_CELLS_PER_REQUEST = 500;

export const plansQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'plans', 'list'],
        queryFn: async (): Promise<Plan[]> =>
            fetchAll((page) =>
                unwrap(
                    api.GET('/companies/{companyId}/plans', {
                        params: {
                            path: { companyId },
                            query: { per_page: 200, page },
                        },
                    }),
                ),
            ),
    });

export const versionsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'plans', 'versions'],
        queryFn: async (): Promise<PlanVersion[]> =>
            fetchAll((page) =>
                unwrap(
                    api.GET('/companies/{companyId}/plan-versions', {
                        params: {
                            path: { companyId },
                            query: { per_page: 200, page },
                        },
                    }),
                ),
            ),
    });

export const versionQuery = (companyId: string, id: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'plans', 'version', id],
        queryFn: async (): Promise<PlanVersion> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/plan-versions/{id}', {
                        params: { path: { companyId, id } },
                    }),
                )
            ).data,
    });

export const planQuery = (companyId: string, id: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'plans', 'one', id],
        queryFn: async (): Promise<Plan> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/plans/{id}', {
                        params: { path: { companyId, id } },
                    }),
                )
            ).data,
    });

/** Every line of a version: a grid holds the whole version, so pages are followed to the end. */
export const linesQuery = (companyId: string, versionId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'plans', 'lines', versionId],
        queryFn: async (): Promise<PlanLine[]> =>
            (await fetchAll((page) =>
                unwrap(
                    api.GET('/companies/{companyId}/plan-versions/{id}/lines', {
                        params: {
                            path: { companyId, id: versionId },
                            query: { per_page: 500, page },
                        },
                    }),
                ),
            )) as PlanLine[],
    });

export const planningPolicyQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'plans', 'policy'],
        queryFn: async (): Promise<PlanningPolicy> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/planning-policy', {
                        params: { path: { companyId } },
                    }),
                )
            ).data as PlanningPolicy,
    });

export async function createPlan(
    companyId: string,
    body: components['schemas']['CreatePlanRequest'],
): Promise<Plan> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/plans', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

/** `lock_version` is required; the fiscal year cannot change. */
export async function updatePlan(
    companyId: string,
    id: string,
    body: components['schemas']['UpdatePlanRequest'],
): Promise<Plan> {
    return (
        await unwrap(
            api.PATCH('/companies/{companyId}/plans/{id}', {
                params: { path: { companyId, id } },
                body,
            }),
        )
    ).data;
}

export async function createVersion(
    companyId: string,
    planId: string,
    name: string,
): Promise<PlanVersion> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/plans/{id}/versions', {
                params: { path: { companyId, id: planId } },
                body: { name },
            }),
        )
    ).data;
}

/** Renames a draft; a published or archived version cannot change (PL-FR-01). */
export async function renameVersion(
    companyId: string,
    id: string,
    body: components['schemas']['UpdatePlanVersionRequest'],
): Promise<PlanVersion> {
    return (
        await unwrap(
            api.PATCH('/companies/{companyId}/plan-versions/{id}', {
                params: { path: { companyId, id } },
                body,
            }),
        )
    ).data;
}

/** A new draft that copies a published version and keeps it as its origin (PL-AC-01). */
export async function reviseVersion(
    companyId: string,
    id: string,
    name: string,
): Promise<PlanVersion> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/plan-versions/{id}/revise', {
                params: { path: { companyId, id } },
                body: { name },
            }),
        )
    ).data;
}

export async function publishVersion(
    companyId: string,
    id: string,
    lockVersion: number,
): Promise<PlanVersion> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/plan-versions/{id}/publish', {
                params: { path: { companyId, id } },
                body: { lock_version: lockVersion },
            }),
        )
    ).data;
}

export async function updatePlanningPolicy(
    companyId: string,
    body: {
        lock_version: number;
        require_distinct_publisher: boolean;
        default_versions: {
            fiscal_year_id: string;
            plan_version_id: string | null;
        }[];
    },
): Promise<PlanningPolicy> {
    return (
        await unwrap(
            api.PUT('/companies/{companyId}/planning-policy', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data as PlanningPolicy;
}

export type Cell = {
    period_id: string;
    account_id: string;
    dimensions: Record<string, string>;
    amount: string;
    note?: string | null;
};

/**
 * Enters cells in a draft version, in requests of at most 500. Each request is all or nothing, and
 * the cells before a refused request stay saved. A refusal names problems as `lines.<index>.<field>`,
 * with the index counted in `cells`.
 */
export async function enterCells(
    companyId: string,
    versionId: string,
    cells: Cell[],
): Promise<{ created: number; updated: number }> {
    const total = { created: 0, updated: 0 };

    for (let at = 0; at < cells.length; at += MAX_CELLS_PER_REQUEST) {
        try {
            const { data } = await unwrap(
                api.PUT('/companies/{companyId}/plan-versions/{id}/lines', {
                    params: { path: { companyId, id: versionId } },
                    body: {
                        lines: cells.slice(at, at + MAX_CELLS_PER_REQUEST).map(
                            (cell) =>
                                ({
                                    ...cell,
                                    dimensions:
                                        Object.keys(cell.dimensions).length > 0
                                            ? cell.dimensions
                                            : undefined,
                                }) as never,
                        ),
                    },
                }),
            );

            total.created += data.created;
            total.updated += data.updated;
        } catch (error) {
            // Report problems against the caller's list, not the request that failed.
            if (error instanceof ApiError && at > 0) {
                throw new ApiError(
                    error.status,
                    error.message,
                    error.code,
                    Object.fromEntries(
                        Object.entries(error.errors).map(([key, messages]) => [
                            key.replace(
                                /^lines\.(\d+)/,
                                (_, index: string) =>
                                    `lines.${Number(index) + at}`,
                            ),
                            messages,
                        ]),
                    ),
                    error.retryAfter,
                );
            }

            throw error;
        }
    }

    return total;
}

export async function deleteLine(
    companyId: string,
    versionId: string,
    lineId: string,
): Promise<void> {
    await unwrap(
        api.DELETE('/companies/{companyId}/plan-versions/{id}/lines/{lineId}', {
            params: { path: { companyId, id: versionId, lineId } },
        }),
    );
}

/** From another version of the same fiscal year, or from what was posted in a period (PL-FR-03). */
export async function copyLines(
    companyId: string,
    versionId: string,
    source:
        | { from_version_id: string }
        | { actuals_period_id: string; to_period_id: string },
): Promise<{ created: number; updated: number; skipped?: number }> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/plan-versions/{id}/lines/copy', {
                params: { path: { companyId, id: versionId } },
                body: source,
            }),
        )
    ).data;
}

/**
 * Imports a CSV (columns `period`, `account_code`, `amount`, optionally `note` and `dimension.<type key>`).
 * All or nothing: the API refuses the whole file and names every bad row, as `rows.<line>.<field>` errors.
 */
export async function importLines(
    companyId: string,
    versionId: string,
    csv: string,
): Promise<{ created: number; updated: number }> {
    let response: Response;

    try {
        response = await fetch(
            `/api/v1/companies/${companyId}/plan-versions/${versionId}/lines/import`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    ...defaultHeaders('POST'),
                    'Content-Type': 'text/csv',
                },
                body: csv,
            },
        );
    } catch {
        throw new ApiError(0, 'Network error', 'NETWORK');
    }

    const body = (await response.json().catch(() => ({}))) as {
        data?: { created: number; updated: number };
    } & Partial<ApiErrorBody>;

    if (!response.ok || body.data === undefined) {
        throw apiErrorFrom(response, body);
    }

    return body.data;
}

/** The lines as CSV, in the layout the import reads (X-FR-02). */
export function exportLines(companyId: string, versionId: string) {
    return downloadFile(
        `/api/v1/companies/${companyId}/plan-versions/${versionId}/lines/export`,
        'text/csv',
        `plan-lines-${versionId}.csv`,
    );
}
