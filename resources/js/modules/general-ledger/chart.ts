import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { operations } from '@/api/schema';

type Json<
    Op extends keyof operations,
    Status extends number,
> = operations[Op] extends {
    responses: Record<Status, { content: { 'application/json': infer Body } }>;
}
    ? Body
    : never;

export type TemplateSummary =
    Json<'account-templates.index', 200> extends {
        data: (infer Item)[];
    }
        ? Item
        : never;
export type Template =
    Json<'account-templates.show', 200> extends {
        data: infer Data;
    }
        ? Data
        : never;
export type TemplateAccount = Template extends { accounts: (infer A)[] }
    ? A
    : never;

export const accountTemplatesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'account-templates'],
        staleTime: Infinity,
        queryFn: async (): Promise<TemplateSummary[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/account-templates', {
                        params: { path: { companyId } },
                    }),
                )
            ).data,
    });

/** Every account of one template, for review before the import is confirmed (X-AC-03). */
export const accountTemplateQuery = (companyId: string, key: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'account-templates', key],
        staleTime: Infinity,
        queryFn: async (): Promise<Template> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/account-templates/{key}', {
                        params: { path: { companyId, key } },
                    }),
                )
            ).data,
    });

/** How many accounts the company has; zero means the chart is still empty. */
export const chartSizeQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'chart-size'],
        queryFn: async (): Promise<number> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/accounts', {
                        params: {
                            path: { companyId },
                            query: { per_page: 1 },
                        },
                    }),
                )
            ).meta.total,
    });

/** `confirmed` is always true here: the screen only calls this after the user confirmed (X-AC-03). */
export async function importTemplate(
    companyId: string,
    input: { template: string; exclude: string[] },
) {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/accounts/import-template', {
                params: { path: { companyId } },
                body: { ...input, confirmed: true },
            }),
        )
    ).data;
}
