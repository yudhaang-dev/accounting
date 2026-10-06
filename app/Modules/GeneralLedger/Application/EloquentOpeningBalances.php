<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\AccountClass;
use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\OpeningBalanceBatchData;
use App\Modules\GeneralLedger\Contracts\OpeningBalanceImport;
use App\Modules\GeneralLedger\Contracts\OpeningBalances;
use App\Modules\GeneralLedger\Contracts\PeriodDirectory;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Contracts\SystemTransactionKeys;
use App\Modules\GeneralLedger\Domain\Account;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Imports the opening balances of a company as one journal (GL-FR-11). The source codes of accounts
 * and dimension values are translated through the mapping of the command, and a code without a
 * mapping refuses the whole batch. The rest of the validation (balance, active leaf accounts,
 * required dimensions, currencies, the period) is that of every posting. The batch is recorded in
 * the same transaction as its journal, so a failure leaves neither.
 */
final class EloquentOpeningBalances implements OpeningBalances
{
    public const TRANSACTION_TYPE_KEY = SystemTransactionKeys::OPENING_BALANCE;

    public function __construct(
        private readonly PostingService $posting,
        private readonly EloquentPostingValidator $validator,
        private readonly AccountDirectory $accounts,
        private readonly ConversionMapper $mapper,
        private readonly PeriodDirectory $periods,
        private readonly CompanyContext $company,
        private readonly Audit $audit,
    ) {}

    public function import(OpeningBalanceImport $command): OpeningBalanceBatchData
    {
        return $this->company->run($command->companyId, fn (): OpeningBalanceBatchData => DB::transaction(function () use ($command): OpeningBalanceBatchData {
            $this->validator->company($command->companyId);

            // One import at a time per company: two requests cannot both find no batch and post.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["{$command->companyId}:opening-balances"]);

            $hash = $this->hash($command);
            $existing = $this->batches($command->companyId)->first();

            if ($existing !== null) {
                if ($existing->source_key !== $command->sourceKey) {
                    throw new ApiException(ErrorCode::InvalidStateTransition, errors: [
                        'source_key' => [__('ledger.opening_already_imported', ['key' => $existing->source_key])],
                    ]);
                }

                if ($existing->payload_hash !== $hash) {
                    throw new ApiException(ErrorCode::IdempotencyKeyReused, errors: [
                        'source_key' => [__('ledger.opening_key_reused', ['key' => $existing->source_key])],
                    ]);
                }

                return $this->data($existing, replayed: true);
            }

            $this->dates($command);
            $resolved = $this->resolve($command);

            $journal = $this->posting->post(new PostJournal(
                companyId: $command->companyId,
                accountingDate: $command->ledgerStartDate,
                description: (string) __('ledger.opening_description', ['date' => $command->ledgerStartDate]),
                source: JournalSource::System,
                lines: $resolved['lines'],
                transactionTypeKey: self::TRANSACTION_TYPE_KEY,
                references: ['opening_balance_source_key' => $command->sourceKey, 'source_system' => $command->sourceSystem],
                postedBy: $command->importedBy,
                actorPermissions: $command->actorPermissions,
                openingBalance: true,
            ));

            $this->controlAccounts($command, $journal->id, $resolved['account_ids']);

            $id = (string) Str::ulid();

            DB::table('gl_opening_balance_batches')->insert([
                'id' => $id,
                'company_id' => $command->companyId,
                'source_key' => $command->sourceKey,
                'source_system' => $command->sourceSystem,
                'payload_hash' => $hash,
                'cutover_date' => $command->cutoverDate,
                'ledger_start_date' => $command->ledgerStartDate,
                'status' => 'posted',
                'journal_id' => $journal->id,
                'line_count' => count($command->lines),
                'mapping' => json_encode(['accounts' => $command->accountMapping, 'dimensions' => $command->dimensionMapping], JSON_THROW_ON_ERROR),
                'imported_by' => $command->importedBy,
                'created_at' => now(),
            ]);

            $this->audit->record(
                'opening_balance.imported',
                "Opening balances of {$command->ledgerStartDate} imported from {$command->sourceKey} as journal {$journal->number}.",
                properties: ['batch_id' => $id, 'journal_id' => $journal->id, 'source_key' => $command->sourceKey, 'cutover_date' => $command->cutoverDate, 'ledger_start_date' => $command->ledgerStartDate, 'lines' => count($command->lines)],
                companyId: $command->companyId,
            );

            return $this->data($this->batches($command->companyId)->where('b.id', $id)->sole());
        }));
    }

    public function current(string $companyId): ?OpeningBalanceBatchData
    {
        $batch = $this->batches($companyId)->first();

        return $batch === null ? null : $this->data($batch);
    }

    private function batches(string $companyId): Builder
    {
        return DB::table('gl_opening_balance_batches as b')
            ->join('gl_journals as j', fn ($join) => $join->on('j.company_id', '=', 'b.company_id')->on('j.id', '=', 'b.journal_id'))
            ->where('b.company_id', $companyId)
            ->select('b.*', 'j.number as journal_number');
    }

    private function data(stdClass $batch, bool $replayed = false): OpeningBalanceBatchData
    {
        return new OpeningBalanceBatchData(
            $batch->id,
            $batch->source_key,
            $batch->source_system,
            CarbonImmutable::parse($batch->cutover_date)->toDateString(),
            CarbonImmutable::parse($batch->ledger_start_date)->toDateString(),
            $batch->history_granularity,
            $batch->status,
            $batch->journal_id,
            $batch->journal_number,
            (int) $batch->line_count,
            CarbonImmutable::parse($batch->created_at)->toIso8601String(),
            $replayed,
        );
    }

    /** The same source key, content and mapping give the same hash. */
    private function hash(OpeningBalanceImport $command): string
    {
        $payload = [
            'cutover_date' => $command->cutoverDate,
            'ledger_start_date' => $command->ledgerStartDate,
            'source_system' => $command->sourceSystem,
            'accounts' => $command->accountMapping,
            'dimensions' => $command->dimensionMapping,
            'control_totals' => $command->controlTotals,
            'lines' => array_map(fn ($line): array => (array) $line, $command->lines),
        ];

        // Left out when absent, so the hash of a batch imported before it existed stays what it was.
        if ($command->controlDetailDigest !== null) {
            $payload['control_detail'] = $command->controlDetailDigest;
        }

        return $this->mapper->hash($payload);
    }

    /**
     * The balances are dated the ledger start date, which must begin a period so that the opening
     * balance of the period is the batch. Income and expense are carried only when the ledger starts
     * inside a fiscal year: at its first day they have no year to date.
     *
     * @throws ValidationException
     */
    private function dates(OpeningBalanceImport $command): void
    {
        $errors = [];

        foreach (['cutover_date' => $command->cutoverDate, 'ledger_start_date' => $command->ledgerStartDate] as $field => $value) {
            if (! $this->isDate($value)) {
                $errors[$field][] = __('validation.date_format', ['attribute' => $field, 'format' => 'Y-m-d']);
            }
        }

        if ($errors === [] && $command->ledgerStartDate > $command->cutoverDate) {
            $errors['ledger_start_date'][] = __('ledger.opening_start_after_cutover');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Fails with PERIOD_NOT_OPEN when no open period covers the date.
        $this->validator->periodFor($command->companyId, $command->ledgerStartDate, self::TRANSACTION_TYPE_KEY, $command->actorPermissions);
        $period = $this->periods->forDate($command->companyId, $command->ledgerStartDate);

        if ($period !== null && $period->startDate !== $command->ledgerStartDate) {
            throw ValidationException::withMessages([
                'ledger_start_date' => [__('ledger.opening_start_not_period_start', ['period' => $period->name, 'start' => $period->startDate])],
            ]);
        }
    }

    /**
     * @return array{lines: list<PostJournalLine>, account_ids: list<string>}
     *
     * @throws ApiException MAPPING_REQUIRED
     * @throws ValidationException
     */
    private function resolve(OpeningBalanceImport $command): array
    {
        $resolved = $this->mapper->translate($command->companyId, $command->accountMapping, $command->dimensionMapping, $command->lines);

        $this->yearToDate($command, $resolved['account_ids']);

        return $resolved;
    }

    /**
     * @param  list<string>  $accountIds
     *
     * @throws ValidationException
     */
    private function yearToDate(OpeningBalanceImport $command, array $accountIds): void
    {
        $period = $this->periods->forDate($command->companyId, $command->ledgerStartDate);

        if ($period === null) {
            return;
        }

        $yearStart = (string) DB::table('gl_fiscal_years')->where('company_id', $command->companyId)->where('id', $period->fiscalYearId)->value('start_date');

        if (CarbonImmutable::parse($yearStart)->toDateString() !== $command->ledgerStartDate) {
            return;
        }

        foreach ($accountIds as $accountId) {
            $account = $this->accounts->find($command->companyId, $accountId);

            if ($account !== null && in_array($account->class, [AccountClass::Income, AccountClass::Expense], true)) {
                throw ValidationException::withMessages([
                    'lines' => [__('ledger.opening_year_to_date_at_year_start', ['account' => $account->code])],
                ]);
            }
        }
    }

    /**
     * A control account of a subledger must carry what the subledger holds in open items. This is
     * checked on the journal just posted, so the amounts are the converted ones the ledger stores; a
     * mismatch throws and takes the journal with it.
     *
     * @param  list<string>  $accountIds  accounts of the batch
     *
     * @throws ValidationException
     */
    private function controlAccounts(OpeningBalanceImport $command, string $journalId, array $accountIds): void
    {
        $totals = $command->controlTotals ?? [];
        $control = $this->mapper->controlAccounts($command->companyId, $accountIds, $totals);

        if ($control === []) {
            return;
        }

        /** @var array<string, string> $balances */
        $balances = DB::table('gl_journal_lines')
            ->where('company_id', $command->companyId)->where('journal_id', $journalId)->whereIn('account_id', array_keys($control))
            ->groupBy('account_id')
            ->selectRaw('account_id, sum(debit - credit) as net')
            ->pluck('net', 'account_id')->all();

        $this->mapper->verifyControl($control, $accountIds, $totals, $balances);
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
