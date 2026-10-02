<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\CompanyCurrency;
use App\Modules\MasterData\Domain\DimensionStatus;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates or reactivates a currency of the company. The functional currency is always active
 * (MD-FR-06). A currency is never deleted.
 */
final class SetCurrencyStatus
{
    public function __construct(
        private readonly CurrencyRules $rules,
        private readonly Audit $audit,
    ) {}

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function deactivate(string $code): CompanyCurrency
    {
        if ($this->rules->isFunctional($code)) {
            throw new ApiException(ErrorCode::InvalidStateTransition, __('currencies.functional_always_active'));
        }

        return $this->change($code, DimensionStatus::Active, DimensionStatus::Inactive, 'currency.deactivated');
    }

    /** @throws ApiException INVALID_STATE_TRANSITION */
    public function reactivate(string $code): CompanyCurrency
    {
        return $this->change($code, DimensionStatus::Inactive, DimensionStatus::Active, 'currency.reactivated');
    }

    private function change(string $code, DimensionStatus $from, DimensionStatus $to, string $event): CompanyCurrency
    {
        return DB::transaction(function () use ($code, $from, $to, $event): CompanyCurrency {
            $currency = CompanyCurrency::query()->where('currency', $code)->lockForUpdate()->firstOrFail();

            if ($currency->status !== $from) {
                throw new ApiException(ErrorCode::InvalidStateTransition);
            }

            $currency->status = $to;
            $currency->save();

            $this->audit->record(
                $event,
                "Currency {$code} changed from {$from->value} to {$to->value}.",
                $currency,
                before: ['status' => $from->value],
                after: ['status' => $to->value],
                companyId: $currency->company_id,
            );

            return $currency;
        });
    }
}
