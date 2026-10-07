import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { operations } from '@/api/schema';
import { fetchAll } from '@/lib/fetch-all';
import type { ReportResult } from '@/modules/reporting/report-definitions';
import {
    filterParams,
    type DateMode,
    type ReportFilter,
} from '@/modules/reporting/report-filter';

type Show =
    operations['report-snapshots.show']['responses'][200]['content']['application/json']['data'];

/** A published report without its values (RP-FR-14). */
export type ReportSnapshotSummary = Omit<Show, 'result'>;

/** A snapshot with the result it was published with. */
export type ReportSnapshot = Omit<Show, 'result'> & { result: ReportResult };

export const snapshotsQuery = (companyId: string, definitionId: string) =>
    queryOptions({
        queryKey: [
            'companies',
            companyId,
            'report-snapshots',
            'list',
            definitionId,
        ],
        queryFn: async (): Promise<ReportSnapshotSummary[]> =>
            fetchAll((page) =>
                unwrap(
                    api.GET('/companies/{companyId}/report-snapshots', {
                        params: {
                            path: { companyId },
                            query: {
                                definition_id: definitionId,
                                per_page: 200,
                                page,
                            } as never,
                        },
                    }),
                ),
            ) as Promise<ReportSnapshotSummary[]>,
    });

export const snapshotQuery = (companyId: string, id: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'report-snapshots', id],
        // A user with a data scope gets 403: the page says so instead of retrying.
        retry: false,
        queryFn: async (): Promise<ReportSnapshot> =>
            (
                (await unwrap(
                    api.GET('/companies/{companyId}/report-snapshots/{id}', {
                        params: { path: { companyId, id } },
                    }),
                )) as unknown as { data: ReportSnapshot }
            ).data,
    });

/** The filter of a report as the JSON body of a publication. */
function publishBody(filter: ReportFilter, mode: DateMode, reason: string) {
    const params = filterParams(filter, mode);
    const body: Record<string, unknown> = {};

    for (const key of ['from', 'to', 'as_of', 'source', 'plan_version_id']) {
        const value = params.get(key);

        if (value) {
            body[key] = value;
        }
    }

    const dimensions = Object.fromEntries(
        Object.entries(filter.dimensions).filter(([, value]) => value),
    );

    if (Object.keys(dimensions).length > 0) {
        body.dimensions = dimensions;
    }

    if (filter.transaction_type_keys.length > 0) {
        body.transaction_type_keys = filter.transaction_type_keys;
    }

    if (reason !== '') {
        body.reason = reason;
    }

    return body;
}

/** Runs the definition with the filter on screen and keeps what it produced. */
export async function publishSnapshot(
    companyId: string,
    definitionId: string,
    filter: ReportFilter,
    mode: DateMode,
    reason: string,
): Promise<ReportSnapshotSummary> {
    return (
        await unwrap(
            api.POST(
                '/companies/{companyId}/report-definitions/{id}/snapshots',
                {
                    params: { path: { companyId, id: definitionId } },
                    body: publishBody(filter, mode, reason) as never,
                },
            ),
        )
    ).data as ReportSnapshotSummary;
}

/** The same parameters against the definition and mapping in force now; adds a snapshot (X-FR-03). */
export async function recomputeSnapshot(
    companyId: string,
    id: string,
    reason: string,
): Promise<ReportSnapshotSummary> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/report-snapshots/{id}/recompute', {
                params: { path: { companyId, id } },
                body: (reason === '' ? {} : { reason }) as never,
            }),
        )
    ).data as ReportSnapshotSummary;
}

/** The definition changed after the snapshot was published, so a recalculation would differ. */
export const needsRecompute = (
    snapshot: Pick<ReportSnapshotSummary, 'definition_version'>,
    currentVersion: number,
): boolean => snapshot.definition_version < currentVersion;
