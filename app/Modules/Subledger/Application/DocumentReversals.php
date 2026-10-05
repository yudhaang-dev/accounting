<?php

namespace App\Modules\Subledger\Application;

use App\Modules\GeneralLedger\Contracts\PostedJournal;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostingValidator;
use App\Modules\GeneralLedger\Contracts\ReverseJournal;
use App\Modules\Subledger\Domain\Allocation;
use App\Modules\Subledger\Domain\CashDirection;
use App\Modules\Subledger\Domain\CashTransaction;
use App\Modules\Subledger\Domain\Document;
use App\Modules\Subledger\Domain\DocumentReversal;
use App\Modules\Subledger\Domain\OpenItem;
use App\Modules\Subledger\Domain\OpenItemStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Reversal of the journal of a document (SL-FR-12). The ledger posts the mirror image of the journal; the
 * subledger takes the document out of the open items and gives back the cash it moved. A document that
 * still has active allocations is refused, so that what was settled against it is not left standing: the
 * allocations have to be cancelled first, each with its own exchange difference.
 */
final class DocumentReversals
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly PostingValidator $validator,
        private readonly ManageAllocations $allocations,
        private readonly CompanyContext $company,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws ApiException NOT_FOUND, INVALID_STATE_TRANSITION (reversed already, not the journal of a document, active allocations), COMPANY_READ_ONLY, PERIOD_NOT_OPEN
     */
    public function validate(ReverseJournal $reversal): void
    {
        $this->company->run($reversal->companyId, function () use ($reversal): void {
            $this->validator->validateReversal($reversal);
            $this->check($reversal);
        });
    }

    /**
     * @throws ApiException as `validate()`
     */
    public function reverse(ReverseJournal $reversal): PostedJournal
    {
        return $this->company->run($reversal->companyId, fn (): PostedJournal => DB::transaction(function () use ($reversal): PostedJournal {
            $document = $this->check($reversal, lock: true);

            // Reversing again with the same key: the ledger gives back the reversal it made.
            if (DocumentReversal::query()->where('document_id', $document->id)->exists()) {
                return $this->posting->reverse($reversal);
            }

            $journal = $this->posting->reverse($reversal);

            OpenItem::query()->where('document_id', $document->id)->firstOrFail()
                ->forceFill(['remaining_amount' => '0', 'remaining_functional' => '0', 'status' => OpenItemStatus::Reversed])->save();

            DocumentReversal::create(['document_id' => $document->id, 'journal_id' => $journal->id, 'reversal_date' => $journal->accountingDate, 'reversed_by' => $reversal->postedBy]);

            $this->returnCash($document, $journal);
            $this->restoreDifferences($document, $journal, $reversal->postedBy);

            $this->audit->record(
                'document.reversed',
                "Document {$document->document_number} reversed in journal {$journal->number}.",
                $document,
                after: ['reversal_journal_id' => $journal->id, 'reversal_date' => $journal->accountingDate],
                companyId: $document->company_id,
            );

            return $journal;
        }));
    }

    /**
     * The document of the journal, once the journal is known to be one that can be reversed.
     *
     * @throws ApiException
     */
    private function check(ReverseJournal $reversal, bool $lock = false): Document
    {
        $document = Document::query()->when($lock, fn ($query) => $query->lockForUpdate())->where('journal_id', $reversal->journalId)->first();

        if ($document === null) {
            $number = (string) DB::table('gl_journals')->where('id', $reversal->journalId)->value('number');

            throw new ApiException(ErrorCode::InvalidStateTransition, errors: ['journal_id' => [__('documents.reversal_not_document', ['number' => $number])]]);
        }

        $item = OpenItem::query()->when($lock, fn ($query) => $query->lockForUpdate())->where('document_id', $document->id)->firstOrFail();

        // A document reversed already has nothing left to check; the ledger tells when the journal was reversed.
        if ($item->status === OpenItemStatus::Reversed) {
            return $document;
        }

        $active = Allocation::query()->where('status', 'active')
            ->where(fn ($query) => $query->where('debit_item_id', $item->id)->orWhere('credit_item_id', $item->id))->exists();

        if ($active) {
            throw new ApiException(ErrorCode::InvalidStateTransition, errors: ['journal_id' => [__('documents.reversal_has_allocations', ['number' => $document->document_number])]]);
        }

        // Without automatic reversal the open item carries the revalued amount, which the ledger holds on the control account.
        $revalued = DB::table('sl_revaluation_items as items')->join('sl_revaluation_runs as runs', 'runs.id', '=', 'items.run_id')
            ->where('items.open_item_id', $item->id)->where('runs.auto_reverse', false)->exists();

        if ($revalued) {
            throw new ApiException(ErrorCode::InvalidStateTransition, errors: ['journal_id' => [__('documents.reversal_revalued', ['number' => $document->document_number])]]);
        }

        return $document;
    }

    /** The cash the document moved goes back with a transaction of the opposite direction, so the account follows the ledger. */
    private function returnCash(Document $document, PostedJournal $journal): void
    {
        $moved = CashTransaction::query()->where('document_id', $document->id)->whereNull('journal_id')->get();

        foreach ($moved as $transaction) {
            CashTransaction::create([
                'cash_account_id' => $transaction->cash_account_id,
                'direction' => $transaction->direction === CashDirection::In ? CashDirection::Out : CashDirection::In,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'transaction_date' => $journal->accountingDate,
                'reference' => (string) __('documents.reversal_cash_description', ['number' => $document->document_number]),
                'document_id' => $document->id,
                'journal_id' => $journal->id,
            ]);
        }
    }

    /**
     * The exchange difference of an allocation the document made when it was posted is a line of its journal.
     * Cancelling the allocation took it out again, and the reversal takes it out a second time, so it is booked once more.
     */
    private function restoreDifferences(Document $document, PostedJournal $journal, ?int $userId): void
    {
        $cancelled = Allocation::query()->where('document_id', $document->id)->where('status', 'cancelled')->whereNull('journal_id')->get()
            ->filter(fn (Allocation $allocation): bool => ! $allocation->realized_difference->isEqualTo(BigDecimal::zero()));

        foreach ($cancelled as $allocation) {
            $this->allocations->restoreDifference($allocation, $journal->accountingDate, $userId);
        }
    }
}
