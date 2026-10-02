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
     * @param  string|null  $sourceOverrideReason  set when the posting went to an account that does not accept its source, by the override of the policy (GL-FR-07)
     * @param  list<string>  $overriddenAccounts  codes of those accounts
     * @param  list<array{account_id: string, debit: BigDecimal, credit: BigDecimal, description: string|null, dimension_set_id: string}>  $lines
     */
    public function __construct(
        public string $periodId,
        public string $currency,
        public array $lines,
        public ?string $sourceOverrideReason = null,
        public array $overriddenAccounts = [],
    ) {}
}
