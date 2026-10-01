import { queryOptions } from '@tanstack/react-query';
import { api } from '@/api/client';
import type { components } from '@/api/schema';

export type Company = components['schemas']['CompanyResource'];

export const companyQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId],
        queryFn: async (): Promise<Company> => {
            const { data } = await api.GET('/companies/{companyId}', {
                params: { path: { companyId } },
            });

            if (!data) {
                throw new Error(`Unexpected /companies/${companyId} response`);
            }

            return data.data;
        },
    });
