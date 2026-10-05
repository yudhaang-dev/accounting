<?php

namespace App\Modules\TransactionEntry\Domain;

/**
 * The posting rules of a `structured` version: one rule per journal line, in order (TE-FR-05). An
 * `amount` rule posts the value of an amount field to one side of an account; the `fx_difference` rule
 * carries what is left to balance the journal in the functional currency to the exchange difference accounts.
 *
 * The account of an `amount` rule comes from a fixed account, from an `account` field, from the control
 * account of the party in a `party` field (which needs the subledger route of the version), or from the
 * ledger account of the cash account in a `cash_account` field (TE-FR-05).
 *
 * @phpstan-type AccountSource array{source: string, account_id?: string, field?: string, party_field?: string}
 * @phpstan-type RuleLine array{type: string, side?: string, amount_field?: string, account?: AccountSource, apply_dimensions: bool, reuses_field: bool}
 */
final class PostingRules
{
    public const AMOUNT = 'amount';

    public const FX_DIFFERENCE = 'fx_difference';

    public const FIXED_ACCOUNT = 'fixed';

    public const ACCOUNT_FIELD = 'field';

    public const CONTROL_ACCOUNT = 'control_account';

    public const CASH_ACCOUNT = 'cash_account';

    /**
     * Indexes of the rules that use a field another rule also uses without saying so. A field shared by
     * several rules is allowed only when each of them has `reuses_field` (TE-FR-05).
     *
     * @param  list<RuleLine>  $rules
     * @return list<int>
     */
    public static function undeclaredReuse(array $rules): array
    {
        $users = [];

        foreach ($rules as $index => $rule) {
            foreach (self::fieldsOf($rule) as $name) {
                $users[$name][] = $index;
            }
        }

        $undeclared = [];

        foreach ($users as $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $index) {
                    if (! $rules[$index]['reuses_field']) {
                        $undeclared[$index] = $index;
                    }
                }
            }
        }

        ksort($undeclared);

        return array_values($undeclared);
    }

    /**
     * @param  RuleLine  $rule
     * @return list<string>
     */
    private static function fieldsOf(array $rule): array
    {
        if ($rule['type'] !== self::AMOUNT) {
            return [];
        }

        $fields = [$rule['amount_field'] ?? ''];

        $account = $rule['account'] ?? [];

        $fields[] = match ($account['source'] ?? null) {
            self::ACCOUNT_FIELD, self::CASH_ACCOUNT => $account['field'] ?? '',
            self::CONTROL_ACCOUNT => $account['party_field'] ?? '',
            default => '',
        };

        return array_values(array_unique(array_filter($fields, fn (string $name): bool => $name !== '')));
    }
}
