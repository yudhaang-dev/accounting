import { keepPreviousData, queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import { fetchAll } from '@/lib/fetch-all';

export type SubledgerDocument = components['schemas']['DocumentResource'];
export type OpenItem = components['schemas']['OpenItemResource'];
export type DocumentKind = components['schemas']['DocumentKind'];

export const DOCUMENT_KINDS: DocumentKind[] = [
    'invoice',
    'credit_note',
    'payment',
    'advance',
    'write_off',
    'loan',
];

export const OPEN_ITEM_STATUSES = ['open', 'settled', 'reversed'] as const;
export type OpenItemStatus = (typeof OPEN_ITEM_STATUSES)[number];

export const CONTROL_ROLE_KEYS = [
    'receivables_control',
    'payables_control',
] as const;
export type ControlRoleKey = (typeof CONTROL_ROLE_KEYS)[number];

/** Every party, for the picker and the names a row shows. A company has hundreds, not millions. */
export const partyOptionsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'parties', 'options'],
        queryFn: async () =>
            fetchAll((page) =>
                unwrap(
                    api.GET('/companies/{companyId}/parties', {
                        params: {
                            path: { companyId },
                            query: { per_page: 200, page },
                        },
                    }),
                ),
            ),
    });

/** The open items of one party that can still be settled, for the allocations of an entry (SL-FR-05). */
export const partyOpenItemsQuery = (companyId: string, partyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'open-items', 'party', partyId],
        queryFn: async () =>
            fetchAll((page) =>
                unwrap(
                    api.GET('/companies/{companyId}/open-items', {
                        params: {
                            path: { companyId },
                            query: {
                                'filter[party_id]': partyId,
                                'filter[status]': 'open',
                                per_page: 200,
                                page,
                            },
                        },
                    }),
                ),
            ),
    });

export type DocumentFilters = {
    partyId?: string;
    kind?: DocumentKind;
    dateFrom: string;
    dateTo: string;
    page: number;
    perPage: number;
};

export type DocumentPage = {
    documents: SubledgerDocument[];
    page: number;
    total: number;
};

export const documentsQuery = (companyId: string, filters: DocumentFilters) =>
    queryOptions({
        queryKey: ['companies', companyId, 'documents', 'list', filters],
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<DocumentPage> => {
            const body = await unwrap(
                api.GET('/companies/{companyId}/documents', {
                    params: {
                        path: { companyId },
                        query: {
                            'filter[party_id]': filters.partyId,
                            'filter[kind]': filters.kind,
                            'filter[date_from]': filters.dateFrom || undefined,
                            'filter[date_to]': filters.dateTo || undefined,
                            page: filters.page,
                            per_page: filters.perPage,
                        },
                    },
                }),
            );

            return {
                documents: body.data,
                page: body.meta.current_page,
                total: body.meta.total,
            };
        },
    });

export type OpenItemFilters = {
    partyId?: string;
    controlRole?: ControlRoleKey;
    kind?: DocumentKind;
    status?: OpenItemStatus;
    dueTo: string;
    page: number;
    perPage: number;
};

export type OpenItemPage = {
    items: OpenItem[];
    page: number;
    total: number;
};

export const openItemsQuery = (companyId: string, filters: OpenItemFilters) =>
    queryOptions({
        queryKey: ['companies', companyId, 'open-items', 'list', filters],
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<OpenItemPage> => {
            const body = await unwrap(
                api.GET('/companies/{companyId}/open-items', {
                    params: {
                        path: { companyId },
                        query: {
                            'filter[party_id]': filters.partyId,
                            'filter[control_role]': filters.controlRole,
                            'filter[kind]': filters.kind,
                            'filter[status]': filters.status,
                            'filter[due_to]': filters.dueTo || undefined,
                            page: filters.page,
                            per_page: filters.perPage,
                        },
                    },
                }),
            );

            return {
                items: body.data,
                page: body.meta.current_page,
                total: body.meta.total,
            };
        },
    });
