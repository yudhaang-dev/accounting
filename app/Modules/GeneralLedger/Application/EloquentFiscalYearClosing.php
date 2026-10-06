<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountRole;
use App\Modules\GeneralLedger\Contracts\AccountRoles;
use App\Modules\GeneralLedger\Contracts\ClosingRunData;
use App\Modules\GeneralLedger\Contracts\FiscalYearClose;
use App\Modules\GeneralLedger\Contracts\FiscalYearClosing;
use App\Modules\GeneralLedger\Contracts\FiscalYearReopen;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Contracts\ReverseJournal;
use App\Modules\GeneralLedger\Contracts\SystemTransactionKeys;
use App\Modules\MasterData\Contracts\Currencies;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Closes a fiscal year with one journal (GL-FR-12). Every income and expense account that carries a
 * balance for the year, per dimension set, is brought to zero, and the result of each dimension set
 * goes to the `retained_earnings` account in that same set, so the dimensions of the result stay
 * known. The year is read from the ledger without earlier closing journals, so closing again after a
 * reopening starts from the real activity of the year. Balance sheet accounts are untouched: their
 * balance in the next year is what the ledger holds on the last day of this one (GL-FR-13).
 */
final class EloquentFiscalYearClosing implements FiscalYearClosing
{
    public const TRANSACTION_TYPE_KEY = SystemTransactionKeys::CLOSING;

    public function __construct(
        private readonly PostingService $posting,
        private readonly EloquentPostingValidator $validator,
        private readonly AccountRoles $roles,
        private readonly PeriodDirectory $periods,
        private readonly Currencies $currencies,
        private readonly CompanyContext $company,
        private readonly Audit $audit,
    ) {}

    public function close(FiscalYearClose $command): ClosingRunData
    {
        return $this->company->run($command->companyId, fn (): ClosingRunData => DB::transaction(function () use ($command): ClosingRunData {
            $this->validator->company($command->companyId);

            $year = $this->periods->fiscalYear($command->companyId, $command->fiscalYearId)
                ?? throw new ApiException(ErrorCode::NotFound);

            // One closing of a year at a time; the partial unique index is the backstop.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["{$command->companyId}:close-year:{$year->id}"]);

            $existing = $this->current($command->companyId, $year->id);

            if ($existing !== null) {
                return new ClosingRunData(...[...get_object_vars($existing), 'replayed' => true]);
            }

            $this->assertBalanced($command->companyId, $year->endDate);

            $retained = $this->roles->require($command->companyId, AccountRole::RetainedEarnings, $year->endDate);
            $scale = $this->currencies->functionalScale($command->companyId);
            $balances = $this->balances($command->companyId, $year->startDate, $year->endDate);

            $lines = [];
            $bySet = [];
            $result = BigDecimal::zero();

            foreach ($balances as $row) {
                $net = BigDecimal::of($row->net);
                $amount = (string) $net->abs()->toScale($scale);

                $lines[] = new PostJournalLine(
                    $row->account_id,
                    $net->isNegative() ? $amount : '0',
                    $net->isNegative() ? '0' : $amount,
                    description: (string) __('ledger.closing_line'),
                    dimensionSetId: $row->dimension_set_id,
                );
                $bySet[$row->dimension_set_id] = ($bySet[$row->dimension_set_id] ?? BigDecimal::zero())->plus($net);
                $result = $result->plus($net);
            }

            foreach ($bySet as $setId => $net) {
                if ($net->isZero()) {
                    continue;
                }

                $amount = (string) $net->abs()->toScale($scale);

                $lines[] = new PostJournalLine(
                    $retained->accountId,
                    $net->isPositive() ? $amount : '0',
                    $net->isPositive() ? '0' : $amount,
                    description: (string) __('ledger.closing_line'),
                    dimensionSetId: $setId,
                );
            }

            $id = (string) Str::ulid();

            $journal = $lines === [] ? null : $this->posting->post(new PostJournal(
                companyId: $command->companyId,
                accountingDate: $year->endDate,
                description: (string) __('ledger.closing_description', ['year' => $year->name]),
                source: JournalSource::System,
                lines: $lines,
                transactionTypeKey: self::TRANSACTION_TYPE_KEY,
                references: ['fiscal_year_id' => $year->id],
                entryId: "closing:{$id}",
                postedBy: $command->closedBy,
                actorPermissions: $command->actorPermissions,
            ));

            DB::table('gl_closing_runs')->insert([
                'id' => $id,
                'company_id' => $command->companyId,
                'fiscal_year_id' => $year->id,
                'journal_id' => $journal?->id,
                'status' => 'closed',
                'retained_earnings_mapping_version' => $retained->version,
                'line_count' => count($lines),
                'closed_by' => $command->closedBy,
                'created_at' => now(),
            ]);

            $this->audit->record(
                'fiscal_year.closed',
                "Fiscal year {$year->name} closed".($journal === null ? ' without a closing journal.' : " with journal {$journal->number}."),
                properties: ['run_id' => $id, 'fiscal_year_id' => $year->id, 'journal_id' => $journal?->id, 'result' => (string) $result->negated()->toScale($scale)],
                companyId: $command->companyId,
            );

            return $this->current($command->companyId, $year->id) ?? throw new ApiException(ErrorCode::ServerError);
        }));
    }

    public function reopen(FiscalYearReopen $command): ClosingRunData
    {
        return $this->company->run($command->companyId, fn (): ClosingRunData => DB::transaction(function () use ($command): ClosingRunData {
            $this->validator->company($command->companyId);

            $year = $this->periods->fiscalYear($command->companyId, $command->fiscalYearId)
                ?? throw new ApiException(ErrorCode::NotFound);

            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["{$command->companyId}:close-year:{$year->id}"]);

            $run = $this->runs($command->companyId, $year->id)->where('r.status', 'closed')->first()
                ?? throw new ApiException(ErrorCode::InvalidStateTransition, errors: [
                    'fiscal_year' => [__('ledger.fiscal_year_not_closed', ['year' => $year->name])],
                ]);

            // Marked first: while the year counts as closed, the ledger refuses the reversal itself.
            DB::table('gl_closing_runs')->where('company_id', $command->companyId)->where('id', $run->id)->update([
                'status' => 'reopened',
                'reopened_by' => $command->reopenedBy,
                'reopen_reason' => $command->reason,
                'reopened_at' => now(),
            ]);

            $reversal = $run->journal_id === null ? null : $this->posting->reverse(new ReverseJournal(
                companyId: $command->companyId,
                journalId: $run->journal_id,
                accountingDate: $year->endDate,
                description: (string) __('ledger.reopen_description', ['year' => $year->name]),
                entryId: "reopen:{$run->id}",
                postedBy: $command->reopenedBy,
                actorPermissions: $command->actorPermissions,
            ));

            DB::table('gl_closing_runs')->where('company_id', $command->companyId)->where('id', $run->id)->update(['reopen_journal_id' => $reversal?->id]);

            $this->audit->record(
                'fiscal_year.reopened',
                "Fiscal year {$year->name} reopened".($reversal === null ? '.' : ", closing journal reversed by {$reversal->number}."),
                properties: ['run_id' => $run->id, 'fiscal_year_id' => $year->id, 'reason' => $command->reason, 'journal_id' => $run->journal_id, 'reversal_journal_id' => $reversal?->id],
                companyId: $command->companyId,
            );

            return $this->data($this->runs($command->companyId, $year->id)->where('r.id', $run->id)->sole());
        }));
    }

    public function current(string $companyId, string $fiscalYearId): ?ClosingRunData
    {
        $run = $this->runs($companyId, $fiscalYearId)->where('r.status', 'closed')->first();

        return $run === null ? null : $this->data($run);
    }

    private function runs(string $companyId, string $fiscalYearId): Builder
    {
        return DB::table('gl_closing_runs as r')
            ->leftJoin('gl_journals as j', fn ($join) => $join->on('j.company_id', '=', 'r.company_id')->on('j.id', '=', 'r.journal_id'))
            ->where('r.company_id', $companyId)->where('r.fiscal_year_id', $fiscalYearId)
            ->select('r.*', 'j.number as journal_number');
    }

    private function data(stdClass $run): ClosingRunData
    {
        $result = $run->journal_id === null ? '0' : (string) DB::table('gl_journal_lines as l')
            ->join('gl_accounts as a', fn ($join) => $join->on('a.company_id', '=', 'l.company_id')->on('a.id', '=', 'l.account_id'))
            ->where('l.company_id', $run->company_id)->where('l.journal_id', $run->journal_id)->whereIn('a.class', ['income', 'expense'])
            ->sum(DB::raw('l.debit - l.credit'));

        return new ClosingRunData(
            $run->id,
            $run->fiscal_year_id,
            $run->status,
            $run->journal_id,
            $run->journal_number,
            (int) $run->line_count,
            (string) BigDecimal::of($result)->toScale(2),
            (int) $run->retained_earnings_mapping_version,
            CarbonImmutable::parse($run->created_at)->toIso8601String(),
            reversalJournalId: $run->reopen_journal_id,
        );
    }

    /**
     * The ledger must balance up to the end of the year. Posting enforces it per journal, so a
     * difference means the data was tampered with; closing on top of it would hide that.
     *
     * @throws ApiException UNBALANCED_ENTRY
     */
    private function assertBalanced(string $companyId, string $end): void
    {
        $totals = DB::table('gl_journal_lines')->where('company_id', $companyId)->where('accounting_date', '<=', $end)
            ->selectRaw('coalesce(sum(debit), 0) as debit, coalesce(sum(credit), 0) as credit')->first();

        if (! BigDecimal::of($totals->debit)->isEqualTo($totals->credit)) {
            throw new ApiException(ErrorCode::UnbalancedEntry, errors: [
                'fiscal_year' => [__('ledger.closing_unbalanced', ['debit' => $totals->debit, 'credit' => $totals->credit])],
            ]);
        }
    }

    /**
     * Net debit minus credit of every income and expense account per dimension set over the year,
     * without the closing journals; accounts that net to zero are left out.
     *
     * @return list<stdClass>
     */
    private function balances(string $companyId, string $start, string $end): array
    {
        $rows = DB::table('gl_journal_lines as l')
            ->join('gl_journals as j', fn ($join) => $join->on('j.company_id', '=', 'l.company_id')->on('j.id', '=', 'l.journal_id'))
            ->join('gl_accounts as a', fn ($join) => $join->on('a.company_id', '=', 'l.company_id')->on('a.id', '=', 'l.account_id'))
            ->where('l.company_id', $companyId)
            ->whereIn('a.class', ['income', 'expense'])
            ->whereBetween('l.accounting_date', [$start, $end])
            ->whereRaw('j.transaction_type_key is distinct from ?', [self::TRANSACTION_TYPE_KEY])
            ->groupBy('l.account_id', 'a.code', 'l.dimension_set_id')
            ->havingRaw('sum(l.debit - l.credit) <> 0')
            ->orderBy('a.code')
            ->orderBy('l.dimension_set_id')
            ->selectRaw('l.account_id, l.dimension_set_id, sum(l.debit - l.credit) as net')
            ->get()->all();

        return array_values($rows);
    }
}
