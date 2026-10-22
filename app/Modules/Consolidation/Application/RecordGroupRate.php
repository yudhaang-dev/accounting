<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Consolidation\Domain\Group;
use App\Modules\Consolidation\Domain\GroupRate;
use App\Modules\Consolidation\Domain\RateType;
use App\Platform\Audit\Audit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records the rate of a currency for a rate type of a group and an effective date (CO-FR-06). Rates are never
 * changed: a different rate for the same type, currency and date adds a new version (a correction), and the
 * same rate again changes nothing.
 */
final class RecordGroupRate
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @return array{rate: GroupRate, created: bool} `created` is false when the latest version already has this rate
     *
     * @throws ValidationException
     */
    public function __invoke(string $groupId, string $rateTypeId, string $currency, string $effectiveDate, string $rate, string $source, ?int $userId): array
    {
        return DB::transaction(function () use ($groupId, $rateTypeId, $currency, $effectiveDate, $rate, $source, $userId): array {
            $group = Group::query()->lockForUpdate()->findOrFail($groupId);
            $type = RateType::query()->where('group_id', $groupId)->find($rateTypeId)
                ?? throw ValidationException::withMessages(['rate_type_id' => [__('consolidation.policy_rate_type_unknown')]]);

            if (! $type->is_active) {
                throw ValidationException::withMessages(['rate_type_id' => [__('consolidation.rate_type_inactive')]]);
            }

            if ($currency === $group->presentation_currency) {
                throw ValidationException::withMessages(['currency' => [__('consolidation.rate_presentation_currency', ['code' => $currency])]]);
            }

            $latest = GroupRate::query()->where('rate_type_id', $type->id)->where('currency', $currency)->whereDate('effective_date', $effectiveDate)->orderByDesc('version')->first();
            $decimal = BigDecimal::of($rate);

            if ($latest !== null && $latest->rate->isEqualTo($decimal)) {
                return ['rate' => $latest, 'created' => false];
            }

            $created = GroupRate::create([
                'group_id' => $groupId,
                'rate_type_id' => $type->id,
                'currency' => $currency,
                'effective_date' => $effectiveDate,
                'rate' => $decimal,
                'version' => $latest === null ? 1 : $latest->version + 1,
                'source' => $source,
                'created_by' => $userId,
            ])->refresh();

            $this->audit->record(
                $latest === null ? 'group_rate.recorded' : 'group_rate.corrected',
                "Group rate {$type->code} {$currency} {$effectiveDate} recorded as version {$created->version}.",
                $created,
                before: $latest === null ? [] : ['rate' => (string) $latest->rate, 'version' => $latest->version],
                after: ['rate' => (string) $created->rate, 'version' => $created->version, 'source' => $source],
                properties: ['group_id' => $groupId],
                companyId: $group->parent_company_id,
            );

            return ['rate' => $created, 'created' => true];
        });
    }
}
