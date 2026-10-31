<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountData;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\PeriodData;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\GeneralLedger\Contracts\SystemTransactionKeys;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Modules\Planning\Contracts\PlanReader;
use App\Modules\Planning\Contracts\PlanVersionData;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Support\Access\DataScope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Measures an activity plan against the ledger (RP-FR-11, RP-FR-12, ADR-0017): for each period of the
 * plan and each income or expense account, what was posted in the scope of the plan beside what the
 * initial estimate (the first version published) and a revision (the last, or one the reader names)
 * planned. Everything is in the direction of the normal balance of the account, so a cost is positive.
 *
 * Besides the differences per account, each period has the difference of the result (income minus
 * expense), where positive is always favorable, and its running total: a total that keeps the same
 * sign shows estimates leaning one way.
 */
final class PlanEvaluation
{
    private const VERSIONS = ['initial', 'revision'];

    public function __construct(
        private readonly PlanReader $plans,
        private readonly PeriodDirectory $periods,
        private readonly AccountDirectory $accounts,
        private readonly DimensionSets $dimensions,
        private readonly ReportLedger $ledger,
        private readonly CompanyDirectory $companies,
        private readonly Currencies $currencies,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException NOT_FOUND for an unknown plan
     * @throws ValidationException when the revision is not a version of the plan that has been published
     */
    public function __invoke(string $companyId, string $planId, ?string $revisionVersionId, DataScope $scope): array
    {
        $plan = $this->plans->plan($companyId, $planId) ?? throw new ApiException(ErrorCode::NotFound);
        $company = $this->companies->find($companyId) ?? throw new ApiException(ErrorCode::NotFound);
        $published = $this->plans->publishedVersions($companyId, $planId);

        $versions = [
            'initial' => $published[0] ?? null,
            'revision' => $this->revision($published, $revisionVersionId),
        ];

        $slice = $this->ledger->slice($companyId, $this->filter($companyId, $plan->scopeDimensionValueId), $scope);
        $scale = $this->currencies->functionalScale($companyId);

        $accounts = [];

        foreach ($this->accounts->all($companyId) as $account) {
            $accounts[$account->id] = $account;
        }

        $periods = $this->periods->overlapping($companyId, $plan->startsOn, $plan->endsOn);
        $cells = [];   // account ID => period ID => actual|initial|revision => amount

        foreach ($periods as $period) {
            foreach ($slice->trialBalance($period->startDate, $period->endDate, [SystemTransactionKeys::CLOSING, SystemTransactionKeys::OPENING_BALANCE]) as $row) {
                $account = $accounts[$row->accountId] ?? null;

                if ($account !== null && $this->isResult($account)) {
                    $cells[$account->id][$period->id]['actual'] = $this->inDirection($account, BigDecimal::of($row->debit)->minus($row->credit));
                }
            }

            foreach (self::VERSIONS as $kind) {
                if ($versions[$kind] === null) {
                    continue;
                }

                foreach ($this->plans->amounts($companyId, $versions[$kind]->id, [$period->id]) as $amount) {
                    if (isset($accounts[$amount->accountId]) && $this->isResult($accounts[$amount->accountId])) {
                        $cells[$amount->accountId][$period->id][$kind] = BigDecimal::of($amount->amount);
                    }
                }
            }
        }

        uksort($cells, fn (string $a, string $b): int => strcmp($accounts[$a]->code, $accounts[$b]->code));

        $rows = [];

        foreach ($cells as $accountId => $byPeriod) {
            $account = $accounts[$accountId];
            $total = [];
            $rowCells = [];

            foreach ($periods as $period) {
                $values = $byPeriod[$period->id] ?? [];

                foreach (['actual', ...self::VERSIONS] as $kind) {
                    $total[$kind] = ($total[$kind] ?? BigDecimal::zero())->plus($values[$kind] ?? BigDecimal::zero());
                }

                $rowCells[] = ['period_id' => $period->id, ...$this->cell($values, $versions, $account, $scale)];
            }

            $rows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'class' => $account->class->value,
                'normal_balance' => $account->normalBalance,
                'cells' => $rowCells,
                'total' => $this->cell($total, $versions, $account, $scale),
            ];
        }

        return [
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'objective' => $plan->objective,
                'starts_on' => $plan->startsOn,
                'ends_on' => $plan->endsOn,
                'scope_dimension_value_id' => $plan->scopeDimensionValueId,
            ],
            'functional_currency' => $company->functionalCurrency,
            'restricted' => $scope->isRestricted(),
            'versions' => array_map(fn (?PlanVersionData $version): ?array => $version === null ? null : ['id' => $version->id, 'name' => $version->name], $versions),
            'available_versions' => array_map(fn (PlanVersionData $version): array => ['id' => $version->id, 'name' => $version->name], $published),
            'periods' => $this->periodSummaries($periods, $cells, $accounts, $versions, $scale),
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<PlanVersionData>  $published
     *
     * @throws ValidationException
     */
    private function revision(array $published, ?string $revisionVersionId): ?PlanVersionData
    {
        if ($revisionVersionId === null) {
            return $published === [] ? null : $published[array_key_last($published)];
        }

        foreach ($published as $version) {
            if ($version->id === $revisionVersionId) {
                return $version;
            }
        }

        throw ValidationException::withMessages(['revision_version_id' => [__('plans.revision_unknown')]]);
    }

    private function filter(string $companyId, ?string $scopeValueId): ReportFilter
    {
        if ($scopeValueId === null) {
            return new ReportFilter;
        }

        // The type key of the value, found through the values it expands to (itself and its descendants).
        $typeKey = array_key_first($this->dimensions->expandValues($companyId, [$scopeValueId]));

        return new ReportFilter(dimensions: [(string) $typeKey => $scopeValueId]);
    }

    private function isResult(AccountData $account): bool
    {
        return in_array($account->class, [AccountClass::Income, AccountClass::Expense], true);
    }

    /** The movement as a positive amount in the direction of the normal balance of the account. */
    private function inDirection(AccountData $account, BigDecimal $debitMinusCredit): BigDecimal
    {
        return $account->normalBalance === 'credit' ? $debitMinusCredit->negated() : $debitMinusCredit;
    }

    /**
     * @param  array<string, BigDecimal>  $values  actual, initial and revision, each when there is one
     * @param  array<string, PlanVersionData|null>  $versions
     * @param  int<0, max>  $scale
     * @return array<string, mixed>
     */
    private function cell(array $values, array $versions, AccountData $account, int $scale): array
    {
        $actual = $values['actual'] ?? BigDecimal::zero();
        $cell = ['actual' => $this->format($actual, $scale)];
        $variances = [];

        foreach (self::VERSIONS as $kind) {
            $planned = $versions[$kind] === null ? null : ($values[$kind] ?? BigDecimal::zero());
            $cell[$kind] = $planned === null ? null : $this->format($planned, $scale);
            $variances[$kind] = $planned === null ? null : $this->variance($actual, $planned, $account->normalBalance === 'credit', $scale);
        }

        return [...$cell, 'variances' => $variances];
    }

    /**
     * Realised minus planned, as a percentage of the absolute plan (`null` when the plan is zero); an
     * income is favorable above the plan and an expense below it (RP-FR-12). Zero is neither.
     *
     * @param  int<0, max>  $scale
     * @return array{amount: string, percentage: string|null, favorable: bool|null}
     */
    private function variance(BigDecimal $actual, BigDecimal $planned, bool $higherIsFavorable, int $scale): array
    {
        $difference = $actual->minus($planned);

        return [
            'amount' => $this->format($difference, $scale),
            'percentage' => $planned->isZero() ? null : (string) $difference->multipliedBy(100)->dividedBy($planned->abs(), 2, RoundingMode::HalfUp),
            'favorable' => $difference->isZero() ? null : $difference->isPositive() === $higherIsFavorable,
        ];
    }

    /**
     * @param  list<PeriodData>  $periods
     * @param  array<string, array<string, array<string, BigDecimal>>>  $cells
     * @param  array<string, AccountData>  $accounts
     * @param  array<string, PlanVersionData|null>  $versions
     * @param  int<0, max>  $scale
     * @return list<array<string, mixed>>
     */
    private function periodSummaries(array $periods, array $cells, array $accounts, array $versions, int $scale): array
    {
        $running = array_fill_keys(self::VERSIONS, BigDecimal::zero());
        $summaries = [];

        foreach ($periods as $period) {
            $result = ['actual' => BigDecimal::zero(), 'initial' => BigDecimal::zero(), 'revision' => BigDecimal::zero()];

            foreach ($cells as $accountId => $byPeriod) {
                $sign = $accounts[$accountId]->class === AccountClass::Income ? 1 : -1;

                foreach ($result as $kind => $sum) {
                    $result[$kind] = $sum->plus(($byPeriod[$period->id][$kind] ?? BigDecimal::zero())->multipliedBy($sign));
                }
            }

            $planned = [];
            $variance = [];
            $cumulative = [];

            foreach (self::VERSIONS as $kind) {
                if ($versions[$kind] === null) {
                    $planned[$kind] = $variance[$kind] = $cumulative[$kind] = null;

                    continue;
                }

                $difference = $result['actual']->minus($result[$kind]);
                $running[$kind] = $running[$kind]->plus($difference);
                $planned[$kind] = $this->format($result[$kind], $scale);
                $variance[$kind] = $this->format($difference, $scale);
                $cumulative[$kind] = $this->format($running[$kind], $scale);
            }

            $summaries[] = [
                'id' => $period->id,
                'name' => $period->name,
                'start_date' => $period->startDate,
                'end_date' => $period->endDate,
                'result' => ['actual' => $this->format($result['actual'], $scale), ...$planned],
                'variance' => $variance,
                'cumulative_variance' => $cumulative,
            ];
        }

        return $summaries;
    }

    /** @param  int<0, max>  $scale */
    private function format(BigDecimal $value, int $scale): string
    {
        return (string) $value->toScale($scale, RoundingMode::HalfUp);
    }
}
