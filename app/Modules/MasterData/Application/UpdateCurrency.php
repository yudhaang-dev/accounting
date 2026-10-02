<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Contracts\CurrencyRounding;
use App\Modules\MasterData\Domain\CompanyCurrency;
use App\Platform\Api\ApiException;
use App\Platform\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes the scale or rounding mode a company uses for a currency (MD-FR-06).
 */
final class UpdateCurrency
{
    private const FIELDS = ['scale', 'rounding_mode'];

    public function __construct(
        private readonly CurrencyRules $rules,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  any of scale, rounding_mode
     *
     * @throws ValidationException
     * @throws ApiException VERSION_CONFLICT
     */
    public function __invoke(string $code, array $data, int $expectedVersion): CompanyCurrency
    {
        return DB::transaction(function () use ($code, $data, $expectedVersion): CompanyCurrency {
            $currency = CompanyCurrency::query()->where('currency', $code)->lockForUpdate()->firstOrFail();
            $changes = array_intersect_key($data, array_flip(self::FIELDS));

            if (isset($changes['scale'])) {
                $this->rules->assertScaleChangeAllowed($code, $currency->scale, (int) $changes['scale']);
            }

            if (isset($changes['rounding_mode'])) {
                $changes['rounding_mode'] = CurrencyRounding::from($changes['rounding_mode']);
            }

            $before = $this->snapshot($currency, array_keys($changes));
            $currency->updateLocked($changes, $expectedVersion);
            $after = $this->snapshot($currency, array_keys($changes));

            if ($before !== $after) {
                $this->audit->record(
                    'currency.updated',
                    "Currency {$code} updated.",
                    $currency,
                    before: $before,
                    after: $after,
                    companyId: $currency->company_id,
                );
            }

            return $currency->refresh();
        });
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function snapshot(CompanyCurrency $currency, array $fields): array
    {
        return array_map(
            fn (mixed $value): mixed => $value instanceof CurrencyRounding ? $value->value : $value,
            $currency->only($fields),
        );
    }
}
