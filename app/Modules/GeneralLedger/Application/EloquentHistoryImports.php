<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\HistoryImport;
use App\Modules\GeneralLedger\Contracts\HistoryImportData;
use App\Modules\GeneralLedger\Contracts\HistoryImports;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Imports the history between the ledger start date and the cutover date as journals (GL-FR-11),
 * one per transaction or one per period, and then holds the ledger to the trial balance of the
 * source at the cutover date: per account, what the opening balances and the history add up to must
 * be what the source reports. Mappings of the opening balance batch apply; the command may add to
 * them. A difference rejects the whole import.
 */
final class EloquentHistoryImports implements HistoryImports
{
    public const TRANSACTION_TYPE_KEY = 'system.history_import';

    public function __construct(
        private readonly PostingService $posting,
        private readonly EloquentPostingValidator $validator,
        private readonly ConversionMapper $mapper,
        private readonly PeriodDirectory $periods,
        private readonly CompanyContext $company,
        private readonly Audit $audit,
    ) {}

    public function import(HistoryImport $command): HistoryImportData
    {
        return $this->company->run($command->companyId, fn (): HistoryImportData => DB::transaction(function () use ($command): HistoryImportData {
            $this->validator->company($command->companyId);

            // Same lock as the opening balance import: the batch cannot change while history is imported.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["{$command->companyId}:opening-balances"]);

            $batch = DB::table('gl_opening_balance_batches')->where('company_id', $command->companyId)->first()
                ?? throw new ApiException(ErrorCode::InvalidStateTransition, errors: ['source_key' => [__('ledger.history_needs_opening')]]);

            $mapping = json_decode((string) $batch->mapping, true, flags: JSON_THROW_ON_ERROR);
            $accountMapping = $command->accountMapping + $mapping['accounts'];
            $dimensionMapping = array_replace_recursive($mapping['dimensions'], $command->dimensionMapping);

            $hash = $this->mapper->hash([
                'granularity' => $command->granularity,
                'accounts' => $command->accountMapping,
                'dimensions' => $command->dimensionMapping,
                'control_totals' => $command->controlTotals,
                'journals' => array_map(fn ($journal): array => (array) $journal, $command->journals),
                'cutover_balances' => array_map(fn ($line): array => (array) $line, $command->cutoverBalances),
            ]);

            $existing = DB::table('gl_history_imports')->where('company_id', $command->companyId)->first();

            if ($existing !== null) {
                if ($existing->source_key !== $command->sourceKey) {
                    throw new ApiException(ErrorCode::InvalidStateTransition, errors: ['source_key' => [__('ledger.history_already_imported', ['key' => $existing->source_key])]]);
                }

                if ($existing->payload_hash !== $hash) {
                    throw new ApiException(ErrorCode::IdempotencyKeyReused, errors: ['source_key' => [__('ledger.history_key_reused', ['key' => $existing->source_key])]]);
                }

                return $this->data($existing, replayed: true);
            }

            $start = CarbonImmutable::parse($batch->ledger_start_date)->toDateString();
            $cutover = CarbonImmutable::parse($batch->cutover_date)->toDateString();
            $last = CarbonImmutable::parse($cutover)->subDay()->toDateString();

            $this->structure($command, $start, $cutover, $last);

            $touched = [];

            foreach ($command->journals as $index => $journal) {
                $resolved = $this->mapper->translate($command->companyId, $accountMapping, $dimensionMapping, $journal->lines, "journals.{$index}.lines");
                $touched = [...$touched, ...$resolved['account_ids']];

                $this->posting->post(new PostJournal(
                    companyId: $command->companyId,
                    accountingDate: $journal->accountingDate,
                    description: $journal->description ?? (string) __('ledger.history_description', ['date' => $journal->accountingDate]),
                    source: JournalSource::System,
                    lines: $resolved['lines'],
                    transactionTypeKey: self::TRANSACTION_TYPE_KEY,
                    references: ['history_source_key' => $command->sourceKey, 'granularity' => $command->granularity],
                    postedBy: $command->importedBy,
                    actorPermissions: $command->actorPermissions,
                    openingBalance: true,
                ));
            }

            $source = $this->mapper->translate($command->companyId, $accountMapping, $dimensionMapping, $command->cutoverBalances, 'cutover_balances');
            $balances = $this->ledgerBalances($command->companyId, $cutover);

            $this->matchSource($command, $source['lines'], $balances);
            $this->controlAccounts($command, array_values(array_unique($touched)), $balances);

            $id = (string) Str::ulid();

            DB::table('gl_history_imports')->insert([
                'id' => $id,
                'company_id' => $command->companyId,
                'batch_id' => $batch->id,
                'source_key' => $command->sourceKey,
                'payload_hash' => $hash,
                'granularity' => $command->granularity,
                'journal_count' => count($command->journals),
                'mapping' => json_encode(['accounts' => $command->accountMapping, 'dimensions' => $command->dimensionMapping], JSON_THROW_ON_ERROR),
                'imported_by' => $command->importedBy,
                'created_at' => now(),
            ]);

            DB::table('gl_opening_balance_batches')->where('company_id', $command->companyId)->where('id', $batch->id)->update(['history_granularity' => $command->granularity]);

            $this->audit->record(
                'history.imported',
                "History from {$start} to {$last} imported from {$command->sourceKey} as ".count($command->journals).' journals.',
                properties: ['history_import_id' => $id, 'batch_id' => $batch->id, 'source_key' => $command->sourceKey, 'granularity' => $command->granularity, 'journals' => count($command->journals)],
                companyId: $command->companyId,
            );

            return $this->data(DB::table('gl_history_imports')->where('id', $id)->sole());
        }));
    }

    public function current(string $companyId): ?HistoryImportData
    {
        $import = DB::table('gl_history_imports')->where('company_id', $companyId)->first();

        return $import === null ? null : $this->data($import);
    }

    private function data(stdClass $import, bool $replayed = false): HistoryImportData
    {
        return new HistoryImportData(
            $import->id,
            $import->batch_id,
            $import->source_key,
            $import->granularity,
            (int) $import->journal_count,
            CarbonImmutable::parse($import->created_at)->toIso8601String(),
            $replayed,
        );
    }

    /**
     * The history lies between the ledger start date and the day before the cutover date; a period
     * summary has one journal per period.
     *
     * @throws ApiException INVALID_STATE_TRANSITION
     * @throws ValidationException
     */
    private function structure(HistoryImport $command, string $start, string $cutover, string $last): void
    {
        if ($start === $cutover) {
            throw new ApiException(ErrorCode::InvalidStateTransition, errors: ['source_key' => [__('ledger.history_no_gap')]]);
        }

        $errors = [];

        if (! in_array($command->granularity, [HistoryImport::PERIOD_SUMMARY, HistoryImport::TRANSACTIONS], true)) {
            $errors['granularity'][] = __('ledger.history_granularity_invalid');
        }

        if ($command->journals === []) {
            $errors['journals'][] = __('ledger.history_journals_required');
        }

        $periods = [];

        foreach ($command->journals as $index => $journal) {
            if (! $this->isDate($journal->accountingDate)) {
                $errors["journals.{$index}.accounting_date"][] = __('validation.date_format', ['attribute' => 'accounting_date', 'format' => 'Y-m-d']);

                continue;
            }

            if ($journal->accountingDate < $start || $journal->accountingDate > $last) {
                $errors["journals.{$index}.accounting_date"][] = __('ledger.history_date_out_of_range', ['from' => $start, 'to' => $last]);

                continue;
            }

            $period = $this->periods->forDate($command->companyId, $journal->accountingDate);

            if ($command->granularity === HistoryImport::PERIOD_SUMMARY && $period !== null) {
                if (isset($periods[$period->id])) {
                    $errors["journals.{$index}.accounting_date"][] = __('ledger.history_period_repeated', ['period' => $period->name, 'first' => $periods[$period->id] + 1]);
                }

                $periods[$period->id] ??= $index;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Net debit minus credit per account of everything the ledger holds before the cutover date.
     *
     * @return array<string, string>
     */
    private function ledgerBalances(string $companyId, string $cutover): array
    {
        /** @var array<string, string> */
        return DB::table('gl_journal_lines')
            ->where('company_id', $companyId)->where('accounting_date', '<', $cutover)
            ->groupBy('account_id')
            ->selectRaw('account_id, sum(debit - credit) as net')
            ->pluck('net', 'account_id')->all();
    }

    /**
     * The ledger must carry, per account, what the source reports at the cutover date, and nothing
     * where the source reports nothing.
     *
     * @param  list<PostJournalLine>  $source
     * @param  array<string, string>  $balances
     *
     * @throws ValidationException
     */
    private function matchSource(HistoryImport $command, array $source, array $balances): void
    {
        $expected = [];

        foreach ($source as $line) {
            $expected[$line->accountId] = ($expected[$line->accountId] ?? BigDecimal::zero())->plus($line->debit)->minus($line->credit);
        }

        $codes = DB::table('gl_accounts')->where('company_id', $command->companyId)
            ->whereIn('id', array_unique([...array_keys($expected), ...array_keys($balances)]))->pluck('code', 'id');
        $errors = [];

        foreach (array_unique([...array_keys($expected), ...array_keys($balances)]) as $accountId) {
            $ledger = BigDecimal::of($balances[$accountId] ?? '0');
            $reported = $expected[$accountId] ?? BigDecimal::zero();

            if (! $ledger->isEqualTo($reported)) {
                $errors['cutover_balances'][] = __('ledger.history_cutover_differs', [
                    'account' => $codes[$accountId] ?? $accountId,
                    'ledger' => $ledger->toScale(2)->toString(),
                    'source' => $reported->toScale(2)->toString(),
                ]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A control account touched by the history must carry, at the cutover date, what the subledger
     * holds in open items.
     *
     * @param  list<string>  $accountIds
     * @param  array<string, string>  $balances
     *
     * @throws ValidationException
     */
    private function controlAccounts(HistoryImport $command, array $accountIds, array $balances): void
    {
        $totals = $command->controlTotals ?? [];
        $control = $this->mapper->controlAccounts($command->companyId, $accountIds, $totals);

        if ($control !== []) {
            $this->mapper->verifyControl($control, $accountIds, $totals, $balances);
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
}
