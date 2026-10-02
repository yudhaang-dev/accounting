<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\RateType;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a rate type to the current company (MD-FR-07).
 */
final class CreateRateType
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array{key: string, label: string, max_age_days?: int|null}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(array $data): RateType
    {
        return DB::transaction(function () use ($data): RateType {
            if (RateType::query()->where('key', $data['key'])->exists()) {
                throw ValidationException::withMessages(['key' => [__('rates.key_taken', ['key' => $data['key']])]]);
            }

            $type = RateType::create([
                'key' => $data['key'],
                'label' => $data['label'],
                'max_age_days' => $data['max_age_days'] ?? null,
            ])->refresh();

            $this->audit->record(
                'rate_type.created',
                "Rate type {$type->key} created.",
                $type,
                after: $type->only(['key', 'label', 'max_age_days', 'status']),
                companyId: $type->company_id,
            );

            return $type;
        });
    }
}
