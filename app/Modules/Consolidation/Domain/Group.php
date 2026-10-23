<?php

namespace App\Modules\Consolidation\Domain;

use App\Platform\Api\Concerns\HasLockVersion;
use App\Support\Eloquent\UlidModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A consolidation group: a parent company, a presentation currency and a perimeter (CO-FR-02). It belongs to
 * no company, so it carries no company scope; who may reach it is a grant on it (IA-FR-07).
 *
 * @property string $id
 * @property string $name
 * @property string $parent_company_id
 * @property string $presentation_currency
 * @property string $package_policy `draft` keeps a run over incomplete packages as a draft, `reject` refuses it
 * @property string $elimination_tolerance the largest difference, in the presentation currency, that an intragroup pair may leave and still be eliminated (CO-FR-07)
 * @property int $lock_version
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Group extends UlidModel
{
    use HasLockVersion;

    protected $table = 'co_groups';

    protected $fillable = ['name', 'parent_company_id', 'presentation_currency', 'package_policy', 'elimination_tolerance', 'created_by'];

    /** @return HasMany<GroupAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(GroupAccount::class)->orderBy('code');
    }
}
