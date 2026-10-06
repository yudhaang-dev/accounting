<?php

namespace App\Modules\Reporting\Domain;

use App\Support\Company\CompanyScopedModel;
use Illuminate\Support\Carbon;

/**
 * One immutable structure of a report definition: basis, rows and columns (RP-FR-01).
 *
 * @property string $id
 * @property string $company_id
 * @property string $definition_id
 * @property int $version
 * @property array<string, mixed> $structure
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class ReportDefinitionVersion extends CompanyScopedModel
{
    protected $table = 'rp_report_definition_versions';

    protected $fillable = ['definition_id', 'version', 'structure', 'created_by'];

    protected function casts(): array
    {
        return ['structure' => 'array'];
    }
}
