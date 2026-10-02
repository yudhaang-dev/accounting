<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\JournalData;
use App\Modules\GeneralLedger\Contracts\JournalDirectory;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Domain\Journal;
use App\Modules\MasterData\Contracts\DimensionSets;
use App\Support\Access\DataScopeContext;
use App\Support\Company\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Journals as seen through the data scope of the caller (IA-FR-03): one with no line inside the scope
 * does not exist for them, and one with only some of its lines inside is reported as not entirely in it.
 */
final class EloquentJournalDirectory implements JournalDirectory
{
    public function __construct(
        private readonly CompanyContext $company,
        private readonly DataScopeContext $scope,
        private readonly DimensionSets $dimensionSets,
    ) {}

    public function find(string $companyId, string $journalId): ?JournalData
    {
        return $this->company->run($companyId, function () use ($companyId, $journalId): ?JournalData {
            $journal = Journal::query()->find($journalId);

            if ($journal === null) {
                return null;
            }

            $lines = DB::table('gl_journal_lines')->where('journal_id', $journal->id);
            $total = (string) (clone $lines)->sum('debit');
            $withinScope = true;

            $scope = $this->scope->current();

            if ($scope->isRestricted()) {
                $allowed = $this->dimensionSets->setIdsWithin($companyId, $scope->allowed());
                $inside = (clone $lines)->whereIn('dimension_set_id', $allowed)->count();

                if ($inside === 0) {
                    return null;
                }

                $withinScope = $inside === (clone $lines)->count();
            }

            return new JournalData(
                $journal->id,
                $journal->number,
                $journal->accounting_date->toDateString(),
                JournalSource::from($journal->source),
                $journal->transaction_type_key,
                $total,
                Journal::query()->where('reverses_journal_id', $journal->id)->exists(),
                $journal->reverses_journal_id !== null,
                $withinScope,
            );
        });
    }
}
