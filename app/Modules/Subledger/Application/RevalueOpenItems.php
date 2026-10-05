<?php

namespace App\Modules\Subledger\Application;

use App\Modules\GeneralLedger\Contracts\AccountRole;
use App\Modules\GeneralLedger\Contracts\AccountRoles;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\LedgerPolicies;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Contracts\ReverseJournal;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\MasterData\Contracts\CurrencyRounding;
use App\Modules\MasterData\Contracts\ExchangeRates;
use App\Modules\Subledger\Contracts\ControlAccounts;
use App\Modules\Subledger\Domain\Allocation;
use App\Modules\Subledger\Domain\OpenItem;
use App\Modules\Subledger\Domain\OpenItemStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyScope;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Revalues the foreign currency open items at the end of a period (SL-FR-07). Each open item is valued at
 * the closing rate of the ledger policy and compared with the functional value it carries; the difference is
 * posted as a `system.fx_revaluation` journal to the control account and to the unrealized gain or loss
 * accounts, and reversed on the first day of the next period when the policy says so.
 *
 * With reversal, the open items keep the value they were recorded at, and what a run adjusted is kept per
 * item, so a run again for the period posts only what changed since. Without it, the adjustment becomes the
 * value the open item carries, so a run again finds nothing to do and a later settlement books the realized
 * difference from the closing rate. Either way the unrealized and the realized differences add up to the
 * difference from the rate of the open item to the rate of its settlement, and nothing is counted twice (SL-AC-06).
 */
final class RevalueOpenItems
{
    public const TRANSACTION_TYPE_KEY = 'system.fx_revaluation';

    public function __construct(
        private readonly PeriodDirectory $periods,
        private readonly LedgerPolicies $policies,
        private readonly AccountRoles $roles,
        private readonly ControlAccounts $controlAccounts,
        private readonly PostingService $posting,
        private readonly ExchangeRates $rates,
        private readonly Currencies $currencies,
        private readonly CompanyDirectory $companies,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  list<string>  $actorPermissions
     * @return array{id: string|null, period_id: string, journal_id: string|null, reversal_journal_id: string|null, adjustments: list<array{open_item_id: string, currency: string, remaining_amount: string, carried: string, revalued: string, adjustment: string}>}
     *
     * @throws ApiException NOT_FOUND, MAPPING_REQUIRED, EXCHANGE_RATE_NOT_FOUND, PERIOD_NOT_OPEN and the posting failures
     * @throws ValidationException when an allocation is dated after the period, which the values of the open items at its end no longer show
     */
    public function __invoke(string $companyId, string $periodId, int $actorId, array $actorPermissions = []): array
    {
        return DB::transaction(function () use ($companyId, $periodId, $actorId, $actorPermissions): array {
            $period = $this->periods->find($companyId, $periodId) ?? throw new ApiException(ErrorCode::NotFound);
            $end = $period->endDate;
            $policy = $this->policies->current($companyId);

            if ($policy->revaluationRateTypeKey === null) {
                throw new ApiException(ErrorCode::MappingRequired, errors: ['revaluation_rate_type_key' => [__('ledger.revaluation_rate_type_missing')]]);
            }

            $gain = $this->roles->require($companyId, AccountRole::FxUnrealizedGain, $end);
            $loss = $this->roles->require($companyId, AccountRole::FxUnrealizedLoss, $end);

            // One run per period at a time, so two clicks cannot both post the same difference.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["{$companyId}:open-item-revalue:{$periodId}"]);

            $adjustments = $this->adjustments($companyId, $periodId, $end, $policy->revaluationRateTypeKey, $policy->revaluationAutoReverse);

            if ($adjustments === []) {
                return ['id' => null, 'period_id' => $periodId, 'journal_id' => null, 'reversal_journal_id' => null, 'adjustments' => []];
            }

            $lines = $this->lines($companyId, $end, $adjustments, $policy->revaluationRateTypeKey, $gain->accountId, $loss->accountId);

            // Debits and credits of the control accounts that cancel out leave nothing to post.
            if ($lines === []) {
                return ['id' => null, 'period_id' => $periodId, 'journal_id' => null, 'reversal_journal_id' => null, 'adjustments' => []];
            }

            $journal = $this->posting->post(new PostJournal(
                companyId: $companyId,
                accountingDate: $end,
                description: (string) __('documents.revaluation_description', ['period' => $period->name]),
                source: JournalSource::Subledger,
                lines: $lines,
                transactionTypeKey: self::TRANSACTION_TYPE_KEY,
                references: ['period_id' => $periodId],
                entryId: (string) Str::ulid(),
                postedBy: $actorId,
                actorPermissions: $actorPermissions,
            ));

            $reversal = $policy->revaluationAutoReverse
                ? $this->posting->reverse(new ReverseJournal(
                    companyId: $companyId,
                    journalId: $journal->id,
                    accountingDate: CarbonImmutable::parse($end)->addDay()->toDateString(),
                    description: (string) __('documents.revaluation_reversal_description', ['period' => $period->name]),
                    entryId: (string) Str::ulid(),
                    postedBy: $actorId,
                    actorPermissions: $actorPermissions,
                ))
                : null;

            $runId = (string) Str::ulid();
            $now = now();

            DB::table('sl_revaluation_runs')->insert([
                'id' => $runId, 'company_id' => $companyId, 'period_id' => $periodId, 'journal_id' => $journal->id,
                'reversal_journal_id' => $reversal?->id, 'rate_type_key' => $policy->revaluationRateTypeKey,
                'auto_reverse' => $policy->revaluationAutoReverse, 'run_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach ($adjustments as $row) {
                DB::table('sl_revaluation_items')->insert([
                    'id' => (string) Str::ulid(), 'company_id' => $companyId, 'run_id' => $runId, 'open_item_id' => $row['item']->id,
                    'remaining_amount' => (string) $row['remaining']->toScale(6), 'rate' => $row['rate'], 'adjustment' => (string) $row['adjustment'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                // Without reversal the open item carries the revalued value from now on.
                if (! $policy->revaluationAutoReverse) {
                    $row['item']->forceFill(['remaining_functional' => $row['item']->remaining_functional->plus($row['adjustment'])])->save();
                }
            }

            $this->audit->record(
                'open_item_revaluation.run',
                "Revaluation of the open items of period {$period->name} posted journal {$journal->number}.",
                properties: ['run_id' => $runId, 'period_id' => $periodId, 'journal_id' => $journal->id, 'reversal_journal_id' => $reversal?->id, 'adjustments' => count($adjustments)],
                companyId: $companyId,
            );

            return [
                'id' => $runId,
                'period_id' => $periodId,
                'journal_id' => $journal->id,
                'reversal_journal_id' => $reversal?->id,
                'adjustments' => array_map(fn (array $row): array => [
                    'open_item_id' => $row['item']->id,
                    'currency' => $row['item']->currency,
                    'remaining_amount' => (string) $row['remaining']->toScale(6),
                    'carried' => (string) $row['carried']->toScale(6),
                    'revalued' => (string) $row['revalued']->toScale(6),
                    'adjustment' => (string) $row['adjustment']->toScale(6),
                ], $adjustments),
            ];
        });
    }

    /**
     * What each open item is off by, at the closing rate of the date. An item is valued as it stands at the
     * end of the period; the items of documents dated later are left out.
     *
     * @return list<array{item: OpenItem, remaining: BigDecimal, rate: string, carried: BigDecimal, revalued: BigDecimal, adjustment: BigDecimal}>
     *
     * @throws ValidationException
     */
    private function adjustments(string $companyId, string $periodId, string $end, string $rateTypeKey, bool $autoReverse): array
    {
        $functional = $this->companies->find($companyId)->functionalCurrency ?? throw new ApiException(ErrorCode::CompanyReadOnly);
        $scale = $this->currencies->functionalScale($companyId);
        $mode = ($this->currencies->find($companyId, $functional)->rounding ?? CurrencyRounding::HalfUp)->brick();

        $items = OpenItem::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('status', OpenItemStatus::Open->value)
            ->where('currency', '!=', $functional)
            ->whereDate('document_date', '<=', $end)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($items->isEmpty()) {
            return [];
        }

        $later = Allocation::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->where('status', 'active')->whereDate('allocation_date', '>', $end)
            ->where(fn ($query) => $query->whereIn('debit_item_id', $items->modelKeys())->orWhereIn('credit_item_id', $items->modelKeys()))
            ->exists();

        if ($later) {
            throw ValidationException::withMessages(['period_id' => [__('documents.revaluation_later_allocations')]]);
        }

        // What earlier runs of this period adjusted and a reversal will take back; the items do not show it.
        $previous = $autoReverse
            ? DB::table('sl_revaluation_items as i')->join('sl_revaluation_runs as r', 'r.id', '=', 'i.run_id')
                ->where('i.company_id', $companyId)->where('r.period_id', $periodId)->where('r.auto_reverse', true)
                ->groupBy('i.open_item_id')->selectRaw('i.open_item_id, sum(i.adjustment) as adjusted')->pluck('adjusted', 'open_item_id')
            : collect();

        $rates = [];
        $result = [];

        foreach ($items as $item) {
            $rate = $rates[$item->currency] ??= $this->rates->find($companyId, $rateTypeKey, $item->currency, $end)->rate;
            $revalued = BigDecimal::of($item->remaining_amount)->multipliedBy($rate)->toScale($scale, $mode);
            $carried = $item->remaining_functional->plus($previous[$item->id] ?? '0');
            $adjustment = $revalued->minus($carried);

            if (! $adjustment->isZero()) {
                $result[] = ['item' => $item, 'remaining' => $item->remaining_amount, 'rate' => $rate, 'carried' => $carried, 'revalued' => $revalued, 'adjustment' => $adjustment];
            }
        }

        return $result;
    }

    /**
     * The control account lines of the adjustments, one per control account and currency, against the unrealized
     * gain or loss: more debit than credit is a gain.
     *
     * @param  list<array{item: OpenItem, remaining: BigDecimal, rate: string, carried: BigDecimal, revalued: BigDecimal, adjustment: BigDecimal}>  $adjustments
     * @return list<PostJournalLine>
     */
    private function lines(string $companyId, string $end, array $adjustments, string $rateTypeKey, string $gainAccountId, string $lossAccountId): array
    {
        /** @var array<string, array{account: string, currency: string, net: BigDecimal}> $groups */
        $groups = [];

        foreach ($adjustments as $row) {
            $item = $row['item'];
            $account = $this->controlAccounts->require($companyId, $item->control_role, $item->party_id, $end)->accountId;
            $key = "{$account}|{$item->currency}";
            // A debit side item worth more is a debit; a credit side one worth more is a credit.
            $effect = $item->side === 'debit' ? $row['adjustment'] : $row['adjustment']->negated();
            $groups[$key] = ['account' => $account, 'currency' => $item->currency, 'net' => ($groups[$key]['net'] ?? BigDecimal::zero())->plus($effect)];
        }

        $lines = [];

        foreach ($groups as $group) {
            if ($group['net']->isZero()) {
                continue;
            }

            $debit = $group['net']->isPositive();
            $amount = (string) $group['net']->abs();
            $description = (string) __('documents.revaluation_line', ['currency' => $group['currency']]);

            $lines[] = new PostJournalLine($group['account'], $debit ? $amount : '0', $debit ? '0' : $amount, description: $description, currency: $group['currency'], rateTypeKey: $rateTypeKey, adjustment: true);
            $lines[] = new PostJournalLine($debit ? $gainAccountId : $lossAccountId, $debit ? '0' : $amount, $debit ? $amount : '0', description: $description);
        }

        return $lines;
    }
}
