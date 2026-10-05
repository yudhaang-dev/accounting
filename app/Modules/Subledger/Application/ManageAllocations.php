<?php

namespace App\Modules\Subledger\Application;

use App\Modules\GeneralLedger\Contracts\JournalSource;
use App\Modules\GeneralLedger\Contracts\PostingService;
use App\Modules\GeneralLedger\Contracts\PostJournal;
use App\Modules\MasterData\Contracts\CompanyDirectory;
use App\Modules\MasterData\Contracts\Currencies;
use App\Modules\MasterData\Contracts\CurrencyRounding;
use App\Modules\Subledger\Contracts\ControlAccounts;
use App\Modules\Subledger\Domain\Allocation;
use App\Modules\Subledger\Domain\OpenItem;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Allocations asked for on documents that are already posted, such as applying an advance to an
 * invoice, and the cancellation of any allocation (SL-FR-05). Open items recorded at different rates
 * settle at different functional values, and the difference is booked to the realized exchange
 * accounts in a journal of its own: with the allocation, and with its cancellation the other way round
 * (SL-FR-06). Without a difference there is no journal.
 */
final class ManageAllocations
{
    public const TRANSACTION_TYPE_KEY = 'system.fx_realized';

    public function __construct(
        private readonly OpenItemAllocator $allocator,
        private readonly Audit $audit,
        private readonly RealizedDifference $differences,
        private readonly ControlAccounts $controlAccounts,
        private readonly PostingService $posting,
        private readonly CompanyDirectory $companies,
        private readonly Currencies $currencies,
    ) {}

    /**
     * Settles `$amount` of two open items against each other, whichever of them is the debit side.
     *
     * @param  string  $date  `YYYY-MM-DD`; not before the date of either document
     *
     * @throws ValidationException
     */
    public function allocate(string $itemId, string $otherId, string $amount, string $date, int $userId): Allocation
    {
        if ($itemId === $otherId) {
            throw ValidationException::withMessages(['counterpart_open_item_id' => [__('documents.allocation_same_item')]]);
        }

        return DB::transaction(function () use ($itemId, $otherId, $amount, $date, $userId): Allocation {
            // Locked in the order of their IDs, as concurrent allocations of the same items lock alike.
            $ids = [$itemId, $otherId];
            sort($ids);
            $found = OpenItem::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $one = $found->get($itemId);
            $other = $found->get($otherId);
            $value = BigDecimal::of($amount);

            $problems = [];

            if ($one === null) {
                $problems['open_item_id'] = [__('documents.allocation_item_unknown')];
            }

            if ($other === null) {
                $problems['counterpart_open_item_id'] = [__('documents.allocation_item_unknown')];
            }

            if ($one === null || $other === null) {
                throw ValidationException::withMessages($problems);
            }

            $errors = [
                ...$this->allocator->problems($other, $one->party_id, $one->control_role, $one->side, $one->currency, $value),
                ...$this->allocator->problems($one, $one->party_id, $one->control_role, $other->side, $one->currency, $value),
            ];

            if ($date < $one->document_date->toDateString() || $date < $other->document_date->toDateString()) {
                $errors[] = __('documents.allocation_before_document');
            }

            if ($errors !== []) {
                throw ValidationException::withMessages(['amount' => array_values(array_unique($errors))]);
            }

            $companyId = $one->company_id;
            $functional = $this->companies->find($companyId)->functionalCurrency ?? '';
            $scale = $this->currencies->functionalScale($companyId);
            $mode = ($this->currencies->find($companyId, $functional)->rounding ?? CurrencyRounding::HalfUp)->brick();
            $oneParts = $this->allocator->functionalPart($one, $value, $scale, $mode);
            $otherParts = $this->allocator->functionalPart($other, $value, $scale, $mode);
            [$debitPart, $creditPart] = $one->side === 'debit' ? [$oneParts, $otherParts] : [$otherParts, $oneParts];

            $journalId = null;
            $difference = $creditPart->minus($debitPart);

            if (! $difference->isZero()) {
                $journalId = $this->book($one, $date, $difference, $userId, __('documents.realized_difference_allocation', ['debit' => $one->side === 'debit' ? $one->document_number : $other->document_number, 'credit' => $one->side === 'debit' ? $other->document_number : $one->document_number]));
            }

            return $this->allocator->apply($one, $other, $value, $date, null, $userId, $oneParts, $otherParts, $journalId);
        });
    }

    /**
     * Takes an allocation back: its amounts return to the open items, which open again, and the exchange
     * difference it booked is booked the other way round, dated today. It stays on record with who
     * cancelled it, when and why.
     *
     * @throws ApiException INVALID_STATE_TRANSITION when it is cancelled already
     */
    public function cancel(string $allocationId, string $reason, string $date, int $userId): Allocation
    {
        return DB::transaction(function () use ($allocationId, $reason, $date, $userId): Allocation {
            $allocation = Allocation::query()->lockForUpdate()->findOrFail($allocationId);

            if (! $allocation->isActive()) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $ids = [$allocation->debit_item_id, $allocation->credit_item_id];
            sort($ids);
            $items = OpenItem::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $debit = $items[$allocation->debit_item_id];

            $journalId = $allocation->realized_difference->isZero()
                ? null
                : $this->book($debit, $date, $allocation->realized_difference->negated(), $userId, __('documents.realized_difference_cancelled', ['debit' => $debit->document_number]), $allocation->realized_difference->isPositive());

            $this->allocator->release($allocation, $debit, $items[$allocation->credit_item_id]);

            $allocation->forceFill(['status' => 'cancelled', 'cancelled_on' => $date, 'cancelled_by' => $userId, 'cancel_reason' => $reason, 'cancel_journal_id' => $journalId])->save();

            $this->audit->record(
                'allocation.cancelled',
                "Allocation of {$allocation->amount} cancelled.",
                $allocation,
                before: ['status' => 'active'],
                after: ['status' => 'cancelled'],
                properties: ['reason' => $reason],
                companyId: $allocation->company_id,
            );

            return $allocation->refresh();
        });
    }

    /**
     * Books a realized exchange difference of the open items of an item's party and control account in a
     * journal of its own, and returns the ID of the journal.
     */
    private function book(OpenItem $item, string $date, BigDecimal $difference, int $userId, string $description, ?bool $gainAccount = null): string
    {
        $control = $this->controlAccounts->require($item->company_id, $item->control_role, $item->party_id, $date);
        $lines = $this->differences->lines($item->company_id, $date, $control->accountId, $item->currency, (string) $item->exchange_rate, $difference, $gainAccount);

        return $this->posting->post(new PostJournal(
            companyId: $item->company_id,
            accountingDate: $date,
            description: Str::limit($description, 500, ''),
            source: JournalSource::Subledger,
            lines: $lines,
            transactionTypeKey: self::TRANSACTION_TYPE_KEY,
            entryId: (string) Str::ulid(),
            postedBy: $userId,
        ))->id;
    }
}
