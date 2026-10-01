<?php

namespace App\Modules\GeneralLedger\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The fiscal calendar of a company: the month a fiscal year starts and how it is cut into periods (GL-FR-03).
 *
 * @property string $id
 * @property string $company_id
 * @property CalendarPattern $pattern
 * @property int $start_month
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FiscalCalendar extends VersionedCompanyModel
{
    protected $table = 'gl_fiscal_calendars';

    protected $fillable = ['pattern', 'start_month'];

    protected function casts(): array
    {
        return [
            'pattern' => CalendarPattern::class,
            'start_month' => 'integer',
        ];
    }

    /** @return HasMany<FiscalYear, $this> */
    public function fiscalYears(): HasMany
    {
        return $this->hasMany(FiscalYear::class, 'calendar_id');
    }
}
