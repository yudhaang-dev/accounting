<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountData;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\GeneralLedger\Contracts\PostingValidator;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Company\CompanyStatusLookup;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Checks a posting command in a fixed order and stops at the first group of problems: company,
 * structure, period, accounts, dimensions, balance (GL-FR-05). It has no side effects.
 */
final class EloquentPostingValidator implements PostingValidator
{
    public function __construct(
        private readonly CompanyStatusLookup $status,
        private readonly CompanyDirectory $companies,
        private readonly PeriodDirectory $periods,
        private readonly AccountDirectory $accounts,
        private readonly DimensionSets $dimensionSets,
    ) {}

    public function validate(PostJournal $command): void
    {
        $this->resolve($command);
    }

    /**
     * @throws ApiException
     * @throws ValidationException
     */
    public function resolve(PostJournal $command): ResolvedPosting
    {
        $company = $this->status->isWritable($command->companyId) ? $this->companies->find($command->companyId) : null;

        if ($company === null) {
            throw new ApiException(ErrorCode::CompanyReadOnly);
        }

        $amounts = $this->structure($command, $company->functionalCurrency);
        $periodId = $this->period($command);
        $this->accounts($command);
        $emptySet = $this->dimensions($command);
        $this->balance($amounts);

        $lines = [];

        foreach ($command->lines as $index => $line) {
            $lines[] = [
                'account_id' => $line->accountId,
                'debit' => $amounts[$index]['debit'],
                'credit' => $amounts[$index]['credit'],
                'description' => $line->description,
                'dimension_set_id' => $line->dimensionSetId ?? $emptySet,
            ];
        }

        return new ResolvedPosting($periodId, $company->functionalCurrency, $lines);
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
        $scale = max(0, (int) config('ledger.default_scale'));

        if (count($command->lines) < 2) {
            $errors['lines'][] = __('ledger.lines_required');
        }

        if (! $this->isDate($command->accountingDate)) {
            $errors['accounting_date'][] = __('validation.date_format', ['attribute' => 'accounting_date', 'format' => 'Y-m-d']);
        }

        foreach ($command->lines as $index => $line) {
            $debit = null;
            $credit = null;

            try {
                $debit = $this->amount($line->debit, $scale);
            } catch (InvalidArgumentException $e) {
                $errors["lines.{$index}.debit"][] = $e->getMessage();
            }

            try {
                $credit = $this->amount($line->credit, $scale);
            } catch (InvalidArgumentException $e) {
                $errors["lines.{$index}.credit"][] = $e->getMessage();
            }

            if ($debit !== null && $credit !== null) {
                $amounts[$index] = ['debit' => $debit, 'credit' => $credit];

                if ($debit->isZero() === $credit->isZero()) {
                    $errors["lines.{$index}.debit"][] = __('ledger.one_side');
                }
            }

            if ($line->currency !== null && $line->currency !== $functionalCurrency) {
                $errors["lines.{$index}.currency"][] = __('ledger.currency_unsupported', ['currency' => $functionalCurrency]);
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
     * @throws ApiException PERIOD_NOT_OPEN
     */
    private function period(PostJournal $command): string
    {
        $period = $this->periods->forDate($command->companyId, $command->accountingDate);

        if ($period === null) {
            throw new ApiException(ErrorCode::PeriodNotOpen, errors: [
                'accounting_date' => [__('ledger.period_missing', ['date' => $command->accountingDate])],
            ]);
        }

        if (! $period->isOpen()) {
            throw new ApiException(ErrorCode::PeriodNotOpen, errors: [
                'accounting_date' => [__('ledger.period_closed', ['period' => $period->name, 'status' => $period->status])],
            ]);
        }

        return $period->id;
    }

    /**
     * @throws ApiException ACCOUNT_NOT_POSTABLE
     */
    private function accounts(PostJournal $command): void
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
    }

    /**
     * @return string ID of the empty dimension set of the company
     *
     * @throws ApiException DIMENSION_INVALID
     */
    private function dimensions(PostJournal $command): string
    {
        $errors = [];

        foreach ($command->lines as $index => $line) {
            if ($line->dimensionSetId !== null && ! $this->dimensionSets->exists($command->companyId, $line->dimensionSetId)) {
                $errors["lines.{$index}.dimension_set_id"] = [__('ledger.dimension_set_unknown')];
            }
        }

        if ($errors !== []) {
            throw new ApiException(ErrorCode::DimensionInvalid, errors: $errors);
        }

        return $this->dimensionSets->emptySet($command->companyId);
    }

    /**
     * @param  list<array{debit: BigDecimal, credit: BigDecimal}>  $amounts
     *
     * @throws ApiException UNBALANCED_ENTRY
     */
    private function balance(array $amounts): void
    {
        $debit = BigDecimal::sum(...array_column($amounts, 'debit'));
        $credit = BigDecimal::sum(...array_column($amounts, 'credit'));

        if (! $debit->isEqualTo($credit)) {
            throw new ApiException(ErrorCode::UnbalancedEntry, errors: [
                'lines' => [__('ledger.unbalanced', ['debit' => $debit->toString(), 'credit' => $credit->toString()])],
            ]);
        }
    }
}
