<?php

namespace App\Modules\GeneralLedger\Domain;

use App\Platform\Retention\RetainedCompanyModel;
use App\Support\Money\DecimalCast;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of a posted journal: functional debit or credit, transaction currency columns (ADR-0008)
 * and the dimension set (ADR-0007). Append-only like its journal.
 *
 * @property string $id
 * @property string $company_id
 * @property string $journal_id
 * @property int $line_number
 * @property string $account_id
 * @property string $period_id
 * @property Carbon $accounting_date
 * @property BigDecimal $debit
 * @property BigDecimal $credit
 * @property string $currency
 * @property BigDecimal $transaction_amount
 * @property BigDecimal $exchange_rate
 * @property string $dimension_set_id
 * @property string|null $description
 */
class JournalLine extends RetainedCompanyModel
{
    public const UPDATED_AT = null;

    protected $table = 'gl_journal_lines';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted journal lines are immutable; post a reversal instead (GL-FR-06).'));
    }

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'accounting_date' => 'date',
            'debit' => DecimalCast::class.':6',
            'credit' => DecimalCast::class.':6',
            'transaction_amount' => DecimalCast::class.':6',
            'exchange_rate' => DecimalCast::class.':12',
        ];
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
