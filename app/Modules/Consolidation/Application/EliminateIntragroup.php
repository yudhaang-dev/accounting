<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Consolidation\Domain\RunElimination;
use App\Modules\Consolidation\Domain\RunMatch;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;

/**
 * Matches what the entities of a run hold against each other and eliminates the pairs that agree (CO-FR-07).
 *
 * A position is the part of an account that a company holds against another group company (a line with that
 * company as counterparty), in the presentation currency. Positions are grouped by the pair of entities and by
 * kind: closing balances on balance sheet accounts (`balance`), movement on income and expense accounts (`flow`).
 * Both sides of a pair are debit minus credit, so what one side owes the other cancels out: the pair matches when
 * the two net amounts add up to no more than the tolerance of the group, in either direction.
 *
 * - A matched pair has every position reversed on the group account it was mapped to. What the pair still
 *   leaves inside the tolerance is kept as a row of its own, on no account, so that the run stays in balance and
 *   the difference is seen and never removed silently.
 * - A pair over the tolerance is an exception: nothing of it is eliminated.
 * - A position against a company outside the perimeter of the run, or against itself, is an exception too: there is
 *   no other side to match it with.
 *
 * It only computes. The caller stores the rows, and the ledgers of the entities are never touched (CO-AC-04).
 */
final class EliminateIntragroup
{
    private const SCALE = 6;

    /**
     * @param  list<string>  $memberIds  the entities of the run
     * @param  list<array<string, mixed>>  $positions  `company_id`, `counterparty_company_id`, account fields and the six amounts, in the presentation currency
     * @param  array<string, array{id: string, code: string, name: string, class: string}|null>  $groupAccounts  the group account by `company:account`
     * @return array{matches: list<array<string, mixed>>, eliminations: list<array<string, mixed>>} rows to store, without the run
     */
    public function __invoke(array $memberIds, array $positions, string $tolerance, array $groupAccounts): array
    {
        $pairs = [];

        foreach ($positions as $position) {
            $companyId = (string) $position['company_id'];
            $counterpartyId = (string) $position['counterparty_company_id'];
            $inside = $counterpartyId !== $companyId && in_array($counterpartyId, $memberIds, true);
            [$a, $b] = $inside ? [min($companyId, $counterpartyId), max($companyId, $counterpartyId)] : [$companyId, $counterpartyId];
            $kind = in_array($position['account_class'], ['income', 'expense'], true) ? 'flow' : 'balance';

            $pairs["{$kind}|{$a}|{$b}"] ??= ['kind' => $kind, 'a' => $a, 'b' => $b, 'inside' => $inside, 'positions' => []];
            $pairs["{$kind}|{$a}|{$b}"]['positions'][] = $position;
        }

        ksort($pairs);

        $matches = [];
        $eliminations = [];

        foreach ($pairs as $pair) {
            $amount = fn (array $position): BigDecimal => $pair['kind'] === 'balance'
                ? BigDecimal::of($position['closing_debit'])->minus($position['closing_credit'])
                : BigDecimal::of($position['debit'])->minus($position['credit']);
            $sideA = BigDecimal::zero();
            $sideB = BigDecimal::zero();

            foreach ($pair['positions'] as $position) {
                if ($position['company_id'] === $pair['a']) {
                    $sideA = $sideA->plus($amount($position));
                } else {
                    $sideB = $sideB->plus($amount($position));
                }
            }

            $difference = $sideA->plus($sideB);
            $matched = $pair['inside'] && $difference->abs()->isLessThanOrEqualTo($tolerance);
            $matchId = (string) Str::ulid();

            $matches[] = [
                'id' => $matchId,
                'kind' => $pair['kind'],
                'company_a_id' => $pair['a'],
                'company_b_id' => $pair['b'],
                'amount_a' => $this->money($sideA),
                'amount_b' => $this->money($sideB),
                'difference' => $this->money($difference),
                'status' => $matched ? RunMatch::MATCHED : RunMatch::EXCEPTION,
                'reason' => $matched ? null : ($pair['inside'] ? 'difference' : 'outside_perimeter'),
                'positions' => $pair['positions'],
            ];

            if (! $matched) {
                continue;
            }

            $net = array_fill_keys(['opening', 'movement', 'closing'], BigDecimal::zero());

            foreach ($pair['positions'] as $position) {
                $target = $groupAccounts["{$position['company_id']}:{$position['account_id']}"] ?? null;

                // The reversal of a position swaps its sides.
                $eliminations[] = [
                    'match_id' => $matchId,
                    'kind' => RunElimination::ELIMINATION,
                    'company_id' => $position['company_id'],
                    'counterparty_company_id' => $position['counterparty_company_id'],
                    'account_id' => $position['account_id'],
                    'account_code' => $position['account_code'],
                    'account_name' => $position['account_name'],
                    'account_class' => $position['account_class'],
                    'group_account_id' => $target['id'] ?? null,
                    'group_account_code' => $target['code'] ?? null,
                    'group_account_name' => $target['name'] ?? null,
                    'group_account_class' => $target['class'] ?? null,
                    'opening_debit' => $this->money($position['opening_credit']),
                    'opening_credit' => $this->money($position['opening_debit']),
                    'debit' => $this->money($position['credit']),
                    'credit' => $this->money($position['debit']),
                    'closing_debit' => $this->money($position['closing_credit']),
                    'closing_credit' => $this->money($position['closing_debit']),
                ];

                $net['opening'] = $net['opening']->plus($position['opening_debit'])->minus($position['opening_credit']);
                $net['movement'] = $net['movement']->plus($position['debit'])->minus($position['credit']);
                $net['closing'] = $net['closing']->plus($position['closing_debit'])->minus($position['closing_credit']);
            }

            if ($net['opening']->isZero() && $net['movement']->isZero() && $net['closing']->isZero()) {
                continue;
            }

            // What the positions of the pair do not cancel stays in the group, so debit still equals credit.
            $eliminations[] = [
                'match_id' => $matchId,
                'kind' => RunElimination::DIFFERENCE,
                'company_id' => null,
                'counterparty_company_id' => null,
                'account_id' => null,
                'account_code' => null,
                'account_name' => null,
                'account_class' => null,
                'group_account_id' => null,
                'group_account_code' => null,
                'group_account_name' => null,
                'group_account_class' => null,
                'opening_debit' => $this->money($net['opening']->isPositive() ? $net['opening'] : BigDecimal::zero()),
                'opening_credit' => $this->money($net['opening']->isNegative() ? $net['opening']->negated() : BigDecimal::zero()),
                'debit' => $this->money($net['movement']->isPositive() ? $net['movement'] : BigDecimal::zero()),
                'credit' => $this->money($net['movement']->isNegative() ? $net['movement']->negated() : BigDecimal::zero()),
                'closing_debit' => $this->money($net['closing']->isPositive() ? $net['closing'] : BigDecimal::zero()),
                'closing_credit' => $this->money($net['closing']->isNegative() ? $net['closing']->negated() : BigDecimal::zero()),
            ];
        }

        return ['matches' => $matches, 'eliminations' => $eliminations];
    }

    private function money(BigDecimal|string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(self::SCALE, RoundingMode::HalfUp);
    }
}
