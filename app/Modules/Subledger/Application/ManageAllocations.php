<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Domain\Allocation;
use App\Modules\Subledger\Domain\OpenItem;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Allocations asked for on documents that are already posted, such as applying an advance to an
 * invoice, and the cancellation of any allocation (SL-FR-05). Neither makes a journal.
 */
final class ManageAllocations
{
    public function __construct(
        private readonly OpenItemAllocator $allocator,
        private readonly Audit $audit,
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

            return $this->allocator->apply($one, $other, $value, $date, null, $userId);
        });
    }

    /**
     * Takes an allocation back: its amounts return to the open items, which open again. It stays on
     * record with who cancelled it, when and why.
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

            $this->allocator->release($allocation, $items[$allocation->debit_item_id], $items[$allocation->credit_item_id]);

            $allocation->forceFill(['status' => 'cancelled', 'cancelled_on' => $date, 'cancelled_by' => $userId, 'cancel_reason' => $reason])->save();

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
}
