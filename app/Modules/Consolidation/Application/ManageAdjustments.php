<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Consolidation\Domain\Adjustment;
use App\Modules\Consolidation\Domain\AdjustmentLine;
use App\Modules\Consolidation\Domain\Group;
use App\Modules\Consolidation\Domain\GroupAccount;
use App\Modules\Consolidation\Domain\Run;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual adjustments of a consolidation run (CO-FR-08). One is proposed on a run with a source, a reason and
 * balanced lines on active accounts of the group; someone other than the proposer approves or rejects it, once.
 * The run is not changed: its result adds the approved adjustments when it is read (CO-FR-09). Every step is
 * audited in the trail of the group's parent company, and nothing reaches a ledger of an entity (CO-AC-04).
 */
final class ManageAdjustments
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  list<array{group_account_id: string, debit: string, credit: string}>  $lines
     *
     * @throws ValidationException
     */
    public function propose(string $groupId, string $runId, string $source, string $reason, array $lines, int $proposedBy): Adjustment
    {
        $run = Run::query()->where('group_id', $groupId)->findOrFail($runId);
        $accounts = GroupAccount::query()->where('group_id', $groupId)->whereIn('id', array_column($lines, 'group_account_id'))->get()->keyBy('id');
        $errors = [];
        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();

        foreach ($lines as $index => $line) {
            $account = $accounts[$line['group_account_id']] ?? null;
            $amounts = [BigDecimal::of($line['debit']), BigDecimal::of($line['credit'])];

            if ($account === null || ! $account->is_active) {
                $errors["lines.{$index}.group_account_id"] = [__('consolidation.adjustment_account')];
            }

            if ($amounts[0]->isNegative() || $amounts[1]->isNegative() || ($amounts[0]->isPositive() === $amounts[1]->isPositive())) {
                $errors["lines.{$index}.debit"] = [__('consolidation.adjustment_one_side')];
            }

            $debit = $debit->plus($amounts[0]);
            $credit = $credit->plus($amounts[1]);
        }

        if ($errors === [] && ! $debit->isEqualTo($credit)) {
            $errors['lines'] = [__('consolidation.adjustment_unbalanced')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($groupId, $run, $source, $reason, $lines, $accounts, $proposedBy): Adjustment {
            $adjustment = Adjustment::create([
                'group_id' => $groupId,
                'run_id' => $run->id,
                'source' => $source,
                'reason' => $reason,
                'status' => Adjustment::PENDING,
                'proposed_by' => $proposedBy,
            ])->refresh();

            foreach ($lines as $line) {
                $account = $accounts[$line['group_account_id']];

                AdjustmentLine::create([
                    'adjustment_id' => $adjustment->id,
                    'group_account_id' => $account->id,
                    'group_account_code' => $account->code,
                    'group_account_name' => $account->name,
                    'group_account_class' => $account->class,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ]);
            }

            $this->audit($adjustment, 'group_adjustment.proposed', 'Consolidation adjustment proposed.', after: $this->snapshot($adjustment));

            return $adjustment;
        });
    }

    /**
     * @throws ApiException SEGREGATION_OF_DUTIES when the approver proposed it; INVALID_STATE_TRANSITION unless pending
     */
    public function approve(string $groupId, string $id, int $approverId): Adjustment
    {
        return $this->decide($groupId, $id, $approverId, Adjustment::APPROVED, null, 'group_adjustment.approved', 'Consolidation adjustment approved.');
    }

    /**
     * @throws ApiException SEGREGATION_OF_DUTIES when the approver proposed it; INVALID_STATE_TRANSITION unless pending
     */
    public function reject(string $groupId, string $id, int $approverId, string $reason): Adjustment
    {
        return $this->decide($groupId, $id, $approverId, Adjustment::REJECTED, $reason, 'group_adjustment.rejected', 'Consolidation adjustment rejected.');
    }

    private function decide(string $groupId, string $id, int $approverId, string $status, ?string $rejectionReason, string $event, string $description): Adjustment
    {
        return DB::transaction(function () use ($groupId, $id, $approverId, $status, $rejectionReason, $event, $description): Adjustment {
            $adjustment = Adjustment::query()->where('group_id', $groupId)->lockForUpdate()->findOrFail($id);

            if ($adjustment->status !== Adjustment::PENDING) {
                throw new ApiException(ErrorCode::InvalidStateTransition, (string) __('consolidation.adjustment_not_pending'));
            }

            if ($adjustment->proposed_by === $approverId) {
                throw new ApiException(ErrorCode::SegregationOfDuties, (string) __('consolidation.adjustment_approver_must_differ'));
            }

            $before = $this->snapshot($adjustment);
            $adjustment->fill(['status' => $status, 'decided_by' => $approverId, 'decided_at' => now(), 'rejection_reason' => $rejectionReason])->save();

            $this->audit($adjustment, $event, $description, before: $before, after: $this->snapshot($adjustment));

            return $adjustment->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(Adjustment $adjustment): array
    {
        return [
            'run_id' => $adjustment->run_id,
            'source' => $adjustment->source,
            'reason' => $adjustment->reason,
            'status' => $adjustment->status,
            'rejection_reason' => $adjustment->rejection_reason,
            'lines' => $adjustment->lines()->get()->map(fn (AdjustmentLine $line): array => ['group_account_id' => $line->group_account_id, 'debit' => (string) $line->debit, 'credit' => (string) $line->credit])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(Adjustment $adjustment, string $event, string $description, array $before = [], array $after = []): void
    {
        $this->audit->record(
            $event,
            $description,
            $adjustment,
            before: $before,
            after: $after,
            properties: ['group_id' => $adjustment->group_id, 'run_id' => $adjustment->run_id],
            companyId: Group::query()->findOrFail($adjustment->group_id)->parent_company_id,
        );
    }
}
