<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\AccountRole;
use App\Modules\GeneralLedger\Contracts\AccountRoles;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\LedgerPolicies;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Contracts\ReverseJournal;
use App\Modules\GeneralLedger\Domain\Period;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\MasterData\Contracts\CurrencyRounding;
use App\Modules\MasterData\Contracts\ExchangeRates;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyScope;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Revalues the foreign currency balances of non-control accounts at the end of a period (GL-FR-09).
 *
 * For each account, currency and dimension set the balance in the transaction currency is valued at
 * the closing rate of the policy and compared with what the ledger carries in the functional
 * currency. The difference is posted as a `system.fx_revaluation` journal against the unrealized
 * gain or loss accounts. The carried value includes earlier adjustments, so running again only
 * posts what changed since and a run that finds nothing posts nothing: results never double.
 */
final class RunRevaluation
{
    public const TRANSACTION_TYPE_KEY = 'system.fx_revaluation';

    public function __construct(
        private readonly PostingService $posting,
        private readonly LedgerPolicies $policies,
        private readonly AccountRoles $roles,
        private readonly AccountDirectory $accounts,
        private readonly ExchangeRates $rates,
        private readonly Currencies $currencies,
        private readonly CompanyDirectory $companies,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  list<string>  $actorPermissions
     *
     * @throws ApiException NOT_FOUND, MAPPING_REQUIRED, EXCHANGE_RATE_NOT_FOUND, PERIOD_NOT_OPEN and the posting failures
     */
    public function __invoke(string $companyId, string $periodId, int $actorId, array $actorPermissions = []): RevaluationResult
    {
        return DB::transaction(function () use ($companyId, $periodId, $actorId, $actorPermissions): RevaluationResult {
            $period = Period::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->find($periodId)
                ?? throw new ApiException(ErrorCode::NotFound);
            $end = $period->end_date->toDateString();

            $policy = $this->policies->current($companyId);

            if ($policy->revaluationRateTypeKey === null) {
                throw new ApiException(ErrorCode::MappingRequired, errors: [
                    'revaluation_rate_type_key' => [__('ledger.revaluation_rate_type_missing')],
                ]);
            }

            $gain = $this->roles->require($companyId, AccountRole::FxUnrealizedGain, $end);
            $loss = $this->roles->require($companyId, AccountRole::FxUnrealizedLoss, $end);

            // One run per period at a time, so two clicks cannot both post the same difference.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["{$companyId}:revalue:{$periodId}"]);

            $adjustments = $this->adjustments($companyId, $end, $policy->revaluationRateTypeKey);

            if ($adjustments === []) {
                return new RevaluationResult($periodId, null, null, null);
            }

            $lines = [];

            foreach ($adjustments as $adjustment) {
                $increase = $adjustment['adjustment']->isPositive();
                $amount = (string) $adjustment['adjustment']->abs();

                $lines[] = new PostJournalLine(
                    $adjustment['account_id'],
                    $increase ? $amount : '0',
                    $increase ? '0' : $amount,
                    description: (string) __('ledger.revaluation_line', ['currency' => $adjustment['currency']]),
                    currency: $adjustment['currency'],
                    dimensionSetId: $adjustment['dimension_set_id'],
                    rateTypeKey: $policy->revaluationRateTypeKey,
                    adjustment: true,
                );
                $lines[] = new PostJournalLine(
                    $increase ? $gain->accountId : $loss->accountId,
                    $increase ? '0' : $amount,
                    $increase ? $amount : '0',
                    description: (string) __('ledger.revaluation_line', ['currency' => $adjustment['currency']]),
                    dimensionSetId: $adjustment['dimension_set_id'],
                );
            }

            $journal = $this->posting->post(new PostJournal(
                companyId: $companyId,
                accountingDate: $end,
                description: (string) __('ledger.revaluation_description', ['period' => $period->name]),
                source: JournalSource::System,
                lines: $lines,
                transactionTypeKey: self::TRANSACTION_TYPE_KEY,
                references: ['period_id' => $periodId],
                postedBy: $actorId,
                actorPermissions: $actorPermissions,
            ));

            $reversal = $policy->revaluationAutoReverse
                ? $this->posting->reverse(new ReverseJournal(
                    companyId: $companyId,
                    journalId: $journal->id,
                    accountingDate: CarbonImmutable::parse($end)->addDay()->toDateString(),
                    description: (string) __('ledger.revaluation_reversal_description', ['period' => $period->name]),
                    postedBy: $actorId,
                    actorPermissions: $actorPermissions,
                ))
                : null;

            $runId = (string) Str::ulid();

            DB::table('gl_revaluation_runs')->insert([
                'id' => $runId,
                'company_id' => $companyId,
                'period_id' => $periodId,
                'journal_id' => $journal->id,
                'reversal_journal_id' => $reversal?->id,
                'rate_type_key' => $policy->revaluationRateTypeKey,
                'gain_mapping_version' => $gain->version,
                'loss_mapping_version' => $loss->version,
                'run_by' => $actorId,
                'created_at' => now(),
            ]);

            $this->audit->record(
                'revaluation.run',
                "Revaluation of period {$period->name} posted journal {$journal->number}.",
                properties: ['run_id' => $runId, 'period_id' => $periodId, 'journal_id' => $journal->id, 'reversal_journal_id' => $reversal?->id, 'adjustments' => count($adjustments)],
                companyId: $companyId,
            );

            return new RevaluationResult($periodId, $runId, $journal->id, $reversal?->id, array_map(fn (array $row): array => [
                'account_id' => $row['account_id'],
                'currency' => $row['currency'],
                'dimension_set_id' => $row['dimension_set_id'],
                'transaction_balance' => (string) $row['transaction_balance'],
                'carried' => (string) $row['carried'],
                'revalued' => (string) $row['revalued'],
                'adjustment' => (string) $row['adjustment'],
            ], $adjustments));
        });
    }

    /**
     * What each revaluable foreign currency balance is off by, at the closing rate of the date.
     *
     * @return list<array{account_id: string, currency: string, dimension_set_id: string, transaction_balance: BigDecimal, carried: BigDecimal, revalued: BigDecimal, adjustment: BigDecimal}>
     */
    private function adjustments(string $companyId, string $end, string $rateTypeKey): array
    {
        $company = $this->companies->find($companyId) ?? throw new ApiException(ErrorCode::CompanyReadOnly);
        $scale = $this->currencies->functionalScale($companyId);
        $mode = ($this->currencies->find($companyId, $company->functionalCurrency)->rounding ?? CurrencyRounding::HalfUp)->brick();

        $rows = DB::table('gl_journal_lines as l')
            ->join('gl_accounts as a', fn ($join) => $join->on('a.company_id', '=', 'l.company_id')->on('a.id', '=', 'l.account_id'))
            ->where('l.company_id', $companyId)
            ->where('a.is_revaluable', true)
            ->where('l.currency', '!=', $company->functionalCurrency)
            ->where('l.accounting_date', '<=', $end)
            ->groupBy('l.account_id', 'a.code', 'l.currency', 'l.dimension_set_id')
            ->orderBy('a.code')
            ->orderBy('l.currency')
            ->orderBy('l.dimension_set_id')
            ->selectRaw('l.account_id, l.currency, l.dimension_set_id')
            ->selectRaw('sum(case when l.debit > 0 then l.transaction_amount else -l.transaction_amount end) as transaction_balance')
            ->selectRaw('sum(l.debit - l.credit) as carried')
            ->get();

        $result = [];
        /** @var array<string, BigDecimal> $closing */
        $closing = [];

        foreach ($rows as $row) {
            $account = $this->accounts->find($companyId, $row->account_id);

            // A control account of a subledger is revalued from its open items instead (SL-FR-07).
            if ($account === null || (! $account->acceptsSource(JournalSource::Entry) && ! $account->acceptsSource(JournalSource::System))) {
                continue;
            }

            $closing[$row->currency] ??= BigDecimal::of($this->rates->find($companyId, $rateTypeKey, $row->currency, $end)->rate);

            $balance = BigDecimal::of($row->transaction_balance);
            $carried = BigDecimal::of($row->carried);
            $revalued = $balance->multipliedBy($closing[$row->currency])->toScale($scale, $mode);
            $adjustment = $revalued->minus($carried);

            if ($adjustment->isZero()) {
                continue;
            }

            $result[] = [
                'account_id' => $row->account_id,
                'currency' => $row->currency,
                'dimension_set_id' => $row->dimension_set_id,
                'transaction_balance' => $balance->toScale($this->currencies->find($companyId, $row->currency)->scale ?? $scale),
                'carried' => $carried->toScale($scale),
                'revalued' => $revalued,
                'adjustment' => $adjustment->toScale($scale),
            ];
        }

        return $result;
    }
}
