<?php

namespace App\Modules\Consolidation\Domain;

use App\Support\Eloquent\UlidModel;
use Illuminate\Support\Carbon;

/**
 * A company that took part in a run, with the state its package was in (CO-FR-03).
 *
 * @property string $id
 * @property string $run_id
 * @property string $company_id
 * @property string $company_name
 * @property string $functional_currency
 * @property string $book_status
 * @property Carbon|null $coverage_from
 * @property Carbon|null $coverage_to
 * @property string $coverage_granularity
 * @property string $coverage_status
 * @property bool $balanced
 * @property array<string, mixed> $source
 * @property array<string, mixed>|null $translation the policy version and the rates it was translated by; null when not translated
 */
class RunEntity extends UlidModel
{
    public $timestamps = false;

    protected $table = 'co_run_entities';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['coverage_from' => 'date', 'coverage_to' => 'date', 'balanced' => 'boolean', 'source' => 'array', 'translation' => 'array'];
    }
}
