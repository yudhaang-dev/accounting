import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';

export const postingProfilesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'posting-profiles'],
        queryFn: async () =>
            (
                await unwrap(
                    api.GET('/companies/{companyId}/posting-profiles', {
                        params: { path: { companyId } },
                    }),
                )
            ).data,
    });
