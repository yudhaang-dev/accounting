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
 * A line in a foreign currency carries `currency`, the `exchange_rate` the entry brought (null when it
 * brought none) and, once a rate is known, the `applied_rate` that will be used with the `rate_type_key`
 * it was found under (null for a rate the entry brought) (TE-FR-06).
 *
 * @phpstan-import-type RuleLine from PostingRules
 *
 * @phpstan-type Line array{account_id: string, debit: string, credit: string, description: string|null, dimensions: array<string, string>, currency?: string|null, exchange_rate?: string|null, applied_rate?: string|null, rate_type_key?: string|null}
 */
final class EntryLines
{
    public function __construct(
        private readonly AccountRoles $roles,
        private readonly CompanyContext $company,
        private readonly EntryRates $rates,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @param  bool  $strict  false for a draft: a rate that is not there yet leaves the exchange difference out instead of failing
     * @return list<Line>
     *
     * @throws ValidationException when an account field a rule posts to has no value
     * @throws ApiException MAPPING_REQUIRED when an exchange difference arises and its role is not mapped, EXCHANGE_RATE_NOT_FOUND
     */
    public function generate(TransactionTypeVersion $version, array $values, string $accountingDate, ?string $currency = null, ?string $inputRate = null, bool $strict = true): array
    {
        $fields = $version->fields ?? [];
        $dimensions = FieldValues::dimensions($fields, $values);
        $foreign = $this->rates->foreign($currency);
        $applied = $foreign === null ? null : $this->rates->resolve($version, $foreign, $inputRate, $accountingDate, $strict);
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
            $functional = $applied === null ? $amount : $this->rates->toFunctional($amount, $applied['rate']);
            $balance = $debit ? $balance->plus($functional) : $balance->minus($functional);

            $lines[$index] = $this->line($this->accountOf($rule, $values), $amount, $debit, $dimensionsOf)
                + ($foreign === null ? [] : ['currency' => $foreign, 'exchange_rate' => $inputRate, 'applied_rate' => $applied['rate'] ?? null, 'rate_type_key' => $applied['rate_type_key'] ?? null]);
        }

        // Without a rate there is nothing to say yet about what is left over in the functional currency.
        if (! $balance->isZero() && ($foreign === null || $applied !== null)) {
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
     * The lines of an entry as they will be posted: a structured entry is rebuilt from its values and a
     * free-lines entry gets its rates, so that a posting uses the rules, role mappings and rates as they
     * stand now (TE-FR-08).
     *
     * @return list<Line>
     *
     * @throws ApiException EXCHANGE_RATE_NOT_FOUND
     */
    public function of(Entry $entry): array
    {
        $version = TransactionTypeVersion::query()
            ->where('transaction_type_id', $entry->transaction_type_id)
            ->where('version', $entry->transaction_type_version)
            ->firstOrFail();
        $date = $entry->accounting_date->toDateString();

        if ($entry->field_values !== null) {
            return $this->generate($version, $entry->field_values, $date, $entry->currency, $entry->exchange_rate);
        }

        return array_map(function (array $line) use ($version, $date): array {
            $line += ['dimensions' => []];
            $foreign = $this->rates->foreign($line['currency'] ?? null);

            if ($foreign === null) {
                return $line;
            }

            $applied = $this->rates->resolve($version, $foreign, $line['exchange_rate'] ?? null, $date, true);

            return ['applied_rate' => $applied['rate'] ?? null, 'rate_type_key' => $applied['rate_type_key'] ?? null] + $line;
        }, $entry->lines);
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
