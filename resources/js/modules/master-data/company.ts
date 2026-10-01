import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';

export type Company = components['schemas']['CompanyResource'];
export type UpdateCompanyInput = components['schemas']['UpdateCompanyRequest'];

export const companyQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId],
        queryFn: async (): Promise<Company> =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}', {
                        params: { path: { companyId } },
                    }),
                )
            ).data,
    });

/** `lock_version` is required: a stale one is answered with 409 VERSION_CONFLICT (API-TR-14). */
export async function updateCompany(
    companyId: string,
    body: UpdateCompanyInput,
): Promise<Company> {
    return (
        await unwrap(
            api.PATCH('/companies/{companyId}', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

/** Answers 422 COMPANY_NOT_READY with the missing prerequisites, keyed by requirement (X-AC-03). */
export async function activateCompany(companyId: string) {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/activate', {
                params: { path: { companyId } },
            }),
        )
    ).data;
}
