import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import { fetchAll } from '@/lib/fetch-all';

export type JournalSource = components['schemas']['JournalSource'];

export const JOURNAL_SOURCES: JournalSource[] = ['entry', 'subledger', 'system'];

/** The filters every report shares (RP-FR-04). Empty means "do not narrow". */
export type ReportFilter = {
    from: string;
    to: string;
    /** A point in time for balance-style reports; replaces the range when the viewer uses it. */
    as_of: string;
    /** Dimension type key => dimension value ID. A parent value covers its descendants (server side). */
    dimensions: Record<string, string>;
    transaction_type_keys: string[];
    source: JournalSource | '';
    plan_version_id: string;
};

export const emptyFilter = (): ReportFilter => ({
    from: '',
    to: '',
    as_of: '',
    dimensions: {},
    transaction_type_keys: [],
    source: '',
    plan_version_id: '',
});

/** Which of the date inputs a screen offers. */
export type DateMode = 'range' | 'as_of';

/** Whether anything beyond the dates narrows the report. */
export const hasNarrowing = (filter: ReportFilter): boolean =>
    Object.values(filter.dimensions).some(Boolean) ||
    filter.transaction_type_keys.length > 0 ||
    filter.source !== '' ||
    filter.plan_version_id !== '';

export type FilterErrors = Partial<
    Record<
        'from' | 'to' | 'as_of',
        'common.required' | 'reports.filter.rangeInvalid'
    >
>;

/** Required dates and their order; the message is a translation key. */
export function validateFilter(
    filter: ReportFilter,
    mode: DateMode,
): FilterErrors {
    if (mode === 'as_of') {
        return filter.as_of ? {} : { as_of: 'common.required' };
    }

    const errors: FilterErrors = {};

    if (!filter.from) {
        errors.from = 'common.required';
    }

    if (!filter.to) {
        errors.to = 'common.required';
    }

    if (filter.from && filter.to && filter.from > filter.to) {
        errors.to = 'reports.filter.rangeInvalid';
    }

    return errors;
}

/**
 * The query string of a filter, in the shape the API reads: `dimensions[<type key>]=<value id>` and
 * `transaction_type_keys[]=<key>`. Empty parts are left out so a URL only names what narrows.
 */
export function filterParams(
    filter: ReportFilter,
    mode: DateMode,
): URLSearchParams {
    const params = new URLSearchParams();

    if (mode === 'as_of') {
        params.set('as_of', filter.as_of);
    } else {
        params.set('from', filter.from);
        params.set('to', filter.to);
    }

    for (const [key, value] of Object.entries(filter.dimensions)) {
        if (value) {
            params.set(`dimensions[${key}]`, value);
        }
    }

    for (const key of filter.transaction_type_keys) {
        params.append('transaction_type_keys[]', key);
    }

    if (filter.source) {
        params.set('source', filter.source);
    }

    if (filter.plan_version_id) {
        params.set('plan_version_id', filter.plan_version_id);
    }

    return params;
}

export type FilterDimensionType = {
    key: string;
    label: string;
    values: { id: string; code: string; name: string }[];
};

export type FilterOptions = {
    dimensionTypes: FilterDimensionType[];
    transactionTypes: { key: string; label: string }[];
    planVersions: { id: string; name: string }[];
};

/**
 * What the filter can pick from. Every dimension value counts, whatever its status or validity, because
 * a report looks at the past; a type with no value is left out. Loaded only once the viewer opens the
 * extra filters.
 */
export const filterOptionsQuery = (companyId: string, enabled: boolean) =>
    queryOptions({
        queryKey: ['companies', companyId, 'reports', 'filter-options'],
        enabled,
        staleTime: 60_000,
        queryFn: async (): Promise<FilterOptions> => {
            const [types, values, transactionTypes, versions] =
                await Promise.all([
                    fetchAll((page) =>
                        unwrap(
                            api.GET('/companies/{companyId}/dimension-types', {
                                params: {
                                    path: { companyId },
                                    query: { per_page: 200, page },
                                },
                            }),
                        ),
                    ),
                    fetchAll((page) =>
                        unwrap(
                            api.GET(
                                '/companies/{companyId}/dimension-values',
                                {
                                    params: {
                                        path: { companyId },
                                        query: { per_page: 200, page },
                                    },
                                },
                            ),
                        ),
                    ),
                    unwrap(
                        api.GET('/companies/{companyId}/transaction-types', {
                            params: { path: { companyId } },
                        }),
                    ),
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
                ]);

            return {
                dimensionTypes: types
                    .map((type) => ({
                        key: type.key,
                        label: type.label,
                        values: values
                            .filter(
                                (value) => value.dimension_type_id === type.id,
                            )
                            .map(({ id, code, name }) => ({ id, code, name })),
                    }))
                    .filter((type) => type.values.length > 0),
                transactionTypes: transactionTypes.data.map(
                    ({ key, label }) => ({ key, label }),
                ),
                planVersions: versions.map(({ id, name }) => ({ id, name })),
            };
        },
    });
