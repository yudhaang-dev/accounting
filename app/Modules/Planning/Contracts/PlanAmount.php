<?php

namespace App\Modules\Planning\Contracts;

/** What a plan version holds for one account, added up over the periods and dimension sets asked for. */
final readonly class PlanAmount
{
    /** @param  string  $amount  decimal in the functional currency, positive in the natural direction of the account (PL-FR-02) */
    public function __construct(
        public string $accountId,
        public string $amount,
    ) {}
}
