<?php

namespace App\Modules\TransactionEntry\Application;

use App\Modules\TransactionEntry\Contracts\ApprovalPolicies;
use App\Modules\TransactionEntry\Domain\Entry;
use Brick\Math\BigDecimal;

/**
 * Chooses the approval an entry needs from the policy of the company (TE-FR-09): the first rule whose
 * type key, source and threshold the entry meets. An entry no rule matches needs one approval by a
 * holder of `entries.approve`. The plan is kept on the entry when it is submitted, so a later change of
 * the policy does not move the goalposts of an entry that is already waiting.
 *
 * @phpstan-type Plan array{policy_version: int, rule: int|null, amount: string, stages: list<string>, auto_post: bool, separation_of_duties: bool, approvals: list<int>}
 */
final class ApprovalPlanner
{
    public const DEFAULT_PERMISSION = 'entries.approve';

    public function __construct(
        private readonly ApprovalPolicies $policies,
        private readonly EntryRates $rates,
    ) {}

    /**
     * @return Plan
     */
    public function plan(Entry $entry): array
    {
        $policy = $this->policies->current($entry->company_id);
        $amount = $this->amount($entry);

        foreach ($policy->rules as $index => $rule) {
            if (($rule->transactionTypeKey === null || $rule->transactionTypeKey === $entry->transaction_type_key)
                && ($rule->source === null || $rule->source === $entry->source)
                && ($rule->threshold === null || $amount->isGreaterThan($rule->threshold))) {
                return $this->make($policy->version, $index, $amount, $rule->stages, $rule->autoPost, $policy->separationOfDuties);
            }
        }

        return $this->make($policy->version, null, $amount, [self::DEFAULT_PERMISSION], false, $policy->separationOfDuties);
    }

    /**
     * The plan of an entry that was submitted before plans were kept: one ordinary approval.
     *
     * @return Plan
     */
    public function fallback(): array
    {
        return $this->make(0, null, BigDecimal::zero(), [self::DEFAULT_PERMISSION], false, false);
    }

    /** Total debit of the entry in the functional currency, as the lines stand when it is submitted. */
    private function amount(Entry $entry): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($entry->lines as $line) {
            $debit = BigDecimal::of($line['debit']);
            $rate = $line['applied_rate'] ?? null;

            $total = $total->plus($rate === null ? $debit : $this->rates->toFunctional($debit, $rate));
        }

        return $total;
    }

    /**
     * @param  list<string>  $stages
     * @return Plan
     */
    private function make(int $version, ?int $rule, BigDecimal $amount, array $stages, bool $autoPost, bool $separationOfDuties): array
    {
        return [
            'policy_version' => $version,
            'rule' => $rule,
            'amount' => (string) $amount,
            'stages' => $stages,
            'auto_post' => $autoPost,
            'separation_of_duties' => $separationOfDuties,
            'approvals' => [],
        ];
    }
}
