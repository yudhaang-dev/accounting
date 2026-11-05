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

export type PackSummary =
    Json<'template-packs.index', 200> extends { data: (infer Item)[] }
        ? Item
        : never;
export type Pack =
    Json<'template-packs.show', 200> extends { data: infer Data }
        ? Data
        : never;
export type PackComponent = Pack extends { components: (infer C)[] }
    ? C
    : never;
export type PackItem = PackComponent extends { items: (infer I)[] } ? I : never;
export type InstallResult =
    Json<'template-packs.install', 200> extends { data: infer Data }
        ? Data
        : never;

export const templatePacksQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'template-packs'],
        staleTime: Infinity,
        retry: false,
        queryFn: async (): Promise<PackSummary[]> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/template-packs', {
                        params: { path: { companyId } },
                    }),
                )
            ).data,
    });

/** What the pack would create in this company, item by item; nothing is created (X-AC-06). */
export const templatePackQuery = (companyId: string, key: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'template-packs', key],
        queryFn: async (): Promise<Pack> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/template-packs/{key}', {
                        params: { path: { companyId, key } },
                    }),
                )
            ).data,
    });

/** `confirmed` is always true here: the screen only calls this after the user confirmed (X-AC-06). */
export async function installPack(
    companyId: string,
    key: string,
    input: { components: string[]; exclude: Record<string, string[]> },
): Promise<InstallResult> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/template-packs/{key}/install', {
                params: { path: { companyId, key } },
                // The generated type reads the map of excluded items as a list of lists.
                body: {
                    ...input,
                    exclude: input.exclude as unknown as string[][],
                    confirmed: true,
                },
            }),
        )
    ).data;
}
