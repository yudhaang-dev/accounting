<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Contracts\CurrencyRounding;
use App\Modules\MasterData\Domain\CompanyCurrency;
use App\Modules\MasterData\Domain\Currency;
use App\Platform\Api\ApiException;
use App\Platform\Audit\Audit;
use App\Support\Company\CompanyContext;
use App\Support\Currency\ForeignCurrencyRequirements;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Activates a currency from the ISO 4217 reference for the current company (MD-FR-06). The scale
 * defaults to the ISO minor unit and the rounding to half-up. A foreign currency is refused until the
 * ledger roles for exchange differences are mapped (GL-FR-10).
 */
final class ActivateCurrency
{
    public function __construct(
        private readonly Audit $audit,
        private readonly CurrencyRules $rules,
        private readonly ForeignCurrencyRequirements $requirements,
        private readonly CompanyContext $context,
    ) {}

    /**
     * @param  array{currency: string, scale?: int|null, rounding_mode?: string|null}  $data
     *
     * @throws ValidationException
     * @throws ApiException MAPPING_REQUIRED
     */
    public function __invoke(array $data): CompanyCurrency
    {
        return DB::transaction(function () use ($data): CompanyCurrency {
            $reference = Currency::query()->find($data['currency'])
                ?? throw ValidationException::withMessages(['currency' => [__('currencies.unknown', ['code' => $data['currency']])]]);

            if (CompanyCurrency::query()->where('currency', $reference->code)->exists()) {
                throw ValidationException::withMessages(['currency' => [__('currencies.already_activated', ['code' => $reference->code])]]);
            }

            if (! $this->rules->isFunctional($reference->code)) {
                $this->requirements->assertMet($this->context->require());
            }

            $currency = CompanyCurrency::create([
                'currency' => $reference->code,
                'scale' => $data['scale'] ?? $reference->minor_unit,
                'rounding_mode' => CurrencyRounding::from($data['rounding_mode'] ?? CurrencyRounding::HalfUp->value),
            ])->refresh();

            $this->audit->record(
                'currency.activated',
                "Currency {$currency->currency} activated.",
                $currency,
                after: ['currency' => $currency->currency, 'scale' => $currency->scale, 'rounding_mode' => $currency->rounding_mode->value],
                companyId: $currency->company_id,
            );

            return $currency;
        });
    }
}
