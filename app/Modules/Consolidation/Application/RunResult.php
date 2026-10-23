<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Consolidation\Domain\Adjustment;
use App\Modules\Consolidation\Domain\AdjustmentLine;
use App\Modules\Consolidation\Domain\Run;
use App\Modules\Consolidation\Domain\RunBalance;
use App\Modules\Consolidation\Domain\RunElimination;
use App\Modules\Consolidation\Domain\RunEntity;
use App\Modules\Consolidation\Domain\RunMatch;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The result of a run as it is read (CO-FR-04, CO-FR-10): the lines per account of the group, each with the
 * accounts and entities it adds up, the entities that took part, and the exceptions, which are the balances of
 * accounts without a mapping. All of it comes from the rows stored with the run, never from the ledgers, so
 * it reads the same whatever has changed since (CO-FR-09).
 *
 * An entity in another currency is translated before it is added (CO-FR-06): its rows carry the amounts in the
 * functional currency and the rates they came from, and the difference that translation leaves is a row of its
 * own, of kind `translation_difference`, on the equity account of the policy.
 *
 * What the entities hold against each other is matched pair by pair (CO-FR-07): `intercompany` lists the verdict
 * on each pair with the positions behind it, and the pairs that agreed within the tolerance are eliminated by rows
 * of their own. A line carries the eliminations on its group account, and its figures are the sources plus them;
 * eliminations that belong to no group account (what a pair left within the tolerance, a position whose account
 * has no mapping) are listed apart. Both are part of the totals, so the run still balances.
 *
 * Manual adjustments recorded on the run (CO-FR-08) are added when the result is read, and only the approved
 * ones: the rows of the run never change, the adjustments are records about it. An adjustment is a movement of
 * the period, so it adds to the debit and credit columns and to the closing balance, never to the opening one.
 *
 * A group line adds the movements and the balances of its sources; the closing balance is stated as one
 * signed figure, debit minus credit, and split into the side it falls on. The result says `management_rollup`
 * and not `formal`: it is not a consolidated statement (CO-FR-05).
 */
final class RunResult
{
    private const SCALE = 6;

    /**
     * @return array<string, mixed>
     */
    public function summary(Run $run): array
    {
        return [
            'id' => $run->id,
            'group_id' => $run->group_id,
            'from' => $run->from_date->toDateString(),
            'to' => $run->to_date->toDateString(),
            'cutoff_date' => $run->to_date->toDateString(),
            'label' => $run->label,
            'formal' => false,
            'status' => $run->status,
            'presentation_currency' => $run->presentation_currency,
            'mapping_version' => $run->mapping_version,
            'translation_policy_version' => $run->translation_policy_version,
            'package_policy' => $run->package_policy,
            'elimination_tolerance' => $this->money(BigDecimal::of($run->elimination_tolerance), 2),
            'coverage_status' => $run->coverage_status,
            'created_by' => $run->created_by,
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Run $run): array
    {
        $entities = $run->entities()->get();
        $balances = $run->balances()->orderBy('group_account_code')->orderBy('company_id')->orderBy('account_code')->get();
        $eliminations = $run->eliminations()->orderBy('group_account_code')->orderBy('company_id')->orderBy('account_code')->orderBy('id')->get();
        $matches = $run->matches()->orderBy('kind')->orderBy('company_a_id')->orderBy('company_b_id')->get();
        $recorded = Adjustment::query()->where('run_id', $run->id)->with('lines')->orderBy('created_at')->orderBy('id')->get();
        $scale = $this->scale(array_values([...$balances->all(), ...$eliminations->all(), ...$matches->all(), ...$recorded->pluck('lines')->flatten()->all()]));

        $lines = [];
        $exceptions = [];
        $totals = ['mapped' => $this->zero(), 'unmapped' => $this->zero(), 'eliminations' => $this->zero(), 'adjustments' => $this->zero()];

        foreach ($balances as $balance) {
            $closing = BigDecimal::of($balance->closing_debit)->minus($balance->closing_credit);

            if ($balance->group_account_id === null) {
                $exceptions[] = $this->source($balance, $scale) + ['reason' => 'unmapped'];
                $totals['unmapped'] = $this->add($totals['unmapped'], $balance);

                continue;
            }

            $lines[$balance->group_account_id] ??= [
                'group_account_id' => $balance->group_account_id,
                'code' => (string) $balance->group_account_code,
                'name' => (string) $balance->group_account_name,
                'class' => (string) $balance->group_account_class,
                'sum' => $this->zero(),
                'sources' => [],
            ];
            $lines[$balance->group_account_id]['sum'] = $this->add($lines[$balance->group_account_id]['sum'], $balance);
            $lines[$balance->group_account_id]['sources'][] = $this->source($balance, $scale) + ['closing' => $this->money($closing, $scale)];
            $totals['mapped'] = $this->add($totals['mapped'], $balance);
        }

        $unassigned = [];

        foreach ($eliminations as $elimination) {
            $totals['eliminations'] = $this->add($totals['eliminations'], $elimination);

            if ($elimination->group_account_id === null) {
                $unassigned[] = $this->elimination($elimination, $scale);

                continue;
            }

            $lines[$elimination->group_account_id] ??= [
                'group_account_id' => $elimination->group_account_id,
                'code' => (string) $elimination->group_account_code,
                'name' => (string) $elimination->group_account_name,
                'class' => (string) $elimination->group_account_class,
                'sum' => $this->zero(),
                'sources' => [],
            ];
            $lines[$elimination->group_account_id]['sum'] = $this->add($lines[$elimination->group_account_id]['sum'], $elimination);
            $lines[$elimination->group_account_id]['eliminations'][] = $this->elimination($elimination, $scale);
        }

        foreach ($recorded->where('status', Adjustment::APPROVED) as $adjustment) {
            foreach ($adjustment->lines as $line) {
                $amounts = $this->zero();
                $amounts['debit'] = $amounts['closing_debit'] = BigDecimal::of($line->debit);
                $amounts['credit'] = $amounts['closing_credit'] = BigDecimal::of($line->credit);

                $lines[$line->group_account_id] ??= [
                    'group_account_id' => $line->group_account_id,
                    'code' => $line->group_account_code,
                    'name' => $line->group_account_name,
                    'class' => $line->group_account_class,
                    'sum' => $this->zero(),
                    'sources' => [],
                ];
                $lines[$line->group_account_id]['sum'] = $this->add($lines[$line->group_account_id]['sum'], null, $amounts);
                $lines[$line->group_account_id]['adjustments'][] = ['adjustment_id' => $adjustment->id, 'source' => $adjustment->source, 'reason' => $adjustment->reason, ...$this->figures($amounts, $scale)];
                $totals['adjustments'] = $this->add($totals['adjustments'], null, $amounts);
            }
        }

        $grand = $this->add($this->add($this->add($this->add($this->zero(), null, $totals['mapped']), null, $totals['unmapped']), null, $totals['eliminations']), null, $totals['adjustments']);

        return [
            ...$this->summary($run),
            'perimeter' => $run->perimeter,
            'entities' => $entities->map(fn (RunEntity $entity): array => [
                'company_id' => $entity->company_id,
                'name' => $entity->company_name,
                'functional_currency' => $entity->functional_currency,
                'book_status' => $entity->book_status,
                'coverage_from' => $entity->coverage_from?->toDateString(),
                'coverage_to' => $entity->coverage_to?->toDateString(),
                'coverage_granularity' => $entity->coverage_granularity,
                'coverage_status' => $entity->coverage_status,
                'balanced' => $entity->balanced,
                'source' => $entity->source,
                'translation' => $entity->translation,
            ])->values()->all(),
            'lines' => array_values(array_map(fn (array $line): array => [
                'group_account_id' => $line['group_account_id'],
                'code' => $line['code'],
                'name' => $line['name'],
                'class' => $line['class'],
                ...$this->figures($line['sum'], $scale),
                'sources' => $line['sources'],
                'eliminations' => $line['eliminations'] ?? [],
                'adjustments' => $line['adjustments'] ?? [],
            ], $lines)),
            'exceptions' => $exceptions,
            'intercompany' => $matches->map(fn (RunMatch $match): array => [
                'id' => $match->id,
                'kind' => $match->kind,
                'company_a_id' => $match->company_a_id,
                'company_b_id' => $match->company_b_id,
                'amount_a' => $this->money(BigDecimal::of($match->amount_a), $scale),
                'amount_b' => $this->money(BigDecimal::of($match->amount_b), $scale),
                'difference' => $this->money(BigDecimal::of($match->difference), $scale),
                'status' => $match->status,
                'reason' => $match->reason,
                'positions' => $match->positions,
            ])->values()->all(),
            'unassigned_eliminations' => $unassigned,
            'adjustments' => $recorded->map(fn (Adjustment $adjustment): array => $this->adjustment($adjustment))->values()->all(),
            'totals' => [
                'adjustments' => $this->figures($totals['adjustments'], $scale),
                'eliminations' => $this->figures($totals['eliminations'], $scale),
                'mapped' => $this->figures($totals['mapped'], $scale),
                'unmapped' => $this->figures($totals['unmapped'], $scale),
                'all' => $this->figures($grand, $scale),
            ],
            // Debit equals credit over everything the entities hold, mapped or not, and every package says so.
            'balanced' => $grand['closing_debit']->isEqualTo($grand['closing_credit'])
                && $entities->every(fn (RunEntity $entity): bool => $entity->balanced),
        ];
    }

    /**
     * @return array{opening_debit: BigDecimal, opening_credit: BigDecimal, debit: BigDecimal, credit: BigDecimal, closing_debit: BigDecimal, closing_credit: BigDecimal}
     */
    private function zero(): array
    {
        $zero = BigDecimal::zero();

        return ['opening_debit' => $zero, 'opening_credit' => $zero, 'debit' => $zero, 'credit' => $zero, 'closing_debit' => $zero, 'closing_credit' => $zero];
    }

    /**
     * @param  array<string, BigDecimal>  $sum
     * @param  array<string, BigDecimal>|null  $other  added instead of a balance
     * @return array<string, BigDecimal>
     */
    private function add(array $sum, RunBalance|RunElimination|null $balance, ?array $other = null): array
    {
        foreach (array_keys($sum) as $column) {
            $sum[$column] = $sum[$column]->plus($balance !== null ? $balance->{$column} : $other[$column]);
        }

        return $sum;
    }

    /**
     * @param  array<string, BigDecimal>  $sum
     * @param  int<0, max>  $scale
     * @return array<string, string>
     */
    private function figures(array $sum, int $scale): array
    {
        $closing = $sum['closing_debit']->minus($sum['closing_credit']);

        return [
            'opening_debit' => $this->money($sum['opening_debit'], $scale),
            'opening_credit' => $this->money($sum['opening_credit'], $scale),
            'debit' => $this->money($sum['debit'], $scale),
            'credit' => $this->money($sum['credit'], $scale),
            'closing' => $this->money($closing, $scale),
            'closing_debit' => $this->money($closing->isPositive() ? $closing : BigDecimal::zero(), $scale),
            'closing_credit' => $this->money($closing->isNegative() ? $closing->negated() : BigDecimal::zero(), $scale),
        ];
    }

    /**
     * @param  int<0, max>  $scale
     * @return array<string, array<string, mixed>|string|null>
     */
    private function source(RunBalance $balance, int $scale): array
    {
        return [
            'company_id' => $balance->company_id,
            'account_id' => $balance->account_id,
            'code' => $balance->account_code,
            'name' => $balance->account_name,
            'class' => $balance->account_class,
            'opening_debit' => $this->money(BigDecimal::of($balance->opening_debit), $scale),
            'opening_credit' => $this->money(BigDecimal::of($balance->opening_credit), $scale),
            'debit' => $this->money(BigDecimal::of($balance->debit), $scale),
            'credit' => $this->money(BigDecimal::of($balance->credit), $scale),
            'closing_debit' => $this->money(BigDecimal::of($balance->closing_debit), $scale),
            'closing_credit' => $this->money(BigDecimal::of($balance->closing_credit), $scale),
            'kind' => $balance->kind,
            'translation' => $balance->translation,
        ];
    }

    /**
     * The adjustments recorded on a run, oldest first, whatever their status.
     *
     * @return list<array<string, mixed>>
     */
    public function adjustments(Run $run): array
    {
        return array_values(Adjustment::query()->where('run_id', $run->id)->with('lines')->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (Adjustment $adjustment): array => $this->adjustment($adjustment))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function adjustment(Adjustment $adjustment): array
    {
        return [
            'id' => $adjustment->id,
            'run_id' => $adjustment->run_id,
            'source' => $adjustment->source,
            'reason' => $adjustment->reason,
            'status' => $adjustment->status,
            'proposed_by' => $adjustment->proposed_by,
            'decided_by' => $adjustment->decided_by,
            'decided_at' => $adjustment->decided_at?->toIso8601String(),
            'rejection_reason' => $adjustment->rejection_reason,
            'created_at' => $adjustment->created_at?->toIso8601String(),
            'lines' => $adjustment->lines->map(fn (AdjustmentLine $line): array => [
                'group_account_id' => $line->group_account_id,
                'code' => $line->group_account_code,
                'name' => $line->group_account_name,
                'class' => $line->group_account_class,
                'debit' => $this->natural($line->debit),
                'credit' => $this->natural($line->credit),
            ])->values()->all(),
        ];
    }

    /**
     * One elimination: the reversal of an intragroup position, or the difference a pair left (CO-FR-07).
     *
     * @param  int<0, max>  $scale
     * @return array<string, string|null>
     */
    private function elimination(RunElimination $elimination, int $scale): array
    {
        return [
            'match_id' => $elimination->match_id,
            'kind' => $elimination->kind,
            'company_id' => $elimination->company_id,
            'counterparty_company_id' => $elimination->counterparty_company_id,
            'account_id' => $elimination->account_id,
            'code' => $elimination->account_code,
            'name' => $elimination->account_name,
            'class' => $elimination->account_class,
            'opening_debit' => $this->money(BigDecimal::of($elimination->opening_debit), $scale),
            'opening_credit' => $this->money(BigDecimal::of($elimination->opening_credit), $scale),
            'debit' => $this->money(BigDecimal::of($elimination->debit), $scale),
            'credit' => $this->money(BigDecimal::of($elimination->credit), $scale),
            'closing_debit' => $this->money(BigDecimal::of($elimination->closing_debit), $scale),
            'closing_credit' => $this->money(BigDecimal::of($elimination->closing_credit), $scale),
            'closing' => $this->money(BigDecimal::of($elimination->closing_debit)->minus($elimination->closing_credit), $scale),
        ];
    }

    /**
     * The scale amounts were stored with: the most decimals any of them really uses, at least two.
     *
     * @param  list<RunBalance|RunElimination|RunMatch|AdjustmentLine>  $rows
     * @return int<0, max>
     */
    private function scale(array $rows): int
    {
        $scale = 2;

        foreach ($rows as $row) {
            $columns = match (true) {
                $row instanceof RunMatch => ['amount_a', 'amount_b', 'difference'],
                $row instanceof AdjustmentLine => ['debit', 'credit'],
                default => ['opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'],
            };

            foreach ($columns as $column) {
                $scale = max($scale, strlen(rtrim(explode('.', (string) BigDecimal::of($row->getAttribute($column)))[1] ?? '', '0')));
            }
        }

        return min($scale, self::SCALE);
    }

    /** The amount with the decimals it uses, at least two. */
    private function natural(string $amount): string
    {
        $decimals = strlen(rtrim(explode('.', (string) BigDecimal::of($amount))[1] ?? '', '0'));

        return (string) BigDecimal::of($amount)->toScale(max(2, min($decimals, self::SCALE)), RoundingMode::HalfUp);
    }

    /** @param  int<0, max>  $scale */
    private function money(BigDecimal $value, int $scale): string
    {
        return (string) $value->toScale($scale, RoundingMode::HalfUp);
    }
}
