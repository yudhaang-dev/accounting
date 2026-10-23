<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Consolidation\Domain\Run;
use Brick\Math\BigDecimal;

/**
 * The group statements read from the result of a run (CO-FR-10): a balance sheet and an income statement over the
 * accounts of the group, the report of eliminations and adjustments, and the reconciliation of each entity to
 * the group. Nothing here reads a ledger or a rate: every figure comes from {@see RunResult}, so the statements
 * agree with the run and read the same whatever has changed since (CO-FR-09).
 *
 * - The balance sheet takes the closing balances; the earnings that no account of equity holds yet are the closing
 *   balances of income and expense accounts together, as in the entity balance sheet (RP-FR-08).
 * - The income statement takes the movement of the range.
 * - Assets and expenses are shown as debit minus credit, liabilities, equity and income as credit minus debit.
 * - A statement carries the label and the formal flag of its run, so a draft is never taken for a formal one.
 *   There is no group cash flow: it needs a classification of cash flows at group level that does not exist.
 */
final class RunStatements
{
    public function __construct(private readonly RunResult $result) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Run $run): array
    {
        $result = ($this->result)($run);

        /** @var list<array<string, mixed>> $lines */
        $lines = $result['lines'];

        return [
            ...$this->result->summary($run),
            'balance_sheet' => $this->balanceSheet($lines),
            'income_statement' => $this->incomeStatement($lines),
            'eliminations' => $this->eliminations($result),
            'reconciliation' => $this->reconciliation($result),
            'cash_flow' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function balanceSheet(array $lines): array
    {
        $section = fn (string $class, bool $debitNature): array => $this->section($lines, $class, fn (array $line): BigDecimal => $debitNature
            ? BigDecimal::of($line['closing'])
            : BigDecimal::of($line['closing'])->negated());

        $assets = $section('asset', true);
        $liabilities = $section('liability', false);
        $equity = $section('equity', false);

        $earnings = BigDecimal::zero();

        foreach ($lines as $line) {
            if (in_array($line['class'], ['income', 'expense'], true)) {
                $earnings = $earnings->minus($line['closing']);
            }
        }

        $right = BigDecimal::of($liabilities['total'])->plus($equity['total'])->plus($earnings);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'current_year_earnings' => (string) $earnings,
            'total_liabilities_and_equity' => (string) $right,
            'difference' => (string) BigDecimal::of($assets['total'])->minus($right),
            'balanced' => BigDecimal::of($assets['total'])->isEqualTo($right),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function incomeStatement(array $lines): array
    {
        $income = $this->section($lines, 'income', fn (array $line): BigDecimal => BigDecimal::of($line['credit'])->minus($line['debit']));
        $expenses = $this->section($lines, 'expense', fn (array $line): BigDecimal => BigDecimal::of($line['debit'])->minus($line['credit']));

        return [
            'income' => $income,
            'expenses' => $expenses,
            'net_result' => (string) BigDecimal::of($income['total'])->minus($expenses['total']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  callable(array<string, mixed>): BigDecimal  $amount
     * @return array{lines: list<array{group_account_id: string, code: string, name: string, amount: string}>, total: string}
     */
    private function section(array $lines, string $class, callable $amount): array
    {
        $rows = [];
        $total = BigDecimal::zero();

        foreach ($lines as $line) {
            if ($line['class'] !== $class) {
                continue;
            }

            $value = $amount($line);
            $total = $total->plus($value);
            $rows[] = ['group_account_id' => $line['group_account_id'], 'code' => $line['code'], 'name' => $line['name'], 'amount' => (string) $value];
        }

        return ['lines' => $rows, 'total' => (string) $total];
    }

    /**
     * The report of what was eliminated and adjusted (CO-FR-07, CO-FR-08): the verdict on every intragroup pair, the
     * rows that eliminate the matched ones, and the adjustments with their status. Only approved adjustments count.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function eliminations(array $result): array
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = $result['lines'];
        $rows = [];

        foreach ($lines as $line) {
            foreach ($line['eliminations'] as $elimination) {
                $rows[] = $elimination + ['group_account_id' => $line['group_account_id'], 'group_account_code' => $line['code']];
            }
        }

        /** @var list<array<string, mixed>> $unassigned */
        $unassigned = $result['unassigned_eliminations'];

        return [
            'pairs' => $result['intercompany'],
            'eliminations' => [...$rows, ...$unassigned],
            'adjustments' => $result['adjustments'],
            'total_eliminated' => $result['totals']['eliminations'],
            'total_adjusted' => $result['totals']['adjustments'],
        ];
    }

    /**
     * Each entity's way to the group total (CO-FR-10): what its accounts hold in the presentation currency, the
     * translation difference of its own, and what was eliminated against it. Adjustments and the differences that
     * eliminated pairs leave belong to no entity and are listed apart, so the parts add up to the total of the run.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function reconciliation(array $result): array
    {
        /** @var list<array<string, mixed>> $entities */
        $entities = $result['entities'];
        $parts = [];

        foreach ($entities as $entity) {
            $parts[$entity['company_id']] = ['accounts' => BigDecimal::zero(), 'translation_difference' => BigDecimal::zero(), 'eliminations' => BigDecimal::zero()];
        }

        $add = function (string $companyId, string $part, string $closing) use (&$parts): void {
            if (isset($parts[$companyId])) {
                $parts[$companyId][$part] = $parts[$companyId][$part]->plus($closing);
            }
        };

        /** @var list<array<string, mixed>> $lines */
        $lines = $result['lines'];

        foreach ($lines as $line) {
            foreach ($line['sources'] as $source) {
                $add($source['company_id'], $source['kind'] === 'translation_difference' ? 'translation_difference' : 'accounts', $source['closing']);
            }

            foreach ($line['eliminations'] as $elimination) {
                $add((string) $elimination['company_id'], 'eliminations', $elimination['closing']);
            }
        }

        /** @var list<array<string, mixed>> $exceptions */
        $exceptions = $result['exceptions'];

        foreach ($exceptions as $exception) {
            $add($exception['company_id'], 'accounts', (string) BigDecimal::of($exception['closing_debit'])->minus($exception['closing_credit']));
        }

        /** @var list<array<string, mixed>> $unassigned */
        $unassigned = $result['unassigned_eliminations'];
        $apart = BigDecimal::zero();

        foreach ($unassigned as $elimination) {
            if ($elimination['company_id'] !== null) {
                $add($elimination['company_id'], 'eliminations', $elimination['closing']);

                continue;
            }

            $apart = $apart->plus($elimination['closing']);
        }

        $total = BigDecimal::zero();
        $rows = [];

        foreach ($entities as $entity) {
            $part = $parts[$entity['company_id']];
            $contribution = $part['accounts']->plus($part['translation_difference'])->plus($part['eliminations']);
            $total = $total->plus($contribution);

            $rows[] = [
                'company_id' => $entity['company_id'],
                'name' => $entity['name'],
                'functional_currency' => $entity['functional_currency'],
                'book_status' => $entity['book_status'],
                'coverage_status' => $entity['coverage_status'],
                'accounts' => (string) $part['accounts'],
                'translation_difference' => (string) $part['translation_difference'],
                'eliminations' => (string) $part['eliminations'],
                'contribution' => (string) $contribution,
            ];
        }

        $adjusted = BigDecimal::of($result['totals']['adjustments']['closing']);

        return [
            'entities' => $rows,
            'elimination_differences' => (string) $apart,
            'adjustments' => (string) $adjusted,
            'total' => (string) $total->plus($apart)->plus($adjusted),
            'run_total' => $result['totals']['all']['closing'],
        ];
    }
}
