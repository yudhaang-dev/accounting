<?php

namespace App\Modules\MasterData\Contracts;

/**
 * Currencies of a company (MD-FR-06): which are activated and the scale and rounding mode of each.
 */
interface Currencies
{
    /** Whether the code is in the ISO 4217 reference. */
    public function isKnown(string $code): bool;

    /** The currency as the company configured it, active or not; null when it never activated it. */
    public function find(string $companyId, string $code): ?CurrencyConfig;

    /** Whether the company has the currency activated. The functional currency always is. */
    public function isActive(string $companyId, string $code): bool;

    /**
     * Decimal places of amounts in the functional currency, the scale of the ledger. Falls back to
     * `ledger.default_scale` for a company without a configured functional currency.
     *
     * @return int<0, max>
     */
    public function functionalScale(string $companyId): int;
}
