<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Contracts\PostedJournal;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\ReverseJournal;
use App\Modules\GeneralLedger\Domain\Journal;
use App\Modules\GeneralLedger\Domain\JournalLine;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Platform\Numbering\Numbering;
use App\Platform\Numbering\NumberSeriesNotFound;
use App\Platform\Numbering\ResetPeriod;
use App\Support\Company\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        private readonly Audit $audit,
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
                'source_override_reason' => $resolved->sourceOverrideReason,
                'posted_at' => now(),
            ]);

            $movements = [];

            foreach ($resolved->lines as $index => $line) {
                $onDebit = ! $line['debit']->isZero();

                JournalLine::create([
                    'journal_id' => $journal->id,
                    'line_number' => $index + 1,
                    'account_id' => $line['account_id'],
                    'period_id' => $resolved->periodId,
                    'accounting_date' => $command->accountingDate,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'currency' => $line['currency'],
                    'transaction_amount' => $line['transaction_amount'],
                    'exchange_rate' => $line['exchange_rate'],
                    'rate_type_id' => $line['rate_type_id'],
                    'rate_date' => $line['rate_date'],
                    'dimension_set_id' => $line['dimension_set_id'],
                    'description' => $line['description'],
                ]);

                $movements[] = [
                    'account_id' => $line['account_id'],
                    'period_id' => $resolved->periodId,
                    'dimension_set_id' => $line['dimension_set_id'],
                    'currency' => $line['currency'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'transaction_debit' => $onDebit ? $line['transaction_amount'] : BigDecimal::zero(),
                    'transaction_credit' => $onDebit ? BigDecimal::zero() : $line['transaction_amount'],
                ];
            }

            $this->balances->apply($command->companyId, $movements);
            $this->auditOverride($journal, $resolved);

            return new PostedJournal($journal->id, $journal->number, $command->accountingDate, $resolved->periodId);
        }));
    }

    private function auditOverride(Journal $journal, ResolvedPosting $resolved): void
    {
        if ($resolved->sourceOverrideReason === null) {
            return;
        }

        $this->audit->record(
            'journal.source_override',
            "Journal {$journal->number} posted to ".implode(', ', $resolved->overriddenAccounts)." from the source {$journal->source}, outside the sources the accounts accept.",
            $journal,
            properties: ['reason' => $resolved->sourceOverrideReason, 'accounts' => $resolved->overriddenAccounts, 'source' => $journal->source],
            companyId: $journal->company_id,
        );
    }

    public function reverse(ReverseJournal $command): PostedJournal
    {
        return $this->company->run($command->companyId, fn (): PostedJournal => DB::transaction(function () use ($command): PostedJournal {
            $this->validator->company($command->companyId);

            // Serialises concurrent reversals of the same journal; the unique index is the backstop.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["{$command->companyId}:reverse:{$command->journalId}"]);

            $original = Journal::query()->with('lines')->find($command->journalId) ?? throw new ApiException(ErrorCode::NotFound);
            $existing = Journal::query()->where('reverses_journal_id', $original->id)->first();

            if ($existing !== null) {
                if ($command->entryId !== null && $existing->entry_id === $command->entryId) {
                    return new PostedJournal($existing->id, $existing->number, $existing->accounting_date->toDateString(), $existing->period_id, replayed: true);
                }

                throw new ApiException(ErrorCode::InvalidStateTransition, errors: [
                    'journal_id' => [__('ledger.already_reversed', ['number' => $original->number, 'reversal' => $existing->number])],
                ]);
            }

            if (! $this->isDate($command->accountingDate)) {
                throw ValidationException::withMessages([
                    'accounting_date' => [__('validation.date_format', ['attribute' => 'accounting_date', 'format' => 'Y-m-d'])],
                ]);
            }

            $periodId = $this->validator->periodFor($command->companyId, $command->accountingDate, $original->transaction_type_key, $command->actorPermissions);

            $reversal = Journal::create([
                'number' => $this->nextNumber($command->companyId, CarbonImmutable::parse($command->accountingDate)),
                'accounting_date' => $command->accountingDate,
                'period_id' => $periodId,
                'description' => $command->description ?? __('ledger.reversal_of', ['number' => $original->number]),
                'source' => $original->source,
                'transaction_type_key' => $original->transaction_type_key,
                'transaction_type_version' => $original->transaction_type_version,
                'reverses_journal_id' => $original->id,
                'entry_id' => $command->entryId,
                'posted_by' => $command->postedBy,
                'posted_at' => now(),
            ]);

            $movements = [];

            foreach ($original->lines as $line) {
                // Same account, dimension set, currency, transaction amount and rate; only the side flips.
                $debit = $line->credit;
                $credit = $line->debit;

                JournalLine::create([
                    'journal_id' => $reversal->id,
                    'line_number' => $line->line_number,
                    'account_id' => $line->account_id,
                    'period_id' => $periodId,
                    'accounting_date' => $command->accountingDate,
                    'debit' => $debit,
                    'credit' => $credit,
                    'currency' => $line->currency,
                    'transaction_amount' => $line->transaction_amount,
                    'exchange_rate' => $line->exchange_rate,
                    'rate_type_id' => $line->rate_type_id,
                    'rate_date' => $line->rate_date,
                    'dimension_set_id' => $line->dimension_set_id,
                    'description' => $line->description,
                ]);

                $movements[] = [
                    'account_id' => $line->account_id,
                    'period_id' => $periodId,
                    'dimension_set_id' => $line->dimension_set_id,
                    'currency' => $line->currency,
                    'debit' => $debit,
                    'credit' => $credit,
                    'transaction_debit' => $line->debit->isZero() ? $line->transaction_amount : BigDecimal::zero(),
                    'transaction_credit' => $line->debit->isZero() ? BigDecimal::zero() : $line->transaction_amount,
                ];
            }

            $this->balances->apply($command->companyId, $movements);

            return new PostedJournal($reversal->id, $reversal->number, $command->accountingDate, $periodId);
        }));
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
