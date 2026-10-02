import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';

export type Account = components['schemas']['AccountResource'];
export type AccountClass = components['schemas']['AccountClass'];
export type AccountStatus = components['schemas']['AccountStatus'];

/**
 * The generated request types give `dimension_requirements` as a list (a limit of the generator);
 * the API takes an object of dimension type key => requirement, which is what these declare.
 */
type WithRequirements<T> = Omit<T, 'dimension_requirements'> & {
    dimension_requirements?: Record<string, DimensionRequirement>;
};

/** Hands the body to the generated client, whose type disagrees with the API on `dimension_requirements`. */
const asWire = <T>(body: unknown): T => body as T;

export type CreateAccountInput = WithRequirements<
    components['schemas']['CreateAccountRequest']
>;
export type UpdateAccountInput = WithRequirements<
    components['schemas']['UpdateAccountRequest']
>;

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
                body: asWire<components['schemas']['CreateAccountRequest']>(
                    body,
                ),
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
                body: asWire<components['schemas']['UpdateAccountRequest']>(
                    body,
                ),
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

export type PostingSource = components['schemas']['JournalSource'];
export type CurrencyRestriction = components['schemas']['CurrencyRestriction'];
export type DimensionRequirement =
    components['schemas']['DimensionRequirement'];

export const POSTING_SOURCES: PostingSource[] = [
    'entry',
    'subledger',
    'system',
];

export const CURRENCY_RESTRICTIONS: CurrencyRestriction[] = [
    'any',
    'functional_only',
    'specific',
];

export const DIMENSION_REQUIREMENTS: DimensionRequirement[] = [
    'optional',
    'required',
    'forbidden',
];

/**
 * What an account demands of each dimension type (GL-FR-02), as type key => requirement; a type that
 * is not listed is optional. The OpenAPI document types this field as a string (a limit of the
 * generator), but on the wire it is an object, so it is read as one here.
 */
export function dimensionRequirementsOf(
    account?: Pick<Account, 'dimension_requirements'>,
): Record<string, DimensionRequirement> {
    const value: unknown = account?.dimension_requirements;

    return value !== null && typeof value === 'object'
        ? (value as Record<string, DimensionRequirement>)
        : {};
}

/** A control account is kept by a subledger: it takes neither entries nor system postings (GL-FR-07). */
export const isControlAccount = (
    account: Pick<Account, 'allowed_sources'>,
): boolean =>
    !account.allowed_sources.includes('entry') &&
    !account.allowed_sources.includes('system');
