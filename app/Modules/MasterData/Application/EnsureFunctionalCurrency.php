<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\CompanyCurrency;
use App\Modules\MasterData\Domain\Currency;
use App\Modules\MasterData\Domain\DimensionStatus;
use App\Support\Company\CompanyContext;

/**
 * Keeps the functional currency of a company activated (MD-FR-06): on creation and whenever it
 * changes during setup. A currency the company already configured keeps its scale and rounding.
 */
final class EnsureFunctionalCurrency
{
    public function __construct(private readonly CompanyContext $context) {}

    public function __invoke(string $companyId, string $code): void
    {
        $this->context->run($companyId, function () use ($code): void {
            $existing = CompanyCurrency::query()->where('currency', $code)->first();

            if ($existing !== null) {
                if (! $existing->isActive()) {
                    $existing->forceFill(['status' => DimensionStatus::Active])->save();
                }

                return;
            }

            $reference = Currency::query()->find($code);

            // An unknown code never reaches here through the API; skip rather than invent a scale.
            if ($reference !== null) {
                CompanyCurrency::create(['currency' => $code, 'scale' => $reference->minor_unit]);
            }
        });
    }
}
