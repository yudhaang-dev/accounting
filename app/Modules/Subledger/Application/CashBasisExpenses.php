<?php

namespace App\Modules\Subledger\Application;

use App\Modules\MasterData\Contracts\CompanyDirectory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * What was paid to suppliers in a range, attributed to the accounts the bills were booked to (RP-FR-11). Each active
 * allocation of a payment to a payables bill dated in the range is cash going out on that day; it is spread over the
 * lines of the bill's journal, other than the control account, in proportion to their amounts, so an expense account
 * shows the cash that settled its share of the bills. Lines on accounts that are not expenses (input tax, a prepaid
 * asset) are added up apart, and so is cash on a bill with no lines to follow, such as an open item of the conversion
 * date; the three always add up to the cash paid on bills. An advance counts when it is applied, on the allocation
 * date. Cash that never went through a bill, and expenses paid straight from a cash account, are not here: the former
 * waits for an allocation and the latter is the direct cash flow (RP-FR-09).
 *
 * @phpstan-type AccountRow array{account_id: string, account_code: string, account_name: string, dimension_set_id: string, cash_paid: string}
 */
final class CashBasisExpenses
{
    private const SCALE = 6;

    public function __construct(private readonly CompanyDirectory $companies) {}

    /**
     * @return array{parameters: array{from: string, to: string}, functional_currency: string, accounts: list<AccountRow>, other_accounts: string, unattributed: string, total: string}
     */
    public function __invoke(string $companyId, string $from, string $to): array
    {
        $allocations = $this->allocations($companyId, $from, $to);
        $lines = $this->lines($companyId, array_values(array_unique(array_filter(array_column($allocations, 'journal_id')))));

        $accounts = [];
        $other = BigDecimal::zero();
        $unattributed = BigDecimal::zero();

        foreach ($allocations as $allocation) {
            $cash = BigDecimal::of($allocation['cash']);
            $follow = $allocation['opening'] ? [] : ($lines[$allocation['journal_id']] ?? []);
            $total = BigDecimal::zero();

            foreach ($follow as $line) {
                $total = $total->plus($line['net']);
            }

            if (! $total->isPositive()) {
                $unattributed = $unattributed->plus($cash);

                continue;
            }

            foreach ($this->spread($cash, $follow, $total) as $index => $share) {
                $line = $follow[$index];

                if ($line['class'] !== 'expense') {
                    $other = $other->plus($share);

                    continue;
                }

                $key = $line['account_id'].'|'.$line['dimension_set_id'];
                $accounts[$key] ??= ['account_id' => $line['account_id'], 'account_code' => $line['code'], 'account_name' => $line['name'], 'dimension_set_id' => $line['dimension_set_id'], 'cash_paid' => BigDecimal::zero()];
                $accounts[$key]['cash_paid'] = $accounts[$key]['cash_paid']->plus($share);
            }
        }

        $rows = array_values($accounts);
        usort($rows, fn (array $a, array $b): int => [$a['account_code'], $a['dimension_set_id']] <=> [$b['account_code'], $b['dimension_set_id']]);
        $total = $other->plus($unattributed);

        foreach ($rows as $row) {
            $total = $total->plus($row['cash_paid']);
        }

        return [
            'parameters' => ['from' => $from, 'to' => $to],
            'functional_currency' => $this->companies->find($companyId)->functionalCurrency ?? '',
            'accounts' => array_map(fn (array $row): array => [...$row, 'cash_paid' => $this->format($row['cash_paid'])], $rows),
            'other_accounts' => $this->format($other),
            'unattributed' => $this->format($unattributed),
            'total' => $this->format($total),
        ];
    }

    /**
     * The cash split in proportion to the net amounts, the rounding going to the largest line so the shares add up.
     *
     * @param  list<array{net: BigDecimal}>  $lines
     * @return array<int, BigDecimal>
     */
    private function spread(BigDecimal $cash, array $lines, BigDecimal $total): array
    {
        $shares = [];
        $largest = 0;
        $given = BigDecimal::zero();

        foreach ($lines as $index => $line) {
            $shares[$index] = $cash->multipliedBy($line['net'])->dividedBy($total, self::SCALE, RoundingMode::Down);
            $given = $given->plus($shares[$index]);

            if ($line['net']->isGreaterThan($lines[$largest]['net'])) {
                $largest = $index;
            }
        }

        $shares[$largest] = $shares[$largest]->plus($cash->minus($given));

        return $shares;
    }

    /**
     * Allocations of payments to payables bills dated in the range, not cancelled by its end.
     *
     * @return list<array{journal_id: string, opening: bool, cash: string}>
     */
    private function allocations(string $companyId, string $from, string $to): array
    {
        $rows = DB::select(
            "select d.journal_id, d.is_opening, a.functional_amount
             from sl_allocations a
             join sl_open_items bill on bill.company_id = a.company_id and bill.id = a.credit_item_id
             join sl_open_items pay on pay.company_id = a.company_id and pay.id = a.debit_item_id
             join sl_documents d on d.company_id = bill.company_id and d.id = bill.document_id
             where a.company_id = ? and a.allocation_date between ? and ? and (a.status = 'active' or a.cancelled_on > ?)
               and bill.control_role = 'payables_control' and bill.kind = 'invoice' and pay.kind = 'payment'
             order by a.allocation_date, a.id",
            [$companyId, $from, $to, $to],
        );

        $result = [];

        foreach ($rows as $row) {
            $result[] = ['journal_id' => (string) $row->journal_id, 'opening' => (bool) $row->is_opening, 'cash' => (string) $row->functional_amount];
        }

        return $result;
    }

    /**
     * The lines of the bill journals other than the control accounts, netted per account and dimension set.
     *
     * @param  list<string>  $journalIds
     * @return array<string, list<array{account_id: string, code: string, name: string, class: string, dimension_set_id: string, net: BigDecimal}>>
     */
    private function lines(string $companyId, array $journalIds): array
    {
        if ($journalIds === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($journalIds), '?'));
        $rows = DB::select(
            "select l.journal_id, l.account_id, a.code, a.name, a.class, l.dimension_set_id, sum(l.debit - l.credit) as net
             from gl_journal_lines l
             join gl_accounts a on a.company_id = l.company_id and a.id = l.account_id
             where l.company_id = ? and l.journal_id in ({$marks})
               and l.account_id not in (select account_id from sl_posting_profile_accounts where company_id = ? and control_role = 'payables_control')
             group by l.journal_id, l.account_id, a.code, a.name, a.class, l.dimension_set_id
             order by l.journal_id, a.code, l.dimension_set_id",
            [$companyId, ...$journalIds, $companyId],
        );

        $lines = [];

        foreach ($rows as $row) {
            $lines[(string) $row->journal_id][] = [
                'account_id' => (string) $row->account_id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'class' => (string) $row->class,
                'dimension_set_id' => (string) $row->dimension_set_id,
                'net' => BigDecimal::of((string) $row->net),
            ];
        }

        return $lines;
    }

    private function format(BigDecimal $value): string
    {
        return (string) $value->toScale(self::SCALE, RoundingMode::HalfUp);
    }
}
