<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Domain\Period;
use App\Modules\GeneralLedger\Domain\PeriodStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Closes and reopens a period (GL-FR-04). Reopening needs a reason; both are audited with the actor.
 */
final class SetPeriodStatus
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @throws ApiException INVALID_STATE_TRANSITION
     */
    public function close(string $periodId): Period
    {
        return $this->change($periodId, PeriodStatus::Open, PeriodStatus::Closed, 'period.closed', null);
    }

    /**
     * @throws ApiException INVALID_STATE_TRANSITION
     */
    public function reopen(string $periodId, string $reason): Period
    {
        return $this->change($periodId, PeriodStatus::Closed, PeriodStatus::Open, 'period.reopened', $reason);
    }

    private function change(string $periodId, PeriodStatus $from, PeriodStatus $to, string $event, ?string $reason): Period
    {
        return DB::transaction(function () use ($periodId, $from, $to, $event, $reason): Period {
            $period = Period::query()->lockForUpdate()->findOrFail($periodId);

            if ($period->status !== $from) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $period->status = $to;
            $period->save();

            $this->audit->record(
                $event,
                "Period {$period->name} changed from {$from->value} to {$to->value}.",
                $period,
                before: ['status' => $from->value],
                after: ['status' => $to->value],
                properties: $reason === null ? [] : ['reason' => $reason],
                companyId: $period->company_id,
            );

            return $period;
        });
    }
}
