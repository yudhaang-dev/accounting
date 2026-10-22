<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\GeneralLedger\Contracts\AccountRole;
use App\Modules\GeneralLedger\Contracts\AccountRoles;
use App\Modules\Subledger\Contracts\CashAccountData;
use App\Modules\Subledger\Contracts\CashAccountDirectory;
use App\Modules\Subledger\Contracts\ControlAccounts;
use App\Modules\Subledger\Contracts\PartyDirectory;
use App\Modules\TransactionEntry\Domain\Entry;
use App\Modules\TransactionEntry\Domain\FieldValues;
use App\Modules\TransactionEntry\Domain\PostingRules;
use App\Modules\TransactionEntry\Domain\SubledgerRoute;
use App\Modules\TransactionEntry\Domain\TransactionTypeVersion;
use App\Platform\Api\ApiException;
use App\Support\Company\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;
use LogicException;

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
 * @phpstan-type Line array{account_id: string, debit: string, credit: string, description: string|null, dimensions: array<string, string>, currency?: string|null, exchange_rate?: string|null, applied_rate?: string|null, rate_type_key?: string|null, counterparty_company_id?: string|null}
 */
final class EntryLines
{
    public function __construct(
        private readonly AccountRoles $roles,
        private readonly CompanyContext $company,
        private readonly EntryRates $rates,
        private readonly ControlAccounts $controlAccounts,
        private readonly PartyDirectory $parties,
        private readonly CashAccountDirectory $cashAccounts,
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
        $pending = $foreign !== null && $applied === null;
        $cashInAccountCurrency = PostingRules::cashInAccountCurrency($version->posting_rules ?? [], SubledgerRoute::isTransfer($version->subledger_route));

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
            $accountId = $this->accountOf($rule, $values, $version, $accountingDate);
            [$lineCurrency, $lineApplied, $lineInput] = [$foreign, $applied, $inputRate];

            // The cash of a transfer, or of a payment with an amount of its own, is in the currency of its account, at the rate in the table (TE-FR-06).
            if (in_array($index, $cashInAccountCurrency, true)) {
                $lineCurrency = $this->rates->foreign($this->cashAccountData($rule['account']['field'] ?? '', $values)->currency);
                $lineInput = null;
                $lineApplied = $lineCurrency === null ? null : $this->rates->resolve($version, $lineCurrency, null, $accountingDate, $strict);
                $pending = $pending || ($lineCurrency !== null && $lineApplied === null);
            }

            $functional = $lineApplied === null ? $amount : $this->rates->toFunctional($amount, $lineApplied['rate']);
            $balance = $debit ? $balance->plus($functional) : $balance->minus($functional);

            $lines[$index] = $this->line($accountId, $amount, $debit, $dimensionsOf, $this->counterpartyOf($rule, $values))
                + ($lineCurrency === null ? [] : ['currency' => $lineCurrency, 'exchange_rate' => $lineInput, 'applied_rate' => $lineApplied['rate'] ?? null, 'rate_type_key' => $lineApplied['rate_type_key'] ?? null]);
        }

        // Without a rate there is nothing to say yet about what is left over in the functional currency.
        if (! $balance->isZero() && ! $pending) {
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
        $version = $entry->typeVersion();
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
     * @throws ApiException MAPPING_REQUIRED when the control account of the party is not mapped
     */
    private function accountOf(array $rule, array $values, TransactionTypeVersion $version, string $accountingDate): string
    {
        $source = $rule['account'] ?? [];

        if (($source['source'] ?? null) === PostingRules::FIXED_ACCOUNT) {
            return (string) ($source['account_id'] ?? '');
        }

        if (($source['source'] ?? null) === PostingRules::CONTROL_ACCOUNT) {
            return $this->controlAccountOf($source['party_field'] ?? '', $values, $version, $accountingDate);
        }

        if (($source['source'] ?? null) === PostingRules::CASH_ACCOUNT) {
            return $this->cashAccountOf($source['field'] ?? '', $values);
        }

        $field = $source['field'] ?? '';
        $accountId = $values[$field] ?? null;

        if (! is_string($accountId) || $accountId === '') {
            throw ValidationException::withMessages(["values.{$field}" => [__('entries.value_required', ['field' => $field])]]);
        }

        return $accountId;
    }

    /**
     * The control account of the party in the field, by the posting profile of the party as it stands on
     * the accounting date (SL-FR-02). The role is the one of the subledger route of the version.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    private function controlAccountOf(string $field, array $values, TransactionTypeVersion $version, string $accountingDate): string
    {
        $partyId = $values[$field] ?? null;
        $companyId = $this->company->require();

        if (! is_string($partyId) || $partyId === '') {
            throw ValidationException::withMessages(["values.{$field}" => [__('entries.value_required', ['field' => $field])]]);
        }

        if ($this->parties->find($companyId, $partyId) === null) {
            throw ValidationException::withMessages(["values.{$field}" => [__('entries.field_party_unknown')]]);
        }

        return $this->controlAccounts->require($companyId, SubledgerRoute::role($version->subledger_route ?? throw new LogicException('A control account rule needs the subledger route.')), $partyId, $accountingDate)->accountId;
    }

    /**
     * The ledger account of the cash account in the field.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    private function cashAccountOf(string $field, array $values): string
    {
        return $this->cashAccountData($field, $values)->accountId;
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    private function cashAccountData(string $field, array $values): CashAccountData
    {
        $cashAccountId = $values[$field] ?? null;

        if (! is_string($cashAccountId) || $cashAccountId === '') {
            throw ValidationException::withMessages(["values.{$field}" => [__('entries.value_required', ['field' => $field])]]);
        }

        $cash = $this->cashAccounts->find($this->company->require(), $cashAccountId);

        if ($cash === null) {
            throw ValidationException::withMessages(["values.{$field}" => [__('entries.field_cash_account_unknown')]]);
        }

        return $cash;
    }

    /**
     * The group company of the party the line is about (GL-FR-15): the party of the control account of the rule,
     * or the one its `counterparty_party_field` names. Null when there is no such party or it has no group company.
     *
     * @param  RuleLine  $rule
     * @param  array<string, mixed>  $values
     */
    private function counterpartyOf(array $rule, array $values): ?string
    {
        $source = $rule['account'] ?? [];
        $field = ($source['source'] ?? null) === PostingRules::CONTROL_ACCOUNT ? ($source['party_field'] ?? null) : ($rule['counterparty_party_field'] ?? null);
        $partyId = $field === null ? null : ($values[$field] ?? null);

        if (! is_string($partyId) || $partyId === '') {
            return null;
        }

        return $this->parties->find($this->company->require(), $partyId)?->counterpartyCompanyId;
    }

    /**
     * @param  array<string, string>  $dimensions
     * @return Line
     */
    private function line(string $accountId, BigDecimal $amount, bool $debit, array $dimensions, ?string $counterparty = null): array
    {
        return [
            'account_id' => $accountId,
            'debit' => $debit ? (string) $amount : '0',
            'credit' => $debit ? '0' : (string) $amount,
            'description' => null,
            'dimensions' => $dimensions,
        ] + ($counterparty === null ? [] : ['counterparty_company_id' => $counterparty]);
    }
}
