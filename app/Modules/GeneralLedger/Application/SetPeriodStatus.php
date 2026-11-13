<?php

namespace App\Modules\GeneralLedger\Application;

use App\Modules\GeneralLedger\Domain\Period;
use App\Modules\GeneralLedger\Domain\PeriodStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use App\Support\Ledger\ClosingListener;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Moves a period between `open`, `soft_closed` and `closed` (GL-FR-04). Reopening needs a reason;
 * every change is audited with the actor and the reason when there is one. Results derived from a
 * closed period are flagged for recalculation when it reopens (GL-FR-14, X-FR-03); closing it tells the
 * {@see ClosingListener}s, which derive report snapshots (RP-FR-14).
 */
final class SetPeriodStatus
{
    /** @var array<string, list<PeriodStatus>> statuses a transition may start from, by action */
    private const FROM = [
        'soft_close' => [PeriodStatus::Open],
        'close' => [PeriodStatus::Open, PeriodStatus::SoftClosed],
        'reopen' => [PeriodStatus::SoftClosed, PeriodStatus::Closed],
    ];

    /** @param  iterable<ClosingListener>  $listeners */
    public function __construct(
        private readonly Audit $audit,
        private readonly iterable $listeners = [],
    ) {}

    /**
     * Keeps the period open to the postings the ledger policy names and refuses all others.
     *
     * @throws ApiException INVALID_STATE_TRANSITION
     */
    public function softClose(string $periodId, ?string $reason = null): Period
    {
        return $this->change($periodId, 'soft_close', PeriodStatus::SoftClosed, 'period.soft_closed', $reason);
    }

    /**
     * @throws ApiException INVALID_STATE_TRANSITION
     */
    public function close(string $periodId, ?string $reason = null): Period
    {
        $period = $this->change($periodId, 'close', PeriodStatus::Closed, 'period.closed', $reason);
        $actorId = Auth::id();

        foreach ($this->listeners as $listener) {
            $listener->periodClosed($period->company_id, $period->id, $period->start_date->toDateString(), $period->end_date->toDateString(), $actorId === null ? null : (int) $actorId);
        }

        return $period;
    }

    /**
     * @throws ApiException INVALID_STATE_TRANSITION
     */
    public function reopen(string $periodId, string $reason): Period
    {
        return $this->change($periodId, 'reopen', PeriodStatus::Open, 'period.reopened', $reason);
    }

    private function change(string $periodId, string $action, PeriodStatus $to, string $event, ?string $reason): Period
    {
        return DB::transaction(function () use ($periodId, $action, $to, $event, $reason): Period {
            // Waits for the postings in flight into the period, and holds back new ones until the status has changed (EloquentPostingValidator::periodFor).
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["gl-period:{$periodId}"]);
            $period = Period::query()->lockForUpdate()->findOrFail($periodId);
            $from = $period->status;

            if (! in_array($from, self::FROM[$action], true)) {
                throw new ApiException(ErrorCode::InvalidStateTransition, errors: [
                    'status' => [__('periods.invalid_transition', ['from' => $from->value, 'to' => $to->value])],
                ]);
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
