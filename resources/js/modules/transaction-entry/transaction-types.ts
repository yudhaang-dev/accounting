import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import { fetchAll } from '@/lib/fetch-all';
import type { components } from '@/api/schema';

type Schemas = components['schemas'];

export type FieldType = Schemas['FieldType'];
export type FieldDef = Schemas['VersionRequest']['fields'][number];
export type PostingRule = NonNullable<
    Schemas['VersionRequest']['posting_rules']
>[number];
export type VersionInput = Schemas['VersionRequest'];
export type SubledgerRoute = NonNullable<VersionInput['subledger_route']>;
export type ResetPeriod = Schemas['ResetPeriod'];
export type NumberPolicy = Schemas['NumberingRequest']['policy'];

export type VersionMode = 'free_lines' | 'structured';
export type VersionStatus = 'draft' | 'published' | 'retired';

/** The generated type leaves the version columns as plain strings, so the screens read this one. */
export type TypeVersion = {
    version: number;
    mode: VersionMode;
    status: VersionStatus;
    fields: FieldDef[] | null;
    posting_rules: PostingRule[] | null;
    allowed_currencies: string[] | null;
    rate_type_key: string | null;
    allow_rate_input: boolean;
    subledger_route: SubledgerRoute | null;
};

export type Numbering = {
    pattern: string;
    padding: number;
    reset: ResetPeriod;
    policy: NumberPolicy;
};

export type TransactionType = Omit<
    Schemas['TransactionTypeResource'],
    'versions' | 'numbering'
> & {
    versions: TypeVersion[];
    numbering: Numbering;
};

export const FIELD_TYPES: FieldType[] = [
    'amount',
    'date',
    'text',
    'reference',
    'account',
    'party',
    'cash_account',
    'dimension',
    'allocations',
];

export const RESET_PERIODS: ResetPeriod[] = [
    'never',
    'yearly',
    'monthly',
    'daily',
];

const asType = (resource: Schemas['TransactionTypeResource']) =>
    resource as unknown as TransactionType;

export const transactionTypesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'transaction-types'],
        queryFn: async (): Promise<TransactionType[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/transaction-types', {
                        params: { path: { companyId } },
                    }),
                )
            ).data.map(asType),
    });

/** The version entries are made against: the highest one that is published. */
export function activeVersion(type: TransactionType): TypeVersion | undefined {
    return type.versions
        .filter((version) => version.status === 'published')
        .sort((a, b) => b.version - a.version)[0];
}

/** Types an entry can be made for: those with a published version. */
export function usableTypes(types: TransactionType[]): TransactionType[] {
    return types.filter((type) => activeVersion(type) !== undefined);
}

export async function createTransactionType(
    companyId: string,
    body: { key: string; label: string },
): Promise<TransactionType> {
    return asType(
        (
            await unwrap(
                api.POST('/companies/{companyId}/transaction-types', {
                    params: { path: { companyId } },
                    body,
                }),
            )
        ).data,
    );
}

export async function setNumbering(
    companyId: string,
    id: string,
    body: Numbering,
): Promise<TransactionType> {
    return asType(
        (
            await unwrap(
                api.PUT(
                    '/companies/{companyId}/transaction-types/{id}/numbering',
                    {
                        params: { path: { companyId, id } },
                        body,
                    },
                ),
            )
        ).data,
    );
}

export type TemplateSummary = {
    key: string;
    name: string;
    version: number;
    type_count: number;
};

export type TemplateType = {
    key: string;
    label: string;
    mode: VersionMode;
    fields: FieldDef[];
    posting_rules: PostingRule[];
};

export type Template = {
    key: string;
    name: string;
    version: number;
    types: TemplateType[];
};

export const templatesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'transaction-type-templates'],
        queryFn: async (): Promise<TemplateSummary[]> =>
            (
                await unwrap(
                    api.GET(
                        '/companies/{companyId}/transaction-type-templates',
                        { params: { path: { companyId } } },
                    ),
                )
            ).data as TemplateSummary[],
    });

export const templateQuery = (companyId: string, key: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'transaction-type-templates', key],
        queryFn: async (): Promise<Template> =>
            (
                await unwrap(
                    api.GET(
                        '/companies/{companyId}/transaction-type-templates/{key}',
                        { params: { path: { companyId, key } } },
                    ),
                )
            ).data as unknown as Template,
    });

/** Creates the picked types of a reviewed template; `confirmed` is the user's own confirmation (X-AC-03). */
export async function importTemplate(
    companyId: string,
    template: string,
    keys: string[],
): Promise<void> {
    await unwrap(
        api.POST('/companies/{companyId}/transaction-types/import-template', {
            params: { path: { companyId } },
            body: { template, confirmed: true, keys },
        }),
    );
}

export const transactionTypeQuery = (companyId: string, id: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'transaction-types', id],
        queryFn: async (): Promise<TransactionType> =>
            asType(
                (
                    await unwrap(
                        api.GET(
                            '/companies/{companyId}/transaction-types/{id}',
                            {
                                params: { path: { companyId, id } },
                            },
                        ),
                    )
                ).data,
            ),
    });

/** Active currencies a version may allow, functional first. Kept apart from the master data screens' cache. */
export type CurrencyOption = {
    code: string;
    name: string;
    is_functional: boolean;
};

export const currencyOptionsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'transaction-entry', 'currencies'],
        staleTime: 60_000,
        queryFn: async (): Promise<CurrencyOption[]> =>
            (
                await fetchAll((page) =>
                    unwrap(
                        api.GET('/companies/{companyId}/currencies', {
                            params: {
                                path: { companyId },
                                query: { per_page: 200, page },
                            },
                        }),
                    ),
                )
            )
                .filter((currency) => currency.status === 'active')
                .map(({ code, name, is_functional }) => ({
                    code,
                    name,
                    is_functional,
                })),
    });

export type RateTypeOption = { key: string; label: string };

export const rateTypeOptionsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'transaction-entry', 'rate-types'],
        staleTime: 60_000,
        queryFn: async (): Promise<RateTypeOption[]> =>
            (
                await fetchAll((page) =>
                    unwrap(
                        api.GET('/companies/{companyId}/rate-types', {
                            params: {
                                path: { companyId },
                                query: { per_page: 200, page },
                            },
                        }),
                    ),
                )
            )
                .filter((type) => type.status === 'active')
                .map(({ key, label }) => ({ key, label })),
    });

export type DimensionTypeOption = { key: string; label: string };

export const dimensionTypeOptionsQuery = (companyId: string) =>
    queryOptions({
        queryKey: [
            'companies',
            companyId,
            'transaction-entry',
            'dimension-types',
        ],
        staleTime: 60_000,
        queryFn: async (): Promise<DimensionTypeOption[]> =>
            (
                await fetchAll((page) =>
                    unwrap(
                        api.GET('/companies/{companyId}/dimension-types', {
                            params: {
                                path: { companyId },
                                query: {
                                    'filter[status]': 'active',
                                    per_page: 200,
                                    page,
                                },
                            },
                        }),
                    ),
                )
            ).map(({ key, label }) => ({ key, label })),
    });

export async function createVersion(
    companyId: string,
    id: string,
    body: VersionInput,
): Promise<TransactionType> {
    return asType(
        (
            await unwrap(
                api.POST(
                    '/companies/{companyId}/transaction-types/{id}/versions',
                    {
                        params: { path: { companyId, id } },
                        body,
                    },
                ),
            )
        ).data,
    );
}

export async function updateVersion(
    companyId: string,
    id: string,
    version: number,
    body: VersionInput,
): Promise<TransactionType> {
    return asType(
        (
            await unwrap(
                api.PUT(
                    '/companies/{companyId}/transaction-types/{id}/versions/{version}',
                    { params: { path: { companyId, id, version } }, body },
                ),
            )
        ).data,
    );
}

export async function publishVersion(
    companyId: string,
    id: string,
    version: number,
): Promise<TransactionType> {
    return asType(
        (
            await unwrap(
                api.POST(
                    '/companies/{companyId}/transaction-types/{id}/versions/{version}/publish',
                    { params: { path: { companyId, id, version } } },
                ),
            )
        ).data,
    );
}

export async function retireVersion(
    companyId: string,
    id: string,
    version: number,
): Promise<TransactionType> {
    return asType(
        (
            await unwrap(
                api.POST(
                    '/companies/{companyId}/transaction-types/{id}/versions/{version}/retire',
                    { params: { path: { companyId, id, version } } },
                ),
            )
        ).data,
    );
}
