<?php

namespace App\Modules\GeneralLedger\Application;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `gl_account_balances` in step with posting (GL-FR-05). Amounts are summed per balance key
 * first, then the keys are written in sorted order with one atomic upsert each, so concurrent
 * postings take their row locks in the same order and cannot deadlock on each other.
 */
final class AccountBalances
{
    /**
     * @param  list<array{account_id: string, period_id: string, dimension_set_id: string, currency: string, debit: BigDecimal, credit: BigDecimal, transaction_debit: BigDecimal, transaction_credit: BigDecimal}>  $movements
     */
    public function apply(string $companyId, array $movements): void
    {
        /** @var array<string, array{account_id: string, period_id: string, dimension_set_id: string, currency: string, debit: BigDecimal, credit: BigDecimal, transaction_debit: BigDecimal, transaction_credit: BigDecimal}> $byKey */
        $byKey = [];

        foreach ($movements as $movement) {
            $key = implode('|', [$movement['account_id'], $movement['period_id'], $movement['dimension_set_id'], $movement['currency']]);

            if (isset($byKey[$key])) {
                foreach (['debit', 'credit', 'transaction_debit', 'transaction_credit'] as $column) {
                    $byKey[$key][$column] = $byKey[$key][$column]->plus($movement[$column]);
                }
            } else {
                $byKey[$key] = $movement;
            }
        }

        ksort($byKey, SORT_STRING);

        foreach ($byKey as $row) {
            DB::statement(
                'INSERT INTO gl_account_balances (company_id, account_id, period_id, dimension_set_id, currency, debit, credit, transaction_debit, transaction_credit, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, now())
                 ON CONFLICT (company_id, account_id, period_id, dimension_set_id, currency) DO UPDATE SET
                     debit = gl_account_balances.debit + EXCLUDED.debit,
                     credit = gl_account_balances.credit + EXCLUDED.credit,
                     transaction_debit = gl_account_balances.transaction_debit + EXCLUDED.transaction_debit,
                     transaction_credit = gl_account_balances.transaction_credit + EXCLUDED.transaction_credit,
                     updated_at = now()',
                [
                    $companyId, $row['account_id'], $row['period_id'], $row['dimension_set_id'], $row['currency'],
                    $row['debit']->toScale(6)->__toString(), $row['credit']->toScale(6)->__toString(),
                    $row['transaction_debit']->toScale(6)->__toString(), $row['transaction_credit']->toScale(6)->__toString(),
                ],
            );
        }
    }
}
