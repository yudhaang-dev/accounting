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
use App\Modules\Subledger\Contracts\ControlAccount;
use App\Modules\Subledger\Contracts\ControlAccounts;
use App\Modules\Subledger\Contracts\PartyDirectory;
use App\Modules\Subledger\Contracts\PostedDocument;
use App\Modules\Subledger\Contracts\SubledgerDocument;
use App\Modules\Subledger\Contracts\SubledgerPosting;
use App\Modules\Subledger\Domain\CashAccount;
use App\Modules\Subledger\Domain\CashDirection;
use App\Modules\Subledger\Domain\CashTransaction;
use App\Modules\Subledger\Domain\Document;
use App\Modules\Subledger\Domain\OpenItem;
use App\Modules\Subledger\Domain\PostingProfileAccount;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use App\Support\Company\CompanyScope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Records the documents of entries that have a subledger route and posts their journal (SL-FR-04). An
 * invoice raises the balance of a control account and every other kind lowers it, so the document
 * leaves an open item of its own for what it posted to the control account; a payment or an advance
 * also records the cash it moved. Settling open items by allocation comes with SL-FR-05.
 *
 * The control account line of the journal is the one to the control account of the party's posting
 * profile, and the cash line the one to the ledger account of the cash account. Those accounts take
 * the source `subledger` only (GL-FR-02), so no other posting can reach them and the open items stay
 * equal to the ledger (SL-FR-09).
 */
final class DocumentPosting implements SubledgerPosting
{
    public function __construct(
        private readonly PartyDirectory $parties,
        private readonly CashAccountDirectory $cashAccounts,
        private readonly ControlAccounts $controlAccounts,
        private readonly PostingValidator $validator,
        private readonly PostingService $posting,
        private readonly CompanyDirectory $companies,
        private readonly AccountDirectory $accounts,
        private readonly CompanyContext $company,
        private readonly Audit $audit,
    ) {}

    public function validate(SubledgerDocument $document): void
    {
        $this->company->run($document->journal->companyId, function () use ($document): void {
            $this->check($document);
            $this->validator->validate($document->journal);
        });
    }

    public function post(SubledgerDocument $document): PostedDocument
    {
        $journalCommand = $document->journal;

        return $this->company->run($journalCommand->companyId, fn (): PostedDocument => DB::transaction(function () use ($document, $journalCommand): PostedDocument {
            $existing = $journalCommand->entryId === null ? null : Document::query()->where('entry_id', $journalCommand->entryId)->first();

            // The entry was posted before: the ledger gives back its journal.
            if ($existing !== null) {
                return new PostedDocument($existing->id, $this->posting->post($journalCommand));
            }

            $checked = $this->check($document);
            $journal = $this->posting->post($journalCommand);

            if ($journal->replayed && ($recorded = Document::query()->where('journal_id', $journal->id)->first()) !== null) {
                return new PostedDocument($recorded->id, $journal);
            }

            return new PostedDocument($this->record($document, $checked['control'], $checked['cash'], $journal)->id, $journal);
        }));
    }

    /**
     * What the subledger asks of the document before the ledger looks at the journal.
     *
     * @return array{control: ControlAccount, cash: CashAccountData|null}
     *
     * @throws ValidationException
     */
    private function check(SubledgerDocument $document): array
    {
        $command = $document->journal;

        if ($command->source !== JournalSource::Subledger) {
            throw new LogicException('The journal of a subledger document is posted from the source subledger.');
        }

        $companyId = $command->companyId;
        $errors = [];

        $party = $this->parties->find($companyId, $document->partyId);

        if ($party === null) {
            $errors['party_id'][] = __('documents.party_unknown');
        } elseif (! $party->isActive) {
            $errors['party_id'][] = __('documents.party_inactive', ['name' => $party->name]);
        } elseif (! ($document->role->value === 'receivables_control' ? $party->isCustomer : $party->isSupplier)) {
            $errors['party_id'][] = __($document->role->value === 'receivables_control' ? 'documents.party_not_customer' : 'documents.party_not_supplier', ['name' => $party->name]);
        }

        $cash = null;

        if ($document->kind->movesCash()) {
            $cash = $document->cashAccountId === null ? null : $this->cashAccounts->find($companyId, $document->cashAccountId);

            if ($document->cashAccountId === null) {
                $errors['cash_account_id'][] = __('documents.cash_account_required', ['kind' => $document->kind->value]);
            } elseif ($cash === null) {
                $errors['cash_account_id'][] = __('documents.cash_account_unknown');
            } elseif (! $cash->isActive) {
                $errors['cash_account_id'][] = __('documents.cash_account_inactive', ['name' => $cash->name]);
            }
        } elseif ($document->cashAccountId !== null) {
            $errors['cash_account_id'][] = __('documents.cash_account_not_allowed', ['kind' => $document->kind->value]);
        }

        $date = $command->documentDate ?? $command->accountingDate;

        if ($document->dueDate !== null && $document->dueDate < $date) {
            $errors['due_date'][] = __('documents.due_before_document');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $control = $this->controlAccounts->require($companyId, $document->role, $document->partyId, $command->accountingDate);
        $errors = $this->lineErrors($document, $control, $cash);

        if ($errors !== []) {
            throw ValidationException::withMessages(['lines' => $errors]);
        }

        return ['control' => $control, 'cash' => $cash];
    }

    /**
     * The journal must post what the document says: the control account on the side its kind needs, the
     * cash account on the other side and in the currency of the account, and nothing to the control
     * account of another profile or to the account of another cash account, which would leave a
     * balance without a rincian.
     *
     * @return list<string>
     */
    private function lineErrors(SubledgerDocument $document, ControlAccount $control, ?CashAccountData $cash): array
    {
        $command = $document->journal;
        $functional = $this->companies->find($command->companyId)->functionalCurrency ?? '';
        $expected = $document->kind->controlSide($document->role);
        $errors = [];
        $controlCurrencies = [];
        $controlLines = 0;
        $cashLines = 0;

        foreach ($command->lines as $line) {
            $side = BigDecimal::of($line->debit)->isPositive() ? 'debit' : 'credit';
            $currency = $line->currency ?? $functional;

            if ($line->accountId === $control->accountId) {
                $controlLines++;
                $controlCurrencies[$currency] = true;

                if ($side !== $expected) {
                    $errors[] = __('documents.control_line_side', ['kind' => $document->kind->value, 'side' => $expected]);
                }
            } elseif ($cash !== null && $line->accountId === $cash->accountId) {
                $cashLines++;

                if ($side === $expected) {
                    $errors[] = __('documents.cash_line_side', ['kind' => $document->kind->value, 'side' => $expected === 'debit' ? 'credit' : 'debit']);
                }

                if ($currency !== $cash->currency) {
                    $errors[] = __('documents.cash_line_currency', ['name' => $cash->name, 'currency' => $cash->currency]);
                }
            }
        }

        if ($controlLines === 0) {
            $errors[] = __('documents.control_line_missing', ['kind' => $document->kind->value]);
        }

        if (count($controlCurrencies) > 1) {
            $errors[] = __('documents.control_currency_mixed');
        }

        if ($cash !== null && $cashLines === 0) {
            $errors[] = __('documents.cash_line_missing', ['name' => $cash->name]);
        }

        return array_values(array_unique([...$errors, ...$this->strayLines($document, $control, $cash)]));
    }

    /**
     * @return list<string>
     */
    private function strayLines(SubledgerDocument $document, ControlAccount $control, ?CashAccountData $cash): array
    {
        $companyId = $document->journal->companyId;
        $accountIds = array_values(array_unique(array_map(fn (PostJournalLine $line): string => $line->accountId, $document->journal->lines)));

        $controls = PostingProfileAccount::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->whereIn('account_id', $accountIds)->pluck('account_id')->all();
        $cashes = CashAccount::query()->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->whereIn('account_id', $accountIds)->pluck('account_id')->all();

        $errors = [];

        foreach (array_diff($controls, [$control->accountId]) as $accountId) {
            $errors[] = __('documents.stray_control_line', ['account' => $this->accounts->find($companyId, $accountId)->code ?? $accountId]);
        }

        foreach (array_diff($cashes, [$cash->accountId ?? '']) as $accountId) {
            $errors[] = __('documents.stray_cash_line', ['account' => $this->accounts->find($companyId, $accountId)->code ?? $accountId]);
        }

        return $errors;
    }

    /**
     * Writes the document, its open item and, for cash, the cash transaction from what the ledger stored.
     */
    private function record(SubledgerDocument $document, ControlAccount $control, ?CashAccountData $cash, PostedJournal $journal): Document
    {
        $command = $document->journal;
        $side = $document->kind->controlSide($document->role);
        $lines = array_values(array_filter($journal->lines, fn (PostedJournalLine $line): bool => $line->accountId === $control->accountId));
        $amount = array_reduce($lines, fn (BigDecimal $sum, PostedJournalLine $line): BigDecimal => $sum->plus($line->transactionAmount), BigDecimal::zero());
        $functional = array_reduce($lines, fn (BigDecimal $sum, PostedJournalLine $line): BigDecimal => $sum->plus($side === 'debit' ? $line->debit : $line->credit), BigDecimal::zero());
        $date = $command->documentDate ?? $command->accountingDate;
        $number = $document->documentNumber ?? $journal->number;

        $recorded = Document::create([
            'kind' => $document->kind,
            'control_role' => $document->role,
            'party_id' => $document->partyId,
            'cash_account_id' => $cash?->id,
            'journal_id' => $journal->id,
            'entry_id' => $command->entryId,
            'document_number' => $number,
            'document_date' => $date,
            'due_date' => $document->dueDate ?? $date,
            'accounting_date' => $command->accountingDate,
            'currency' => $lines[0]->currency,
            'amount' => $amount,
            'functional_amount' => $functional,
            'exchange_rate' => $lines[0]->exchangeRate,
            'description' => $command->description,
            'created_by' => $command->postedBy,
        ]);

        OpenItem::create([
            'document_id' => $recorded->id,
            'party_id' => $document->partyId,
            'control_role' => $document->role,
            'kind' => $document->kind,
            'side' => $side,
            'document_number' => $number,
            'document_date' => $date,
            'due_date' => $document->dueDate ?? $date,
            'currency' => $lines[0]->currency,
            'original_amount' => $amount,
            'remaining_amount' => $amount,
            'original_functional' => $functional,
            'remaining_functional' => $functional,
            'exchange_rate' => $lines[0]->exchangeRate,
        ]);

        if ($cash !== null) {
            $this->recordCash($cash, $journal, $recorded, $number);
        }

        $this->audit->record(
            'document.recorded',
            "Document {$number} ({$document->kind->value}) recorded in journal {$journal->number}.",
            $recorded,
            after: ['kind' => $document->kind->value, 'party_id' => $document->partyId, 'amount' => (string) $amount, 'currency' => $recorded->currency, 'journal_id' => $journal->id],
            companyId: $command->companyId,
        );

        return $recorded;
    }

    private function recordCash(CashAccountData $cash, PostedJournal $journal, Document $document, string $reference): void
    {
        $lines = array_values(array_filter($journal->lines, fn (PostedJournalLine $line): bool => $line->accountId === $cash->accountId));
        $amount = array_reduce($lines, fn (BigDecimal $sum, PostedJournalLine $line): BigDecimal => $sum->plus($line->transactionAmount), BigDecimal::zero());

        CashTransaction::create([
            'cash_account_id' => $cash->id,
            'direction' => BigDecimal::of($lines[0]->debit)->isPositive() ? CashDirection::In : CashDirection::Out,
            'amount' => $amount,
            'currency' => $cash->currency,
            'transaction_date' => $document->accounting_date->toDateString(),
            'reference' => $reference,
            'document_id' => $document->id,
        ]);
    }
}
