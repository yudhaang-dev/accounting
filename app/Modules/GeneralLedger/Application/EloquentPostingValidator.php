<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountData;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\AccountRole;
use App\Modules\GeneralLedger\Contracts\AccountRoles;
use App\Modules\GeneralLedger\Contracts\DimensionRequirement;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\LedgerPolicies;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\GeneralLedger\Contracts\PostingValidator;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\ReverseJournal;
use App\Modules\GeneralLedger\Domain\Journal;
use App\Modules\MasterData\Contracts\CompanyData;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\MasterData\Contracts\CurrencyRounding;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Modules\MasterData\Contracts\ExchangeRateData;
use App\Modules\MasterData\Contracts\ExchangeRates;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Company\CompanyStatusLookup;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Checks a posting command in a fixed order and stops at the first group of problems: company,
 * structure, period, accounts, account attributes (sources, currency, dimensions; GL-FR-02), balance (GL-FR-05). It changes nothing in the ledger; the only write is finding or creating the
 * dimension set of a line, which is idempotent (MD-FR-04).
 */
final class EloquentPostingValidator implements PostingValidator
{
    public function __construct(
        private readonly CompanyStatusLookup $status,
        private readonly CompanyDirectory $companies,
        private readonly PeriodDirectory $periods,
        private readonly AccountDirectory $accounts,
        private readonly DimensionSets $dimensionSets,
        private readonly Currencies $currencies,
        private readonly LedgerPolicies $policies,
        private readonly ExchangeRates $rates,
        private readonly AccountRoles $roles,
    ) {}

    public function validate(PostJournal $command): void
    {
        $this->resolve($command);
    }

    public function validateReversal(ReverseJournal $command): void
    {
        $this->company($command->companyId);

        $original = Journal::query()->find($command->journalId) ?? throw new ApiException(ErrorCode::NotFound);
        $existing = Journal::query()->where('reverses_journal_id', $original->id)->first();

        if ($existing !== null && ($command->entryId === null || $existing->entry_id !== $command->entryId)) {
            throw new ApiException(ErrorCode::InvalidStateTransition, errors: [
                'journal_id' => [__('ledger.already_reversed', ['number' => $original->number, 'reversal' => $existing->number])],
            ]);
        }

        if (! $this->isDate($command->accountingDate)) {
            throw ValidationException::withMessages([
                'accounting_date' => [__('validation.date_format', ['attribute' => 'accounting_date', 'format' => 'Y-m-d'])],
            ]);
        }

        $this->periodFor($command->companyId, $command->accountingDate, $original->transaction_type_key, $command->actorPermissions);
    }

    /**
     * @throws ApiException
     * @throws ValidationException
     */
    public function resolve(PostJournal $command): ResolvedPosting
    {
        $company = $this->company($command->companyId);
        $amounts = $this->structure($command, $company->functionalCurrency);
        $periodId = $this->periodFor($command->companyId, $command->accountingDate, $command->transactionTypeKey, $command->actorPermissions);
        $converted = $this->convert($command, $amounts, $company->functionalCurrency);
        $accounts = $this->accounts($command);
        $overridden = $command->openingBalance ? [] : $this->sources($command, $accounts);
        $this->currencies($command, $accounts, $company->functionalCurrency);
        $setIds = $this->dimensions($command);
        $this->dimensionRequirements($command, $accounts, $setIds);
        $this->counterparties($command);

        $lines = [];

        foreach ($command->lines as $index => $line) {
            $lines[] = $converted[$index] + [
                'account_id' => $line->accountId,
                'description' => $line->description,
                'dimension_set_id' => $setIds[$index],
                'counterparty_company_id' => $line->counterpartyCompanyId,
            ];
        }

        $lines = $this->balance($command, $lines, $company->functionalCurrency);

        return new ResolvedPosting($periodId, $company->functionalCurrency, $lines, $overridden === [] ? null : trim((string) $command->sourceOverrideReason), $overridden);
    }

    /**
     * The counterparty of a line must look like a company ID and not be this company (GL-FR-15). Whether it
     * is a company of the same group is not the ledger's to say: it is stored as given.
     *
     * @throws ValidationException
     */
    private function counterparties(PostJournal $command): void
    {
        $errors = [];

        foreach ($command->lines as $index => $line) {
            $id = $line->counterpartyCompanyId;

            if ($id !== null && (! Str::isUlid($id) || strcasecmp($id, $command->companyId) === 0)) {
                $errors["lines.{$index}.counterparty_company_id"] = [__('ledger.counterparty_invalid')];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return list<array{debit: BigDecimal, credit: BigDecimal}>
     *
     * @throws ValidationException
     */
    private function structure(PostJournal $command, string $functionalCurrency): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];
        $amounts = [];
        $scale = $this->currencies->functionalScale($command->companyId);

        if (count($command->lines) < 2) {
            $errors['lines'][] = __('ledger.lines_required');
        }

        if (! $this->isDate($command->accountingDate)) {
            $errors['accounting_date'][] = __('validation.date_format', ['attribute' => 'accounting_date', 'format' => 'Y-m-d']);
        }

        foreach ($command->lines as $index => $line) {
            $debit = null;
            $credit = null;
            $currency = $line->currency ?? $functionalCurrency;
            $lineScale = $scale;

            // Amounts of a foreign line are in its currency, so they follow that currency's scale.
            if ($currency !== $functionalCurrency) {
                $config = $this->currencies->find($command->companyId, $currency);

                if ($config === null || ! $config->active) {
                    $errors["lines.{$index}.currency"][] = __('ledger.currency_inactive', ['currency' => $currency]);

                    continue;
                }

                // An adjustment carries functional amounts, so it follows the scale of the ledger.
                $lineScale = $line->adjustment ? $scale : $config->scale;
            }

            if ($line->adjustment && ($currency === $functionalCurrency || ! in_array($command->source, [JournalSource::System, JournalSource::Subledger], true))) {
                $errors["lines.{$index}.adjustment"][] = __('ledger.adjustment_not_allowed');

                continue;
            }

            try {
                $debit = $this->amount($line->debit, $lineScale);
            } catch (InvalidArgumentException $e) {
                $errors["lines.{$index}.debit"][] = $e->getMessage();
            }

            try {
                $credit = $this->amount($line->credit, $lineScale);
            } catch (InvalidArgumentException $e) {
                $errors["lines.{$index}.credit"][] = $e->getMessage();
            }

            if ($debit !== null && $credit !== null) {
                $amounts[$index] = ['debit' => $debit, 'credit' => $credit];

                if ($debit->isZero() === $credit->isZero()) {
                    $errors["lines.{$index}.debit"][] = __('ledger.one_side');
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return array_values($amounts);
    }

    /**
     * @param  int<0, max>  $scale
     *
     * @throws InvalidArgumentException with the message saying why the amount is refused
     */
    private function amount(string $value, int $scale): BigDecimal
    {
        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException(__('ledger.amount_invalid'));
        }

        try {
            // Throws instead of rounding when the value has more decimals than the scale allows.
            return BigDecimal::of($value)->toScale($scale, RoundingMode::Unnecessary);
        } catch (RoundingNecessaryException) {
            throw new InvalidArgumentException(__('ledger.amount_scale', ['scale' => $scale]));
        } catch (MathException) {
            throw new InvalidArgumentException(__('ledger.amount_invalid'));
        }
    }

    private function isDate(string $value): bool
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->format('Y-m-d') === $value;
        } catch (InvalidFormatException) {
            return false;
        }
    }

    /**
     * @throws ApiException COMPANY_READ_ONLY
     */
    public function company(string $companyId): CompanyData
    {
        $company = $this->status->isWritable($companyId) ? $this->companies->find($companyId) : null;

        if ($company === null) {
            throw new ApiException(ErrorCode::CompanyReadOnly);
        }

        return $company;
    }

    /**
     * The period that accepts a posting on the date for this transaction type key and these permissions.
     *
     * @param  list<string>  $actorPermissions
     * @return string the period ID
     *
     * @throws ApiException PERIOD_NOT_OPEN
     */
    public function periodFor(string $companyId, string $accountingDate, ?string $transactionTypeKey, array $actorPermissions): string
    {
        $period = $this->periods->forDate($companyId, $accountingDate);

        if ($period === null) {
            throw new ApiException(ErrorCode::PeriodNotOpen, errors: [
                'accounting_date' => [__('ledger.period_missing', ['date' => $accountingDate])],
            ]);
        }

        // A closed year has its income and expense at zero; only reopening it lets the ledger change (GL-FR-12, GL-FR-14).
        if (DB::table('gl_closing_runs')->where('company_id', $companyId)->where('fiscal_year_id', $period->fiscalYearId)->where('status', 'closed')->exists()) {
            throw new ApiException(ErrorCode::PeriodNotOpen, errors: [
                'accounting_date' => [__('ledger.fiscal_year_closed', ['period' => $period->name])],
            ]);
        }

        if ($period->isSoftClosed()) {
            // Only what the policy names may enter; the permission was certified by the application layer (GL-AC-04).
            if (! $this->policies->current($companyId)->allowsSoftClosedPosting($transactionTypeKey, $actorPermissions)) {
                throw new ApiException(ErrorCode::PeriodNotOpen, errors: [
                    'accounting_date' => [__('ledger.period_soft_closed', ['period' => $period->name])],
                ]);
            }
        } elseif (! $period->isOpen()) {
            throw new ApiException(ErrorCode::PeriodNotOpen, errors: [
                'accounting_date' => [__('ledger.period_closed', ['period' => $period->name, 'status' => $period->status])],
            ]);
        }

        return $period->id;
    }

    /**
     * @return array<string, AccountData> the accounts of the command by ID
     *
     * @throws ApiException ACCOUNT_NOT_POSTABLE
     */
    private function accounts(PostJournal $command): array
    {
        $errors = [];
        /** @var array<string, AccountData|null> $known */
        $known = [];

        foreach ($command->lines as $index => $line) {
            $account = $known[$line->accountId] ??= $this->accounts->find($command->companyId, $line->accountId);

            $problem = match (true) {
                $account === null => 'account_unknown',
                ! $account->isActive => 'account_inactive',
                ! $account->isLeaf => 'account_parent',
                default => null,
            };

            if ($problem !== null) {
                $errors["lines.{$index}.account_id"] = [__("ledger.{$problem}")];
            }
        }

        if ($errors !== []) {
            throw new ApiException(ErrorCode::AccountNotPostable, errors: $errors);
        }

        /** @var array<string, AccountData> $known */
        return $known;
    }

    /**
     * A line to an account that does not accept the source of the posting is refused, unless the
     * policy names an override permission, the actor holds it and gives a reason (GL-FR-07).
     *
     * @param  array<string, AccountData>  $accounts
     * @return list<string> codes of the accounts posted to by override
     *
     * @throws ApiException POSTING_SOURCE_NOT_ALLOWED
     * @throws ValidationException when the override is allowed but has no reason
     */
    private function sources(PostJournal $command, array $accounts): array
    {
        $errors = [];

        foreach ($command->lines as $index => $line) {
            if (! $accounts[$line->accountId]->acceptsSource($command->source)) {
                $errors["lines.{$index}.account_id"] = [__('ledger.source_not_allowed', [
                    'account' => $accounts[$line->accountId]->code,
                    'source' => $command->source->value,
                ])];
            }
        }

        if ($errors === []) {
            return [];
        }

        if (! $this->policies->current($command->companyId)->allowsSourceOverride($command->actorPermissions)) {
            throw new ApiException(ErrorCode::PostingSourceNotAllowed, errors: $errors);
        }

        $codes = [];

        foreach (array_keys($errors) as $field) {
            $codes[] = $accounts[$command->lines[(int) explode('.', $field)[1]]->accountId]->code;
        }

        $codes = array_values(array_unique($codes));

        if (trim((string) $command->sourceOverrideReason) === '') {
            throw ValidationException::withMessages([
                'source_override_reason' => [__('ledger.override_reason_required', ['account' => implode(', ', $codes), 'source' => $command->source->value])],
            ]);
        }

        return $codes;
    }

    /**
     * @param  array<string, AccountData>  $accounts
     *
     * @throws ApiException ACCOUNT_CURRENCY_NOT_ALLOWED
     */
    private function currencies(PostJournal $command, array $accounts, string $functionalCurrency): void
    {
        $errors = [];

        foreach ($command->lines as $index => $line) {
            $currency = $line->currency ?? $functionalCurrency;

            if (! $accounts[$line->accountId]->acceptsCurrency($currency, $functionalCurrency)) {
                $errors["lines.{$index}.currency"] = [__('ledger.currency_not_allowed', [
                    'account' => $accounts[$line->accountId]->code,
                    'currency' => $currency,
                ])];
            }
        }

        if ($errors !== []) {
            throw new ApiException(ErrorCode::AccountCurrencyNotAllowed, errors: $errors);
        }
    }

    /**
     * @return list<string> dimension set ID per line: the given set, the resolved combination or the empty set
     *
     * @throws ApiException DIMENSION_INVALID
     */
    private function dimensions(PostJournal $command): array
    {
        $errors = [];
        $setIds = [];

        foreach ($command->lines as $index => $line) {
            if ($line->dimensionSetId !== null) {
                if (! $this->dimensionSets->exists($command->companyId, $line->dimensionSetId)) {
                    $errors["lines.{$index}.dimension_set_id"] = [__('ledger.dimension_set_unknown')];
                }

                $setIds[] = $line->dimensionSetId;

                continue;
            }

            try {
                $setIds[] = $this->dimensionSets->resolve($command->companyId, $line->dimensions ?? [], $command->accountingDate);
            } catch (ApiException $exception) {
                foreach ($exception->errors as $field => $messages) {
                    $errors["lines.{$index}.{$field}"] = $messages;
                }

                $setIds[] = '';
            }
        }

        if ($errors !== []) {
            throw new ApiException(ErrorCode::DimensionInvalid, errors: $errors);
        }

        return $setIds;
    }

    /**
     * Required dimension types missing on a line, or forbidden ones present, per the account (GL-FR-02).
     *
     * @param  array<string, AccountData>  $accounts
     * @param  list<string>  $setIds  dimension set per line
     *
     * @throws ApiException DIMENSION_REQUIRED, DIMENSION_INVALID
     */
    private function dimensionRequirements(PostJournal $command, array $accounts, array $setIds): void
    {
        $contents = $this->dimensionSets->describe($command->companyId, array_values(array_unique($setIds)));
        $missing = [];
        $forbidden = [];

        foreach ($command->lines as $index => $line) {
            $account = $accounts[$line->accountId];
            $present = $contents[$setIds[$index]] ?? [];

            foreach ($account->dimensionRequirements as $key => $requirement) {
                if ($requirement === DimensionRequirement::Required && ! isset($present[$key])) {
                    $missing["lines.{$index}.dimensions.{$key}"] = [__('ledger.dimension_required', ['account' => $account->code, 'type' => $key])];
                }

                if ($requirement === DimensionRequirement::Forbidden && isset($present[$key])) {
                    $forbidden["lines.{$index}.dimensions.{$key}"] = [__('ledger.dimension_forbidden', ['account' => $account->code, 'type' => $key])];
                }
            }
        }

        if ($missing !== []) {
            throw new ApiException(ErrorCode::DimensionRequired, errors: $missing + $forbidden);
        }

        if ($forbidden !== []) {
            throw new ApiException(ErrorCode::DimensionInvalid, errors: $forbidden);
        }
    }

    /**
     * Converts every line to the functional currency (GL-FR-08): a foreign amount times the rate,
     * rounded with the rounding mode and scale of the functional currency. The rate is the one given
     * on the line or the one found in its rate type on the accounting date (MD-FR-08), never a guess.
     *
     * @param  list<array{debit: BigDecimal, credit: BigDecimal}>  $amounts  in the currency of each line
     * @return list<array{debit: BigDecimal, credit: BigDecimal, currency: string, transaction_amount: BigDecimal, exchange_rate: BigDecimal, rate_type_id: string|null, rate_date: string|null}>
     *
     * @throws ValidationException
     * @throws ApiException EXCHANGE_RATE_NOT_FOUND
     */
    private function convert(PostJournal $command, array $amounts, string $functionalCurrency): array
    {
        $scale = $this->currencies->functionalScale($command->companyId);
        $mode = ($this->currencies->find($command->companyId, $functionalCurrency)->rounding ?? CurrencyRounding::HalfUp)->brick();
        $errors = [];
        $converted = [];
        /** @var array<string, ExchangeRateData> $found */
        $found = [];

        foreach ($command->lines as $index => $line) {
            $debit = $amounts[$index]['debit'];
            $credit = $amounts[$index]['credit'];
            $currency = $line->currency ?? $functionalCurrency;
            $transaction = $debit->plus($credit);

            if ($currency === $functionalCurrency) {
                $converted[] = ['debit' => $debit, 'credit' => $credit, 'currency' => $currency, 'transaction_amount' => $transaction, 'exchange_rate' => BigDecimal::one(), 'rate_type_id' => null, 'rate_date' => null];

                continue;
            }

            try {
                if ($line->exchangeRate !== null) {
                    if (preg_match('/^\d+(\.\d{1,12})?$/', $line->exchangeRate) !== 1 || BigDecimal::of($line->exchangeRate)->isZero()) {
                        throw new InvalidArgumentException(__('ledger.rate_invalid'));
                    }

                    $rate = BigDecimal::of($line->exchangeRate);
                    $typeId = $date = null;
                } elseif ($line->rateTypeKey !== null) {
                    $entry = $found["{$line->rateTypeKey}|{$currency}"] ??= $this->rates->find($command->companyId, $line->rateTypeKey, $currency, $command->accountingDate);
                    [$rate, $typeId, $date] = [BigDecimal::of($entry->rate), $entry->rateTypeId, $entry->effectiveDate];
                } else {
                    throw new InvalidArgumentException(__('ledger.rate_required', ['currency' => $currency]));
                }
            } catch (InvalidArgumentException $e) {
                $errors["lines.{$index}.exchange_rate"][] = $e->getMessage();

                continue;
            }

            if ($line->adjustment) {
                $converted[] = ['debit' => $debit, 'credit' => $credit, 'currency' => $currency, 'transaction_amount' => BigDecimal::zero()->toScale($scale), 'exchange_rate' => $rate, 'rate_type_id' => $typeId, 'rate_date' => $date];

                continue;
            }

            $functional = $transaction->multipliedBy($rate)->toScale($scale, $mode);

            if ($functional->isZero()) {
                $errors["lines.{$index}.debit"][] = __('ledger.converted_zero');

                continue;
            }

            $zero = BigDecimal::zero()->toScale($scale);
            $converted[] = [
                'debit' => $debit->isZero() ? $zero : $functional,
                'credit' => $credit->isZero() ? $zero : $functional,
                'currency' => $currency,
                'transaction_amount' => $transaction,
                'exchange_rate' => $rate,
                'rate_type_id' => $typeId,
                'rate_date' => $date,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $converted;
    }

    /**
     * The journal must balance exactly in the functional currency. A difference that comes from
     * converting foreign lines, up to the tolerance of the policy, is booked to the `fx_rounding`
     * account as one more line (GL-FR-05); anything else is refused.
     *
     * @param  list<array{debit: BigDecimal, credit: BigDecimal, currency: string, transaction_amount: BigDecimal, exchange_rate: BigDecimal, rate_type_id: string|null, rate_date: string|null, account_id: string, description: string|null, dimension_set_id: string}>  $lines
     * @return list<array{debit: BigDecimal, credit: BigDecimal, currency: string, transaction_amount: BigDecimal, exchange_rate: BigDecimal, rate_type_id: string|null, rate_date: string|null, account_id: string, description: string|null, dimension_set_id: string}>
     *
     * @throws ApiException UNBALANCED_ENTRY, MAPPING_REQUIRED
     */
    private function balance(PostJournal $command, array $lines, string $functionalCurrency): array
    {
        $debit = BigDecimal::sum(...array_column($lines, 'debit'));
        $credit = BigDecimal::sum(...array_column($lines, 'credit'));

        if ($debit->isEqualTo($credit)) {
            return $lines;
        }

        $difference = $debit->minus($credit);
        $converted = array_filter($lines, fn (array $line): bool => $line['currency'] !== $functionalCurrency) !== [];

        if (! $converted || $difference->abs()->isGreaterThan(BigDecimal::of($this->policies->current($command->companyId)->fxRoundingTolerance))) {
            throw new ApiException(ErrorCode::UnbalancedEntry, errors: [
                'lines' => [__('ledger.unbalanced', ['debit' => $debit->toString(), 'credit' => $credit->toString()])],
            ]);
        }

        $amount = $difference->abs();
        $zero = BigDecimal::zero()->toScale($amount->getScale());

        // Added by the system, so the attributes of the account (dimensions, sources) are not asked of it.
        $lines[] = [
            'account_id' => $this->roles->require($command->companyId, AccountRole::FxRounding, $command->accountingDate)->accountId,
            'debit' => $difference->isNegative() ? $amount : $zero,
            'credit' => $difference->isNegative() ? $zero : $amount,
            'currency' => $functionalCurrency,
            'transaction_amount' => $amount,
            'exchange_rate' => BigDecimal::one(),
            'rate_type_id' => null,
            'rate_date' => null,
            'description' => (string) __('ledger.rounding_line'),
            'dimension_set_id' => $this->dimensionSets->emptySet($command->companyId),
        ];

        return $lines;
    }
}
