import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';

export type Account = components['schemas']['AccountResource'];
export type AccountClass = components['schemas']['AccountClass'];
export type AccountStatus = components['schemas']['AccountStatus'];
export type CreateAccountInput = components['schemas']['CreateAccountRequest'];
export type UpdateAccountInput = components['schemas']['UpdateAccountRequest'];

export const ACCOUNT_CLASSES: AccountClass[] = [
    'asset',
    'liability',
    'equity',
    'income',
    'expense',
];

/**
 * The whole chart, fetched page by page until it is complete. A chart is a tree and a parent may be
 * on another page than its children, so the list screen filters, indents and pages it in the browser
 * from the complete set (a chart is hundreds of accounts, not millions).
 */
export const allAccountsQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'accounts', 'all'],
        queryFn: async (): Promise<Account[]> => {
            const accounts: Account[] = [];
            let page = 1;
            let lastPage = 1;

            do {
                const body = await unwrap(
                    api.GET('/companies/{companyId}/accounts', {
                        params: {
                            path: { companyId },
                            query: { per_page: 200, page },
                        },
                    }),
                );

                accounts.push(...body.data);
                lastPage = body.meta.last_page;
                page += 1;
            } while (page <= lastPage);

            return accounts;
        },
    });

/** How many levels below the top each account sits, from the parent links of the whole chart. */
export function accountDepths(accounts: Account[]): Map<string, number> {
    const parentOf = new Map(accounts.map((a) => [a.id, a.parent_id]));
    const depths = new Map<string, number>();

    for (const { id } of accounts) {
        let depth = 0;

        for (
            let parent = parentOf.get(id);
            parent;
            parent = parentOf.get(parent)
        ) {
            depth += 1;

            // A parent link loop cannot exist (the API refuses it); do not spin if data ever lies.
            if (depth > accounts.length) {
                break;
            }
        }

        depths.set(id, depth);
    }

    return depths;
}

export async function createAccount(
    companyId: string,
    body: CreateAccountInput,
): Promise<Account> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/accounts', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

/** `lock_version` is required; the class cannot change (a different class is a different account). */
export async function updateAccount(
    companyId: string,
    id: string,
    body: UpdateAccountInput,
): Promise<Account> {
    return (
        await unwrap(
            api.PATCH('/companies/{companyId}/accounts/{id}', {
                params: { path: { companyId, id } },
                body,
            }),
        )
    ).data;
}

export async function setAccountStatus(
    companyId: string,
    id: string,
    action: 'deactivate' | 'reactivate',
): Promise<Account> {
    const path = `/companies/{companyId}/accounts/{id}/${action}` as const;

    return (
        await unwrap(api.POST(path, { params: { path: { companyId, id } } }))
    ).data;
}
