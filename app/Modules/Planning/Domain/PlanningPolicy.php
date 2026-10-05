<?php

namespace App\Modules\Planning\Domain;

use App\Platform\Api\VersionedCompanyModel;
use Illuminate\Support\Carbon;

/**
 * The publishing rules of a company's plans (PL-FR-04).
 *
 * @property string $id
 * @property string $company_id
 * @property bool $require_distinct_publisher the publisher must differ from whoever changed the version last
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PlanningPolicy extends VersionedCompanyModel
{
    protected $table = 'pl_planning_policies';

    protected $fillable = ['require_distinct_publisher'];

    protected function casts(): array
    {
        return ['require_distinct_publisher' => 'boolean'];
    }
}
