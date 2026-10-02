<?php

namespace App\Modules\TransactionEntry\Domain;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Checks the values of an entry against the field schema of its version (TE-FR-04): the shape of each
 * value. Whether an account, party or dimension value exists is a matter of the validation on submit.
 *
 * @phpstan-import-type FieldDefinition from FieldSchema
 */
final class FieldValues
{
    /**
     * Problems per field, keyed `values.<name>`; empty when the values fit the schema.
     *
     * @param  list<FieldDefinition>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, list<string>>
     */
    public static function errors(array $fields, array $values): array
    {
        $errors = [];
        $known = [];

        foreach ($fields as $field) {
            $known[$field['name']] = true;
            $value = $values[$field['name']] ?? null;

            if ($value === null || $value === '') {
                if ($field['required'] ?? true) {
                    $errors["values.{$field['name']}"] = [__('entries.value_required', ['field' => $field['label']])];
                }

                continue;
            }

            if (($problem = self::problem($field, $value)) !== null) {
                $errors["values.{$field['name']}"] = [$problem];
            }
        }

        foreach (array_keys($values) as $name) {
            if (! isset($known[$name])) {
                $errors["values.{$name}"] = [__('entries.value_unknown', ['field' => $name])];
            }
        }

        return $errors;
    }

    /**
     * The values without the empty ones; what is stored and read back.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function present(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Dimension type key => value ID of the dimension fields that carry a value.
     *
     * @param  list<FieldDefinition>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function dimensions(array $fields, array $values): array
    {
        $dimensions = [];

        foreach ($fields as $field) {
            $value = $values[$field['name']] ?? null;

            if ($field['type'] === FieldType::Dimension->value && is_string($value) && $value !== '') {
                $dimensions[$field['dimension_type'] ?? ''] = $value;
            }
        }

        return $dimensions;
    }

    /**
     * @param  FieldDefinition  $field
     */
    private static function problem(array $field, mixed $value): ?string
    {
        $invalid = (string) __('entries.value_invalid', ['field' => $field['label']]);

        return match (FieldType::from($field['type'])) {
            FieldType::Amount => is_string($value) && preg_match('/'.FieldSchema::DECIMAL.'/', $value) === 1 ? null : $invalid,
            FieldType::Date => is_string($value) && self::isDate($value) ? null : $invalid,
            FieldType::Text => is_string($value) && mb_strlen($value) <= 500 ? null : $invalid,
            FieldType::Reference => is_string($value) && mb_strlen($value) <= 100 ? null : $invalid,
            FieldType::Account => self::accountProblem($field, $value),
            FieldType::Party, FieldType::CashAccount, FieldType::Dimension => is_string($value) && strlen($value) === 26 ? null : $invalid,
            FieldType::Allocations => self::allocationsAreValid($value) ? null : $invalid,
        };
    }

    /**
     * @param  FieldDefinition  $field
     */
    private static function accountProblem(array $field, mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) !== 26) {
            return (string) __('entries.value_invalid', ['field' => $field['label']]);
        }

        return isset($field['account_ids']) && ! in_array($value, $field['account_ids'], true)
            ? (string) __('entries.value_account_not_allowed', ['field' => $field['label']])
            : null;
    }

    private static function isDate(string $value): bool
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->toDateString() === $value;
        } catch (Throwable) {
            return false;
        }
    }

    private static function allocationsAreValid(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 200) {
            return false;
        }

        foreach ($value as $allocation) {
            if (! is_array($allocation) || ! is_string($allocation['open_item_id'] ?? null) || ! is_string($allocation['amount'] ?? null)
                || preg_match('/'.FieldSchema::DECIMAL.'/', $allocation['amount']) !== 1) {
                return false;
            }
        }

        return true;
    }
}
