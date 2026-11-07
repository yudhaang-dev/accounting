<?php

namespace App\Modules\TransactionEntry\Domain;

/**
 * The field schema of a `structured` version, published as a subset of JSON Schema so the SPA and
 * integration clients can build forms and validate on their own (TE-FR-04).
 *
 * @phpstan-type FieldDefinition array{name: string, type: string, label: string, required?: bool, account_ids?: list<string>, account_classes?: list<string>, dimension_type?: string, allowed_roles?: list<string>}
 */
final class FieldSchema
{
    public const DECIMAL = '^[0-9]+(\.[0-9]+)?$';

    /**
     * @param  list<FieldDefinition>  $fields
     * @return array<string, mixed>
     */
    public static function toJsonSchema(array $fields): array
    {
        $properties = [];
        $required = [];

        foreach ($fields as $field) {
            $properties[$field['name']] = self::property($field);

            if ($field['required'] ?? true) {
                $required[] = $field['name'];
            }
        }

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  FieldDefinition  $field
     * @return array<string, mixed>
     */
    private static function property(array $field): array
    {
        $property = match (FieldType::from($field['type'])) {
            // A non-negative decimal string; its scale is bounded by the currency of the entry (TE-FR-06).
            FieldType::Amount => ['type' => 'string', 'pattern' => self::DECIMAL, 'x-scale' => 'currency'],
            FieldType::Date => ['type' => 'string', 'format' => 'date'],
            FieldType::Text => ['type' => 'string', 'maxLength' => 500],
            FieldType::Reference => ['type' => 'string', 'maxLength' => 100],
            FieldType::Account => ['type' => 'string', 'x-reference' => 'account'] + (isset($field['account_ids']) ? ['enum' => $field['account_ids']] : []) + (isset($field['account_classes']) ? ['x-account-classes' => $field['account_classes']] : []),
            FieldType::Party => ['type' => 'string', 'x-reference' => 'party'] + (isset($field['allowed_roles']) ? ['x-allowed-roles' => $field['allowed_roles']] : []),
            FieldType::CashAccount => ['type' => 'string', 'x-reference' => 'cash_account'],
            FieldType::Dimension => ['type' => 'string', 'x-reference' => 'dimension_value', 'x-dimension-type' => $field['dimension_type'] ?? null],
            FieldType::Allocations => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'open_item_id' => ['type' => 'string'],
                        'amount' => ['type' => 'string', 'pattern' => self::DECIMAL],
                    ],
                    'required' => ['open_item_id', 'amount'],
                    'additionalProperties' => false,
                ],
            ],
        };

        return ['title' => $field['label']] + $property;
    }
}
