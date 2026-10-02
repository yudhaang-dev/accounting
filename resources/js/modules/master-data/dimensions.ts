import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';
import type { components } from '@/api/schema';
import { fetchAll } from '@/lib/fetch-all';

export type DimensionType = components['schemas']['DimensionTypeResource'];
export type DimensionValue = components['schemas']['DimensionValueResource'];
export type CreateDimensionTypeInput =
    components['schemas']['CreateDimensionTypeRequest'];
export type UpdateDimensionTypeInput =
    components['schemas']['UpdateDimensionTypeRequest'];
export type CreateDimensionValueInput =
    components['schemas']['CreateDimensionValueRequest'];
export type UpdateDimensionValueInput =
    components['schemas']['UpdateDimensionValueRequest'];

/** Every dimension type of the company, ordered by key (a company has a handful). */
export const dimensionTypesQuery = (companyId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'dimension-types'],
        queryFn: async (): Promise<DimensionType[]> =>
            fetchAll((page) =>
                unwrap(
                    api.GET('/companies/{companyId}/dimension-types', {
                        params: {
                            path: { companyId },
                            query: { per_page: 200, page },
                        },
                    }),
                ),
            ),
    });

/**
 * Every value of one type. Values form a tree and a parent may be on another page than its
 * children, so the screen indents and filters the complete set in the browser.
 */
export const dimensionValuesQuery = (companyId: string, typeId: string) =>
    queryOptions({
        queryKey: ['companies', companyId, 'dimension-values', typeId],
        queryFn: async (): Promise<DimensionValue[]> =>
            fetchAll((page) =>
                unwrap(
                    api.GET('/companies/{companyId}/dimension-values', {
                        params: {
                            path: { companyId },
                            query: {
                                'filter[dimension_type_id]': typeId,
                                per_page: 200,
                                page,
                            },
                        },
                    }),
                ),
            ),
    });

/** How many levels below the top each value sits, from the parent links of the whole set. */
export function valueDepths(values: DimensionValue[]): Map<string, number> {
    const parentOf = new Map(values.map((v) => [v.id, v.parent_id]));
    const depths = new Map<string, number>();

    for (const { id } of values) {
        let depth = 0;

        for (
            let parent = parentOf.get(id);
            parent;
            parent = parentOf.get(parent)
        ) {
            depth += 1;

            // A parent link loop cannot exist (the API refuses it); do not spin if data ever lies.
            if (depth > values.length) {
                break;
            }
        }

        depths.set(id, depth);
    }

    return depths;
}

/** The values in tree order: each parent followed by its children, siblings by code. */
export function inTreeOrder(values: DimensionValue[]): DimensionValue[] {
    const byParent = new Map<string | null, DimensionValue[]>();

    for (const value of values) {
        const key =
            value.parent_id !== null &&
            values.some((v) => v.id === value.parent_id)
                ? value.parent_id
                : null;

        byParent.set(key, [...(byParent.get(key) ?? []), value]);
    }

    const ordered: DimensionValue[] = [];
    const walk = (parent: string | null) => {
        for (const value of (byParent.get(parent) ?? []).sort((a, b) =>
            a.code.localeCompare(b.code),
        )) {
            ordered.push(value);
            walk(value.id);
        }
    };

    walk(null);

    return ordered;
}

/** The value and everything below it, so a value is never offered as its own ancestor. */
export function withDescendants(
    values: DimensionValue[],
    id: string,
): Set<string> {
    const found = new Set([id]);
    let grew = true;

    while (grew) {
        grew = false;

        for (const value of values) {
            if (
                value.parent_id !== null &&
                found.has(value.parent_id) &&
                !found.has(value.id)
            ) {
                found.add(value.id);
                grew = true;
            }
        }
    }

    return found;
}

export async function createDimensionType(
    companyId: string,
    body: CreateDimensionTypeInput,
): Promise<DimensionType> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/dimension-types', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

/** `lock_version` is required; the key cannot change. */
export async function updateDimensionType(
    companyId: string,
    id: string,
    body: UpdateDimensionTypeInput,
): Promise<DimensionType> {
    return (
        await unwrap(
            api.PATCH('/companies/{companyId}/dimension-types/{id}', {
                params: { path: { companyId, id } },
                body,
            }),
        )
    ).data;
}

export async function setDimensionTypeStatus(
    companyId: string,
    id: string,
    action: 'deactivate' | 'reactivate',
): Promise<DimensionType> {
    const path =
        `/companies/{companyId}/dimension-types/{id}/${action}` as const;

    return (
        await unwrap(api.POST(path, { params: { path: { companyId, id } } }))
    ).data;
}

export async function createDimensionValue(
    companyId: string,
    body: CreateDimensionValueInput,
): Promise<DimensionValue> {
    return (
        await unwrap(
            api.POST('/companies/{companyId}/dimension-values', {
                params: { path: { companyId } },
                body,
            }),
        )
    ).data;
}

/** `lock_version` is required; the type cannot change. */
export async function updateDimensionValue(
    companyId: string,
    id: string,
    body: UpdateDimensionValueInput,
): Promise<DimensionValue> {
    return (
        await unwrap(
            api.PATCH('/companies/{companyId}/dimension-values/{id}', {
                params: { path: { companyId, id } },
                body,
            }),
        )
    ).data;
}

export async function setDimensionValueStatus(
    companyId: string,
    id: string,
    action: 'deactivate' | 'reactivate',
): Promise<DimensionValue> {
    const path =
        `/companies/{companyId}/dimension-values/{id}/${action}` as const;

    return (
        await unwrap(api.POST(path, { params: { path: { companyId, id } } }))
    ).data;
}

/** Only a value that was never used and has no children can be deleted; otherwise it is deactivated. */
export async function deleteDimensionValue(
    companyId: string,
    id: string,
): Promise<void> {
    await unwrap(
        api.DELETE('/companies/{companyId}/dimension-values/{id}', {
            params: { path: { companyId, id } },
        }),
    );
}
