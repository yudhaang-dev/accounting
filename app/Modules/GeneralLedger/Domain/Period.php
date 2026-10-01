<?php

namespace App\Modules\GeneralLedger\Domain;

use App\Support\Company\CompanyScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An accounting period (GL-FR-03, GL-FR-04). Periods are never deleted; their status decides
 * whether postings are accepted.
 *
 * @property string $id
 * @property string $company_id
 * @property string $fiscal_year_id
 * @property int $number
 * @property string $name
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property PeriodStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Period extends CompanyScopedModel
{
    protected $table = 'gl_periods';

    protected $attributes = ['status' => 'open'];

    protected $fillable = ['fiscal_year_id', 'number', 'name', 'start_date', 'end_date', 'status'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => PeriodStatus::class,
        ];
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
