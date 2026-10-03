<?php

namespace App\Modules\TransactionEntry\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * A recurring journal (TE-FR-14): the template of an entry, made again on every date its frequency gives.
 * The n-th date is counted from the start date, not from the previous one, so a schedule that starts on
 * the 31st stays on the last day of shorter months.
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property string $transaction_type_key
 * @property int|null $transaction_type_version
 * @property array<string, mixed> $template description, currency, exchange rate and lines or values of the entries; the date is the occurrence
 * @property string $frequency daily, weekly, monthly, quarterly or yearly
 * @property int $interval_count every how many of the frequency
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property string $initial_status `draft` or `submitted`: where the entries are left
 * @property string $status active, paused or completed
 * @property int $run_count occurrences made or skipped so far
 * @property Carbon|null $next_run_on null when completed
 * @property Carbon|null $last_run_on
 * @property string|null $last_entry_id
 * @property string|null $last_error why the next occurrence could not be made; cleared when it is made or skipped
 * @property Carbon|null $last_error_at
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RecurringSchedule extends VersionedCompanyModel
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];

    protected $table = 'te_recurring_schedules';

    protected $fillable = [
        'name', 'transaction_type_key', 'transaction_type_version', 'template', 'frequency', 'interval_count', 'start_date', 'end_date',
        'initial_status', 'status', 'run_count', 'next_run_on', 'last_run_on', 'last_entry_id', 'last_error', 'last_error_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'template' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
            'next_run_on' => 'date',
            'last_run_on' => 'date',
            'last_error_at' => 'datetime',
        ];
    }

    /** The date of the occurrence with that index (0 is the start date). */
    public function occurrence(int $index): CarbonImmutable
    {
        $start = CarbonImmutable::parse($this->start_date->toDateString());
        $step = $index * $this->interval_count;

        return match ($this->frequency) {
            'daily' => $start->addDays($step),
            'weekly' => $start->addWeeks($step),
            'monthly' => $start->addMonthsNoOverflow($step),
            'quarterly' => $start->addMonthsNoOverflow(3 * $step),
            default => $start->addYearsNoOverflow($step),
        };
    }

    /** The date of the next occurrence from `run_count`, or null when it falls after the end date. */
    public function nextOccurrence(): ?CarbonImmutable
    {
        $next = $this->occurrence($this->run_count);

        return $this->end_date !== null && $next->toDateString() > $this->end_date->toDateString() ? null : $next;
    }
}
