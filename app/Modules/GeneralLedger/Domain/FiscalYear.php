<?php

namespace App\Modules\GeneralLedger\Domain;

use App\Support\Company\CompanyScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A fiscal year: twelve consecutive periods without gaps or overlap (GL-FR-03).
 *
 * @property string $id
 * @property string $company_id
 * @property string $calendar_id
 * @property string $name
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FiscalYear extends CompanyScopedModel
{
    protected $table = 'gl_fiscal_years';

    protected $fillable = ['calendar_id', 'name', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /** @return BelongsTo<FiscalCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(FiscalCalendar::class, 'calendar_id');
    }

    /** @return HasMany<Period, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(Period::class)->orderBy('number');
    }
}
