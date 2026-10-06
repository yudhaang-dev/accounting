<?php

namespace App\Modules\Reporting\Domain;

use App\Support\Company\CompanyScopedModel;
use Illuminate\Support\Carbon;

/**
 * A published report as computed at the time (RP-FR-14). Immutable: the table refuses updates and
 * deletes, and a recalculation adds a snapshot that names the one it comes from.
 *
 * @property string $id
 * @property string $company_id
 * @property string $definition_id
 * @property int $definition_version
 * @property int $sequence
 * @property array<string, mixed> $parameters
 * @property array<string, int|null> $mapping_versions
 * @property array<string, mixed> $data_boundary
 * @property array<string, mixed> $result
 * @property string|null $reason
 * @property string|null $recomputed_from_id
 * @property int|null $published_by
 * @property Carbon $published_at
 */
class ReportSnapshot extends CompanyScopedModel
{
    public $timestamps = false;

    protected $table = 'rp_report_snapshots';

    protected $fillable = ['definition_id', 'definition_version', 'sequence', 'parameters', 'mapping_versions', 'data_boundary', 'result', 'reason', 'recomputed_from_id', 'published_by', 'published_at'];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'mapping_versions' => 'array',
            'data_boundary' => 'array',
            'result' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
