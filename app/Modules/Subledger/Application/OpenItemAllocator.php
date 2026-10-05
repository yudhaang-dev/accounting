<?php

namespace App\Modules\Subledger\Application;

use App\Modules\Subledger\Contracts\ControlRole;
use App\Modules\Subledger\Domain\Allocation;
use App\Modules\Subledger\Domain\OpenItem;
use App\Modules\Subledger\Domain\OpenItemStatus;
use App\Platform\Audit\Audit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Settles open items against each other (SL-FR-05): an amount of a debit side item against a credit side
 * one of the same party, control account and currency, in part or in full, in any combination. An
 * allocation makes no journal; the exchange difference of items recorded at different rates comes with
 * SL-FR-06, so until then both sides must carry the same functional value for the amount.
 */
final class OpenItemAllocator
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * Why an open item cannot be settled by a document or item of the party, control role, side and
     * currency given, for that amount; empty when it can.
     *
     * @param  string  $side  `debit` or `credit`, the side of the document or item that settles it; the open item must be on the other
     * @return list<string>
     */
    public function problems(?OpenItem $item, string $partyId, ControlRole $role, string $side, string $currency, BigDecimal $amount): array
    {
        if ($item === null) {
            return [__('documents.allocation_item_unknown')];
        }

        $number = $item->document_number;
        $problems = [];

        if ($item->status !== OpenItemStatus::Open) {
            $problems[] = __('documents.allocation_item_settled', ['number' => $number]);
        }

        if ($item->party_id !== $partyId || $item->control_role !== $role) {
            $problems[] = __('documents.allocation_item_party', ['number' => $number]);
        }

        if ($item->side === $side) {
            $problems[] = __('documents.allocation_item_side', ['number' => $number]);
        }

        if ($item->currency !== $currency) {
            $problems[] = __('documents.allocation_item_currency', ['number' => $number, 'currency' => $item->currency]);
        }

        if ($amount->isGreaterThan($item->remaining_amount)) {
            $problems[] = __('documents.allocation_item_amount', ['number' => $number, 'remaining' => (string) $item->remaining_amount]);
        }

        return $problems;
    }

    /**
     * Settles `$amount` of both items and records the allocation. The items must be locked and pass
     * `problems()`.
     *
     * @throws ValidationException when the two items carry different functional values for the amount
     */
    public function apply(OpenItem $one, OpenItem $other, BigDecimal $amount, string $date, ?string $documentId, ?int $userId): Allocation
    {
        [$debit, $credit] = $one->side === 'debit' ? [$one, $other] : [$other, $one];

        $debitPart = $this->functionalPart($debit, $amount);
        $creditPart = $this->functionalPart($credit, $amount);

        if (! $debitPart->isEqualTo($creditPart)) {
            throw ValidationException::withMessages(['allocations' => [__('documents.allocation_rates_differ', ['debit' => $debit->document_number, 'credit' => $credit->document_number])]]);
        }

        $this->settle($debit, $amount, $debitPart);
        $this->settle($credit, $amount, $creditPart);

        $allocation = Allocation::create([
            'debit_item_id' => $debit->id,
            'credit_item_id' => $credit->id,
            'document_id' => $documentId,
            'amount' => $amount,
            'functional_amount' => $debitPart,
            'allocation_date' => $date,
            'created_by' => $userId,
        ]);

        $this->audit->record(
            'allocation.created',
            "{$amount} {$debit->currency} of {$debit->document_number} settled against {$credit->document_number}.",
            $allocation,
            after: ['debit_item_id' => $debit->id, 'credit_item_id' => $credit->id, 'amount' => (string) $amount, 'allocation_date' => $date],
            companyId: $allocation->company_id,
        );

        return $allocation;
    }

    /** Gives the amounts of an active allocation back to its open items; both must be locked. */
    public function release(Allocation $allocation, OpenItem $debit, OpenItem $credit): void
    {
        foreach ([$debit, $credit] as $item) {
            $remaining = $item->remaining_amount->plus($allocation->amount);

            $item->forceFill([
                'remaining_amount' => $remaining,
                'remaining_functional' => $item->remaining_functional->plus($allocation->functional_amount),
                'status' => OpenItemStatus::Open,
            ])->save();
        }
    }

    /** The share of what remains of the item, in the functional currency, that `$amount` settles. */
    private function functionalPart(OpenItem $item, BigDecimal $amount): BigDecimal
    {
        if ($amount->isEqualTo($item->remaining_amount)) {
            return $item->remaining_functional;
        }

        return $item->remaining_functional->multipliedBy($amount)->dividedBy($item->remaining_amount, 6, RoundingMode::HalfUp);
    }

    private function settle(OpenItem $item, BigDecimal $amount, BigDecimal $functional): void
    {
        $remaining = $item->remaining_amount->minus($amount);

        $item->forceFill([
            'remaining_amount' => $remaining,
            'remaining_functional' => $item->remaining_functional->minus($functional),
            'status' => $remaining->isZero() ? OpenItemStatus::Settled : OpenItemStatus::Open,
        ])->save();
    }
}
