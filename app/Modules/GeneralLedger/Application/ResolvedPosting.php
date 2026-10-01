<?php

namespace App\Modules\GeneralLedger\Application;

use Brick\Math\BigDecimal;

/**
 * A posting command that passed validation, with everything posting needs resolved: the period,
 * the functional currency, the empty dimension set and the amounts as exact decimals.
 */
final readonly class ResolvedPosting
{
    /**
     * @param  list<array{account_id: string, debit: BigDecimal, credit: BigDecimal, description: string|null, dimension_set_id: string}>  $lines
     */
    public function __construct(
        public string $periodId,
        public string $currency,
        public array $lines,
    ) {}
}
