<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\PostedJournal;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Domain\Journal;
use App\Modules\GeneralLedger\Domain\JournalLine;
use App\Platform\Numbering\Numbering;
use App\Platform\Numbering\NumberSeriesNotFound;
use App\Platform\Numbering\ResetPeriod;
use App\Support\Company\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Posts one balanced journal atomically (GL-FR-05): lock on the entry, replay when it was posted
 * before, validate, number, store, update balances. Everything happens in one transaction, which
 * is a savepoint when the caller already has one, so a failure leaves no journal, balance or number.
 */
final class EloquentPostingService implements PostingService
{
    public const NUMBER_SERIES = 'journal';

    public function __construct(
        private readonly EloquentPostingValidator $validator,
        private readonly AccountBalances $balances,
        private readonly Numbering $numbering,
        private readonly CompanyContext $company,
    ) {}

    public function post(PostJournal $command): PostedJournal
    {
        return $this->company->run($command->companyId, fn (): PostedJournal => DB::transaction(function () use ($command): PostedJournal {
            if ($command->entryId !== null) {
                // Serialises concurrent postings of the same entry; the unique index is the backstop.
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["{$command->companyId}:{$command->entryId}"]);

                $existing = Journal::query()->where('entry_id', $command->entryId)->first();

                if ($existing !== null) {
                    return new PostedJournal($existing->id, $existing->number, $existing->accounting_date->toDateString(), $existing->period_id, replayed: true);
                }
            }

            $resolved = $this->validator->resolve($command);
            $date = CarbonImmutable::parse($command->accountingDate);

            $journal = Journal::create([
                'number' => $this->nextNumber($command->companyId, $date),
                'accounting_date' => $command->accountingDate,
                'period_id' => $resolved->periodId,
                'document_date' => $command->documentDate,
                'description' => $command->description,
                'source' => $command->source->value,
                'transaction_type_key' => $command->transactionTypeKey,
                'transaction_type_version' => $command->transactionTypeVersion,
                'source_references' => $command->references,
                'entry_id' => $command->entryId,
                'posted_by' => $command->postedBy,
                'posted_at' => now(),
            ]);

            $movements = [];

            foreach ($resolved->lines as $index => $line) {
                $amount = $line['debit']->plus($line['credit']);

                JournalLine::create([
                    'journal_id' => $journal->id,
                    'line_number' => $index + 1,
                    'account_id' => $line['account_id'],
                    'period_id' => $resolved->periodId,
                    'accounting_date' => $command->accountingDate,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'currency' => $resolved->currency,
                    'transaction_amount' => $amount,
                    'exchange_rate' => 1,
                    'dimension_set_id' => $line['dimension_set_id'],
                    'description' => $line['description'],
                ]);

                $movements[] = [
                    'account_id' => $line['account_id'],
                    'period_id' => $resolved->periodId,
                    'dimension_set_id' => $line['dimension_set_id'],
                    'currency' => $resolved->currency,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'transaction_debit' => $line['debit'],
                    'transaction_credit' => $line['credit'],
                ];
            }

            $this->balances->apply($command->companyId, $movements);

            return new PostedJournal($journal->id, $journal->number, $command->accountingDate, $resolved->periodId);
        }));
    }

    /**
     * Issues the next journal number, creating the default series of the company on first use.
     */
    private function nextNumber(string $companyId, CarbonImmutable $date): string
    {
        try {
            return $this->numbering->next($companyId, self::NUMBER_SERIES, $date);
        } catch (NumberSeriesNotFound) {
            /** @var array{pattern: string, padding: int, reset: string} $default */
            $default = config('ledger.journal_number');

            $this->numbering->define($companyId, self::NUMBER_SERIES, $default['pattern'], $default['padding'], ResetPeriod::from($default['reset']));

            return $this->numbering->next($companyId, self::NUMBER_SERIES, $date);
        }
    }
}
