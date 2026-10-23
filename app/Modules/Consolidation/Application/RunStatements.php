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
 * - The cash flow is indirect (CO-FR-10), built from the change of each balance sheet account of the group between
 *   the opening and the closing balance of the run, by the cash flow class the account had in the run.
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
            'cash_flow' => $this->cashFlow($lines),
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
            'current_year_earnings' => $this->str($earnings),
            'total_liabilities_and_equity' => $this->str($right),
            'difference' => $this->str(BigDecimal::of($assets['total'])->minus($right)),
            'balanced' => BigDecimal::of($assets['total'])->isEqualTo($right),
        ];
    }

    /**
     * The indirect cash flow of the group (CO-FR-10). Starting from the result of the range, the change of every
     * other account of the balance sheet moves cash the other way: an asset that grew used cash, a liability that
     * grew supplied it. The accounts of each class make up one activity; `cash` accounts are the cash itself, and
     * an equity account without a class counts as financing. The translation difference account is not an
     * activity: it and what translating the opening balances at their own rate leaves are shown as the effect of
     * translation. An asset or liability with a balance and no class is `unclassified`, left out of every
     * activity, and then the cash flow does not reconcile and says so rather than hiding it.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function cashFlow(array $lines): array
    {
        $zero = BigDecimal::zero();
        $activities = ['operating' => [], 'investing' => [], 'financing' => []];
        $unclassified = [];
        $openingCash = $zero;
        $closingCash = $zero;
        $resultByMovement = $zero;
        $resultByChange = $zero;
        $translation = $zero;

        foreach ($lines as $line) {
            $opening = BigDecimal::of($line['opening_debit'])->minus($line['opening_credit']);
            $closing = BigDecimal::of($line['closing']);
            $change = $closing->minus($opening);

            if (in_array($line['class'], ['income', 'expense'], true)) {
                $resultByMovement = $resultByMovement->minus(BigDecimal::of($line['debit'])->minus($line['credit']));
                $resultByChange = $resultByChange->minus($change);

                continue;
            }

            if ($this->isTranslationDifference($line['sources'])) {
                $translation = $translation->minus($change);

                continue;
            }

            $class = $line['cash_flow_class'] ?? ($line['class'] === 'equity' ? 'financing' : null);

            if ($class === 'cash') {
                $openingCash = $openingCash->plus($opening);
                $closingCash = $closingCash->plus($closing);

                continue;
            }

            $row = ['group_account_id' => $line['group_account_id'], 'code' => $line['code'], 'name' => $line['name'], 'amount' => $this->str($change->negated())];

            if ($class === null) {
                if (! $opening->isZero() || ! $closing->isZero()) {
                    $unclassified[] = $row;
                }

                continue;
            }

            $activities[$class][] = $row;
        }

        // What translating the opening result at its own rate adds to the result of the range, with the change of the
        // translation difference account: the part of the change in cash that no activity explains.
        $translationEffect = $resultByChange->minus($resultByMovement)->plus($translation);

        $totals = [];

        foreach ($activities as $activity => $rows) {
            $totals[$activity] = array_reduce($rows, fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['amount']), $zero);
        }

        $operating = $resultByMovement->plus($totals['operating']);
        $netChange = $operating->plus($totals['investing'])->plus($totals['financing'])->plus($translationEffect);
        $difference = $closingCash->minus($openingCash)->minus($netChange);

        return [
            'method' => 'indirect',
            'net_result' => $this->str($resultByMovement),
            'operating' => ['lines' => $activities['operating'], 'total' => $this->str($operating)],
            'investing' => ['lines' => $activities['investing'], 'total' => $this->str($totals['investing'])],
            'financing' => ['lines' => $activities['financing'], 'total' => $this->str($totals['financing'])],
            'translation_effect' => $this->str($translationEffect),
            'net_change' => $this->str($netChange),
            'opening_cash' => $this->str($openingCash),
            'closing_cash' => $this->str($closingCash),
            'difference' => $this->str($difference),
            'unclassified' => $unclassified,
            'reconciled' => $unclassified === [] && $difference->isZero(),
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
            'net_result' => $this->str(BigDecimal::of($income['total'])->minus($expenses['total'])),
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
            $rows[] = ['group_account_id' => $line['group_account_id'], 'code' => $line['code'], 'name' => $line['name'], 'amount' => $this->str($value)];
        }

        return ['lines' => $rows, 'total' => $this->str($total)];
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
                'accounts' => $this->str($part['accounts']),
                'translation_difference' => $this->str($part['translation_difference']),
                'eliminations' => $this->str($part['eliminations']),
                'contribution' => $this->str($contribution),
            ];
        }

        $adjusted = BigDecimal::of($result['totals']['adjustments']['closing']);

        return [
            'entities' => $rows,
            'elimination_differences' => $this->str($apart),
            'adjustments' => $this->str($adjusted),
            'total' => $this->str($total->plus($apart)->plus($adjusted)),
            'run_total' => $result['totals']['all']['closing'],
        ];
    }

    /**
     * Whether a line is the account that takes the translation difference: it has sources and all are that.
     *
     * @param  list<array<string, mixed>>  $sources
     */
    private function isTranslationDifference(array $sources): bool
    {
        foreach ($sources as $source) {
            if ($source['kind'] !== 'translation_difference') {
                return false;
            }
        }

        return $sources !== [];
    }

    /** The amount with at least two decimals: a sum of nothing is 0.00, as every other figure. */
    private function str(BigDecimal $amount): string
    {
        return (string) $amount->toScale(max(2, $amount->getScale()));
    }
}
