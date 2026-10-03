import { queryOptions } from '@tanstack/react-query';
import { api, unwrap } from '@/api/client';

/** What the entry form needs to know about one field of a structured version (TE-FR-04). */
export type SchemaField = {
    name: string;
    label: string;
    required: boolean;
    kind:
        | 'amount'
        | 'date'
        | 'text'
        | 'reference'
        | 'account'
        | 'dimension'
        /** Party, cash account and allocations come from the subledger, which the form cannot pick from yet. */
        | 'unsupported';
    /** For an account field: the accounts it is limited to; none means every account. */
    accountIds?: string[];
    /** For a dimension field: the key of the dimension type whose values are offered. */
    dimensionType?: string;
    maxLength?: number;
};

type JsonSchema = {
    properties?: Record<string, Record<string, unknown>>;
    required?: string[];
};

/**
 * Reads the field schema the API publishes for a version (a subset of JSON Schema) into the fields the
 * form draws, in the order the version defines them.
 */
export function parseSchema(schema: JsonSchema | null): SchemaField[] {
    if (!schema?.properties) {
        return [];
    }

    const required = new Set(schema.required ?? []);

    return Object.entries(schema.properties).map(([name, property]) => {
        const reference = property['x-reference'];
        const field: SchemaField = {
            name,
            label: typeof property.title === 'string' ? property.title : name,
            required: required.has(name),
            kind: 'text',
        };

        if (property.type === 'array' || reference === 'party') {
            field.kind = 'unsupported';
        } else if (reference === 'cash_account') {
            field.kind = 'unsupported';
        } else if (reference === 'account') {
            field.kind = 'account';
            field.accountIds = Array.isArray(property.enum)
                ? (property.enum as string[])
                : undefined;
        } else if (reference === 'dimension_value') {
            field.kind = 'dimension';
            field.dimensionType =
                typeof property['x-dimension-type'] === 'string'
                    ? property['x-dimension-type']
                    : undefined;
        } else if (property['x-scale'] === 'currency') {
            field.kind = 'amount';
        } else if (property.format === 'date') {
            field.kind = 'date';
        } else {
            field.kind = property.maxLength === 100 ? 'reference' : 'text';
            field.maxLength =
                typeof property.maxLength === 'number'
                    ? property.maxLength
                    : undefined;
        }

        return field;
    });
}

export const versionSchemaQuery = (
    companyId: string,
    typeId: string,
    version: number,
) =>
    queryOptions({
        queryKey: [
            'companies',
            companyId,
            'transaction-types',
            typeId,
            'schema',
            version,
        ],
        staleTime: 5 * 60_000,
        queryFn: async (): Promise<SchemaField[]> =>
            parseSchema(
                (
                    await unwrap(
                        api.GET(
                            '/companies/{companyId}/transaction-types/{id}/versions/{version}/schema',
                            {
                                params: {
                                    path: { companyId, id: typeId, version },
                                },
                            },
                        ),
                    )
                ).data as JsonSchema | null,
            ),
    });

/** The values as the API takes them: a field left empty is left out, so "required" is the server's call. */
export function valuesBody(
    fields: SchemaField[],
    values: Record<string, string>,
): Record<string, string> {
    return Object.fromEntries(
        fields
            .filter((field) => field.kind !== 'unsupported')
            .map((field) => [field.name, (values[field.name] ?? '').trim()])
            .filter(([, value]) => value !== ''),
    );
}
