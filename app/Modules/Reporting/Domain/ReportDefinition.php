<?php

namespace App\Modules\Reporting\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named report whose structure lives in versions (RP-FR-01). `current_version` is the one that
 * runs; earlier versions stay readable.
 *
 * @property string $id
 * @property string $company_id
 * @property string $key
 * @property string $name
 * @property string $kind
 * @property int $current_version
 * @property bool $publish_on_period_close
 * @property bool $publish_on_year_close
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReportDefinition extends VersionedCompanyModel
{
    protected $table = 'rp_report_definitions';

    protected $attributes = ['kind' => 'custom', 'current_version' => 1, 'publish_on_period_close' => false, 'publish_on_year_close' => false];

    protected $fillable = ['key', 'name', 'kind', 'current_version', 'publish_on_period_close', 'publish_on_year_close'];

    protected function casts(): array
    {
        return ['publish_on_period_close' => 'boolean', 'publish_on_year_close' => 'boolean'];
    }

    /** @return HasMany<ReportDefinitionVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ReportDefinitionVersion::class, 'definition_id');
    }
}
