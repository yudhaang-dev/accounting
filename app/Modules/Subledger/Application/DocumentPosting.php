<?php

namespace App\Modules\Subledger\Application;

use App\Modules\GeneralLedger\Contracts\AccountDirectory;
use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostedJournal;
use App\Modules\GeneralLedger\Contracts\PostedJournalLine;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostingValidator;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\GeneralLedger\Contracts\PostJournalLine;
use App\Modules\GeneralLedger\Contracts\ReverseJournal;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\MasterData\Contracts\CurrencyRounding;
use App\Modules\MasterData\Contracts\ExchangeRates;
use App\Modules\Subledger\Contracts\CashAccountData;
use App\Modules\Subledger\Contracts\CashAccountDirectory;
use App\Modules\Subledger\Contracts\CashTransfer;
use App\Modules\Subledger\Contracts\ControlAccount;
use App\Modules\Subledger\Contracts\ControlAccounts;
use App\Modules\Subledger\Contracts\DocumentKind;
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
        private readonly OpenItemAllocator $allocator,
        private readonly RealizedDifference $differences,
        private readonly Currencies $currencies,
        private readonly ExchangeRates $rates,
        private readonly CashTransferPosting $transfers,
        private readonly DocumentReversals $reversals,
    ) {}

    public function validate(SubledgerDocument $document): void
    {
        $this->company->run($document->journal->companyId, function () use ($document): void {
            $checked = $this->check($document);
            $this->validator->validate($this->withLines($document->journal, $this->settlement($document, $checked['control'], lock: false)['lines']));
        });
    }

    public function validateTransfer(CashTransfer $transfer): void
    {
        $this->transfers->validate($transfer);
    }

    public function postTransfer(CashTransfer $transfer): PostedJournal
    {
        return $this->transfers->post($transfer);
    }

    public function validateReversal(ReverseJournal $reversal): void
    {
        $this->reversals->validate($reversal);
    }

    public function reverse(ReverseJournal $reversal): PostedJournal
    {
        return $this->reversals->reverse($reversal);
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
            $plan = $this->settlement($document, $checked['control'], lock: true);
            $journal = $this->posting->post($this->withLines($journalCommand, $plan['lines']));

            if ($journal->replayed && ($recorded = Document::query()->where('journal_id', $journal->id)->first()) !== null) {
                return new PostedDocument($recorded->id, $journal);
            }

            return new PostedDocument($this->record($document, $checked['control'], $checked['cash'], $journal, $plan['steps'])->id, $journal);
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

        if (($errors = $this->allocationErrors($document, $control)) !== []) {
            throw ValidationException::withMessages(['allocations' => $errors]);
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
     * Writes the document, its open item and, for cash, the cash transaction from what the ledger stored,
     * then settles what the document names with the steps of the plan.
     *
     * @param  list<array{item: string, amount: BigDecimal, own: BigDecimal|null, target: BigDecimal|null}>  $steps
     */
    private function record(SubledgerDocument $document, ControlAccount $control, ?CashAccountData $cash, PostedJournal $journal, array $steps): Document
    {
        $command = $document->journal;
        $side = $document->kind->controlSide($document->role);
        $lines = array_values(array_filter($journal->lines, fn (PostedJournalLine $line): bool => $line->accountId === $control->accountId && BigDecimal::of($line->transactionAmount)->isPositive()));
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

        $own = OpenItem::create([
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

        foreach ($steps as $step) {
            $this->allocator->apply($own, OpenItem::query()->whereKey($step['item'])->firstOrFail(), $step['amount'], $command->accountingDate, $recorded->id, $command->postedBy, $step['own'], $step['target']);
        }

        return $recorded;
    }

    /**
     * What the document says it settles must be settled by it (SL-FR-05): the open items exist, are open,
     * belong to the party and control account on the other side and in the same currency, have enough
     * left, and together do not exceed the document. A write-off settles in full.
     *
     * @return list<string>
     */
    private function allocationErrors(SubledgerDocument $document, ControlAccount $control, bool $lock = false): array
    {
        $kind = $document->kind;

        if ($document->allocations === []) {
            return $kind === DocumentKind::WriteOff ? [__('documents.allocations_required', ['kind' => $kind->value])] : [];
        }

        if (! $kind->allocates()) {
            return [__('documents.allocations_not_allowed', ['kind' => $kind->value])];
        }

        $functional = $this->companies->find($document->journal->companyId)->functionalCurrency ?? '';
        $total = BigDecimal::zero();
        $currency = $functional;

        foreach ($document->journal->lines as $line) {
            if ($line->accountId === $control->accountId) {
                $total = $total->plus($line->debit)->plus($line->credit);
                $currency = $line->currency ?? $functional;
            }
        }

        $side = $kind->controlSide($document->role);
        $errors = [];
        $sum = BigDecimal::zero();
        $seen = [];

        foreach ($document->allocations as $allocation) {
            $amount = BigDecimal::of($allocation['amount']);

            if (isset($seen[$allocation['open_item_id']])) {
                $errors[] = __('documents.allocation_repeated');

                continue;
            }

            $seen[$allocation['open_item_id']] = true;
            $item = OpenItem::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($allocation['open_item_id']);

            if (! $amount->isPositive()) {
                $errors[] = __('documents.allocation_amount_positive');
            }

            array_push($errors, ...$this->allocator->problems($item, $document->partyId, $document->role, $side, $currency, $amount));
            $sum = $sum->plus($amount);
        }

        if ($sum->isGreaterThan($total)) {
            $errors[] = __('documents.allocation_exceeds_document', ['total' => (string) $total]);
        } elseif ($kind === DocumentKind::WriteOff && ! $sum->isEqualTo($total)) {
            $errors[] = __('documents.allocations_required', ['kind' => $kind->value]);
        }

        return array_values(array_unique($errors));
    }

    /**
     * What settling the open items the document names does to the journal and to the open items (SL-FR-05,
     * SL-FR-06). In the functional currency nothing differs. In a foreign one the control account line
     * of the document carries the amount at the rate of the document, while each open item it settles
     * carries its own functional value, so the journal gets the lines that book the difference. Each step
     * is the share of the document and of the open item, in the functional currency, that an allocation settles.
     *
     * @param  bool  $lock  lock the open items, as posting does; validating does not
     * @return array{lines: list<PostJournalLine>, steps: list<array{item: string, amount: BigDecimal, own: BigDecimal|null, target: BigDecimal|null}>}
     *
     * @throws ValidationException
     */
    private function settlement(SubledgerDocument $document, ControlAccount $control, bool $lock): array
    {
        if ($document->allocations === []) {
            return ['lines' => [], 'steps' => []];
        }

        // The items were checked with the document; check them again now that they are locked.
        if ($lock && ($errors = $this->allocationErrors($document, $control, lock: true)) !== []) {
            throw ValidationException::withMessages(['allocations' => $errors]);
        }

        $command = $document->journal;
        $companyId = $command->companyId;
        $functional = $this->companies->find($companyId)->functionalCurrency ?? '';
        $allocations = $document->allocations;
        usort($allocations, fn (array $a, array $b): int => strcmp($a['open_item_id'], $b['open_item_id']));

        $controlLines = array_values(array_filter($command->lines, fn (PostJournalLine $line): bool => $line->accountId === $control->accountId));
        $currency = $controlLines[0]->currency ?? $functional;

        if ($currency === $functional) {
            return ['lines' => [], 'steps' => array_map(fn (array $allocation): array => ['item' => $allocation['open_item_id'], 'amount' => BigDecimal::of($allocation['amount']), 'own' => null, 'target' => null], $allocations)];
        }

        $scale = $this->currencies->functionalScale($companyId);
        $mode = ($this->currencies->find($companyId, $functional)->rounding ?? CurrencyRounding::HalfUp)->brick();
        $rate = $controlLines[0]->exchangeRate ?? $this->rates->find($companyId, (string) $controlLines[0]->rateTypeKey, $currency, $command->accountingDate)->rate;
        $remaining = BigDecimal::zero();
        $remainingFunctional = BigDecimal::zero();

        foreach ($controlLines as $line) {
            $lineAmount = BigDecimal::of($line->debit)->plus($line->credit);
            $lineRate = $line->exchangeRate ?? $this->rates->find($companyId, (string) $line->rateTypeKey, $currency, $command->accountingDate)->rate;
            $remaining = $remaining->plus($lineAmount);
            $remainingFunctional = $remainingFunctional->plus($lineAmount->multipliedBy($lineRate)->toScale($scale, $mode));
        }

        $ownIsCredit = $document->kind->controlSide($document->role) === 'credit';
        $difference = BigDecimal::zero();
        $steps = [];

        foreach ($allocations as $allocation) {
            $amount = BigDecimal::of($allocation['amount']);
            $target = OpenItem::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($allocation['open_item_id']);

            $own = $amount->isEqualTo($remaining) ? $remainingFunctional : $remainingFunctional->multipliedBy($amount)->dividedBy($remaining, $scale, $mode);
            $theirs = $this->allocator->functionalPart($target, $amount, $scale, $mode);
            $remaining = $remaining->minus($amount);
            $remainingFunctional = $remainingFunctional->minus($own);

            $difference = $difference->plus($ownIsCredit ? $own->minus($theirs) : $theirs->minus($own));
            $steps[] = ['item' => $target->id, 'amount' => $amount, 'own' => $own, 'target' => $theirs];
        }

        return ['lines' => $this->differences->lines($companyId, $command->accountingDate, $control->accountId, $currency, $rate, $difference), 'steps' => $steps];
    }

    /**
     * @param  list<PostJournalLine>  $extra
     */
    private function withLines(PostJournal $command, array $extra): PostJournal
    {
        if ($extra === []) {
            return $command;
        }

        return new PostJournal(
            companyId: $command->companyId,
            accountingDate: $command->accountingDate,
            description: $command->description,
            source: $command->source,
            lines: [...$command->lines, ...$extra],
            documentDate: $command->documentDate,
            transactionTypeKey: $command->transactionTypeKey,
            transactionTypeVersion: $command->transactionTypeVersion,
            references: $command->references,
            entryId: $command->entryId,
            postedBy: $command->postedBy,
            actorPermissions: $command->actorPermissions,
            sourceOverrideReason: $command->sourceOverrideReason,
            openingBalance: $command->openingBalance,
        );
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
