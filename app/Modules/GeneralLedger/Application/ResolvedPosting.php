<?php

namespace App\Modules\GeneralLedger\Application;

use Brick\Math\BigDecimal;

/**
 * A posting command that passed validation, with everything posting needs resolved: the period, the
 * functional currency, the dimension sets, the lines converted to the functional currency with the
 * rate they used (GL-FR-08) and the rounding line when one was needed (GL-FR-05).
 */
final readonly class ResolvedPosting
{
    /**
     * @param  string|null  $sourceOverrideReason  set when the posting went to an account that does not accept its source, by the override of the policy (GL-FR-07)
     * @param  list<string>  $overriddenAccounts  codes of those accounts
     * @param  list<array{account_id: string, debit: BigDecimal, credit: BigDecimal, currency: string, transaction_amount: BigDecimal, exchange_rate: BigDecimal, rate_type_id: string|null, rate_date: string|null, description: string|null, dimension_set_id: string, counterparty_company_id?: string|null}>  $lines  debit and credit are functional
     */
    public function __construct(
        public string $periodId,
        public string $currency,
        public array $lines,
        public ?string $sourceOverrideReason = null,
        public array $overriddenAccounts = [],
    ) {}
}
