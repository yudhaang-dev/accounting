<?php

namespace App\Modules\Subledger\Domain;

use App\Support\Company\CompanyScopedModel;
use App\Support\Money\DecimalCast;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * The reconciliation of one account on a date (SL-FR-09). `currency` is the functional currency for a control
 * account, whose total is in it, and the currency of the cash account otherwise. Only a difference can change,
 * once, by being waived.
 *
 * @property string $id
 * @property string $company_id
 * @property string $kind `control` or `cash`
 * @property string $account_id
 * @property string $account_code
 * @property string $currency
 * @property Carbon $as_of
 * @property BigDecimal $ledger_balance
 * @property BigDecimal $detail_total
 * @property BigDecimal $difference ledger less detail
 * @property string $status `balanced`, `difference` or `waived`
 * @property list<array{journal_id: string, number: string, accounting_date: string, source: string, debit: string, credit: string, description: string|null}> $causes
 * @property string|null $waiver_reason
 * @property int|null $waived_by
 * @property Carbon|null $waived_at
 * @property int|null $run_by
 * @property Carbon|null $created_at
 */
class ReconciliationRun extends CompanyScopedModel
{
    protected $table = 'sl_reconciliation_runs';

    protected $fillable = [
        'kind', 'account_id', 'account_code', 'currency', 'as_of', 'ledger_balance', 'detail_total', 'difference', 'status', 'causes',
        'waiver_reason', 'waived_by', 'waived_at', 'run_by',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'ledger_balance' => DecimalCast::class.':6',
            'detail_total' => DecimalCast::class.':6',
            'difference' => DecimalCast::class.':6',
            'causes' => 'array',
            'waived_at' => 'datetime',
        ];
    }
}
