import { keepPreviousData, queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components, operations } from '@/api/schema';

export type BankStatement = components['schemas']['BankStatementResource'];
export type BankStatementLine =
    components['schemas']['BankStatementLineResource'];
export type BankImportMapping =
    components['schemas']['BankImportMappingResource'];
export type SaveBankImportMappingInput =
    components['schemas']['SaveBankImportMappingRequest'];
export type ImportBankStatementInput =
    components['schemas']['ImportBankStatementRequest'];

type Json<
    Operation extends keyof operations,
    Status extends number,
> = operations[Operation] extends {
    responses: Record<Status, { content: { 'application/json': infer Body } }>;
}
    ? Body
    : never;

export type BankReport =
    Json<'bank-statements.report', 200> extends {
        data: infer Data;
    }
        ? Data
        : never;
export type BankSuggestion = (Json<'bank-statements.suggestions', 200> extends {
    data: infer Data;
}
    ? Data
    : never)[number];

export const DELIMITERS = [',', ';', '\t', '|'] as const;
export const DATE_FORMATS = [
    'Y-m-d',
    'd/m/Y',
    'd-m-Y',
    'm/d/Y',
    'd.m.Y',
    'Ymd',
] as const;
export const DECIMAL_SEPARATORS = ['.', ','] as const;

const everything = (companyId: string) =>
    ['companies', companyId, 'bank-statements'] as const;

export const bankMappingQuery = (companyId: string, cashAccountId: string) =>
    queryOptions({
        queryKey: [...everything(companyId), 'mapping', cashAccountId],
        queryFn: async (): Promise<BankImportMapping | null> =>
            (
                await unwrap(
                    api.GET(
                        '/companies/{companyId}/cash-accounts/{id}/import-mapping',
                        { params: { path: { companyId, id: cashAccountId } } },
                    ),
                )
            ).data,
    });

/** The first save creates the mapping; later saves carry the `lock_version` that was read. */
export async function saveBankMapping(
    companyId: string,
    cashAccountId: string,
    body: SaveBankImportMappingInput,
): Promise<BankImportMapping> {
    return (
        await unwrap(
            api.PUT(
                '/companies/{companyId}/cash-accounts/{id}/import-mapping',
                { params: { path: { companyId, id: cashAccountId } }, body },
            ),
        )
    ).data;
}

export type BankStatementFilters = {
    cashAccountId?: string;
    page: number;
    perPage: number;
};

export type BankStatementPage = {
    statements: BankStatement[];
    page: number;
    total: number;
};

export const bankStatementsQuery = (
    companyId: string,
    filters: BankStatementFilters,
) =>
    queryOptions({
        queryKey: [...everything(companyId), 'list', filters],
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<BankStatementPage> => {
            const body = await unwrap(
                api.GET('/companies/{companyId}/bank-statements', {
                    params: {
                        path: { companyId },
                        query: {
                            'filter[cash_account_id]': filters.cashAccountId,
                            page: filters.page,
                            per_page: filters.perPage,
                        },
                    },
                }),
            );

            return {
                statements: body.data,
                page: body.meta.current_page,
                total: body.meta.total,
            };
        },
    });

export const bankStatementQuery = (companyId: string, id: string) =>
    queryOptions({
        queryKey: [...everything(companyId), 'detail', id],
        queryFn: async (): Promise<BankStatement> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/bank-statements/{id}', {
                        params: { path: { companyId, id } },
                    }),
                )
            ).data,
    });

export type BankLineFilters = {
    /** `undefined` lists every line, `true` the matched ones, `false` the open ones. */
    matched?: boolean;
    page: number;
    perPage: number;
};

export type BankLinePage = {
    lines: BankStatementLine[];
    page: number;
    total: number;
};

export const bankLinesQuery = (
    companyId: string,
    statementId: string,
    filters: BankLineFilters,
) =>
    queryOptions({
        queryKey: [...everything(companyId), 'lines', statementId, filters],
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<BankLinePage> => {
            const body = await unwrap(
                api.GET('/companies/{companyId}/bank-statements/{id}/lines', {
                    params: {
                        path: { companyId, id: statementId },
                        query: {
                            // Laravel's `boolean` rule reads 1 and 0, not the "false" the client would write.
                            'filter[matched]':
                                filters.matched === undefined
                                    ? undefined
                                    : (Number(
                                          filters.matched,
                                      ) as unknown as boolean),
                            page: filters.page,
                            per_page: filters.perPage,
                        },
                    },
                }),
            );

            return {
                lines: body.data,
                page: body.meta.current_page,
                total: body.meta.total,
            };
        },
    });

export const bankSuggestionsQuery = (
    companyId: string,
    statementId: string,
    windowDays: number,
) =>
    queryOptions({
        queryKey: [
            ...everything(companyId),
            'suggestions',
            statementId,
            windowDays,
        ],
        queryFn: async (): Promise<BankSuggestion[]> =>
            (
                await unwrap(
                    api.GET(
                        '/companies/{companyId}/bank-statements/{id}/suggestions',
                        {
                            params: {
                                path: { companyId, id: statementId },
                                query: { window_days: windowDays },
                            },
                        },
                    ),
                )
            ).data,
    });

export const bankReportQuery = (companyId: string, statementId: string) =>
    queryOptions({
        queryKey: [...everything(companyId), 'report', statementId],
        queryFn: async (): Promise<BankReport> =>
            (
                await unwrap(
                    api.GET(
                        '/companies/{companyId}/bank-statements/{id}/report',
                        { params: { path: { companyId, id: statementId } } },
                    ),
                )
            ).data,
    });

export async function importBankStatement(
    companyId: string,
    cashAccountId: string,
    body: ImportBankStatementInput,
): Promise<BankStatement> {
    return (
        await unwrap(
            api.POST(
                '/companies/{companyId}/cash-accounts/{id}/bank-statements',
                { params: { path: { companyId, id: cashAccountId } }, body },
            ),
        )
    ).data;
}

export async function matchBankLine(
    companyId: string,
    lineId: string,
    cashTransactionId: string,
): Promise<BankStatementLine> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/bank-statement-lines/{id}/match', {
                params: { path: { companyId, id: lineId } },
                body: { cash_transaction_id: cashTransactionId },
            }),
        )
    ).data;
}

export async function unmatchBankLine(
    companyId: string,
    lineId: string,
): Promise<BankStatementLine> {
    return (
        await unwrap(
            api.POST(
                '/companies/{companyId}/bank-statement-lines/{id}/unmatch',
                { params: { path: { companyId, id: lineId } } },
            ),
        )
    ).data;
}

/** Everything a match or an import changes: statements, lines, report, and the cleared marks of cash transactions. */
export const bankInvalidationKeys = (companyId: string) => [
    everything(companyId),
    ['companies', companyId, 'cash-transactions'],
];
