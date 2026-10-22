<?php

namespace App\Modules\Reporting\Application;

use App\Modules\GeneralLedger\Contracts\AccountData;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\PeriodData;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\Reporting\Contracts\ReportPackage;
use App\Modules\Reporting\Contracts\ReportPackageAccount;
use App\Modules\Reporting\Contracts\ReportPackageBalance;
use App\Modules\Reporting\Contracts\ReportPackagePeriod;
use App\Modules\Reporting\Contracts\ReportPackages;
use App\Support\Access\DataScope;
use App\Support\Company\CompanyContext;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Validation\ValidationException;

/**
 * Reads the trial balance of the company as the system, without a data scope, and adds what a consolidation
 * must know about it: the status of the periods, the coverage of the history, the mapping versions in force.
 * It runs inside the company context of the company asked for, so one call never reads another company's data.
 */
final class BuildReportPackage implements ReportPackages
{
    public function __construct(
        private readonly TrialBalance $trialBalance,
        private readonly PeriodDirectory $periods,
        private readonly AccountDirectory $accounts,
        private readonly CashEquivalents $cash,
        private readonly CompanyContext $company,
    ) {}

    public function build(string $companyId, string $from, string $to): ReportPackage
    {
        $this->assertRange($from, $to);

        return $this->company->run($companyId, function () use ($companyId, $from, $to): ReportPackage {
            $report = ($this->trialBalance)($companyId, $from, $to, scope: DataScope::unrestricted());
            $periods = $this->periods->within($companyId, $from, $to);

            return new ReportPackage(
                companyId: $companyId,
                from: $from,
                to: $to,
                cutoffDate: $to,
                functionalCurrency: $report['functional_currency'],
                bookStatus: $this->bookStatus($periods),
                periods: array_map(fn (PeriodData $period): ReportPackagePeriod => new ReportPackagePeriod($period->id, $period->name, $period->startDate, $period->endDate, $period->status), $periods),
                coverageFrom: $report['coverage_from'],
                coverageTo: $report['coverage_to'],
                coverageGranularity: $report['coverage_granularity'],
                coverageStatus: $report['coverage_status'],
                balances: array_map(fn (array $row): ReportPackageBalance => new ReportPackageBalance(
                    $row['account_id'], $row['code'], $row['name'], $row['class'],
                    $row['opening_debit'], $row['opening_credit'], $row['debit'], $row['credit'], $row['closing_debit'], $row['closing_credit'],
                ), $report['rows']),
                balanced: $report['balanced'],
                mappingVersions: [CashEquivalents::ROLE => $this->cash->current($companyId, $to)['version'] ?? null],
                sourceReferences: [
                    'report' => 'trial_balance',
                    'parameters' => $report['parameters'],
                    'conversion_batch_id' => $report['conversion_batch']['id'] ?? null,
                ],
            );
        });
    }

    public function accounts(string $companyId): array
    {
        return array_values(array_map(
            fn (AccountData $account): ReportPackageAccount => new ReportPackageAccount($account->id, $account->code, $account->name, $account->class->value, true),
            array_filter($this->accounts->all($companyId), fn (AccountData $account): bool => $account->isLeaf && $account->isActive),
        ));
    }

    /**
     * The weakest status of the periods: one open period makes the package open, whatever else is closed.
     *
     * @param  list<PeriodData>  $periods
     */
    private function bookStatus(array $periods): string
    {
        $statuses = array_unique(array_map(fn (PeriodData $period): string => $period->status, $periods));

        return match (true) {
            $periods === [] => 'no_periods',
            in_array('open', $statuses, true) => 'open',
            in_array('soft_closed', $statuses, true) => 'soft_closed',
            default => 'closed',
        };
    }

    /** @throws ValidationException */
    private function assertRange(string $from, string $to): void
    {
        foreach (['from' => $from, 'to' => $to] as $field => $value) {
            try {
                if (CarbonImmutable::createFromFormat('!Y-m-d', $value)->format('Y-m-d') !== $value) {
                    throw ValidationException::withMessages([$field => [__('validation.date_format', ['attribute' => $field, 'format' => 'Y-m-d'])]]);
                }
            } catch (InvalidFormatException) {
                throw ValidationException::withMessages([$field => [__('validation.date_format', ['attribute' => $field, 'format' => 'Y-m-d'])]]);
            }
        }

        if ($to < $from) {
            throw ValidationException::withMessages(['to' => [__('validation.after_or_equal', ['attribute' => 'to', 'date' => 'from'])]]);
        }
    }
}
