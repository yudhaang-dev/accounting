<?php

namespace App\Modules\Subledger\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostedJournal;
use App\Modules\GeneralLedger\Contracts\PostedJournalLine;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostingValidator;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\Subledger\Contracts\CashAccountData;
use App\Modules\Subledger\Contracts\CashAccountDirectory;
use App\Modules\Subledger\Contracts\CashEntry;
use App\Modules\Subledger\Domain\CashAccount;
use App\Modules\Subledger\Domain\CashDirection;
use App\Modules\Subledger\Domain\CashTransaction;
use App\Modules\Subledger\Domain\PostingProfileAccount;
use App\Support\Company\CompanyContext;
use App\Support\Company\CompanyScope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Posts entries that move cash with no party (SL-FR-03, SL-FR-11, ADR-0016). The account of the cash account
 * takes the source `subledger` only, so the cash such an entry moves is recorded here and nowhere else.
 */
final class CashEntryPosting
{
    public function __construct(
        private readonly CashAccountDirectory $cashAccounts,
        private readonly AccountDirectory $accounts,
        private readonly CompanyDirectory $companies,
        private readonly PostingValidator $validator,
        private readonly PostingService $posting,
        private readonly CompanyContext $company,
    ) {}

    public function validate(CashEntry $entry): void
    {
        $this->company->run($entry->journal->companyId, function () use ($entry): void {
            $this->check($entry);
            $this->validator->validate($entry->journal);
        });
    }

    public function post(CashEntry $entry): PostedJournal
    {
        return $this->company->run($entry->journal->companyId, fn (): PostedJournal => DB::transaction(function () use ($entry): PostedJournal {
            [$cash, $direction] = $this->check($entry);
            $journal = $this->posting->post($entry->journal);

            if (! $journal->replayed) {
                $this->record($cash, $direction, $journal, $entry->reference);
            }

            return $journal;
        }));
    }

    /**
     * @return array{CashAccountData, CashDirection}
     *
     * @throws ValidationException
     */
    private function check(CashEntry $entry): array
    {
        $command = $entry->journal;

        if ($command->source !== JournalSource::Subledger) {
            throw new LogicException('The journal of a cash entry is posted from the source subledger.');
        }

        $cash = $this->cashAccounts->find($command->companyId, $entry->cashAccountId);

        if ($cash === null) {
            throw ValidationException::withMessages(['cash_account_id' => [__('documents.cash_account_unknown')]]);
        }

        if (! $cash->isActive) {
            throw ValidationException::withMessages(['cash_account_id' => [__('documents.cash_account_inactive', ['name' => $cash->name])]]);
        }

        [$errors, $direction] = $this->lineErrors($entry, $cash);

        if ($errors !== []) {
            throw ValidationException::withMessages(['lines' => $errors]);
        }

        assert($direction !== null);

        return [$cash, $direction];
    }

    /**
     * The lines to the account of the cash account all stand on one side and in its currency, and the
     * journal touches no control account and no other cash account.
     *
     * @return array{list<string>, CashDirection|null}
     */
    private function lineErrors(CashEntry $entry, CashAccountData $cash): array
    {
        $command = $entry->journal;
        $functional = $this->companies->find($command->companyId)->functionalCurrency ?? '';
        $errors = [];
        $sides = [];

        foreach ($command->lines as $line) {
            if ($line->accountId !== $cash->accountId) {
                continue;
            }

            $sides[BigDecimal::of($line->debit)->isPositive() ? 'debit' : 'credit'] = true;

            if (($line->currency ?? $functional) !== $cash->currency) {
                $errors[] = __('documents.cash_line_currency', ['name' => $cash->name, 'currency' => $cash->currency]);
            }
        }

        if ($sides === []) {
            $errors[] = __('documents.cash_line_missing', ['name' => $cash->name]);
        } elseif (count($sides) > 1) {
            $errors[] = __('documents.cash_entry_line_sides', ['name' => $cash->name]);
        }

        $direction = isset($sides['debit']) ? CashDirection::In : CashDirection::Out;

        return [array_values(array_unique([...$errors, ...$this->strayLines($entry, $cash)])), $sides === [] ? null : $direction];
    }

    /**
     * @return list<string>
     */
    private function strayLines(CashEntry $entry, CashAccountData $cash): array
    {
        $companyId = $entry->journal->companyId;
        $accountIds = array_values(array_unique(array_map(fn (PostJournalLine $line): string => $line->accountId, $entry->journal->lines)));

        $controls = PostingProfileAccount::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->whereIn('account_id', $accountIds)->pluck('account_id')->all();
        $cashes = CashAccount::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->whereIn('account_id', $accountIds)->pluck('account_id')->all();

        $errors = [];

        foreach ($controls as $accountId) {
            $errors[] = __('documents.stray_control_line', ['account' => $this->accounts->find($companyId, $accountId)->code ?? $accountId]);
        }

        foreach (array_diff($cashes, [$cash->accountId]) as $accountId) {
            $errors[] = __('documents.stray_cash_line', ['account' => $this->accounts->find($companyId, $accountId)->code ?? $accountId]);
        }

        return $errors;
    }

    /** Records the cash of the lines to the account of `$cash`, in the currency of the account. */
    private function record(CashAccountData $cash, CashDirection $direction, PostedJournal $journal, ?string $reference): void
    {
        $amount = array_reduce(
            array_filter($journal->lines, fn (PostedJournalLine $line): bool => $line->accountId === $cash->accountId),
            fn (BigDecimal $sum, PostedJournalLine $line): BigDecimal => $sum->plus($line->transactionAmount),
            BigDecimal::zero(),
        );

        CashTransaction::create([
            'cash_account_id' => $cash->id,
            'direction' => $direction,
            'amount' => $amount,
            'currency' => $cash->currency,
            'transaction_date' => $journal->accountingDate,
            'reference' => $reference !== null && $reference !== '' ? $reference : $journal->number,
            'journal_id' => $journal->id,
        ]);
    }
}
