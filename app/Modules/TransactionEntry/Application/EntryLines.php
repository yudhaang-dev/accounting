<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\GeneralLedger\Contracts\AccountRole;
use App\Modules\GeneralLedger\Contracts\AccountRoles;
use App\Modules\TransactionEntry\Domain\Entry;
use App\Modules\TransactionEntry\Domain\FieldValues;
use App\Modules\TransactionEntry\Domain\PostingRules;
use App\Modules\TransactionEntry\Domain\TransactionTypeVersion;
use App\Platform\Api\ApiException;
use App\Support\Company\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * Turns the field values of a `structured` entry into journal lines by the posting rules of its version
 * (TE-FR-05). Values are taken as given: nothing is computed from them (P-02) except the one balancing
 * line of an `fx_difference` rule. A rule whose amount is zero or empty produces no line.
 *
 * @phpstan-import-type RuleLine from PostingRules
 *
 * @phpstan-type Line array{account_id: string, debit: string, credit: string, description: string|null, dimensions: array<string, string>}
 */
final class EntryLines
{
    public function __construct(
        private readonly AccountRoles $roles,
        private readonly CompanyContext $company,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @return list<Line>
     *
     * @throws ValidationException when an account field a rule posts to has no value
     * @throws ApiException MAPPING_REQUIRED when an exchange difference arises and its role is not mapped
     */
    public function generate(TransactionTypeVersion $version, array $values, string $accountingDate): array
    {
        $fields = $version->fields ?? [];
        $dimensions = FieldValues::dimensions($fields, $values);
        $lines = [];
        $fxRules = [];
        $balance = BigDecimal::zero();

        foreach ($version->posting_rules ?? [] as $index => $rule) {
            $dimensionsOf = $rule['apply_dimensions'] ? $dimensions : [];

            if ($rule['type'] === PostingRules::FX_DIFFERENCE) {
                $fxRules[$index] = $dimensionsOf;

                continue;
            }

            $amount = BigDecimal::of($values[$rule['amount_field'] ?? ''] ?? '0');

            if ($amount->isZero()) {
                continue;
            }

            $debit = ($rule['side'] ?? null) === 'debit';
            $balance = $debit ? $balance->plus($amount) : $balance->minus($amount);

            $lines[$index] = $this->line($this->accountOf($rule, $values), $amount, $debit, $dimensionsOf);
        }

        if (! $balance->isZero()) {
            foreach ($fxRules as $index => $dimensionsOf) {
                // More debit than credit is made up with a credit, a gain, and the other way round.
                $gain = $balance->isPositive();
                $role = $this->roles->require($this->company->require(), $gain ? AccountRole::FxRealizedGain : AccountRole::FxRealizedLoss, $accountingDate);
                $lines[$index] = $this->line($role->accountId, $balance->abs(), ! $gain, $dimensionsOf);

                break;
            }
        }

        ksort($lines);

        return array_values($lines);
    }

    /**
     * Rebuilds the lines of a structured entry from its values, so that a posting uses the rules and role
     * mappings as they stand now; a `free_lines` entry keeps its own lines.
     *
     * @return list<Line>
     */
    public function of(Entry $entry): array
    {
        if ($entry->field_values === null) {
            return array_map(fn (array $line): array => $line + ['dimensions' => []], $entry->lines);
        }

        $version = TransactionTypeVersion::query()
            ->where('transaction_type_id', $entry->transaction_type_id)
            ->where('version', $entry->transaction_type_version)
            ->firstOrFail();

        return $this->generate($version, $entry->field_values, $entry->accounting_date->toDateString());
    }

    /**
     * @param  RuleLine  $rule
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    private function accountOf(array $rule, array $values): string
    {
        $source = $rule['account'] ?? [];

        if (($source['source'] ?? null) === PostingRules::FIXED_ACCOUNT) {
            return (string) ($source['account_id'] ?? '');
        }

        $field = $source['field'] ?? '';
        $accountId = $values[$field] ?? null;

        if (! is_string($accountId) || $accountId === '') {
            throw ValidationException::withMessages(["values.{$field}" => [__('entries.value_required', ['field' => $field])]]);
        }

        return $accountId;
    }

    /**
     * @param  array<string, string>  $dimensions
     * @return Line
     */
    private function line(string $accountId, BigDecimal $amount, bool $debit, array $dimensions): array
    {
        return [
            'account_id' => $accountId,
            'debit' => $debit ? (string) $amount : '0',
            'credit' => $debit ? '0' : (string) $amount,
            'description' => null,
            'dimensions' => $dimensions,
        ];
    }
}
