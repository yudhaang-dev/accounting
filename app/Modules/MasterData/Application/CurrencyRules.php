<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\Company;
use App\Modules\MasterData\Domain\CompanyStatus;
use App\Support\Company\CompanyContext;
use Illuminate\Validation\ValidationException;

/**
 * Rules about the functional currency of the current company that the currency actions share.
 */
final class CurrencyRules
{
    public function __construct(private readonly CompanyContext $context) {}

    public function isFunctional(string $code): bool
    {
        return $this->company()->functional_currency === $code;
    }

    /**
     * The ledger validates amounts against the scale of the functional currency, so it can only
     * change while the company is being set up.
     *
     * @throws ValidationException
     */
    public function assertScaleChangeAllowed(string $code, int $current, int $new): void
    {
        if ($new !== $current && $this->isFunctional($code) && $this->company()->status !== CompanyStatus::Setup) {
            throw ValidationException::withMessages(['scale' => [__('currencies.functional_scale_locked')]]);
        }
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->context->require());
    }
}
